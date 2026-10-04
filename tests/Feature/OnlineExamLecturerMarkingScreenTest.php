<?php

namespace Tests\Feature;

use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\QuestionPrompt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE LECTURER MARKING SCREEN — LAYOUT AND MARKING.
 *
 * ── WHAT WAS REPORTED ──────────────────────────────────────────────────────
 *
 * Exam 21, submission 15. The lecturer's results page had a broken horizontal
 * layout: student information and submitted answers cut off, the table
 * overflowing, and the marking inputs squeezed into the far-right Actions column.
 *
 * The cause was structural. ONE table carried both jobs — the per-student summary
 * and the student's written answers — behind a hard `min-width: 1250px` with
 * `white-space: nowrap` on every cell. A written answer cannot live inside that,
 * so it was pushed sideways and truncated, and the mark input was in the one
 * column narrow enough to break.
 *
 * These tests hold the two fixes in place:
 *
 *   1. THE LAYOUT. The summary carries exactly the nine agreed columns and no
 *      fixed width; the marking work lives full width BELOW it, one card per
 *      question on the paper. Nothing may be concealed with `overflow: hidden`,
 *      because a hidden answer is indistinguishable from an empty one.
 *
 *   2. THE MARKING. Each card shows the question, its type, its maximum, the
 *      student's EXACT saved answer, an award bounded by that maximum, optional
 *      feedback, and an unambiguous Saved/Unmarked state.
 */
class OnlineExamLecturerMarkingScreenTest extends TestCase
{
    use OnlineExamTestHelper;

