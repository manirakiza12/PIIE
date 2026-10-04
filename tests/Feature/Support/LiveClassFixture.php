<?php

namespace Tests\Feature\Support;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\School;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;

/**
 * One HEI Course Offering Live Class fixture, shared by the certification suite
 * and the final-corrections suite.
 *
 * Extracted into a trait rather than inherited, because the second suite needed
 * the SAME fixture and extending the first would have re-run all of its tests
 * inside the second - inflating both the reported test count and the failure
 * count of every regression.
 */
trait LiveClassFixture
{
    use AdmissionsTestHelper;

    protected int $school;

    protected int $year;

    protected int $period;

    protected int $subject;

    protected CourseOffering $offering;

    protected User $lecturer;

    protected User $student;

    protected User $otherLecturer;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('app.timezone', 'UTC');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->bootAdmissionsTestSchema();
        $this->schema();

        $this->school = (int) DB::table('schools')->insertGetId([
            'title' => 'PIIE', 'school_type' => 'higher_ed', 'education_level' => 'tertiary',
            'timezone' => 'Africa/Kampala', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->year = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $this->school, 'label' => '2026/2027',
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->period = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $this->school, 'academic_year_id' => $this->year, 'type' => 'semester',
            'label' => 'Semester 1', 'sequence' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $this->school, 'name' => 'Business Mathematics', 'code' => 'BBIT1103',
        ]);

        $this->offering = app(CourseOfferingService::class)
            ->createDraft($this->school, $this->subject, $this->year, $this->period, 'BBIT1103-2026-S1');
        DB::table('course_offerings')->where('id', $this->offering->id)->update(['status' => 'in_progress']);
        $this->offering = $this->offering->fresh();

        $this->lecturer = $this->user('Daniel Okello', 3);
        $this->otherLecturer = $this->user('Grace Nakato', 3);
        $this->student = $this->user('Kyeyune Amos', 7);

