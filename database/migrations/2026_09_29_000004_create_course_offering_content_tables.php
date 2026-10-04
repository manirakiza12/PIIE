<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course Content: modules, lessons, lesson resources and lesson progress.
 *
 * WHY THESE FOUR TABLES AND NO OTHERS
 *
 * PIIE already has a curriculum layer - `curricula` (a Programme Study Plan),
 * `curriculum_stages` and `curriculum_memberships` (which Course Units sit at
 * which stage). That layer answers "what is planned to be taught in this
 * programme". It is a catalogue, and it is institutional: it is the same for
 * every lecturer and every student in the programme.
 *
 * Course Content answers a different question: "what did this lecturer actually
 * prepare for THIS delivery of this unit". That is per-Course-Offering, authored,
 * and belongs below the Offering in the authoritative chain, not beside the
 * Study Plan. Naming every table `course_offering_*` makes that ownership
 * unmistakable, so a future reader cannot mistake a module for a curriculum
 * stage and build a second, competing structure.
 *
 * There is no second Course, and no second assessment engine. A lesson row
 * carries `content_type` and `completion_rule` as RESERVED vocabulary so Reading
 * and Video items, and later completion rules, can be introduced without
 * altering the existing rows - but only `lesson`/`manual` are implemented here.
 * Quizzes and assignments keep their own engines; nothing here duplicates them.
 *
 * `course_offering_id` is denormalised onto lessons and resources on purpose.
 * Every read is scoped "this Offering, this tenant", and carrying the Offering on
 * the row makes that a single indexed predicate instead of a join that could be
 * forgotten. The models enforce that it always equals the parent's Offering.
 *
 * Progress rows are created on first engagement, so "Not Started" is the ABSENCE
 * of a row rather than a stored 'not_started' that has to be kept in step. The
 * unique index on (lesson, student) is what makes repeated completion attempts
 * idempotent instead of double-counting.
 */
class CreateCourseOfferingContentTables extends Migration
{
    public function up(): void
    {
        // ── Modules ────────────────────────────────────────────────────────
        if (! Schema::hasTable('course_offering_modules')) {
            Schema::create('course_offering_modules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('course_offering_id')->index();
                $table->string('title', 191);
                $table->text('summary')->nullable();
                $table->unsignedInteger('sequence')->default(0);
                // draft -> published -> archived. Plain words, no internals.
                $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
                // Optional release. A published module with no release date is
                // visible now; with one, it is not visible before that moment.
                $table->dateTime('released_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['course_offering_id', 'status', 'sequence'], 'com_modules_offering_status_seq');
            });
        }

        // ── Lessons ────────────────────────────────────────────────────────
        if (! Schema::hasTable('course_offering_lessons')) {
            Schema::create('course_offering_lessons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('course_offering_id')->index();
                $table->unsignedBigInteger('course_offering_module_id')->index();
                $table->string('title', 191);
                $table->string('summary', 500)->nullable();
                $table->text('learning_objectives')->nullable();
                // Sanitised HTML, authored in the rich text editor. Long text,
                // because a lesson with tables and images is not a paragraph.
                $table->longText('body')->nullable();
                // RESERVED vocabulary: 'lesson' now, 'reading'/'video' later.
                $table->string('content_type', 30)->default('lesson');
                $table->unsignedSmallInteger('estimated_minutes')->nullable();
                $table->unsignedInteger('sequence')->default(0);
                $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
                $table->dateTime('released_at')->nullable();
                // RESERVED: 'manual' is implemented. view_percentage, quiz,
                // assignment and teacher_verification are declared so the
                // architecture can accept them later without a migration, and
                // the reader will refuse to honour one it cannot check.
                $table->enum('completion_rule', [
                    'manual', 'view_percentage', 'quiz', 'assignment', 'teacher_verification',
                ])->default('manual');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index(['course_offering_module_id', 'status', 'sequence'], 'com_lessons_module_status_seq');
                $table->index(['course_offering_id', 'status'], 'com_lessons_offering_status');
            });
        }

        // ── Lesson resources (attachments) ─────────────────────────────────
        if (! Schema::hasTable('course_offering_lesson_resources')) {
            Schema::create('course_offering_lesson_resources', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('course_offering_id')->index();
                $table->unsignedBigInteger('course_offering_lesson_id')->index();
                $table->string('title', 191);
                $table->enum('type', ['link', 'file'])->default('link');
                $table->string('link_url', 500)->nullable();
                // Files are stored OUTSIDE the web root and served through the
                // authorised access route, exactly like Live Class materials: a
                // path in public/ would make every attachment world-readable.
                $table->string('original_name', 191)->nullable();
                $table->string('stored_name', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        // ── Lesson progress ────────────────────────────────────────────────
        if (! Schema::hasTable('course_offering_lesson_progress')) {
            Schema::create('course_offering_lesson_progress', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('course_offering_id')->index();
                $table->unsignedBigInteger('course_offering_lesson_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                // 'not started' is the absence of a row, so this column only ever
                // holds a real engagement state.
                $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->dateTime('last_viewed_at')->nullable();
                $table->timestamps();

                // This is the idempotency guarantee: a student who presses
                // "Mark Complete" twice, or whose request is retried, updates one
                // row instead of creating two and inflating their progress.
                $table->unique(
                    ['course_offering_lesson_id', 'student_id'],
                    'com_progress_lesson_student_unique'
                );
                $table->index(['student_id', 'course_offering_id'], 'com_progress_student_offering');
            });
        }
    }

    public function down(): void
    {
        // Only ever used on a scratch database. piie_main is never rolled back.
        foreach ([
            'course_offering_lesson_progress',
            'course_offering_lesson_resources',
            'course_offering_lessons',
            'course_offering_modules',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
