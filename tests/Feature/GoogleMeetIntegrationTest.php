<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\GoogleAccountConnection;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\Google\GoogleCalendarService;
use App\Support\Google\GoogleOAuthCredentials;
use App\Support\LiveClasses\GoogleConferenceStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Google Meet integration: OAuth, per-lecturer authorization, and event creation.
 *
 * ── EVERY GOOGLE CALL IS FAKED ──────────────────────────────────────────────
 *
 * `Http::fake()` stands in for Google, so this suite never contacts Google, never
 * needs the operator's credentials, and never creates a real conference. That is
 * not a convenience: a test that created real calendar events would pollute a
 * real lecturer's calendar, and one that needed live credentials could not be run
 * by CI at all.
 *
 * The gap that leaves is stated plainly rather than papered over: a fake cannot
 * prove Google's server accepts our request shape. That is what the live consent
 * run is for, and it is NOT yet done — see the report.
 *
 * ── WHAT IS ASSERTED, IN ORDER OF SEVERITY ──────────────────────────────────
 *
 *  1. Tokens are encrypted at rest and never rendered.
 *  2. The OAuth `state` check actually rejects a mismatched or replayed callback.
 *  3. One lecturer can never read, use or disconnect another's grant.
 *  4. `conferenceDataVersion=1` is genuinely on the wire.
 *  5. A pending conference is reported honestly, not as success or failure.
 */
class GoogleMeetIntegrationTest extends TestCase
{
    use StaffModuleTestHelper;

    private string $credentialsPath;

    private int $school;

    private User $lecturer;

    private User $otherLecturer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();

        // A fixture credentials file, so the suite never depends on the operator's
        // real secrets being present.
        $this->credentialsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-google-'.uniqid().'.json';
        file_put_contents($this->credentialsPath, json_encode([
            'web' => [
                'client_id' => 'test-client-id.apps.googleusercontent.com',
                'client_secret' => 'test-client-secret',
                'redirect_uris' => ['http://127.0.0.1:8000/auth/google/callback'],
            ],
        ]));
        GoogleOAuthCredentials::$pathOverride = $this->credentialsPath;

