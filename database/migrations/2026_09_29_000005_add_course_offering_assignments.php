<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course-Offering Assignments, built INSIDE the existing assignment domain.
 *
 * WHY THIS EXTENDS `assignments` RATHER THAN ADDING A NEW PRODUCT
 *
 * PIIE already has assignments, submissions and grading. They are keyed to the
 * legacy K12 concepts Subject / Class / Teacher and have no Course Offering on
 * them at all, and both tables are currently empty. Creating a parallel
 * `course_offering_assignments` table would have produced two assignment
 * products with two submission models and two grading paths, and a future
 * "which table is authoritative?" decision for the academic office. So the
 * existing tables are EXTENDED, and one nullable column separates the two worlds:
 *
 *   course_offering_id IS NULL  -> a legacy K12 assignment, unchanged
 *   course_offering_id IS SET   -> a Course Offering assignment
 *
 * Every legacy column keeps its meaning and its rows. Nothing is renamed,
 * retyped or dropped, so `is_published`, `due_date`, `max_marks`,
 * `submission_type` and the `status` enum continue to work exactly as the K12
 * screens and the existing tests expect. HEI visibility is decided by the new
 * `status` column, and the legacy `is_published` flag is still maintained for
 * HEI rows so any older query still behaves sensibly rather than going dark.
 *
 * `submission_type` is deliberately NOT retyped. The existing enum already
 * carries file, text and "any" (file + text), which is exactly the set of
 * submission modes required, so no enum migration is needed and no K12 row can
 * be disturbed. The legacy "link" value is left in place for K12 but is NOT
 * offered to HEI lecturers, because PIIE has no external submission
 * integration to honour it.
 *
 * LIFECYCLE, IN PLAIN WORDS
 *
 *   draft      -> lecturer/admin only; no student can see or discover it
 *   scheduled  -> approved, but not open until `released_at`; invisible before
 *   published  -> open to confirmed students on this exact Course Offering
 *   closed     -> no NEW submissions accepted; existing ones are preserved
 *
 * SUBMISSIONS ARE ONE ROW PER ATTEMPT, NOT ONE ROW OVERWRITTEN
 *
 * A UNIQUE index on (assignment, student, attempt_no) makes each attempt its own
 * durable record, so resubmission cannot erase earlier work and grading history
 * is preserved for free. The pre-existing K12 controller already refused a second
 * submission per (assignment, student) and always used attempt 1, so this index
 * matches the behaviour the legacy data already had rather than inventing a new
 * rule. K12 code that calls ->first() still gets attempt 1.
 *
 * `is_draft` exists because a student must be able to PREPARE work without it
 * counting as submitted. Opening an assignment must never be a submission, and
 * neither must saving a draft.
 *
 * `marks_released_at` is the gate for a student seeing a mark or any feedback.
 * A lecturer may record a mark while still deciding on it; the student sees
 * nothing until the mark is deliberately released. That is a column rather than a
 * flag on the row's status so "released when" is answerable afterwards.
 *
 * `idempotency_key` is a UNIQUE column, so a double-click, a retried request or
 * two racing tabs cannot create two submissions: the loser of the unique index
 * is silently discarded rather than double-counted. This is the same technique
 * PIIE's Live Class notification ledger uses.
 *
 * FILES ARE STORED OUTSIDE THE WEB ROOT
 *
 * HEI submission files live under storage/app, and are only reachable through an
 * authorising route. This is deliberately different from the legacy K12 flow,
 * which writes into public_path('assets/uploads/assignments') and therefore
 * serves every student's work at a guessable public URL. That is a pre-existing
 * weakness in the legacy path; it is reported, not silently changed, because the
 * legacy screens depend on it and K12 behaviour must be preserved.
 */
class AddCourseOfferingAssignments extends Migration
{
    public function up(): void
    {
        $this->extendAssignments();
        $this->extendSubmissions();
        $this->createResources();
        $this->createNotificationLedger();
    }

    /**
     * Assignments: HEI ownership plus the governed lifecycle.
     *
     * Every added column is nullable or has a default, so a legacy K12 row is
     * left valid and unremarkable by this migration.
     */
    private function extendAssignments(): void
    {
        if (! Schema::hasTable('assignments')) {
            return;
        }

        Schema::table('assignments', function (Blueprint $table): void {
            // The HEI container. NULL identifies a legacy K12 assignment, which
            // is what every existing K12 query already keys off implicitly.
            $table->unsignedBigInteger('course_offering_id')->nullable()->index('asg_offering_idx');
            $table->text('learning_objectives')->nullable();

            // draft | scheduled | published | closed
            $table->string('status', 20)->default('draft');

            // When the assignment becomes open to students. Required for
            // `scheduled`, ignored for `published` (which is open now).
            $table->dateTime('released_at')->nullable();
            // The final deadline. After this, no NEW submission is accepted,
            // whatever the late policy says. Existing submissions are kept.
            $table->dateTime('closes_at')->nullable();

            // NULL or 1 means one attempt. >1 permits resubmission up to that
            // many attempts.
            $table->unsignedSmallInteger('allowed_attempts')->nullable();
            // allow = a submission after the DUE date is still accepted and
            // recorded as late.
            // block = a submission after the due date is refused.
            // `closes_at` closes the assignment either way.
            $table->string('late_policy', 20)->default('allow');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
        });

        Schema::table('assignments', function (Blueprint $table): void {
            $table->index(['course_offering_id', 'status'], 'asg_offering_status_idx');
        });
    }

