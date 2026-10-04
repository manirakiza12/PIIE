<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\School;
use App\Models\User;
use App\Support\CourseOffering\SystemTesterAccess;
use App\Support\LiveClasses\LiveClassSchedulingContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The lecturer-facing Live Class workflow, as a Course Offering workflow.
 *
 * The defects this pins, all found by manual inspection of
 * /teacher/live-classes/create:
 *
 *  - The generic create screen handed an HEI lecturer a Class / Section /
 *    Programme / Session form, i.e. the K12 academic model, and let them build
 *    a class that contradicted the Course Offering it belonged to.
 *  - A raw lifecycle dropdown was pre-filled, so a brand-new future class
 *    rendered as "Live" the moment the form opened.
 *  - Meeting ID, Meeting Password, Recording URL and an "Attendance enabled"
 *    checkbox all sat in the scheduling form, exposing provider-internal and
 *    after-class concepts to a lecturer who had no use for them.
 *  - The timezone was a free-text box defaulting to the application value, and
 *    "Meet Now" could create a meeting belonging to no academic record at all.
 */
class LiveClassLecturerUxTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

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
        $this->schema();
        if (! Schema::hasColumn('users', 'staff_status')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('staff_status')->nullable();
            });
        }

        $this->school = $this->makeSchool();
        $this->markHigherEducation($this->school);

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
        $this->offering = $this->makeOffering('BBIT1103-2026-S1');
        $this->lecturer = $this->makeLecturer('Daniel Okello', tester: true);
        $this->allocate($this->offering, $this->lecturer);
    }

    private function schema(): void
    {
        // The shared admissions `schools` table has no institution_type or
        // timezone column, and TenantConfiguration reads both. They are added
        // here rather than stubbed out, so the HEI branch is exercised for real
        // instead of being forced.
        foreach (['school_type' => 'string', 'education_level' => 'string', 'timezone' => 'string'] as $column => $kind) {
            if (! Schema::hasColumn('schools', $column)) {
                Schema::table('schools', function (Blueprint $t) use ($column, $kind): void {
                    $t->{$kind}($column)->nullable();
                });
            }
        }

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
        Schema::create('curriculum_memberships', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id');
            $t->unsignedBigInteger('student_id')->nullable(); $t->unsignedBigInteger('programme_id')->nullable();
            $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::create('user_permissions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->string('permission', 100); $t->timestamps();
            $t->unique(['user_id', 'permission']);
        });
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
        Schema::create('teacher_programme_assignments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id');
            $t->unsignedBigInteger('programme_id')->nullable(); $t->timestamps();
        });
        Schema::create('live_class_notifications', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->string('type', 30); $t->unsignedInteger('recipient_count')->default(0);
            $t->timestamp('sent_at'); $t->timestamps();
            $t->unique(['live_class_id', 'type'], 'live_class_notifications_live_class_id_type_unique');
        });
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
    }

    private function markHigherEducation(int $school): void
    {
        DB::table('schools')->where('id', $school)->update([
            'school_type' => 'higher_ed',
            'education_level' => 'tertiary',
            'timezone' => 'Africa/Kampala',
        ]);
    }

    private function markK12(int $school): void
    {
        DB::table('schools')->where('id', $school)->update([
            'school_type' => 'k12',
            'education_level' => 'secondary',
            'timezone' => 'Africa/Kampala',
        ]);
    }

    private function makeOffering(string $reference, string $status = 'in_progress'): CourseOffering
    {
        $offering = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($this->school, $this->subject, $this->year, $this->period, $reference);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => $status]);

        return $offering->fresh();
    }

    private function makeLecturer(string $name, bool $tester = false, ?int $school = null): User
    {
        $school ??= $this->school;
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

    private function allocate(CourseOffering $offering, User $lecturer, ?string $startsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $offering->school_id, 'course_offering_id' => $offering->id,
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => $startsOn ?? now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registerStudent(string $name = 'Confirmed Student', string $status = 'confirmed'): User
    {
        $student = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'@example.test',
            'role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active',
            'password' => Hash::make('Student#2026'),
        ]);
        DB::table('course_registrations')->insert([
            'school_id' => $this->school, 'student_id' => $student->id, 'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id, 'status' => $status,
        ]);

        return $student;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Introduction Live Class',
            'platform' => 'jitsi',
            'action' => 'publish',
            'start_date' => now()->addDays(4)->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ];
    }

    // ══ §1 / §2 COURSE_OFFERING_PRIMARY ══

    public function test_the_hei_create_screen_asks_for_a_course_offering_not_a_class(): void
    {
        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.create'));

        $response->assertOk();
        $response->assertSee('BBIT1103-2026-S1');
        $response->assertSee('Business Mathematics');
        $response->assertSee('2026/2027');
        $response->assertSee('Semester 1');
        $response->assertSee('Primary Lecturer');
        $response->assertSee(route('teacher.course_offerings.live_classes.create', $this->offering->id), false);

        // The legacy K12 academic model must not be offered at all.
        $response->assertDontSee('All classes');
        $response->assertDontSee('All courses');
        $response->assertDontSee('All sessions');
    }

    public function test_the_hei_create_screen_offers_only_offerings_the_lecturer_may_manage(): void
    {
        $other = $this->makeOffering('SECRET-2026-S1');
        $stranger = $this->makeLecturer('Stranger', tester: true);
        $this->allocate($other, $stranger);

        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.create'));

        $response->assertOk();
        $response->assertSee('BBIT1103-2026-S1');
        $response->assertDontSee('SECRET-2026-S1');
    }

    public function test_a_lecturer_with_no_managed_offering_is_told_why(): void
    {
        $unallocated = $this->makeLecturer('Unallocated', tester: true);

        $response = $this->actingAs($unallocated)->get(route('teacher.live_classes.create'));

        $response->assertOk();
        $response->assertSee('You have no Course Offering to schedule into yet.');
    }

    // ══ §3 / §13 OFFERING_5_PRESELECTED + ACADEMIC_CONTEXT_DERIVED ══

    public function test_the_offering_scoped_form_preselects_the_offering_and_derives_context(): void
    {
        $this->registerStudent();
        $this->registerStudent('Second Student');

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk();
        $response->assertSee('Course Context');
        $response->assertSee('BBIT1103-2026-S1');
        $response->assertSee('Business Mathematics');
        $response->assertSee('2026/2027');
        $response->assertSee('Semester 1');
        $response->assertSee('2 registered students');
        $response->assertSee('Primary Lecturer');

        // There is no Offering picker: it is already chosen by the route.
        $response->assertDontSee('name="course_offering_id"', false);
    }

    public function test_change_course_offering_is_offered_only_when_there_is_somewhere_to_change_to(): void
    {
        $alone = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));
        $alone->assertOk()->assertDontSee('Change Course Offering');

        $second = $this->makeOffering('BBIT1104-2026-S1');
        $this->allocate($second, $this->lecturer);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('Change Course Offering');
    }

    // ══ §2 / §4 LEGACY_ACADEMIC_FIELDS_HIDDEN ══

    public function test_the_lecturer_form_hides_every_legacy_or_internal_field(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk();

        foreach ([
            'name="subject_id"', 'name="class_id"', 'name="programme_id"',
            'name="academic_session_id"', 'name="meeting_id"',
            'name="meeting_password"', 'name="recording_url"',
            'name="attendance_enabled"', 'name="is_published"', 'name="status"',
        ] as $forbidden) {
            $response->assertDontSee($forbidden, false, "{$forbidden} must not be in the lecturer form");
        }

        // What must be there.
        foreach (['name="title"', 'name="description"', 'name="start_date"',
            'name="start_time"', 'name="end_time"', 'name="platform"', 'name="action"'] as $required) {
            $response->assertSee($required, false);
        }
    }

    // ══ §5 TIMEZONE ══

    public function test_the_timezone_comes_from_the_institution_not_a_hardcode(): void
    {
        $scheduling = app(LiveClassSchedulingContext::class);
        $this->assertSame('Africa/Kampala', $scheduling->timezone($this->lecturer));
        $this->assertTrue($scheduling->hasConfiguredTimezone($this->lecturer));

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));
        $response->assertOk();
        $response->assertSee('Africa/Kampala');
    }

    public function test_an_unset_tenant_timezone_falls_back_to_the_application_default_and_says_so(): void
    {
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);

        $scheduling = app(LiveClassSchedulingContext::class);
        $this->assertFalse($scheduling->hasConfiguredTimezone($this->lecturer));
        $this->assertSame(config('app.timezone'), $scheduling->timezone($this->lecturer));

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('has not set a timezone yet');
    }

    public function test_a_saved_class_stores_the_institution_timezone_and_utc_canonically(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload()
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertSame('Africa/Kampala', $class->timezone);
        $this->assertSame(
            '10:00',
            Carbon::parse($class->scheduled_at)->setTimezone('Africa/Kampala')->format('H:i'),
            'the stored instant must read back as 10:00 in the institution timezone'
        );
        $this->assertSame(
            '07:00',
            Carbon::parse($class->scheduled_at)->setTimezone('UTC')->format('H:i'),
            'and it is stored canonically as the equivalent UTC instant (Kampala is UTC+3)'
        );
    }

    // ══ §6 CREATE_STATUS_FIXED / SAVE_AS_DRAFT / SCHEDULE_AND_NOTIFY ══

    public function test_the_create_form_does_not_offer_a_raw_status_dropdown_or_prefill_live(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk();
        $response->assertDontSee('value="live"', false);
        $response->assertSee('Save as Draft');
        $response->assertSee('Schedule &amp; Notify Students', false);
    }

    public function test_save_as_draft_creates_a_draft_and_notifies_nobody(): void
    {
        $this->registerStudent();

        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['action' => 'draft'])
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertFalse((bool) $class->is_published);
        $this->assertSame(LiveClass::STATUS_DRAFT, $class->status);
        $this->assertSame(0, DB::table('user_notifications')->count());
    }

    public function test_schedule_and_notify_publishes_and_reports_the_recipient_count(): void
    {
        $this->registerStudent('Confirmed One');
        $this->registerStudent('Confirmed Two');
        $this->registerStudent('Pending One', 'pending');

        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['action' => 'publish'])
        );
        $response->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertTrue((bool) $class->is_published);
        $this->assertNotSame(LiveClass::STATUS_LIVE, $class->status,
            'a future class must not be stored as live');
        $this->assertSame(LiveClass::STATUS_SCHEDULED, $class->computed_status);

        $success = (string) session('success');
        $this->assertStringContainsString('2 registered students were notified', $success);
        $this->assertSame(2, DB::table('user_notifications')->count());
    }

    public function test_a_client_cannot_assert_its_own_publishing_state(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload([
                'action' => 'draft',
                'is_published' => 1,
                'status' => 'live',
                'attendance_enabled' => 1,
                'recording_url' => 'https://videos.example.test/sneaky',
            ])
        );

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertFalse((bool) $class->is_published, 'action=draft must win over a posted is_published');
        $this->assertFalse((bool) $class->attendance_enabled);
        $this->assertNull($class->recording_url);
    }

    // ══ §7 MEETING_PROVIDER_UX ══

    public function test_only_supported_providers_are_offered_and_teams_is_absent(): void
    {
        $options = app(LiveClassSchedulingContext::class)->platformOptions($this->lecturer);

        $this->assertNotEmpty($options);
        $this->assertArrayNotHasKey('microsoft_teams', $options);
        $this->assertArrayNotHasKey('teams', $options);
        foreach (array_keys($options) as $key) {
            $this->assertContains(
                $key,
                ['jitsi', 'google_meet', 'zoom', 'bigbluebutton', 'custom'],
                'only providers this application can actually host may be offered'
            );
        }
    }

    public function test_an_auto_creating_provider_never_asks_for_a_link(): void
    {
        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['platform' => 'jitsi', 'meeting_url' => null])
        );

        $response->assertSessionHasNoErrors();
        $this->assertNotEmpty(LiveClass::where('course_offering_id', $this->offering->id)->value('meeting_url'));
    }

    public function test_a_manual_provider_requires_a_link(): void
    {
        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['platform' => 'custom', 'meeting_url' => null])
        );

        $response->assertSessionHasErrors('meeting_url');
        $this->assertSame(0, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    // ══ §8 MEETING_ID / MEETING_PASSWORD ══

    public function test_provider_credentials_cannot_be_posted_or_prepopulated(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload([
                'meeting_id' => 'borrowed-room-id',
                'meeting_password' => 'borrowed-secret',
            ])
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertNull($class->meeting_id);
        $this->assertNull($class->meeting_password);
    }

    // ══ §9 RECORDING_CREATE_VISIBILITY ══

    public function test_a_recording_cannot_be_smuggled_in_at_scheduling_time(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['recording_url' => 'https://videos.example.test/premature'])
        )->assertSessionHasNoErrors();

        $this->assertNull(
            LiveClass::where('course_offering_id', $this->offering->id)->value('recording_url')
        );
    }

    // ══ §10 LEGACY_ATTENDANCE_CHECKBOX ══

    public function test_legacy_attendance_is_off_for_an_offering_backed_class(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['attendance_enabled' => 1])
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertFalse((bool) $class->attendance_enabled,
            'joining a Live Class must never write participation that competes with official Attendance');
    }

    // ══ §12 GLOBAL_INDEX_HEI_FILTERS / MEET_NOW_OFFERING_GOVERNED ══

    public function test_the_global_index_offers_offering_aware_filters_for_hei(): void
    {
        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.index'));

        $response->assertOk();
        $response->assertSee('Course Offering');
        $response->assertSee('Academic Period');
        $response->assertSee('All Course Offerings');
        $response->assertDontSee('All classes');
        $response->assertDontSee('All courses');
        $response->assertDontSee('All sessions');
    }

    public function test_quick_modal_wording_is_gone(): void
    {
        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.index'));

        $response->assertOk();
        $response->assertDontSee('Quick Modal');
        $response->assertSee('Quick Schedule');
    }

    public function test_meet_now_requires_an_authorised_offering_for_hei(): void
    {
        // No Offering at all: refused rather than creating an orphan meeting.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.meet_now'), ['platform' => 'jitsi'])
            ->assertSessionHasErrors('course_offering_id');

        // Somebody else's Offering: refused.
        $other = $this->makeOffering('SECRET-2026-S1');
        $stranger = $this->makeLecturer('Stranger', tester: true);
        $this->allocate($other, $stranger);

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.meet_now'), [
            'platform' => 'jitsi', 'course_offering_id' => $other->id,
        ])->assertForbidden();

        $this->assertSame(0, LiveClass::count(), 'no academically orphaned meeting may be created');
    }

    // ══ §14 SECURITY / AUTHORITY ══

    public function test_a_normal_future_lecturer_is_still_date_gated(): void
    {
        $future = $this->makeOffering('BBIT1103-2027-S1');
        $normal = $this->makeLecturer('Future Lecturer', tester: false);
        $this->allocate($future, $normal, now()->addMonth()->startOfMonth()->toDateString());

        $this->actingAs($normal)
            ->get(route('teacher.course_offerings.live_classes.create', $future->id))
            ->assertForbidden();

        $this->actingAs($normal)->post(
            route('teacher.course_offerings.live_classes.store', $future->id), $this->payload()
        )->assertForbidden();
    }

    public function test_the_governed_pre_start_tester_still_works_for_offering_five(): void
    {
        $future = $this->makeOffering('BBIT1103-2027-S1');
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)->delete();
        $this->allocate($future, $this->lecturer, now()->addMonth()->startOfMonth()->toDateString());
        DB::table('audit_logs')->insert([
            'school_id' => $this->school, 'action' => 'COURSE_OFFERING_EARLY_START',
            'event_type' => 'COURSE_OFFERING_EARLY_START', 'module' => 'Course Offerings',
            'description' => 'early start', 'record_type' => CourseOffering::class,
            'record_id' => $future->id, 'created_at' => now(),
        ]);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $future->id))
            ->assertOk();

        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $future->id), $this->payload()
        )->assertSessionHasNoErrors();

        $this->assertSame(1, LiveClass::where('course_offering_id', $future->id)->count());
    }

    // ══ §13 validation returns to the form with input preserved ══

    public function test_a_validation_failure_returns_to_the_form_and_keeps_the_input(): void
    {
        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['title' => '', 'start_date' => ''])
        );

        $response->assertSessionHasErrors(['title', 'start_date']);
        $response->assertSessionHasInput('platform', 'jitsi');

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('Please check the following and try again.');
    }

    public function test_end_time_must_follow_start_time(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            $this->payload(['start_time' => '14:00', 'end_time' => '13:00'])
        )->assertSessionHasErrors('end_time');
    }

    // ══ §1 K12_WORKFLOW_PRESERVED ══

    public function test_a_k12_institution_keeps_the_legacy_create_form(): void
    {
        $k12 = $this->makeSchool();
        $this->markK12($k12);
        $k12Lecturer = $this->makeLecturer('K12 Teacher', school: $k12);
        $subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $k12, 'name' => 'Mathematics', 'code' => 'MATH101',
        ]);
        $classId = (int) DB::table('classes')->insertGetId([
            'school_id' => $k12, 'name' => 'Form 1 East',
        ]);

        $response = $this->actingAs($k12Lecturer)->get(route('teacher.live_classes.create'));

        $response->assertOk();
        $response->assertSee('Assign To');
        $response->assertSee('Mathematics');
        $response->assertSee('Form 1 East');
        $response->assertSee('name="class_id"', false);
        $response->assertDontSee('Course Offering');
    }

    public function test_a_k12_institution_still_keeps_the_legacy_meet_now_selectors(): void
    {
        $k12 = $this->makeSchool();
        $this->markK12($k12);
        $k12Lecturer = $this->makeLecturer('K12 Meet', school: $k12);

        $this->actingAs($k12Lecturer)
            ->get(route('teacher.live_classes.index'))
            ->assertOk()
            ->assertSee('All classes')
            ->assertSee('All sessions');
    }

    public function test_a_k12_institution_still_refuses_offering_context_on_meet_now(): void
    {
        $k12 = $this->makeSchool();
        $this->markK12($k12);
        $k12Lecturer = $this->makeLecturer('K12 Guard', school: $k12);

        $this->actingAs($k12Lecturer)->post(route('teacher.live_classes.meet_now'), [
            'platform' => 'jitsi', 'course_offering_id' => $this->offering->id,
        ])->assertSessionHasErrors('course_offering_id');
    }
}
