<?php

namespace Tests\Feature\Support;

use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingLessonResource;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course Content fixture: the academic chain from LiveClassFixture, plus the
 * four content tables.
 *
 * BUILT ON THE EXISTING FIXTURE RATHER THAN A NEW ONE
 *
 * Course Content lives inside a Course Offering, so every meaningful test needs
 * an institution, a year, a period, a subject, an in-progress Offering, an
 * allocated lecturer and a confirmed student. Rebuilding that here would create
 * a second, subtly different version of the same chain, and the two suites would
 * then disagree about what "allocated" or "confirmed" means. So this trait
 * composes the existing one and adds only what Course Content needs.
 *
 * @property int $school
 * @property int $year
 * @property int $period
 * @property int $subject
 * @property CourseOffering $offering
 * @property User $lecturer
 * @property User $otherLecturer
 * @property User $student
 */
trait CourseContentFixture
{
    /**
     * The inner setUp is ALIASED, not merely used.
     *
     * A trait that uses another trait and then declares `setUp` SHADOWS the
     * inner one - the chain would never be built and every typed property
     * would be uninitialised. Aliasing it keeps it callable, so this trait adds
     * to the fixture instead of quietly replacing it.
     */
    use LiveClassFixture {
        setUp as protected liveClassSetUp;
    }

    protected function setUp(): void
    {
        $this->liveClassSetUp();

        $this->contentSchema();
        $this->discoverySchema();
    }

