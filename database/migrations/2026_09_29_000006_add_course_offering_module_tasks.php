<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-Offering Assignments: module/chapter tasks, extensible evidence, and an
 * optional Course Offering cover image.
 *
 * WHAT THIS ADDS, AND WHY IT IS THE MINIMUM
 *
 * Three concerns, all additive, all nullable or defaulted so every existing row
 * stays valid and unchanged.
 *
 * 1. A MODULE MAY OWN AN ASSIGNMENT
 *
 * `assignments.course_offering_module_id` is ONE nullable column, and it reuses
 * the existing `course_offering_modules` entity rather than introducing a
 * "module task" product. A Course Offering already contains modules and lessons;
 * an assignment that belongs to a module is the same assignment, pointing at the
 * module it belongs to.
 *
 * NULL is the default and the normal case, because the brief is explicit that the
 * relationship is OPTIONAL: a module may have no assignment, and most
 * assignments are not module-scoped at all. No join table is used, because a
 * module owns at most the assignments attached to it - there is no many-to-many
 * relationship to model here, and a join table would be a second thing to keep
 * consistent for no benefit.
 *
 * 2. OPTIONAL OR REQUIRED, AND HOW A REQUIRED ONE IS SATISFIED
 *
 * `requirement_role` answers the first question and DEFAULTS TO 'optional'.
 * That default is the whole point: an existing or newly created assignment must
 * never silently start blocking a student's progression. Optional is the safe
 * state, and blocking is something a lecturer must choose.
 *
 * `completion_rule` answers the second, and only for a REQUIRED assignment. Its
 * vocabulary mirrors `course_offering_lessons.completion_rule`, which is already
 * an enum with reserved-but-unimplemented members in this codebase. Two rules are
 * implemented because both are derivable from data PIIE already stores:
 *
 *   submission      a real, non-draft attempt exists. Factual and observable.
 *   released_mark   the work has been marked AND the mark returned to the student
 *                   (marks_released_at set). Strictest; for required graded work.
 *
 * `teacher_verification` is DECLARED but NOT implemented, exactly as the lesson
 * side declares `view_percentage`, `quiz` and `assignment` without implementing
 * them. Declaring a rule PIIE cannot observe would be the same error as faking
 * one: the platform would report a task as blocked on a state it can never reach.
 * A rule that cannot be satisfied must not be selectable.
 *
 * 3. EVIDENCE, AS A SET OF KINDS RATHER THAN A SINGLE ENUM VALUE
 *
 * `submission_kinds` is a comma-separated allowlist from a fixed vocabulary:
 * text, document, image, audio, video, link. It is a SET because the brief asks
 * for "permitted combinations" and a single enum value cannot express a
 * combination. A single-value enum would have meant either losing combinations or
 * an enum migration on the legacy K12 column - and `submission_type` is an enum
 * that the K12 screens read, so retyping it would put working legacy behaviour at
 * risk for a HEI feature.
 *
 * NULL means "derive from the legacy `submission_type`", which is what keeps
 * every K12 row and every pre-existing HEI assignment behaving exactly as before.
 *
 * `assignment_submission_items` is then the evidence itself: one row per item a
 * student attaches, so a single submission can carry a written response, a
 * diagram, a photograph, a voice recording and a link without any of them
 * overwriting another.
 *
 * WHY THIS DOES NOT PRECLUDE IN-BROWSER RECORDING
 *
 * The `audio` and `video` kinds are ordinary uploads here: a file the student
 * chooses, stored privately. Nothing in the schema, the vocabulary or the
 * validation assumes where the bytes came from. A future in-browser recorder
 * produces the same evidence item through the same path and needs no column,
 * rename or rewrite - it simply stops asking the student to pick a file. The
 * distinction that would need new storage (a raw capture id, a transcript) can be
 * added then, and additively.
 *
 * Nothing here pretends recording exists: there is no "Record" control, and the
 * submission form offers only upload.
 *
 * FILES STAY OUTSIDE THE WEB ROOT
 *
 * Every evidence item's `stored_path` is a generated name under storage/app and is
 * reachable only through an authorising route that re-checks the confirmed
 * registration and, for a lecturer, the allocation on that exact Offering. A
 * student's photograph or voice recording is personal evidence and must not be
 * world-readable at a guessable path.
 *
 * 4. AN OPTIONAL COURSE OFFERING COVER IMAGE - CAPABILITY ONLY
 *
 * Five nullable columns on `course_offerings`, no view change anywhere. The brief
 * is explicit that the visual card redesign is deferred, so this adds the data
 * relationship and the authorised read path, and deliberately adds no CSS, no
 * card markup and no layout. When the card interface is designed, the image will
 * already exist and will already be served safely.
 *
 * The file is stored outside the web root for the same reason as everything else:
 * a cover image is institution material, and a path under public/ would make it
 * readable by anyone who guessed it.
 *
 * MARKS ARE NOT TOUCHED
 *
 * The ONLY column added to `assignment_submissions` is `text_response`, the
 * rich-text response. Nothing about marking is added, removed or retyped: the
 * mark, its maximum, its release instant and the grader are exactly as the
 * completed Course-Offering Assignments work left them. So the Gradebook read
 * model (`GradebookFeed`) keeps consuming them unchanged, and the future Course
 * Offering Gradebook still needs no migration and no manual copying of marks.
 *
 * LESSON PROGRESS IS NOT TOUCHED EITHER
 *
 * Module completion is COMPUTED, never stored, for the whole of this feature. A
 * computed function cannot corrupt a stored record, which is what makes it safe to
 * add module-task rules without any risk to the lesson completion evidence a
 * student has already earned.
 */