    /**
     * Submissions: attempts, drafts, protected file metadata, grading audit and
     * the release gate.
     */
    private function extendSubmissions(): void
    {
        if (! Schema::hasTable('assignment_submissions')) {
            return;
        }

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_offering_id')->nullable()->index('asub_offering_idx');
            $table->unsignedSmallInteger('attempt_no')->default(1);
            // Prepared work that has NOT been submitted. Opening an assignment
            // and saving a draft both leave this at 1; only an explicit submit
            // clears it.
            $table->boolean('is_draft')->default(false);

            // The original filename is kept only as a display label; `file_path`
            // holds a GENERATED path outside the web root.
            $table->string('file_name', 191)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_mime', 100)->nullable();

            // Grading audit: who decided what, and when.
            $table->dateTime('graded_at')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedBigInteger('returned_by')->nullable();
            // THE STUDENT-VISIBLE GATE. A recorded mark is invisible to the
            // student until this is set.
            $table->dateTime('marks_released_at')->nullable();

            // Client-supplied token proving one logical submission. The UNIQUE
            // index is the actual duplicate guard.
            $table->string('idempotency_key', 64)->nullable();
        });

        Schema::table('assignment_submissions', function (Blueprint $table): void {
            // One row per attempt. Resubmission therefore preserves history
            // instead of overwriting it.
            $table->unique(
                ['assignment_id', 'student_id', 'attempt_no'],
                'asub_attempt_unique'
            );
            $table->unique('idempotency_key', 'asub_idempotency_unique');
            $table->index(['course_offering_id', 'student_id'], 'asub_offering_student_idx');
        });
    }

    /**
     * Lecturer attachments on an assignment.
     *
     * A separate table from `course_offering_lesson_resources` on purpose. That
     * table belongs to Course Content, which is closed and manually tested;
     * widening it to carry assignments would mean a polymorphic rewrite of a
     * certified surface. This is a distinct aggregate with a distinct owner,
     * and it stores files privately exactly as lesson resources do.
     */
    private function createResources(): void
    {
        if (Schema::hasTable('assignment_resources')) {
            return;
        }

        Schema::create('assignment_resources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('course_offering_id')->nullable()->index();
            $table->unsignedBigInteger('assignment_id')->index();
            $table->string('title', 191);
            $table->string('type', 20)->default('link');
            $table->string('link_url', 500)->nullable();
            // Generated name, outside the web root, served only through the
            // authorising route.
            $table->string('original_name', 191)->nullable();
            $table->string('stored_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * One row per announced event, with a UNIQUE dedup key.
     *
     * Mirrors live_class_notifications, but keys on a string rather than
     * (parent, type) so that per-submission events - a grade returned to one
     * student - are distinguishable from whole-assignment events. A MySQL UNIQUE
     * index does not constrain NULLs, so a nullable submission column could not
     * carry the guarantee on its own.
     */
    private function createNotificationLedger(): void
    {
        if (Schema::hasTable('assignment_notifications')) {
            return;
        }

        Schema::create('assignment_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('assignment_id')->index();
            $table->unsignedBigInteger('submission_id')->nullable()->index();
            $table->string('dedup_key', 191);
            $table->string('type', 40);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique('dedup_key', 'asn_dedup_unique');
        });
    }

    public function down(): void
    {
        // Only ever used on a scratch database. piie_main is never rolled back.
        Schema::dropIfExists('assignment_notifications');
        Schema::dropIfExists('assignment_resources');

        if (Schema::hasTable('assignment_submissions')) {
            Schema::table('assignment_submissions', function (Blueprint $table): void {
                foreach (['asub_idempotency_unique', 'asub_attempt_unique', 'asub_offering_student_idx'] as $index) {
                    try {
                        $table->dropUnique($index);
                    } catch (\Throwable $e) {
                        // Already absent on this engine.
                    }
                }
                $table->dropIndex('asub_offering_idx');
                foreach ([
                    'course_offering_id', 'attempt_no', 'is_draft', 'file_name', 'file_size', 'file_mime',
                    'graded_at', 'graded_by', 'returned_at', 'returned_by', 'marks_released_at', 'idempotency_key',
                ] as $column) {
                    $table->dropColumn($column);
                }
            });
        }

        if (Schema::hasTable('assignments')) {
            Schema::table('assignments', function (Blueprint $table): void {
                $table->dropIndex('asg_offering_status_idx');
                $table->dropIndex('asg_offering_idx');
                foreach ([
                    'course_offering_id', 'learning_objectives', 'status', 'released_at', 'closes_at',
                    'allowed_attempts', 'late_policy', 'created_by', 'updated_by', 'closed_at', 'closed_by',
                ] as $column) {
                    $table->dropColumn($column);
                }
            });
        }
    }
}
