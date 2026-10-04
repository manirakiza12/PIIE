<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\School;
use App\Models\User;
use App\Support\LiveClasses\LiveClassNotifier;
use App\Support\TenantTimezone;
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
 * Two timezones, one instant.
 *
 * THE SCENARIO (section 11 of the brief)
 *
 *   Institution   Africa/Kampala      - the official academic reference
 *   Lecturer      Europe/London       - physically in another country
 *   Student A     Africa/Kampala      - follows the institution
 *   Student B     America/New_York    - physically in another country
 *
 * ONE class is created. Every party must see a correct local rendering of the
 * SAME stored instant, the institution's reference must remain Kampala, they
 * must all enter the join window simultaneously, and one person's preference
 * must never leak into another's.
 */
class MultinationalTimezoneTest extends TestCase
{
    use AdmissionsTestHelper;
    use \Tests\Feature\Support\FrozenClock;

    private int $school;

    private int $otherSchool;

    private int $year;

    private int $period;

    private int $subject;

    private CourseOffering $offering;

    private User $londonLecturer;

    private User $kampalaStudent;

    private User $newYorkStudent;

    private User $noPreferenceStudent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('app.timezone', 'UTC');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->bootAdmissionsTestSchema();
        $this->schema();

        $this->school = $this->makeSchool('Africa/Kampala');
        $this->otherSchool = $this->makeSchool('Europe/London');

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