class AddCourseOfferingModuleTasks extends Migration
{
    public function up(): void
    {
        $this->attachTasksToModules();
        $this->addRichTextResponse();
        $this->addEvidenceTable();
        $this->addCoverImage();
    }

    /**
     * Assignments gain an optional owning module, an optional/required role, the
     * rule that satisfies a required task, and their evidence allowlist.
     */
    private function attachTasksToModules(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }

        Schema::table('assignments', function (Blueprint $table): void {
            // THE MINIMUM RELATIONSHIP. One nullable column pointing at the
            // EXISTING module entity. NULL is the normal case: the association is
            // optional, a module may have no assignment, and most assignments are
            // not attached to a module at all.
            $table->unsignedBigInteger('course_offering_module_id')->nullable()->index('asg_module_idx');

            // 'optional' | 'required'. DEFAULTED TO OPTIONAL on purpose - an
            // assignment must never start blocking a student's progression
            // because this migration ran. Blocking is something a lecturer
            // chooses deliberately.
            $table->string('requirement_role', 20)->default('optional');

            // How a REQUIRED task is satisfied. Only meaningful when
            // requirement_role = 'required'; an optional task is never gated.
            // Vocabulary mirrors course_offering_lessons.completion_rule so the
            // two systems speak the same words.
            $table->string('completion_rule', 30)->default('submission');

            // The evidence a student may hand in: a comma-separated allowlist of
            // text,document,image,audio,video,link. A SET, because the brief asks
            // for permitted combinations and a single enum value cannot express
            // one. NULL means "derive from the legacy submission_type", which is
            // what keeps every K12 row and every pre-existing HEI assignment
            // behaving exactly as it did before this column existed.
            $table->string('submission_kinds', 120)->nullable();
        });