        foreach ([$this->lecturer, $this->otherLecturer] as $lecturer) {
            DB::table('user_permissions')->insert([
                ['school_id' => $this->school, 'user_id' => $lecturer->id, 'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()],
                ['school_id' => $this->school, 'user_id' => $lecturer->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
        $this->allocate($this->lecturer, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->allocate($this->otherLecturer, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER);
        $this->confirm($this->student);
    }

    /**
     * The fixture schema, ADDITIVE on top of AdmissionsTestHelper.
     *
     * Every table is guarded, because the shared helper already builds several of
     * them. An unguarded create fails on SQLite with "table already exists",
     * which is a fixture defect rather than a product one - and one that would
     * otherwise mask every real assertion in a suite.
     */
    private function schema(): void
    {
        $this->columns();

        if (! Schema::hasTable('currency')) {
            Schema::create('currency', function (Blueprint $t): void {
                $t->id(); $t->string('title')->nullable(); $t->string('code')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label');
                $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('academic_periods')) {
            Schema::create('academic_periods', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id');
                $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence');
                $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name');
                $t->string('code')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offerings')) {
            Schema::create('course_offerings', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id');
                $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id');
                $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_lecturer_allocations')) {
            Schema::create('course_offering_lecturer_allocations', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on');
                $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_registrations')) {
            Schema::create('course_registrations', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('course_offering_id');
                $t->string('status'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_permissions')) {
            Schema::create('user_permissions', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
                $t->string('permission', 100); $t->timestamps();
                $t->unique(['user_id', 'permission']);
            });
        }
        if (! Schema::hasTable('teacher_programme_assignments')) {
            Schema::create('teacher_programme_assignments', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('staff_roles')) {
            Schema::create('staff_roles', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name', 100);
                $t->boolean('is_active')->default(true); $t->timestamps();
            });
        }
        if (! Schema::hasTable('staff_role_permissions')) {
            Schema::create('staff_role_permissions', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('staff_role_id');
                $t->string('permission', 100); $t->timestamps();
            });
        }
        if (! Schema::hasTable('user_staff_roles')) {
            Schema::create('user_staff_roles', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('staff_role_id'); $t->timestamps();
            });
        }
        if (! Schema::hasTable('enrollments')) {
            Schema::create('enrollments', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('class_id')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('live_class_notifications')) {
            Schema::create('live_class_notifications', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
                $t->string('type', 30); $t->unsignedInteger('recipient_count')->default(0);
                $t->timestamp('sent_at'); $t->timestamps();
                $t->unique(['live_class_id', 'type'], 'lcn_unique');
            });
        }
        if (! Schema::hasTable('live_class_attendances')) {
            Schema::create('live_class_attendances', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
                $t->unsignedBigInteger('user_id'); $t->unsignedSmallInteger('role_id')->nullable();
                $t->timestamp('joined_at')->nullable(); $t->timestamp('left_at')->nullable();
                $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_attendance_sessions')) {
            Schema::create('course_offering_attendance_sessions', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
                $t->unsignedBigInteger('live_class_id')->nullable(); $t->string('status', 20);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('course_offering_attendance_records')) {
            Schema::create('course_offering_attendance_records', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id');
                $t->unsignedBigInteger('attendance_session_id'); $t->unsignedBigInteger('registration_id');
                $t->string('status', 20); $t->text('notes')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('live_class_materials')) {
            Schema::create('live_class_materials', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
                $t->string('title'); $t->string('category', 30)->nullable();
                $t->string('url', 500)->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('live_classes')) {
            Schema::create('live_classes', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->string('title');
                $t->text('description')->nullable(); $t->unsignedBigInteger('subject_id')->nullable();
                $t->unsignedBigInteger('course_offering_id')->nullable(); $t->unsignedBigInteger('class_id')->nullable();
                $t->unsignedBigInteger('programme_id')->nullable(); $t->unsignedBigInteger('academic_session_id')->nullable();
                $t->unsignedBigInteger('teacher_id')->nullable();
                $t->enum('platform', ['jitsi', 'google_meet', 'zoom', 'bigbluebutton', 'custom'])->default('jitsi');
                $t->string('meeting_url', 500)->nullable(); $t->string('meeting_id', 150)->nullable();
                $t->string('meeting_password', 150)->nullable();
                $t->date('start_date')->nullable(); $t->time('start_time')->nullable(); $t->time('end_time')->nullable();
                $t->string('timezone', 64)->default('UTC');
                $t->datetime('scheduled_at')->nullable(); $t->datetime('ends_at')->nullable();
                $t->enum('status', ['draft', 'scheduled', 'live', 'ended', 'cancelled'])->default('draft');
                $t->boolean('is_published')->default(false); $t->boolean('attendance_enabled')->default(false);
                $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
                $t->string('recording_url', 500)->nullable();
                $t->dateTime('started_at')->nullable();
                $t->dateTime('ended_at')->nullable();
                $t->dateTime('cancelled_at')->nullable();
                $t->unsignedBigInteger('started_by')->nullable();
                $t->unsignedBigInteger('ended_by')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->string('recording_status', 20)->default('none');
                $t->timestamps();
            });
        }
    }

    /** Columns the shared helper does not already provide. */
    private function columns(): void
    {
        foreach (['school_type' => 'string', 'education_level' => 'string', 'timezone' => 'string',
            'phone' => 'integer', 'address' => 'string', 'primary_locale' => 'string',
            'country_code' => 'string', 'school_currency' => 'string', 'currency_position' => 'string',
            'terminology_overrides' => 'text', 'academic_calendar_pattern' => 'string',
        ] as $col => $kind) {
            if (Schema::hasTable('schools') && ! Schema::hasColumn('schools', $col)) {
                Schema::table('schools', function (Blueprint $t) use ($col, $kind): void {
                    $t->{$kind}($col)->nullable();
                });
            }
        }
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('timezone', 64)->nullable();
            });
        }
    }

    protected function user(string $name, int $role): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => str_replace([' ', '.'], ['.', ''], strtolower($name))."{$role}@example.test",
            'role_id' => $role, 'school_id' => $this->school, 'account_status' => 'active',
            'password' => Hash::make('User#2026'),
        ]);
    }

    protected function allocate(User $user, string $role, ?string $startsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $user->id, 'role' => $role,
            'starts_on' => $startsOn ?? now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function confirm(User $user, string $status = CourseRegistration::STATUS_CONFIRMED): void
    {
        DB::table('course_registrations')->insert([
            'school_id' => $this->school, 'student_id' => $user->id, 'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id, 'status' => $status,
        ]);
    }

    protected function class(array $attributes = []): LiveClass
    {
        $starts = $attributes['scheduled_at'] ?? now()->addHours(3);
        $class = new LiveClass();
        $class->forceFill(array_merge([
            'school_id' => $this->school, 'title' => 'Business Mathematics',
            'subject_id' => $this->subject, 'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->lecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/room',
            'timezone' => 'Africa/Kampala',
            'scheduled_at' => $starts, 'ends_at' => Carbon::parse($starts)->copy()->addHour(),
            'start_date' => Carbon::parse($starts)->toDateString(),
            'start_time' => Carbon::parse($starts)->format('H:i:s'),
            'end_time' => Carbon::parse($starts)->copy()->addHour()->format('H:i:s'),
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ], $attributes))->save();

        return $class->fresh();
    }
}
