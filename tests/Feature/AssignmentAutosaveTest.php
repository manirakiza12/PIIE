<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Support\Assignments\QuestionResponseService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Answers save as the student types.
 *
 * WHY THIS IS NOT JUST A DEBOUNCE
 *
 * The endpoint is the easy half. The half that matters is what the client does
 * with it, and what it refuses to do: a submit that beats an in-flight save hands
 * in a STALE copy, which is the bug the exam engine already fixed by refusing the
 * submit rather than racing it. This endpoint exists so the assignment page can
 * make the same promise - and keep it.
 *
 * WHAT IT MUST NOT DO
 *
 * Autosave writes through `saveDraft`, so it consumes NO attempt. A student who
 * types for ten minutes with autosave running has spent nothing. That guarantee is
 * asserted directly, because an autosave that quietly cost an attempt would be
 * worse than no autosave at all.
 */
class AssignmentAutosaveTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    /**
     * A published, question-based assignment.
     *
     * `$attributes` must be MERGED, not ignored. A helper declared with no
     * parameters silently swallows an override passed by the caller - PHP does not
     * complain about extra arguments to a user-defined function - so
     * `questionAssignment(['status' => closed])` built a PUBLISHED assignment and
     * the test then failed for a reason that had nothing to do with what it was
     * checking.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function questionAssignment(array $attributes = []): Assignment
    {
        return $this->publishedAssignment(array_merge([
            'title' => 'Question paper',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 3,
        ], $attributes));
    }

    private function url(Assignment $assignment): string
    {
        return route('student.courses.assignments.autosave', [$this->offering->id, $assignment->id]);
    }

    // ── The endpoint ────────────────────────────────────────────────────────

    public function test_a_student_can_save_one_answer_without_submitting(): void
    {
        $assignment = $this->questionAssignment();
        $question = $this->question($assignment, ['prompt' => 'Explain the ratio']);

        $response = $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'Because it compares two quantities.',
            ]);

        $response->assertOk()->assertJson(['saved' => true]);

        $stored = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertTrue((bool) $stored->is_draft, 'an autosaved answer is a DRAFT');
    }

    public function test_autosave_consumes_no_attempt(): void
    {
        // THE guarantee. An autosave that cost an attempt would be worse than no
        // autosave: a student answering twenty questions would arrive at Submit
        // having already spent attempts they never meant to use.
        $assignment = $this->questionAssignment();
        $question = $this->question($assignment, ['prompt' => 'Explain the ratio']);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->student)
                ->postJson($this->url($assignment), [
                    'question_id' => $question->id,
                    'text' => 'Draft number '.$i,
                ])
                ->assertOk();
        }

        $used = app(\App\Support\Assignments\SubmissionService::class)
            ->attemptsUsed($assignment, $this->student);

        $this->assertSame(0, $used, 'five autosaves must cost nothing');
    }

    public function test_the_response_carries_a_timestamp_the_student_can_be_shown(): void
    {
        // The indicator is the feature. "Saved 14:32" is what makes a student trust
        // it; a bare 200 proves nothing to a person.
        $assignment = $this->questionAssignment();
        $question = $this->question($assignment, ['prompt' => 'Explain the ratio']);

        $response = $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'An answer.',
            ]);

        $this->assertArrayHasKey('saved_at', $response->json());
        $this->assertNotEmpty($response->json('saved_at'));
    }

    // ── Authority ───────────────────────────────────────────────────────────

    public function test_another_student_cannot_autosave_into_someone_elses_draft(): void
    {
        $assignment = $this->questionAssignment();
        $question = $this->question($assignment, ['prompt' => 'Explain the ratio']);

        $stranger = \App\Models\User::query()->create([
            'school_id' => $this->school,
            'role_id' => 7,
            'name' => 'Unregistered Stranger',
            'email' => 'autosave-stranger@example.test',
            'account_status' => 'active',
        ]);
        $this->grantStudyEligibilityFixture($stranger);

        $this->actingAs($stranger)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'Not my answer.',
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_id', $stranger->id)
                ->count()
        );
    }

    public function test_autosave_is_refused_once_the_attempts_are_spent(): void
    {
        // Otherwise autosave becomes a way to keep writing after the limit - and the
        // deadline with it. It is gated by exactly the same rule as the manual save.
        $assignment = $this->publishedAssignment([
            'title' => 'Single attempt',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'allowed_attempts' => 1,
        ]);
        $question = $this->question($assignment, ['prompt' => 'Explain']);

        $this->submission($assignment, $this->student, [
            'marks_awarded' => 90,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
        ]);

        $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'One more go.',
            ])
            ->assertStatus(422);
    }

    public function test_autosave_is_refused_on_a_closed_assignment(): void
    {
        // A closed assignment stays READABLE on purpose - a student must be able to
        // see their submission and any released mark on it. So this is not a 404:
        // the read succeeds and the WRITE is refused, which is the distinction that
        // matters. A student who cannot see what they handed in cannot tell a
        // deadline apart from a mistake.
        $assignment = $this->questionAssignment(['status' => Assignment::STATUS_CLOSED]);
        $question = $this->question($assignment, ['prompt' => 'Explain']);

        $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'Late answer.',
            ])
            ->assertStatus(422);

        $this->assertSame(
            0,
            AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->count(),
            'a closed assignment must accept no new work at all'
        );
    }

    public function test_a_question_from_another_assignment_is_refused(): void
    {
        // The answer is written against ONE attempt of ONE assignment. A question id
        // belonging elsewhere would otherwise attach an answer to the wrong paper.
        $assignment = $this->questionAssignment();
        $foreign = $this->question($this->publishedAssignment(['title' => 'Another paper']), [
            'prompt' => 'Not this one',
        ]);

        $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $foreign->id,
                'text' => 'Misplaced.',
            ])
            ->assertStatus(422);
    }

    // ── The page wires it up ────────────────────────────────────────────────

    public function test_the_student_page_offers_the_autosave_endpoint(): void
    {
        $assignment = $this->questionAssignment();
        $this->question($assignment, ['prompt' => 'Explain']);

        $content = $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-autosave-url', $content);
        $this->assertStringContainsString('assignment-autosave.js', $content);
        // The indicator is what makes the feature trustworthy.
        $this->assertStringContainsString('as-autosave-status', $content);
    }

    public function test_the_answers_are_actually_reachable_through_the_endpoint(): void
    {
        // The saved value has to survive in the same place the Save button writes,
        // or autosaving would be storing a copy nothing ever reads.
        $assignment = $this->questionAssignment();
        $question = $this->question($assignment, ['prompt' => 'Explain the ratio']);

        $this->actingAs($this->student)
            ->postJson($this->url($assignment), [
                'question_id' => $question->id,
                'text' => 'Stored where the reader looks.',
            ])
            ->assertOk();

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)
            ->first();

        $responses = DB::table('assignment_question_responses')
            ->where('assignment_submission_id', $submission->id)
            ->where('assignment_question_id', $question->id)
            ->get();

        $this->assertCount(1, $responses);
        $this->assertSame('Stored where the reader looks.', $responses->first()->text_response);
    }
}
