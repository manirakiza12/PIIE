<?php

namespace Tests\Feature;

use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE "RECORD 0 — NO ANSWER SUBMITTED" BUTTON, SUBMITTED THE WAY A BROWSER DOES.
 *
 * ── WHY THIS FILE EXISTS AT ALL ───────────────────────────────────────────────
 *
 * The button was rendered with NO `awarded_marks` input beside it. Pressing it
 * therefore POSTed only the CSRF token, so the controller's `required` rule failed
 * and the lecturer was bounced back with "awarded_marks is required" — forever.
 *
 * The button could never work, and no test noticed, because every earlier test POSTed
 * `['awarded_marks' => 0]` directly. Those tests exercised the CONTROLLER and never
 * the CONTROL: they passed while the feature was broken in the browser, which is the
 * worst possible kind of green.
 *
 * So every test here does what a browser does — it reads the rendered HTML, takes the
 * form's action and the fields actually present inside it, and submits exactly those.
 * If the markup and the controller ever disagree again, this fails.
 */
class OnlineExamRecordZeroButtonTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected $offering;
    protected $lecturer;
    protected $admin;
    protected $student;
    protected $exam;
    protected $writtenQ;
    protected $objectiveQ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();

        // Lecturer capabilities WITHOUT publish and WITHOUT manage_exam_results, so
        // the handover boundary is genuinely exercised rather than assumed.
        DB::table('global_settings')->where('key', 'role_perm_3')->delete();
        DB::table('global_settings')->insert([
            'key' => 'role_perm_3',
            'value' => json_encode([
                'view_online_exams', 'create_online_exams', 'edit_own_online_exams',
                'manage_exam_questions', 'view_exam_attempts', 'view_exam_results',
                'mark_exam_answers',
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->offering = $this->makeOffering(1);
        $this->lecturer = $this->makeUser(3, 1, 'active', 'Daniel Okello');
        $this->allocateLecturer($this->offering, $this->lecturer);

        $this->admin = $this->makeUser(2, 1, 'active', 'Administrator');
        $this->grantAdminPermissions();

        $this->student = $this->makeUser(7, 1, 'active', 'Kyeyune Amos');
        $this->confirmStudent($this->offering, $this->student);

        // ONE WRITTEN QUESTION AND ONE AUTO-MARKED MCQ. The paper is deliberately
        // minimal: this file is about the Record 0 control, so the fewer moving parts
        // around it the clearer the failure when something breaks.
        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Record Zero Button Paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);
        $this->exam = \App\Models\OnlineExam::query()->findOrFail($examId);

        $this->writtenQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>Explain compound interest.</p>', 'type' => 'short', 'marks' => 6, 'sort_order' => 1,
        ]);
        $this->objectiveQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>2 + 2 = ?</p>', 'type' => 'mcq',
            'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '6',
            'correct_ans' => 'b', 'marks' => 4, 'sort_order' => 2,
        ]);
    }

    /** Admin capabilities, mirroring the governance suite. */
    private function grantAdminPermissions(): void
    {
        DB::table('global_settings')->where('key', 'role_perm_2')->delete();
        DB::table('global_settings')->insert([
            'key' => 'role_perm_2',
            'value' => json_encode([
                'view_online_exams', 'admin.online_exams', 'create_online_exams',
                'edit_all_online_exams', 'manage_exam_questions', 'view_exam_attempts',
                'view_exam_results', 'mark_exam_answers', 'manage_exam_results',
                'publish_online_exams', 'review_exam_proctoring',
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * EXTRACT A FORM FROM RENDERED HTML AND SUBMIT EXACTLY ITS FIELDS.
     *
     * Deliberately crude: it does not know anything about the application. It reads
     * the action, the method, and every input/select/textarea the server actually
     * emitted, and posts those and nothing else — which is the whole point, since the
     * bug was a field the server did not emit.
     *
     * @return array{action: string, fields: array<string, string>}
     */
    private function formIn(string $html, string $needle): array
    {
        $this->assertStringContainsString($needle, $html, "no form containing: {$needle}");

        // Isolate the single form whose body mentions the needle.
        $offset = 0;
        while (($pos = strpos($html, $needle, $offset)) !== false) {
            $start = strrpos(substr($html, 0, $pos), '<form');
            if ($start !== false) {
                $end = strpos($html, '</form>', $pos);
                if ($end !== false) {
                    $form = substr($html, $start, $end - $start + 7);

                    if (preg_match('/action="([^"]+)"/', $form, $m)) {
                        $fields = [];

                        // Every field the server actually rendered.
                        if (preg_match_all('/<input\b[^>]*>/i', $form, $inputs)) {
                            foreach ($inputs[0] as $input) {
                                if (! preg_match('/\bname="([^"]+)"/', $input, $n)) {
                                    continue;
                                }
                                // Unchecked checkboxes and disabled inputs are not submitted.
                                if (preg_match('/\bdisabled\b/i', $input)) {
                                    continue;
                                }
                                if (preg_match('/\btype="(checkbox|radio)"/i', $input)
                                    && ! preg_match('/\bchecked\b/i', $input)) {
                                    continue;
                                }
                                $value = preg_match('/\bvalue="([^"]*)"/', $input, $v)
                                    ? html_entity_decode($v[1], ENT_QUOTES)
                                    : '';

                                $fields[html_entity_decode($n[1], ENT_QUOTES)] = $value;
                            }
                        }

                        return [
                            'action' => html_entity_decode($m[1], ENT_QUOTES),
                            'fields' => $fields,
                        ];
                    }
                }
            }
            $offset = $pos + strlen($needle);
        }

        $this->fail("could not isolate a form around: {$needle}");
    }

    /** @test */
    public function the_record_zero_button_submits_a_mark_of_zero_and_it_is_accepted(): void
    {
        $submission = $this->submittedWithABlankWrittenQuestion();
        $question = OnlineExamQuestion::query()->findOrFail($this->writtenQ);

        // What a lecturer actually sees.
        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk();

        $html = $page->getContent();

        // The control exists and says what it does.
        $this->assertStringContainsString('Record 0', $html, 'the button must be on the page');
        $this->assertStringContainsString('No answer was submitted', $html);

        // And it is a real form that a browser can submit.
        $form = $this->formIn($html, 'Record 0');
        $this->assertSame(
            route('teacher.online_exams.submissions.record_decision', [
                'submission' => $submission->id,
                'question' => $question->id,
            ]),
            $form['action'],
            'the form must post to the decision route for THIS submission and question'
        );

        /**
         * THE DEFECT, STATED AS AN ASSERTION.
         *
         * `awarded_marks` must be present in the submitted fields. It was absent, so
         * the button could only ever post a CSRF token and be rejected by `required`.
         */
        $this->assertArrayHasKey(
            'awarded_marks',
            $form['fields'],
            'the Record 0 button must submit a marking value; without one the request '
            . 'carries no award and validation rejects it'
        );

        // Numeric zero, submitted exactly as the browser would.
        $this->assertEquals(0, (float) $form['fields']['awarded_marks']);
        $this->assertNotSame('', trim($form['fields']['awarded_marks']));

        // Submit it.
        $response = $this->actingAs($this->lecturer)
            ->post($form['action'], $form['fields']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('teacher.online_exams.results', [
            'exam' => $this->exam->id,
            'submission' => $submission->id,
        ]));

        // Recorded, attributed, timestamped.
        $answer = \App\Models\OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertSame(0.0, (float) $answer->awarded_marks, 'zero must be stored as zero');
        $this->assertNotNull($answer->awarded_marks, 'zero must be stored, not treated as absent');
        $this->assertSame($this->lecturer->id, (int) $answer->marked_by);
        $this->assertNotNull($answer->marked_at);

        // The fact that nothing was answered is preserved.
        $this->assertNull($answer->answer_text);
        $this->assertNull($answer->selected_option);
        $this->assertFalse(\App\Support\OnlineExams\OnlineExamMarking::hasResponse($answer));

        // Pending falls to zero, so the handover becomes available.
        $submission->refresh()->load(['exam.questions', 'answerRows']);
        $this->assertFalse(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission)
        );
    }

    /** @test */
    public function the_marking_queue_link_is_a_real_working_link_for_the_exact_submission(): void
    {
        $submission = $this->submittedWithABlankWrittenQuestion();

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->getContent();

        // Not the old dead-end label.
        $this->assertStringNotContainsString(
            'Decide on the submissions page',
            $html,
            'the queue must not tell a marker to go and find it themselves'
        );

        // A real href pointing at this submission AND at its row on the results page.
        //
        // The `#submission-{id}` fragment is asserted too, because "open the exact
        // submission" means the exact ROW: a marker clicking this should land on the
        // row that needs deciding, not merely on a page where they must hunt for it.
        $expected = route('teacher.online_exams.results', [
            'exam' => $this->exam->id,
            'submission' => $submission->id,
        ]) . '#submission-' . $submission->id;

        $this->assertStringContainsString(
            'href="' . htmlspecialchars($expected, ENT_QUOTES) . '"',
            $html,
            'the queue must link to the exact submission row by named route'
        );

        // And the link actually resolves, rather than 404ing. The fragment is not part of
        // the request, so the URL is stripped before it is followed.
        $this->actingAs($this->lecturer)
            ->get(strtok($expected, '#'))
            ->assertOk();
    }

    /** @test */
    public function after_recording_zero_the_handover_button_is_offered_and_works(): void
    {
        $submission = $this->submittedWithABlankWrittenQuestion();
        $question = OnlineExamQuestion::query()->findOrFail($this->writtenQ);

        // Before: no handover offered.
        $before = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('action-submit-for-review', $before);
        $this->assertStringContainsString('still requires a marking decision', $before);

        // Record the zero through the real form.
        $form = $this->formIn($before, 'Record 0');
        $this->actingAs($this->lecturer)->post($form['action'], $form['fields'])
            ->assertSessionHasNoErrors();

        // After: the handover button is offered, and it succeeds.
        $after = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action-submit-for-review', $after);
        $this->assertStringNotContainsString('still requires a marking decision', $after);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);

        // And an administrator can now release it, which the student then sees.
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->assertSee('Automatic Marks');
    }

    /**
     * A SUBMITTED ATTEMPT WHOSE WRITTEN QUESTION HAS NO ANSWER ROW.
     *
     * Built by hand rather than by clearing an answer, because the condition under
     * test is precisely "the row was never created" — the historical shape of exam 17
     * submission 12, reproduced in a fixture so the production record stays untouched.
     */
    private function submittedWithABlankWrittenQuestion(): OnlineExamSubmission
    {
        $submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'school_id' => 1,
            'total_marks_snapshot' => 10,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Only the MCQ is answered. The written question gets NO row at all.
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $submissionId), [
            'submission_id' => $submissionId,
            'question_id' => $this->objectiveQ,
            'answer_revision' => 1,
            'selected_option' => 'b',
        ])->assertOk();

        $this->assertSame(0, (int) DB::table('online_exam_answers')
            ->where('submission_id', $submissionId)
            ->where('question_id', $this->writtenQ)
            ->count(), 'the fixture must reproduce a question with no answer row');

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $this->exam->id), ['submission_id' => $submissionId])
            ->assertSessionHasNoErrors();

        return OnlineExamSubmission::query()->findOrFail($submissionId);
    }
}