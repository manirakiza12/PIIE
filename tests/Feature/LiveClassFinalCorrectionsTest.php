<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\LiveClasses\LiveClassDisplay;
use App\Support\TenantTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Focused regression tests for the corrections found in the FINAL manual test of
 * real Course Offering Live Class #46.
 *
 * Each test names the defect it closes. Where the defect was a false CLAIM
 * rather than a crash, the test asserts the absence of the claim - because a
 * page that says something untrue is worse than one that 500s.
 */
class LiveClassFinalCorrectionsTest extends TestCase
{
    use \Tests\Feature\Support\LiveClassFixture;
    use \Tests\Feature\Support\AdmissionsTestHelper;
    // ══════════════ 1. TIMEZONE VIEWER FALLBACK ══════════════

    /**
     * THE REPORTED SYMPTOM, verbatim from the manual test:
     *
     *   Lecturer  Africa/Kampala   ~13:22 - 16:01
     *   Kyeyune   UTC              ~10:22 - 13:01
     *
     * Those are the SAME instant rendered in two clocks, so the arithmetic was
     * never wrong - the resolver was right and the two viewers simply resolved to
     * different zones. That is correct behaviour ONLY IF it comes from the stated
     * order: this user's own choice, else the institution, else the application.
     * It is a bug when it comes from anything else, and in particular when the
     * SCHEDULER's zone is used as the viewer's.
     */
    public function test_the_viewer_timezone_is_user_then_institution_then_application(): void
    {
        $class = $this->class([
            'scheduled_at' => Carbon::parse('2026-09-29 10:22:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 13:01:00', 'UTC'),
            // The scheduler's own zone. It must NEVER become anybody's display zone.
            'timezone' => 'Africa/Kampala',
            'start_time' => '13:22:00',
            'end_time' => '16:01:00',
        ]);

        $tz = app(TenantTimezone::class);
        $display = app(LiveClassDisplay::class);

        // 1. The user's own valid choice wins.
        $this->lecturer->update(['timezone' => 'Africa/Kampala']);
        $this->assertSame('Africa/Kampala', $tz->effective($this->lecturer->fresh()));
        $this->assertSame(
            '1:22 PM - 4:01 PM',
            $display->for($class, $this->lecturer->fresh())->timeRange(),
            'a lecturer in Kampala reads Kampala time'
        );

        // 2. No choice -> the institution's timezone.
        $this->assertNull($this->student->fresh()->timezone);
        $this->assertSame('Africa/Kampala', $tz->effective($this->student->fresh()));
        $this->assertSame(
            '1:22 PM - 4:01 PM',
            $display->for($class, $this->student->fresh())->timeRange()
        );

        // 3. Institution unset -> the application default. The fallback is
        //    PRESERVED, not quietly replaced with a hardcoded Kampala.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);
        $this->assertSame('UTC', $tz->effective($this->student->fresh()));
        $this->assertSame('10:22 AM - 1:01 PM', $display->for($class, $this->student->fresh())->timeRange());

        // And the lecturer's own preference still wins over that fallback.
        $this->assertSame(
            '1:22 PM - 4:01 PM',
            $display->for($class, $this->lecturer->fresh())->timeRange()
        );
    }

    public function test_the_scheduler_timezone_is_never_used_as_the_viewers_timezone(): void
    {
        $class = $this->class([
            'scheduled_at' => Carbon::parse('2026-09-29 10:22:00', 'UTC'),
            'ends_at' => Carbon::parse('2026-09-29 13:01:00', 'UTC'),
            'timezone' => 'Africa/Kampala',
        ]);

        // A viewer with no preference and an unset institution resolves to the
        // application default - NOT to the scheduler's Africa/Kampala.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);
        $resolved = app(TenantTimezone::class)->effective($this->student->fresh());

        $this->assertNotSame($class->timezone, $resolved,
            'the stored scheduler timezone is a record of how it was entered, not a viewer preference');
        $this->assertSame('UTC', $resolved);
    }