        // A lecturer who is physically in London, teaching a Kampala course.
        $this->londonLecturer = $this->makeUser('Daniel Okello', 3, $this->school, 'Europe/London');
        DB::table('user_permissions')->insert([
            ['school_id' => $this->school, 'user_id' => $this->londonLecturer->id, 'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $this->school, 'user_id' => $this->londonLecturer->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $this->londonLecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->kampalaStudent = $this->registerStudent('Student A', 'Africa/Kampala');
        $this->newYorkStudent = $this->registerStudent('Student B', 'America/New_York');
        $this->noPreferenceStudent = $this->registerStudent('Student C', null);
    }

    private function schema(): void
    {
        foreach ([
            'school_type' => 'string', 'education_level' => 'string', 'timezone' => 'string',
            'phone' => 'integer', 'address' => 'string', 'school_info' => 'text',
            'primary_locale' => 'string', 'country_code' => 'string',
            'academic_calendar_pattern' => 'string', 'school_currency' => 'string',
            'currency_position' => 'string', 'terminology_overrides' => 'text',
        ] as $col => $kind) {
            if (! Schema::hasColumn('schools', $col)) {
                Schema::table('schools', function (Blueprint $t) use ($col, $kind): void {
                    $t->{$kind}($col)->nullable();
                });
            }
        }
        // The user preference column, mirroring the migration.
        if (! Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('timezone', 64)->nullable();
            });
        }
        Schema::create('currency', function (Blueprint $t): void {
            $t->id(); $t->string('title')->nullable(); $t->string('code')->nullable(); $t->timestamps();
        });
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
            $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('course_offering_id');
            $t->string('status'); $t->timestamps();
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
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('staff_role_id');
            $t->string('permission', 100); $t->timestamps();
        });
        Schema::create('user_staff_roles', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('staff_role_id'); $t->timestamps();
        });
        Schema::create('teacher_programme_assignments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('teacher_id'); $t->timestamps();
        });
        Schema::create('live_class_notifications', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->string('type', 30); $t->unsignedInteger('recipient_count')->default(0);
            $t->timestamp('sent_at'); $t->timestamps();
            $t->unique(['live_class_id', 'type'], 'lcn_unique');
        });
        Schema::create('live_class_attendances', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('live_class_id');
            $t->unsignedBigInteger('user_id'); $t->unsignedSmallInteger('role_id')->nullable();
            $t->timestamp('joined_at')->nullable(); $t->timestamp('left_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable(); $t->timestamps();
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
            $t->boolean('is_published')->default(false); $t->boolean('attendance_enabled')->default(false);
            $t->unsignedBigInteger('created_by')->nullable(); $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->unsignedBigInteger('started_by')->nullable();
            $t->unsignedBigInteger('ended_by')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('recording_status', 20)->default('none');
        $t->string('recording_url', 500)->nullable();
        $t->timestamps();

        });
    }

    private function makeSchool(?string $timezone): int
    {
        return (int) DB::table('schools')->insertGetId([
            'title' => 'School '.($timezone ?? 'unset'),
            'school_type' => 'higher_ed', 'education_level' => 'tertiary',
            'timezone' => $timezone, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeOffering(string $reference): CourseOffering
    {
        $o = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($this->school, $this->subject, $this->year, $this->period, $reference);
        DB::table('course_offerings')->where('id', $o->id)->update(['status' => 'in_progress']);

        return $o->fresh();
    }

    private function makeUser(string $name, int $role, int $school, ?string $timezone): User
    {
        return User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name))."{$school}.{$role}@example.test",
            'role_id' => $role, 'school_id' => $school, 'account_status' => 'active',
            'timezone' => $timezone, 'password' => Hash::make('User#2026'),
        ]);
    }

    private function registerStudent(string $name, ?string $timezone): User
    {
        $s = $this->makeUser($name, 7, $this->school, $timezone);
        DB::table('course_registrations')->insert([
            'school_id' => $this->school, 'student_id' => $s->id, 'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id, 'status' => 'confirmed',
        ]);

        return $s;
    }

    // ══ §3 THE RESOLUTION RULE ══

    public function test_the_resolution_order_is_user_then_institution_then_application(): void
    {
        $tz = app(TenantTimezone::class);

        // 1. The person's own choice wins.
        $this->assertSame('Europe/London', $tz->effective($this->londonLecturer));
        // 2. NULL means follow the institution.
        $this->assertSame('Africa/Kampala', $tz->effective($this->kampalaStudent));
        $this->assertFalse($tz->hasUserTimezone($this->noPreferenceStudent));
        // 3. Institution NULL means the application default.
        $bare = $this->makeUser('Bare Institution', 3, $this->school, null);
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);
        $this->assertSame('UTC', $tz->effective($bare));
        DB::table('schools')->where('id', $this->school)->update(['timezone' => 'Africa/Kampala']);
    }

    public function test_a_personal_timezone_never_changes_the_institution_reference(): void
    {
        $tz = app(TenantTimezone::class);
        $this->assertSame('Africa/Kampala', $tz->resolve($this->londonLecturer),
            'the institution timezone is the lecturer\'\s institution timezone, not theirs');
        $this->assertSame('Africa/Kampala', School::query()->find($this->school)->fresh()->timezone);

        $this->londonLecturer->update(['timezone' => 'Asia/Dubai']);
        $this->assertSame('Africa/Kampala', School::query()->find($this->school)->fresh()->timezone,
            'a personal preference must not be able to alter institutional time');
    }

    public function test_one_users_preference_does_not_reach_another_user(): void
    {
        $tz = app(TenantTimezone::class);
        $this->assertSame('Europe/London', $tz->effective($this->londonLecturer));
        $this->assertSame('America/New_York', $tz->effective($this->newYorkStudent));
        $this->assertSame('Africa/Kampala', $tz->effective($this->kampalaStudent));
    }

    // ══ §2 / §8 the preference is selectable ══

    public function test_a_lecturer_a_student_and_staff_can_each_choose_their_own_timezone(): void
    {
        // One shared endpoint for every role, so the rule cannot drift per portal.
        foreach ([3 => 'Lecturer', 7 => 'Student', 11 => 'Staff'] as $role => $label) {
            $user = $this->makeUser('Person '.$label, $role, $this->school, null);

            $this->actingAs($user)->get(route('profile.regional.edit'))->assertOk();
            $this->actingAs($user)->post(route('profile.regional.update'), ['timezone' => 'Asia/Dubai'])
                ->assertRedirect(route('profile.regional.edit'));

            $this->assertSame('Asia/Dubai', $user->fresh()->timezone, "{$label} can set a personal timezone");
            $this->assertSame('Asia/Dubai', app(TenantTimezone::class)->effective($user->fresh()));
        }
    }

    public function test_the_preference_screen_is_a_searchable_dropdown_not_a_text_box(): void
    {
        $response = $this->actingAs($this->londonLecturer)->get(route('profile.regional.edit'));

        $response->assertOk()
            ->assertSee('<select name="timezone"', false)
            ->assertSee('Use institution timezone')
            ->assertSee('Africa/Kampala')
            ->assertSee('Europe/London')
            ->assertSee('America/New_York')
            ->assertSee('select2', false);
    }

    public function test_clearing_the_preference_returns_to_the_institution_timezone(): void
    {
        $this->londonLecturer->update(['timezone' => 'Europe/London']);
        $this->assertSame('Europe/London', app(TenantTimezone::class)->effective($this->londonLecturer));

        $this->actingAs($this->londonLecturer)
            ->post(route('profile.regional.update'), ['timezone' => ''])
            ->assertRedirect();

        $this->assertNull($this->londonLecturer->fresh()->timezone, 'NULL, not a copy of the institution value');
        $this->assertSame('Africa/Kampala', app(TenantTimezone::class)->effective($this->londonLecturer->fresh()));
    }

    public function test_an_invalid_or_fixed_offset_timezone_is_refused(): void
    {
        $this->actingAs($this->londonLecturer)
            ->post(route('profile.regional.update'), ['timezone' => 'UTC+1'])
            ->assertSessionHasErrors('timezone');
        $this->assertSame('Europe/London', $this->londonLecturer->fresh()->timezone,
            'the refused value is not stored, and the previous choice is left alone');
    }

    // ══ §4 LIVE CLASS SCHEDULING ══

    public function test_a_london_lecturer_typing_0800_creates_one_instant_that_is_1000_kampala(): void
    {
        $this->actingAs($this->londonLecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            [
                'title' => 'Business Mathematics', 'platform' => 'jitsi', 'action' => 'draft',
                'start_date' => '2026-09-29', 'start_time' => '08:00', 'end_time' => '09:00',
                'meeting_url' => 'https://meet.jit.si/room',
            ]
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $tz = app(TenantTimezone::class);

        // ONE schedule, normalised to UTC for storage.
        $this->assertSame('Europe/London', $class->timezone,
            'the class records the clock it was entered in');
        $this->assertSame('07:00', Carbon::parse($class->scheduled_at)->setTimezone('UTC')->format('H:i'),
            '08:00 London is 07:00 UTC');
        $this->assertSame('10:00', $tz->inTenantTime($class->scheduled_at, School::query()->find($this->school))->format('H:i'),
            'and 10:00 in the institution timezone');

        // NOT two schedules: exactly one row exists.
        $this->assertSame(1, LiveClass::where('course_offering_id', $this->offering->id)->count());
    }

    public function test_the_scheduling_form_shows_both_clocks_and_the_institution_equivalent(): void
    {
        $response = $this->actingAs($this->londonLecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id));

        $response->assertOk()
            ->assertSee('London (UTC+1)')       // personal
            ->assertSee('Kampala (UTC+3)')      // institution
            ->assertSee('Your timezone')
            ->assertSee('Institution timezone')
            ->assertSee('Institution time');
        $response->assertDontSee('has not set a timezone yet');
    }

    public function test_a_lecturer_following_the_institution_sees_no_duplicate_clock(): void
    {
        $following = $this->makeUser('Kampala Lecturer', 3, $this->school, null);
        DB::table('user_permissions')->insert([
            ['school_id' => $this->school, 'user_id' => $following->id, 'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $this->school, 'user_id' => $following->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $following->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($following)
            ->get(route('teacher.course_offerings.live_classes.create', $this->offering->id))
            ->assertOk()
            ->assertSee('Kampala (UTC+3)')
            ->assertDontSee('London (UTC+1)')
            ->assertDontSee('Your timezone:');
    }

    public function test_the_client_cannot_choose_the_zone_its_own_input_is_read_in(): void
    {
        $this->actingAs($this->londonLecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            [
                'title' => 'Spoof attempt', 'platform' => 'jitsi', 'action' => 'draft',
                'start_date' => '2026-09-29', 'start_time' => '08:00', 'end_time' => '09:00',
                'meeting_url' => 'https://meet.jit.si/room',
                // A tampered form must not move the class by whole hours.
                'timezone' => 'America/New_York',
            ]
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertSame('Europe/London', $class->timezone,
            'the submitted timezone is ignored; the scheduler\'\s own clock is authoritative');
        $this->assertSame('07:00', Carbon::parse($class->scheduled_at)->setTimezone('UTC')->format('H:i'));
    }

    // ══ §5 STUDENT DISPLAY ══

    public function test_each_student_sees_the_same_instant_in_their_own_clock(): void
    {
        $startsAt = Carbon::parse('2026-09-29 07:00:00', 'UTC'); // 10:00 Kampala, 08:00 London
        $class = $this->makeClass($startsAt);

        $tz = app(TenantTimezone::class);
        $school = School::query()->find($this->school);

        $this->assertSame('10:00', $tz->inEffectiveTime($startsAt, $this->kampalaStudent, $school)->format('H:i'));
        $this->assertSame('03:00', $tz->inEffectiveTime($startsAt, $this->newYorkStudent, $school)->format('H:i'));
        // Student C chose nothing, so they follow the institution.
        $this->assertSame('10:00', $tz->inEffectiveTime($startsAt, $this->noPreferenceStudent, $school)->format('H:i'));

        // Every rendering is the same instant.
        foreach ([$this->kampalaStudent, $this->newYorkStudent, $this->noPreferenceStudent] as $s) {
            $this->assertSame(
                $startsAt->getTimestamp(),
                $tz->inEffectiveTime($startsAt, $s, $school)->getTimestamp()
            );
        }

        $this->assertSame(1, LiveClass::count(), 'one class, not one per timezone');
    }

    // ══ §6 NOTIFICATIONS PER RECIPIENT ══

    public function test_each_recipient_is_told_the_time_in_their_own_clock(): void
    {
        $startsAt = Carbon::parse('2026-09-29 07:00:00', 'UTC');
        $class = $this->makeClass($startsAt);

        LiveClassNotifier::announcePublished($class->fresh());

        $bodyFor = fn (User $u) => (string) DB::table('user_notifications')
            ->where('user_id', $u->id)->where('type', 'live_class_published')->value('body');

        // 07:00 UTC == 10:00 Kampala == 03:00 New York.
        $this->assertStringContainsString('10:00 AM', $bodyFor($this->kampalaStudent));
        $this->assertStringContainsString('3:00 AM', $bodyFor($this->newYorkStudent));
        $this->assertStringContainsString('10:00 AM', $bodyFor($this->noPreferenceStudent));

        // The lecturer is not a recipient here, but his own clock is the one he
        // scheduled in; nobody else was moved.
        $this->assertStringNotContainsString('3:00 AM', $bodyFor($this->kampalaStudent));
        $this->assertStringNotContainsString('10:00 AM', $bodyFor($this->newYorkStudent));
        $this->assertSame(3, DB::table('user_notifications')->where('type', 'live_class_published')->count());
    }

    public function test_the_email_uses_the_recipients_own_clock(): void
    {
        Mail::fake();
        foreach (['smtp_user' => 'u', 'smtp_pass' => 'p', 'smtp_host' => 'h', 'smtp_port' => '2525'] as $k => $v) {
            DB::table('global_settings')->insert(['key' => $k, 'value' => $v]);
        }

        $startsAt = now()->addHour();
        $class = $this->makeClass($startsAt);

        \Illuminate\Support\Facades\Artisan::call('live-classes:send-reminders');

        $tz = app(TenantTimezone::class);
        $school = School::query()->find($this->school);
        $expected = [
            $this->kampalaStudent->id => $tz->inEffectiveTime($startsAt, $this->kampalaStudent, $school)->format('H:i'),
            $this->newYorkStudent->id => $tz->inEffectiveTime($startsAt, $this->newYorkStudent, $school)->format('H:i'),
            $this->noPreferenceStudent->id => $tz->inEffectiveTime($startsAt, $this->noPreferenceStudent, $school)->format('H:i'),
        ];

        foreach (Mail::sent(\App\Mail\LiveClassReminderEmail::class) as $mail) {
            $matched = null;
            foreach ([$this->kampalaStudent, $this->newYorkStudent, $this->noPreferenceStudent] as $student) {
                if ($mail->hasTo($student->email)) {
                    $matched = $student;
                    break;
                }
            }
            $this->assertNotNull($matched, 'every reminder email goes to a confirmed student');
            $this->assertSame($expected[$matched->id], $mail->data['time'],
                "recipient {$matched->name} must be shown their own local time");
            $this->assertNotEmpty($mail->data['timezone_label'],
                'the zone is named so the printed time is not ambiguous');
        }
        $this->assertCount(3, Mail::sent(\App\Mail\LiveClassReminderEmail::class));
    }

    // ══ §7 JOIN WINDOW IS INSTANT-BASED ══

    public function test_everyone_enters_the_join_window_at_the_same_instant(): void
    {
        $startsAt = Carbon::parse('2026-09-29 07:00:00', 'UTC');
        $class = $this->makeClass($startsAt);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        $tz = app(TenantTimezone::class);
        $school = School::query()->find($this->school);

        // 07:00 UTC == 10:00 Kampala == 08:00 London == 03:00 New York.
        // The window opens 15 minutes earlier, i.e. 06:45 UTC, which is a
        // DIFFERENT wall clock in every one of those zones. Whichever clock a
        // person happens to be reading, the window is open or shut for all of
        // them at the same instant.
        $probes = [
            'London 07:00' => Carbon::parse('2026-09-29 07:00:00', 'Europe/London'),
            'Kampala 09:00' => Carbon::parse('2026-09-29 09:00:00', 'Africa/Kampala'),
            'New York 02:00' => Carbon::parse('2026-09-29 02:00:00', 'America/New_York'),
        ];
        foreach ($probes as $label => $moment) {
            $this->assertFalse(
                $access->withinJoinWindow($class->fresh(), $moment),
                "not yet at the 15-minute mark, expressed as {$label}"
            );
        }

        // One minute inside the window, spelled three ways: open for all.
        $open = [
            'London 07:46' => Carbon::parse('2026-09-29 07:46:00', 'Europe/London'),
            'Kampala 09:46' => Carbon::parse('2026-09-29 09:46:00', 'Africa/Kampala'),
            'New York 02:46' => Carbon::parse('2026-09-29 02:46:00', 'America/New_York'),
        ];
        foreach ($open as $label => $moment) {
            $this->assertTrue($access->withinJoinWindow($class->fresh(), $moment), "open at {$label}");
        }

        // And each of those probes is genuinely the same instant as each other.
        $instants = array_map(fn (Carbon $m) => $m->getTimestamp(), $open);
        $this->assertCount(1, array_unique($instants), 'three spellings, one instant');

        // And the authorising user identity is irrelevant to the window.
        $this->assertSame(
            $tz->inEffectiveTime($startsAt, $this->newYorkStudent, $school)->getTimestamp(),
            $tz->inEffectiveTime($startsAt, $this->kampalaStudent, $school)->getTimestamp()
        );
    }

    // ══ §11 tenant isolation with two clocks ══

    public function test_tenant_isolation_holds_with_personal_timezones_in_play(): void
    {
        $tz = app(TenantTimezone::class);
        $londonStaff = $this->makeUser('London Staff', 3, $this->otherSchool, 'Europe/London');

        $this->assertSame('Europe/London', $tz->resolve($londonStaff),
            'a second institution keeps its own reference');
        $this->assertSame('Europe/London', $tz->resolve($this->otherSchool ? School::query()->find($this->otherSchool) : null));
        $this->assertSame('Africa/Kampala', School::query()->find($this->school)->fresh()->timezone);
    }

    public function test_no_fixed_offset_is_ever_stored(): void
    {
        // "UTC+3" and friends are refused outright. A region that observes
        // daylight saving cannot be described by a fixed offset, and an offset
        // would silently be an hour wrong twice a year.
        foreach (['UTC+3', 'EAT', 'GMT+1', '3', '+03:00'] as $bad) {
            $this->actingAs($this->londonLecturer)
                ->post(route('profile.regional.update'), ['timezone' => $bad])
                ->assertSessionHasErrors('timezone');
        }
        $this->assertSame('Europe/London', $this->londonLecturer->fresh()->timezone,
            'a fixed offset never reaches the column');
    }

    public function test_the_whole_scenario_keeps_one_institution_and_one_lifecycle(): void
    {
        $startsAt = Carbon::parse('2026-09-29 07:00:00', 'UTC');
        $class = $this->makeClass($startsAt);
        $class->update(['is_published' => true, 'status' => LiveClass::STATUS_SCHEDULED]);

        $tz = app(TenantTimezone::class);
        $school = School::query()->find($this->school);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        // One class, one instant, one lifecycle state, seen from three clocks.
        $this->assertSame(1, LiveClass::count());
        $this->assertSame('Africa/Kampala', $school->fresh()->timezone, 'institution reference unchanged');
        foreach ([$this->kampalaStudent, $this->newYorkStudent, $this->noPreferenceStudent] as $s) {
            $this->assertSame(
                $startsAt->getTimestamp(),
                $tz->inEffectiveTime($class->scheduled_at, $s, $school)->getTimestamp()
            );
        }
        // And the lifecycle is shared: the join window is a property of the
        // class, not of who is asking or which clock they read.
        $this->assertFalse($access->withinJoinWindow($class->fresh(), Carbon::parse('2026-09-29 06:00:00', 'UTC')));
        $this->assertTrue($access->withinJoinWindow($class->fresh(), Carbon::parse('2026-09-29 06:46:00', 'UTC')));
    }

    private function makeClass(Carbon $startsAt): LiveClass
    {
        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->school, 'title' => 'Business Mathematics',
            'subject_id' => $this->subject, 'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->londonLecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'Africa/Kampala',
            'scheduled_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
            'start_date' => $startsAt->toDateString(), 'start_time' => $startsAt->format('H:i:s'),
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();

        return $class->fresh();
    }
}
