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
 * Institution timezone: one authoritative source, per tenant, stored as a real
 * IANA identifier.
 *
 * THE DEFECT THIS PINS
 *
 * `schools.timezone` already existed and was already validated - and it was
 * still NULL in production, because the only administration screen that could
 * set it presented a free-text box asking a human to type "Africa/Kampala" by
 * hand. Every other regional field on that screen (institution type, education
 * level, primary language, country, calendar pattern) is a proper dropdown; the
 * timezone was the lone exception, and it was the one field nobody could fill in
 * confidently. Live Classes therefore fell back to the platform default and the
 * lecturer was told so on the scheduling form.
 *
 * Nothing here converts or rewrites a stored record. A Live Class holds an
 * absolute instant; these tests prove that changing an institution's timezone
 * changes only which wall clock is displayed, never the moment itself.
 */
class TenantTimezoneTest extends TestCase
{
    use AdmissionsTestHelper;
    use \Tests\Feature\Support\FrozenClock;

    private int $kampala;

    private int $london;

    private int $unset;

    private int $year;

    private int $period;

    private int $subject;

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
        if (! Schema::hasColumn('users', 'staff_status')) {
            Schema::table('users', function (Blueprint $t): void {
                $t->string('staff_status')->nullable();
            });
        }

        $this->kampala = $this->makeSchool('Africa/Kampala');
        $this->london = $this->makeSchool('Europe/London');
        $this->unset = $this->makeSchool(null);