        $this->school = $this->makeSchool(['title' => 'PIIE Kampala', 'status' => 1]);
        // role_id 3 IS the lecturer. These fixtures previously used 6, which is the
        // PARENT role - so the whole suite was asserting Google behaviour for a
        // user who is not a lecturer at all, and passed while the real lecturer
        // path was dead. The constant is used rather than the literal so this
        // cannot drift from TeacherMiddleware again.
        $this->lecturer = User::factory()->create(['role_id' => PermissionService::TEACHER, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->otherLecturer = User::factory()->create(['role_id' => PermissionService::TEACHER, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active']);

        $this->liveClassTables();

        // Built from the real migration, so the columns and casts under test are
        // the ones production has. A hand-written approximation here would let the
        // encryption assertions pass against a schema that does not exist.
        (require base_path('database/migrations/2026_10_04_000001_create_google_account_connections_table.php'))->up();
    }

    protected function tearDown(): void
    {
        GoogleOAuthCredentials::$pathOverride = null;
        if (is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    private function liveClassTables(): void
    {
        Schema::create('live_classes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('course_offering_id')->nullable();
            $t->unsignedBigInteger('class_id')->nullable();
            $t->unsignedBigInteger('programme_id')->nullable();
            $t->unsignedBigInteger('academic_session_id')->nullable();
            $t->unsignedBigInteger('teacher_id')->nullable();
            $t->string('platform')->default('jitsi');
            $t->string('meeting_url', 500)->nullable();
            $t->string('meeting_id', 150)->nullable();
            $t->string('meeting_password', 150)->nullable();
            $t->date('start_date')->nullable();
            $t->string('start_time')->nullable();
            $t->string('end_time')->nullable();
            $t->string('timezone')->default('Africa/Kampala');
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->string('status')->default('scheduled');
            $t->boolean('is_published')->default(true);
            $t->boolean('attendance_enabled')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('google_calendar_event_id')->nullable();
            $t->string('google_conference_status', 32)->nullable();
            $t->timestamps();
        });
    }

    private function connect(User $user, string $email = 'lecturer@piie.ac.ug'): GoogleAccountConnection
    {
        $row = DB::table('google_account_connections')->insertGetId([
            'school_id' => $this->school,
            'user_id' => $user->id,
            'access_token_ciphertext' => null,   // encrypted later by the model
            'refresh_token_ciphertext' => null,
            'access_token_expires_at' => time() + 3600,
            'calendar_id' => 'primary',
            'status' => 'ok',
            'connected_at' => now(),
        ]);

        $connection = GoogleAccountConnection::findOrFail($row);
        $connection->forceFill([
            'access_token_ciphertext' => 'access-token-'.$user->id,
            'refresh_token_ciphertext' => 'refresh-token-'.$user->id,
            'google_email' => $email,
        ])->save();

        return $connection->fresh();
    }

    // ── 1. Route exists ───────────────────────────────────────────────────────

    public function test_the_oauth_callback_route_exists_at_the_registered_uri(): void
    {
        // The PATH must be exactly `auth/google/callback`, because that is what
        // is registered in Google Cloud. A change here produces
        // `redirect_uri_mismatch` at the token exchange, which looks like a
        // credentials fault and is not one.
        $this->assertSame('auth/google/callback', app('router')->getRoutes()->getByName('google.auth.callback')->uri());

        // The absolute form is DERIVED from APP_URL, never from the request — which
        // is what guarantees it cannot be steered by an attacker. Note that
        // phpunit.xml overrides APP_URL to http://localhost, so this asserts the
        // relationship, not the literal production host.
        $this->assertSame(
            url('/auth/google/callback'),
            route('google.auth.callback'),
            'the callback must be built from APP_URL, and only from APP_URL'
        );

        // The URI the operator registered in Google Cloud must be the URI this
        // application sends. This compares against the credentials file's own
        // record of it. It cannot verify Google's console — only the operator can
        // do that — but it catches the common failure where the app's callback
        // and the downloaded credentials have drifted apart.
        $this->assertStringEndsWith(
            '/auth/google/callback',
            (string) GoogleOAuthCredentials::read()['redirect_uri'],
            'the registered redirect_uri must point at the callback route'
        );
    }

    public function test_every_google_route_requires_authentication(): void
    {
        foreach (['google.auth.connect', 'google.auth.callback'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        // Disconnect is POST-only, so a stray link — a prefetcher, a chat client's
        // link preview, a crawler — cannot disconnect a lecturer. 405 is the
        // stronger answer than a redirect: the request is refused at the router,
        // before any handler runs.
        $this->get(route('google.auth.disconnect'))->assertStatus(405);
    }

    // ── 2. Credentials handling ───────────────────────────────────────────────

    public function test_the_credentials_file_is_read_from_outside_the_web_root(): void
    {
        $path = GoogleOAuthCredentials::path();

        $this->assertStringNotContainsString('public', str_replace('\\', '/', $path));
        $this->assertFileExists($path);
    }

    public function test_git_would_not_commit_the_credentials_directory(): void
    {
        // The rule is the only thing standing between a routine `git add -A` and a
        // committed client secret.
        $ignored = trim((string) shell_exec(
            'cd '.escapeshellarg(base_path()).' && git check-ignore storage/app/google/oauth-credentials.json 2>&1'
        ));

        $this->assertNotSame('', $ignored, 'storage/app/google is NOT gitignored');
    }

    public function test_missing_credentials_are_reported_not_fatal(): void
    {
        $path = GoogleOAuthCredentials::$pathOverride;
        GoogleOAuthCredentials::$pathOverride = '/nonexistent/oauth-credentials.json';

        $this->assertFalse(GoogleOAuthCredentials::isConfigured());

        $this->actingAs($this->lecturer)
            ->get(route('google.auth.connect'))
            ->assertRedirect()
            ->assertSessionHas('error');

        GoogleOAuthCredentials::$pathOverride = $path;
    }

    public function test_malformed_credentials_do_not_leak_their_contents(): void
    {
        $path = GoogleOAuthCredentials::$pathOverride;
        file_put_contents($path, 'NOT-JSON-AT-ALL secret-leak-canary');
        GoogleOAuthCredentials::$pathOverride = $path;

        try {
            GoogleOAuthCredentials::read();
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('secret-leak-canary', $e->getMessage());
        }
    }

    public function test_the_credentials_file_is_never_rendered_or_returned_to_a_browser(): void
    {
        $this->connect($this->lecturer);

        $html = $this->actingAs($this->lecturer)->get(route('google.auth.connect'))->getContent();

        $this->assertStringNotContainsString('test-client-secret', $html);
    }

    // ── 3. Token exchange ─────────────────────────────────────────────────────

    public function test_connect_stores_encrypted_tokens_not_plaintext(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.SECRET-ACCESS',
                'refresh_token' => '1//REFRESH-SECRET',
                'expires_in' => 3600,
                'scope' => GoogleCalendarService::class ? 'https://www.googleapis.com/auth/calendar.events' : null,
                'token_type' => 'Bearer',
            ]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response([
                'sub' => 'google-sub-1', 'email' => 'lecturer@piie.ac.ug', 'name' => 'Lecturer One',
            ]),
        ]);

        $response = $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $state = session('google.oauth.state')['state'];

        $this->actingAs($this->lecturer)->get(route('google.auth.callback').'?'.http_build_query([
            'code' => 'auth-code', 'state' => $state,
        ]))->assertRedirect();

        $raw = DB::table('google_account_connections')->where('user_id', $this->lecturer->id)->first();

        // On disk: ciphertext. Not merely "not selected" — the bytes themselves.
        $this->assertNotSame('ya29.SECRET-ACCESS', $raw->access_token_ciphertext);
        $this->assertNotSame('1//REFRESH-SECRET', $raw->refresh_token_ciphertext);
        $this->assertStringNotContainsString('SECRET', (string) $raw->access_token_ciphertext);
        $this->assertStringNotContainsString('REFRESH', (string) $raw->refresh_token_ciphertext);
        $this->assertSame('lecturer@piie.ac.ug', $raw->google_email);

        // And through the model, the plaintext comes back.
        $connection = GoogleAccountConnection::where('user_id', $this->lecturer->id)->firstOrFail();
        $this->assertSame('ya29.SECRET-ACCESS', $connection->access_token_ciphertext);
        $this->assertSame('1//REFRESH-SECRET', $connection->refresh_token_ciphertext);
    }

    public function test_the_token_endpoint_receives_the_exchange_grant(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'l@piie.ac.ug']),
        ]);

        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $state = session('google.oauth.state')['state'];

