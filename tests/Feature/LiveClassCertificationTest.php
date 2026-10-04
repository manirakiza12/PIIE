<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\School;
use App\Models\User;
use App\Support\LiveClasses\LiveClassAccessService;
use App\Support\LiveClasses\LiveClassDisplay;
use App\Support\LiveClasses\LiveClassLifecycle;
use App\Support\LiveClasses\LiveClassPlatform;
use App\Support\LiveClasses\LiveClassService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * One test per reported defect, each naming the defect it closes.
 *
 * The class exists to hold the real-world findings from browser testing of
 * Course Offering Live Classes, so every test states the failure it prevents in
 * its own name and, where the old behaviour was a false CLAIM rather than a
 * crash, asserts the absence of that claim.
 */
class LiveClassCertificationTest extends TestCase
{
    use \Tests\Feature\Support\LiveClassFixture { setUp as protected fixtureSetUp; }
    use \Tests\Feature\Support\FrozenClock;

    protected function setUp(): void
    {
        // Frozen BEFORE the fixture builds its rows, so every timestamp the
        // fixture writes belongs to the same scenario date as the assertions.
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00', 'UTC'));
        $this->fixtureSetUp();
        $this->freezeClock();
    }

    public function test_an_offering_counts_the_live_classes_that_exist_even_before_the_allocation_start_date(): void
    {
        // The real piie_main situation, reproduced exactly: an allocation whose
        // agreed start is two days away, on a deliberately early-started
        // Offering, held by a lecturer carrying the pre-start testing grant.
        // The classes are already taught and owned - the classes are the
        // lecturer's own work - and the dashboard said zero.
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $this->offering->id)
            ->where('user_id', $this->lecturer->id)
            ->update(['starts_on' => now()->addDays(2)->toDateString()]);
        DB::table('user_permissions')->insert([
            'school_id' => $this->school, 'user_id' => $this->lecturer->id,
            'permission' => 'system.testing.prestart_lecturer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The grant alone is not enough, and deliberately so: the relaxation only
        // applies to an Offering somebody deliberately early-started. This is the
        // evidence CourseOfferingService::startEarly() leaves behind, and it is
        // present in piie_main for exactly this Offering.
        DB::table('audit_logs')->insert([
            'school_id' => $this->school, 'user_id' => $this->lecturer->id,
            'action' => \App\Support\CourseOffering\SystemTesterAccess::EARLY_START_AUDIT,
            'module' => 'Course Offerings', 'description' => 'Early start',
            'record_type' => CourseOffering::class, 'record_id' => $this->offering->id,
            'ip_address' => '127.0.0.1', 'created_at' => now(),
        ]);

        $this->class();
        $this->class();
        $this->class(['status' => LiveClass::STATUS_CANCELLED]);

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.show', $this->offering->id));
        $response->assertOk();

        $counts = $response->viewData('liveClassCounts');
        $this->assertSame(3, $counts['total'], 'the classes exist, so the counter must not read zero');
        $this->assertSame(3, $response->viewData('liveClasses')->count(),
            'and the count and the list are the same fact');
        $this->assertGreaterThan(0, array_sum($counts['parts']),
            'the breakdown makes the total checkable rather than a bare number');
    }

    public function test_the_offering_counter_excludes_classes_from_other_offerings_and_legacy_k12(): void
    {
        $other = app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->createDraft($this->school, $this->subject, $this->year, $this->period, 'OTHER-1');
        DB::table('course_offerings')->where('id', $other->id)->update(['status' => 'in_progress']);

        $this->class();
        // A legacy K12 class: no Offering at all.
        (new LiveClass())->forceFill([
            'school_id' => $this->school, 'title' => 'K12 Form 1', 'platform' => 'jitsi',
            'timezone' => 'UTC', 'status' => LiveClass::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        ])->save();
        // A class on a different Offering.
        (new LiveClass())->forceFill([
            'school_id' => $this->school, 'title' => 'Other offering', 'platform' => 'jitsi',
            'course_offering_id' => $other->id, 'subject_id' => $this->subject,
            'teacher_id' => $this->lecturer->id, 'timezone' => 'UTC',
            'status' => LiveClass::STATUS_SCHEDULED,
            'scheduled_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(),
        ])->save();

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.show', $this->offering->id));

        $this->assertSame(1, $response->viewData('liveClassCounts')['total'],
            'only this Offering\'s own class counts - not legacy K12, not another Offering');
    }

    // ══════════════ DEFECT 2: index/history filters ══════════════

    public function test_every_quick_filter_returns_the_classes_it_names(): void
    {
        $this->class(['scheduled_at' => now()->addDay(), 'status' => LiveClass::STATUS_SCHEDULED]);
        $this->class(['scheduled_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'status' => LiveClass::STATUS_LIVE]);
        $this->class(['status' => LiveClass::STATUS_CANCELLED]);
        $this->class(['status' => LiveClass::STATUS_ENDED]);

        // 'upcoming' is deliberately coarse: it holds everything not yet concluded
        // and not yet finished, so a currently-LIVE class appears in it too.
        foreach (['upcoming' => 2, 'live' => 1, 'cancelled' => 1, 'completed' => 1] as $view => $expected) {
            $seen = $this->actingAs($this->lecturer)
                ->get(route('teacher.live_classes.index', ['view' => $view]))
                ->viewData('classes');
            $this->assertCount($expected, $seen, "the {$view} tab returns the classes it names");
        }

        $all = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'all']))
            ->viewData('classes');
        $this->assertCount(4, $all);
    }

