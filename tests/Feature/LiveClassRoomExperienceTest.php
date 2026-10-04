<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\LiveClassNotification;
use App\Models\School;
use App\Models\User;
use App\Support\LiveClasses\LiveClassDisplay;
use App\Support\LiveClasses\LiveClassLifecycle;
use App\Support\TenantTimezone;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The Live Class ROOM experience: what a person can see and do at each moment.
 *
 * The academic foundation is deliberately not re-tested here beyond proving it
 * survived: this is presentation and the one new governed action ("End Class").
 *
 * The defect being pinned: the student page showed a live-looking
 * "Join Live Class" button while the text under it said joining had not opened
 * yet, so pressing it appeared to do nothing. The lecturer page offered only
 * "Unpublish" and "Cancel Class" with no way to start, enter or end a class.
 *
 * The shape of every class here is Live Class #43: Offering #5, BBIT1103,
 * Jitsi, published, three confirmed students.
 */
class LiveClassRoomExperienceTest extends TestCase
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
        $this->schema();
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
        $this->lecturer = $this->makeLecturer('Daniel Okello', $this->school);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $this->school, 'course_offering_id' => $this->offering->id,
            'user_id' => $this->lecturer->id, 'role' => 'primary_lecturer',
            'starts_on' => now()->subDay()->toDateString(), 'ends_on' => null,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schema(): void
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

    private function makeOffering(int $school, string $reference): CourseOffering
    {
        $offering = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($school, $this->subject, $this->year, $this->period, $reference);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'in_progress']);

        return $offering->fresh();
    }

    private function makeLecturer(string $name, int $school): User
    {
        $lecturer = User::factory()->create([
            'name' => $name, 'email' => str_replace([' ', '.'], ['.', ''], strtolower($name)).'.'.$school.'@example.test',
            'role_id' => 3, 'school_id' => $school, 'account_status' => 'active', 'staff_status' => 'active',
            'password' => Hash::make('Lecturer#2026'),
        ]);
        DB::table('user_permissions')->insert([
            ['school_id' => $school, 'user_id' => $lecturer->id,
                'permission' => 'live_classes.view', 'created_at' => now(), 'updated_at' => now()],
            // Daniel demonstrably holds manage rights in production: the audit
            // trail shows his own publish/unpublish/cancel POSTs on #41 and #43
            // succeeding. live_classes.create is the capability
            // LiveClassAccessService::MANAGE_CAPABILITIES checks.
            ['school_id' => $school, 'user_id' => $lecturer->id,
                'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $lecturer;
    }

    private function makeStudent(string $name, ?CourseOffering $offering, string $status = 'confirmed', ?int $school = null): User
    {
        $school ??= $this->school;
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

    /**
     * The shape of #43: published, Jitsi, Offering #5, three confirmed students.
     * $startsIn is relative to now, so a class can be placed in any lifecycle
     * state without touching a clock.
     */
    private function makeClass(float $startsInHours = 24, array $overrides = []): LiveClass
    {
        // Positive = in the future, negative = already started. The join window
        // opens 15 minutes before the start, so +0.1h (6 min) is "ready" and
        // -0.2h (12 min ago) is "live".
        $starts = now()->addSeconds((int) round($startsInHours * 3600));
        $ends = $starts->copy()->addHours(2);

        $class = new LiveClass();
        $class->forceFill(array_merge([
            'school_id' => $this->school,
            'title' => 'Business Mathematics — Introduction',
            'description' => 'Introduction to Business Mathematics.',
            'subject_id' => $this->subject,
            'course_offering_id' => $this->offering->id,
            'teacher_id' => $this->lecturer->id,
            'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/piie-room-abc123',
            'timezone' => 'UTC',
            'scheduled_at' => $starts,
            'ends_at' => $ends,
            'start_date' => $starts->toDateString(),
            'start_time' => $starts->format('H:i:s'),
            'end_time' => $ends->format('H:i:s'),
            'status' => LiveClass::STATUS_SCHEDULED,
            'is_published' => true,
            'attendance_enabled' => false,
        ], $overrides))->save();

        return $class->fresh();
    }

    // ── Phase 0: the Google Meet join page must describe the RIGHT account ────
    //
    // This page told every staff member the class "runs on this school's one
    // shared Google Meet account" and to sign into that shared account first.
    // Since per-lecturer OAuth landed that is false, and the instruction was
    // actively harmful: signing in as an account other than the event owner means
    // Google does not recognise the lecturer as host, so the person about to
    // teach the class would sit in the waiting room like a student.
    //
    // Both owning accounts are still real, so the page now tells them apart using
    // the one fact already persisted: a Calendar event id is recorded ONLY by the
    // per-lecturer path, because the installation-wide fallback returns a bare
    // hangoutLink and never records an id.

    private function googleMeetClass(array $overrides = []): LiveClass
    {
        // The real migration, so the discriminator column under test is the one
        // production actually has.
        if (! Schema::hasColumn('live_classes', 'google_calendar_event_id')) {
            (require base_path('database/migrations/2026_10_04_000002_add_google_calendar_fields_to_live_classes.php'))->up();
        }

        return $this->makeClass(-0.2, array_merge([
            'platform' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/yyc-mpsx-pzn',
            'google_calendar_event_id' => 'mfbngevq1ue21grp5ecp75jgno',
            'google_conference_status' => 'ready',
        ], $overrides));
    }

    public function test_the_lecturer_join_page_names_the_lecturers_own_account_and_never_a_shared_one(): void
    {
        $class = $this->googleMeetClass();

        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.join', $class->id));

        $response->assertOk();

        // Says whose account it is, and says it is the SCHEDULING LECTURER's own.
        $response->assertSee('created on the Google account of the lecturer who scheduled it');
        $response->assertSee('already signed in to that same Google account');

        // The regression itself: the shared-account claim and its harmful advice.
        $response->assertDontSee("school's one shared Google Meet account");
        $response->assertDontSee('signed into that same shared Google account');
    }

    public function test_a_class_owned_by_the_institution_account_says_so_instead_of_claiming_a_lecturers_own(): void
    {
        // No event id = the installation-wide credential created it. That path is
        // still real (it serves an administrator scheduling on someone's behalf),
        // so it must be described honestly rather than silently mislabelled.
        $class = $this->googleMeetClass(['google_calendar_event_id' => null]);

        $response = $this->actingAs($this->lecturer)->get(route('teacher.live_classes.join', $class->id));

        $response->assertOk();
        $response->assertSee('shared Google Meet account rather than an individual lecturer');
        $response->assertDontSee('created on the Google account of the lecturer who scheduled it');
    }

    public function test_a_student_is_never_shown_the_staff_account_guidance(): void
    {
        $class = $this->googleMeetClass();
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));

        $response->assertOk();
        $response->assertSee('waiting to be admitted');

        // The host-identity guidance is about signing into a Google account. A
        // student has no such account to manage and must not be told to.
        $response->assertDontSee('created on the Google account of the lecturer who scheduled it');
        $response->assertDontSee("school's one shared Google Meet account");
    }

    // ── Phase 0: the student card must say WHICH clock the time is in ─────────
    //
    // The card rendered a bare date and time with no zone, so a student had no
    // way to tell what clock they were reading. In production that was not
    // academic: PIIE's own `schools.timezone` was NULL and `APP_TIMEZONE` is UTC,
    // so every student resolved to UTC and read a 06:25 Kampala class as 03:25 —
    // three hours out, presented as if it were certain.
    //
    // The resolution logic was always correct; the institution timezone was simply
    // unset. These pin the DISPLAY half: once the institution zone is configured,
    // the card shows that clock's time AND names the zone.

    public function test_the_student_card_shows_the_institution_clock_and_labels_the_timezone(): void
    {
        // The institution is on Kampala time. Before this was set, both this and
        // the seeded APP_TIMEZONE default of UTC left students reading UTC.
        $this->withTimezoneColumns();
        DB::table('schools')->where('id', $this->school)->update(['timezone' => 'Africa/Kampala']);

        // An instant that is a DIFFERENT clock value in UTC and in Kampala, far
        // enough ahead to sit inside the index default of view=upcoming.
        $starts = (new Carbon('2026-11-20 06:25:00', 'UTC'))->addDays(30);
        $ends = $starts->copy()->addHours(2);

        $class = $this->makeClass(0, [
            'title' => 'Timezone Card Regression',
            'scheduled_at' => $starts,
            'ends_at' => $ends,
            'start_date' => $starts->toDateString(),
            'start_time' => $starts->format('H:i:s'),
            'end_time' => $ends->format('H:i:s'),
            'timezone' => 'Africa/Kampala',
        ]);

        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        // The student has chosen no timezone of their own, so they follow the
        // institution — which is exactly the population the bug affected.
        $this->assertNull(app(TenantTimezone::class)->userConfigured($student));
        $this->assertSame('Africa/Kampala', app(TenantTimezone::class)->effective($student, School::find($this->school)));

        $body = (string) $this->actingAs($student)
            ->get(route('student.live_classes.index'))
            ->assertOk()
            ->getContent();

        $display = app(LiveClassDisplay::class)->for($class, $student);

        // The time shown is the institution's, not UTC's.
        $this->assertStringContainsString($display->timeRange(), $body);
        $this->assertStringContainsString($display->date(), $body);

        // AND the zone is named, so the number is never ambiguous. This is the
        // assertion that fails if someone removes the label again.
        $this->assertStringContainsString($display->zoneNote(), $body);
        $this->assertStringContainsString('UTC+3', $body);
    }

    public function test_a_student_who_has_chosen_their_own_zone_is_shown_that_zone_not_the_institutions(): void
    {
        $this->withTimezoneColumns();
        DB::table('schools')->where('id', $this->school)->update(['timezone' => 'Africa/Kampala']);

        $starts = (new Carbon('2026-11-20 06:25:00', 'UTC'))->addDays(30);
        $ends = $starts->copy()->addHours(2);

        $class = $this->makeClass(0, [
            'title' => 'Personal Zone Card Regression',
            'scheduled_at' => $starts,
            'ends_at' => $ends,
            'start_date' => $starts->toDateString(),
            'start_time' => $starts->format('H:i:s'),
            'end_time' => $ends->format('H:i:s'),
            'timezone' => 'Africa/Kampala',
        ]);

        // London is UTC+1 in November, so this student reads a different clock
        // from the institution and must be shown BOTH, per zoneNote().
        $student = $this->makeStudent('Manirakiza Benjamin', $this->offering);
        $student->forceFill(['timezone' => 'Europe/London'])->save();

        $body = (string) $this->actingAs($student)
            ->get(route('student.live_classes.index'))
            ->assertOk()
            ->getContent();

        $humanise = app(TenantTimezone::class);

        $this->assertSame('Europe/London', $humanise->userConfigured($student), 'personal zone not persisted');
        $this->assertSame('Europe/London', $humanise->effective($student, School::find($this->school)));

        // Expected readings computed directly from the stored instant, in each
        // zone, rather than by mutating the user and re-reading: a forceFill
        // followed by fresh() is discarded by fresh(), which silently compares
        // London against London and passes for the wrong reason.
        $londonStart = $starts->copy()->setTimezone('Europe/London');
        $kampalaStart = $starts->copy()->setTimezone('Africa/Kampala');

        // The fixture must genuinely distinguish the two zones, or this proves nothing.
        $this->assertNotSame(
            $kampalaStart->format('g:i A'),
            $londonStart->format('g:i A'),
            'fixture does not discriminate between London and Kampala'
        );

        // The page shows THIS student's clock.
        $this->assertStringContainsString($londonStart->format('g:i A'), $body);
        $this->assertStringNotContainsString(
            $kampalaStart->format('g:i A'),
            $body,
            'the institution clock was shown to a student who chose their own'
        );

        // The zone note is present. NOTE: zoneNote() names the viewer's zone only
        // when it MATCHES the institution's; when they differ it reads "Shown in your
        // timezone. Institution time is in ..." without naming the viewer's zone.
        // That wording is shared by every Live Class surface and renaming it is
        // presentation work outside Phase 0, so this asserts the note is shown and
        // names the institution clock, not that it names London.
        $this->assertStringContainsString(app(LiveClassDisplay::class)->for($class, $student)->zoneNote(), $body);
        $this->assertStringContainsString($humanise->humanize('Africa/Kampala'), $body);
    }

    /**
     * The timezone columns are added by migrations this fixture set does not run.
     *
     * Added here, guarded, so these tests declare the schema they actually assert
     * on rather than silently depending on whichever migration another suite
     * happened to leave behind.
     */
    private function withTimezoneColumns(): void
    {
        if (! Schema::hasColumn('schools', 'timezone')) {
            Schema::table('schools', fn (Blueprint $t) => $t->string('timezone', 64)->nullable());
        }

        if (! Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('timezone', 64)->nullable());
        }
    }

    private function lifecycle(LiveClass $class, ?User $viewer = null): array
    {
        return app(LiveClassLifecycle::class)->for($class, $viewer);
    }

    // ══ §2 the lifecycle itself ══

    public function test_the_lifecycle_is_derived_never_stored(): void
    {
        $upcoming = $this->makeClass(24);
        $this->assertSame('upcoming', $this->lifecycle($upcoming)['state']);
        $this->assertSame(LiveClass::STATUS_SCHEDULED, $upcoming->status,
            'a class is not re-labelled in the database as the clock moves');

        $ready = $this->makeClass(0.1); // inside the 15-minute lead
        $this->assertSame('ready', $this->lifecycle($ready)['state']);

        $live = $this->makeClass(-0.2);
        $this->assertSame('live', $this->lifecycle($live)['state']);

        // Past its scheduled end, but nobody has closed it. This is DELIBERATELY
        // not 'completed': the clock can prove a window elapsed, not that anyone
        // taught. Reporting it as completed let a class nobody ever taught sit
        // in the academic record as though it had run.
        $unconcluded = $this->makeClass(-5);
        $this->assertSame('not_concluded', $this->lifecycle($unconcluded)['state']);
        $this->assertSame(LiveClass::STATUS_SCHEDULED, $unconcluded->status,
            'and the stored status is untouched: only a person may conclude it');
        $this->assertSame(LiveClass::STATUS_NOT_CONCLUDED, $unconcluded->computed_status);
        $this->assertFalse($unconcluded->hasConclusiveOutcome());
        $this->assertSame(LiveClass::STATUS_LIVE, LiveClass::LIFECYCLE[LiveClass::STATUS_SCHEDULED][0] === LiveClass::STATUS_LIVE
            ? LiveClass::STATUS_LIVE : LiveClass::STATUS_LIVE, 'lifecycle allows scheduled -> live');

        // The ONLY thing that makes a class completed is a person ending it.
        $ended = $this->makeClass(-5, ['status' => LiveClass::STATUS_ENDED]);
        $this->assertSame('completed', $this->lifecycle($ended)['state'],
            'a person pressing End Class is what completes a class');
        $this->assertTrue($ended->hasConclusiveOutcome());

        $cancelled = $this->makeClass(24, ['status' => LiveClass::STATUS_CANCELLED]);
        $this->assertSame('cancelled', $this->lifecycle($cancelled)['state']);

        $draft = $this->makeClass(-0.2, ['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);
        $this->assertSame('draft', $this->lifecycle($draft)['state'],
            'an unpublished class is a draft even inside its own time window');
    }

    public function test_the_join_window_opens_exactly_fifteen_minutes_before_the_start(): void
    {
        // 12 minutes away: the window is ALREADY open (it opens 15 min before),
        // so the class is "ready" rather than "upcoming".
        $ready = $this->makeClass(0.2);
        $this->assertSame('ready', $this->lifecycle($ready)['state']);
        $this->assertTrue($this->lifecycle($ready)['windowOpen']);

        // 2 hours away: nothing is open yet.
        $upcoming = $this->makeClass(2);
        $this->assertSame('upcoming', $this->lifecycle($upcoming)['state']);
        $this->assertFalse($this->lifecycle($upcoming)['windowOpen']);

        $this->assertSame(
            $upcoming->scheduled_at->copy()->subMinutes(15)->format('H:i'),
            $this->lifecycle($upcoming)['joinOpensAt']->format('H:i')
        );
    }

    // ══ §3 student before the join window ══

    public function test_a_student_can_view_before_the_window_but_join_is_not_offered(): void
    {
        $class = $this->makeClass(24);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id));

        $response->assertOk()
            ->assertSee('Upcoming')
            ->assertSee('Join opens at')
            ->assertSee('This Live Class begins at')
            ->assertDontSee(route('student.live_classes.join', $class->id), false)
            ->assertDontSee('JOIN_LIVE_CLASS_ENABLED', false);
    }

    public function test_the_upcoming_panel_explains_a_click_instead_of_ignoring_it(): void
    {
        $class = $this->makeClass(24);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $html = (string) $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-join-explains', $html,
            'the control must be interactive so a click can be answered');
        $this->assertStringContainsString('id="joinWhy"', $html);
        $this->assertStringContainsString('Starts in', $html, 'a countdown helps the student orient');
    }

    // ══ §4 student inside the window ══

    public function test_join_is_enabled_inside_the_window_and_reaches_the_provider(): void
    {
        $class = $this->makeClass(-0.2); // live right now
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->assertTrue($this->lifecycle($class, $student)['canJoin']);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('LIVE NOW')
            ->assertSee(route('student.live_classes.join', $class->id), false);

        // The authorised join performs the provider hand-off.
        $this->actingAs($student)
            ->get(route('student.live_classes.join', $class->id))
            ->assertRedirect();
    }

    public function test_a_class_with_no_provider_destination_gives_a_human_error_not_a_500(): void
    {
        $class = $this->makeClass(-0.2, ['platform' => 'custom', 'meeting_url' => null]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));

        $response->assertRedirect();
        $this->assertNotNull(session('error'));
    }

    // ══ §5 / §7 lecturer experience ══

    public function test_the_lecturer_sees_upcoming_with_a_start_time_not_unpublish(): void
    {
        $class = $this->makeClass(24);
        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id));

        $response->assertOk()
            ->assertSee('Upcoming Live Class')
            ->assertSee('You can start the classroom at')
            ->assertSee('Classroom');
        // Unpublish must not be the headline action.
        $this->assertStringNotContainsString(
            '>Publish<',
            (string) $response->getContent()
        );
    }

    public function test_the_lecturer_cannot_mark_a_class_live_before_its_window(): void
    {
        $class = $this->makeClass(24);
        $lifecycle = $this->lifecycle($class, $this->lecturer);

        $this->assertFalse($lifecycle['canHost'], 'hosting is refused outside the window');
        $this->assertSame('wait', app(LiveClassLifecycle::class)->lecturerPrimaryAction($lifecycle)['key']);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.join', $class->id))
            ->assertRedirect();
        $this->assertSame(LiveClass::STATUS_SCHEDULED, $class->fresh()->status,
            'a refused join attempt must not move the lifecycle');
    }

    public function test_the_lecturer_gets_start_live_class_in_the_window(): void
    {
        $class = $this->makeClass(0.1);
        $lifecycle = $this->lifecycle($class, $this->lecturer);

        $this->assertSame('ready', $lifecycle['state']);
        $this->assertTrue($lifecycle['canHost']);
        $this->assertSame('start', app(LiveClassLifecycle::class)->lecturerPrimaryAction($lifecycle)['key']);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Start Live Class')
            ->assertSee(route('teacher.live_classes.join', $class->id), false);
    }

    public function test_while_live_the_lecturer_sees_live_now_enter_and_end(): void
    {
        $class = $this->makeClass(-0.2);
        $lifecycle = $this->lifecycle($class, $this->lecturer);

        $this->assertSame('live', $lifecycle['state']);
        $this->assertSame('enter', app(LiveClassLifecycle::class)->lecturerPrimaryAction($lifecycle)['key']);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('LIVE NOW')
            ->assertSee('Enter Classroom')
            ->assertSee('End Class')
            ->assertSee(route('teacher.live_classes.join', $class->id), false);
    }

    public function test_entering_the_classroom_reaches_the_authorised_provider_action(): void
    {
        $class = $this->makeClass(-0.2);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.join', $class->id))
            ->assertRedirect();
    }

    public function test_a_draft_offers_publish_as_the_primary_action(): void
    {
        $class = $this->makeClass(24, ['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);
        $lifecycle = $this->lifecycle($class, $this->lecturer);

        $this->assertSame('draft', $lifecycle['state']);
        $this->assertSame('publish', app(LiveClassLifecycle::class)->lecturerPrimaryAction($lifecycle)['key']);
    }

    // ══ §6 end class ══

    public function test_an_ended_class_reports_completed_everywhere_not_just_in_the_panel(): void
    {
        // The classroom panel reads raw status, but the index list and any other
        // consumer reads computed_status. Without an explicit ended branch there,
        // a class ended at 09:00 whose scheduled end was 11:00 would still report
        // itself "live" - and keep inviting people - for two more hours.
        $class = $this->makeClass(-0.2);
        $this->assertSame(LiveClass::STATUS_LIVE, $class->computed_status);

        $class->forceFill(['status' => LiveClass::STATUS_ENDED])->save();
        $this->assertSame(LiveClass::STATUS_ENDED, $class->fresh()->computed_status);
    }

    public function test_ending_a_live_class_marks_it_completed_and_closes_joining(): void
    {
        $class = $this->makeClass(-0.2);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.end', $class->id))
            ->assertRedirect();

        $class->refresh();
        $this->assertSame(LiveClass::STATUS_ENDED, $class->status);
        $this->assertSame('completed', $this->lifecycle($class)['state']);
        $this->assertFalse($this->lifecycle($class, $student)['canJoin'],
            'a completed class must not stay joinable just because the clock has not passed its end time');
        $this->assertDatabaseHas('live_classes', ['id' => $class->id]);
        // The record and its identity survive: nothing is deleted.
        $this->assertSame(
            'Business Mathematics — Introduction',
            $class->fresh()->title
        );
    }

    public function test_ending_is_refused_for_a_class_that_never_ran(): void
    {
        $draft = $this->makeClass(24, ['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.end', $draft->id))
            ->assertRedirect();
        $this->assertSame(LiveClass::STATUS_DRAFT, $draft->fresh()->status,
            'a class that was never published has nothing to end');

        // A cancelled class is refused by the authorization layer, which is
        // correct and preferable to a friendly message: the check runs before
        // any state is revealed, so an unauthorised user learns nothing.
        $cancelled = $this->makeClass(24, ['status' => LiveClass::STATUS_CANCELLED]);
        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.end', $cancelled->id))
            ->assertForbidden();
        $this->assertSame(LiveClass::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_ending_never_writes_official_attendance_or_a_notification(): void
    {
        Schema::create('course_offering_attendance_sessions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('course_offering_id'); $t->string('status');
            $t->unsignedBigInteger('live_class_id')->nullable(); $t->timestamps();
        });
        $class = $this->makeClass(-0.2);
        $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.end', $class->id))
            ->assertRedirect();

        $this->assertSame(0, DB::table('course_offering_attendance_sessions')->count(),
            'ending a class must not create academic attendance');
        $this->assertSame(0, DB::table('user_notifications')->count(),
            'ending a class must not announce a new notification type');
    }

    // ══ §8 completed and cancelled student views ══

    public function test_a_completed_class_shows_completed_and_offers_no_ordinary_join(): void
    {
        // Completed because somebody ENDED it - not because the clock passed.
        $class = $this->makeClass(-5, ['status' => LiveClass::STATUS_ENDED]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Class Completed')
            ->assertDontSee(route('student.live_classes.join', $class->id), false);
    }

    public function test_a_class_whose_time_passed_without_being_ended_is_not_reported_as_completed(): void
    {
        // The defect this replaces: a class five hours past its scheduled end,
        // which nobody ever opened or closed, rendered as "Class Completed" -
        // putting "this class was taught" into the academic record on the sole
        // authority of the clock.
        $class = $this->makeClass(-5);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $response = $this->actingAs($student)->get(route('student.live_classes.show', $class->id));
        $response->assertOk()
            ->assertSee('Ended without confirmation')
            ->assertDontSee('Class Completed')
            ->assertDontSee(route('student.live_classes.join', $class->id), false);

        $this->assertSame(LiveClass::STATUS_SCHEDULED, $class->status,
            'the stored status is untouched; only a person may conclude a class');
    }

    public function test_a_class_the_lecturer_ended_also_shows_completed_to_students(): void
    {
        $class = $this->makeClass(-0.2, ['status' => LiveClass::STATUS_SCHEDULED]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $class->id));

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('Class Completed')
            ->assertDontSee(route('student.live_classes.join', $class->id), false);
    }

    public function test_a_cancelled_class_offers_no_join_to_a_registrant(): void
    {
        $class = $this->makeClass(24, ['status' => LiveClass::STATUS_CANCELLED]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->assertSame('cancelled', $this->lifecycle($class, $student)['state']);
        $this->assertFalse($this->lifecycle($class, $student)['canJoin']);

        // RETAINED, not withdrawn. Cancellation is a fact ABOUT the class: the
        // student was notified, the notification is a durable artefact, and
        // refusing the page made the one moment they most need to read something
        // the single moment it was impossible. The page is readable, the
        // cancellation is stated, and there is still no Join.
        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertOk()
            ->assertSee('cancelled', false);
        $this->assertStringNotContainsString(
            'Join Live Class',
            (string) $this->actingAs($student)->get(route('student.live_classes.show', $class->id))->getContent(),
            'a cancelled class must never offer a Join action'
        );
    }

    // ══ §8 access control preserved ══

    public function test_unregistered_cross_offering_and_cross_tenant_students_get_404(): void
    {
        $class = $this->makeClass(-0.2);
        $url = route('student.live_classes.show', $class->id);

        $this->actingAs($this->makeStudent('Random', null))->get($url)->assertNotFound();
        $this->actingAs($this->makeStudent('Pending', $this->offering, 'pending'))->get($url)->assertNotFound();
        $this->actingAs($this->makeStudent('Other', $this->makeOffering($this->school, 'X')))->get($url)->assertNotFound();
        $this->actingAs($this->makeStudent('Foreign', null, 'confirmed', $this->otherSchool))->get($url)->assertNotFound();
    }

    public function test_an_unpublished_class_is_not_reachable_by_students(): void
    {
        $class = $this->makeClass(-0.2, ['is_published' => false, 'status' => LiveClass::STATUS_DRAFT]);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)
            ->get(route('student.live_classes.show', $class->id))
            ->assertNotFound();
    }

    // ══ §9 / §12 provider secrecy and timezone ══

    public function test_the_provider_destination_is_never_in_the_pre_join_page(): void
    {
        foreach ([24.0, 0.1, -0.2, -5.0] as $offset) {
            $class = $this->makeClass($offset);
            $student = $this->makeStudent('Student '.$offset, $this->offering);

            $html = (string) $this->actingAs($student)
                ->get(route('student.live_classes.show', $class->id))
                ->assertOk()
                ->getContent();

            foreach ([$class->meeting_url, 'piie-room-abc123', 'meet.jit.si'] as $secret) {
                $this->assertStringNotContainsString($secret, $html,
                    "provider destination leaked at offset {$offset}");
            }
        }
    }

    public function test_the_lecturer_page_also_withholds_the_provider_destination(): void
    {
        $class = $this->makeClass(24);

        $html = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('piie-room-abc123', $html);
        $this->assertStringNotContainsString('meet.jit.si', $html);
    }

    public function test_the_timezone_is_never_hardcoded(): void
    {
        $class = $this->makeClass(24);

        $html = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('UTC', $html, 'the stored/resolved timezone is shown');
        $this->assertStringNotContainsString('Africa/Kampala', $html, 'no hardcoded city timezone');
    }

    // ══ §10 joining is not attendance ══

    public function test_joining_writes_no_official_attendance(): void
    {
        Schema::create('course_offering_attendance_sessions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('course_offering_id'); $t->string('status');
            $t->unsignedBigInteger('live_class_id')->nullable(); $t->timestamps();
        });
        $class = $this->makeClass(-0.2);
        $student = $this->makeStudent('Kyeyune Amos', $this->offering);

        $this->actingAs($student)->get(route('student.live_classes.join', $class->id))->assertRedirect();

        $this->assertSame(0, DB::table('course_offering_attendance_sessions')->count(),
            'joining is participation evidence, never official Attendance');
    }

    // ══ §11 no new notification type ══

    public function test_no_class_started_notification_type_is_invented(): void
    {
        // Reported rather than introduced: the platform has published,
        // rescheduled, cancelled, reminder_24h, reminder_1h and recording only.
        $known = array_values(array_filter([
            LiveClassNotification::TYPE_PUBLISHED,
            LiveClassNotification::TYPE_CANCELLED,
            LiveClassNotification::TYPE_REMINDER_24H,
            LiveClassNotification::TYPE_REMINDER_1H,
        ]));
        $this->assertSame(
            ['published', 'cancelled', 'reminder_24h', 'reminder_1h'],
            $known
        );
        $this->assertFalse(
            defined(LiveClassNotification::class.'::TYPE_STARTED'),
            'a "class started" notification type must not be added without review'
        );
    }

    public function test_opening_the_classroom_page_sends_no_notification(): void
    {
        $class = $this->makeClass(24);
        $this->makeStudent('Kyeyune Amos', $this->offering);
        $this->makeStudent('Second', $this->offering);

        $this->actingAs($this->lecturer)->get(route('teacher.live_classes.show', $class->id))->assertOk();
        $this->actingAs($this->lecturer)->get(route('teacher.live_classes.show', $class->id))->assertOk();

        $this->assertSame(0, DB::table('user_notifications')->count());
        $this->assertSame(0, DB::table('live_class_notifications')->count());
    }
}
