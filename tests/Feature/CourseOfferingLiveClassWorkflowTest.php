<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\CourseOffering\SystemTesterAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The real Course Offering -> Live Class workflow, end to end.
 *
 * The gap this covers is narrow and specific. The offering-scoped Live Class
 * endpoints already existed and were already lecturer-aware
 * (LiveClassController::createForOffering / storeForOffering both call
 * LiveClassAccessService::canLecturerCreateForOffering), but they were mounted
 * under middleware('auth','admin','rbac') only. A Lecturer in the teacher
 * portal therefore had no route to create an Offering-backed class at all: the
 * only creation form they could reach was the legacy Class/Section one, which
 * cannot express Offering context and so would never attach course_offering_id.
 *
 * These tests prove the lecturer route now works, that it is gated by the SAME
 * shared SystemTesterAccess definition used everywhere else, and that a normal
 * lecturer without the grant stays date-gated.
 */
class CourseOfferingLiveClassWorkflowTest extends TestCase
{
    use AdmissionsTestHelper;

    private const TENANT = 1;

    private int $school;

    private int $otherSchool;

    private int $year;

    private int $period;

    private int $subject;

    private CourseOffering $offering;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        // The shared admissions schema already provides schools, users,
        // academic years/periods, subjects, programmes, course_offerings and
        // audit_logs. Only the Live Class tables are added on top, so nothing
        // is declared twice and the two halves cannot disagree.
        $this->bootAdmissionsTestSchema();
        $this->liveClassSchema();
        // The shared admissions users table has no staff_status column, and the
        // tester authority reads it. Added here rather than weakening the check.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'staff_status')) {
            \Illuminate\Support\Facades\Schema::table('users', function (Blueprint $t): void {
                $t->string('staff_status')->nullable();
            });
        }

        $this->school = $this->makeSchool();
        $this->otherSchool = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);

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
        $this->offering = $this->makeOffering('in_progress', 'BBIT1103-2026-S1');
    }

    /** Only the Live Class tables: the rest comes from the shared schema. */
    private function liveClassSchema(): void
    {
        // Mirrors the real piie_main live_classes definition (read-only
        // inspection), including the enums, so the fixture cannot drift from
        // the production shape.
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
        Schema::create('live_class_attendances', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->unsignedBigInteger('user_id'); $t->unsignedSmallInteger('role_id')->nullable();
            $t->timestamp('joined_at')->nullable(); $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps();
        });
        Schema::create('live_class_materials', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->string('type', 20)->default('link'); $t->string('category', 20)->default('resource');
            $t->string('title'); $t->text('description')->nullable(); $t->string('link_url')->nullable();
            $t->string('file_path')->nullable(); $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable(); $t->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id');
            $t->unsignedBigInteger('user_id'); $t->string('role', 32); $t->date('starts_on');
            $t->date('ends_on')->nullable(); $t->string('status', 16)->default('planned'); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->string('permission', 100); $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });
        // PermissionService::grantTablesExist() requires all four grant tables
        // before it will read a single grant, so the tester permission cannot
        // resolve unless the custom-role half exists too.
        Schema::create('staff_roles', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('name', 100);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('staff_role_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('staff_role_id'); $t->string('permission', 100); $t->timestamps();
        });
        Schema::create('user_staff_roles', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('staff_role_id'); $t->timestamps();
        });
        // The shared admissions schema has no academic calendar of its own.
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
            $t->string('reference', 50)->nullable(); $t->string('status', 20)->default('draft');
            $t->timestamps();
        });
        Schema::create('course_registrations', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable();
            $t->unsignedBigInteger('course_offering_id'); $t->string('status'); $t->timestamps();
        });
    }

    private function makeOffering(string $status, string $reference, ?int $school = null): CourseOffering
    {
        $school ??= $this->school;
        $year = $this->year;
        $period = $this->period;
        if ($school !== $this->school) {
            $year = (int) DB::table('academic_years')->insertGetId([
                'school_id' => $school, 'label' => '2026/2027',
                'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
            ]);
            $period = (int) DB::table('academic_periods')->insertGetId([
                'school_id' => $school, 'academic_year_id' => $year, 'type' => 'semester',
                'label' => 'Semester 1', 'sequence' => 1,
                'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
            ]);
            $subject = (int) DB::table('subjects')->insertGetId([
                'school_id' => $school, 'name' => 'Other Unit', 'code' => 'OTHER-1',
            ]);
        } else {
            $subject = $this->subject;
        }

        // CourseOffering is created only through its service, by design; this
        // fixture goes through the same governed path the application uses.
        $service = app(\App\Support\CourseOffering\CourseOfferingService::class);
        $offering = $service->createDraft($school, $subject, $year, $period, $reference);

        if ($status !== 'draft') {
            DB::table('course_offerings')->where('id', $offering->id)->update(['status' => $status]);
        }

        return $offering->fresh();
    }

    /** Daniel-equivalent: a Lecturer with an allocation whose start is in the future. */
    private function lecturer(bool $tester = false, string $name = 'Daniel Okello', int $school = null): User
    {
        $school ??= $this->school;
        $lecturer = User::factory()->create([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 3, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active',
            'password' => Hash::make('Lecturer#2026'),
        ]);
        // live_classes.create is the base capability a Lecturer needs.
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

    /**
     * A forward-dated ACTIVE allocation, plus the governed early-start audit that
     * makes the pre-start testing situation legitimate.
     */
    private function preStartAllocation(CourseOffering $offering, User $lecturer, bool $governedEarlyStart = true): CourseOfferingLecturerAllocation
    {
        $id = DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $offering->school_id, 'course_offering_id' => $offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->addMonth()->startOfMonth()->toDateString(),
            'ends_on' => now()->addMonths(6)->endOfMonth()->toDateString(),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($governedEarlyStart) {
            DB::table('audit_logs')->insert([
                'school_id' => $offering->school_id, 'action' => 'COURSE_OFFERING_EARLY_START',
                'event_type' => 'COURSE_OFFERING_EARLY_START', 'module' => 'Course Offerings',
                'description' => 'early start', 'record_type' => CourseOffering::class,
                'record_id' => $offering->id, 'created_at' => now(),
            ]);
        }

        return CourseOfferingLecturerAllocation::find($id);
    }

    private function makeStudent(string $name, int $school, CourseOffering $offering, string $status = 'confirmed'): User
    {
        $student = User::factory()->create([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 7, 'school_id' => $school, 'account_status' => 'active',
            'password' => Hash::make('Student#2026'),
        ]);
        DB::table('course_registrations')->insert([
            'school_id' => $school, 'student_id' => $student->id,
            'subject_id' => $offering->subject_id, 'course_offering_id' => $offering->id,
            'status' => $status,
        ]);

        return $student;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Business Mathematics revision',
            'description' => 'Weekly problem class',
            'type' => 'custom',
            'platform' => 'jitsi',
            'start_date' => now()->addWeek()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ];
    }

    // 1. LECTURER_CREATE: the offering-scoped create form opens for a Lecturer.
    public function test_a_lecturer_can_open_the_offering_scoped_live_class_form(): void
    {
        // An in-force allocation is the normal lecturer case; the pre-start
        // tester case is covered separately below.
        $lecturer = $this->lecturer();
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('Business Mathematics');
    }

    /**
     * The Google Connect control must exist where the decision is made.
     *
     * It previously lived only on the generic Live Classes index, so a lecturer
     * who had just been told "Connect your Google Account before scheduling a
     * Google Meet class" — while standing on this very form — had to go and find
     * the control somewhere else entirely.
     *
     * The Offering context rides along in the return path, so consent brings the
     * lecturer back to THIS form for THIS Offering instead of the class list.
     */
    public function test_the_offering_create_page_carries_the_connect_control_and_the_offering_return_path(): void
    {
        $lecturer = $this->lecturer();
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The form exactly as it comes back after a refused Google Meet attempt:
        // old() still holds google_meet, which is what puts the panel on screen.
        $response = $this->actingAs($lecturer)
            ->withSession(['_old_input' => ['platform' => 'google_meet']])
            ->from(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk();
        $html = $response->getContent() ?: '';

        $this->assertStringContainsString('data-google-connection', $html);
        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString(route('google.auth.connect'), $html);

        // The Offering context is preserved across the OAuth round trip. Asserted
        // as a PATH, not a full url: the return value is restricted to a same-site
        // path because that restriction is the open-redirect defence, so an
        // absolute url here would (correctly) be refused by the controller.
        $expected = (string) parse_url(
            route('teacher.course_offerings.live_classes.create', $this->offering->id),
            PHP_URL_PATH
        );
        $this->assertStringStartsWith('/', $expected);
        $this->assertStringContainsString('return='.rawurlencode($expected), $html);
    }

    /** Jitsi and Zoom scheduling must not be cluttered with a Google panel. */
    public function test_the_offering_create_page_shows_no_google_panel_for_a_non_google_platform(): void
    {
        $lecturer = $this->lecturer();
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($lecturer)
            ->withSession(['_old_input' => ['platform' => 'jitsi']])
            ->from(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk();
        $this->assertStringNotContainsString('data-google-connection', $response->getContent() ?: '');
    }

    // 2. PRESTART_LECTURER_TESTER: the tester may create before the allocation start.
    public function test_a_pre_start_tester_lecturer_can_create_an_offering_live_class(): void
    {
        $lecturer = $this->lecturer(tester: true);
        $this->preStartAllocation($this->offering, $lecturer);

        // The allocation really is pre-start, so this proves the tester exception.
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse(
                DB::table('course_offering_lecturer_allocations')->where('user_id', $lecturer->id)->value('starts_on')
            )->isFuture(),
            'the allocation has not begun yet'
        );

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload()
        )->assertSessionHasNoErrors();

        $liveClass = LiveClass::where('course_offering_id', $this->offering->id)->first();
        $this->assertNotNull($liveClass, 'the Live Class was created and is Offering-backed');
        $this->assertSame('Business Mathematics revision', $liveClass->title);
        $this->assertSame($this->school, (int) $liveClass->school_id, 'tenant preserved');
        $this->assertSame((int) $lecturer->id, (int) $liveClass->teacher_id, 'the lecturer facilitates it');
    }

    // 3. NORMAL_LECTURER_DATE_GATE: without the grant, still refused.
    public function test_a_normal_lecturer_cannot_create_before_the_allocation_starts(): void
    {
        $lecturer = $this->lecturer(tester: false, name: 'Normal Lecturer');
        $this->preStartAllocation($this->offering, $lecturer);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertForbidden();

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload()
        )->assertForbidden();

        $this->assertSame(0, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    // 3b. The same normal lecturer is fine once the allocation is in force:
    //      the grant is not what unlocks it, the date is.
    public function test_a_normal_lecturer_can_create_once_the_allocation_is_in_force(): void
    {
        $lecturer = $this->lecturer(tester: false, name: 'In Force Lecturer');
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload()
        )->assertSessionHasNoErrors();

        $this->assertSame(1, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    // 4. A governed early start is required: a tester on a normally-started
    //    Offering is still date-gated.
    public function test_tester_access_requires_governed_early_start_evidence(): void
    {
        $lecturer = $this->lecturer(tester: true, name: 'No Early Start Tester');
        $this->preStartAllocation($this->offering, $lecturer, governedEarlyStart: false);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertForbidden();
    }

    // 5. Offering context is route-derived and cannot be spoofed.
    public function test_offering_context_cannot_be_submitted_by_the_client(): void
    {
        $lecturer = $this->lecturer(tester: true, name: 'Spoof Lecturer');
        $this->preStartAllocation($this->offering, $lecturer);
        $foreign = $this->makeOffering('in_progress', 'OTHER-OFFERING');

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload([
                'course_offering_id' => $foreign->id,
                'school_id' => $this->school,
            ])
        )->assertSessionHasErrors();

        $this->assertSame(0, LiveClass::where('course_offering_id', $foreign->id)->count(),
            'nothing was attached to the spoofed Offering');
    }

    // 6. CROSS_TENANT_BLOCKED.
    public function test_a_cross_tenant_lecturer_is_refused(): void
    {
        $foreign = $this->lecturer(name: 'Foreign Lecturer', school: $this->otherSchool);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->otherSchool, 'course_offering_id' => $this->offering->id,
            'user_id' => $foreign->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($foreign)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertNotFound();

        $this->assertSame(0, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    // 7. CROSS_OFFERING_BLOCKED: a lecturer of another Offering cannot add to this one.
    public function test_a_lecturer_of_another_offering_cannot_create_for_this_one(): void
    {
        $other = $this->makeOffering('in_progress', 'SECOND-OFFERING');
        $stranger = $this->lecturer(tester: true, name: 'Other Offering Lecturer');
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $other->id,
            'user_id' => $stranger->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($stranger)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertForbidden();

        $this->assertSame(0, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    // 8. STUDENT_ACCESS_AUTHORITY: only a CONFIRMED registration opens the class.
    public function test_only_a_confirmed_registration_grants_a_student_access(): void
    {
        $lecturer = $this->lecturer(tester: true, name: 'Class Owner');
        $this->preStartAllocation($this->offering, $lecturer);
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id), $this->payload()
        )->assertSessionHasNoErrors();
        $liveClass = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->actingAs($lecturer)->post(route('teacher.live_classes.publish', $liveClass->id))
            ->assertRedirect();
        $liveClass->refresh();
        $this->assertTrue((bool) $liveClass->is_published, 'a student can only see a published class');

        $confirmed = $this->makeStudent('Confirmed Student', $this->school, $this->offering);
        $pending = $this->makeStudent('Pending Student', $this->school, $this->offering, 'pending');
        $dropped = $this->makeStudent('Dropped Student', $this->school, $this->offering, 'dropped');
        $otherOffering = $this->makeStudent('Other Offering Student', $this->school,
            $this->makeOffering('in_progress', 'SECOND-OFFERING'));
        $otherTenant = $this->makeStudent('Foreign Student', $this->otherSchool,
            $this->makeOffering('in_progress', 'FOREIGN-1', $this->otherSchool));
        $random = User::factory()->create([
            'name' => 'Random Student', 'email' => 'random.student@example.test',
            'role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active',
        ]);

        $this->actingAs($confirmed)->get(route('live_classes.materials.access', [$liveClass->id, 1]))
            ->assertNotFound(); // no material exists, but not a 403 on authorization

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $this->assertTrue($access->canStudentViewClass($confirmed, $liveClass),
            'a confirmed registration grants access');
        $this->assertFalse($access->canStudentViewClass($pending, $liveClass),
            'a registration that is not yet confirmed does not');
        $this->assertFalse($access->canStudentViewClass($dropped, $liveClass),
            'a dropped registration does not');
        $this->assertFalse($access->canStudentViewClass($otherOffering, $liveClass),
            'a confirmed registration for a different Offering does not');
        $this->assertFalse($access->canStudentViewClass($random, $liveClass),
            'an unregistered student does not');
        $this->assertFalse($access->canStudentViewClass($otherTenant, $liveClass),
            'a cross-tenant student does not');
    }

    // 9. LECTURER_LIFECYCLE: publish and cancel follow the existing states.
    public function test_the_existing_live_class_lifecycle_applies_to_an_offering_class(): void
    {
        $this->assertSame('draft', LiveClass::STATUS_DRAFT);
        $this->assertSame('scheduled', LiveClass::STATUS_SCHEDULED);
        $this->assertSame('live', LiveClass::STATUS_LIVE);
        $this->assertSame('ended', LiveClass::STATUS_ENDED);
        $this->assertSame('cancelled', LiveClass::STATUS_CANCELLED);

        $lecturer = $this->lecturer(tester: true, name: 'Lifecycle Lecturer');
        $this->preStartAllocation($this->offering, $lecturer);
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id), $this->payload()
        )->assertSessionHasNoErrors();
        $liveClass = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();

        $this->assertSame(LiveClass::STATUS_DRAFT, $liveClass->status, 'it starts as a draft');
        $this->assertFalse((bool) $liveClass->is_published, 'a draft is not published');

        // Publish
        $this->actingAs($lecturer)
            ->post(route('teacher.live_classes.publish', $liveClass->id))
            ->assertRedirect();
        $liveClass->refresh();
        $this->assertTrue((bool) $liveClass->is_published, 'it can be published');
        $this->assertNotSame(LiveClass::STATUS_CANCELLED, $liveClass->status);

        // Cancel
        $this->actingAs($lecturer)
            ->post(route('teacher.live_classes.cancel', $liveClass->id))
            ->assertRedirect();
        $this->assertSame(LiveClass::STATUS_CANCELLED, $liveClass->fresh()->status);
    }

    // 10. LECTURER_EDIT: the class remains manageable through the existing route.
    public function test_a_lecturer_can_reach_the_class_management_screen(): void
    {
        $lecturer = $this->lecturer(tester: true, name: 'Editing Lecturer');
        $this->preStartAllocation($this->offering, $lecturer);
        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id), $this->payload()
        )->assertSessionHasNoErrors();
        $liveClass = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();

        $this->actingAs($lecturer)
            ->get(route('teacher.live_classes.show', $liveClass->id))
            ->assertOk()
            ->assertSee('Business Mathematics revision');
    }

    // 11. The Offering workspace surfaces the creation entry point with context.
    public function test_the_offering_workspace_offers_the_creation_entry_point(): void
    {
        $lecturer = $this->lecturer(tester: true, name: 'Workspace Lecturer');
        $this->preStartAllocation($this->offering, $lecturer);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.show', $this->offering->id))
            ->assertOk()
            ->assertSee('Schedule a Live Class')
            ->assertSee(route('teacher.course_offerings.live_classes.create', $this->offering->id), false)
            ->assertSee('2026/2027')
            ->assertSee('Semester 1')
            ->assertSee('In Progress')
            ->assertSee('Primary Lecturer');
    }

    // 12. A Lecturer with no allocation at all reaches nothing.
    public function test_a_lecturer_without_an_allocation_cannot_create(): void
    {
        $stranger = $this->lecturer(tester: true, name: 'Unallocated Lecturer');

        $this->actingAs($stranger)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertForbidden();
    }
}
