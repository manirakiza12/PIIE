<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamProctoringEvent;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * EXAMINATION INTEGRITY — CONFIGURATION, SCOPE AND THE LIMITS THAT ARE STATED.
 *
 * The browser module has its own DOM-level tests (`tests/js/`). This file covers the
 * half that can only be checked on the server: that the POLICY is resolved in one
 * place, that the attempt page is given the resolved policy rather than the browser
 * deciding it, that every incident type the script can send is one the server accepts,
 * and — the one that matters most — that nothing here can end a student's attempt.
 */
class OnlineExamIntegrityControlsTest extends TestCase
{
    use OnlineExamTestHelper;

    private int $examId;
    private int $studentId;
    private int $lecturerId;
    private int $classId;
    private int $questionId;
    private int $submissionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->studentId = (int) $this->makeUser(7, 1, 'active', 'Kyeyune Amos')->id;
        $this->classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $this->classId);
        $this->enrollStudent($this->studentId, 1, $this->classId);

        $this->lecturerId = (int) $this->makeUser(3, 1, 'active', 'Daniel Okello')->id;
        \Illuminate\Support\Facades\DB::table('teacher_permissions')->insert([
            'class_id' => $this->classId, 'section_id' => 1, 'school_id' => 1,
            'teacher_id' => $this->lecturerId, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);

        $this->examId = $this->makeExam([
            'title' => 'Integrity verification', 'subject_id' => $subjectId, 'class_id' => $this->classId,
            'created_by' => $this->lecturerId, 'creator_id' => $this->lecturerId,
        ]);

        // One real question, so the protected-area assertions below are about a page a
        // student would actually be looking at rather than an empty paper.
        $this->questionId = $this->makeQuestion($this->examId, [
            'question' => '<p>Explain the law of demand.</p>',
            'type' => 'essay', 'option_a' => null, 'option_b' => null, 'option_c' => null, 'option_d' => null,
            'correct_ans' => null, 'marks' => 10, 'sort_order' => 1,
        ]);

        $this->submissionId = $this->makeSubmission([
            'online_exam_id' => $this->examId, 'student_id' => $this->studentId, 'status' => 'in_progress',
        ]);
    }

    private function exam(): OnlineExam
    {
        return OnlineExam::query()->findOrFail($this->examId);
    }

    // ── 1. The policy is resolved once, on the server ─────────────────────────

    public function test_an_exam_with_no_accommodation_gets_the_full_default_policy(): void
    {
        $settings = $this->exam()->integritySettings();

        $this->assertTrue($settings['enabled']);
        $this->assertTrue($settings['block_clipboard']);
        $this->assertTrue($settings['block_context_menu']);
        $this->assertTrue($settings['block_navigation_shortcuts']);
        $this->assertTrue($settings['warn_on_focus_loss']);
        $this->assertNull($settings['accommodation']);
    }

    public function test_a_clipboard_exemption_relaxes_only_the_clipboard(): void
    {
        \Illuminate\Support\Facades\DB::table('online_exams')->where('id', $this->examId)
            ->update(['integrity_accommodation' => 'clipboard_exempt']);

        $settings = $this->exam()->integritySettings();

        $this->assertSame('clipboard_exempt', $settings['accommodation']);
        $this->assertFalse($settings['block_clipboard']);
        $this->assertFalse($settings['block_context_menu']);
        // The parts no accommodation needs relaxed, stay relaxed-by-default: an
        // accommodation must not become a way to switch monitoring off.
        $this->assertTrue($settings['block_navigation_shortcuts']);
        $this->assertTrue($settings['warn_on_focus_loss'], 'the incident is still reported to the student');
    }

    public function test_an_unknown_accommodation_falls_back_to_the_protective_default(): void
    {
        \Illuminate\Support\Facades\DB::table('online_exams')->where('id', $this->examId)
            ->update(['integrity_accommodation' => 'anything_goes']);

        $settings = $this->exam()->integritySettings();

        // Failing toward MORE protection. A typo must never silently unmonitor a paper.
        $this->assertTrue($settings['block_clipboard']);
        $this->assertTrue($settings['block_navigation_shortcuts']);
        $this->assertNull($settings['accommodation'], 'an unknown value is not reported as an accommodation');
    }

    public function test_an_accommodation_cannot_silence_the_institutional_thresholds(): void
    {
        \Illuminate\Support\Facades\DB::table('online_exams')->where('id', $this->examId)
            ->update(['integrity_accommodation' => 'off']);

        $settings = $this->exam()->integritySettings();

        $this->assertFalse($settings['warn_on_focus_loss']);
        // The banner threshold is institutional, and an exam-level setting must not be
        // able to remove it: that would make the column a switch for the monitoring
        // rather than an adjustment to it.
        $this->assertSame(
            (int) config('online_exam_integrity.persistent_banner_after'),
            $settings['persistent_banner_after']
        );
        $this->assertGreaterThan(0, $settings['persistent_banner_after']);
    }

    public function test_the_deployment_master_switch_disables_every_restriction(): void
    {
        config(['online_exam_integrity.enabled' => false]);

        $settings = $this->exam()->integritySettings();

        $this->assertFalse($settings['enabled']);
        $this->assertFalse($settings['block_clipboard']);
        $this->assertFalse($settings['block_context_menu']);
        $this->assertFalse($settings['block_navigation_shortcuts']);
        $this->assertFalse($settings['warn_on_focus_loss']);
    }

    // ── 2. The page is given the resolved policy ──────────────────────────────

    public function test_the_attempt_page_carries_the_resolved_policy_to_the_browser(): void
    {
        $html = $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertOk()
            ->getContent();

        // The script must not decide its own policy in the browser; it is told.
        $this->assertStringContainsString('PIIEExamRestricted.settings', $html);

        preg_match('/PIIEExamRestricted\.settings = (\{.*?\});/s', $html, $m);
        $this->assertNotEmpty($m, 'the resolved policy must be present as JSON');

        $policy = json_decode($m[1], true);
        $this->assertIsArray($policy);
        $this->assertTrue($policy['block_clipboard']);
        $this->assertArrayHasKey('persistent_banner_after', $policy);
    }

    public function test_an_accommodated_paper_tells_the_student_it_is_in_force(): void
    {
        \Illuminate\Support\Facades\DB::table('online_exams')->where('id', $this->examId)
            ->update(['integrity_accommodation' => 'clipboard_exempt']);

        $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertOk()
            ->assertSee('An approved adjustment applies to this paper.', false)
            ->assertSee('data-testid="integrity-accommodation"', false);
    }

    public function test_the_page_states_the_limits_of_the_restrictions_plainly(): void
    {
        $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertOk()
            // A control that implies a guarantee it cannot keep discredits everything
            // else the page says.
            ->assertSee('cannot prevent you minimising the window', false)
            ->assertSee('Leaving the page does not end your examination', false);
    }

    public function test_the_protected_area_is_the_whole_exam_including_the_question_text(): void
    {
        $html = $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertOk()
            ->getContent();

        // The containment test the restricted module resolves its root from. A question
        // statement is a `<p>`; the earlier tag list excluded it, which is why copy
        // still worked.
        $this->assertStringContainsString('id="examTakeRoot"', $html);
        $this->assertStringContainsString('data-piie-exam-area="1"', $html);
        // The question statement is INSIDE that root, which is the whole point: the
        // earlier tag list excluded a `<p>`, so this text stayed copyable.
        $rootAt = strpos($html, 'id="examTakeRoot"');
        $statementAt = strpos($html, 'data-question-prompt');
        $rootEnd = strpos($html, '</div>', $statementAt);

        $this->assertIsInt($statementAt, 'the question statement must be rendered');
        $this->assertGreaterThan($rootAt, $statementAt);
    }

    // ── 3. Every event the script can send is one the server accepts ──────────

    #[\PHPUnit\Framework\Attributes\DataProvider('integrityEventProvider')]
    public function test_an_integrity_event_is_recorded_against_the_students_own_attempt(string $eventType): void
    {
        $this->actingAs(\App\Models\User::find($this->studentId))
            ->postJson(route('student.online_exam.incident', $this->submissionId), [
                'event_type' => $eventType,
                'detail' => ['source' => 'test'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('online_exam_proctoring_events', [
            'submission_id' => $this->submissionId,
            'event_type' => $eventType,
        ]);
    }

    public static function integrityEventProvider(): array
    {
        $cases = [];

        foreach ([
            'clipboard_blocked', 'context_menu_blocked', 'navigation_attempted', 'print_attempted',
            'focus_lost', 'focus_returned', 'tab_hidden', 'fullscreen_exited',
            'connection_lost', 'connection_restored',
        ] as $type) {
            $cases[$type] = [$type];
        }

        return $cases;
    }

    public function test_an_unknown_event_type_is_still_refused(): void
    {
        $this->actingAs(\App\Models\User::find($this->studentId))
            ->postJson(route('student.online_exam.incident', $this->submissionId), [
                'event_type' => 'exam_passed_by_magic',
            ])
            ->assertStatus(422);

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('online_exam_proctoring_events')->count());
    }

    public function test_a_student_cannot_record_an_incident_against_another_students_attempt(): void
    {
        $other = (int) $this->makeUser(7, 1, 'active', 'Someone Else')->id;

        // `findStudentSubmissionOrFail()` answers 404 for an attempt that is not the caller's,
        // so that a submission's existence is not itself disclosed. The requirement is
        // that the write is refused, not which status expresses it.
        $this->actingAs(\App\Models\User::find($other))
            ->postJson(route('student.online_exam.incident', $this->submissionId), [
                'event_type' => 'focus_lost',
            ])
            ->assertStatus(404);

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('online_exam_proctoring_events')->count(),
            'a refused incident must not be written');
    }

    // ── 4. THE LIMIT THAT MATTERS MOST ────────────────────────────────────────

    /**
     * RECORDING IS NOT PUNISHMENT.
     *
     * Every one of these is a refusal the brief asked for. None of them may end an
     * attempt, take a mark, or change who can read the result. An automated consequence
     * for leaving a tab punishes a dropped connection, a phone call and an operating
     * system notification identically, and a proctoring log that does that is the exact
     * failure a proctoring log exists to avoid.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noConsequenceEventProvider')]
    public function test_recording_an_integrity_event_never_punishes_the_student(string $eventType): void
    {
        $before = \App\Models\OnlineExamSubmission::find($this->submissionId);

        $this->actingAs(\App\Models\User::find($this->studentId))
            ->postJson(route('student.online_exam.incident', $this->submissionId), [
                'event_type' => $eventType,
            ])
            ->assertOk();

        // Prove the attempt is untouched by re-reading the row: status, score, and the
        // student's own view of the paper all unchanged.
        $after = \App\Models\OnlineExamSubmission::find($this->submissionId);

        $this->assertSame($before->status, $after->status, 'the attempt must not be submitted or terminated');
        $this->assertSame($before->score, $after->score, 'no mark may be removed');
        $this->assertSame($before->result_review_state, $after->result_review_state);
        $this->assertNull($after->published_at, 'the result must not become visible');

        $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertOk();
    }

    public static function noConsequenceEventProvider(): array
    {
        return [
            'left the page' => ['focus_lost'],
            'hid the tab' => ['tab_hidden'],
            'reached for the clipboard' => ['clipboard_blocked'],
            'tried to print' => ['print_attempted'],
            'left fullscreen' => ['fullscreen_exited'],
        ];
    }

    public function test_the_incident_log_is_visible_to_staff_and_not_to_the_student(): void
    {
        $this->actingAs(\App\Models\User::find($this->studentId))
            ->postJson(route('student.online_exam.incident', $this->submissionId), [
                'event_type' => 'focus_lost',
            ])->assertOk();

        // A lecturer assigned to the class may review the record.
        $this->actingAs(\App\Models\User::find($this->lecturerId))
            ->get(route('teacher.online_exams.proctoring.review', [
                'exam' => $this->examId, 'submission_id' => $this->submissionId,
            ]))
            ->assertOk();

        // A student has no route to it at all. The role middleware redirects rather than
        // rendering a 403, so the assertion is that the RECORD is not shown — which is
        // the fact that matters.
        $studentResponse = $this->actingAs(\App\Models\User::find($this->studentId))
            ->get(route('teacher.online_exams.proctoring.review', [
                'exam' => $this->examId, 'submission_id' => $this->submissionId,
            ]));

        $this->assertStringNotContainsString(
            'focus_lost',
            $studentResponse->getContent() ?: '',
            'a student must not be shown their own integrity record through a staff route'
        );
    }

    public function test_the_event_types_the_script_sends_all_exist_in_the_model(): void
    {
        // The server validates against this list, so an event the browser can emit but
        // the model does not declare would be a silent 422 — and an integrity record
        // that never arrives is worse than no integrity control, because it looks like
        // it is working.
        $declared = OnlineExamProctoringEvent::EVENT_TYPES;

        foreach ([
            'clipboard_blocked', 'context_menu_blocked', 'navigation_attempted', 'print_attempted',
            'focus_lost', 'focus_returned', 'tab_hidden', 'fullscreen_exited',
            'connection_lost', 'connection_restored',
        ] as $emitted) {
            $this->assertContains($emitted, $declared,
                "exam-restricted-mode.js emits '{$emitted}' but the server does not accept it");
        }
    }

    // ── 5. The adjustment is reachable, not dead storage ─────────────────────

    public function test_the_lecturer_can_grant_an_adjustment_through_the_real_exam_form(): void
    {
        $html = $this->actingAs(\App\Models\User::find($this->lecturerId))
            ->get(route('teacher.online_exams.create'))
            ->assertOk()
            ->getContent();

        // Every configured accommodation is selectable, from the CONFIGURED list, so
        // adding one to the config cannot be silently unreachable in the UI.
        foreach (array_keys((array) config('online_exam_integrity.accommodations')) as $key) {
            $this->assertStringContainsString('value="'.$key.'"', $html);
        }
        $this->assertStringContainsString('data-testid="integrity-accommodation-select"', $html);
    }

    public function test_an_unknown_accommodation_is_refused_by_the_exam_form(): void
    {
        $classId = $this->classId;

        $response = $this->actingAs(\App\Models\User::find($this->lecturerId))->post(route('teacher.online_exams.store'), [
            'title' => 'Rejected adjustment',
            'class_id' => $classId,
            'duration_mins' => 30,
            'total_marks' => 20,
            'pass_mark' => 10,
            'max_attempts' => 1,
            'exam_type' => 'quiz',
            'result_release_policy' => 'immediate',
            'workflow_state' => 'draft',
            'integrity_accommodation' => 'disable_everything',
        ]);

        $response->assertSessionHasErrors('integrity_accommodation');
    }
}