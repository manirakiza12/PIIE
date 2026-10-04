<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\LiveClass;
use App\Models\LiveClassNotification;
use App\Models\User;
use App\Support\CourseOffering\SystemTesterAccess;
use App\Support\LiveClasses\LiveClassAccessService;
use App\Support\LiveClasses\LiveClassNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Two real defects and one new workflow, proven together.
 *
 * 1. GET /teacher/live-classes returned a branded 500 for every lecturer.
 *    LiveClassAccessService::lecturerVisibleClassIdsQuery() returned a builder
 *    whose select was never constrained, so it compiled `select * from
 *    live_classes ...` and was fed straight back into `whereIn('id', ...)`.
 *    MySQL/MariaDB rejects a multi-column IN operand (SQLSTATE 21000 / 1241)
 *    and the whole request died inside paginate()'s count query. Tenant admins
 *    never saw it because their branch uses orWhereNotNull('course_offering_id')
 *    and never builds this sub-query.
 *
 *    IMPORTANT: SQLite accepts `IN (select *)`, so an ordinary request test
 *    passes with or without the fix. These tests therefore assert the SHAPE of
 *    the compiled SQL as well, which is what actually changed and which fails
 *    loudly on the bug.
 *
 * 2. Course-Offering Live Classes notified students, but only publish: a
 *    reschedule, a cancellation and a new recording were all silent, and
 *    nothing stopped a repeated publish re-notifying the same cohort.
 */
class LiveClassNotificationWorkflowTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    private int $otherSchool;

    private int $year;

    private int $period;

    private int $subject;

    private CourseOffering $offering;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->bootAdmissionsTestSchema();
        $this->liveClassSchema();
        if (! Schema::hasColumn('users', 'staff_status')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('staff_status')->nullable();
            });
        }

        $this->school = $this->makeSchool();
        $this->otherSchool = $this->makeSchool();

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
        $this->offering = $this->makeOffering($this->school, 'BBIT1103-2026-S1');

        $this->lecturer = $this->makeLecturer('Daniel Okello', $this->school, tester: true);
        $this->inForceAllocation($this->offering, $this->lecturer);
    }

    private function liveClassSchema(): void
    {
        Schema::create('academic_years', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label');
            $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
        });
        Schema::create('academic_periods', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id');
            $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence');
            $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id');
            $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id');
            $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft'); $t->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
            $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on');
            $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps();
        });
        Schema::create('course_registrations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable();
            $t->unsignedBigInteger('course_offering_id'); $t->string('status'); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->string('permission', 100); $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });
        foreach ([['staff_roles', ['name' => 'string', 'is_active' => 'boolean']],
            ['staff_role_permissions', ['staff_role_id' => 'unsignedBigInteger', 'permission' => 'string']],
            ['user_staff_roles', ['staff_role_id' => 'unsignedBigInteger']]] as [$table, $cols]) {
            Schema::create($table, function (Blueprint $t) use ($table, $cols): void {
                $t->id(); $t->unsignedBigInteger('school_id');
                foreach ($cols as $name => $kind) {
                    $kind === 'boolean' ? $t->boolean($name)->default(true) : $t->{$kind}($name)->nullable();
                }
                if ($table !== 'staff_role_permissions') {
                    $t->unsignedBigInteger('user_id')->nullable();
                }
                $t->timestamps();
            });
        }
        // Mirrors the real piie_main live_classes definition.
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
            $t->boolean('is_published')->default(false);
            $t->boolean('attendance_enabled')->default(false);
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
        Schema::create('live_class_notifications', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->string('type', 30); $t->unsignedInteger('recipient_count')->default(0);
            $t->timestamp('sent_at'); $t->timestamps();
            // The real table has this UNIQUE index and it is what suppresses
            // duplicate announcements. SQLite needs it declared to behave the
            // same way, so the dedup tests are meaningful here too.
            $t->unique(['live_class_id', 'type'], 'live_class_notifications_live_class_id_type_unique');
        });
        // user_notifications is provided by the shared admissions schema with
        // the real production shape (type varchar(40), title varchar(191),
        // url varchar(500)), so it is reused rather than redeclared.
        // The lecturer index reaches getAllowedSubjects(), which probes this
        // table to decide whether a school assigns lecturers to Programmes.
        Schema::create('teacher_programme_assignments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('programme_id')->nullable(); $t->timestamps();
        });
        // Written by join() to record participation. Participation is evidence
        // only; it must never become official Attendance.
        Schema::create('live_class_attendances', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->unsignedBigInteger('user_id'); $t->unsignedSmallInteger('role_id')->nullable();
            $t->timestamp('joined_at')->nullable(); $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps();
        });
    }

    private function makeOffering(int $school, string $reference): CourseOffering
    {
        $service = app(\App\Support\CourseOffering\CourseOfferingService::class);
        $offering = $service->createDraft($school, $this->subject, $this->year, $this->period, $reference);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'in_progress']);

        return $offering->fresh();
    }

    private function makeLecturer(string $name, int $school, bool $tester = false): User
    {
        $lecturer = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 3, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active',
            'password' => Hash::make('Lecturer#2026'),
        ]);
        DB::table('user_permissions')->insert([
            'school_id' => $school, 'user_id' => $lecturer->id,
            'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($tester) {
            DB::table('user_permissions')->insert([
                'school_id' => $school, 'user_id' => $lecturer->id,
                'permission' => SystemTesterAccess::PERMISSION, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $lecturer;
    }

    private function inForceAllocation(CourseOffering $offering, User $lecturer, ?string $startsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $offering->school_id, 'course_offering_id' => $offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => $startsOn ?? now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStudent(string $name, int $school, ?CourseOffering $offering, string $status = 'confirmed'): User
    {
        $student = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 7, 'school_id' => $school, 'account_status' => 'active',
            'password' => Hash::make('Student#2026'),
        ]);
        if ($offering) {
            DB::table('course_registrations')->insert([
                'school_id' => $school, 'student_id' => $student->id, 'subject_id' => $offering->subject_id,
                'course_offering_id' => $offering->id, 'status' => $status,
            ]);
        }

        return $student;
    }

    private function makeClass(array $overrides = []): LiveClass
    {
        $class = new LiveClass();
        $class->forceFill(array_merge([
            'school_id' => $this->school,
            'title' => 'Introduction Live Class',
            'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->lecturer->id,
            'platform' => 'jitsi',
            'meeting_url' => 'https://meet.example.test/abc-secret-room',
            'meeting_id' => 'abc-secret-room',
            'meeting_password' => 'topsecret',
            'timezone' => 'UTC',
            'scheduled_at' => now()->addDays(3)->setTime(10, 0),
            'ends_at' => now()->addDays(3)->setTime(11, 0),
            'start_date' => now()->addDays(3)->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => LiveClass::STATUS_SCHEDULED,
            'is_published' => true,
        ], $overrides))->save();

        return $class->fresh();
    }

    private function notifiedStudentIds(?string $type = null): array
    {
        return DB::table('user_notifications')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ============ PART A / B: the real 500 ============

    /**
     * The shape assertion is the load-bearing one. `IN (select *)` is legal in
     * SQLite, so only inspecting the compiled SQL catches this regression.
     */
    public function test_the_lecturer_visibility_subquery_selects_exactly_one_column(): void
    {
        $query = app(LiveClassAccessService::class)
            ->lecturerVisibleClassIdsQuery($this->lecturer, $this->school);

        $this->assertSame(
            ['live_classes.id'],
            $query->getQuery()->columns,
            'the builder must project a single column to stay a legal IN operand'
        );
        $this->assertStringNotContainsString(
            'in (select *',
            $query->toSql(),
            'a wildcard projection here is what produced SQLSTATE 21000 in production'
        );
    }

    public function test_the_lecturer_live_classes_index_loads(): void
    {
        $this->makeClass();

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index'))
            ->assertOk();
    }

    public function test_the_offering_scoped_lecturer_pages_all_load(): void
    {
        $this->makeClass();

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.show', $this->offering->id))
            ->assertOk()
            ->assertSee('Introduction Live Class');

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('Business Mathematics');
    }

    public function test_a_future_dated_lecturer_without_the_grant_is_still_blocked(): void
    {
        $other = $this->makeLecturer('Future Lecturer', $this->school, tester: false);
        $this->inForceAllocation($this->offering, $other, now()->addMonth()->startOfMonth()->toDateString());

        $this->actingAs($other)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertForbidden();
    }

    public function test_a_pre_start_tester_lecturer_can_create(): void
    {
        $future = $this->makeOffering($this->school, 'BBIT1103-2026-S2');
        DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $this->offering->id)->delete();
        $this->inForceAllocation($future, $this->lecturer, now()->addMonth()->startOfMonth()->toDateString());
        DB::table('audit_logs')->insert([
            'school_id' => $this->school, 'action' => 'COURSE_OFFERING_EARLY_START',
            'event_type' => 'COURSE_OFFERING_EARLY_START', 'module' => 'Course Offerings',
            'description' => 'early start', 'record_type' => CourseOffering::class,
            'record_id' => $future->id, 'created_at' => now(),
        ]);
        $this->offering = $future->fresh();

        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $future->id),
            [
                'title' => 'Pre-start tester class', 'platform' => 'jitsi', 'type' => 'custom',
                'start_date' => now()->addWeek()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
                'meeting_url' => 'https://meet.example.test/room', 'is_published' => '1',
            ]
        )->assertSessionHasNoErrors();

        $this->assertSame(1, LiveClass::where('course_offering_id', $future->id)->count());
    }

    // ============ PART C: the events are actually WIRED, not just callable ============

    /**
     * Calling LiveClassNotifier directly proves the announcement is correct but
     * not that anything triggers it. These two go through the single service
     * both the lecturer and the admin update paths use, so a lecturer editing
     * the time in the browser produces a reschedule notice without any
     * controller-specific code.
     */
    public function test_saving_a_changed_time_through_the_service_notifies_a_reschedule(): void
    {
        $liveClass = $this->makeClass();
        $student = $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        LiveClassNotifier::announcePublished($liveClass);
        DB::table('user_notifications')->delete();

        app(\App\Support\LiveClasses\LiveClassService::class)->updateOfferingMeeting(
            $this->lecturer,
            (int) $liveClass->id,
            ['scheduled_at' => now()->addDays(6)->setTime(15, 45)->toDateTimeString(),
                'ends_at' => now()->addDays(6)->setTime(16, 45)->toDateTimeString()]
        );

        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_rescheduled')->count());
        $this->assertStringContainsString(
            now()->addDays(6)->setTime(15, 45)->format('g:i A'),
            DB::table('user_notifications')->where('type', 'live_class_rescheduled')->value('body')
        );
        $this->assertContains((int) $student->id, $this->notifiedStudentIds('live_class_rescheduled'));
    }

    public function test_resaving_an_unchanged_time_notifies_nothing(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $service = app(\App\Support\LiveClasses\LiveClassService::class);
        $service->updateOfferingMeeting($this->lecturer, (int) $liveClass->id, [
            'title' => $liveClass->title,
            'scheduled_at' => $liveClass->scheduled_at->toDateTimeString(),
            'ends_at' => $liveClass->ends_at->toDateTimeString(),
        ]);

        $this->assertSame(0, DB::table('user_notifications')->where('type', 'live_class_rescheduled')->count(),
            'a lecturer clicking Save on an unchanged form must not spam a cohort');
    }

    public function test_adding_a_recording_through_the_service_notifies_once(): void
    {
        $liveClass = $this->makeClass(['recording_url' => null]);
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $service = app(\App\Support\LiveClasses\LiveClassService::class);
        $service->updateOfferingMeeting($this->lecturer, (int) $liveClass->id, [
            'recording_url' => 'https://videos.example.test/rec-1',
        ]);
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_recording')->count());

        $service->updateOfferingMeeting($this->lecturer, (int) $liveClass->id, [
            'recording_url' => 'https://videos.example.test/rec-1',
        ]);
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_recording')->count(),
            'saving the same recording twice notifies once');
    }

    public function test_a_lecturer_update_that_fails_never_notifies(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        // A conflicting subject is refused by the service; nothing is announced.
        try {
            app(\App\Support\LiveClasses\LiveClassService::class)->updateOfferingMeeting(
                $this->lecturer,
                (int) $liveClass->id,
                ['subject_id' => 999999]
            );
            $this->fail('the service should have refused a subject outside the Offering');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('user_notifications')->count());
        $this->assertSame(0, DB::table('live_class_notifications')->count());
    }

    // ============ PART F: recipient authority and tenant isolation ============

    public function test_only_confirmed_registrations_of_this_offering_are_notified(): void
    {
        $liveClass = $this->makeClass();

        $confirmed = $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        $pending = $this->makeStudent('Pending Student', $this->school, $this->offering, 'pending');
        $dropped = $this->makeStudent('Dropped Student', $this->school, $this->offering, 'dropped');
        $otherOffering = $this->makeStudent('Other Offering Student', $this->school,
            $this->makeOffering($this->school, 'OTHER-1'));
        $otherTenant = $this->makeStudent('Foreign Student', $this->otherSchool, null);
        DB::table('course_registrations')->insert([
            'school_id' => $this->otherSchool, 'student_id' => $otherTenant->id,
            'subject_id' => $this->subject, 'course_offering_id' => $this->offering->id, 'status' => 'confirmed',
        ]);
        $unregistered = $this->makeStudent('Random Student', $this->school, null);

        $count = LiveClassNotifier::announcePublished($liveClass);

        $this->assertSame(1, $count);
        $this->assertSame([(int) $confirmed->id], $this->notifiedStudentIds());
        foreach ([$pending, $dropped, $otherOffering, $otherTenant, $unregistered] as $excluded) {
            $this->assertNotContains(
                (int) $excluded->id,
                $this->notifiedStudentIds(),
                "{$excluded->name} must not be notified"
            );
        }
    }

    public function test_a_lecturer_with_a_student_role_elsewhere_is_not_notified_as_a_student(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        LiveClassNotifier::announcePublished($liveClass);

        $this->assertNotContains(
            (int) $this->lecturer->id,
            $this->notifiedStudentIds(),
            'the lecturer facilitates the class; that is not a student registration'
        );
    }

    public function test_a_draft_class_notifies_nobody(): void
    {
        $liveClass = $this->makeClass(['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $this->assertSame(0, LiveClassNotifier::announcePublished($liveClass));
        $this->assertSame([], $this->notifiedStudentIds());
    }

    // ============ PART C: the four lifecycle events ============

    public function test_publish_announces_with_course_unit_title_date_and_action_url(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $this->assertSame(1, LiveClassNotifier::announcePublished($liveClass));

        $row = DB::table('user_notifications')->first();
        $this->assertSame('Business Mathematics live class scheduled', $row->title);
        $this->assertStringContainsString('Introduction Live Class', $row->body);
        $this->assertStringContainsString($liveClass->scheduled_at->format('j F Y'), $row->body);
        $this->assertStringContainsString($liveClass->scheduled_at->format('g:i A'), $row->body);
        $this->assertSame(route('student.live_classes.show', $liveClass->id), $row->url);
        $this->assertSame('live_class_published', $row->type);
    }

    public function test_reschedule_announces_the_new_time_and_is_not_a_second_new_class(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        LiveClassNotifier::announcePublished($liveClass);

        $liveClass->forceFill([
            'scheduled_at' => now()->addDays(5)->setTime(14, 30),
            'ends_at' => now()->addDays(5)->setTime(15, 30),
        ])->save();
        $liveClass = $liveClass->fresh();

        $this->assertSame(1, LiveClassNotifier::announceRescheduled($liveClass));

        $row = DB::table('user_notifications')->where('type', 'live_class_rescheduled')->first();
        $this->assertSame('Business Mathematics live class rescheduled', $row->title);
        $this->assertStringContainsString(
            $liveClass->scheduled_at->format('j F Y').' at '.$liveClass->scheduled_at->format('g:i A'),
            $row->body
        );
        $this->assertSame(
            1,
            DB::table('user_notifications')->where('type', 'live_class_published')->count(),
            'a reschedule must not read as a brand new class'
        );
    }

    public function test_cancellation_reaches_the_students_who_were_told_about_the_class(): void
    {
        $liveClass = $this->makeClass();
        $student = $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        LiveClassNotifier::announcePublished($liveClass);

        $liveClass->forceFill(['status' => LiveClass::STATUS_CANCELLED])->save();
        $liveClass = $liveClass->fresh();

        // This is the trap: eligibleStudentUserIds() deliberately returns
        // nothing once a class is cancelled, so resolving recipients through it
        // here would notify nobody and the students would never hear.
        $this->assertSame(1, LiveClassNotifier::announceCancelled($liveClass));
        $this->assertContains((int) $student->id, $this->notifiedStudentIds('live_class_cancelled'));

        $row = DB::table('user_notifications')->where('type', 'live_class_cancelled')->first();
        $this->assertSame('Business Mathematics live class cancelled', $row->title);
        $this->assertStringContainsString('Introduction Live Class', $row->body);
    }

    public function test_a_recording_announcement_fires_once_for_the_event_not_once_per_url(): void
    {
        $liveClass = $this->makeClass(['recording_url' => null]);
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $liveClass->forceFill(['recording_url' => 'https://videos.example.test/rec-1'])->save();
        $this->assertSame(1, LiveClassNotifier::announceRecording($liveClass->fresh()));
        $this->assertSame(0, LiveClassNotifier::announceRecording($liveClass->fresh()),
            're-saving the same recording must not notify again');

        // The dedup key is the EVENT, not the file. It used to be derived from
        // the URL, so correcting a recording link counted as a brand new
        // recording: a student who had already watched it was told to watch it
        // again, and a single recording produced several notifications over its
        // life. The page they land on always shows the current link, so nothing
        // is lost by announcing the availability once.
        $liveClass->forceFill(['recording_url' => 'https://videos.example.test/rec-2'])->save();
        $this->assertSame(0, LiveClassNotifier::announceRecording($liveClass->fresh()),
            'a corrected link is not a new recording for the student');

        $rows = DB::table('user_notifications')->where('type', 'live_class_recording')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Recording available - Business Mathematics', $rows->first()->title);
    }

    // ============ PART G: duplicate protection ============

    public function test_repeated_publish_and_cancel_notify_exactly_once(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $this->assertSame(1, LiveClassNotifier::announcePublished($liveClass));
        $this->assertSame(0, LiveClassNotifier::announcePublished($liveClass));
        $this->assertSame(0, LiveClassNotifier::announcePublished($liveClass));

        $liveClass->forceFill(['status' => LiveClass::STATUS_CANCELLED])->save();
        $this->assertSame(1, LiveClassNotifier::announceCancelled($liveClass->fresh()));
        $this->assertSame(0, LiveClassNotifier::announceCancelled($liveClass->fresh()));

        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_published')->count());
        $this->assertSame(1, DB::table('user_notifications')->where('type', 'live_class_cancelled')->count());
    }

    public function test_the_deduplication_key_is_enforced_by_a_unique_index(): void
    {
        $liveClass = $this->makeClass();

        LiveClassNotification::create([
            'school_id' => $this->school, 'live_class_id' => $liveClass->id,
            'type' => LiveClassNotification::TYPE_PUBLISHED, 'recipient_count' => 3, 'sent_at' => now(),
        ]);

        // If the unique index were missing, a duplicate would insert silently
        // and every student would be notified repeatedly.
        $this->expectException(QueryException::class);
        LiveClassNotification::create([
            'school_id' => $this->school, 'live_class_id' => $liveClass->id,
            'type' => LiveClassNotification::TYPE_PUBLISHED, 'recipient_count' => 3, 'sent_at' => now(),
        ]);
    }

    public function test_a_class_with_no_students_is_never_marked_as_announced(): void
    {
        $liveClass = $this->makeClass();

        $this->assertSame(0, LiveClassNotifier::announcePublished($liveClass));
        $this->assertSame(0, DB::table('live_class_notifications')->count(),
            'suppression must record only events that actually fired');

        $student = $this->makeStudent('Late Confirm', $this->school, $this->offering);
        $this->assertSame(1, LiveClassNotifier::announcePublished($liveClass),
            'a later genuine publish still reaches a real audience');
        $this->assertSame([(int) $student->id], $this->notifiedStudentIds());
    }

    public function test_every_dedup_key_fits_the_real_thirty_character_column(): void
    {
        $keys = [
            LiveClassNotification::TYPE_PUBLISHED,
            LiveClassNotification::TYPE_CANCELLED,
            LiveClassNotification::TYPE_REMINDER_24H,
            LiveClassNotification::TYPE_REMINDER_1H,
            LiveClassNotification::rescheduledKey(now()),
            LiveClassNotification::recordingKey('https://videos.example.test/rec-1'),
        ];

        foreach ($keys as $key) {
            $this->assertNotNull($key, 'a key must be derivable');
            $this->assertLessThanOrEqual(
                30,
                strlen((string) $key),
                "dedup key '{$key}' would be truncated by live_class_notifications.type varchar(30)"
            );
        }
    }

    // ============ PART E: the action URL stays authorised ============

    public function test_the_notification_action_url_does_not_bypass_access_control(): void
    {
        // Running right now, so the only thing that can refuse the registered
        // student is authorization - not the 15-minute join window.
        $liveClass = $this->makeClass([
            'scheduled_at' => now(),
            'ends_at' => now()->addHour(),
            'start_date' => now()->toDateString(),
        ]);
        $insider = $this->makeStudent('Insider', $this->school, $this->offering);
        $outsider = $this->makeStudent('Outsider', $this->school, null);
        $pending = $this->makeStudent('Pending', $this->school, $this->offering, 'pending');
        $otherTenant = $this->makeStudent('Foreign', $this->otherSchool, null);

        LiveClassNotifier::announcePublished($liveClass);
        $url = DB::table('user_notifications')->value('url');
        // The CTA is the student's DETAIL page. It is deliberately not the join
        // endpoint: a "View Live Class" action that tried to join turned a valid
        // scheduled class into a join refusal whenever it was read before the
        // join window.
        $this->assertSame(route('student.live_classes.show', $liveClass->id), $url);

        // One identical URL for everyone; the destination re-checks access, so
        // holding the link confers nothing. A student of this institution who
        // is not registered is refused, and another tenant's student gets a 404
        // and never learns the class exists.
        $this->actingAs($outsider)->get($url)->assertNotFound();
        $this->actingAs($pending)->get($url)->assertNotFound();
        $this->actingAs($otherTenant)->get($url)->assertNotFound();

        $granted = $this->actingAs($insider)->get($url);
        $granted->assertOk();
        $this->assertSame(0, DB::table('live_class_attendances')->count(),
            'viewing never records participation');
    }

    public function test_no_provider_secret_ever_reaches_a_notification(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        LiveClassNotifier::announcePublished($liveClass);
        $liveClass->forceFill(['recording_url' => 'https://videos.example.test/rec-1'])->save();
        LiveClassNotifier::announceRecording($liveClass->fresh());
        $liveClass->forceFill(['status' => LiveClass::STATUS_CANCELLED])->save();
        LiveClassNotifier::announceCancelled($liveClass->fresh());

        $rows = DB::table('user_notifications')->get();
        $this->assertGreaterThanOrEqual(3, $rows->count());
        foreach ($rows as $row) {
            $haystack = $row->title.' '.$row->body.' '.$row->url;
            $this->assertStringNotContainsString($liveClass->meeting_url, $haystack);
            $this->assertStringNotContainsString($liveClass->meeting_id, $haystack);
            $this->assertStringNotContainsString($liveClass->meeting_password, $haystack);
            $this->assertStringNotContainsString('topsecret', $haystack);
            $this->assertStringNotContainsString('https://meet.example.test', $haystack);
        }
    }

    // ============ no Attendance mutation ============

    public function test_no_attendance_is_written_by_any_live_class_notification(): void
    {
        Schema::create('course_offering_attendance_sessions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('course_offering_id'); $t->string('status');
            $t->unsignedBigInteger('live_class_id')->nullable(); $t->timestamps();
        });
        $liveClass = $this->makeClass();
        $student = $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        LiveClassNotifier::announcePublished($liveClass);
        $liveClass->forceFill(['status' => LiveClass::STATUS_CANCELLED])->save();
        LiveClassNotifier::announceCancelled($liveClass->fresh());

        $this->assertSame(0, DB::table('course_offering_attendance_sessions')->count(),
            'participation is evidence; official Attendance stays lecturer-controlled');
    }

    // ============ Part H: lecturer feedback ============

    public function test_creating_a_published_class_confirms_how_many_students_were_notified(): void
    {
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            [
                'title' => 'Introduction Live Class', 'platform' => 'jitsi', 'type' => 'custom',
                'start_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
                'meeting_url' => 'https://meet.example.test/room', 'is_published' => '1',
            ]
        );

        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString(
            '1 registered student was notified',
            (string) $response->getSession()->get('success')
        );
        $this->assertStringNotContainsString(
            'notification id',
            strtolower((string) $response->getSession()->get('success'))
        );
    }

    public function test_a_draft_creation_tells_the_lecturer_nothing_was_sent(): void
    {
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            [
                'title' => 'Draft class', 'platform' => 'jitsi', 'type' => 'custom',
                'start_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
                'meeting_url' => 'https://meet.example.test/room',
            ]
        );

        $response->assertSessionHasNoErrors();
        $this->assertStringContainsString('draft', strtolower((string) $response->getSession()->get('success')));
        $this->assertSame([], $this->notifiedStudentIds(), 'a draft reaches no students');
    }

    public function test_the_reminder_command_still_runs_against_the_unchanged_ledger(): void
    {
        $liveClass = $this->makeClass();
        $this->makeStudent('Confirmed Student', $this->school, $this->offering);

        // The 24h window for a class three days out is not due yet, so this must
        // be a clean no-op rather than an error, proving the pre-existing
        // reminder path still works against the ledger the new events share.
        $this->artisan('live-classes:send-reminders')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('live_class_notifications')
            ->where('type', LiveClassNotification::TYPE_REMINDER_24H)->count());
    }

    public function test_the_reminder_window_does_fire_for_a_class_24_hours_out(): void
    {
        $liveClass = $this->makeClass([
            'scheduled_at' => now()->addHours(24),
            'ends_at' => now()->addHours(25),
            'start_date' => now()->addHours(24)->toDateString(),
        ]);
        $student = $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        Mail::fake();

        $this->artisan('live-classes:send-reminders')->assertExitCode(0);

        $this->assertSame(1, DB::table('live_class_notifications')
            ->where('live_class_id', $liveClass->id)
            ->where('type', LiveClassNotification::TYPE_REMINDER_24H)->count());
        $this->assertContains((int) $student->id, $this->notifiedStudentIds('live_class_reminder'));
    }
}