    public function test_the_resolver_is_used_on_every_live_class_surface(): void
    {
        // WHY THE DATES ARE DERIVED RATHER THAN WRITTEN OUT
        //
        // The index's default "upcoming" tab only lists a class whose
        // `ends_at` has not passed, so a hard-coded date turns this test into a
        // clock bomb: it silently stops listing the class the moment that
        // moment arrives, and then fails for a reason that has nothing to do
        // with the resolver. That is exactly what happened - the class was
        // pinned to 2026-09-29 13:01 UTC and expired during the day.
        //
        // Deriving the date from now() keeps the test's MEANING exactly: a
        // scheduled class at a known UTC instant, rendered in each viewer's own
        // zone. Kampala is UTC+3 all year, so the expected clock readings are
        // unchanged - the lecturer reads 13:22 and the student 10:22.
        $start = Carbon::now('UTC')->addDays(2)->setTime(10, 22)->startOfMinute();

        $class = $this->class([
            'scheduled_at' => $start,
            'ends_at' => (clone $start)->addHours(2)->setTime(13, 1),
            // The misleading legacy columns stay as they were: a raw local
            // "13:22" that must never be rendered as the authoritative time.
            'start_time' => '13:22:00',
            'end_time' => '16:01:00',
        ]);
        $this->lecturer->update(['timezone' => 'Africa/Kampala']);

        // Reproduce #46 exactly: the lecturer has chosen Kampala, the student has
        // chosen nothing, and PIIE's own institution timezone is UNSET. The
        // student must therefore fall through to the application default and read
        // 10:22 UTC - which is the same instant the lecturer reads as 13:22.
        DB::table('schools')->where('id', $this->school)->update(['timezone' => null]);

        $surfaces = [
            [$this->lecturer, route('teacher.live_classes.show', $class->id), '13:22'],
            [$this->lecturer, route('teacher.live_classes.index'), '13:22'],
            [$this->lecturer, route('teacher.course_offerings.show', $this->offering->id), '13:22'],
            [$this->lecturer, route('teacher.live_classes.materials', $class->id), null],
            [$this->student, route('student.live_classes.show', $class->id), '10:22'],
            [$this->student, route('student.live_classes.index'), '10:22'],
        ];

        foreach ($surfaces as [$actor, $url, $expected]) {
            $body = (string) $this->actingAs($actor)->get($url)->getContent();

            // The typed column must never be rendered as a time.
            $this->assertStringNotContainsString('13:22:00', $body, "{$url} must not render the raw column");
            $this->assertStringNotContainsString('16:01:00', $body, "{$url} must not render the raw column");

            if ($expected !== null) {
                $this->assertStringContainsString($expected, $body,
                    "{$url} must render the viewer's own clock");
            }
        }
    }

    // ══════════════ 2. COMPLETED PRESENTATION ══════════════

