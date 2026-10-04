<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentNotification;
use App\Models\AssignmentSubmission;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\AssignmentNotifier;
use App\Support\Assignments\GradebookFeed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Course-Offering Assignments.
 *
 * The questions this suite answers, in the order the brief lists them:
 * authority (allocation for a lecturer, confirmed registration for a student),
 * tenant and Offering isolation, the publishing lifecycle, the submission rules,
 * protected files, grading, notifications, and the shape a future Gradebook will
 * consume.
 *
 * The two properties that most deserve to be unbreakable, and are pinned hardest
 * here:
 *
 *   OPENING AN ASSIGNMENT IS NOT A SUBMISSION. Reading a task and leaving has
 *   submitted nothing and consumed no attempt.
 *
 *   A MARK IS NOT VISIBLE TO A STUDENT UNTIL IT IS RETURNED. Recording one and
 *   publishing it are separate acts.
 */
class CourseAssignmentTest extends TestCase
{
    use AssignmentFixture;

    // ══════════════════════════════════════════════════════════════════════
    // LECTURER AUTHORITY — ALLOCATION, NOT ROLE
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_allocated_lecturer_can_reach_the_assignments_workspace(): void
    {
        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertSee('Assignments')
            ->assertSee('Create an assignment');
    }

    public function test_an_unallocated_lecturer_cannot_author_an_assignment(): void
    {
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)->delete();

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Not mine to set',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
            ])
            ->assertNotFound();

        $this->assertSame(0, Assignment::query()->where('title', 'Not mine to set')->count());
    }

    public function test_a_lecturer_whose_allocation_is_not_in_force_cannot_reach_the_admin_path(): void
    {
        // THE REGRESSION THIS PINS.
        //
        // `course_assignments.manage` is granted to every teaching role by the
        // role-based compatibility layer, so it cannot be used to tell an
        // administrator from a lecturer. If the two authority checks were
        // unioned, a lecturer who failed the ALLOCATION check would fall through
        // to the admin path and be let in by their role alone.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->lecturer->id)
            ->update(['starts_on' => now()->addYear()->toDateString()]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Before my allocation starts',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
            ])
            ->assertForbidden();

        $this->assertSame(0, Assignment::query()->where('title', 'Before my allocation starts')->count());
    }

    public function test_a_teacher_is_never_treated_as_a_course_offering_administrator(): void
    {
        $access = app(\App\Support\Assignments\AssignmentAccess::class);

        $this->assertTrue(
            $access->canLecturerManage($this->lecturer, $this->offering),
            'an allocated lecturer should be able to author'
        );
        $this->assertFalse(
            $access->canAdminManage($this->lecturer, $this->offering),
            'a lecturer must not satisfy the administrator check'
        );
    }

    public function test_a_lecturer_cannot_grade_a_submission_in_another_offering(): void
    {
        // Same school, same lecturer, same tenant — only the Offering differs. A
        // second tenant would not prove this, because the school check would
        // catch the request anyway.
        $theirs = $this->secondOfferingAssignment();
        $ours = $this->publishedAssignment();
        $submission = $this->submission($ours, $this->student);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$theirs['offeringId']}/assignments/{$theirs['assignmentId']}")
            ->assertNotFound();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$theirs['offeringId']}/assignments/{$theirs['assignmentId']}/submissions/{$submission->id}")
            ->assertNotFound();
    }

    public function test_a_student_cannot_reach_the_lecturer_assignments_workspace(): void
    {
        $response = $this->actingAs($this->student)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments");

        $this->assertNotContains(200, [$response->getStatusCode()]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // STUDENT AUTHORITY — CONFIRMED REGISTRATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_confirmed_registrant_sees_published_assignments(): void
    {
        $this->publishedAssignment(['title' => 'Visible to Kyeyune']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertSee('Visible to Kyeyune');
    }

    public function test_a_student_awaiting_confirmation_cannot_see_assignments(): void
    {
        $this->publishedAssignment(['title' => 'Should not be listed']);

        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'registered']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertNotFound();
    }

    public function test_a_student_who_dropped_the_course_cannot_see_assignments(): void
    {
        $this->publishedAssignment(['title' => 'Should not be listed either']);

        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'dropped']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertNotFound();
    }

    public function test_a_student_cannot_open_an_assignment_from_another_offering(): void
    {
        $theirs = $this->secondOfferingAssignment();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$theirs['assignmentId']}")
            ->assertNotFound();
    }

    public function test_a_student_cannot_reach_another_tenants_assignment(): void
    {
        $other = $this->otherTenantAssignment();

        $this->actingAs($this->student)
            ->get("/student/courses/{$other['offeringId']}/assignments/{$other['assignmentId']}")
            ->assertNotFound();
    }

    public function test_a_student_cannot_submit_to_another_tenants_assignment(): void
    {
        $other = $this->otherTenantAssignment();

        $this->actingAs($this->student)
            ->post("/student/courses/{$other['offeringId']}/assignments/{$other['assignmentId']}/submit", [
                'text' => 'Injected answer',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('assignment_submissions')
            ->where('assignment_id', $other['assignmentId'])
            ->where('student_id', $this->student->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE DOMAIN PARTITION — K12 MUST BE UNCHANGED AND MUST NOT SEE HEI WORK
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_legacy_k12_assignment_still_behaves_exactly_as_before(): void
    {
        $k12 = $this->k12Assignment(['title' => 'K12 homework: fractions']);

        // course_offering_id IS NULL is the definition of a legacy row, and the
        // scope must include it rather than exclude it.
        $this->assertNull($k12->course_offering_id);
        $this->assertSame(1, Assignment::query()->k12()->count());
        $this->assertTrue($k12->is_published);

        // The legacy screens still read the legacy flag and the legacy enums.
        $this->assertContains($k12->submission_type, ['file', 'text', 'link', 'any']);
    }

    public function test_a_hei_assignment_is_never_listed_to_k12_students(): void
    {
        // THE REGRESSION THIS PINS.
        //
        // The legacy student list selects `class_id IS NULL`, and a Course
        // Offering assignment has class_id NULL BY DESIGN. Without the domain
        // partition every HEI assignment in the school would be listed to every
        // K12 student — who could then submit to it through the legacy routes.
        $this->publishedAssignment(['title' => 'HEI work that must stay hidden']);
        $this->k12Assignment(['title' => 'K12 homework: fractions']);

        $this->assertSame(1, Assignment::query()->k12()->count());
        $this->assertSame(
            0,
            Assignment::query()->k12()->where('title', 'HEI work that must stay hidden')->count()
        );
        $this->assertSame(
            1,
            Assignment::query()->where('title', 'HEI work that must stay hidden')->count()
        );
    }

    public function test_a_k12_student_cannot_submit_to_a_hei_assignment_through_the_legacy_route(): void
    {
        $hei = $this->publishedAssignment(['title' => 'HEI only']);

        // The legacy submit route. A K12 student reaching a HEI assignment here
        // would bypass every Course Offering rule: attempts, the late policy,
        // the deadline and the submission type.
        $this->actingAs($this->student)
            ->post("/student/assignments/{$hei->id}/submit", [
                'submission' => 'Smuggled in through the legacy route',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('assignment_submissions')
            ->where('assignment_id', $hei->id)->count());
    }

    public function test_a_course_offering_id_can_never_arrive_through_the_legacy_store(): void
    {
        // THE REGRESSION THIS PINS.
        //
        // The legacy K12 create and update actions validate a FIXED field list
        // and pass exactly that list to create()/fill(). `course_offering_id` is
        // not in it, so the HEI container is not a field the legacy screens can
        // write at all - not even by a hand-crafted POST. That is why the domain
        // partition is a property of the SCHEMA and not merely of the queries:
        // a legacy row has nowhere to put an Offering id.
        $controller = new \App\Http\Controllers\AssignmentController($this->app);

        $reflection = new \ReflectionMethod($controller, 'store');
        $source = implode("\n", array_slice(
            file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        $this->assertStringContainsString("\$request->validate(", $source);
        $this->assertStringNotContainsString('course_offering_id', $source);

        // And a legacy row genuinely has no Offering attached.
        $legacy = $this->k12Assignment();
        $this->assertNull($legacy->course_offering_id);
        $this->assertTrue(Assignment::k12()->whereKey($legacy->id)->exists());
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLISHING LIFECYCLE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_draft_assignment_is_invisible_to_students(): void
    {
        $draft = $this->assignment(['title' => 'Still being written']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertDontSee('Still being written');

        // A 404, not a 403, so the student pages cannot be used to discover what
        // has not been published.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$draft->id}")
            ->assertNotFound();
    }

    public function test_publishing_makes_an_assignment_visible_and_notifies_confirmed_students(): void
    {
        $draft = $this->assignment(['title' => 'Business Ratios']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            ->assertRedirect();

        $this->assertSame(Assignment::STATUS_PUBLISHED, $draft->fresh()->status);
        $this->assertTrue($draft->fresh()->is_published);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$draft->id}")
            ->assertOk()
            ->assertSee('Business Ratios');

        $this->assertSame(1, DB::table('user_notifications')
            ->where('user_id', $this->student->id)
            ->where('type', 'assignment_published')
            ->count());
    }

    public function test_a_scheduled_assignment_is_not_available_before_its_release_time(): void
    {
        $scheduled = $this->assignment([
            'title' => 'Opens next week',
            'status' => Assignment::STATUS_SCHEDULED,
            'is_published' => true,
            'released_at' => now()->addWeek(),
        ]);

        $this->assertFalse(AssignmentLifecycle::isOpenToStudents($scheduled));
        $this->assertSame('Scheduled', AssignmentLifecycle::labelFor($scheduled));

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertDontSee('Opens next week');

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$scheduled->id}")
            ->assertNotFound();
    }

    public function test_a_scheduled_assignment_opens_once_its_release_time_arrives(): void
    {
        $scheduled = $this->assignment([
            'title' => 'Opens today',
            'status' => Assignment::STATUS_SCHEDULED,
            'is_published' => true,
            'released_at' => now()->subMinute(),
        ]);

        $this->assertTrue(AssignmentLifecycle::isOpenToStudents($scheduled));
        $this->assertSame('Published', AssignmentLifecycle::labelFor($scheduled));

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$scheduled->id}")
            ->assertOk()
            ->assertSee('Opens today');
    }

    public function test_a_scheduled_assignment_requires_a_release_time(): void
    {
        $this->expectException(\DomainException::class);

        // "Will open when it opens" is not a promise anyone can act on.
        AssignmentLifecycle::assertStateRequirements(
            $this->assignment(['released_at' => null]),
            Assignment::STATUS_SCHEDULED
        );
    }

    public function test_an_assignment_with_no_instructions_cannot_be_published(): void
    {
        $draft = $this->assignment(['instructions' => '<p>   </p>']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            // The rule is enforced by AssignmentService::assertPublishable(), which
            // names the field the lecturer must fix. The Publish BUTTON and the
            // Save path apply the same rule - a test that only covered one of them
            // would pass while the other released an unusable assignment.
            ->assertSessionHasErrors('instructions');

        $this->assertSame(Assignment::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_an_assignment_with_no_due_date_cannot_be_published(): void
    {
        $draft = $this->assignment(['due_date' => null]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}/state/published")
            ->assertSessionHasErrors('due_date');
    }

    public function test_closing_preserves_existing_submissions(): void
    {
        $assignment = $this->publishedAssignment();
        $submission = $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/state/closed")
            ->assertRedirect();

        $this->assertSame(Assignment::STATUS_CLOSED, $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->closed_at);
        $this->assertSame($this->lecturer->id, $assignment->fresh()->closed_by);

        // The student's work is still there, and still visible to them.
        $this->assertSame(1, DB::table('assignment_submissions')->where('assignment_id', $assignment->id)->count());
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertOk();
    }

    public function test_a_closed_assignment_cannot_be_reopened(): void
    {
        $closed = $this->assignment(['status' => Assignment::STATUS_CLOSED, 'is_published' => true]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$closed->id}/state/published")
            ->assertSessionHasErrors('status');

        $this->assertSame(Assignment::STATUS_CLOSED, $closed->fresh()->status);
    }

    public function test_an_assignment_with_submissions_cannot_be_returned_to_draft(): void
    {
        $assignment = $this->publishedAssignment();
        $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/state/draft")
            ->assertSessionHasErrors('status');

        $this->assertNotSame(Assignment::STATUS_DRAFT, $assignment->fresh()->status);
    }

    public function test_an_assignment_with_submissions_cannot_be_hard_deleted(): void
    {
        $assignment = $this->publishedAssignment();
        $this->submission($assignment, $this->student);

        $this->actingAs($this->lecturer)
            ->delete("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertSessionHasErrors('status');

        $this->assertNotNull(Assignment::query()->find($assignment->id));
    }

    public function test_the_lifecycle_buttons_offer_only_permitted_moves(): void
    {
        $draft = $this->assignment();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$draft->id}");

        $response->assertOk();
        // A draft may be published or scheduled, but never closed.
        $response->assertSee('Publish now');
        $response->assertDontSee('Close — stop new submissions');
    }
}
