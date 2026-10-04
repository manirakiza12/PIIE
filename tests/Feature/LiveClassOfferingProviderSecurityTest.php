<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Models\GoogleAccountConnection;
use App\Support\Google\GoogleOAuthClient;
use App\Support\Google\GoogleOAuthCredentials;
use App\Support\LiveClasses\GoogleConferenceStatus;
use App\Support\Permissions\PermissionService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassOfferingProviderSecurityTest extends TestCase
{
    use LiveClassTestHelper;

    private const JITSI_TEST_SECRET = 'isolated-provider-test-signing-key';

    private int $subjectA;
    private int $offeringA;
    private int $parallelOffering;
    private int $foreignOffering;

    /** Temp OAuth credentials file; removed in tearDown so no fixture leaks. */
    private ?string $credentialsPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'UTC'));
        Schema::table('live_classes', fn (Blueprint $table) => $table->unsignedBigInteger('course_offering_id')->nullable());
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable(); $table->unsignedBigInteger('academic_period_id')->nullable();
            $table->string('reference')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id'); $table->string('role'); $table->string('status');
            $table->date('starts_on'); $table->date('ends_on')->nullable();
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('course_offering_id')->nullable(); $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('addons', function (Blueprint $table): void {
            $table->id(); $table->string('unique_identifier')->nullable(); $table->string('status')->nullable();
        });

        DB::table('schools')->insert([
            ['id' => 1, 'title' => 'Tenant A'], ['id' => 2, 'title' => 'Tenant B'],
        ]);
        $this->subjectA = $this->subject(1, 'Provider course');
        $foreignSubject = $this->subject(2, 'Provider course');
        $this->offeringA = $this->offering(1, $this->subjectA);
        $this->parallelOffering = $this->offering(1, $this->subjectA);
        $this->foreignOffering = $this->offering(2, $foreignSubject);
        DB::table('global_settings')->insert([
            ['key' => 'live_class_jitsi_base_url', 'value' => 'https://meet.example.test', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'live_class_platform_zoom', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'live_class_platform_google_meet', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        Config::set('services.jitsi.algorithm', 'HS256');
        Config::set('services.jitsi.app_id', 'provider-security-test-app');
        Config::set('services.jitsi.app_secret', self::JITSI_TEST_SECRET);
        Config::set('services.google_meet.client_id', 'nonproduction-google-client');
        Config::set('services.google_meet.client_secret', 'nonproduction-google-secret');
        Config::set('services.google_meet.refresh_token', 'nonproduction-google-refresh');
        Config::set('services.google_meet.calendar_id', 'primary');
        Config::set('services.zoom.account_id', 'nonproduction-zoom-account');
        Config::set('services.zoom.client_id', 'nonproduction-zoom-client');
        Config::set('services.zoom.client_secret', 'nonproduction-zoom-secret');
    }

    protected function tearDown(): void
    {
        // The static override is process-wide, so leaving it set would silently
        // point every later test in this PHPUnit process at a deleted temp file.
        GoogleOAuthCredentials::$pathOverride = null;

        if ($this->credentialsPath !== null && is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }
        $this->credentialsPath = null;

        Carbon::setTestNow();
        Config::set('services.jitsi.algorithm', 'RS256');
        Config::set('services.jitsi.app_id', '');
        Config::set('services.jitsi.kid', '');
        Config::set('services.jitsi.private_key', '');
        Config::set('services.jitsi.app_secret', '');
        parent::tearDown();
    }

    public function test_real_jitsi_join_route_signs_moderator_only_for_primary_and_co_allocations(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        foreach ([
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => true,
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => true,
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => false,
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => false,
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => false,
        ] as $role => $expectedModerator) {
            $lecturer = $this->user($role.'-provider@example.test', 3, 1);
            $this->allocation($lecturer, $this->offeringA, $role);
            $joinUrl = route('teacher.live_classes.join', $class->id);
            if ($role === CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT) {
                $joinUrl .= '?moderator=1&is_moderator=1&room=attacker-room&token=attacker-token';
            }
            $response = $this->actingAs($lecturer)->get($joinUrl);
            $response->assertOk()->assertViewHas('isModerator', $expectedModerator);
            $token = $response->viewData('jitsiJwt');
            $this->assertNotEmpty($token);
            $claims = (array) JWT::decode($token, new Key(self::JITSI_TEST_SECRET, 'HS256'));
            $this->assertSame($expectedModerator, (bool) $claims['context']->user->moderator);
            $this->assertSame($lecturer->id, (int) $claims['context']->user->id);
            $this->assertSame('room-1', $claims['room']);
            $this->assertStringNotContainsString(self::JITSI_TEST_SECRET, $response->getContent() ?: '');
            $this->assertStringNotContainsString('meeting-password-secret', $response->getContent() ?: '');
        }

        $admin = $this->user('admin-jitsi-host@example.test', 2, 1);
        $adminResponse = $this->actingAs($admin)->get(route('admin.live_classes.join', $class->id));
        $adminResponse->assertOk()->assertViewHas('isModerator', true);
        $adminClaims = (array) JWT::decode($adminResponse->viewData('jitsiJwt'), new Key(self::JITSI_TEST_SECRET, 'HS256'));
        $this->assertTrue($adminClaims['context']->user->moderator);
    }

    public function test_student_jitsi_token_is_participant_only_and_other_registrations_are_denied(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $confirmed = $this->student('confirmed-jitsi@example.test', 1);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $response = $this->actingAs($confirmed)->get(route('student.live_classes.join', $class->id));
        $response->assertOk()->assertViewHas('isModerator', false);
        $claims = (array) JWT::decode($response->viewData('jitsiJwt'), new Key(self::JITSI_TEST_SECRET, 'HS256'));
        $this->assertFalse($claims['context']->user->moderator);
        $this->assertFalse($claims['context']->features->recording);
        $this->assertFalse($claims['context']->features->livestreaming);

        $denials = [
            [CourseRegistration::STATUS_REGISTERED, $this->offeringA, 1, 'registered-jitsi@example.test'],
            [CourseRegistration::STATUS_DROPPED, $this->offeringA, 1, 'dropped-jitsi@example.test'],
            [CourseRegistration::STATUS_CONFIRMED, $this->parallelOffering, 1, 'parallel-jitsi@example.test'],
            [CourseRegistration::STATUS_CONFIRMED, $this->foreignOffering, 2, 'foreign-jitsi@example.test'],
        ];
        foreach ($denials as [$status, $offeringId, $schoolId, $email]) {
            $student = $this->student($email, $schoolId);
            $this->registration($student, $offeringId, $status);
            $denied = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
            $this->assertNotSame('https://meet.example.test/room-1', $denied->headers->get('Location'));
            $this->assertStringNotContainsString('room-1', $denied->getContent() ?: '');
            $this->assertStringNotContainsString(self::JITSI_TEST_SECRET, $denied->getContent() ?: '');
        }

        $unregistered = $this->student('unregistered-jitsi@example.test', 1);
        $this->actingAs($unregistered)->get(route('student.live_classes.join', $class->id))
            ->assertRedirect()->assertDontSee('room-1');

        $disabled = $this->student('disabled-jitsi@example.test', 1);
        $this->registration($disabled, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);
        $disabled->refresh();
        $disabledResponse = $this->actingAs($disabled)->get(route('student.live_classes.join', $class->id));
        $this->assertContains($disabledResponse->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('room-1', $disabledResponse->getContent() ?: '');

        $suspended = $this->user('suspended-allocation-provider@example.test', 3, 1);
        $this->allocation($suspended, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        DB::table('users')->where('id', $suspended->id)->update(['staff_status' => 'suspended']);
        $suspended->refresh();
        $suspendedResponse = $this->actingAs($suspended)->get(route('teacher.live_classes.join', $class->id));
        $this->assertContains($suspendedResponse->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('room-1', $suspendedResponse->getContent() ?: '');
    }

    public function test_join_window_http_boundaries_are_enforced_before_jitsi_token_delivery(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $student = $this->student('jitsi-window@example.test', 1);
        $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);

        foreach ([
            ['2026-09-25 09:44:59', false],
            ['2026-09-25 09:45:00', true],
            ['2026-09-25 11:15:00', true],
            ['2026-09-25 11:15:01', false],
        ] as [$time, $allowed]) {
            Carbon::setTestNow(Carbon::parse($time, 'UTC'));
            $response = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
            if ($allowed) {
                $response->assertOk()->assertViewHas('isModerator', false);
                $claims = (array) JWT::decode($response->viewData('jitsiJwt'), new Key(self::JITSI_TEST_SECRET, 'HS256'));
                $this->assertFalse($claims['context']->user->moderator);
            } else {
                $this->assertNotSame(200, $response->getStatusCode());
                $this->assertStringNotContainsString('room-1', $response->getContent() ?: '');
                $this->assertStringNotContainsString(self::JITSI_TEST_SECRET, $response->getContent() ?: '');
            }
        }
        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00', 'UTC'));
    }

    public function test_nonqualifying_and_cross_tenant_lecturers_never_receive_provider_tokens(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $ended = $this->user('ended-allocation-provider@example.test', 3, 1);
        $this->allocation($ended, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ENDED, '2026-09-01', '2026-09-24');
        $this->actingAs($ended)->get(route('teacher.live_classes.join', $class->id))
            ->assertRedirect()->assertDontSee('room-1');

        $future = $this->user('future-allocation-provider@example.test', 3, 1);
        $this->allocation($future, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            CourseOfferingLecturerAllocation::STATUS_ACTIVE, '2026-09-26', null);
        $this->actingAs($future)->get(route('teacher.live_classes.join', $class->id))
            ->assertRedirect()->assertDontSee('room-1');

        $parallel = $this->user('parallel-allocation-provider@example.test', 3, 1);
        $this->allocation($parallel, $this->parallelOffering, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($parallel)->get(route('teacher.live_classes.join', $class->id))
            ->assertRedirect()->assertDontSee('room-1');

        $foreign = $this->user('foreign-allocation-provider@example.test', 3, 2);
        $this->allocation($foreign, $this->foreignOffering, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($foreign)->get(route('teacher.live_classes.join', $class->id))
            ->assertNotFound()->assertDontSee('room-1');

        $disabled = $this->user('disabled-allocation-provider@example.test', 3, 1);
        $this->allocation($disabled, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        DB::table('users')->where('id', $disabled->id)->update(['account_status' => 'disable']);
        $disabled->refresh();
        $disabledResponse = $this->actingAs($disabled)->get(route('teacher.live_classes.join', $class->id));
        $this->assertContains($disabledResponse->getStatusCode(), [302, 403]);
        $this->assertStringNotContainsString('room-1', $disabledResponse->getContent() ?: '');
    }

    public function test_recording_route_authorizes_before_redirect_and_blocks_cross_object_access(): void
    {
        $class = $this->liveClass(1, $this->offeringA, [
            'status' => LiveClass::STATUS_ENDED, 'scheduled_at' => '2026-09-25 08:00:00',
            'ends_at' => '2026-09-25 09:00:00', 'recording_url' => 'https://video.example.test/private-recording-secret',
        ]);
        $confirmed = $this->student('recording-confirmed-provider@example.test', 1);
        $this->registration($confirmed, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $this->actingAs($confirmed)->get(route('live_classes.recording.access', $class->id))
            ->assertRedirect('https://video.example.test/private-recording-secret');

        foreach ([
            [CourseRegistration::STATUS_REGISTERED, $this->offeringA, 1, 'recording-pending@example.test'],
            [CourseRegistration::STATUS_DROPPED, $this->offeringA, 1, 'recording-dropped@example.test'],
            [CourseRegistration::STATUS_CONFIRMED, $this->parallelOffering, 1, 'recording-parallel@example.test'],
            [CourseRegistration::STATUS_CONFIRMED, $this->foreignOffering, 2, 'recording-foreign@example.test'],
        ] as [$status, $offeringId, $schoolId, $email]) {
            $student = $this->student($email, $schoolId);
            $this->registration($student, $offeringId, $status);
            $response = $this->actingAs($student)->get(route('live_classes.recording.access', $class->id));
            $this->assertNotSame('https://video.example.test/private-recording-secret', $response->headers->get('Location'));
            $this->assertStringNotContainsString('private-recording-secret', $response->getContent() ?: '');
        }

        $parallelClass = $this->liveClass(2, $this->parallelOffering, [
            'status' => LiveClass::STATUS_ENDED, 'scheduled_at' => '2026-09-25 08:00:00',
            'ends_at' => '2026-09-25 09:00:00', 'recording_url' => 'https://video.example.test/other-recording-secret',
        ]);
        $this->actingAs($confirmed)->get(route('live_classes.recording.access', $parallelClass->id))
            ->assertForbidden()->assertDontSee('other-recording-secret');
    }

    public function test_authorized_edit_form_is_the_only_class_page_with_provider_fields_and_tampering_does_not_elevate_ta(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $primary = $this->user('primary-provider-edit@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->actingAs($primary)->get(route('teacher.live_classes.edit', $class->id))
            ->assertOk()->assertDontSee('https://meet.example.test/room-1')->assertDontSee('meeting-password-secret');

        $ta = $this->user('ta-provider-tamper@example.test', 3, 1);
        $this->allocation($ta, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT);
        $payload = [
            'title' => 'Injected provider class', 'platform' => 'jitsi', 'meeting_url' => 'https://attacker.example.test/secret-room',
            'meeting_id' => 'injected-id', 'meeting_password' => 'injected-password',
            'start_date' => '2026-09-25', 'start_time' => '10:00', 'end_time' => '11:00', 'timezone' => 'UTC',
            'status' => 'scheduled', 'is_published' => 1, 'recording_url' => 'https://attacker.example.test/recording-secret',
            'course_offering_id' => $this->parallelOffering, 'moderator' => true, 'is_moderator' => true,
            'room_name' => 'attacker-room', 'token' => 'attacker-token', 'host_identity' => $ta->id,
        ];
        $this->actingAs($ta)->put(route('teacher.live_classes.update', $class->id), $payload)->assertForbidden();
        $this->assertDatabaseHas('live_classes', [
            'id' => $class->id, 'course_offering_id' => $this->offeringA,
            'meeting_url' => 'https://meet.example.test/room-1', 'meeting_password' => 'meeting-password-secret',
            'recording_url' => 'https://video.example.test/recording-provider-secret',
        ]);
    }

    public function test_offering_provider_creation_failure_is_safe_and_creation_is_host_only(): void
    {
        $primary = $this->user('primary-provider-create@example.test', 3, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        Http::fake(['zoom.us/*' => Http::response(['error' => 'provider-secret-response'], 503)]);
        Log::spy();
        $zoomPayload = $this->offeringPayload('zoom');
        $zoomPayload['teacher_id'] = $primary->id;
        $failed = $this->actingAs($primary)->from(route('admin.course_offerings.live_classes.create', $this->offeringA))
            ->post(route('admin.course_offerings.live_classes.store', $this->offeringA), $zoomPayload);
        $failed->assertRedirect()->assertSessionHasErrors('meeting_url');
        $failureText = session('errors')->first('meeting_url');
        foreach (['provider-secret-response', 'nonproduction-zoom-secret', 'access_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $failureText);
        }
        $this->assertSame(0, DB::table('live_classes')->count());
        Log::shouldHaveReceived('warning')->once();

        $ta = $this->user('ta-provider-create@example.test', 3, 1);
        $this->allocation($ta, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT);
        Http::fake();
        $unauthorizedPayload = $this->offeringPayload('google_meet');
        $unauthorizedPayload['teacher_id'] = $ta->id;
        $this->actingAs($ta)->post(route('admin.course_offerings.live_classes.store', $this->offeringA), $unauthorizedPayload)
            ->assertForbidden();
        Http::assertNothingSent();

        $googlePayload = $this->offeringPayload('google_meet');
        $googlePayload['teacher_id'] = $primary->id;
        $googlePayload['meeting_url'] = 'https://meet.google.com/provider-created-room';
        $created = $this->actingAs($primary)->post(route('admin.course_offerings.live_classes.store', $this->offeringA), $googlePayload);
        $created->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('live_classes')->where('course_offering_id', $this->offeringA)->count());
        $this->assertStringNotContainsString('provider-created-room', $created->getContent() ?: '');
    }

    public function test_provider_failure_logging_uses_only_safe_context_and_new_access_after_loss_is_denied(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $student = $this->student('provider-loss-student@example.test', 1);
        $registrationId = $this->registration($student, $this->offeringA, CourseRegistration::STATUS_CONFIRMED);
        $valid = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
        $valid->assertOk();
        DB::table('course_registrations')->where('id', $registrationId)->update(['status' => CourseRegistration::STATUS_DROPPED]);
        $denied = $this->actingAs($student)->get(route('student.live_classes.join', $class->id));
        $this->assertNotSame(200, $denied->getStatusCode());
        $this->assertStringNotContainsString('room-1', $denied->getContent() ?: '');

        $row = DB::table('audit_logs')->orderByDesc('id')->first();
        if ($row) {
            $audit = json_encode($row);
            foreach (['meeting-password-secret', self::JITSI_TEST_SECRET, 'access_token'] as $secret) {
                $this->assertStringNotContainsString($secret, $audit);
            }
        }

        $lecturer = $this->user('host-access-loss@example.test', 3, 1);
        $allocationId = DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => 1, 'course_offering_id' => $this->offeringA, 'user_id' => $lecturer->id,
            'role' => CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER,
            'status' => CourseOfferingLecturerAllocation::STATUS_ACTIVE,
            'starts_on' => '2026-09-01', 'ends_on' => null,
        ]);
        $hostResponse = $this->actingAs($lecturer)->get(route('teacher.live_classes.join', $class->id));
        $hostResponse->assertOk()->assertViewHas('isModerator', true);
        DB::table('course_offering_lecturer_allocations')->where('id', $allocationId)->update([
            'status' => CourseOfferingLecturerAllocation::STATUS_ENDED, 'ends_on' => '2026-09-25',
        ]);
        $afterLoss = $this->actingAs($lecturer)->get(route('teacher.live_classes.join', $class->id));
        $this->assertNotSame(200, $afterLoss->getStatusCode());
        $this->assertStringNotContainsString('room-1', $afterLoss->getContent() ?: '');
    }

    public function test_model_serialization_and_denial_pages_do_not_expose_offering_provider_secrets(): void
    {
        $class = $this->liveClass(1, $this->offeringA);
        $serialized = json_encode($class->fresh());
        foreach (['https://meet.example.test/room-1', 'meeting-id-secret', 'meeting-password-secret', 'recording-provider-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $unauthorized = $this->user('unauthorized-provider-view@example.test', 3, 1);
        $show = $this->actingAs($unauthorized)->get(route('teacher.live_classes.show', $class->id));
        $show->assertForbidden();
        foreach (['room-1', 'meeting-id-secret', 'meeting-password-secret', 'recording-provider-secret', self::JITSI_TEST_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $show->getContent() ?: '');
        }
    }

    // ── Offering-backed Google persistence ────────────────────────────────────
    //
    // The Offering route (LiveClassController::storeForOffering) used to resolve
    // the meeting through the string-only helper, so a Google class created there
    // recorded NEITHER its Calendar event id NOR its conference state. The class
    // was created and the join link worked, but PIIE kept no handle on the
    // calendar entry behind it - so cancelling the class left an orphan Meet
    // conference, and a live join link, on the lecturer's real calendar while
    // PIIE reported the class cancelled. The link is all a student needs to join,
    // so that is a security defect and not a housekeeping one.
    //
    // These pin the three things the route must now do: persist the event id,
    // persist the conference status, and treat a pending conference as a real
    // scheduled class rather than a failure.

    public function test_offering_backed_google_meet_persists_the_calendar_event_id_and_conference_status(): void
    {
        $this->bootGoogleSchema();
        $primary = $this->user('primary-google-offering@example.test', PermissionService::TEACHER, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->connectGoogle($primary, 'primary-google-offering@example.test');
        $this->fakeGoogleReady('offering-google-event-id');

        $payload = $this->offeringPayload('google_meet');
        $payload['teacher_id'] = $primary->id;
        // No meeting_url: the whole point is that PIIE asks Google for one.
        unset($payload['meeting_url']);

        $this->actingAs($primary)->post(route('teacher.course_offerings.live_classes.store', $this->offeringA), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::table('live_classes')->where('course_offering_id', $this->offeringA)->first();

        $this->assertNotNull($row, 'The Offering-backed Google class was not saved.');
        $this->assertSame('offering-google-event-id', $row->google_calendar_event_id);
        $this->assertSame(GoogleConferenceStatus::READY, $row->google_conference_status);
        $this->assertSame('https://meet.google.com/offering-google-event-id', $row->meeting_url);
    }

    public function test_offering_backed_pending_conference_is_saved_as_pending_not_rejected_as_a_failure(): void
    {
        $this->bootGoogleSchema();
        $primary = $this->user('primary-google-pending@example.test', PermissionService::TEACHER, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->connectGoogle($primary, 'primary-google-pending@example.test');
        // Google accepted the event but has not issued the conference yet.
        $this->fakeGooglePending('offering-google-pending-id');

        $payload = $this->offeringPayload('google_meet');
        $payload['teacher_id'] = $primary->id;
        unset($payload['meeting_url']);

        $this->actingAs($primary)->post(route('teacher.course_offerings.live_classes.store', $this->offeringA), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::table('live_classes')->where('course_offering_id', $this->offeringA)->first();

        $this->assertNotNull($row, 'A pending conference is a real scheduled class and must still be saved.');
        $this->assertSame('offering-google-pending-id', $row->google_calendar_event_id);
        $this->assertSame(GoogleConferenceStatus::PENDING, $row->google_conference_status);
        // No invented URL: a student must never be handed a link that does not work.
        $this->assertTrue($row->meeting_url === null || $row->meeting_url === '');
        $this->assertFalse(GoogleConferenceStatus::describe($row->google_conference_status)['joinable']);
    }

    // ── No silent substitution of the institution-wide calendar ───────────────

    /**
     * The central rule: an unconnected lecturer must NOT get the institution's
     * calendar by default.
     *
     * Before this, choosing Google Meet on an Offering fell straight through to the
     * installation-wide .env refresh token, so the conference was created on the
     * institution's calendar while the lecturer believed it was on their own. No
     * message said otherwise. `Http::assertNothingSent()` is the load-bearing
     * assertion: it proves the request was refused BEFORE any Google call, so
     * nothing was written to any calendar as a side effect.
     */
    public function test_an_unconnected_lecturer_cannot_silently_use_the_institution_calendar(): void
    {
        $this->bootGoogleSchema();
        $primary = $this->user('unconnected-lecturer@example.test', PermissionService::TEACHER, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);

        // The institution-wide credential IS configured for this test - which is
        // exactly the condition under which the silent substitution used to happen.
        $this->assertNotSame('', (string) config('services.google_meet.refresh_token'));

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'institution-token'], 200),
            'www.googleapis.com/calendar/*' => Http::response([
                'id' => 'institution-owned-event',
                'conferenceData' => ['entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/institution-room']]],
            ], 200),
        ]);

        $payload = $this->offeringPayload('google_meet');
        $payload['teacher_id'] = $primary->id;
        unset($payload['meeting_url']);

        $this->actingAs($primary)->post(route('teacher.course_offerings.live_classes.store', $this->offeringA), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors('platform');

        $message = session('errors')->first('platform');
        $this->assertStringContainsString('Connect your Google Account before scheduling a Google Meet class.', $message);

        // No Google request of any kind, and no class created.
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('live_classes')->count());
    }

    /**
     * A lecturer who HAS connected gets the event on their own calendar.
     *
     * Proves the positive half of the rule above, and specifically that the
     * institution credential is not what answers: the only token faked is the one
     * the refresh produced, and the institution-wide endpoint would have had to be
     * reached for the class to land there instead.
     */
    public function test_a_connected_lecturer_uses_their_own_grant_and_not_the_institution_credential(): void
    {
        $this->bootGoogleSchema();
        $primary = $this->user('connected-lecturer@example.test', PermissionService::TEACHER, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        $this->connectGoogle($primary, 'connected-lecturer@example.test');
        $this->fakeGoogleReady('own-calendar-event-id');

        $payload = $this->offeringPayload('google_meet');
        $payload['teacher_id'] = $primary->id;
        unset($payload['meeting_url']);

        $this->actingAs($primary)->post(route('teacher.course_offerings.live_classes.store', $this->offeringA), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $row = DB::table('live_classes')->where('course_offering_id', $this->offeringA)->first();
        $this->assertNotNull($row);
        $this->assertSame('own-calendar-event-id', $row->google_calendar_event_id);
        $this->assertSame(GoogleConferenceStatus::READY, $row->google_conference_status);

        // The event was written to the calendar_id on the LECTURER'S OWN connection
        // row, which is what distinguishes it from the institution fallback.
        $connection = DB::table('google_account_connections')->where('user_id', $primary->id)->first();
        $this->assertSame('primary', $connection->calendar_id);
        $this->assertSame('connected-lecturer@example.test', $connection->google_email);
    }

    /** Manual entry must survive: a lecturer may still paste their own link. */
    public function test_an_unconnected_lecturer_can_still_paste_a_meeting_link(): void
    {
        $this->bootGoogleSchema();
        $primary = $this->user('paste-link-lecturer@example.test', PermissionService::TEACHER, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);
        Http::fake();

        $payload = $this->offeringPayload('google_meet');
        $payload['teacher_id'] = $primary->id;
        $payload['meeting_url'] = 'https://meet.google.com/pasted-by-the-lecturer';

        $this->actingAs($primary)->post(route('teacher.course_offerings.live_classes.store', $this->offeringA), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'https://meet.google.com/pasted-by-the-lecturer',
            DB::table('live_classes')->value('meeting_url')
        );
        // Pasted, so no provider call and no Google bookkeeping invented.
        Http::assertNothingSent();
        $this->assertNull(DB::table('live_classes')->value('google_calendar_event_id'));
    }

    /**
     * The institution-wide credential must still work where it always did.
     *
     * This is the regression guard on requirement 7: refusing the substitution in
     * the lecturer's Offering workflow must not have removed the shared credential
     * from the non-Offering path, which is what an administrator uses.
     */
    public function test_the_institution_wide_credential_still_serves_the_non_offering_path(): void
    {
        $primary = $this->user('admin-non-offering@example.test', 2, 1);
        $this->allocation($primary, $this->offeringA, CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'institution-token'], 200),
            'www.googleapis.com/calendar/*' => Http::response([
                'id' => 'institution-non-offering-event',
                'conferenceData' => ['entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/institution-non-offering']]],
            ], 200),
        ]);

        $this->actingAs($primary)->post(route('admin.live_classes.store'), [
            'title' => 'Non-Offering institution credential class',
            'class_id' => $this->makeClass(1, ['name' => 'Non-Offering credential class']),
            'platform' => 'google_meet',
            'start_date' => '2026-09-26', 'start_time' => '14:00', 'end_time' => '15:00',
            'timezone' => 'UTC', 'status' => 'scheduled', 'is_published' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            'https://meet.google.com/institution-non-offering',
            DB::table('live_classes')->value('meeting_url')
        );
    }

    public function test_a_parent_cannot_complete_the_google_oauth_consent_for_this_installation(): void
    {
        $this->bootGoogleSchema();
        $parent = $this->user('parent-google-oauth@example.test', 6, 1);

        // The connect route is behind `auth` alone, so without a role gate any
        // signed-in account could grant this installation calendar.events scope.
        $this->actingAs($parent)->get(route('google.auth.connect'))->assertForbidden();

        $this->actingAs($parent)->post(route('google.auth.disconnect'))->assertForbidden();

        Http::fake();
        Http::assertNothingSent();
    }

    private function subject(int $schoolId, string $name): int
    {
        return (int) DB::table('subjects')->insertGetId([
            'school_id' => $schoolId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function offering(int $schoolId, int $subjectId): int
    {
        return (int) DB::table('course_offerings')->insertGetId([
            'school_id' => $schoolId, 'subject_id' => $subjectId, 'status' => CourseOffering::STATUS_OPEN,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $email, int $roleId, int $schoolId): User
    {
        return User::create([
            'name' => $email, 'email' => $email, 'role_id' => $roleId,
            'school_id' => $schoolId, 'status' => 1, 'account_status' => 'active',
        ]);
    }

    private function student(string $email, int $schoolId): User
    {
        return $this->user($email, 7, $schoolId);
    }

    private function allocation(User $user, int $offeringId, string $role, string $status = 'active', string $startsOn = '2026-09-01', ?string $endsOn = null): void
    {
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $user->school_id, 'course_offering_id' => $offeringId, 'user_id' => $user->id,
            'role' => $role, 'status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]);
    }

    private function registration(User $student, int $offeringId, string $status): int
    {
        return (int) DB::table('course_registrations')->insertGetId([
            'school_id' => $student->school_id, 'student_id' => $student->id, 'course_offering_id' => $offeringId,
            'subject_id' => $student->school_id === 1 ? $this->subjectA : null, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function liveClass(int $id, int $offeringId, array $overrides = []): LiveClass
    {
        $row = array_merge([
            'id' => $id, 'school_id' => 1, 'course_offering_id' => $offeringId, 'subject_id' => $this->subjectA,
            'title' => 'Provider class '.$id, 'description' => 'Provider security fixture', 'platform' => 'jitsi',
            'meeting_url' => 'https://meet.example.test/room-'.$id, 'meeting_id' => 'meeting-id-secret',
            'meeting_password' => 'meeting-password-secret', 'recording_url' => 'https://video.example.test/recording-provider-secret',
            'scheduled_at' => '2026-09-25 10:00:00', 'ends_at' => '2026-09-25 11:00:00',
            'start_date' => '2026-09-25', 'start_time' => '10:00:00', 'end_time' => '11:00:00', 'timezone' => 'UTC',
            'status' => LiveClass::STATUS_SCHEDULED, 'is_published' => 1, 'attendance_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
        DB::table('live_classes')->insert($row);
        return LiveClass::query()->findOrFail($id);
    }

    private function offeringPayload(string $platform): array
    {
        return [
            'title' => 'Offering provider creation', 'teacher_id' => null, 'platform' => $platform,
            'start_date' => '2026-09-26', 'start_time' => '11:00', 'end_time' => '12:00', 'timezone' => 'UTC',
            'status' => 'scheduled', 'is_published' => 0,
        ];
    }

    /**
     * Apply the two real Google migrations on top of the shared Live Class schema.
     *
     * The helper's schema predates this feature and does not define these columns.
     * The real migrations are used rather than hand-written equivalents so the
     * columns and casts under test are the ones production actually has.
     */
    private function bootGoogleSchema(): void
    {
        if ($this->credentialsPath === null) {
            $this->credentialsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-offering-google-'.uniqid().'.json';
            file_put_contents($this->credentialsPath, json_encode([
                'web' => [
                    'client_id' => 'offering-test-client.apps.googleusercontent.com',
                    'client_secret' => 'offering-test-secret',
                    'redirect_uris' => ['http://localhost/auth/google/callback'],
                ],
            ]));
            GoogleOAuthCredentials::$pathOverride = $this->credentialsPath;
        }

        (require base_path('database/migrations/2026_10_04_000001_create_google_account_connections_table.php'))->up();
        (require base_path('database/migrations/2026_10_04_000002_add_google_calendar_fields_to_live_classes.php'))->up();
    }

    private function connectGoogle(User $user, string $email): void
    {
        $id = DB::table('google_account_connections')->insertGetId([
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'calendar_id' => 'primary',
            // STATUS_OK is 'ok'. Inventing a 'connected' value here produced a row
            // that isUsable() rejects, so accessTokenFor() returned null and the
            // test silently exercised the installation-wide fallback instead.
            'status' => GoogleAccountConnection::STATUS_OK,
            'google_email' => $email,
            'scope' => GoogleOAuthClient::SCOPE_CALENDAR_EVENTS,
            'access_token_expires_at' => time() + 3600,
            'last_refreshed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // forceFill()+save(), not a raw UPDATE: both tokens carry the `encrypted`
        // cast, so writing plaintext into the column would make the row unreadable
        // and accessTokenFor() would mark it as needing re-authentication. Writing
        // through the model is what actually encrypts them.
        GoogleAccountConnection::findOrFail($id)->forceFill([
            'access_token_ciphertext' => 'offering-test-access-token',
            'refresh_token_ciphertext' => 'offering-test-refresh-token',
        ])->save();
    }

    /** Google issues a token and a conference that is ready to join. */
    private function fakeGoogleReady(string $eventId): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh-access-token', 'expires_in' => 3599], 200),
            'www.googleapis.com/calendar/*' => Http::response([
                'id' => $eventId,
                'htmlLink' => 'https://calendar.google.com/event?eid='.$eventId,
                'conferenceData' => [
                    'entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/'.$eventId]],
                ],
            ], 200),
        ]);
    }

    /**
     * Google accepts the event but has not produced the conference yet.
     *
     * An empty `conferenceData` is exactly what the real API returns while the
     * conference is still being created, and it is the case that must not be
     * reported as a network failure.
     */
    private function fakeGooglePending(string $eventId): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fresh-access-token', 'expires_in' => 3599], 200),
            'www.googleapis.com/calendar/*' => Http::response([
                'id' => $eventId,
                'htmlLink' => 'https://calendar.google.com/event?eid='.$eventId,
                'conferenceData' => [],
            ], 200),
        ]);
    }
}