    /**
     * "Completed" used to also match anything whose end time had passed, so a
     * class nobody ever taught was filed as completed on the clock's authority
     * alone. Completed now means exactly one thing: a person ended it.
     */
    public function test_completed_means_a_person_ended_it_not_that_the_clock_passed(): void
    {
        $neverEnded = $this->class([
            'scheduled_at' => now()->subHours(3), 'ends_at' => now()->subHours(2),
            'status' => LiveClass::STATUS_SCHEDULED,
        ]);

        $seen = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'completed']))
            ->viewData('classes');

        $this->assertCount(0, $seen,
            'a class whose time passed is not completed until somebody says so');
        $this->assertNotContains($neverEnded->id, $seen->pluck('id')->all());
    }

    public function test_the_empty_state_names_the_filter_that_was_applied(): void
    {
        // No cancelled classes exist at all.
        $this->class();

        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'cancelled']))
            ->getContent();

        $this->assertStringContainsString('No cancelled live classes', $body);
        $this->assertStringNotContainsString('No live classes scheduled', $body,
            'telling a lecturer they have no live classes when they have some is the defect');
    }

    public function test_real_cancelled_classes_are_listed_under_cancelled(): void
    {
        $this->class();
        $this->class(['status' => LiveClass::STATUS_CANCELLED]);
        $this->class(['status' => LiveClass::STATUS_CANCELLED]);

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'cancelled']));
        $response->assertOk();
        $this->assertCount(2, $response->viewData('classes'));
    }

    // ══════════════ DEFECT 3: cancelled student detail must be retained ══════════════

    public function test_a_cancelled_class_stays_readable_by_a_confirmed_registrant(): void
    {
        $class = $this->class();
        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));
        $cancelled = $class->fresh();
        $this->assertSame(LiveClass::STATUS_CANCELLED, $cancelled->status);

        $access = app(LiveClassAccessService::class);
        $this->assertTrue($access->canStudentViewClass($this->student, $cancelled),
            'cancellation withdraws the join, not the record');
        $this->assertFalse($access->canStudentJoin($this->student, $cancelled),
            'and a cancelled class can never be joined');

        $response = $this->actingAs($this->student)->get(route('student.live_classes.show', $cancelled->id));
        $response->assertOk()
            ->assertSee('was cancelled', false)
            ->assertSee('Business Mathematics')
            ->assertDontSee(route('student.live_classes.join', $cancelled->id), false);
    }

    public function test_a_cancelled_class_still_denies_a_student_who_was_never_confirmed(): void
    {
        $class = $this->class();
        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));

        $stranger = $this->user('Unregistered Stranger', 7);

        $this->actingAs($stranger)
            ->get(route('student.live_classes.show', $class->fresh()->id))
            ->assertNotFound();
    }

    public function test_every_notification_event_leads_to_a_page_the_recipient_can_open(): void
    {
        $class = $this->class();

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));
        $cancelled = $class->fresh();

        $rows = DB::table('user_notifications')->where('user_id', $this->student->id)->get();
        $this->assertNotEmpty($rows, 'the cancellation was announced');

        foreach ($rows as $row) {
            $this->assertStringContainsString(
                (string) route('student.live_classes.show', $cancelled->id),
                (string) $row->url,
                'a notification must never point somewhere its own event made unreachable'
            );
        }

        $this->actingAs($this->student)->get(route('student.live_classes.show', $cancelled->id))->assertOk();
    }

    // ══════════════ DEFECT 4: authoritative lifecycle ══════════════

    public function test_the_documented_lifecycle_is_the_one_the_code_enforces(): void
    {
        $this->assertSame([
            LiveClass::STATUS_DRAFT,
            LiveClass::STATUS_SCHEDULED,
            LiveClass::STATUS_LIVE,
            LiveClass::STATUS_ENDED,
            LiveClass::STATUS_CANCELLED,
        ], array_keys(LiveClass::LIFECYCLE));

        $draft = $this->class(['status' => LiveClass::STATUS_DRAFT]);
        $this->assertTrue($draft->canTransitionTo(LiveClass::STATUS_SCHEDULED));
        $this->assertTrue($draft->canTransitionTo(LiveClass::STATUS_CANCELLED));
        $this->assertFalse($draft->canTransitionTo(LiveClass::STATUS_LIVE), 'a draft is never live');
        $this->assertFalse($draft->canTransitionTo(LiveClass::STATUS_ENDED), 'a draft is never completed');

        $scheduled = $this->class();
        $this->assertTrue($scheduled->canTransitionTo(LiveClass::STATUS_LIVE));
        $this->assertTrue($scheduled->canTransitionTo(LiveClass::STATUS_ENDED));
        $this->assertFalse($scheduled->canTransitionTo(LiveClass::STATUS_DRAFT), 'no going back to draft');

        $ended = $this->class(['status' => LiveClass::STATUS_ENDED]);
        $this->assertSame([], $ended->allowedTransitions(), 'completed is terminal');
        $cancelled = $this->class(['status' => LiveClass::STATUS_CANCELLED]);
        $this->assertSame([], $cancelled->allowedTransitions(), 'cancelled is terminal');
    }

    public function test_ready_to_start_is_reached_only_inside_the_join_lead_time(): void
    {
        $lifecycle = app(LiveClassLifecycle::class);
        $class = $this->class();

        $state = fn (Carbon $now) => $lifecycle->state(
            $class, $class->scheduled_at->copy(),
            $class->scheduled_at->copy()->subMinutes(15),
            $class->ends_at->copy(), $now
        );

        $this->assertSame('upcoming', $state($class->scheduled_at->copy()->subMinutes(20)));
        $this->assertSame('ready', $state($class->scheduled_at->copy()->subMinutes(10)));
        $this->assertSame('live', $state($class->scheduled_at->copy()->addMinutes(1)));
    }

    public function test_starting_and_ending_are_recorded_with_who_and_when(): void
    {
        $startsAt = now()->subMinutes(2);
        $class = $this->class(['scheduled_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHour()]);

        $this->actingAs($this->lecturer)->get(route('teacher.live_classes.join', $class->id));

        $opened = $class->fresh();
        $this->assertNotNull($opened->started_at, 'opening the classroom is authoritative evidence');
        $this->assertSame($this->lecturer->id, $opened->started_by);
        $this->assertTrue($opened->hasStartEvidence());

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $class->id));

        $ended = $class->fresh();
        $this->assertSame(LiveClass::STATUS_ENDED, $ended->status);
        $this->assertNotNull($ended->ended_at);
        $this->assertSame($this->lecturer->id, $ended->ended_by);
        $this->assertTrue($ended->hasEndEvidence());
    }

    public function test_ending_twice_cannot_rewrite_who_ended_the_class_or_when(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_LIVE]);
        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $class->id));

        $first = $class->fresh();
        $this->assertNotNull($first->ended_at);

        $this->actingAs($this->otherLecturer)->post(route('teacher.live_classes.end', $class->id));

        $second = $class->fresh();
        $this->assertEquals($first->ended_at->timestamp, $second->ended_at->timestamp,
            'the original conclusion stands');
        $this->assertSame($first->ended_by, $second->ended_by);
    }

    // ══════════════ DEFECT 5: host vs participant authority ══════════════

    public function test_a_participant_can_never_be_told_they_are_the_provider_moderator(): void
    {
        $class = $this->class();
        $platform = app(LiveClassPlatform::class);
        $described = $platform->describe($class, false);

        $this->assertTrue($described['moderation_by_provider'],
            'Jitsi decides moderation, not PIIE');
        $this->assertStringContainsString(
            'not make them a Jitsi moderator',
            implode(' ', $described['limitations']),
            'the page must not claim PIIE authorisation makes a lecturer a Jitsi moderator'
        );
        $this->assertStringNotContainsString('meeting_password', implode(' ', $described['limitations']));
    }

    public function test_a_provider_that_issues_a_host_link_says_so_and_a_public_one_does_not(): void
    {
        $platform = app(LiveClassPlatform::class);

        $zoom = $platform->describe($this->class(['platform' => 'zoom']), true);
        $this->assertTrue($zoom['separate_host_url']);
        $this->assertFalse($zoom['moderation_by_provider']);

        $jitsi = $platform->describe($this->class(['platform' => 'jitsi']), true);
        $this->assertFalse($jitsi['separate_host_url'],
            'a public Jitsi room issues no separate moderator link to report');
        $this->assertTrue($jitsi['moderation_by_provider']);
    }

    public function test_a_lecturer_is_offered_the_classroom_only_while_the_window_is_open(): void
    {
        $access = app(LiveClassAccessService::class);

        $early = $this->class(['scheduled_at' => now()->addHours(2), 'ends_at' => now()->addHours(3)]);
        $this->assertFalse($access->canLecturerHost($this->lecturer, $early),
            'no classroom before the window');

        $open = $this->class(['scheduled_at' => now()->subMinutes(5), 'ends_at' => now()->addHour()]);
        $this->assertTrue($access->canLecturerHost($this->lecturer, $open));

        $unauthorised = $this->user('Unallocated Lecturer', 3);
        DB::table('user_permissions')->insert([
            ['school_id' => $this->school, 'user_id' => $unauthorised->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertFalse($access->canLecturerHost($unauthorised, $open),
            'allocation, not capability alone, grants hosting');
    }

    public function test_provider_secrets_never_reach_a_student_page(): void
    {
        $class = $this->class([
            'meeting_password' => 'super-secret-moderator-pass',
            'meeting_id' => 'room-42',
        ]);

        $body = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.show', $class->id))->getContent();

        $this->assertStringNotContainsString('super-secret-moderator-pass', $body);
        $this->assertStringNotContainsString('room-42', $body);
    }

    // ══════════════ DEFECT 6: cancellation semantics ══════════════

    public function test_cancelling_states_that_piie_stopped_joins_without_claiming_the_provider_was_ended(): void
    {
        $now = now();
        Carbon::setTestNow($now);
        $class = $this->class([
            'scheduled_at' => $now->copy()->subMinutes(10),
            'ends_at' => $now->copy()->addHour(),
            'status' => LiveClass::STATUS_LIVE,
        ]);

        $response = $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));
        $response->assertSessionHas('success');

        $message = (string) session('success');
        $this->assertStringContainsString('No further joins are possible through PIIE', $message);
        $this->assertStringContainsString('PIIE has not ended it', $message,
            'PIIE must not claim it closed a conference on someone else\'s server');
        $this->assertStringContainsString('only a moderator of that meeting can do', $message);

        Carbon::setTestNow();
    }

    public function test_cancelling_a_class_that_never_opened_does_not_claim_a_provider_conference(): void
    {
        $class = $this->class(['scheduled_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));

        $this->assertStringNotContainsString(
            'only a moderator of that meeting can do',
            (string) session('success'),
            'a class that never opened had no conference to end'
        );
    }

    // ══════════════ DEFECT 7: completion ══════════════

    public function test_a_completed_class_stays_in_history_and_students_can_still_read_it(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_LIVE]);
        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $class->id));

        $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'completed']))
            ->assertOk()
            ->assertSee('Business Mathematics');

        $this->actingAs($this->student)
            ->get(route('student.live_classes.show', $class->fresh()->id))
            ->assertOk()
            ->assertSee('Class Completed')
            ->assertDontSee(route('student.live_classes.join', $class->id), false);
    }

    public function test_a_completed_class_can_no_longer_be_edited_or_ended(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);
        $access = app(LiveClassAccessService::class);

        $this->assertFalse($access->canLecturerManage($this->lecturer, $class),
            'a concluded class is read-only except for governed post-class work');
        $this->assertFalse($class->canTransitionTo(LiveClass::STATUS_LIVE));
    }

    // ══════════════ DEFECT 8: recordings ══════════════

    public function test_a_class_being_taught_does_not_imply_a_recording_exists(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_LIVE]);

        $this->assertSame(LiveClass::RECORDING_NONE, $class->recordingState());
        $this->assertSame('No recording', $class->recordingStateLabel());
        $this->assertFalse($class->isRecordingAvailable());

        $body = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.show', $class->id))->getContent();
        $this->assertStringContainsString('No recording', $body);
        $this->assertStringNotContainsString('Watch Recording', $body);
    }

    public function test_the_four_recording_states_are_distinguishable(): void
    {
        $this->assertSame(
            ['none', 'processing', 'available', 'unavailable'],
            LiveClass::RECORDING_STATUSES
        );

        $class = $this->class();
        $class->forceFill(['recording_status' => LiveClass::RECORDING_PROCESSING])->save();
        $this->assertStringContainsString('Awaiting', $class->fresh()->recordingStateLabel());
        $this->assertStringContainsString('set by your lecturer', $class->fresh()->recordingStateLabel(),
            'PIIE has no provider webhook and cannot know a provider is producing a file, so the '
            . 'wording must attribute this state to a person rather than to the provider');

        $class->forceFill(['recording_status' => LiveClass::RECORDING_UNAVAILABLE])->save();
        $this->assertSame('Recording unavailable', $class->fresh()->recordingStateLabel());
    }

    public function test_a_recording_marked_available_without_a_usable_link_reads_as_unavailable(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);
        $class->forceFill([
            'recording_status' => LiveClass::RECORDING_AVAILABLE,
            'recording_url' => 'not-a-url',
        ])->save();

        $this->assertSame(LiveClass::RECORDING_UNAVAILABLE, $class->fresh()->recordingState(),
            'a broken "Watch" button is worse than an honest "not available"');
    }

    public function test_an_authorised_lecturer_can_attach_a_recording_through_the_governed_action(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.recording.attach', $class->id), [
                'recording_status' => LiveClass::RECORDING_AVAILABLE,
                'recording_url' => 'https://videos.example.test/rec-1',
            ])
            ->assertSessionHasNoErrors();

        $saved = $class->fresh();
        $this->assertSame(LiveClass::RECORDING_AVAILABLE, $saved->recordingState());
        $this->assertSame('https://videos.example.test/rec-1', $saved->recording_url);

        $this->assertSame(1, DB::table('user_notifications')
            ->where('type', 'live_class_recording')->where('user_id', $this->student->id)->count(),
            'the student is told once, and the link they get is readable');
    }

    public function test_a_recording_cannot_be_published_for_a_class_that_has_not_concluded(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_LIVE]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.recording.attach', $class->id), [
                'recording_status' => LiveClass::RECORDING_AVAILABLE,
                'recording_url' => 'https://videos.example.test/rec-1',
            ])
            ->assertSessionHas('error');

        $this->assertSame(LiveClass::RECORDING_NONE, $class->fresh()->recordingState(),
            'a published recording would assert a class that has not finished');
    }

    // ══════════════ DEFECT 9: one rendering of one instant ══════════════

    /**
     * The reported symptom: one page showed "10:46 - 11:00" and said "begins at
     * 7:46 AM" for the SAME class. The first was the raw start_time column - the
     * wall clock as typed by the scheduler, in the scheduler's own zone - and the
     * second was scheduled_at read in the viewer's zone. Two numbers, one meeting.
     */
    public function test_a_class_renders_one_instant_and_never_the_raw_typed_columns(): void
    {
        // Exactly the real #45: 07:46 UTC, but the typed wall clock was 10:46.
        $class = $this->class([
            'scheduled_at' => Carbon::parse('2026-09-29 07:46:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 08:00:00', 'UTC'),
            'start_time' => '10:46:00',
            'end_time' => '11:00:00',
        ]);

        $display = app(LiveClassDisplay::class);

        // The institution is Africa/Kampala, so the OFFICIAL reading of
        // 07:46 UTC is 10:46 - which is also what was typed, because the
        // scheduler was in Kampala. That is the coincidence that hid the
        // defect: on this particular class the wrong column and the right
        // answer happened to agree.
        $institution = $display->for($class, null);
        $this->assertSame('10:46 AM - 11:00 AM', $institution->timeRange(),
            'the institution clock reads the stored instant');

        // A lecturer in another country reads the same instant in their clock.
        $this->lecturer->update(['timezone' => 'UTC']);
        $forLecturer = $display->for($class, $this->lecturer->fresh());
        $this->assertSame('7:46 AM - 8:00 AM', $forLecturer->timeRange(),
            'and the typed 10:46 column is never reused as a display value');

        // Neither reading may come from the raw column, whatever it says.
        $this->assertNotSame('10:46:00 - 11:00:00', $forLecturer->timeRange());

        // Both are the same instant - which is the point.
        $this->assertSame(
            $class->scheduled_at->getTimestamp(),
            $institution->localStart()->getTimestamp()
        );
        $this->assertSame(
            $class->scheduled_at->getTimestamp(),
            $forLecturer->localStart()->getTimestamp()
        );
    }

    public function test_no_live_class_screen_renders_the_raw_time_columns(): void
    {
        $this->class([
            'scheduled_at' => Carbon::parse('2026-09-29 07:46:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 08:00:00', 'UTC'),
            'start_time' => '10:46:00',
            'end_time' => '11:00:00',
        ]);

        $screens = [
            [$this->student, route('student.live_classes.show', LiveClass::first()->id)],
            [$this->student, route('student.live_classes.index')],
            [$this->lecturer, route('teacher.live_classes.index')],
            [$this->lecturer, route('teacher.live_classes.show', LiveClass::first()->id)],
            [$this->lecturer, route('teacher.course_offerings.show', $this->offering->id)],
        ];

        foreach ($screens as [$actor, $url]) {
            $body = (string) $this->actingAs($actor)->get($url)->getContent();
            $this->assertStringNotContainsString('10:46:00', $body, "{$url} must not render the raw column");
            $this->assertStringNotContainsString('11:00:00', $body, "{$url} must not render the raw column");
        }
    }

    public function test_the_join_window_stays_instant_based_and_timezone_blind(): void
    {
        $class = $this->class([
            'scheduled_at' => Carbon::parse('2026-09-29 07:00:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 08:00:00', 'UTC'),
        ]);
        $access = app(LiveClassAccessService::class);

        // The same instant spelled three ways.
        $open = [
            'London 07:46' => Carbon::parse('2026-09-29 07:46:00', 'Europe/London'),
            'Kampala 09:46' => Carbon::parse('2026-09-29 09:46:00', 'Africa/Kampala'),
            'New York 02:46' => Carbon::parse('2026-09-29 02:46:00', 'America/New_York'),
        ];
        foreach ($open as $label => $moment) {
            $this->assertTrue($access->withinJoinWindow($class, $moment), "open at {$label}");
        }

        $shut = [
            'London 07:00' => Carbon::parse('2026-09-29 07:00:00', 'Europe/London'),
            'Kampala 09:00' => Carbon::parse('2026-09-29 09:00:00', 'Africa/Kampala'),
            'New York 02:00' => Carbon::parse('2026-09-29 02:00:00', 'America/New_York'),
        ];
        foreach ($shut as $label => $moment) {
            $this->assertFalse($access->withinJoinWindow($class, $moment), "shut at {$label}");
        }

        $this->assertCount(1, array_unique(array_map(
            fn (Carbon $m) => $m->getTimestamp(),
            $open
        )), 'three spellings, one instant');
    }

    // ══════════════ DEFECT 10: the attendance boundary ══════════════

    public function test_joining_never_writes_official_course_offering_attendance(): void
    {
        $class = $this->class([
            'scheduled_at' => now()->subMinutes(5), 'ends_at' => now()->addHour(),
            'status' => LiveClass::STATUS_LIVE,
            // Even with the legacy K12 flag on, an Offering-backed class must not
            // turn a join into academic attendance.
            'attendance_enabled' => true,
        ]);

        $this->actingAs($this->student)->post(route('student.live_classes.join', $class->id));

        $this->assertSame(0, DB::table('course_offering_attendance_records')->count(),
            'official attendance remains a separate, lecturer-finalised record');
    }

    // ══════════════ DEFECT 11: notifications ══════════════

    public function test_each_lifecycle_event_notifies_the_confirmed_students_of_that_offering_exactly_once(): void
    {
        $class = $this->class();
        $notifier = app(\App\Support\LiveClasses\LiveClassNotifier::class);

        $this->assertSame(1, $notifier->announcePublished($class));
        $this->assertSame(0, $notifier->announcePublished($class->fresh()), 'never twice');

        $class->forceFill(['scheduled_at' => now()->addHours(5)])->save();
        $this->assertSame(1, $notifier->announceRescheduled($class->fresh()));

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.cancel', $class->id));
        $this->assertSame(1, DB::table('user_notifications')
            ->where('user_id', $this->student->id)->where('type', 'live_class_cancelled')->count());

        // A different, unconfirmed student is never reached.
        $unconfirmed = $this->user('Registered Only', 7);
        $this->confirm($unconfirmed, CourseRegistration::STATUS_REGISTERED);
        $this->assertSame(0, DB::table('user_notifications')->where('user_id', $unconfirmed->id)->count());
    }

    // ══════════════ DEFECT 12: K12 must keep working ══════════════

    public function test_a_legacy_non_offering_class_is_unaffected(): void
    {
        $legacy = new LiveClass();
        $legacy->forceFill([
            'school_id' => $this->school, 'title' => 'K12 Form 1 Mathematics',
            'class_id' => 7, 'teacher_id' => $this->lecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/k12room', 'timezone' => 'UTC',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => true,
            'scheduled_at' => now()->subMinutes(5), 'ends_at' => now()->addHour(),
        ])->save();
        $legacy = $legacy->fresh();

        $access = app(LiveClassAccessService::class);
        $this->assertFalse($access->isOfferingBacked($legacy), 'a K12 class is not Offering-backed');

        // The lecturer's own K12 index still lists it.
        $seen = $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'live']))
            ->viewData('classes');
        $this->assertTrue($seen->contains('id', $legacy->id), 'K12 listing is untouched');

        // And it can still be ended, without needing a Course Offering.
        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $legacy->id));
        $this->assertSame(LiveClass::STATUS_ENDED, $legacy->fresh()->status);
        $this->assertNotNull($legacy->fresh()->ended_at, 'a K12 class records its conclusion too');
    }

    // ══════════════ additional pins found by revert-and-check ══════════════

    /**
     * Reverting computed_status to report ENDED once the clock passes ends_at
     * made NO test fail the first time this was run, which proved nothing in the
     * certification suite was pinning it. This does.
     */
    public function test_the_model_itself_refuses_to_call_a_past_class_completed(): void
    {
        $class = $this->class([
            'scheduled_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'status' => LiveClass::STATUS_SCHEDULED,
        ]);

        $this->assertSame(LiveClass::STATUS_NOT_CONCLUDED, $class->computed_status,
            'the clock alone must never assert that a class was taught');
        $this->assertFalse($class->hasConclusiveOutcome());
        $this->assertNotSame(LiveClass::STATUS_ENDED, $class->computed_status);

        // And the one thing that DOES make it completed.
        $class->forceFill(['status' => LiveClass::STATUS_ENDED])->save();
        $this->assertSame(LiveClass::STATUS_ENDED, $class->fresh()->computed_status);
    }

    /**
     * Reverting actionUrl() to send recording events to the materials page also
     * failed no test at first, because only cancellation rows were inspected.
     * Every event's link is checked here.
     */
    public function test_every_kind_of_notification_link_lands_on_the_retained_detail_page(): void
    {
        $class = $this->class();
        $notifier = app(\App\Support\LiveClasses\LiveClassNotifier::class);

        $notifier->announcePublished($class);
        $class->forceFill(['scheduled_at' => now()->addHours(6)])->save();
        $notifier->announceRescheduled($class->fresh());
        $class->forceFill(['status' => LiveClass::STATUS_ENDED])->save();
        $class->forceFill([
            'recording_status' => LiveClass::RECORDING_AVAILABLE,
            'recording_url' => 'https://videos.example.test/rec-1',
        ])->save();
        $notifier->announceRecordingAvailable($class->fresh());

        $expected = (string) route('student.live_classes.show', $class->id);
        $rows = DB::table('user_notifications')
            ->where('user_id', $this->student->id)
            ->whereIn('type', ['live_class_published', 'live_class_recording'])
            ->get();

        $this->assertGreaterThanOrEqual(2, $rows->count(), 'published and recording both announced');
        foreach ($rows as $row) {
            $this->assertSame($expected, (string) $row->url,
                "the {$row->type} link must land on the retained detail page");
        }

        // And that page is genuinely open to this student.
        $this->actingAs($this->student)->get(route('student.live_classes.show', $class->id))->assertOk();
    }

    /**
     * Reverting storeForOffering to trust the submitted timezone also failed no
     * test at first. The stored value must be the SCHEDULER's own clock, because
     * that is the clock the typed numbers were read in.
     */
    public function test_the_stored_timezone_is_the_schedulers_own_clock_not_the_submitted_one(): void
    {
        $this->lecturer->update(['timezone' => 'Europe/London']);
        $this->otherLecturer->update(['timezone' => 'America/New_York']);

        $this->actingAs($this->otherLecturer)->post(
            route('teacher.course_offerings.live_classes.store', $this->offering->id),
            [
                'title' => 'Business Mathematics', 'platform' => 'jitsi', 'action' => 'draft',
                'start_date' => '2026-09-29', 'start_time' => '08:00', 'end_time' => '09:00',
                'meeting_url' => 'https://meet.jit.si/room',
                // A tampered or stale form must not choose the zone its own
                // input is read in: that would move the class by whole hours.
                'timezone' => 'Asia/Dubai',
            ]
        )->assertSessionHasNoErrors();

        $saved = LiveClass::where('course_offering_id', $this->offering->id)->firstOrFail();
        $this->assertSame('America/New_York', $saved->timezone,
            'the scheduler\'s own clock is authoritative, not the posted one');
    }
}
