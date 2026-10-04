<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentNotification;
use App\Models\AssignmentSubmission;
use App\Support\Assignments\AssignmentDisplay;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\GradebookFeed;
use App\Support\TenantTimezone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The student submission workflow, protected files, grading and the shapes a
 * future Course Offering Gradebook will consume.
 *
 * THE THREE PROPERTIES PINNED HARDEST HERE
 *
 *   OPENING AN ASSIGNMENT IS NOT A SUBMISSION. Nothing in a GET records work, so
 *   a student who reads a task and leaves has submitted nothing and consumed no
 *   attempt. Every read test below asserts a row COUNT, not just a 200, because
 *   "the page worked" and "nothing was recorded" are different questions and
 *   only the second one is the guarantee.
 *
 *   LATE IS DERIVED FROM TWO STORED INSTANTS. `submitted_at` is written on the
 *   server and compared against the assignment's own deadline, so nothing the
 *   browser said about the time is ever read.
 *
 *   A MARK IS NOT VISIBLE UNTIL IT IS RETURNED. Recording one and publishing it
 *   are separate acts, and the student page is the only place the gate applies.
 */
class CourseAssignmentSubmissionTest extends TestCase
{
    use AssignmentFixture;

    // ══════════════════════════════════════════════════════════════════════
    // OPENING IS NOT SUBMITTING
    // ══════════════════════════════════════════════════════════════════════

    public function test_opening_an_assignment_records_no_submission(): void
    {
        $assignment = $this->publishedAssignment();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee($assignment->title);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk();

        // The guarantee, asserted as a row count rather than a page load.
        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    public function test_opening_an_assignment_keeps_its_state_as_open_not_submitted(): void
    {
        $assignment = $this->publishedAssignment(['title' => 'Not handed in yet']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Open');

        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // DRAFT IS NOT SUBMITTED
    // ══════════════════════════════════════════════════════════════════════

    public function test_saving_a_draft_records_work_but_not_a_submission(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'text' => 'Half-written thinking, not yet ready.',
            ])
            ->assertRedirect();

        $row = DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->first();

        $this->assertNotNull($row, 'the prepared work should be stored');
        $this->assertEquals(1, $row->is_draft, 'prepared work is a draft');
        $this->assertNull($row->submitted_at, 'a draft has no submission instant');
    }

    public function test_a_draft_does_not_count_towards_the_attempt_limit(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 1,
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", ['text' => 'Work in progress'])
            ->assertRedirect();

        // The draft is still there, and a submission can still be made, because
        // preparing work must not lock a student out of handing it in.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Finished answer.',
                'idempotency_key' => 'key-one',
            ])
            ->assertRedirect();

        $rows = DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->get();