    public function test_the_stored_value_stays_ended_but_people_read_completed(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        // The DATABASE value is untouched - compatibility is the point.
        $this->assertSame('ended', $class->status);
        $this->assertSame(LiveClass::STATUS_ENDED, $class->computed_status);
        $this->assertSame('ended', DB::table('live_classes')->where('id', $class->id)->value('status'));

        // The WORD is Completed, everywhere.
        $this->assertSame('Completed', $class->displayStatusLabel());
        $this->assertSame('Completed', LiveClass::DISPLAY_STATUS_LABELS[LiveClass::STATUS_ENDED]);

        $this->assertStringNotContainsString('Ended', $class->displayStatusLabel());

        // The lecturer's own page must not leak the internal term.
        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))->getContent();
        $this->assertStringContainsString('Completed', $body);
        $this->assertStringNotContainsString('>Ended<', $body);

        // The student page too.
        $studentBody = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.show', $class->id))->getContent();
        $this->assertStringContainsString('Completed', $studentBody);
    }

    public function test_every_lifecycle_state_has_one_user_facing_name(): void
    {
        // ONLY `ended` is reworded. Every other state keeps the word the
        // interface already used - renaming "Scheduled" would be scope creep that
        // changed a term nobody complained about.
        $this->assertSame([
            LiveClass::STATUS_DRAFT => 'Draft',
            LiveClass::STATUS_SCHEDULED => 'Scheduled',
            LiveClass::STATUS_LIVE => 'Live Now',
            LiveClass::STATUS_ENDED => 'Completed',
            LiveClass::STATUS_CANCELLED => 'Cancelled',
            LiveClass::STATUS_NOT_CONCLUDED => 'Ended without confirmation',
        ], LiveClass::DISPLAY_STATUS_LABELS);

        $this->assertSame('Draft', $this->class(['is_published' => false, 'status' => LiveClass::STATUS_DRAFT])->displayStatusLabel());
        $this->assertSame('Scheduled', $this->class()->displayStatusLabel());
        $this->assertSame('Live Now', $this->class(['status' => LiveClass::STATUS_LIVE, 'scheduled_at' => now()->subMinutes(1), 'ends_at' => now()->addHour()])->displayStatusLabel());
        $this->assertSame('Cancelled', $this->class(['status' => LiveClass::STATUS_CANCELLED])->displayStatusLabel());
        $this->assertSame(
            'Ended without confirmation',
            $this->class(['status' => LiveClass::STATUS_SCHEDULED, 'scheduled_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)])->displayStatusLabel()
        );
    }

    // ══════════════ 3. COMPLETED MUST REMAIN TERMINAL ══════════════

    public function test_a_completed_class_cannot_be_cancelled_by_the_backend(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        // The button is hidden...
        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))->getContent();
        $this->assertStringNotContainsString(route('teacher.live_classes.cancel', $class->id), $body,
            'the Cancel action must not be offered on a completed class');
        $this->assertStringContainsString('record is final', $body);

        // ...and the REQUEST is refused, which is the rule rather than the courtesy.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.cancel', $class->id))
            ->assertForbidden();

        $this->assertSame(LiveClass::STATUS_ENDED, $class->fresh()->status,
            'and the class is still completed, not quietly converted to cancelled');
        $this->assertNull($class->fresh()->cancelled_at, 'no cancellation was recorded');
    }

    public function test_a_completed_class_cannot_be_rescheduled_or_re_timed(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        $this->actingAs($this->lecturer)
            ->put(route('teacher.live_classes.update', $class->id), [
                'title' => 'Renamed', 'platform' => 'jitsi',
                'start_date' => '2026-10-01', 'start_time' => '10:00', 'end_time' => '11:00',
            ])
            ->assertForbidden();

        $this->assertSame('Business Mathematics', $class->fresh()->title);
    }

    public function test_a_k12_completed_class_also_cannot_be_cancelled(): void
    {
        // The Offering-backed path is already refused by canLecturerManage(),
        // which excludes an ended class - so cancelling one of those would pass
        // even with the transition guard removed. The LEGACY K12 path is the one
        // that matters: it falls through to a generic policy with no lifecycle
        // knowledge at all, so without the named transition a completed class
        // could be reopened from a menu.
        $legacy = new LiveClass();
        $legacy->forceFill([
            'school_id' => $this->school, 'title' => 'K12 Completed',
            'class_id' => 7, 'teacher_id' => $this->lecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/k12done', 'timezone' => 'UTC',
            'status' => LiveClass::STATUS_ENDED, 'is_published' => true,
            'scheduled_at' => now()->subHours(3), 'ends_at' => now()->subHours(2),
        ])->save();
        $legacy = $legacy->fresh();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.cancel', $legacy->id))
            ->assertForbidden();

        $this->assertSame(LiveClass::STATUS_ENDED, $legacy->fresh()->status);
        $this->assertNull($legacy->fresh()->cancelled_at, 'no cancellation was recorded');
    }

    public function test_a_cancelled_class_is_also_terminal(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_CANCELLED]);

        $this->assertTrue($class->isTerminal());
        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.cancel', $class->id))
            ->assertForbidden();
    }

    public function test_post_class_resources_remain_available_on_a_completed_class(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $this->assertTrue(
            $access->canManagePostClassResources($this->lecturer, $class),
            'attaching a recording is the work that legitimately continues after a class'
        );

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.recording.attach', $class->id), [
                'recording_status' => LiveClass::RECORDING_AVAILABLE,
                'recording_url' => 'https://videos.example.test/rec-46',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($class->fresh()->isRecordingAvailable());
    }

    public function test_a_live_class_still_offers_every_lifecycle_action(): void
    {
        $class = $this->class([
            'status' => LiveClass::STATUS_LIVE,
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);

        $body = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.show', $class->id))->getContent();

        $this->assertStringContainsString(route('teacher.live_classes.end', $class->id), $body);
        $this->assertStringContainsString(route('teacher.live_classes.cancel', $class->id), $body);
    }

    // ══════════════ 4 & 5. RECORDING WORKFLOW AND STUDENT UX ══════════════

    public function test_a_processing_recording_never_renders_as_an_available_one(): void
    {
        // A leftover URL plus a processing state: the combination that produced a
        // Watch button with nothing playable behind it.
        $class = $this->class([
            'status' => LiveClass::STATUS_ENDED,
            'recording_status' => LiveClass::RECORDING_PROCESSING,
            'recording_url' => 'https://videos.example.test/stale',
        ]);

        $this->assertSame(LiveClass::RECORDING_PROCESSING, $class->recordingState());
        $this->assertFalse($class->isRecordingAvailable());

        // The student's detail page: no Watch action.
        $studentBody = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.show', $class->id))->getContent();
        $this->assertStringNotContainsString('Watch Recording', $studentBody);

        // The index: no authorised-recording link. The dropdown entry is
        // labelled "Recording" and links to the access route, so the route URL
        // is the precise thing to assert on - a label check alone would pass even
        // with the link present.
        $indexBody = (string) $this->actingAs($this->lecturer)
            ->get(route('teacher.live_classes.index', ['view' => 'completed']))->getContent();
        $this->assertStringNotContainsString(
            route('live_classes.recording.access', $class->id),
            $indexBody,
            'a processing recording must not offer an authorised Watch link'
        );

        // The student drawer: the useful message, and no Watch button.
        $drawer = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.materials', $class->id))->getContent();
        $this->assertStringContainsString('The recording is being prepared', $drawer);
        $this->assertStringContainsString('It will appear here when it becomes available', $drawer);
        $this->assertStringNotContainsString('Watch Recording', $drawer);
    }

    public function test_the_drawer_says_there_is_no_recording_when_there_is_none(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED, 'recording_status' => LiveClass::RECORDING_NONE]);

        $drawer = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.materials', $class->id))->getContent();

        $this->assertStringContainsString('No recording is available for this class', $drawer);
        $this->assertStringNotContainsString('Watch Recording', $drawer);
    }

    public function test_available_with_a_valid_url_appears_in_the_drawer_and_notifies_once(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.recording.attach', $class->id), [
                'recording_status' => LiveClass::RECORDING_AVAILABLE,
                'recording_url' => 'https://videos.example.test/rec-46',
            ])
            ->assertSessionHasNoErrors();

        $saved = $class->fresh();
        $this->assertSame(LiveClass::RECORDING_AVAILABLE, $saved->recordingState());

        $drawer = (string) $this->actingAs($this->student)
            ->get(route('student.live_classes.materials', $saved->id))->getContent();
        $this->assertStringContainsString('Watch Recording', $drawer);
        $this->assertStringContainsString('Recording of this class', $drawer);

        // The notification fired once, on the existing dedup ledger.
        $this->assertSame(1, DB::table('user_notifications')
            ->where('user_id', $this->student->id)
            ->where('type', 'live_class_recording')
            ->count());

        // And re-saving the same recording does not notify again.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.live_classes.recording.attach', $saved->id), [
                'recording_status' => LiveClass::RECORDING_AVAILABLE,
                'recording_url' => 'https://videos.example.test/rec-46',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('user_notifications')
            ->where('user_id', $this->student->id)
            ->where('type', 'live_class_recording')
            ->count());
    }

    public function test_available_is_refused_without_a_usable_https_url(): void
    {
        $class = $this->class(['status' => LiveClass::STATUS_ENDED]);

        foreach ([null, '', 'not-a-url', 'http://insecure.example.test/rec'] as $bad) {
            $this->actingAs($this->lecturer)
                ->post(route('teacher.live_classes.recording.attach', $class->id), [
                    'recording_status' => LiveClass::RECORDING_AVAILABLE,
                    'recording_url' => $bad,
                ]);

            $this->assertNotSame(
                LiveClass::RECORDING_AVAILABLE,
                $class->fresh()->recordingState(),
                'a recording cannot be published without a usable link'
            );
        }

        // Nothing unusable was STORED either. Checking only the resulting state
        // was not enough: the model downgrades a bad link to "unavailable" on
        // read, so a stored-but-broken URL still looked correct while leaving a
        // dead link in the record.
        $this->assertNull($class->fresh()->recording_url,
            'an unusable link must never be written to the record');
        $this->assertSame(LiveClass::RECORDING_NONE, $class->fresh()->recording_status);

        $this->assertSame(0, DB::table('user_notifications')
            ->where('user_id', $this->student->id)
            ->where('type', 'live_class_recording')
            ->count(),
            'and nobody was told about a recording that cannot be opened');
    }

    public function test_the_processing_wording_does_not_claim_provider_evidence(): void
    {
        $label = LiveClass::RECORDING_LABELS[LiveClass::RECORDING_PROCESSING];

        $this->assertStringNotContainsStringIgnoringCase('provider', $label);
        $this->assertStringNotContainsStringIgnoringCase('Jitsi', $label);
        $this->assertStringNotContainsStringIgnoringCase('Google', $label);
        $this->assertStringNotContainsStringIgnoringCase('being processed', $label);
        $this->assertStringContainsString('set by your lecturer', $label);
    }

    // ══════════════ 6. PRESERVED GUARANTEES ══════════════

    public function test_all_previously_certified_guarantees_still_hold(): void
    {
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        // (a) cancelled: readable, never joinable
        $cancelled = $this->class(['status' => LiveClass::STATUS_CANCELLED]);
        $this->assertTrue($access->canStudentViewClass($this->student, $cancelled));
        $this->assertFalse($access->canStudentJoin($this->student, $cancelled));
        $this->actingAs($this->student)->get(route('student.live_classes.show', $cancelled->id))->assertOk();

        // (b) completed: readable, never joinable
        $completed = $this->class(['status' => LiveClass::STATUS_ENDED]);
        $this->assertTrue($access->canStudentViewClass($this->student, $completed));
        $this->assertFalse($access->canStudentJoin($this->student, $completed));
        $this->actingAs($this->student)->get(route('student.live_classes.show', $completed->id))->assertOk();

        // (c) notification links never 404 for a retained class
        foreach ([$cancelled, $completed] as $retained) {
            $this->actingAs($this->student)
                ->get(route('student.live_classes.show', $retained->id))
                ->assertOk();
        }

        // (d) joining never writes official Course Offering attendance
        $this->assertSame(0, DB::table('course_offering_attendance_records')->count());

        // (e) no provider secret leaks
        $secret = $this->class(['meeting_password' => 'never-render-this']);
        $this->assertStringNotContainsString(
            'never-render-this',
            (string) $this->actingAs($this->student)->get(route('student.live_classes.show', $secret->id))->getContent()
        );
    }

    public function test_the_lecturer_offering_counters_and_history_are_unaffected(): void
    {
        $this->class();
        $this->class(['status' => LiveClass::STATUS_CANCELLED]);
        $this->class(['status' => LiveClass::STATUS_ENDED]);

        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.show', $this->offering->id));
        $response->assertOk();
        $this->assertSame(3, $response->viewData('liveClassCounts')['total']);
        $this->assertSame(3, $response->viewData('liveClasses')->count());

        foreach (['cancelled' => 1, 'completed' => 1, 'all' => 3] as $view => $expected) {
            $this->assertCount(
                $expected,
                $this->actingAs($this->lecturer)
                    ->get(route('teacher.live_classes.index', ['view' => $view]))
                    ->viewData('classes'),
                "the {$view} tab"
            );
        }
    }

    public function test_lecturer_authority_is_still_allocation_based(): void
    {
        $class = $this->class([
            'status' => LiveClass::STATUS_LIVE,
            'scheduled_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
        ]);
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);

        $this->assertTrue($access->canLecturerHost($this->lecturer, $class));

        $unallocated = $this->user('Unallocated Lecturer', 3);
        DB::table('user_permissions')->insert([
            ['school_id' => $this->school, 'user_id' => $unallocated->id, 'permission' => 'live_classes.create', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->assertFalse($access->canLecturerHost($unallocated, $class),
            'capability alone is not authority; the Offering allocation is');
        $this->assertFalse($access->canManagePostClassResources($unallocated, $class));
    }

    public function test_a_k12_class_still_works_end_to_end(): void
    {
        $legacy = new LiveClass();
        $legacy->forceFill([
            'school_id' => $this->school, 'title' => 'K12 Form 2 Mathematics',
            'class_id' => 7, 'teacher_id' => $this->lecturer->id, 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.jit.si/k12room', 'timezone' => 'UTC',
            'status' => LiveClass::STATUS_LIVE, 'is_published' => true,
            'scheduled_at' => now()->subMinutes(5), 'ends_at' => now()->addHour(),
        ])->save();
        $legacy = $legacy->fresh();

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $this->assertFalse($access->isOfferingBacked($legacy));
        $this->assertFalse($access->canManagePostClassResources($this->lecturer, $legacy),
            'the post-class Offering authority must not leak onto K12 classes');

        $this->actingAs($this->lecturer)->get(route('teacher.live_classes.show', $legacy->id))->assertOk();

        $this->actingAs($this->lecturer)->post(route('teacher.live_classes.end', $legacy->id));
        $this->assertSame(LiveClass::STATUS_ENDED, $legacy->fresh()->status);
        $this->assertSame('Completed', $legacy->fresh()->displayStatusLabel(),
            'the Completed wording applies to K12 classes too');
    }
}
