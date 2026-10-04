<?php

namespace Tests\Feature;

use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamSubmission;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * Covers the student-facing exam-taking rebuild: instructions() used to be a
 * JSON-only endpoint nothing ever consumed (a browser landing there via the
 * normal "Start Exam" redirect got raw JSON with no way to proceed), and
 * takeExam() never applied shuffle_questions/shuffle_options despite both
 * columns existing, never showed a previously-saved answer on resume, and
 * redirected an expired attempt at a POST-only route via GET (a 405).
 */
class OnlineExamStudentFrontendTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
    }

    // ── instructions() ───────────────────────────────────────────────────

    public function test_instructions_renders_a_page_with_exam_details(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'title' => 'Midterm Test']);
        $this->makeQuestion($examId);

        $response = $this->actingAs($student)->get(route('student.online_exam.instructions', $examId));

        $response->assertStatus(200);
        $response->assertSee('Midterm Test');
        $response->assertSee('Student');
        $response->assertDontSee('Admin');
        $response->assertDontSee('Create Exam');
        $response->assertSee('fetch(startUrl', false);
        $response->assertSee(route('student.online_exam.start', $examId), false);
        $response->assertViewIs('student.online_exam.instructions');
    }

    public function test_student_cannot_be_stranded_on_the_admin_disabled_account_page(): void
    {
        $student = $this->makeUser(7, 1);

        $response = $this->actingAs($student)->get(route('admin.account_disableview'));

        $response->assertRedirect(route('student.account_disable'));
    }

    public function test_instructions_redirects_straight_to_take_when_an_attempt_is_already_active(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId]);
        $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        $response = $this->actingAs($student)->get(route('student.online_exam.instructions', $examId));

        $response->assertRedirect(route('student.online_exam.take', $examId));
    }

    public function test_submitted_manual_result_shows_safe_awaiting_marking_state(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'result_release_policy' => 'manual',
        ]);
        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'pending_manual_marking',
            'submitted_at' => now(),
        ]);

        $this->actingAs($student)
            ->get(route('student.online_exam.result', $submissionId))
            ->assertOk()
            ->assertSee('Exam submitted successfully.')
            // `pending_manual_marking` means a human still has work to do, so the student is
            // told the result is being processed. The old page branched on `status` and
            // could report "finalized and awaiting publication" for a submission whose
            // marking had never happened — which is what exam 17 submission 12 showed.
            ->assertSee('Your exam has been submitted successfully. Your result is being processed.')
            ->assertDontSee('awaiting publication')
            ->assertSee('being processed')
            ->assertViewIs('student.online_exam.submitted');
    }

    // ── Scheduled window: every student sits it at the same time ───────────

    public function test_instructions_hides_the_start_button_before_the_exam_opens(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1, 'class_id' => $classId,
            'start_datetime' => now()->addHour(),
            'end_datetime' => now()->addHours(2),
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.instructions', $examId));

        $response->assertStatus(200);
        $response->assertDontSee('id="startExamBtn"', false);
        $response->assertSee('has not opened yet');
    }

    public function test_instructions_hides_the_start_button_after_the_exam_window_closes(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1, 'class_id' => $classId,
            'start_datetime' => now()->subHours(3),
            'end_datetime' => now()->subHour(),
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.instructions', $examId));

        $response->assertStatus(200);
        $response->assertDontSee('id="startExamBtn"', false);
        $response->assertSee('window has closed');
    }

    public function test_start_is_rejected_before_the_scheduled_opening_time(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1, 'class_id' => $classId,
            'start_datetime' => now()->addHour(),
            'end_datetime' => now()->addHours(2),
        ]);

        // Accept: application/json matches how the real "Start Exam" button
        // actually calls this endpoint (fetch() with that header set) — a
        // plain form post instead gets Laravel's default redirect-with-
        // errors behavior for a failed FormRequest, not 422.
        $response = $this->actingAs($student)->postJson(route('student.online_exam.start', $examId), []);

        $response->assertStatus(422);
        $this->assertSame(0, OnlineExamSubmission::where('online_exam_id', $examId)->count());
    }

    public function test_start_is_rejected_after_the_scheduled_window_closes(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1, 'class_id' => $classId,
            'start_datetime' => now()->subHours(3),
            'end_datetime' => now()->subHour(),
        ]);

        $response = $this->actingAs($student)->postJson(route('student.online_exam.start', $examId), []);

        $response->assertStatus(422);
        $this->assertSame(0, OnlineExamSubmission::where('online_exam_id', $examId)->count());
    }

    public function test_start_succeeds_inside_the_scheduled_window(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1, 'class_id' => $classId,
            'start_datetime' => now()->subMinutes(10),
            'end_datetime' => now()->addHour(),
        ]);

        $response = $this->actingAs($student)->post(route('student.online_exam.start', $examId), [
            'instructions_acknowledged' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, OnlineExamSubmission::where('online_exam_id', $examId)->where('student_id', $student->id)->count());
    }

    public function test_schedule_uses_the_configured_institution_timezone_for_listing_and_start(): void
    {
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'timezone'],
            ['value' => 'Africa/Nairobi', 'created_at' => now(), 'updated_at' => now()]
        );

        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);
        $localNow = Carbon::now('Africa/Nairobi');
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => $localNow->copy()->subMinute()->format('Y-m-d H:i:s'),
            'end_datetime' => $localNow->copy()->addHour()->format('Y-m-d H:i:s'),
        ]);

        $this->actingAs($student)->get(route('student.online_exam.list'))
            ->assertOk()
            ->assertSee('Start Exam');

        $this->actingAs($student)->postJson(route('student.online_exam.start', $examId), [
            'instructions_acknowledged' => true,
        ])
            ->assertOk();
    }

    public function test_start_requires_instruction_acknowledgement(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->subMinute(),
            'end_datetime' => now()->addHour(),
        ]);

        $this->actingAs($student)
            ->postJson(route('student.online_exam.start', $examId), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('instructions_acknowledged');

        $this->assertSame(0, OnlineExamSubmission::where('online_exam_id', $examId)->count());
    }

    // ── takeExam(): shuffling ────────────────────────────────────────────

    public function test_question_order_is_stable_across_reloads_of_the_same_attempt_when_shuffled(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'shuffle_questions' => true]);
        for ($i = 1; $i <= 6; $i++) {
            $this->makeQuestion($examId, ['question' => "Question {$i}", 'sort_order' => $i]);
        }

        $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        $first = $this->actingAs($student)->get(route('student.online_exam.take', $examId));
        $second = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        $first->assertStatus(200);

        /**
         * The clock is excluded, and EVERY value derived from it has to be named.
         *
         * This normalisation used to cover one line, and the test passed — until the
         * timer moved to a deadline and the page began carrying the server's remaining
         * seconds in a second variable. That left a real difference between two renders
         * of the same attempt, so the test became intermittent: it passed when both
         * renders landed in the same second and failed when they straddled one.
         *
         * An intermittently failing test trains people to re-run it, which is how a real
         * regression gets waved through. So the rule is: any server value that is a
         * function of the current time is replaced, and the list is written out rather
         * than pattern-matched loosely.
         */
        $normalize = static function (string $html): string {
            $patterns = [
                // The authoritative remaining time, and the countdown derived from it.
                '/var serverRemainingSeconds = \d+;/' => 'var serverRemainingSeconds = __timer__;',
                '/var remainingSeconds = \d+;/' => 'var remainingSeconds = __timer__;',
                // Any ISO timestamp the page embeds for the client to re-sync against.
                '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}/' => '__timestamp__',
            ];

            foreach ($patterns as $pattern => $replacement) {
                $html = preg_replace($pattern, $replacement, $html) ?? $html;
            }

            return $html;
        };

        $this->assertSame($normalize($first->getContent()), $normalize($second->getContent()));
    }

    public function test_question_order_is_not_shuffled_when_the_exam_does_not_request_it(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'shuffle_questions' => false]);
        $q1 = $this->makeQuestion($examId, ['question' => 'Alpha Question', 'sort_order' => 1]);
        $q2 = $this->makeQuestion($examId, ['question' => 'Beta Question', 'sort_order' => 2]);

        $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        $response = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        $response->assertStatus(200);
        $alphaPos = strpos($response->getContent(), 'Alpha Question');
        $betaPos = strpos($response->getContent(), 'Beta Question');
        $this->assertLessThan($betaPos, $alphaPos, 'Unshuffled questions must render in sort_order.');
    }

    public function test_shuffled_option_display_order_still_submits_the_true_underlying_option_key(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'shuffle_options' => true]);
        $questionId = $this->makeQuestion($examId, [
            'option_a' => 'Correct Answer Text',
            'option_b' => 'Wrong One',
            'option_c' => 'Wrong Two',
            'option_d' => 'Wrong Three',
            'correct_ans' => 'a',
        ]);

        // takeExam() creates no submission itself; without one it redirects
        // to instructions, so an attempt is started directly via the model
        // to isolate this test to the rendering behaviour.
        $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        $response = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        $response->assertStatus(200);
        // Whatever position "Correct Answer Text" renders in, its radio
        // input's value attribute must still be the real key "a" — the
        // question of grading correctness must never depend on display order.
        $response->assertSee('value="a"', false);
        $response->assertSee('Correct Answer Text');
    }

    // ── takeExam(): resume pre-fill ──────────────────────────────────────

    public function test_a_previously_saved_answer_is_pre_filled_on_resume(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId]);
        $questionId = $this->makeQuestion($examId, ['type' => 'mcq']);
        $submissionId = $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        OnlineExamAnswer::create([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'selected_option' => 'b',
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        $response->assertStatus(200);
        $response->assertSee('id="q' . $questionId . 'b" data-question-id="' . $questionId . '" data-answer-type="option"
                                       checked', false);
    }

    public function test_an_essay_answer_text_is_pre_filled_on_resume(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId]);
        $questionId = $this->makeQuestion($examId, ['type' => 'essay', 'option_a' => null, 'option_b' => null, 'option_c' => null, 'option_d' => null]);
        $submissionId = $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1]);

        OnlineExamAnswer::create([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'answer_text' => 'My previously typed essay answer',
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        $response->assertStatus(200);
        $response->assertSee('My previously typed essay answer');
    }

    // ── takeExam(): expired attempt no longer 405s ───────────────────────

    public function test_visiting_take_on_an_expired_attempt_finalises_it_instead_of_erroring(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'pass_mark' => 5]);
        $questionId = $this->makeQuestion($examId, ['marks' => 10, 'correct_ans' => 'a']);
        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'started_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);

        OnlineExamAnswer::create([
            'submission_id' => $submissionId,
            'question_id' => $questionId,
            'selected_option' => 'a',
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.take', $examId));

        // Previously a 405 (GET against a POST-only route); must now CLOSE the
        // attempt and land on the results page instead.
        $response->assertRedirect(route('student.online_exam.result', $submissionId));

        /**
         * "CLOSED", not "finalized".
         *
         * An expired attempt must not be reopenable, and it must not be reported as
         * staff-finalised either. `finalized` is now written only by
         * `finalizeSubmission()` - a deliberate handover by a lecturer or an
         * administrator - because a student's own timeout reaching it is what created
         * submission 12: a result that read "Marking Complete / Finalized / Not
         * Released" with an empty Actions column and no queue it appeared in.
         *
         * One auto-marked MCQ here, so nothing needs a marker and `submitted` is the
         * honest state: closed, awaiting the handover gate.
         */
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            OnlineExamSubmission::find($submissionId)->status,
            'an expired attempt must not remain reopenable'
        );

        $this->assertSame(
            OnlineExamSubmission::STATUS_SUBMITTED,
            OnlineExamSubmission::find($submissionId)->status
        );
    }

    // ── list.blade.php routing ───────────────────────────────────────────

    public function test_list_offers_resume_not_view_result_for_an_in_progress_attempt(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId]);
        $this->makeSubmission(['online_exam_id' => $examId, 'student_id' => $student->id, 'school_id' => 1, 'status' => 'in_progress']);

        $response = $this->actingAs($student)->get(route('student.online_exam.list'));

        $response->assertStatus(200);
        $response->assertSee('Resume Exam');
        $response->assertDontSee('View Result');
    }

    public function test_list_offers_view_result_once_a_finalized_attempt_exists(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'result_release_policy' => 'immediate']);
        $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'finalized',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.list'));

        $response->assertStatus(200);
        $response->assertSee('Result Awaiting Release');
        $response->assertDontSee('View Result');
        $response->assertDontSee('Resume Exam');
    }

    public function test_list_explains_submitted_and_awaiting_release_states(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);
        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId, 'result_release_policy' => 'manual']);

        $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($student)->get(route('student.online_exam.list'))
            ->assertOk()
            ->assertSee('Marking Complete - Awaiting Finalization');

        DB::table('online_exam_submissions')->where('online_exam_id', $examId)->update(['status' => 'finalized']);

        $this->actingAs($student)->get(route('student.online_exam.list'))
            ->assertOk()
            ->assertSee('Result Awaiting Release');
    }

    public function test_expired_submitted_attempt_remains_in_history_and_released_result_is_linked(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'result_release_policy' => 'manual',
            'start_datetime' => now()->subHours(3),
            'end_datetime' => now()->subHour(),
        ]);
        $submission = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            'submitted_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($student)->get(route('student.online_exam.list'));

        $response->assertOk()
            ->assertSee('My Attempts &amp; Results', false)
            ->assertSee('Result Available')
            ->assertSee('View Result')
            ->assertSee(route('student.online_exam.result', $submission), false)
            ->assertDontSee('Start Exam');
    }

    public function test_list_offers_start_exam_when_the_student_has_never_attempted_it(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam(['school_id' => 1, 'class_id' => $classId]);

        $response = $this->actingAs($student)->get(route('student.online_exam.list'));

        $response->assertStatus(200);
        $response->assertSee('Start Exam');
        $response->assertSee(route('student.online_exam.instructions', $examId), false);
    }
}