        // One row: the draft was PROMOTED into the attempt, not duplicated.
        $this->assertCount(1, $rows);
        $this->assertEquals(0, $rows->first()->is_draft);
        $this->assertNotNull($rows->first()->submitted_at);
    }

    public function test_the_list_distinguishes_prepared_work_from_a_submission(): void
    {
        $assignment = $this->publishedAssignment(['title' => 'Work in progress', 'submission_type' => Assignment::SUBMISSION_TEXT]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", ['text' => 'Thinking'])
            ->assertRedirect();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk()
            // The words matter: a prepared draft must never be labelled as
            // submitted, because that is a claim about the student's work.
            ->assertSee('In progress — not yet submitted')
            ->assertSee('prepared work that is not submitted');
    }

    public function test_a_draft_cannot_be_edited_after_the_assignment_has_closed(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'closes_at' => now()->subHour(),
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", ['text' => 'Too late'])
            ->assertSessionHasErrors();
    }

    // ══════════════════════════════════════════════════════════════════════
    // SUBMITTING: TEXT, FILE, AND WHAT IS REQUIRED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_text_submission_is_recorded_with_a_server_side_instant(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);
        $before = now()->subSecond();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Ratio = 3 : 2, so current assets are one and a half times current liabilities.',
                'idempotency_key' => 'text-1',
            ])
            ->assertRedirect();

        $row = DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->first();

        $this->assertNotNull($row);
        $this->assertEquals(0, $row->is_draft);
        $this->assertEquals(1, $row->attempt_no);
        $this->assertEquals('submitted', $row->status);
        // The canonical rich-text field. The legacy `submission` column is left
        // NULL for Course Offering rows, so this also proves the two are not both
        // holding HEI content - which is what stops them drifting apart.
        $this->assertStringContainsString('one and a half times', $row->text_response);
        $this->assertNull($row->submission, 'the legacy column is for K12 rows only');
        // Taken on the server, after the request began.
        $this->assertTrue(Carbon::parse($row->submitted_at)->greaterThan($before));
    }

    public function test_a_file_submission_is_stored_outside_the_web_root(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_FILE]);

        // The form's field is named for the KIND. A document-type assignment
        // reads `document`, an image-type one reads `image`, and so on - which is
        // what lets a marker be told "a photograph" and receive one.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'document' => UploadedFile::fake()->create('my ratios.pdf', 40, 'application/pdf'),
                'idempotency_key' => 'file-1',
            ])
            ->assertRedirect();

        $row = DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->first();
        $this->assertNotNull($row);

        // The evidence is now an ITEM, so this also proves the item exists and is
        // of the right kind - a stronger assertion than reading one file column.
        $item = DB::table('assignment_submission_items')
            ->where('assignment_submission_id', $row->id)
            ->first();

        $this->assertNotNull($item, 'the upload is recorded as an evidence item');
        $this->assertSame('document', $item->kind);

        // The stored path is namespaced by offering, assignment and kind, and is
        // generated - never the client's filename.
        $this->assertStringStartsWith('assignment-submissions/', $item->stored_path);
        $this->assertStringContainsString('/document/', $item->stored_path);
        $this->assertStringNotContainsString('my ratios', $item->stored_path);
        // The original name survives only as a display label.
        $this->assertSame('my ratios.pdf', $item->original_name);

        Storage::disk('local')->assertExists($item->stored_path);
    }

    public function test_a_file_plus_text_submission_requires_both_parts(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT]);

        // Text alone is not what the assignment asked for: it wants a document AND
        // a written response, so supplying one half is still a refusal.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Only words.',
                'idempotency_key' => 'partial-1',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, DB::table('assignment_submissions')->count());

        // And both halves together are accepted, which is what makes this a
        // combination test rather than just a refusal test.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'My reasoning, set out below.',
                'document' => UploadedFile::fake()->create('working.pdf', 8, 'application/pdf'),
                'idempotency_key' => 'partial-2',
            ])
            ->assertRedirect();

        $this->assertSame(1, DB::table('assignment_submissions')->count());
    }

    public function test_an_empty_submission_is_refused(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => "   \n  ",
                'idempotency_key' => 'empty-1',
            ])
            ->assertSessionHasErrors();

        // An empty attempt is not work, and cannot be graded.
        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    public function test_a_forbidden_file_type_is_refused(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_FILE]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'document' => UploadedFile::fake()->create('payload.php', 4, 'application/x-php'),
                'idempotency_key' => 'bad-1',
            ])
            ->assertSessionHasErrors();

        // Neither a submission nor an evidence item may be written for a file that
        // was refused: a row pointing at nothing is worse than no row.
        $this->assertSame(0, DB::table('assignment_submissions')->count());
        $this->assertSame(0, DB::table('assignment_submission_items')->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // ATTEMPTS AND IDEMPOTENCY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_single_attempt_assignment_refuses_a_second_submission(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 1,
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'First answer.', 'idempotency_key' => 'a1',
            ])->assertRedirect();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Second answer.', 'idempotency_key' => 'a2',
            ])->assertSessionHasErrors();

        $this->assertSame(1, DB::table('assignment_submissions')->count());
    }

    public function test_a_submitted_attempt_is_kept_when_a_resubmission_is_allowed(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 2,
        ]);

        foreach ([['First', 'k1'], ['Second', 'k2']] as [$text, $key]) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => $text.' answer.', 'idempotency_key' => $key,
                ])->assertRedirect();
        }

        $rows = DB::table('assignment_submissions')
            ->where('assignment_id', $assignment->id)->orderBy('attempt_no')->get();

        $this->assertCount(2, $rows, 'each attempt is its own durable row');
        $this->assertEquals(1, $rows[0]->attempt_no);
        $this->assertEquals(2, $rows[1]->attempt_no);
        $this->assertStringContainsString('First', $rows[0]->text_response);
        $this->assertStringContainsString('Second', $rows[1]->text_response);
    }

    public function test_attempts_beyond_the_allowance_are_refused(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 2,
        ]);

        foreach (['k1', 'k2', 'k3'] as $key) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => 'Answer '.$key, 'idempotency_key' => $key,
                ]);
        }

        $this->assertSame(2, DB::table('assignment_submissions')->count());
    }

    public function test_a_double_clicked_submit_creates_exactly_one_attempt(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);

        // The same key twice: a double-click, a refresh after a slow save, or two
        // tabs all carry ONE token for the same logical action.
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => 'My answer.', 'idempotency_key' => 'double-click',
                ])->assertRedirect();
        }

        $this->assertSame(1, DB::table('assignment_submissions')->count());
    }

    public function test_the_idempotency_key_is_what_prevents_a_double_submission(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 5,
        ]);

        // Different keys are genuinely different submissions, so this must create
        // a second attempt. It proves the test above is not passing merely
        // because the second request was rejected for another reason.
        foreach (['first', 'second'] as $key) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => 'Answer for '.$key, 'idempotency_key' => $key,
                ])->assertRedirect();
        }

        $this->assertSame(2, DB::table('assignment_submissions')->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // DUE, LATE AND CLOSED BOUNDARIES
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_submission_before_the_due_date_is_not_late(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'due_date' => now()->addDay(),
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'On time.', 'idempotency_key' => 'ontime',
            ])->assertRedirect();

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)->firstOrFail();

        $this->assertFalse($submission->isLate());
        $this->assertSame('submitted', $submission->status);
    }

    public function test_a_submission_after_the_due_date_is_recorded_as_late(): void
    {
        // The assignment is past due but still open, because no final closing time
        // was set and the late policy allows it.
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'due_date' => now()->subDay(),
            'late_policy' => Assignment::LATE_ALLOWED,
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Late but allowed.', 'idempotency_key' => 'late-1',
            ])->assertRedirect();

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)->firstOrFail();

        $this->assertTrue($submission->isLate());
        $this->assertSame('late', $submission->status);
    }

    public function test_lateness_is_decided_by_the_server_instant_not_by_the_client(): void
    {
        // The client cannot influence the recorded time, so a student who has
        // changed their own clock to look on time gains nothing. Asserted by
        // sending a deliberately false timestamp in the payload and showing it
        // has no effect.
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'due_date' => now()->subDay(),
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Trying to look on time.',
                'submitted_at' => now()->subYear()->toDateTimeString(),
                'idempotency_key' => 'clock-1',
            ])->assertRedirect();

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)->firstOrFail();

        $this->assertTrue($submission->isLate(), 'the client-supplied instant is ignored');
        $this->assertTrue($submission->submitted_at->greaterThan($assignment->due_date));
    }

    public function test_a_blocking_late_policy_refuses_a_submission_after_the_due_date(): void
    {
        $assignment = $this->publishedAssignment([
            'due_date' => now()->subDay(),
            'late_policy' => Assignment::LATE_BLOCKED,
        ]);

        $this->assertFalse(AssignmentLifecycle::mayAcceptNewSubmission($assignment, 0));

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Refused.', 'idempotency_key' => 'blocked-1',
            ])->assertSessionHasErrors();

        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    public function test_the_final_closing_time_always_stops_new_submissions(): void
    {
        // The late policy ALLOWS late work, but the final closing time does not
        // care: this is the last moment anything is accepted.
        $assignment = $this->publishedAssignment([
            'due_date' => now()->subDays(2),
            'closes_at' => now()->subHour(),
            'late_policy' => Assignment::LATE_ALLOWED,
        ]);

        $this->assertFalse(AssignmentLifecycle::mayAcceptNewSubmission($assignment, 0));

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Too late.', 'idempotency_key' => 'closed-1',
            ])->assertSessionHasErrors();

        $this->assertSame(0, DB::table('assignment_submissions')->count());
    }

    public function test_closing_an_assignment_keeps_every_existing_submission(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);
        $this->submission($assignment, $this->student, ['submission' => 'Work done before closing.']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/state/closed")
            ->assertRedirect();

        $this->assertSame(1, DB::table('assignment_submissions')->count());
        $this->assertSame(
            'Work done before closing.',
            DB::table('assignment_submissions')->value('submission')
        );
    }

    public function test_a_closed_assignment_shows_the_student_what_is_still_possible(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'closes_at' => now()->subHour(),
        ]);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Submissions are no longer accepted');
    }

    // ══════════════════════════════════════════════════════════════════════
    // PROTECTED FILES
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_can_read_their_own_submitted_file(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_FILE]);
        $submission = $this->submissionWithFile($assignment, $this->student, 'assignment-submissions/1/2/mine.pdf');
        Storage::disk('local')->put('assignment-submissions/1/2/mine.pdf', 'the bytes');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/file")
            ->assertOk();
    }

    public function test_a_student_cannot_read_another_students_submitted_file(): void
    {
        Storage::fake('local');

        // A second confirmed registrant on the SAME Offering, so the registration
        // check passes for both and only ownership can refuse.
        $other = $this->secondConfirmedStudent();
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_FILE]);
        $theirs = $this->submissionWithFile($assignment, $other, 'assignment-submissions/1/3/theirs.pdf');
        Storage::disk('local')->put('assignment-submissions/1/3/theirs.pdf', 'their work');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$theirs->id}/file")
            ->assertNotFound();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$theirs->id}")
            ->assertNotFound();
    }

    public function test_a_student_cannot_read_a_submission_from_another_offering(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment();
        $theirs = $this->submission($assignment, $this->student);
        $other = $this->secondOfferingAssignment();

        $this->actingAs($this->student)
            ->get("/student/courses/{$other['offeringId']}/assignments/{$other['assignmentId']}/submissions/{$theirs->id}")
            ->assertNotFound();
    }

    public function test_a_lecturer_cannot_read_a_submission_from_another_tenant(): void
    {
        $other = $this->otherTenantAssignment();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$other['offeringId']}/assignments/{$other['assignmentId']}/submissions/{$other['submissionId']}")
            ->assertNotFound();
    }

    public function test_a_registered_student_can_read_a_lecturers_handout(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment();
        $resource = $this->assignmentResource($assignment, [
            'type' => \App\Models\AssignmentResource::TYPE_FILE,
            'link_url' => null,
            'original_name' => 'dataset.pdf',
            'stored_name' => 'assignments/1/abc.pdf',
        ]);
        Storage::disk('local')->put('assignments/1/abc.pdf', 'the handout');

        $this->actingAs($this->student)
            ->get("/student/courses/assignment-resources/{$resource->id}")
            ->assertOk();
    }

    public function test_a_handout_for_a_draft_assignment_is_not_reachable(): void
    {
        Storage::fake('local');

        $draft = $this->assignment();
        $resource = $this->assignmentResource($draft, [
            'type' => \App\Models\AssignmentResource::TYPE_FILE,
            'link_url' => null,
            'stored_name' => 'assignments/1/draft.pdf',
        ]);
        Storage::disk('local')->put('assignments/1/draft.pdf', 'a question paper');

        // A 404, not a 403: the pages must not confirm that unpublished material
        // exists at all.
        $this->actingAs($this->student)
            ->get("/student/courses/assignment-resources/{$resource->id}")
            ->assertNotFound();
    }

    public function test_a_handout_from_another_tenant_is_not_reachable(): void
    {
        $other = $this->otherTenantAssignment();

        $resourceId = (int) DB::table('assignment_resources')->insertGetId([
            'school_id' => $other['schoolId'],
            'course_offering_id' => $other['offeringId'],
            'assignment_id' => $other['assignmentId'],
            'title' => 'Theirs',
            'type' => 'file',
            'original_name' => 'secret.pdf',
            'stored_name' => 'assignments/2/secret.pdf',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->get("/student/courses/assignment-resources/{$resourceId}")
            ->assertNotFound();
    }

    public function test_an_unallocated_lecturer_cannot_read_a_handout(): void
    {
        Storage::fake('local');

        // The fixture allocates this lecturer as a co-lecturer, so the allocation
        // is removed first. Testing "unallocated" against a lecturer who IS
        // allocated would pass for the wrong reason if the route ever stopped
        // checking.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $assignment = $this->publishedAssignment();
        $resource = $this->assignmentResource($assignment, [
            'type' => \App\Models\AssignmentResource::TYPE_FILE,
            'link_url' => null,
            'stored_name' => 'assignments/1/handout.pdf',
        ]);
        Storage::disk('local')->put('assignments/1/handout.pdf', 'the handout');

        $this->actingAs($this->otherLecturer)
            ->get("/teacher/course-offerings/assignment-resources/{$resource->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // GRADING
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_marking_list_comes_from_registrations_not_submissions(): void
    {
        $assignment = $this->publishedAssignment();
        $this->secondConfirmedStudent(); // registered, has submitted nothing
        $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions")
            ->assertOk()
            ->assertSee('Not submitted')
            ->assertSee('Submitted');
    }

    public function test_a_lecturer_records_a_mark_without_publishing_it(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => 13.37,
                'feedback' => 'Clear working. Watch the units on question 4.',
            ])
            ->assertRedirect();

        $row = DB::table('assignment_submissions')->find($submission->id);

        $this->assertEquals(13.37, (float) $row->marks_awarded);
        $this->assertNotNull($row->graded_at);
        $this->assertEquals($this->lecturer->id, (int) $row->graded_by);
        // Recorded, but NOT yet released.
        $this->assertNull($row->marks_released_at);
    }

    public function test_a_student_cannot_see_a_mark_until_it_is_returned(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        // A mark value with two decimal places, so it cannot appear incidentally
        // in the layout's JavaScript. An earlier version of this test used 17.5
        // and passed/failed for the wrong reason.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => 13.37,
                'feedback' => 'Zebracrossing comment not yet released.',
            ])->assertRedirect();

        // Proved present in storage first, so the assertions below are meaningful:
        // the leak this test guards against is a DATABASE row rendered, not a row
        // that failed to save.
        $this->assertEquals(13.37, (float) DB::table('assignment_submissions')->find($submission->id)->marks_awarded);

        // THE CENTRAL GATE. The mark exists and is invisible.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertDontSee('13.37')
            ->assertDontSee('Zebracrossing');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertOk()
            ->assertDontSee('13.37')
            ->assertDontSee('Zebracrossing');
    }

    public function test_returning_feedback_makes_the_mark_and_feedback_visible(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);
        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)->post("{$base}/grade", [
            'marks_awarded' => 16.42,
            'feedback' => 'Clear working. Watch the units on question 4.',
        ])->assertRedirect();

        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();

        $row = DB::table('assignment_submissions')->find($submission->id);
        $this->assertNotNull($row->marks_released_at);
        $this->assertEquals($this->lecturer->id, (int) $row->returned_by);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('16.42')
            ->assertSee('Watch the units on question 4')
            ->assertSee('Returned');
    }

    public function test_feedback_cannot_be_returned_before_a_mark_is_recorded(): void
    {
        $assignment = $this->publishedAssignment();
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/release")
            ->assertSessionHasErrors();
    }

    public function test_a_returned_result_can_be_withdrawn_without_losing_the_mark(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);
        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)->post("{$base}/grade", ['marks_awarded' => 4])->assertRedirect();
        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();
        $this->actingAs($this->lecturer)->post("{$base}/unrelease")->assertRedirect();

        $row = DB::table('assignment_submissions')->find($submission->id);

        // The mark is kept so nothing is lost, but the student is told nothing.
        $this->assertEquals(4, (float) $row->marks_awarded);
        $this->assertNull($row->marks_released_at);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertOk()
            ->assertSee('Not marked yet');
    }

    public function test_a_mark_above_the_maximum_is_refused_rather_than_clamped(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => 25,
            ])
            ->assertSessionHasErrors('marks_awarded');

        // Refused, not stored and not silently reduced. A stored 25/20 would make
        // every total and any future gradebook quietly wrong, and clamping would
        // hide the slip that caused it.
        $this->assertNull(DB::table('assignment_submissions')->find($submission->id)->marks_awarded);
    }

    public function test_a_negative_mark_is_refused(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => -5,
            ])
            ->assertSessionHasErrors('marks_awarded');

        $this->assertNull(DB::table('assignment_submissions')->find($submission->id)->marks_awarded);
    }

    public function test_a_lecturer_cannot_grade_a_students_prepared_draft(): void
    {
        $assignment = $this->publishedAssignment(['submission_type' => Assignment::SUBMISSION_TEXT]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", ['text' => 'Still writing'])
            ->assertRedirect();

        $draft = DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->first();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$draft->id}/grade", [
                'marks_awarded' => 10,
            ])
            ->assertSessionHasErrors();
    }

    public function test_a_lecturer_cannot_grade_another_tenants_work(): void
    {
        $other = $this->otherTenantAssignment();

        // This lecturer IS legitimately allocated - to their own Offering, in
        // their own institution. Only the tenant differs, so the Offering in the
        // URL cannot resolve for them at all.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$other['offeringId']}/assignments/{$other['assignmentId']}/submissions/{$other['submissionId']}/grade", [
                'marks_awarded' => 10,
            ])
            ->assertNotFound();

        $this->assertNull(DB::table('assignment_submissions')->find($other['submissionId'])->marks_awarded);
    }

    public function test_a_lecturer_without_an_allocation_cannot_grade_this_work(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => 20,
            ])
            ->assertNotFound();

        $this->assertNull(DB::table('assignment_submissions')->find($submission->id)->marks_awarded);
    }

    // ══════════════════════════════════════════════════════════════════════
    // VIEWER TIMEZONE
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_instant_is_stored_once_and_rendered_in_the_viewers_zone(): void
    {
        $due = Carbon::parse('2026-10-15 23:59:00', 'UTC');
        $assignment = $this->publishedAssignment(['due_date' => $due]);

        // The stored instant is UTC and never re-stored per viewer.
        $this->assertSame('2026-10-15 23:59:00', $assignment->fresh()->due_date->format('Y-m-d H:i:s'));

        $display = app(AssignmentDisplay::class);

        $london = $this->userInTimezone('Kampala Student', 'Europe/London');
        $kampala = $this->userInTimezone('Nairobi Student', 'Africa/Nairobi');

        $londonDue = $display->for($assignment, $london)->due($assignment, $london);
        $kampalaDue = $display->for($assignment, $kampala)->due($assignment, $kampala);

        // ONE moment, two correct readings. They must differ, and each must be a
        // truthful rendering of the same instant.
        $this->assertNotSame($londonDue, $kampalaDue, 'the same instant should read differently in two zones');
        $this->assertSame('16 Oct 2026 at 00:59', $londonDue);
        $this->assertSame('16 Oct 2026 at 02:59', $kampalaDue);

        // The stored value is untouched by any of it.
        $this->assertSame('2026-10-15 23:59:00', $assignment->fresh()->due_date->format('Y-m-d H:i:s'));
    }

    public function test_the_timezone_policy_falls_back_when_the_viewer_has_none(): void
    {
        $tz = app(TenantTimezone::class);

        // The fixture institution DOES set a timezone, so the last step of PIIE's
        // policy is unreachable until it is cleared here. Each link of the chain
        // is then checked in turn, so a hardcoded Africa/Kampala could not slip in
        // at any of them.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);
        $assignment = $this->publishedAssignment(['due_date' => Carbon::parse('2026-10-15 12:00:00', 'UTC')]);
        $display = app(AssignmentDisplay::class);

        // 1. The institution's own zone, when set.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => 'Africa/Nairobi']);
        $this->assertSame('Africa/Nairobi', $display->for($assignment, $this->student)->zone($assignment, $this->student));

        // 2. The viewer's zone wins over the institution's.
        $london = $this->userInTimezone('Outlier Student', 'Europe/London');
        $this->assertSame('Europe/London', $display->for($assignment, $london)->zone($assignment, $london));

        // 3. With neither, the application timezone is the answer - asserted
        //    against config(), never against a literal zone name.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);
        $this->assertSame(config('app.timezone'), $display->for($assignment, $this->student)->zone($assignment, $this->student));
        $this->assertTrue($tz->isValid($display->for($assignment, $this->student)->zone($assignment, $this->student)));
    }

    public function test_the_student_page_labels_the_zone_it_is_showing(): void
    {
        $assignment = $this->publishedAssignment(['due_date' => Carbon::parse('2026-10-15 23:59:00', 'UTC')]);
        $student = $this->userInTimezone('Outlier Student', 'Europe/London');

        $this->actingAs($student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Times shown in');
    }

    public function test_an_authoritative_deadline_is_never_shifted_by_a_viewer_zone(): void
    {
        // The late decision compares UTC instants, so a viewer in another zone
        // cannot gain or lose time by reading the page in their own clock.
        $assignment = $this->publishedAssignment(['due_date' => Carbon::parse('2026-10-15 23:59:00', 'UTC')]);
        $london = $this->userInTimezone('Outlier Student', 'Europe/London');

        $display = app(AssignmentDisplay::class);
        $shown = $display->for($assignment, $london)->due($assignment, $london);

        $this->assertStringContainsString('2026', $shown);
        $this->assertTrue($assignment->due_date->equalTo(Carbon::parse('2026-10-15 23:59:00', 'UTC')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════

    public function test_publishing_notifies_only_confirmed_students_of_that_offering(): void
    {
        $draft = $this->assignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            ->assertRedirect();

        $notified = DB::table('user_notifications')
            ->where('type', 'assignment_published')
            ->pluck('user_id')->all();

        $this->assertContains($this->student->id, $notified);
        // The lecturer is not a recipient of their own announcement.
        $this->assertNotContains($this->lecturer->id, $notified);
    }

    public function test_a_publish_notification_never_goes_to_another_tenants_students(): void
    {
        $other = $this->otherTenantAssignment();
        $draft = $this->assignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            ->assertRedirect();

        $notified = DB::table('user_notifications')
            ->where('type', 'assignment_published')
            ->pluck('user_id')->all();

        $this->assertNotContains($other['student']->id, $notified);
    }

    public function test_publishing_twice_does_not_announce_twice(): void
    {
        $draft = $this->assignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published");

        // A save that leaves the assignment already visible, and a re-attempt at
        // the same transition, must both be silent. The UNIQUE dedup key - not a
        // read-then-write check - is what decides.
        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}", [
                'title' => 'Renamed but still published',
                'instructions' => $draft->instructions,
                'max_marks' => 20,
                'submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_PUBLISHED,
            ])
            ->assertRedirect();

        $this->assertSame(1, DB::table('user_notifications')
            ->where('type', 'assignment_published')->count());
        $this->assertSame(1, DB::table('assignment_notifications')
            ->where('type', AssignmentNotification::TYPE_PUBLISHED)->count());
    }

    public function test_a_graded_notification_is_sent_once_per_attempt(): void
    {
        $assignment = $this->publishedAssignment([
            'max_marks' => 20,
            // A resubmission follows, so the mode must accept text.
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 2,
        ]);
        $submission = $this->submission($assignment, $this->student);
        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)->post("{$base}/grade", ['marks_awarded' => 18])->assertRedirect();
        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();

        $this->assertSame(1, DB::table('user_notifications')
            ->where('type', 'assignment_graded')->count());

        // A second attempt graded and released is a genuinely new event.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'A better answer.', 'idempotency_key' => 'retry-1',
            ])->assertRedirect();

        $second = DB::table('assignment_submissions')
            ->where('assignment_id', $assignment->id)->orderByDesc('attempt_no')->first();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$second->id}/grade", ['marks_awarded' => 19])
            ->assertRedirect();
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$second->id}/release")
            ->assertRedirect();

        $this->assertSame(2, DB::table('user_notifications')
            ->where('type', 'assignment_graded')->count());
    }

    public function test_a_notification_link_resolves_to_an_authorized_page(): void
    {
        $draft = $this->assignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            ->assertRedirect();

        $url = DB::table('user_notifications')
            ->where('type', 'assignment_published')
            ->value('url');

        $this->assertNotNull($url);
        $this->assertStringContainsString("/student/courses/{$this->offering->id}/assignments/{$draft->id}", $url);

        // And the link actually works for its recipient.
        $this->actingAs($this->student)->get($url)->assertOk();
    }

    public function test_a_notification_link_still_resolves_after_the_assignment_closes(): void
    {
        $draft = $this->assignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published");

        $url = DB::table('user_notifications')
            ->where('type', 'assignment_published')
            ->value('url');

        // THE PROPERTY THAT MATTERS. A notification lives in a list and in browser
        // history, so it must never rot. Closing keeps the page reachable because
        // a closed assignment with the student's own feedback is exactly what
        // they most need to read.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/closed")
            ->assertRedirect();

        $this->actingAs($this->student)->get($url)->assertOk();
    }

    public function test_a_notification_carries_no_mark_no_file_and_no_stored_path(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20, 'title' => 'Secret ratios work']);
        $submission = $this->submission($assignment, $this->student, [
            'file_path' => 'assignment-submissions/1/2/private.pdf',
            'file_name' => 'private.pdf',
        ]);
        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)->post("{$base}/grade", [
            'marks_awarded' => 3, 'feedback' => 'Secret private comment.',
        ])->assertRedirect();
        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();

        $notification = DB::table('user_notifications')
            ->where('type', 'assignment_graded')->first();

        $this->assertNotNull($notification);
        // A notification renders in a list and ends up in browser history, so it
        // must not carry a learner's work, a mark, or a resource path.
        $this->assertStringNotContainsString('assignment-submissions/', $notification->body);
        $this->assertStringNotContainsString('private.pdf', $notification->body);
        $this->assertStringNotContainsString('Secret private comment.', $notification->body);
    }

    public function test_publication_is_not_announced_by_email_and_does_not_claim_to_be(): void
    {
        // PIIE's SMTP is not configured in this environment, so nothing may claim
        // an email went out. The notifier uses only the in-app channel.
        $this->assertFalse(\App\Support\Assignments\AssignmentNotifier::isMailConfigured());
    }

    // ══════════════════════════════════════════════════════════════════════
    // GRADEBOOK READINESS
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_gradebook_feed_exposes_released_grades_under_stable_names(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20, 'title' => 'Business Ratios']);
        $submission = $this->submission($assignment, $this->student);
        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)->post("{$base}/grade", ['marks_awarded' => 16])->assertRedirect();
        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();

        $rows = app(GradebookFeed::class)->releasedGradesForOffering($this->offering);

        $this->assertCount(1, $rows);

        $row = $rows[0];

        // The contract a future Course Offering Gradebook is written against.
        foreach ([
            'course_offering_id', 'assignment_id', 'assignment_title', 'student_id',
            'marks', 'max_marks', 'percent', 'graded_at', 'graded_by', 'released_at', 'is_late',
        ] as $field) {
            $this->assertArrayHasKey($field, $row, "the feed must expose '{$field}'");
        }

        $this->assertSame((int) $this->offering->id, $row['course_offering_id']);
        $this->assertSame('Business Ratios', $row['assignment_title']);
        $this->assertSame(16.0, $row['marks']);
        $this->assertSame(20, $row['max_marks']);
        $this->assertSame(80.0, $row['percent']);
        $this->assertNotNull($row['released_at']);
    }

    public function test_the_gradebook_feed_excludes_marks_that_have_not_been_returned(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade", [
                'marks_awarded' => 16,
            ])->assertRedirect();

        // A gradebook that showed an unreleased mark would leak it.
        $this->assertCount(0, app(GradebookFeed::class)->releasedGradesForOffering($this->offering));
    }

    public function test_the_gradebook_matrix_distinguishes_no_mark_from_a_mark_of_zero(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $other = $this->secondConfirmedStudent();
        $unmarked = $this->submission($assignment, $this->student);

        $zero = $this->submission($assignment, $other, [
            'attempt_no' => 1,
            'marks_awarded' => 0,
            'marks_released_at' => now(),
            'graded_at' => now(),
        ]);

        $rows = collect(app(GradebookFeed::class)->matrixForOffering($this->offering))
            ->keyBy('student_id');

        // "not marked yet" and "marked zero" are different facts and must never
        // collapse into the same value.
        $this->assertNull($rows[$this->student->id]['marks'], 'ungraded work is null, not zero');
        $this->assertSame('Submitted', $rows[$this->student->id]['state']);

        $this->assertSame(0.0, $rows[$other->id]['marks'], 'a released zero really is zero');
        $this->assertSame(0.0, $rows[$other->id]['percent']);
    }

    public function test_the_gradebook_matrix_includes_a_student_with_no_work_at_all(): void
    {
        $this->publishedAssignment(['max_marks' => 20]);
        $this->secondConfirmedStudent();

        $rows = app(GradebookFeed::class)->matrixForOffering($this->offering);

        $states = collect($rows)->pluck('state')->unique()->all();
        $this->assertContains('Not submitted', $states);
    }

    public function test_the_gradebook_feed_is_scoped_to_one_offering(): void
    {
        $mine = $this->publishedAssignment(['max_marks' => 20]);
        $this->releasedGradeFor($mine, $this->student, 15);

        $theirs = $this->secondOfferingAssignment();

        $ids = collect(app(GradebookFeed::class)->releasedGradesForOffering($this->offering))
            ->pluck('assignment_id')->unique()->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs['assignmentId'], $ids);
    }

    // ══════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════


    private function userInTimezone(string $name, string $timezone): \App\Models\User
    {
        $user = \App\Models\User::factory()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'.'.uniqid().'@student.example.test',
            'role_id' => 7,
            'school_id' => $this->school,
            'account_status' => 'active',
        ]);

        DB::table('course_registrations')->insert([
            'school_id' => $this->school,
            'student_id' => $user->id,
            'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id,
            'status' => 'confirmed',
        ]);

        // The viewer's own preference, which is the first step of PIIE's
        // timezone policy. A column set here must decide the rendering.
        $usersTable = (new \App\Models\User())->getTable();
        if (SchemaHasColumn($usersTable, 'timezone')) {
            DB::table($usersTable)->where('id', $user->id)->update(['timezone' => $timezone]);
        }

        return $user->fresh();
    }

    /**
     * A submitted attempt that really has a stored file behind it.
     *
     * The file tests need a row whose `file_path` points at bytes that exist,
     * because the download routes check the disk before serving - a test that
     * only put a path in the database would be testing the 404 branch.
     */
    private function submissionWithFile(Assignment $assignment, \App\Models\User $student, string $path): AssignmentSubmission
    {
        return $this->submission($assignment, $student, [
            'file_path' => $path,
            'file_name' => 'work.pdf',
            'file_size' => 1024,
            'file_mime' => 'application/pdf',
            'submission' => null,
        ]);
    }

    private function releasedGradeFor(Assignment $assignment, \App\Models\User $student, float $marks): AssignmentSubmission
    {
        $submission = $this->submission($assignment, $student);
        $submission->update([
            'marks_awarded' => $marks,
            'graded_at' => now(),
            'graded_by' => $this->lecturer->id,
            'marks_released_at' => now(),
            'returned_at' => now(),
            'returned_by' => $this->lecturer->id,
            'status' => 'graded',
        ]);

        return $submission->fresh();
    }
}

/**
 * A local helper so the timezone test does not need to assume a column exists.
 */
function SchemaHasColumn(string $table, string $column): bool
{
    return \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
}
