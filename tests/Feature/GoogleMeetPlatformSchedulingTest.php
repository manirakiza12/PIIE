<?php

namespace Tests\Feature;

use App\Models\GoogleAccountConnection;
use App\Models\GoogleMeetSetting;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class GoogleMeetPlatformSchedulingTest extends TestCase
{
    use LiveClassTestHelper;
    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        foreach (['2026_10_04_000001_create_google_account_connections_table.php', '2026_10_04_000002_add_google_calendar_fields_to_live_classes.php', '2026_10_14_140000_create_google_meet_settings_table.php'] as $file) (require database_path('migrations/'.$file))->up();
        GoogleOAuthCredentials::$pathOverride = null;
        DB::table('schools')->insert([['id'=>1,'title'=>'PIIE'],['id'=>2,'title'=>'Other']]);
        $this->owner = User::create(['name'=>'Platform Owner','email'=>'owner@example.test','role_id'=>1,'account_status'=>'active']);
        $this->admin = User::create(['name'=>'Admin','email'=>'admin@example.test','role_id'=>2,'school_id'=>1,'account_status'=>'active']);
        GoogleMeetSetting::create(['id'=>1,'school_id'=>1,'client_id'=>'test.apps.googleusercontent.com','client_secret'=>'private-secret','enabled'=>true,'platform_user_id'=>$this->owner->id,'calendar_id'=>'primary']);
        GoogleAccountConnection::create(['user_id'=>$this->owner->id,'google_subject_id'=>'owner','google_email'=>'owner@example.test','access_token_ciphertext'=>'access-fixture','refresh_token_ciphertext'=>'refresh-fixture','access_token_expires_at'=>time()+3600,'status'=>'ok']);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        GoogleOAuthCredentials::$pathOverride = null;
        parent::tearDown();
    }

    private function schedule()
    {
        return $this->actingAs($this->admin)->post(route('admin.live_classes.store'), [
            'title'=>'API configured from Super Admin','platform'=>'google_meet','start_date'=>now()->addDay()->format('Y-m-d'),
            'start_time'=>'10:00','end_time'=>'11:00','timezone'=>'Africa/Kampala','is_published'=>0,
        ]);
    }

    public function test_connected_platform_creates_a_google_meet_without_environment_credentials(): void
    {
        config(['services.google_meet.client_id'=>'','services.google_meet.client_secret'=>'','services.google_meet.refresh_token'=>'']);
        Http::fake(['www.googleapis.com/calendar/v3/*'=>Http::response(['id'=>'google-event','hangoutLink'=>'https://meet.google.com/abc-defg-hij'])]);
        $this->schedule()->assertSessionHasNoErrors();
        $class = LiveClass::firstOrFail();
        $this->assertSame('https://meet.google.com/abc-defg-hij', $class->meeting_url);
        $this->assertSame('google-event', $class->google_calendar_event_id);
        $this->assertSame($this->owner->id, (int) $class->google_account_owner_id);
        Http::assertSent(fn ($request) => $request->method()==='POST' && str_contains($request->url(),'conferenceDataVersion=1') && $request['conferenceData']['createRequest']['conferenceSolutionKey']['type']==='hangoutsMeet');
    }

    public function test_pending_google_link_is_saved_and_later_refreshed_without_duplicate_events(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*'=>Http::sequence()->push(['id'=>'pending-event'])->push(['id'=>'pending-event','hangoutLink'=>'https://meet.google.com/ready-link'])]);
        $this->schedule()->assertSessionHasNoErrors();
        $class = LiveClass::firstOrFail();
        $this->assertSame('pending', $class->google_conference_status);
        $this->assertNull($class->meeting_url);
        $this->artisan('live-classes:refresh-google-links')->assertExitCode(0);
        $this->assertSame('ready', $class->fresh()->google_conference_status);
        $this->assertSame('https://meet.google.com/ready-link', $class->fresh()->meeting_url);
        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => $pair[0]->method()==='POST')->count());
    }

    public function test_another_school_cannot_use_the_institution_google_grant(): void
    {
        $this->admin->update(['school_id'=>2]);
        $this->schedule()->assertSessionHasErrors('platform');
        $this->assertSame(0, LiveClass::count());
        Http::assertNothingSent();
    }
}