        $this->year = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $this->kampala, 'label' => '2026/2027',
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->period = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $this->kampala, 'academic_year_id' => $this->year, 'type' => 'semester',
            'label' => 'Semester 1', 'sequence' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $this->subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $this->kampala, 'name' => 'Business Mathematics', 'code' => 'BBIT1103',
        ]);
    }

    private function schema(): void
    {
        foreach ([
            'school_type' => 'string', 'education_level' => 'string', 'timezone' => 'string',
            'primary_locale' => 'string', 'country_code' => 'string',
            'academic_calendar_pattern' => 'string', 'school_currency' => 'string',
            'currency_position' => 'string', 'phone' => 'integer', 'address' => 'string',
            'school_info' => 'text', 'terminology_overrides' => 'text',
        ] as $col => $kind) {
            if (! Schema::hasColumn('schools', $col)) {
                Schema::table('schools', function (Blueprint $t) use ($col, $kind): void {
                    $t->{$kind}($col)->nullable();
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
        Schema::create('currency', function (Blueprint $t): void {
            $t->id(); $t->string('title')->nullable(); $t->string('code')->nullable(); $t->timestamps();
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
        $id = (int) DB::table('schools')->insertGetId([
            'title' => 'School '.($timezone ?? 'unset'),
            'school_type' => 'higher_ed',
            'education_level' => 'tertiary',
            'timezone' => $timezone,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function lecturer(int $school, string $tag = 'a'): User
    {
        $u = User::factory()->create([
            'name' => 'Daniel Okello', 'email' => "lecturer{$school}{$tag}@example.test",
            'role_id' => 3, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active',
            'password' => Hash::make('Lecturer#2026'),
        ]);
        DB::table('user_permissions')->insert([
            ['school_id' => $school, 'user_id' => $u->id, 'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $school, 'user_id' => $u->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $u;
    }

    private function offeringFor(int $school): CourseOffering
    {
        $subject = (int) DB::table('subjects')->insertGetId([
            'school_id' => $school, 'name' => 'Business Mathematics', 'code' => 'BBIT1103-'.$school,
        ]);
        $year = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $school, 'label' => '2026/2027',
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $period = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $school, 'academic_year_id' => $year, 'type' => 'semester',
            'label' => 'Semester 1', 'sequence' => 1,
            'start_date' => '2026-01-01', 'end_date' => '2027-12-31', 'status' => 'active',
        ]);
        $offering = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($school, $subject, $year, $period, 'BBIT1103-2026-S1-'.$school);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'in_progress']);

        return $offering->fresh();
    }

    private function student(string $name, int $school, CourseOffering $offering): User
    {
        $s = User::factory()->create([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name))."@s{$school}.test",
            'role_id' => 7, 'school_id' => $school, 'account_status' => 'active',
            'password' => Hash::make('Student#2026'),
        ]);
        DB::table('course_registrations')->insert([
            'school_id' => $school, 'student_id' => $s->id, 'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id, 'status' => 'confirmed',
        ]);

        return $s;
    }

    // ══ §1 source of truth ══

    public function test_the_existing_school_timezone_column_is_the_source_of_truth(): void
    {
        $tz = app(TenantTimezone::class);
        $kampala = $this->lecturer($this->kampala);
        $london = $this->lecturer($this->london);
        $unset = $this->lecturer($this->unset);

        $this->assertSame('Africa/Kampala', $tz->resolve($kampala));
        $this->assertSame('Europe/London', $tz->resolve($london));
        $this->assertTrue($tz->isConfigured($kampala));
        $this->assertFalse($tz->isConfigured($unset), 'unset is distinguishable from "set to UTC"');
        $this->assertSame('UTC', $tz->resolve($unset), 'unset falls back to the application default');
    }

    // ══ §3 tenant isolation ══

    public function test_changing_one_tenants_timezone_never_moves_another(): void
    {
        $tz = app(TenantTimezone::class);
        $london = $this->lecturer($this->london);

        DB::table('schools')->where('id', $this->kampala)->update(['timezone' => 'Africa/Nairobi']);
        DB::table('schools')->where('id', $this->london)->update(['timezone' => 'America/New_York']);

        $this->assertSame('Africa/Nairobi', $tz->resolve($this->lecturer($this->kampala)));
        $this->assertSame('America/New_York', $tz->resolve($london));
        $this->assertSame('Europe/London', $tz->configured(
            School::query()->findOrFail($this->london)->fill(['timezone' => 'Europe/London'])
        ));
    }

    public function test_no_single_global_timezone_is_used_as_the_tenant_source(): void
    {
        $tz = app(TenantTimezone::class);
        $moment = Carbon::parse('2026-09-29 09:20:00', 'UTC');

        // One instant, three independently configured institutions, three
        // different readings. 09:20 UTC is 12:20 in Kampala (UTC+3) and 10:20
        // in London (UTC+1, British Summer Time in September).
        $this->assertSame('12:20', $tz->inTenantTime($moment, $this->lecturer($this->kampala, 'k'))->format('H:i'));
        $this->assertSame('10:20', $tz->inTenantTime($moment, $this->lecturer($this->london, 'l'))->format('H:i'));
        $this->assertSame('09:20', $tz->inTenantTime($moment, $this->lecturer($this->unset, 'u'))->format('H:i'));

        // The instant itself is identical in all three cases; only the wall
        // clock differs. Compared as a timestamp, because the ISO string
        // carries the offset and would differ purely by representation.
        $this->assertSame(
            $moment->getTimestamp(),
            $tz->inTenantTime($moment, $this->lecturer($this->kampala, 'k2'))->getTimestamp()
        );
        $this->assertSame(
            $moment->getTimestamp(),
            $tz->inTenantTime($moment, $this->lecturer($this->london, 'l2'))->getTimestamp()
        );
    }

    // ══ §2 / §7 settings UI, searchable, stores an IANA identifier ══

    public function test_the_settings_screen_offers_a_searchable_dropdown_not_a_text_box(): void
    {
        $super = User::factory()->create([
            'name' => 'Super Admin', 'email' => 'super@admin.test', 'role_id' => 1,
            'school_id' => $this->kampala, 'account_status' => 'active',
            'password' => Hash::make('Admin#2026'),
        ]);

        $response = $this->actingAs($super)
            ->get(route('superadmin.edit.school', School::query()->findOrFail($this->kampala)->id));

        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('<select name="timezone"', $html,
            'the timezone must be a select, not a free-text input');
        $this->assertStringNotContainsString('<input type="text" class="form-control eForm-control" id="timezone"', $html,
            'the free-text box that made this field unusable in practice is gone');
        $this->assertStringContainsString('Africa/Kampala', $html, 'the identifier is offered');
        $this->assertStringContainsString('Kampala (UTC+3)', $html, 'and shown as a human phrase');
        $this->assertStringContainsString('Europe/London', $html);
        $this->assertStringContainsString('select2', $html, 'and it is searchable');
    }

    public function test_the_settings_screen_can_save_a_timezone(): void
    {
        $super = User::factory()->create([
            'name' => 'Super Admin', 'email' => 'super2@admin.test', 'role_id' => 1,
            'school_id' => $this->kampala, 'account_status' => 'active',
            'password' => Hash::make('Admin#2026'),
        ]);
        $school = School::query()->findOrFail($this->kampala);
        $school->fill(['title' => 'PIIE', 'phone' => 2567000, 'address' => 'Kampala', 'school_info' => 'Institute'])->save();

        $this->actingAs($super)
            ->post(route('superadmin.school.update', ['id' => $school->id]), [
                'title' => 'PIIE', 'phone' => 2567000, 'address' => 'Kampala', 'school_info' => 'Institute',
                'timezone' => 'Africa/Kampala',
            ])
            ->assertRedirect();

        $this->assertSame('Africa/Kampala', School::query()->findOrFail($this->kampala)->timezone);
        $this->assertSame('Africa/Kampala', app(TenantTimezone::class)->configured(
            School::query()->findOrFail($this->kampala)
        ));
    }

    public function test_an_invalid_timezone_is_refused(): void
    {
        $super = User::factory()->create([
            'name' => 'Super Admin', 'email' => 'super3@admin.test', 'role_id' => 1,
            'school_id' => $this->kampala, 'account_status' => 'active',
            'password' => Hash::make('Admin#2026'),
        ]);
        $school = School::query()->findOrFail($this->kampala);
        $school->fill(['title' => 'PIIE', 'phone' => 2567000, 'address' => 'Kampala', 'school_info' => 'I'])->save();

        $this->actingAs($super)
            ->post(route('superadmin.school.update', ['id' => $school->id]), [
                'title' => 'PIIE', 'phone' => 2567000, 'address' => 'Kampala', 'school_info' => 'I',
                'timezone' => 'Not/ARealZone',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Africa/Kampala', School::query()->findOrFail($this->kampala)->fresh()->timezone, 'a rejected value must not overwrite the saved one');
    }

    public function test_a_fixed_offset_is_not_a_valid_stored_value(): void
    {
        // "UTC+3" cannot describe Europe/London, which changes its offset twice a
        // year, so only real identifiers are accepted.
        $this->assertFalse(app(TenantTimezone::class)->isValid('UTC+3'));
        $this->assertFalse(app(TenantTimezone::class)->isValid('EAT'));
        $this->assertTrue(app(TenantTimezone::class)->isValid('Africa/Kampala'));
        $this->assertTrue(app(TenantTimezone::class)->isValid('Europe/London'));
    }

    // ══ §4 / §5 input interpretation and storage ══

    public function test_a_lecturer_entering_0920_means_0920_at_the_institution(): void
    {
        $tz = app(TenantTimezone::class);

        $kampala = $tz->interpretInTenantTime('2026-09-29 09:20:00', $this->lecturer($this->kampala));
        $this->assertSame('09:20', $kampala->format('H:i'));
        $this->assertSame('09:20', $kampala->setTimezone('Africa/Kampala')->format('H:i'));
        $this->assertSame('06:20', $kampala->setTimezone('UTC')->format('H:i'),
            'and it is NOT 09:20 UTC');

        $london = $tz->interpretInTenantTime('2026-09-29 09:20:00', $this->lecturer($this->london));
        $this->assertSame('09:20', $london->setTimezone('Europe/London')->format('H:i'));
        $this->assertSame('08:20', $london->setTimezone('UTC')->format('H:i'));
    }

    public function test_the_scheduling_form_shows_the_human_timezone_and_stores_the_identifier(): void
    {
        $offering = $this->offeringFor($this->kampala);
        $lecturer = $this->lecturer($this->kampala);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->kampala, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.live_classes.create', $offering->id));

        $response->assertOk()
            ->assertSee('Kampala (UTC+3)')
            ->assertSee('Times are shown in your institution&#039;s timezone.', false)
            ->assertDontSee('has not set a timezone yet');
        $this->assertStringContainsString(
            'value="Africa/Kampala"',
            (string) $response->getContent(),
            'the stored value is the IANA identifier'
        );
    }

    public function test_a_class_scheduled_in_kampala_stores_the_utc_instant_and_reads_back_as_kampala(): void
    {
        $offering = $this->offeringFor($this->kampala);
        $lecturer = $this->lecturer($this->kampala);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->kampala, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($lecturer)->post(
            route('teacher.course_offerings.live_classes.store', $offering->id),
            [
                'title' => 'Business Mathematics', 'platform' => 'jitsi', 'action' => 'draft',
                'start_date' => '2026-09-29', 'start_time' => '09:20', 'end_time' => '10:20',
                'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'Africa/Kampala',
            ]
        )->assertSessionHasNoErrors();

        $class = LiveClass::where('course_offering_id', $offering->id)->firstOrFail();

        // Storage: an absolute instant, canonically UTC.
        $this->assertSame('Africa/Kampala', $class->timezone);
        $this->assertSame('06:20', Carbon::parse($class->scheduled_at)->setTimezone('UTC')->format('H:i'),
            '09:20 Kampala is 06:20 UTC');

        // Display: back to the institution clock.
        $tz = app(TenantTimezone::class);
        $this->assertSame('09:20', $tz->inTenantTime($class->scheduled_at, School::query()->find($this->kampala))->format('H:i'));
    }

    // ══ §8 notification and email time ══

    public function test_the_notification_shows_the_institution_local_time(): void
    {
        $offering = $this->offeringFor($this->kampala);
        $student = $this->student('Kyeyune', $this->kampala, $offering);

        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->kampala, 'title' => 'Business Mathematics',
            'subject_id' => $offering->subject_id, 'course_offering_id' => $offering->id,
            'platform' => 'jitsi', 'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'Africa/Kampala',
            'scheduled_at' => Carbon::parse('2026-09-29 06:20:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 07:20:00', 'UTC'),
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();

        LiveClassNotifier::announcePublished($class->fresh());

        $body = (string) DB::table('user_notifications')->where('user_id', $student->id)->value('body');
        $this->assertStringContainsString('9:20 AM', $body, 'the student is shown institution-local time');
        $this->assertStringNotContainsString('6:20 AM', $body, 'and never the platform default');
    }

    public function test_the_email_shows_the_same_time_as_the_notification(): void
    {
        Mail::fake();

        // Make the email channel look configured so the code path actually
        // runs. Mail::fake() means nothing is really sent; this is about which
        // time the mailable is handed.
        foreach (['smtp_user' => 'u', 'smtp_pass' => 'p', 'smtp_host' => 'h', 'smtp_port' => '2525'] as $k => $v) {
            DB::table('global_settings')->insert(['key' => $k, 'value' => $v]);
        }

        $offering = $this->offeringFor($this->kampala);
        $student = $this->student('Kyeyune', $this->kampala, $offering);

        // A class one hour out, so the 1-hour reminder window applies. The
        // instant is absolute; the assertion below is that the email and the
        // in-app notification both render it in the institution's clock.
        $startsAt = now()->addHour();
        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->kampala, 'title' => 'Business Mathematics',
            'subject_id' => $offering->subject_id, 'course_offering_id' => $offering->id,
            'platform' => 'jitsi', 'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'Africa/Kampala',
            'scheduled_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour(),
            'start_date' => $startsAt->toDateString(), 'start_time' => $startsAt->format('H:i:s'),
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();
        $class = $class->fresh();

        \Illuminate\Support\Facades\Artisan::call('live-classes:send-reminders');

        $sent = Mail::sent(\App\Mail\LiveClassReminderEmail::class);
        $this->assertNotEmpty($sent, 'the reminder email must actually be attempted when SMTP is configured');

        $school = School::query()->find($this->kampala);
        $expected = app(TenantTimezone::class)->inTenantTime($class->fresh()->scheduled_at, $school)->format('H:i');
        $platformDefault = $class->fresh()->scheduled_at->setTimezone('UTC')->format('H:i');

        $mail = $sent->first();
        $this->assertSame($expected, $mail->data['time'],
            'the email shows the institution-local time');
        $this->assertNotSame($platformDefault, $mail->data['time'],
            'and not the platform default clock');

        // The in-app notification for the same class agrees with the email.
        // Filtered by type: the reminder run above also wrote a row for this
        // student, and this is about the "scheduled" announcement.
        \App\Support\LiveClasses\LiveClassNotifier::announcePublished($class->fresh());
        $body = (string) DB::table('user_notifications')
            ->where('user_id', $student->id)
            ->where('type', 'live_class_published')
            ->value('body');
        $this->assertStringContainsString(
            app(TenantTimezone::class)->inTenantTime($class->fresh()->scheduled_at, $school)->format('g:i A'),
            $body,
            'the in-app notification and the email state the same local time'
        );
    }

    // ══ §10 join window uses the real instant ══

    public function test_the_join_window_is_unaffected_by_the_display_timezone(): void
    {
        $offering = $this->offeringFor($this->kampala);
        $lecturer = $this->lecturer($this->kampala);
        $student = $this->student('Kyeyune', $this->kampala, $offering);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->kampala, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A class stored at 06:20 UTC is 09:20 in Kampala. The join window is
        // 15 minutes before that INSTANT, so at 06:00 UTC it must already be
        // open - the tenant's offset must not shift the arithmetic.
        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->kampala, 'title' => 'Business Mathematics',
            'subject_id' => $offering->subject_id, 'course_offering_id' => $offering->id,
            'teacher_id' => $lecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'Africa/Kampala',
            'scheduled_at' => Carbon::parse('2026-09-29 06:20:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 07:20:00', 'UTC'),
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        $this->assertTrue(
            $access->withinJoinWindow($class->fresh(), Carbon::parse('2026-09-29 06:10:00', 'UTC')),
            '15 minutes before the stored instant the window is open'
        );
        $this->assertFalse(
            $access->withinJoinWindow($class->fresh(), Carbon::parse('2026-09-29 06:00:00', 'UTC')),
            '16 minutes before it is not'
        );
        $this->assertFalse(
            $access->withinJoinWindow($class->fresh(), Carbon::parse('2026-09-29 06:10:00', 'Africa/Kampala')),
            'the same instant expressed in Kampala time is the same instant'
        );
    }

    // ══ §5 / §10 existing records are never corrupted ══

    public function test_existing_records_keep_their_instants_when_the_tenant_timezone_changes(): void
    {
        // A class written while the institution was on the platform default.
        $class = new LiveClass();
        $class->forceFill([
            'school_id' => $this->kampala, 'title' => 'Existing',
            'subject_id' => $this->subject, 'course_offering_id' => null,
            'platform' => 'jitsi', 'meeting_url' => 'https://meet.jit.si/room', 'timezone' => 'UTC',
            'scheduled_at' => Carbon::parse('2026-09-29 08:25:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 11:00:00', 'UTC'),
            'start_date' => '2026-09-29', 'start_time' => '08:25:00',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
        ])->save();
        $originalInstant = $class->fresh()->scheduled_at->toIso8601String();

        // The institution now configures its own timezone.
        DB::table('schools')->where('id', $this->kampala)->update(['timezone' => 'Africa/Kampala']);

        $after = $class->fresh();
        $this->assertSame($originalInstant, $after->scheduled_at->toIso8601String(),
            'the stored instant is untouched');
        $this->assertSame('08:25:00', $after->getRawOriginal('start_time'), 'the recorded wall-clock columns are untouched');
        $this->assertSame('UTC', $after->timezone, "the class's own timezone column records what it was scheduled in");

        // It is simply now READ in the institution's clock.
        $this->assertSame(
            '11:25',
            app(TenantTimezone::class)->inTenantTime($after->scheduled_at, School::query()->find($this->kampala))->format('H:i')
        );
    }
}
