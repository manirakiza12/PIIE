<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Question-based Course Offering Assignments.
 *
 * ADDITIVE ONLY. Nothing is dropped, retyped or re-interpreted. Every existing
 * assignment, submission and evidence row keeps its exact meaning after this runs,
 * which is what lets Assignment #3 stay published, required and question-free
 * until a lecturer deliberately gives it questions.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY AN ASSIGNMENT IS QUESTION-BASED IS DERIVED, NOT A NEW COLUMN
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * There is no `assignments.is_question_based` flag, and the reason is worth
 * stating because a flag would have been the obvious choice.
 *
 * A flag has to be kept true, and every path that creates questions, deletes
 * them, or edits an assignment has to remember to maintain it. Get it wrong in
 * either direction and students see the wrong form: a generic form for a
 * question-based assignment, or a question list for a legacy one. A derived rule
 * cannot drift, because there is nothing to keep in step.
 *
 * So:
 *
 *   assignment has ZERO questions  ->  a GENERIC assignment. The existing
 *                                        evidence form, the existing
 *                                        `submission_kinds` allowlist, the
 *                                        existing rules. Byte-for-byte the
 *                                        behaviour it has today.
 *   assignment has ONE OR MORE       ->  a QUESTION-BASED assignment. The
 *                                        ordered question experience, per-question
 *                                        evidence, per-question marking, and the
 *                                        mark-integrity gate.
 *
 * Adding the first question is therefore the moment an assignment becomes
 * question-based, and that is a governed act: it is refused once any student has
 * submitted, and it subjects the assignment to the "questions must total the
 * assignment maximum" rule at publication.
 *
 * A new assignment can be drafted with no questions at all, which is a perfectly
 * good draft - and the lecturer screen says plainly that adding a question is what
 * switches the model over.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. `assignment_questions` - the questions themselves
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Belongs to the EXISTING `assignments` row. No new Assignment entity, no join
 * table, no second product. A question IS a row on an assignment, and the
 * assignment keeps owning it.
 *
 * `school_id` and `course_offering_id` are denormalised exactly as
 * `assignment_submission_items` already does, so a tenant-scoped read of a
 * question is one indexed predicate rather than a join. Neither is ever trusted
 * for AUTHORITY: every read path re-resolves the parent assignment through
 * `AssignmentAccess`, which proves tenant and Offering from `assignments` itself.
 * They are indexes, not permissions.
 *
 * `response_kinds` is a comma-separated SET, for the same reason
 * `assignments.submission_kinds` is: "record audio OR upload audio" cannot be
 * expressed by a single value, and a question may permit a deliberate
 * combination. NULL means "not configured", which is refused at publication
 * rather than silently treated as "accepts anything" - a question that accepts
 * anything is not a question.
 *
 * `require_all` is the deliberate-combination control, and it is explicit because
 * the two readings are genuinely different promises to a student:
 *
 *   false (default)  ANY ONE accepted kind satisfies this question
 *                    -> "Record audio OR upload audio" / "Record video, upload
 *                        video, or a web link". What a lecturer writing an oral
 *                        question actually means.
 *   true             EVERY listed kind must be present
 *                    -> rare, and a deliberate choice, so it is opt-in.
 *
 * No `is_active`. Retiring a question is a DELETE, and that is permitted only
 * while no student has submitted - which is the same rule as editing one. An
 * "inactive" question in a published assignment would be a question some students
 * were shown and others were not, and PIIE has no way to decide which is correct.
 * The freeze rule below is the honest version of that idea.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 2. `assignment_question_responses` - one row per (attempt, question)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * THE ROW IS CREATED FOR EVERY QUESTION, NOT ONLY ANSWERED ONES
 *
 * A marker looking at Question 3 needs to see "not answered" as a fact. If a
 * response row only existed once the student typed something, the absence of a
 * row would have to be interpreted - and interpreted differently by the marking
 * screen, the gradebook and the validation path, which is how a missed question
 * becomes a silent zero in one place and a blank in another.
 *
 * So the row's EXISTENCE carries no meaning; its columns do. `text_response` null
 * means not answered, and reads as not answered everywhere.
 *
 * One row per (submission, question), UNIQUE. A draft saved five times is still
 * one row, which is what makes "resume a draft" idempotent rather than
 * accumulating a copy per save.
 *
 * Per-question MARKS live here, and the assignment total is DERIVED from them.
 * `assignment_submissions.marks_awarded` remains the authoritative total for the
 * Gradebook, the release gate and the `released_mark` completion rule - it is
 * written from the sum of these marks, never typed independently by a lecturer.
 * That is how the brief's "do not require the lecturer to independently type
 * another contradictory total" is enforced structurally rather than by asking.
 *
 * `assignment_submission_id` CASCADE: an answer to a question on an attempt that
 * no longer exists is not an answer.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 3. Two nullable columns on `assignment_submission_items`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * `assignment_question_id` - which question this piece of evidence answers.
 * NULL keeps every existing row, and every K12 row, exactly as it is: evidence at
 * assignment level, which is what a generic assignment means. A question-based
 * assignment's items always carry it.
 *
 * `capture_method` - 'upload' or 'browser_recording'. The brief requires that
 * nothing pretend a recording succeeded. Recording WHERE THE BYTES CAME FROM is a
 * factual provenance record, distinct from whether they arrived: the server still
 * validates the file it actually received against the question's own kind,
 * extension allowlist and size limit, and creates the item only from that file. A
 * client that claims 'browser_recording' while sending nothing produces no item
 * and no recording claim on the marking screen - the marker sees a question with
 * no answer, which is the truth.
 *
 * Both are nullable and additive, so the K12 partition and every pre-existing row
 * are untouched.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY NOT HERE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * No new User, Student, Course Offering, Module, Assignment or Submission entity.
 * No duplicate attempt numbering - `assignment_submissions.attempt_no` still
 * decides it, and a draft still consumes no attempt. No stored question total:
 * the sum is computed from the rows, so it cannot be stale. No per-kind
 * extension table: the allowlists already live in
 * `AssignmentSubmissionItem::KIND_EXTENSIONS`, and a question reuses them
 * unchanged rather than duplicating a list that could drift from the validator.
 */
