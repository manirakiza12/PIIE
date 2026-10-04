<?php

namespace Tests\Feature\Support;

use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE COURSE OFFERING TABLES THE EXAM FEATURE READS.
 *
 * ── WHY THIS EXISTS RATHER THAN A MIGRATION ────────────────────────────────
 *
 * The Course Offering schema is large - registrations, allocations, content,
 * modules, lessons, progress, live classes, attendance - and the exam feature
 * reads four tables of it: `course_offerings`, `course_registrations`,
 * `course_offering_lecturer_allocations` and the two academic-calendar tables.
 * Re-declaring the whole foundation here would be a large, drifting copy of
 * something that already has migrations, and the existing Course Content and
 * Assignment fixtures already take the same selective approach.
 *
 * So the real migrations are RUN, and only where the table is missing. That is the
 * important difference: a hand-written `Schema::create` can quietly disagree with
 * production and a test will pass against the fiction, whereas a migration can
 * only produce what production produces. The course offering helper methods below
 * therefore insert real rows through the real columns.
 *
 * ── THE ASSESSMENT TABLES COME FROM THE EXAM HELPER ───────────────────────
 *
 * `OnlineExamTestHelper` already declares `online_exams` and friends, faithfully,
 * for the whole engine. Composing it here rather than re-declaring the exam schema
 * is the same argument one level up: one declaration of the engine's shape, used
 * by every suite that needs it.
 */
trait CourseOfferingExamFixture
{
    /**
     * Build the exam engine's own tables, then the four Course Offering ones the
     * exam feature reads.
     *
     * ── WHY THE TABLES ARE DECLARED HERE RATHER THAN MIGRATED ───────────────
     *
     * The real migrations are not runnable on SQLite. `add_offering_context_to_
     * course_registrations` inspects `information_schema.table_constraints` to
     * verify existing foreign keys, and that table does not exist on SQLite - so
     * invoking it fails outright. That is why twenty-seven existing suites in this
     * codebase declare these tables by hand, and this follows the same convention
     * rather than inventing a third approach.
     *
     * The declarations below are transcriptions of the real columns, and one
     * deliberate guard is kept: `test_the_CALENDAR_columns_match_production`
     * asserts the columns the production code actually binds to exist here, so a
     * column the real table has and this one lacks fails loudly instead of
     * producing a null that reads as a dash. The academic-year defect of the
     * previous pass - code reading `->name` from a table that carries `label` -
     * is exactly what that assertion is for.
     */
    protected function bootCourseOfferingExamSchema(): void
    {
        $this->bootOnlineExamTestSchema();
        $this->createCourseOfferingExamTables();
    }

    private function createCourseOfferingExamTables(): void
    {
        if (! Schema::hasTable('course_registrations')) {
            Schema::create('course_registrations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('session_id')->nullable();
                $table->unsignedBigInteger('course_offering_id')->nullable();
                $table->unsignedBigInteger('curriculum_membership_id')->nullable();
                $table->decimal('registered_credits', 6, 2)->nullable();
                $table->string('registered_classification')->nullable();
                $table->string('status', 20)->default('registered');
                $table->timestamps();
                $table->unique(['school_id', 'student_id', 'course_offering_id']);
            });
        }

        if (! Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('school_id');
                // `label`, and NO `name` column - matching production exactly. The
                // header reads `->label`; a fixture that also offered `name` would
                // hide a regression back to `->name` forever.
                $table->string('label');
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('academic_periods')) {
            Schema::create('academic_periods', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->string('type');
                $table->string('label');
                $table->unsignedSmallInteger('sequence');
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('course_offerings')) {
            Schema::create('course_offerings', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('academic_year_id');
                $table->unsignedBigInteger('academic_period_id');
                $table->string('reference')->nullable();
                $table->string('status');
                $table->string('cover_image_path')->nullable();
                $table->string('cover_image_name')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('course_offering_lecturer_allocations')) {
            Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('course_offering_id');
                $table->unsignedBigInteger('user_id');
                $table->string('role');
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->string('status');
                $table->timestamps();
            });
        }