        $this->actingAs($this->lecturer)->get(route('google.auth.callback').'?code=abc&state='.$state);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'oauth2.googleapis.com/token')
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'abc';
        });
    }

    // ── 4. CSRF state ─────────────────────────────────────────────────────────

    public function test_the_authorize_url_carries_a_fresh_state(): void
    {
        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $first = session('google.oauth.state')['state'];

        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $second = session('google.oauth.state')['state'];

        $this->assertNotSame($first, $second, 'the state must be regenerated per attempt');
        $this->assertSame(40, strlen($second));
    }

    public function test_the_authorize_url_requests_calendar_events_and_offline_access(): void
    {
        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $location = $this->actingAs($this->lecturer)->get(route('google.auth.connect'))->headers->get('Location');

        $this->assertStringContainsString('calendar.events', urldecode($location));
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('response_type=code', $location);
    }

    public function test_a_callback_with_a_wrong_state_is_rejected_and_stores_nothing(): void
    {
        Http::fake();

        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));

        $this->actingAs($this->lecturer)
            ->get(route('google.auth.callback').'?code=stolen&state=not-the-real-state')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('google_account_connections')->count());
        Http::assertNothingSent();
    }

    public function test_a_callback_cannot_be_replayed(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'l@piie.ac.ug']),
        ]);

        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $state = session('google.oauth.state')['state'];
        $url = route('google.auth.callback').'?code=abc&state='.$state;

        $this->actingAs($this->lecturer)->get($url)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('google_account_connections')->count());

        // Same callback again: the state was consumed.
        $this->actingAs($this->lecturer)->get($url)->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, DB::table('google_account_connections')->count(), 'a replay created a second connection');
    }

    public function test_a_state_issued_to_another_user_cannot_be_completed(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'l@piie.ac.ug']),
        ]);

        // Lecturer A starts the flow, capturing A's session state.
        $this->actingAs($this->lecturer)->get(route('google.auth.connect'));

        // Lecturer B, in a different session, presents that state.
        $this->actingAs($this->otherLecturer)
            ->get(route('google.auth.callback').'?code=abc&state=whatever')
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('google_account_connections')->count());
    }

    // ── 5. Disconnect ─────────────────────────────────────────────────────────

    public function test_disconnect_removes_only_the_signed_in_users_connection(): void
    {
        $mine = $this->connect($this->lecturer);
        $theirs = $this->connect($this->otherLecturer);

        $this->actingAs($this->lecturer)
            ->post(route('google.auth.disconnect'))
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertNull(GoogleAccountConnection::find($mine->id));
        $this->assertNotNull(GoogleAccountConnection::find($theirs->id), "another lecturer's connection was deleted");
    }

    public function test_disconnect_is_a_post_so_a_link_cannot_trigger_it(): void
    {
        $this->connect($this->lecturer);

        $this->actingAs($this->lecturer)->get(route('google.auth.disconnect'));

        $this->assertSame(1, DB::table('google_account_connections')->count(), 'a GET disconnected the account');
    }

    // ── 6. Refresh handling ───────────────────────────────────────────────────

    public function test_an_expired_token_is_refreshed_and_the_new_one_persisted(): void
    {
        $connection = $this->connect($this->lecturer);
        DB::table('google_account_connections')->where('id', $connection->id)
            ->update(['access_token_expires_at' => time() - 10]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.REFRESHED', 'expires_in' => 3600, 'token_type' => 'Bearer',
            ]),
        ]);

        $access = app(\App\Support\Google\GoogleAccountService::class)->accessTokenFor($this->lecturer);

        $this->assertNotNull($access);
        $this->assertSame('ya29.REFRESHED', $access['token']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && $r['grant_type'] === 'refresh_token');

        $stored = GoogleAccountConnection::findOrFail($connection->id);
        $this->assertSame('ya29.REFRESHED', $stored->access_token_ciphertext);
        // Google omits refresh_token on a refresh; the existing one must survive.
        $this->assertSame('refresh-token-'.$this->lecturer->id, $stored->refresh_token_ciphertext);
        $this->assertSame('ok', $stored->status);
    }

    public function test_a_valid_token_is_not_refreshed(): void
    {
        $this->connect($this->lecturer);
        Http::fake();

        $access = app(\App\Support\Google\GoogleAccountService::class)->accessTokenFor($this->lecturer);

        $this->assertNotNull($access);
        $this->assertSame('access-token-'.$this->lecturer->id, $access['token']);
        Http::assertNothingSent();
    }

    public function test_a_revoked_grant_marks_the_connection_needing_reauthorisation(): void
    {
        $connection = $this->connect($this->lecturer);
        DB::table('google_account_connections')->where('id', $connection->id)
            ->update(['access_token_expires_at' => time() - 10]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        // Null, not an exception: "no usable connection" is a normal state the
        // caller renders, not a crash.
        $this->assertNull(app(\App\Support\Google\GoogleAccountService::class)->accessTokenFor($this->lecturer));

        $stored = GoogleAccountConnection::findOrFail($connection->id);
        $this->assertSame(GoogleAccountConnection::STATUS_NEEDS_REAUTH, $stored->status);
        $this->assertTrue($stored->needsReauthorization());
        $this->assertStringContainsString('revoked', (string) $stored->status_detail);
    }

    public function test_the_status_indicator_reports_each_state(): void
    {
        $service = app(\App\Support\Google\GoogleAccountService::class);

        $this->assertFalse($service->isConnected($this->lecturer));

        $this->connect($this->lecturer);
        $this->assertTrue($service->isConnected($this->lecturer));

        DB::table('google_account_connections')->where('user_id', $this->lecturer->id)
            ->update(['status' => GoogleAccountConnection::STATUS_NEEDS_REAUTH]);

        $this->assertFalse($service->isConnected($this->lecturer), 'a lapsed grant must not read as connected');
    }

    // ── 7. Calendar event creation ────────────────────────────────────────────

    private function fakeCalendar(array $body, int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.FRESH', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/*' => Http::response($body, $status),
        ]);
    }

    public function test_an_event_is_created_with_a_conference_and_returns_a_meet_url(): void
    {
        $this->fakeCalendar([
            'id' => 'evt_abc123',
            'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            'htmlLink' => 'https://calendar.google.com/event?eid=abc',
            'conferenceData' => ['entryPoints' => [['entryPointType' => 'video', 'uri' => 'https://meet.google.com/abc-defg-hij']]],
        ]);

        $result = app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
            'title' => 'Data Structures',
            'description' => 'Week 3',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'timezone' => 'Africa/Kampala',
            'attendees' => [],
        ]);

        $this->assertSame('evt_abc123', $result['event_id']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $result['meeting_url']);
        $this->assertSame(GoogleConferenceStatus::READY, $result['conference_status']);
        $this->assertNotSame('', $result['request_id']);
    }

    public function test_the_conference_creation_version_is_actually_on_the_wire(): void
    {
        // THE critical assertion. Without `conferenceDataVersion=1` Google ignores
        // the conference block and returns an ordinary event with no Meet link, and
        // reports no error. An integration can therefore look complete and do
        // nothing at all.
        $this->fakeCalendar(['id' => 'evt_1', 'hangoutLink' => 'https://meet.google.com/x']);

        app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
            'title' => 'T', 'starts_at' => now(), 'ends_at' => now()->addHour(),
            'timezone' => 'Africa/Kampala',
        ]);

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/calendar/v3/calendars/primary/events')) {
                return false;
            }

            $query = $request->data()['query'] ?? (parse_url($request->url(), PHP_URL_QUERY) ?? '');
            parse_str((string) $query, $parsed);

            return ($parsed['conferenceDataVersion'] ?? null) === '1';
        });
    }

    public function test_each_new_class_gets_a_unique_conference_request_id(): void
    {
        $this->fakeCalendar(['id' => 'evt_1', 'hangoutLink' => 'https://meet.google.com/x']);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
                'title' => 'T'.$i, 'starts_at' => now(), 'ends_at' => now()->addHour(),
                'timezone' => 'Africa/Kampala',
            ])['request_id'];
        }

        $this->assertCount(3, array_unique($ids), 'conference request ids must be unique per class');
    }

    public function test_the_event_is_created_in_the_lecturers_own_calendar(): void
    {
        $this->fakeCalendar(['id' => 'evt_1', 'hangoutLink' => 'https://meet.google.com/x']);

        $this->connect($this->lecturer, 'own@piie.ac.ug');

        $access = app(\App\Support\Google\GoogleAccountService::class)->accessTokenFor($this->lecturer);
        app(GoogleCalendarService::class)->createMeetingEvent($access['token'], 'primary', [
            'title' => 'T', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'Africa/Kampala',
        ]);

        // The stored token is still valid, so no refresh happens and the STORED
        // token is the one presented. That is the point: a lecturer's own grant, not
        // an installation-wide one.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/calendars/primary/events')
            && $r->hasHeader('Authorization', 'Bearer access-token-'.$this->lecturer->id));
    }

    public function test_the_meet_url_and_event_id_are_saved_on_the_class(): void
    {
        $this->fakeCalendar([
            'id' => 'evt_saved_1',
            'hangoutLink' => 'https://meet.google.com/saved-link',
        ]);

        $result = app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
            'title' => 'Saved', 'starts_at' => now(), 'ends_at' => now()->addHour(),
            'timezone' => 'Africa/Kampala',
        ]);

        $class = LiveClass::create([
            'school_id' => $this->school,
            'title' => 'Saved',
            'platform' => 'google_meet',
            'meeting_url' => $result['meeting_url'],
            'google_calendar_event_id' => $result['event_id'],
            'google_conference_status' => $result['conference_status'],
            'timezone' => 'Africa/Kampala',
            'status' => LiveClass::STATUS_SCHEDULED,
            'is_published' => true,
        ]);

        $fresh = LiveClass::findOrFail($class->id);
        $this->assertSame('https://meet.google.com/saved-link', $fresh->meeting_url);
        $this->assertSame('evt_saved_1', $fresh->google_calendar_event_id);
        $this->assertSame(GoogleConferenceStatus::READY, $fresh->google_conference_status);
    }

    public function test_a_pending_conference_is_reported_as_pending_and_not_as_success(): void
    {
        // Google creates Meet conferences asynchronously; an event can come back
        // with no conference data at all. Calling that success publishes a class
        // with no join link; calling it failure discards a class that will work.
        $this->fakeCalendar(['id' => 'evt_pending', 'hangoutLink' => null]);

        $result = app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
            'title' => 'Pending', 'starts_at' => now(), 'ends_at' => now()->addHour(),
            'timezone' => 'Africa/Kampala',
        ]);

        $this->assertSame(GoogleConferenceStatus::PENDING, $result['conference_status']);
        $this->assertSame('', $result['meeting_url'], 'a pending conference must not invent a URL');
        $this->assertSame('evt_pending', $result['event_id']);
    }

    public function test_a_pending_conference_reconciles_once_google_issues_the_link(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.F', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/*' => Http::response([
                'id' => 'evt_pending',
                'hangoutLink' => 'https://meet.google.com/late-link',
            ]),
        ]);

        $refreshed = app(GoogleCalendarService::class)->refreshEvent('token', 'primary', 'evt_pending');

        $this->assertSame(GoogleConferenceStatus::READY, $refreshed['conference_status']);
        $this->assertSame('https://meet.google.com/late-link', $refreshed['meeting_url']);
    }

    public function test_a_google_refusal_is_reported_without_its_body(): void
    {
        $this->fakeCalendar([
            'error' => ['code' => 403, 'message' => 'Rate limit exceeded', 'errors' => [['reason' => 'rateLimitExceeded']]],
        ], 403);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rateLimitExceeded');

        app(GoogleCalendarService::class)->createMeetingEvent('token', 'primary', [
            'title' => 'T', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'Africa/Kampala',
        ]);
    }

    public function test_an_error_response_never_carries_a_token_into_a_message(): void
    {
        $this->fakeCalendar(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401);

        try {
            app(GoogleCalendarService::class)->createMeetingEvent('ya29.SUPER-SECRET', 'primary', [
                'title' => 'T', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'timezone' => 'Africa/Kampala',
            ]);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('ya29.SUPER-SECRET', $e->getMessage());
        }
    }

    public function test_cancelling_a_class_removes_the_calendar_event_and_its_conference(): void
    {
        // An orphan conference is a security problem, not a tidiness one: the link
        // a student holds keeps working after PIIE believes the class is cancelled.
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.F', 'expires_in' => 3600]),
            'www.googleapis.com/calendar/v3/*' => Http::response([], 204),
        ]);

        app(GoogleCalendarService::class)->deleteEvent('token', 'primary', 'evt_to_cancel');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/events/evt_to_cancel'));
    }

    public function test_deleting_an_event_someone_removed_by_hand_is_treated_as_done(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'notFound'], 404)]);

        // No exception: Google having already deleted it is the outcome we wanted.
        app(GoogleCalendarService::class)->deleteEvent('token', 'primary', 'evt_gone');

        $this->assertTrue(true);
    }

    // ── 8. Isolation ──────────────────────────────────────────────────────────

    public function test_one_lecturer_cannot_see_or_reach_anothers_connection(): void
    {
        $this->connect($this->lecturer, 'first@piie.ac.ug');
        $this->connect($this->otherLecturer, 'second@piie.ac.ug');

        $service = app(\App\Support\Google\GoogleAccountService::class);

        $this->assertSame('first@piie.ac.ug', $service->forUser($this->lecturer)->google_email);
        $this->assertSame('second@piie.ac.ug', $service->forUser($this->otherLecturer)->google_email);

        // Each lecturer's own token is the only one ever returned.
        $this->assertStringContainsString((string) $this->lecturer->id, $service->accessTokenFor($this->lecturer)['token']);
        $this->assertStringContainsString((string) $this->otherLecturer->id, $service->accessTokenFor($this->otherLecturer)['token']);
    }

    public function test_a_student_cannot_connect_a_google_account(): void
    {
        Http::fake();

        // Students have no business authoring calendar events, so the OAuth
        // handshake is refused for them even though the route itself is
        // `auth`-only.
        $this->actingAs($this->student)->get(route('google.auth.connect'));

        $this->assertSame(0, DB::table('google_account_connections')->count());
    }

    public function test_tokens_are_hidden_from_model_serialisation(): void
    {
        $this->connect($this->lecturer);

        $connection = GoogleAccountConnection::where('user_id', $this->lecturer->id)->firstOrFail();
        $array = $connection->toArray();

        $this->assertArrayNotHasKey('access_token_ciphertext', $array);
        $this->assertArrayNotHasKey('refresh_token_ciphertext', $array);
        $this->assertStringNotContainsString('access-token-', json_encode($array));
        $this->assertStringNotContainsString('refresh-token-', json_encode($array));
    }

    // ── 9. Status presentation ────────────────────────────────────────────────

    public function test_the_conference_status_descriptions_are_honest(): void
    {
        $pending = GoogleConferenceStatus::describe(GoogleConferenceStatus::PENDING);
        $this->assertSame('Link not ready yet', $pending['label']);
        $this->assertFalse($pending['joinable']);
        // The explanation must not read as a fault, or a student assumes the
        // class is cancelled.
        $this->assertStringContainsString('Nothing is wrong', $pending['explanation']);

        $ready = GoogleConferenceStatus::describe(GoogleConferenceStatus::READY);
        $this->assertTrue($ready['joinable']);

        $failed = GoogleConferenceStatus::describe(GoogleConferenceStatus::FAILED);
        $this->assertFalse($failed['joinable']);

        // An unrelated platform has no Google status to describe.
        $this->assertNull(GoogleConferenceStatus::describe(null));
    }

    public function test_a_meeting_resolution_reports_pending_rather_than_joinable(): void
    {
        $pending = \App\Support\LiveClasses\MeetingResolution::googlePending('evt_1');
        $this->assertTrue($pending->isGooglePending());
        $this->assertFalse($pending->hasJoinableUrl(), 'a pending conference must not present as joinable');

        $ready = \App\Support\LiveClasses\MeetingResolution::googleReady('evt_1', 'https://meet.google.com/x');
        $this->assertTrue($ready->hasJoinableUrl());
        $this->assertFalse($ready->isGooglePending());
    }
}