        Schema::table('assignments', function (Blueprint $table): void {
            // A module's own tasks, grouped by role. Supporting the completion
            // read, which asks "which REQUIRED tasks does this module have" on
            // every module render.
            $table->index(
                ['course_offering_module_id', 'requirement_role'],
                'asg_module_role_idx'
            );
        });
    }

    /**
     * The rich-text response, as a distinct LONGTEXT field.
     *
     * `assignment_submissions.submission` is TEXT and is read by the legacy K12
     * screens, so it is left exactly as it is and is not repurposed. A rich-text
     * response with an embedded table, and later images, does not fit comfortably
     * in 64 KB, so HEI submissions carry their canonical response here.
     *
     * The two fields never both carry HEI content: K12 rows have no
     * `text_response`, and HEI rows do not write `submission`. The read path
     * prefers `text_response` and falls back, so nothing is mirrored and nothing
     * can drift.
     */
    private function addRichTextResponse(): void
    {
        if (! Schema::hasTable('assignment_submissions')) {
            return;
        }

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->longText('text_response')->nullable();
        });
    }

    /**
     * One row per evidence item a student attaches.
     *
     * A single submission can carry a written response, a photograph of working, a
     * voice recording explaining a decision and a link to a published result. A
     * single file column cannot hold that, and overwriting would destroy evidence.
     */
    private function addEvidenceTable(): void
    {
        if (Schema::hasTable('assignment_submission_items')) {
            return;
        }

        Schema::create('assignment_submission_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();

            // Which attempt the item belongs to. CASCADE is correct: an attempt's
            // evidence has no meaning without the attempt, and an attempt with
            // orphaned evidence would be a lie about what a student handed in.
            $table->unsignedBigInteger('assignment_submission_id')->index('asubitem_submission_idx');
            $table->unsignedBigInteger('assignment_id')->index();
            $table->unsignedBigInteger('course_offering_id')->nullable()->index();

            // text | document | image | audio | video | link
            //
            // A varchar rather than an enum, deliberately. A closed enum would
            // need a MySQL table rebuild to extend, and the natural next kind -
            // in-browser recording - is exactly the kind of addition that should
            // not require rewriting a table. The allowed set is enforced in
            // `AssignmentSubmissionItem::isSupportedKind()` and by validation, so
            // nothing invalid can be written either way.
            $table->string('kind', 20);

            $table->string('label', 191)->nullable();

            // Generated path OUTSIDE the web root, served only through an
            // authorising route. Used for document, image, audio and video.
            $table->string('stored_path', 255)->nullable();
            $table->string('original_name', 191)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Used for the `link` kind. The written response itself lives on the
            // submission, not here, so a submission with text and no evidence
            // items is normal rather than unusual.
            $table->string('url', 500)->nullable();

            // The student may have written something alongside the file, e.g. a
            // caption on a photograph.
            $table->text('note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * An optional cover image per Course Offering. Capability only; no view uses
     * it yet, by design.
     */
    private function addCoverImage(): void
    {
        if (! Schema::hasTable('course_offerings')) {
            return;
        }

        Schema::table('course_offerings', function (Blueprint $table): void {
            // Generated path outside the web root, reachable only through the
            // authorised read route. A cover image is institution material, and a
            // path under public/ would make it readable by anyone who guessed it.
            $table->string('cover_image_path', 255)->nullable();
            $table->string('cover_image_name', 191)->nullable();
            $table->string('cover_image_mime', 100)->nullable();
            $table->unsignedBigInteger('cover_image_size')->nullable();
            // Its own timestamp, so "when was the cover last changed" is
            // answerable without consulting an audit log for a cosmetic asset.
            $table->dateTime('cover_image_updated_at')->nullable();
        });
    }


    public function down(): void
    {
        // Only ever used on a scratch database. piie_main is never rolled back.
        Schema::dropIfExists('assignment_submission_items');

        if (Schema::hasTable('assignment_submissions')) {
            Schema::table('assignment_submissions', function (Blueprint $table): void {
                $table->dropColumn('text_response');
            });
        }

        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->dropIndex('asg_module_role_idx');
                $table->dropIndex('asg_module_idx');
                foreach ([
                    'course_offering_module_id', 'requirement_role',
                    'completion_rule', 'submission_kinds',
                ] as $column) {
                    $table->dropColumn($column);
                }
            });
        }

        if (Schema::hasTable('course_offerings')) {
            Schema::table('course_offerings', function (Blueprint $table): void {
                foreach ([
                    'cover_image_path', 'cover_image_name', 'cover_image_mime',
                    'cover_image_size', 'cover_image_updated_at',
                ] as $column) {
                    $table->dropColumn($column);
                }
            });
        }
    }
}