    /**
     * The minimum Study Plan shape that `StudentCourseOfferingDiscovery` reads.
     *
     * Course Content is entered from a student's "My Courses" page, and testing
     * that entry point for real means rendering the genuine page - not a stub of
     * it. That page left-joins `curriculum_memberships`, so the tables have to
     * exist. Only the columns those queries actually read are declared, and the
     * row is left empty: the Course Content link lives in the CONFIRMED
     * courses table, which is built from `course_registrations`, not from the
     * Study Plan. Reconstructing a whole Programme and Placement here would be
     * a second version of the admissions fixture, and nothing about it would be
     * testing Course Content.
     */
    private function discoverySchema(): void
    {
        if (! Schema::hasTable('curriculum_memberships')) {
            Schema::create('curriculum_memberships', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id');
                $t->unsignedBigInteger('subject_id'); $t->string('period_type', 32)->nullable();
                $t->unsignedSmallInteger('period_sequence')->nullable();
                $t->string('classification', 20)->nullable(); $t->decimal('credits', 6, 2)->nullable();
                $t->unsignedSmallInteger('sequence')->nullable(); $t->timestamps();
            });
        }
        if (Schema::hasTable('curricula') === false) {
            Schema::create('curricula', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id')->nullable();
                $t->string('version', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps();
            });
        }
        if (Schema::hasTable('course_registrations')) {
            // Columns the registration query selects for the confirmed-courses
            // table. Declared here rather than loosening the query, so the test
            // exercises the real SQL the real page runs.
            foreach ([
                'curriculum_membership_id' => 'unsignedBigInteger',
                'registered_credits' => 'decimal',
                'registered_classification' => 'string',
            ] as $column => $kind) {
                if (! Schema::hasColumn('course_registrations', $column)) {
                    Schema::table('course_registrations', function (Blueprint $t) use ($column, $kind): void {
                        $column === 'registered_credits'
                            ? $t->decimal($column, 6, 2)->nullable()
                            : $t->{$kind}($column)->nullable();
                    });
                }
            }
        }
    }

    /**
     * The four Course Content tables, matching the production migration.
     *
     * `course_offering_lesson_progress` carries the same UNIQUE index on
     * (lesson, student) as production. That index IS the idempotency guarantee
     * the service relies on, so a fixture without it would test a schema PIIE
     * does not run.
     */
    private function contentSchema(): void
    {
        if (! Schema::hasTable('course_offering_modules')) {
            Schema::create('course_offering_modules', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->string('title', 191); $t->text('summary')->nullable();
                $t->unsignedInteger('sequence')->default(0);
                $t->string('status', 20)->default('draft');
                $t->dateTime('released_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_lessons')) {
            Schema::create('course_offering_lessons', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->unsignedBigInteger('course_offering_module_id');
                $t->string('title', 191); $t->string('summary', 500)->nullable();
                $t->text('learning_objectives')->nullable(); $t->longText('body')->nullable();
                $t->string('content_type', 30)->default('lesson');
                $t->unsignedSmallInteger('estimated_minutes')->nullable();
                $t->unsignedInteger('sequence')->default(0);
                $t->string('status', 20)->default('draft');
                $t->dateTime('released_at')->nullable();
                $t->string('completion_rule', 32)->default('manual');
                $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_lesson_resources')) {
            Schema::create('course_offering_lesson_resources', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->unsignedBigInteger('course_offering_lesson_id');
                $t->string('title', 191); $t->string('type', 20)->default('link');
                $t->string('link_url', 500)->nullable(); $t->string('original_name', 191)->nullable();
                $t->string('stored_name', 255)->nullable(); $t->string('mime_type', 100)->nullable();
                $t->unsignedBigInteger('size_bytes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_lesson_progress')) {
            Schema::create('course_offering_lesson_progress', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->unsignedBigInteger('course_offering_lesson_id'); $t->unsignedBigInteger('student_id');
                $t->string('status', 20)->default('in_progress');
                $t->dateTime('started_at')->nullable(); $t->dateTime('completed_at')->nullable();
                $t->dateTime('last_viewed_at')->nullable(); $t->timestamps();
                $t->unique(['course_offering_lesson_id', 'student_id'], 'com_progress_lesson_student_unique');
            });
        }
    }

    // ── content builders ──────────────────────────────────────────────────

    protected function module(array $attributes = []): CourseOfferingModule
    {
        return CourseOfferingModule::query()->create(array_merge([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Module 1 — Foundations',
            'summary' => null,
            'sequence' => (int) (CourseOfferingModule::query()->where('course_offering_id', $this->offering->id)->max('sequence') ?? 0) + 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => null,
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    protected function lesson(?CourseOfferingModule $module = null, array $attributes = []): CourseOfferingLesson
    {
        $module ??= $this->module();

        return CourseOfferingLesson::query()->create(array_merge([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'course_offering_module_id' => $module->id,
            'title' => 'Fractions, Ratios & Percentages',
            'summary' => null,
            'learning_objectives' => null,
            'body' => '<p>Percentages are fractions with a denominator of one hundred.</p>',
            'content_type' => CourseOfferingLesson::CONTENT_TYPE_LESSON,
            'estimated_minutes' => 25,
            'sequence' => (int) (CourseOfferingLesson::query()->where('course_offering_module_id', $module->id)->max('sequence') ?? 0) + 1,
            'status' => CourseOfferingLesson::STATUS_PUBLISHED,
            'released_at' => null,
            'completion_rule' => CourseOfferingLesson::RULE_MANUAL,
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    /** A module whose lessons are all published and released: student-visible. */
    protected function visibleModule(string $title = 'Module 1 — Foundations'): CourseOfferingModule
    {
        return $this->module(['title' => $title]);
    }

    protected function resource(CourseOfferingLesson $lesson, array $attributes = []): CourseOfferingLessonResource
    {
        return CourseOfferingLessonResource::query()->create(array_merge([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'title' => 'Further reading',
            'type' => CourseOfferingLessonResource::TYPE_LINK,
            'link_url' => 'https://example.org/reading',
            'created_by' => $this->lecturer->id,
        ], $attributes));
    }

    protected function markProgress(CourseOfferingLesson $lesson, User $student, string $status = CourseOfferingLessonProgress::STATUS_COMPLETED): CourseOfferingLessonProgress
    {
        return CourseOfferingLessonProgress::query()->create([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'started_at' => now()->subDay(),
            'completed_at' => $status === CourseOfferingLessonProgress::STATUS_COMPLETED ? now()->subHour() : null,
            'last_viewed_at' => now(),
        ]);
    }

    // ── a second institution, for isolation tests ─────────────────────────

    /**
     * A whole second tenant: school, subject, Offering, allocated lecturer and
     * confirmed student, all in the other school.
     *
     * Isolation is only meaningfully tested by having content that genuinely
     * belongs somewhere else, and only meaningfully asserted by trying to reach
     * it. A second school with a second set of people is the only fixture that
     * can do that.
     */
    protected function otherTenant(): array
    {
        $schoolId = (int) DB::table('schools')->insertGetId([
            'title' => 'Second Institution', 'school_type' => 'higher_ed', 'education_level' => 'tertiary',
            'timezone' => 'Africa/Nairobi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $subjectId = (int) DB::table('subjects')->insertGetId([
            'school_id' => $schoolId, 'name' => 'Business Mathematics', 'code' => 'BBIT1103',
        ]);

        $offeringId = (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $schoolId, 'subject_id' => $subjectId,
            'academic_year_id' => $this->year, 'academic_period_id' => $this->period,
            'reference' => 'BBIT1103-2026-S1', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lecturer = $this->tenantUser('Grace Nakato', 3, $schoolId);
        $student = $this->tenantUser('Mugisha Peter', 7, $schoolId);

        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId, 'user_id' => $lecturer->id,
            'role' => CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('course_registrations')->insert([
            'school_id' => $schoolId, 'student_id' => $student->id, 'subject_id' => $subjectId,
            'course_offering_id' => $offeringId, 'status' => 'confirmed',
        ]);

        $moduleId = (int) DB::table('course_offering_modules')->insertGetId([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId,
            'title' => 'Other tenant module', 'sequence' => 1, 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lessonId = (int) DB::table('course_offering_lessons')->insertGetId([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId,
            'course_offering_module_id' => $moduleId, 'title' => 'Other tenant lesson',
            'body' => '<p>Confidential to the second institution.</p>', 'content_type' => 'lesson',
            'sequence' => 1, 'status' => 'published', 'completion_rule' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('schoolId', 'subjectId', 'offeringId', 'lecturer', 'student', 'moduleId', 'lessonId');
    }

    private function tenantUser(string $name, int $role, int $schoolId): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => str_replace([' ', '.'], ['.', ''], strtolower($name))."{$role}.{$schoolId}@other.example.test",
            'role_id' => $role, 'school_id' => $schoolId, 'account_status' => 'active',
            'password' => bcrypt('User#2026'),
        ]);
    }

    /**
     * A SECOND Offering inside the SAME school.
     *
     * Needed because cross-Offering denial is a different failure from
     * cross-tenant denial: the ids are both valid, the tenant is the same, and
     * only the Offering differs. A second tenant alone would not prove the
     * Offering boundary, because the school check would catch it anyway.
     */
    protected function secondOfferingSameSchool(): CourseOffering
    {
        $offeringId = (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $this->school, 'subject_id' => $this->subject,
            'academic_year_id' => $this->year, 'academic_period_id' => $this->period,
            'reference' => 'BBIT1103-2026-S2', 'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CourseOffering::query()->findOrFail($offeringId);
    }
}