    private int $lecturerId;
    private int $classId;
    private int $examId;
    private int $submissionId;
    private array $questionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->classId = $this->makeClass(1);
        $this->lecturerId = (int) $this->makeUser(3, 1, 'active', 'Daniel Okello')->id;

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->classId,
            'section_id' => 1,
            'school_id' => 1,
            'teacher_id' => $this->lecturerId,
            'marks' => 1,
            'attendance' => 1,
            'updated_at' => now(),
        ]);

        $this->examId = $this->makeExam([
            'title' => 'final test',
            'class_id' => $this->classId,
            'total_marks' => 10,
            'pass_mark' => 5,
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);

        // Two questions of the two shapes that were reported as unreadable: a written
        // essay and a short answer, both carrying real stored text.
        $this->questionIds['essay'] = $this->makeQuestion($this->examId, [
            'question' => '<p>why is apple blue</p>',
            'type' => 'essay', 'marks' => 5, 'sort_order' => 1,
        ]);
        $this->questionIds['short'] = $this->makeQuestion($this->examId, [
            'question' => '<p>out line the map leading to your home</p>',
            'type' => 'short', 'marks' => 5, 'sort_order'  => 2,
        ]);

        $this->submissionId = $this->makeSubmission([
            'online_exam_id' => $this->examId,
            'student_id' => $this->makeUser(7, 1, 'active', 'Kyeyune Amos')->id,
            'status' => OnlineExamSubmission::STATUS_PENDING_MANUAL,
            'submitted_at' => now(),
        ]);

        $this->storeAnswer($this->questionIds['essay'], '<p>this is how we do it</p>');
        $this->storeAnswer($this->questionIds['short'], 'mnap is what you see by your eyes');
    }

    private function storeAnswer(int $questionId, string $text): void
    {
        DB::table('online_exam_answers')->insert([
            'submission_id' => $this->submissionId,
            'question_id' => $questionId,
            'answer_text' => $text,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function asLecturer(): self
    {
        $this->actingAs(\App\Models\User::find($this->lecturerId));

        return $this;
    }

    private function resultsUrl(): string
    {
        return route('teacher.online_exams.results', [
            'exam' => $this->examId,
            'submission' => $this->submissionId,
        ]);
    }

    private function page(): string
    {
        return $this->asLecturer()->get($this->resultsUrl())->assertOk()->getContent();
    }

    /** The marking section only, so a layout assertion cannot pass on the summary. */
    private function markingSection(string $html): string
    {
        $start = strpos($html, 'id="piie-marking"');

        $this->assertNotFalse($start, 'The marking section must exist on the results page.');

        return substr($html, $start);
    }

    // ═══ 1. THE SUMMARY IS COMPACT AND DOES NOT FORCE A WIDTH ═══════════════════

    public function test_the_summary_carries_exactly_the_agreed_columns(): void
    {
        $html = $this->page();

        // Cut the summary table out so this is a claim about the summary and not
        // about a heading elsewhere on the page.
        $start = strpos($html, '<table class="table eTable piie-results-summary">');
        $this->assertNotFalse($start, 'The summary table must be present.');

        $end = strpos($html, '</table>', $start);
        $table = substr($html, $start, $end - $start);

        foreach ([
            'Student', 'Submitted', 'Automatic', 'Manual', 'Total',
            'Maximum', 'Pending Decisions', 'Status', 'Action',
        ] as $column) {
            $this->assertStringContainsString(
                '>'.$column.'<',
                $table,
                "The summary must carry a \"{$column}\" column."
            );
        }

        // The three that were cut when the job moved: the row number, and the two
        // that duplicated what the Status column now says in one place.
        foreach (['Awaiting Decision', 'Finalization', 'Release'] as $removed) {
            $this->assertStringNotContainsString(
                '>'.$removed.'<',
                $table,
                "\"{$removed}\" was folded into the summary or moved to the marking section."
            );
        }
    }

    public function test_the_summary_does_not_force_a_horizontal_scroll(): void
    {
        $html = $this->page();

        // The reported fault: a hard floor wider than the viewport, plus nowrap on
        // every cell, so the table could not fit and pushed the page sideways.
        $this->assertStringNotContainsString(
            'min-width: 1250px',
            $html,
            'The 1250px floor is what made the page overflow at every width below it.'
        );

        // No rule may conceal content, at any specificity.
        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-[a-z-]+\s*\{[^}]*overflow\s*:\s*hidden/i',
            $html,
            'overflow:hidden hides an answer rather than fitting it, and a hidden answer '
            .'is indistinguishable from an empty one.'
        );

        // The rule that was applied instead: cells may wrap, and may shrink.
        $this->assertStringContainsString(
            'white-space: normal',
            $html,
            'Summary cells must be allowed to wrap rather than being forced onto one line.'
        );
        $this->assertStringContainsString(
            'width: 100%',
            $html,
            'The table takes the width available to it, not a width of its own.'
        );

        // ...and the mark input must NOT be back inside the summary.
        $start = strpos($html, '<table class="table eTable piie-results-summary">');
        $end = strpos($html, '</table>', $start);
        $this->assertStringNotContainsString(
            'name="awarded_marks"',
            substr($html, $start, $end - $start),
            'A number input in a summary column is what made the table overflow.'
        );
    }

    public function test_the_marking_controls_are_outside_the_summary_table(): void
    {
        $html = $this->page();

        $markingAt = strpos($html, 'id="piie-marking"');
        $this->assertNotFalse($markingAt, 'The marking section must exist.');

        $start = strpos($html, '<table class="table eTable piie-results-summary">');
        $end = strpos($html, '</table>', $start);

        // The summary ENDS before the marking section begins. If they interleaved,
        // the answer block would be inside the table again and the overflow returns.
        $this->assertLessThan(
            $markingAt,
            $end,
            'The marking section must come after the summary table closes, not inside it.'
        );
    }

    // ═══ 2. ONE CARD PER QUESTION, WITH EVERYTHING A MARKER NEEDS ══════════════

    public function test_every_question_on_the_paper_gets_a_card(): void
    {
        $html = $this->page();
        $section = $this->markingSection($html);

        $this->assertSame(
            2,
            substr_count($section, 'data-testid="marking-card"'),
            'One card per question on the paper — including one the student never answered, '
            .'because that is exactly the question a marker still owes a decision on.'
        );
    }

    public function test_each_card_shows_the_number_type_maximum_and_full_question_text(): void
    {
        $section = $this->markingSection($this->page());

        // Numbering, so a marker can say "question 2" and mean the same thing the
        // student saw.
        $this->assertStringContainsString('Question 1', $section);
        $this->assertStringContainsString('Question 2', $section);

        // Type and maximum, both as their own labelled facts.
        $this->assertStringContainsString('data-testid="marking-card-type"', $section);
        $this->assertStringContainsString('ESSAY', $section);
        $this->assertStringContainsString('SHORT', $section);
        $this->assertStringContainsString('data-testid="marking-card-max"', $section);

        // The FULL statement, not a truncated label. The truncated label rendered as
        // an empty string for a question stored as `<p><br></p>`, which is how a
        // marker could not tell which question they were deciding.
        $this->assertStringContainsString('why is apple blue', $section);
        $this->assertStringContainsString('out line the map leading to your home', $section);
    }

    public function test_the_students_exact_saved_answers_are_visible(): void
    {
        $section = $this->markingSection($this->page());

        // These are the bytes stored for submission 15, verified against the database
        // rather than assumed. The essay is rich text; the short answer is plain, and
        // its typo is part of the record and must be shown as written.
        $this->assertStringContainsString('this is how we do it', $section);
        $this->assertStringContainsString('mnap is what you see by your eyes', $section);

        // Stored, not reformatted into something that reads as blank.
        $stored = DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->pluck('answer_text')
            ->all();

        $this->assertCount(2, $stored);
    }

    public function test_rich_text_answers_are_rendered_as_live_prose_not_escaped_markup(): void
    {
        $section = $this->markingSection($this->page());

        // The essay was stored as `<p>this is how we do it</p>`, so a marker must see a
        // PARAGRAPH, not the characters of a tag. Escaping is the failure here: it
        // makes a real answer look like source code.
        $this->assertStringContainsString('<p>this is how we do it</p>', $section);
        $this->assertStringNotContainsString('&lt;p&gt;', $section);

        // It is prose, so it carries PIIE's prose treatment. Checked on the WHOLE
        // page, because the style block that does the wrapping is emitted before the
        // marking section and is therefore not inside `$section`.
        $this->assertStringContainsString('piie-prose', $section);
        $this->assertStringContainsString('overflow-wrap: anywhere', $this->page());

        // A short answer stored as plain text has no markup and must not acquire any.
        $this->assertStringNotContainsString('<p>mnap is what you see by your eyes</p>', $section);
    }

    public function test_a_script_in_a_stored_answer_is_not_rendered_to_the_marker(): void
    {
        // The marker views another person's submitted text. It must be sanitised, so
        // this asserts the sanitiser rather than trusting it.
        DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])
            ->update(['answer_text' => '<p>hello</p><script>window.stolen = 1;</script>']);

        $section = $this->markingSection($this->page());

        // Scoped to ONE card's answer block. The layout's own `<script>` tags sit
        // outside it, so asserting on the whole section would prove nothing about
        // the sanitiser.
        $start = strpos($section, 'data-testid="marking-card-answer"');
        $this->assertNotFalse($start, 'The answer block must exist.');
        $answerBlock = substr($section, $start, strpos($section, '</div>', $start) - $start);

        $this->assertStringNotContainsString('<script', $answerBlock);
        $this->assertStringNotContainsString('window.stolen', $answerBlock);
        $this->assertStringContainsString('hello', $answerBlock);
    }

    // ═══ 3. THE MARKING CONTROL ITSELF ═══════════════════════════════════════

    public function test_an_award_input_is_bounded_by_the_question_maximum(): void
    {
        $section = $this->markingSection($this->page());

        $this->assertSame(
            2,
            substr_count($section, 'name="awarded_marks"'),
            'Each answered manual question needs its own award field.'
        );

        // Both questions are worth 5, so both inputs are capped at 5 and floored at 0.
        $this->assertSame(2, substr_count($section, 'max="5"'));
        $this->assertSame(2, substr_count($section, 'min="0"'));

        // The bound is stated to the person as well as enforced on the request.
        $this->assertStringContainsString('0–5', $section);
    }

    public function test_each_card_offers_feedback_and_a_save_mark_button(): void
    {
        $section = $this->markingSection($this->page());

        $this->assertSame(2, substr_count($section, 'name="teacher_comment"'));
        $this->assertSame(2, substr_count($section, 'data-testid="save-mark"'));
        $this->assertStringContainsString('Save Mark', $section);
    }

    public function test_the_saved_state_is_stated_and_starts_unmarked(): void
    {
        $section = $this->markingSection($this->page());

        $this->assertSame(
            2,
            substr_count($section, 'data-testid="mark-state"'),
            'Every card states its marking state, so a marker can tell what is done.'
        );

        $this->assertStringContainsString('Unmarked', $section);
        $this->assertStringNotContainsString('Saved', $section,
            'Nothing has been marked yet, so no card may claim to be saved.');
    }

    public function test_a_marked_question_reports_saved_with_its_mark(): void
    {
        DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])
            ->update([
                'awarded_marks' => 4,
                'marked_by' => $this->lecturerId,
                'marked_at' => now(),
            ]);

        $section = $this->markingSection($this->page());

        $this->assertStringContainsString('Saved', $section);
        $this->assertStringContainsString('4.00', $section);

        // The unmarked one is still unmarked, and still shows its control.
        $this->assertStringContainsString('Unmarked', $section);
    }

    // ═══ 4. THE QUESTION THE STUDENT NEVER ANSWERED ══════════════════════════

    public function test_a_blank_question_is_shown_and_can_only_be_decided_as_zero(): void
    {
        DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['short'])
            ->delete();

        $section = $this->markingSection($this->page());

        $this->assertStringContainsString('No answer was submitted', $section);

        // The only honest mark for a blank is zero, so that is what the control
        // offers — and it offers nothing that could invent a score.
        $this->assertStringContainsString('data-testid="record-zero"', $section);
        $this->assertStringContainsString('name="awarded_marks" value="0"', $section);

        // No free-text award on a blank: a number box here would invite inventing one.
        $this->assertSame(
            1,
            substr_count($section, 'type="number"'),
            'Only the answered question may offer a free award input.'
        );
    }

    public function test_a_question_with_no_stored_text_is_labelled_not_blank(): void
    {
        DB::table('online_exam_questions')->where('id', $this->questionIds['essay'])
            ->update(['question' => '<p><br></p>']);

        $section = $this->markingSection($this->page());

        // A card must never be anonymous, or a marker cannot tell what they are
        // deciding.
        $this->assertStringContainsString(QuestionPrompt::EMPTY_LABEL, $section);

        // ...and the other question is untouched, so the label identifies a fault
        // rather than blanking the paper.
        $this->assertStringContainsString('out line the map leading to your home', $section);
    }

    // ═══ 5. HANDOVER, AND THE LIMIT ON WHAT A LECTURER MAY DO ════════════════

    public function test_handover_is_offered_only_once_every_decision_is_made(): void
    {
        $html = $this->page();

        // Two questions, neither marked: the handover must be shut, or it can only be
        // pressed into a refusal.
        $this->assertStringContainsString('outstanding-explanation', $html);
        $this->assertStringNotContainsString('marking-submit-for-review', $html);

        DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->update([
                'awarded_marks' => 5,
                'marked_by' => $this->lecturerId,
                'marked_at' => now(),
            ]);

        $after = $this->page();

        $this->assertStringNotContainsString('outstanding-explanation', $after);
        $this->assertStringContainsString('marking-submit-for-review', $after);
        $this->assertStringContainsString('Submit Marks for Admin Review', $after);
    }

    public function test_a_lecturer_is_never_offered_publication(): void
    {
        $html = $this->page();

        // Only an administrator publishes. The lecturer's screen may hand over and
        // must contain no control that releases a result.
        $this->assertStringNotContainsString('Publish result', $html);
        $this->assertStringNotContainsString('Publish to Student', $html);
        $this->assertStringNotContainsString('publish_result', $html);
    }

    public function test_a_published_submission_shows_its_answers_with_marking_closed(): void
    {
        // Submission 15's real state: published, so nothing may be editable — but the
        // student's words must still be readable. Publication is
        // `result_published` + a `published` review state; setting only a status
        // would not reproduce it.
        DB::table('online_exam_submissions')->where('id', $this->submissionId)->update([
            'status' => OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            'result_review_state' => 'published',
            'published_at' => now(),
            'published_by' => 2,
        ]);

        $html = $this->page();
        $section = $this->markingSection($html);

        $this->assertStringContainsString('marking-closed-notice', $section);
        $this->assertStringNotContainsString('name="awarded_marks"', $section);
        $this->assertStringNotContainsString('marking-submit-for-review', $section);

        // The answers are still there. Closing the controls must not close the record.
        $this->assertStringContainsString('this is how we do it', $section);
        $this->assertStringContainsString('mnap is what you see by your eyes', $section);
    }

    public function test_marking_a_question_through_the_card_records_it(): void
    {
        $question = OnlineExamQuestion::findOrFail($this->questionIds['essay']);

        $this->asLecturer()->post(route('teacher.online_exams.submissions.record_decision', [
            'submission' => $this->submissionId,
            'question' => $question->id,
        ]), [
            'awarded_marks' => 4.5,
            'teacher_comment' => 'Clear, but say which law applies.',
        ])->assertSessionHasNoErrors();

        $answer = DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $question->id)
            ->first();

        $this->assertSame(4.5, (float) $answer->awarded_marks);
        $this->assertSame('Clear, but say which law applies.', $answer->teacher_comment);
        $this->assertSame($this->lecturerId, (int) $answer->marked_by);
        $this->assertNotNull($answer->marked_at);

        // The answer text is untouched by marking. A marker who saves a mark must not
        // be able to alter what the student wrote.
        $this->assertSame('<p>this is how we do it</p>', $answer->answer_text);
    }

    public function test_a_submission_from_another_exam_cannot_be_selected(): void
    {
        $other = $this->makeExam([
            'title' => 'Another paper',
            'class_id' => $this->classId,
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);

        $theirs = $this->makeSubmission([
            'online_exam_id' => $other,
            'student_id' => $this->makeUser(9, 1, 'active', 'Someone Else')->id,
            'status' => OnlineExamSubmission::STATUS_PENDING_MANUAL,
            'submitted_at' => now(),
        ]);

        // Give the OTHER paper a distinctive answer, so this can assert on its absence
        // rather than on the absence of text that legitimately appears here.
        $otherQuestion = $this->makeQuestion($other, [
            'question' => '<p>a question that belongs to another paper</p>',
            'type' => 'essay', 'marks' => 5, 'sort_order' => 1,
        ]);

        DB::table('online_exam_answers')->insert([
            'submission_id' => $theirs,
            'question_id' => $otherQuestion,
            'answer_text' => '<p>an answer belonging to another paper</p>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A crafted id from another paper must not put that paper's answers on this
        // screen. It resolves to nothing, and this paper is shown instead.
        $html = $this->asLecturer()->get(route('teacher.online_exams.results', [
            'exam' => $this->examId,
            'submission' => $theirs,
        ]))->assertOk()->getContent();

        $section = $this->markingSection($html);

        $this->assertStringNotContainsString('an answer belonging to another paper', $section);
        $this->assertStringNotContainsString('a question that belongs to another paper', $section);

        // ...and the paper actually asked for is shown.
        $this->assertStringContainsString('this is how we do it', $section);
        $this->assertStringContainsString('mnap is what you see by your eyes', $section);
    }
}