class AddCourseOfferingAssignmentQuestions extends Migration
{
    public function up(): void
    {
        $this->createQuestions();
        $this->createResponses();
        $this->scopeEvidenceToAQuestion();
    }

    /**
     * The questions belonging to an existing Course Offering assignment.
     */
    private function createQuestions(): void
    {
        if (Schema::hasTable('assignment_questions')) {
            return;
        }

        Schema::create('assignment_questions', function (Blueprint $table): void {
            $table->id();

            // Index, not authority. See the class docblock: every read re-resolves
            // the parent assignment through AssignmentAccess.
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('course_offering_id')->nullable()->index();

            // The EXISTING assignment. A question is a row on an assignment; there
            // is no second assignment product and no join table.
            $table->unsignedBigInteger('assignment_id')->index('asgq_assignment_idx');

            // The question itself, through the SHARED HtmlSanitizer - the same
            // filter lesson bodies, assignment instructions and written responses
            // pass through. One rich-text store in PIIE with one allowlist, because
            // two stores with two filters is how one of them gets an XSS hole.
            $table->longText('prompt');

            // A short heading is rendered above the prompt, so a long question can
            // be found in the marking screen without reading all of it. Optional,
            // and never a substitute for the prompt.
            $table->string('heading', 191)->nullable();

            // Reading order. Not the id: questions get reordered by a lecturer, and
            // an id-ordered list would silently ignore that.
            $table->unsignedInteger('sequence')->default(1);

            // The question's OWN maximum. Decimal to match marks_awarded, so a
            // half mark is representable on both sides of the comparison.
            $table->decimal('marks', 6, 2)->default(0);

            // Required within the assignment. Distinct from `requirement_role`,
            // which is about MODULE completion: this one is about whether the
            // student may hand in an answer that leaves this question blank.
            //
            // DEFAULT TRUE, because a question a lecturer bothered to write is
            // presumed to be one they want answered. Unlike a schema default that
            // makes things block, this one only ever blocks an incomplete answer
            // to a question the student can SEE, and the question is editable
            // until somebody submits.
            $table->boolean('is_required')->default(true);

            // The evidence this question accepts: a comma-separated SET of
            // text,document,image,audio,video,link. NULL means "not configured",
            // which is REFUSED at publication rather than being read as "anything".
            $table->string('response_kinds', 120)->nullable();

            // false = ANY ONE of the listed kinds satisfies this question.
            // true  = EVERY listed kind is required.
            //
            // The default is "any one" because that is what an oral or a
            // demonstration question means: record it, or upload it, or link to
            // it. "All of them" is a legitimate but unusual promise, so a lecturer
            // has to choose it.
            $table->boolean('require_all')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('assignment_questions', function (Blueprint $table): void {
            // One question list per assignment, in reading order. The unique
            // sequence is NOT enforced: a lecturer may legitimately have two
            // questions at the same position while reordering, and the service
            // renumbers the whole set inside its transaction instead. A unique
            // index here would make an ordinary reorder a constraint violation.
            $table->index(['assignment_id', 'sequence'], 'asgq_assignment_seq_idx');
        });
    }

    /**
     * One row per (attempt, question): the written answer, its mark, its feedback.
     */
    private function createResponses(): void
    {
        if (Schema::hasTable('assignment_question_responses')) {
            return;
        }

        Schema::create('assignment_question_responses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();

            $table->unsignedBigInteger('assignment_question_id')->index('asgres_question_idx');
            $table->unsignedBigInteger('assignment_id')->index();

            // CASCADE: an answer to a question on an attempt that no longer exists
            // is not an answer. Deleting an attempt must not leave orphaned
            // responses that a later audit would read as a submission.
            $table->unsignedBigInteger('assignment_submission_id')->index('asgres_submission_idx');
            $table->unsignedBigInteger('course_offering_id')->nullable()->index();

            // The written answer to THIS question. LONGTEXT, and through the same
            // sanitizer as everything else, because it is rich text from an editor.
            //
            // NULL means NOT ANSWERED, and that is the only way it is read. The row
            // itself carries no meaning - it exists for every question so that "not
            // answered" is a stored fact rather than an inference from a missing
            // row, which three different screens would otherwise have to make
            // identically.
            $table->longText('text_response')->nullable();

            // Marks for THIS question, and feedback for THIS question.
            //
            // The assignment TOTAL is the sum of these, written to
            // `assignment_submissions.marks_awarded`. Nothing asks a lecturer to
            // type a second, independent total that could contradict them.
            $table->decimal('marks_awarded', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();

            $table->timestamps();
        });

        Schema::table('assignment_question_responses', function (Blueprint $table): void {
            // THE idempotency guarantee for draft saving. A draft saved five times
            // is still one row per question, so "resume a draft" cannot accumulate
            // a copy per save. The database decides, not a read-then-write check.
            $table->unique(
                ['assignment_submission_id', 'assignment_question_id'],
                'asgres_submission_question_unq'
            );
        });
    }

    /**
     * Scope one piece of evidence to a question, and record where the bytes came from.
     */
    private function scopeEvidenceToAQuestion(): void
    {
        if (! Schema::hasTable('assignment_submission_items')) {
            return;
        }

        Schema::table('assignment_submission_items', function (Blueprint $table): void {
            // NULL for every existing row and every K12 row: evidence at assignment
            // level, which is exactly what a generic assignment means. A
            // question-based assignment's items always carry this.
            $table->unsignedBigInteger('assignment_question_id')
                ->nullable()
                ->index('asubitem_question_idx');

            // 'upload' | 'browser_recording' | NULL (legacy, or not stated).
            //
            // Provenance, not proof. The server still validates the file it
            // actually received against the question's own kind, extension
            // allowlist and size limit, and creates the item only from that file -
            // so a client claiming a recording while sending nothing yields no
            // item and no recording claim on the marking screen.
            $table->string('capture_method', 20)->nullable();
        });

        Schema::table('assignment_submission_items', function (Blueprint $table): void {
            $table->index(
                ['assignment_submission_id', 'assignment_question_id'],
                'asubitem_submission_question_idx'
            );
        });
    }

    public function down(): void
    {
        // Only ever run on a scratch database. piie_main is never rolled back.
        if (Schema::hasTable('assignment_submission_items')) {
            Schema::table('assignment_submission_items', function (Blueprint $table): void {
                $table->dropIndex('asubitem_submission_question_idx');
                $table->dropIndex('asubitem_question_idx');
                $table->dropColumn('assignment_question_id');
                $table->dropColumn('capture_method');
            });
        }

        Schema::dropIfExists('assignment_question_responses');
        Schema::dropIfExists('assignment_questions');
    }
}
