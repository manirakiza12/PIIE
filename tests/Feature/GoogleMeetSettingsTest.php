<?php

namespace Tests\Feature;

use App\Models\GoogleAccountConnection;
use App\Models\GoogleMeetSetting;
use App\Models\User;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleMeetSettingsTest extends TestCase
{
    use CreatesPublicSiteSchema;
    private User $root;
    private const CLIENT = '123456-test.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();
        (require database_path('migrations/2026_10_04_000001_create_google_account_connections_table.php'))->up();
        (require database_path('migrations/2026_10_14_140000_create_google_meet_settings_table.php'))->up();
        GoogleOAuthCredentials::$pathOverride = '/nonexistent/piie-isolated-google-credentials.json';
        $this->root = $this->userWithRole(1, 'root@example.test');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        GoogleOAuthCredentials::$pathOverride = null;
        parent::tearDown();
    }

    private function save(array $changes = [])
    {
        $response = $this->actingAs($this->root)->post(route('superadmin.google_meet.save'), array_replace([
            'school_id' => 1, 'client_id' => self::CLIENT, 'client_secret' => 'private-client-secret', 'calendar_id' => 'primary', 'enabled' => 1,
        ], $changes));
        GoogleOAuthCredentials::$pathOverride = null;
        return $response;
    }

    public function test_settings_encrypt_credentials_and_never_render_or_flash_the_secret(): void
    {
        $this->save()->assertRedirect(route('superadmin.google_meet.settings'));
        $this->assertNotSame('private-client-secret', DB::table('google_meet_settings')->value('client_secret'));
        $this->assertSame('private-client-secret', GoogleOAuthCredentials::read()['client_secret']);
        $this->actingAs($this->root)->get(route('superadmin.google_meet.settings'))->assertOk()->assertSee(self::CLIENT)->assertDontSee('private-client-secret')->assertHeader('Cache-Control', 'no-store, private');
        $this->save(['school_id' => 999])->assertSessionHasErrors('school_id');
        $this->assertNull(session('_old_input.client_secret'));
    }

    public function test_only_superadmin_can_view_or_modify_setup(): void
    {
        foreach ([2,3,7] as $role) {
            $user = $this->userWithRole($role, "role$role@example.test");
            $this->actingAs($user)->get(route('superadmin.google_meet.settings'))->assertForbidden();
            $this->actingAs($user)->post(route('superadmin.google_meet.save'), [])->assertForbidden();
            $this->actingAs($user)->post(route('superadmin.google_meet.verify'))->assertForbidden();
            $this->actingAs($user)->post(route('superadmin.google_meet.disconnect'))->assertForbidden();
        }
        $this->assertSame(0, GoogleMeetSetting::count());
    }

    public function test_blank_secret_is_preserved_and_disabled_setup_does_not_fall_back(): void
    {
        $this->save();
        $this->save(['client_secret' => '', 'enabled' => 0]);
        $this->assertFalse(GoogleOAuthCredentials::isConfigured());
        $this->save(['client_secret' => '', 'enabled' => 1]);
        $this->assertSame('private-client-secret', GoogleOAuthCredentials::read()['client_secret']);
    }

    public function test_changing_client_invalidates_grants_and_pending_oauth_flow(): void
    {
        $this->save();
        GoogleAccountConnection::create(['user_id' => $this->root->id, 'access_token_ciphertext' => 'access', 'refresh_token_ciphertext' => 'refresh', 'status' => 'ok']);
        $this->actingAs($this->root)->get(route('google.auth.connect', ['return' => '/superadmin/settings/google-meet']));
        $state = session('google.oauth.state.state');
        $this->save(['client_id' => '654321-other.apps.googleusercontent.com']);
        $this->assertSame('needs_reauth', GoogleAccountConnection::first()->status);
        $this->assertNull(GoogleAccountConnection::first()->refresh_token_ciphertext);
        $this->actingAs($this->root)->get(route('google.auth.callback', ['state' => $state, 'code' => 'old-code']))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_web_json_can_be_imported_but_desktop_clients_are_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('google.json', json_encode(['web' => ['client_id' => self::CLIENT, 'client_secret' => 'json-secret']]));
        $this->save(['client_id' => null, 'client_secret' => null, 'credentials_file' => $file])->assertSessionHasNoErrors();
        $this->assertSame('json-secret', GoogleOAuthCredentials::read()['client_secret']);
        $bad = UploadedFile::fake()->createWithContent('desktop.json', '{"installed":{"client_id":"bad"}}');
        $this->save(['credentials_file' => $bad])->assertSessionHasErrors('credentials_file');
    }

    public function test_superadmin_connects_and_verifies_institution_account_without_a_meeting_write(): void
    {
        $this->save();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token'=>'access-test','refresh_token'=>'refresh-test','expires_in'=>3600,'scope'=>'https://www.googleapis.com/auth/calendar.events openid email']),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email'=>'institution@example.test','sub'=>'owner-test']),
            'www.googleapis.com/calendar/v3/*' => Http::response(['kind'=>'calendar#events']),
        ]);
        $this->actingAs($this->root)->get(route('google.auth.connect', ['return' => '/superadmin/settings/google-meet']))->assertRedirect();
        $state = session('google.oauth.state.state');
        $this->actingAs($this->root)->get(route('google.auth.callback', ['code'=>'test-code','state'=>$state]))->assertRedirect('/superadmin/settings/google-meet');
        $this->assertSame($this->root->id, (int) GoogleMeetSetting::find(1)->platform_user_id);
        $this->actingAs($this->root)->from(route('superadmin.google_meet.settings'))->post(route('superadmin.google_meet.verify'))->assertSessionHas('message');
        $this->assertNotNull(GoogleMeetSetting::find(1)->verified_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/calendar/v3/') && $request->method() !== 'GET');
        $this->actingAs($this->root)->get(route('superadmin.google_meet.settings'))->assertSee('institution@example.test')->assertDontSee('refresh-test')->assertDontSee('access-test');
    }

    public function test_readiness_failure_is_safe_and_does_not_leave_a_verified_status(): void
    {
        $this->save();
        GoogleAccountConnection::create(['user_id'=>$this->root->id,'access_token_ciphertext'=>'access-test','refresh_token_ciphertext'=>'refresh-test','access_token_expires_at'=>time()+3600,'status'=>'ok']);
        GoogleMeetSetting::find(1)->update(['platform_user_id'=>$this->root->id,'verified_at'=>now()]);
        Http::fake(['www.googleapis.com/*'=>Http::response(['error'=>['message'=>'private-client-secret']],403)]);
        $this->actingAs($this->root)->post(route('superadmin.google_meet.verify'))->assertSessionHas('error');
        $this->assertNull(GoogleMeetSetting::find(1)->verified_at);
        $this->assertStringNotContainsString('private-client-secret', session('error'));
    }

    public function test_reconnecting_same_account_without_new_refresh_token_preserves_its_grant(): void
    {
        $this->save();
        GoogleAccountConnection::create(['user_id'=>$this->root->id,'google_subject_id'=>'same-owner','access_token_ciphertext'=>'old-access','refresh_token_ciphertext'=>'old-refresh','status'=>'ok']);
        Http::fake([
            'oauth2.googleapis.com/token'=>Http::response(['access_token'=>'new-access','expires_in'=>3600]),
            'www.googleapis.com/oauth2/v3/userinfo'=>Http::response(['sub'=>'same-owner','email'=>'owner@example.test']),
        ]);
        app(\App\Support\Google\GoogleAccountService::class)->connect($this->root, 'code', \App\Support\Google\GoogleMeetSetup::callbackUrl());
        $this->assertSame('old-refresh', GoogleAccountConnection::first()->refresh_token_ciphertext);
        $this->assertSame('new-access', GoogleAccountConnection::first()->access_token_ciphertext);
    }

    public function test_different_account_cannot_inherit_a_previous_refresh_token(): void
    {
        $this->save();
        GoogleAccountConnection::create(['user_id'=>$this->root->id,'google_subject_id'=>'previous-owner','access_token_ciphertext'=>'old-access','refresh_token_ciphertext'=>'old-refresh','status'=>'ok']);
        Http::fake([
            'oauth2.googleapis.com/token'=>Http::response(['access_token'=>'new-access','expires_in'=>3600]),
            'www.googleapis.com/oauth2/v3/userinfo'=>Http::response(['sub'=>'different-owner','email'=>'other@example.test']),
        ]);
        try {
            app(\App\Support\Google\GoogleAccountService::class)->connect($this->root, 'code', \App\Support\Google\GoogleMeetSetup::callbackUrl());
            $this->fail('A new account needs its own refresh grant');
        } catch (\RuntimeException $e) {
            $this->assertSame('old-refresh', GoogleAccountConnection::first()->refresh_token_ciphertext);
            $this->assertSame('previous-owner', GoogleAccountConnection::first()->google_subject_id);
        }
    }

    public function test_google_callback_keeps_trusted_login_hostname_and_rejects_arbitrary_hosts(): void
    {
        $this->save();
        config(['app.url' => 'https://www.piie.ac.ug']);
        $response = $this->actingAs($this->root)->get('https://piie.ac.ug/auth/google/connect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://piie.ac.ug/auth/google/callback', $query['redirect_uri']);
        $this->assertSame($query['redirect_uri'], session('google.oauth.state.redirect_uri'));
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token'=>'host-access','refresh_token'=>'host-refresh','expires_in'=>3600]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['sub'=>'host-owner','email'=>'host@example.test']),
        ]);
        $this->actingAs($this->root)->get('https://piie.ac.ug/auth/google/callback?'.http_build_query(['code'=>'host-code','state'=>$query['state']]))->assertRedirect('/superadmin/settings/google-meet');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token') && $request['redirect_uri']==='https://piie.ac.ug/auth/google/callback');
        $this->assertSame('https://www.piie.ac.ug/auth/google/callback', \App\Support\Google\GoogleMeetSetup::callbackUrl(\Illuminate\Http\Request::create('https://attacker.example.test/')));
        $this->assertCount(2, \App\Support\Google\GoogleMeetSetup::callbackUrls());
    }
}
