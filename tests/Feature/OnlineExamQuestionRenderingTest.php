<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * WHAT THE STUDENT'S EXAM PAGE ACTUALLY RENDERS.
 *
 * Exam 19 reported three symptoms that all point at the same place: question 3 showed
 * TWO toolbars, question 4 had no usable input, and question 3's saved answer was the
 * eleven characters `<p><br></p>`.
 *
 * Those are only explicable if the editors are not one-per-question on the page. So
 * these tests assert the RENDERED STRUCTURE — shells, textareas, ids, question ids and
 * nesting — because that is the layer where the defect lives, and no controller test
 * can see it.
 */
class OnlineExamQuestionRenderingTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected $offering;
    protected $lecturer;
    protected $admin;
    protected $student;
    protected $exam;

    /** qid => [type, marks] for the four-question paper. */
    protected $questionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();

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
        $this->student = $this->makeUser(7, 1, 'active', 'Kyeyune Amos');
        $this->confirmStudent($this->offering, $this->student);

        // THE SHAPE OF EXAM 19: one MCQ, one true/false, one short answer worth 10,
        // one essay worth 4. The two written questions are the ones that misbehaved, and
        // a paper with only one of them cannot reproduce a collision between editors.
        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Rendering Integrity Paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 45,
            'max_attempts' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);
        $this->exam = OnlineExam::query()->findOrFail($examId);

        $this->questionIds = [
            'mcq' => (int) $this->makeQuestion($examId, [
                'question' => '<p>MCQ</p>', 'type' => 'mcq',
                'option_a' => '8', 'option_b' => '10', 'option_c' => '12', 'option_d' => '15',
                'correct_ans' => 'b', 'marks' => 5, 'sort_order' => 1,
            ]),
            'true_false' => (int) $this->makeQuestion($examId, [
                'question' => '<p>TF</p>', 'type' => 'true_false',
                'correct_ans' => 'true', 'marks' => 1, 'sort_order' => 2,
            ]),
            'short' => (int) $this->makeQuestion($examId, [
                'question' => '<p>Explain simple and compound interest.</p>',
                'type' => 'short', 'marks' => 10, 'sort_order' => 3,
            ]),
            'essay' => (int) $this->makeQuestion($examId, [
                'question' => '<p>Written answer four.</p>',
                'type' => 'essay', 'marks' => 4, 'sort_order' => 4,
            ]),
        ];
    }

    /** @test */
    public function each_question_gets_EXACTLY_ONE_editor_shell_and_source_field(): void
    {
        $html = $this->renderTakePage();

        $shells = $this->occurrences($html, 'class="piie-editor-shell"');

        // ONLY the Essay mounts the rich-text editor.
        //
        // This was previously "one shell per written question", because Short Answer
        // was rendered through the editor too. It is a plain labelled textarea now —
        // see the note in `student/online_exam/take.blade.php` — and the guarantee this
        // test actually protects is UNCHANGED and still asserted below: exactly one
        // toolbar per question that has one, and never a stray second shell.
        $richText = [$this->questionIds['essay']];

        $this->assertCount(
            count($richText),
            $shells,
            'exactly one editor shell for the essay, and no extra shell that would show a second toolbar'
        );

        // Each shell carries a distinct `data-for`, and it matches its textarea id.
        $fors = $this->capture($html, '/<div class="piie-editor-shell" data-for="([^"]+)"/');
        $this->assertCount(count($richText), $fors);
        $this->assertSame($fors, array_values(array_unique($fors)), 'editor shells must have unique ids');

        foreach ($richText as $qid) {
            $this->assertContains('exam-answer-' . $qid, $fors, "question {$qid} must own its own editor");
        }

        // One editor source textarea per rich-text question.
        $areas = $this->capture($html, '/<textarea\b[^>]*data-piie-editor[^>]*>/s');
        $this->assertCount(count($richText), $areas);

        // And the Short Answer's control is a REAL textarea — not an editor, not a div,
        // not a rendered component. This is the field that had no usable input at all
        // on exam 20.
        $this->assertStringContainsString('data-testid="short-answer-input"', $html);
        $this->assertStringContainsString(
            'id="exam-answer-' . $this->questionIds['short'] . '"',
            $html,
            'the short answer needs a real, addressable input'
        );
        $this->assertStringContainsString(
            '<label class="form-label" for="exam-answer-' . $this->questionIds['short'] . '">',
            $html,
            'the short answer must be labelled, not merely present'
        );

        // BOTH written questions still post under their own field name.
        $names = $this->capture($html, '/<textarea\b[^>]*name="(answers\[[0-9]+\])"/s');
        $this->assertSame(
            ['answers[' . $this->questionIds['short'] . ']', 'answers[' . $this->questionIds['essay'] . ']'],
            $names,
            'each written question must post under its own field name'
        );
    }

    /** @test */
    public function every_written_editor_carries_the_question_id_the_autosave_looks_for(): void
    {
        $html = $this->renderTakePage();

        // The autosave resolves a textarea by
        // `textarea.exam-answer-input[data-question-id="…"]`. A written editor missing
        // either half of that is invisible to the save routine: the student types into
        // it, the page reports nothing, and the answer is simply never sent. That is
        // the "saved but empty" failure in exam 19.
        preg_match_all(
            '/<textarea\b[^>]*class="([^"]*exam-answer-input[^"]*)"[^>]*data-question-id="([0-9]+)"/s',
            $html,
            $pairs,
            PREG_SET_ORDER
        );

        $written = [$this->questionIds['short'], $this->questionIds['essay']];
        $this->assertCount(count($written), $pairs, 'every written question needs an autosave-addressable source field');

        $seen = [];
        foreach ($pairs as $pair) {
            // The contract is the autosave selector, which BOTH controls carry. Only
            // the essay additionally carries `piie-editor-source` — the class that lets
            // the stylesheet hide it once the editor has taken over.
            $this->assertStringContainsString('exam-answer-input', $pair[1]);
            $seen[] = (int) $pair[2];
        }

        // The essay's field is the one the editor owns.
        $this->assertStringContainsString('piie-editor-source', $this->capture(
            $html,
            '/<textarea\b[^>]*id="exam-answer-' . $this->questionIds['essay'] . '"[^>]*>/s'
        )[0]);

        sort($seen);
        $expected = $written;
        sort($expected);

        $this->assertSame($expected, $seen, 'the autosave question ids must match the written questions exactly');
    }

    /** @test */
    public function each_written_editor_is_rendered_INSIDE_its_own_question_block(): void
    {
        $html = $this->renderTakePage();

        /**
         * NESTING IS THE PART THAT MATTERS.
         *
         * `currentValueFor(questionId)` reads the editor by scoping its lookup to
         * `#question-block-{id}`, and then reads the editor by FIELD NAME. If an
         * editor ever renders outside its own block, that scoped lookup cannot see it,
         * the code falls back to the wrong source, and one question's autosave saves
         * another question's editor — which is exactly how a typed answer becomes
         * `<p><br></p>` on the server while the student watches a green "Saved".
         */
        foreach ($this->questionIds as $label => $qid) {
            $this->assertMatchesRegularExpression(
                '/id="question-block-' . $qid . '".*?<\/div>\s*(?=<!--|\s*<\/div>)/s',
                $html,
                "question {$qid} ({$label}) must render a question block"
            );
        }

        // Every editor shell must sit within the block whose id matches its data-for.
        preg_match_all(
            '/id="question-block-([0-9]+)"(.*?)(?=id="question-block-[0-9]+"|<\/body>)/s',
            $html,
            $blocks,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($blocks, 'the page must render per-question blocks');

        $shellsIn = [];
        foreach ($blocks as $block) {
            preg_match_all('/<div class="piie-editor-shell" data-for="exam-answer-([0-9]+)"/', $block[2], $found);
            foreach ($found[1] as $owner) {
                $shellsIn[] = [$block[1], $owner];
            }
        }

        foreach ($shellsIn as [$blockId, $owner]) {
            $this->assertSame(
                $blockId,
                $owner,
                "an editor for question {$owner} was rendered inside question {$blockId}'s block"
            );
        }
    }

    /** @test */
    public function objective_questions_render_real_input_controls_not_editors(): void
    {
        $html = $this->renderTakePage();

        $mcq = $this->questionIds['mcq'];
        $tf = $this->questionIds['true_false'];

        // Radios for both, addressed by the autosave.
        foreach ([$mcq, $tf] as $qid) {
            $this->assertGreaterThan(
                0,
                preg_match_all('/<input\b[^>]*type="radio"[^>]*data-question-id="' . $qid . '"/', $html),
                "question {$qid} must render radio inputs"
            );
        }

        // And they must NOT have been given a rich-text editor.
        foreach ([$mcq, $tf] as $qid) {
            $this->assertSame(
                0,
                preg_match('/data-for="exam-answer-' . $qid . '"/', $html),
                "question {$qid} is objective and must not mount an editor"
            );
        }

        /**
         * The MCQ's options render as KEYS, with the wording in the label.
         *
         * `value="a"` rather than `value="8"`, deliberately: the stored answer must be
         * the option key, so shuffling or re-ordering the options can never change what
         * a correct answer is worth. Asserted explicitly, because an earlier version of
         * this file asserted the option TEXT was the value — which would have "passed"
         * a page that had quietly broken correctness for shuffled papers.
         */
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $this->assertMatchesRegularExpression(
                '/name="answers\[' . $mcq . '\]"\s+value="' . $key . '"/',
                $html,
                "MCQ option key {$key} must render as the submitted value"
            );
        }

        foreach (['8', '10', '12', '15'] as $wording) {
            $this->assertStringContainsString(
                $wording . '</label>',
                $html,
                "MCQ option wording {$wording} must be visible to the student"
            );
        }
    }

    /** @test */
    public function the_page_carries_the_restricted_interaction_mode_and_focus_monitoring(): void
    {
        $html = $this->renderTakePage();

        // Scoped to the exam page only — never a global clipboard block.
        $this->assertStringContainsString('piie-exam-restricted', $html);
        $this->assertStringContainsString('exam-restricted-mode.js', $html);

        /**
         * The reporting endpoint is a NAMED route, resolved by the view, never typed in.
         *
         * Compared against `json_encode()` of that URL because the view emits it through
         * `@json`, which escapes forward slashes as `\/`. Asserting the bare URL would
         * fail against a perfectly correct page.
         */
        $this->assertStringContainsString(
            json_encode(route('student.online_exam.incident', $this->submissionId())),
            $html,
            'the page must post incidents to the named incident route for THIS attempt'
        );

        // And it must never claim to be able to stop an OS-level action.
        $this->assertStringContainsString(
            'cannot prevent',
            $html,
            'the page must state plainly what ordinary page JavaScript cannot prevent'
        );
        $this->assertStringContainsString(
            'does not end your examination',
            $html,
            'the page must not imply leaving it has an automatic consequence'
        );

        /**
         * THE INCIDENT LIST LIVES IN THE SCRIPT, NOT THE PAGE.
         *
         * Asserting the event names against the page HTML would fail for a page that is
         * perfectly correct, because the behaviour is in the module. So the module is
         * read directly — which also pins the events the server will accept.
         */
        $script = public_path('js/exam-restricted-mode.js');

        $this->assertFileExists($script);

        $source = file_get_contents($script);

        foreach ([
            'focus_lost', 'focus_returned', 'tab_hidden', 'connection_lost', 'connection_restored',
        ] as $event) {
            $this->assertStringContainsString(
                $event,
                $source,
                "focus monitoring must be able to report {$event}"
            );
        }

        // One incident, not two, for a single Alt+Tab.
        $this->assertStringContainsString(
            'INCIDENT_GRACE_MS',
            $source,
            'focus monitoring must absorb the blur/visibilitychange pair of one interruption'
        );

        /**
         * NORMAL TYPING MUST SURVIVE.
         *
         * The keydown handler does block — but only for clipboard shortcuts, which is
         * the requirement. So the invariant is that blocking is GATED by
         * `isClipboardShortcut`, not that no keydown handler exists. An earlier version
         * of this assertion looked for the absence of a block call and would have
         * rejected the very thing it was meant to permit.
         */
        $this->assertStringContainsString(
            'if (isClipboardShortcut(event)) {',
            $source,
            'the keydown handler must gate blocking on the clipboard-shortcut test'
        );

        // And no handler may block ordinary editing keys outright.
        foreach (['Enter', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab', 'Escape'] as $key) {
            $this->assertStringNotContainsString(
                "'" . $key . "'",
                $source,
                "the restricted mode must not special-case block the {$key} key"
            );
        }

        // And the mode must not be installed anywhere else.
        $this->assertSame(
            1,
            substr_count($source, 'document.documentElement.classList.contains(MODE_CLASS)'),
            'the module must gate on the page opt-in rather than always installing'
        );
    }

    private function submissionId(): int
    {
        return (int) DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->exam->id)
            ->where('student_id', $this->student->id)
            ->value('id');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function renderTakePage(): string
    {
        $submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'school_id' => 1,
            'total_marks_snapshot' => 20,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(45),
            'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $submission = OnlineExamSubmission::query()->findOrFail($submissionId);

        $html = $this->actingAs($this->student)
            ->get(route('student.online_exam.take', $this->exam->id))
            ->assertOk()
            ->getContent();

        fwrite(STDERR, sprintf(
            "\n[dbg] len=%d shells=%d editors=%d radios=%d qids=%s\n",
            strlen($html),
            substr_count($html, 'piie-editor-shell'),
            substr_count($html, 'data-piie-editor'),
            substr_count($html, 'type="radio"'),
            json_encode($this->questionIds)
        ));

        return $html;
    }

    /**
     * Counts non-overlapping occurrences of a LITERAL string.
     *
     * Reads `$m[0]`, not `$m[1]`. `capture()` returns the first capturing group, and a
     * pattern built from a plain literal has none — so delegating to it yields an
     * empty list, and "one shell per question" then passes a page containing no shells
     * at all. That is a false green, which is why this does not reuse `capture()`.
     */
private function occurrences(string $html, string $needle): array
{
    preg_match_all('/' . preg_quote($needle, '/') . '/', $html, $m);

    return $m[0] ?? [];
}

    /**
     * A pattern's first capturing group, or its whole match when it has no group.
     *
     * The fallback matters: `$m[1] ?? []` reports "nothing found" for a pattern with
     * no group, so every count assertion built on it reports zero matches for a page
     * that is full of them. That is a false green, and it is how a broken page passes.
     */
private function capture(string $html, string $pattern): array
{
    preg_match_all($pattern, $html, $m);

    if (array_key_exists(1, $m)) {
        return $m[1];
    }

    return $m[0] ?? [];
}
}