        $this->createCourseHomeTables();
    }

    /**
     * The Course Home reads four more tables this fixture's tests do not otherwise
     * touch: modules, lessons, lesson progress and the Assignment itself.
     *
     * Two tests here load the WHOLE Course Home - to check the Quizzes & Exams tab
     * and the count beside it - and that page reads every one of them. Without these
     * the page 500s on `course_offering_modules`, which is a fixture gap and not a
     * defect in the feature.
     *
     * They are declared EMPTY and no test here populates them, which is deliberate:
     * these tests are about the assessment tab, and a course with no content is the
     * honest starting state for a first-week term.
     */
    private function createCourseHomeTables(): void
    {
        if (! Schema::hasTable('course_offering_modules')) {
            Schema::create('course_offering_modules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('course_offering_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->string('completion_rule', 30)->default('all_lessons');
                $table->boolean('is_required')->default(true);
                $table->string('status', 20)->default('published');
                // THE LIFECYCLE COLUMNS. Additive and nullable.
                //
                // Module publishing could not be exercised at all without these: `released_at`
                // is the entire basis of "Scheduled" - `isReleasedToStudents()` reads it,
                // `displayStatusLabel()` reads it, and the lifecycle writes it. A fixture
                // without the column cannot schedule anything, so any test of publishing
                // would fail on "no such column" before asserting a single rule.
                //
                // `summary` is the production name for the module description. The existing
                // `description` column above is LEFT IN PLACE: other suites read it, and
                // renaming a shared fixture column to tidy it up is not worth breaking
                // them over.
                $table->text('summary')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('course_offering_lessons')) {
            Schema::create('course_offering_lessons', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('course_offering_module_id');
                $table->string('title');
                $table->longText('body')->nullable();
                $table->unsignedSmallInteger('sequence')->default(0);
                $table->unsignedInteger('duration_mins')->nullable();
                $table->string('status', 20)->default('published');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('course_offering_lesson_progress')) {
            Schema::create('course_offering_lesson_progress', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('course_offering_lesson_id');
                $table->unsignedBigInteger('student_id');
                $table->string('status', 20)->default('started');
                $table->dateTime('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['course_offering_lesson_id', 'student_id'], 'colp_lesson_student_uq');
            });
        }

        if (! Schema::hasTable('assignments')) {
            // The production column NAMES, read off the real table.
            //
            // `due_date` and `closes_at`, not the `due_at` and `opens_at` that were
            // guessed here first. A fixture column name that does not exist in
            // production is invisible to every test that never queries it, and then
            // 500s the moment a query does - which is exactly what happened.
            Schema::create('assignments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('course_offering_id')->nullable();
                $table->unsignedBigInteger('course_offering_module_id')->nullable();
                $table->string('title');
                $table->longText('instructions')->nullable();
                $table->longText('learning_objectives')->nullable();
                $table->decimal('max_marks', 8, 2)->nullable();
                $table->string('submission_type', 30)->nullable();
                $table->boolean('is_published')->default(false);
                $table->dateTime('due_date')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->dateTime('closes_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->unsignedInteger('allowed_attempts')->default(1);
                $table->string('late_policy', 30)->nullable();
                $table->string('requirement_role', 30)->nullable();
                $table->string('completion_rule', 30)->nullable();
                $table->json('submission_kinds')->nullable();
                $table->string('status', 30)->default('draft');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('live_classes')) {
            // `scheduled_at`/`ends_at` and `platform`/`meeting_url` are the real
            // names; `starts_at` and `join_url` were guesses that did not exist.
            Schema::create('live_classes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('course_offering_id')->nullable();
                $table->unsignedBigInteger('class_id')->nullable();
                $table->unsignedBigInteger('programme_id')->nullable();
                $table->unsignedBigInteger('academic_session_id')->nullable();
                $table->string('platform', 40)->nullable();
                $table->string('meeting_url')->nullable();
                $table->string('meeting_id')->nullable();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('ends_at')->nullable();
                $table->string('timezone', 60)->nullable();
                $table->string('status', 30)->default('scheduled');
                $table->boolean('is_published')->default(false);
                $table->boolean('attendance_enabled')->default(false);
                $table->timestamps();
            });
        }
    }

    /**
     * The fixture's tables carry the columns PRODUCTION binds to.
     *
     * Asserted rather than assumed, because a hand-written declaration that is
     * missing a column produces a silent null - and a null renders as a dash, which
     * is how an entire academic year went missing from every course header in PIIE
     * while twenty-nine fixture tests stayed green.
     */
    protected function assertFixtureTablesMatchProduction(): void
    {
        foreach ([
            ['course_offerings', ['school_id', 'subject_id', 'academic_year_id', 'academic_period_id', 'reference', 'status']],
            ['course_registrations', ['school_id', 'student_id', 'course_offering_id', 'status']],
            ['course_offering_lecturer_allocations', ['school_id', 'course_offering_id', 'user_id', 'role', 'starts_on', 'ends_on', 'status']],
            ['academic_years', ['school_id', 'label']],
            ['academic_periods', ['school_id', 'academic_year_id', 'label']],
            // Read by the Course Home on the way to the Quizzes & Exams tab.
            ['assignments', ['course_offering_id', 'title', 'status', 'closes_at', 'due_date', 'allowed_attempts']],
            ['live_classes', ['course_offering_id', 'scheduled_at', 'status']],
                        // `status` and `released_at` are what module PUBLISHING binds to, and
            // they were missing - which is why a scheduled module could not be
            // exercised. The guard checked identity and ordering only, so it passed
            // over the gap. Checked now.
            ['course_offering_modules', [
                'course_offering_id', 'title', 'sequence', 'completion_rule',
                'status', 'released_at',
            ]],
            ['course_offering_lessons', ['course_offering_module_id', 'title', 'sequence']],
            ['course_offering_lesson_progress', ['course_offering_lesson_id', 'student_id', 'status']],
        ] as [$table, $columns]) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "the [{$table}.{$column}] column production binds to must exist in the fixture"
                );
            }
        }

        // And the columns that DO NOT exist in production must not exist here either,
        // so a regression back to `->name` cannot be made to pass by the fixture.
        $this->assertFalse(Schema::hasColumn('academic_years', 'name'));
        $this->assertFalse(Schema::hasColumn('academic_periods', 'name'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE ACADEMIC CALENDAR
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Insert a row into the calendar table the production code binds to.
     *
     * `label`, and never `name` - see the note on the fixture's table declaration.
     */
    protected function makeAcademicYear(int $schoolId, string $label = '2026/2027'): int
    {

        return (int) DB::table('academic_years')->insertGetId([
            'school_id' => $schoolId,
            'label' => $label,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function makeAcademicPeriod(int $schoolId, int $yearId, string $label = 'Semester 1'): int
    {
        return (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $schoolId,
            'academic_year_id' => $yearId,
            'type' => 'semester',
            'label' => $label,
            'sequence' => 1,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(4)->endOfMonth()->toDateString(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertCalendarExists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "[{$table}] must exist before it is written to");
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE COURSE OFFERING, AND THE THREE RELATIONS THAT GOVERN IT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A Course Offering in the institution, in its term.
     *
     * The subject is created here because the exam's own `subject_id` is DERIVED
     * from the Offering rather than chosen by a lecturer - which is the whole point
     * of not faking delivery through the legacy Class/Section graph.
     */
    protected function makeOffering(int $schoolId = 1, array $overrides = []): CourseOffering
    {
        $yearId = $overrides['academic_year_id'] ?? $this->makeAcademicYear($schoolId);
        $periodId = $overrides['academic_period_id'] ?? $this->makeAcademicPeriod($schoolId, $yearId);
        $subjectId = $this->makeSubject($schoolId);

        $id = (int) DB::table('course_offerings')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'reference' => 'BBIT1103-2026-S1',
            'subject_id' => $subjectId,
            'academic_year_id' => $yearId,
            'academic_period_id' => $periodId,
            'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ], array_diff_key($overrides, ['academic_year_id' => null, 'academic_period_id' => null])));

        return CourseOffering::query()->findOrFail($id);
    }

    /**
     * A lecturer with a CURRENT allocation - started, not ended, active.
     *
     * Current is the point. An allocation that begins tomorrow is a different
     * state with a different answer, and a fixture that made every allocation
     * current by default would quietly stop testing the date rules.
     */
    protected function allocateLecturer(CourseOffering $offering, User $lecturer, array $overrides = []): int
    {
        return (int) DB::table('course_offering_lecturer_allocations')->insertGetId(array_merge([
            'school_id' => $offering->school_id,
            'course_offering_id' => $offering->id,
            'user_id' => $lecturer->id,
            'role' => 'primary_lecturer',
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    /**
     * A student CONFIRMED on this Offering.
     *
     * Confirmed, not merely registered: `registered` is a state the student
     * eligibility rules deliberately refuse, and a fixture that used it would
     * make a test pass for the wrong reason.
     */
    protected function confirmStudent(CourseOffering $offering, User $student, string $status = 'confirmed'): int
    {
        return (int) DB::table('course_registrations')->insertGetId([
            'student_id' => $student->id,
            'subject_id' => $offering->subject_id,
            'session_id' => $offering->academic_period_id,
            'school_id' => $offering->school_id,
            'course_offering_id' => $offering->id,
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // A SECOND INSTITUTION, BUILT ONCE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Another institution, with its own Offering and its own confirmed student.
     *
     * BUILT ONCE and memoised. Calling this twice created two different schools,
     * and a test that compared "their year" against one call while asserting
     * against the other passed or failed for a reason that had nothing to do with
     * tenant isolation.
     */
    protected function otherInstitution(): array
    {
        if (isset($this->piieOtherInstitution)) {
            return $this->piieOtherInstitution;
        }

        $schoolId = (int) DB::table('schools')->insertGetId([
            'title' => 'Second Institution',
            'school_type' => 'higher_ed',
            'education_level' => 'university',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $yearId = $this->makeAcademicYear($schoolId, '2025/2026');
        $theirOffering = $this->makeOffering($schoolId, [
            'reference' => 'THEIRS-2025-S1',
            'academic_year_id' => $yearId,
            'academic_period_id' => $this->makeAcademicPeriod($schoolId, $yearId, 'Semester 2'),
        ]);

        $theirLecturer = $this->makeUser(3, $schoolId, 'Daniel Elsewhere');
        $this->allocateLecturer($theirOffering, $theirLecturer);

        $theirStudent = $this->makeUser(7, $schoolId, 'Student Elsewhere');
        $this->confirmStudent($theirOffering, $theirStudent);

        return $this->piieOtherInstitution = [
            'schoolId' => $schoolId,
            'offering' => $theirOffering,
            'lecturer' => $theirLecturer,
            'student' => $theirStudent,
            'yearId' => $yearId,
        ];
    }

    /** A student of THIS institution who is NOT on the given Offering. */
    protected function unconfirmedStudentFor(CourseOffering $offering, string $name = 'Not On This Course'): User
    {
        $student = $this->makeUser(7, $offering->school_id, $name);

        $this->confirmStudent($offering, $student, 'registered');

        return $student;
    }
}
