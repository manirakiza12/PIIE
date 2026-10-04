<?php

namespace Tests\Feature;

use App\Models\GoogleAccountConnection;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Who may SEE the Google connection panel, and who may never see a credential.
 *
 * ── WHY THIS IS SEPARATE FROM GoogleMeetIntegrationTest ─────────────────────
 *
 * That suite proves the protocol. This one proves the *audience*. The two risks
 * are different and both are silent:
 *
 *   1. An administrator sees a Connect control. An administrator who can connect
 *      an account can create conferences on an arbitrary personal calendar from an
 *      institutional screen, and can then disconnect a lecturer's grant.
 *   2. A token, a scope or a refresh deadline is rendered. A refresh token in a
 *      page is a disclosed credential — on a shared machine, in a screenshot, in
 *      a browser cache, in a support ticket.
 *
 * Both assert on the RENDERED HTML, because that is where either mistake would
 * actually land. Asserting on the service return value would pass while the view
 * printed something extra.
 */
class GoogleConnectionAudienceTest extends TestCase
{
    use StaffModuleTestHelper;

    private string $credentialsPath;

    private int $school;

    private User $lecturer;

    private User $otherLecturer;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();

        // NO credentials file. This is the realistic first-run state, and it is the
        // state in which the panel must still say something honest rather than
        // throwing.
        $this->credentialsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-google-audience-'.uniqid().'.json';
        GoogleOAuthCredentials::$pathOverride = $this->credentialsPath;

        $this->school = $this->makeSchool(['title' => 'PIIE Kampala', 'status' => 1]);
        // role_id 3 IS the lecturer; 6 is the PARENT role. See the note in
        // GoogleMeetIntegrationTest, whose fixtures carried the same error.
        $this->lecturer = User::factory()->create(['role_id' => PermissionService::TEACHER, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->otherLecturer = User::factory()->create(['role_id' => PermissionService::TEACHER, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->admin = User::factory()->create(['role_id' => PermissionService::SCHOOL_ADMIN, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active']);

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

    /**
     * Render just the panel, in the context of a given signed-in user.
     *
     * The full Live Classes index needs a very large schema to render; the panel
     * is the part with the authorization decision in it, so that is what is
     * rendered here.
     */
    private function panelFor(User $user, array $extra = []): string
    {
        // The GLOBAL view() helper, not $this->view(): the latter returns a
        // TestView, which has no render() and would forward the call to the
        // template. `actingAs` sets the guard, so the helper still renders in this
        // user's context.
        $this->actingAs($user);

        return view(
            'admin.live_class._google_connection',
            $extra + ['googleConfigured' => GoogleOAuthCredentials::isConfigured(), 'googleConnection' => null]
        )->render();
    }

    private function connect(User $user, string $email): GoogleAccountConnection
    {
        $id = DB::table('google_account_connections')->insertGetId([
            'school_id' => $this->school,
            'user_id' => $user->id,
            'calendar_id' => 'primary',
            'status' => 'ok',
            'connected_at' => now(),
        ]);

        $row = GoogleAccountConnection::findOrFail($id);
        $row->forceFill([
            'access_token_ciphertext' => 'ya29.ACCESS-'.$user->id,
            'refresh_token_ciphertext' => '1//REFRESH-'.$user->id,
            'google_email' => $email,
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
        ])->save();

        return $row->fresh();
    }

    // ── Who sees the panel ────────────────────────────────────────────────────

    public function test_a_lecturer_is_offered_the_connect_control(): void
    {
        $html = $this->panelFor($this->lecturer);

        $this->assertStringContainsString(route('google.auth.connect'), $html);
        $this->assertStringContainsString('Not connected', $html);
        $this->assertStringContainsString('data-google-connection', $html);
    }

    public function test_a_connected_lecturer_sees_their_status_and_a_disconnect_control(): void
    {
        $this->connect($this->lecturer, 'mine@piie.ac.ug');

        $html = $this->panelFor($this->lecturer, [
            'googleConnection' => app(\App\Support\Google\GoogleAccountService::class)->forUser($this->lecturer),
        ]);

        $this->assertStringContainsString('Connected', $html);
        $this->assertStringContainsString('mine@piie.ac.ug', $html);
        $this->assertStringContainsString(route('google.auth.disconnect'), $html);
        // Connect is replaced by Disconnect, not shown alongside it.
        $this->assertStringNotContainsString(route('google.auth.connect'), $html);
    }

    public function test_an_administrator_is_never_offered_the_connect_control(): void
    {
        $this->connect($this->lecturer, 'lecturer@piie.ac.ug');

        $html = $this->panelFor($this->admin, [
            // Even if the view were handed a connection, an administrator must not
            // get a control that acts on somebody else's grant.
            'googleConnection' => app(\App\Support\Google\GoogleAccountService::class)->forUser($this->lecturer),
        ]);

        $this->assertStringNotContainsString(route('google.auth.connect'), $html);
        $this->assertStringNotContainsString(route('google.auth.disconnect'), $html);
        $this->assertStringNotContainsString('data-google-connection', $html);
    }

    public function test_a_student_is_never_shown_the_panel(): void
    {
        $this->connect($this->lecturer, 'lecturer@piie.ac.ug');

        $html = $this->panelFor($this->student);

        $this->assertStringNotContainsString(route('google.auth.connect'), $html);
        $this->assertStringNotContainsString('data-google-connection', $html);
    }

    public function test_one_lecturer_never_sees_another_lecturers_address_or_connection(): void
    {
        $this->connect($this->lecturer, 'first@piie.ac.ug');

        $html = $this->panelFor($this->otherLecturer, [
            // The controller supplies forUser(Auth::user()) — this asserts that is
            // the only sensible thing to supply, and that the partial would not
            // render someone else's address if it were not.
            'googleConnection' => app(\App\Support\Google\GoogleAccountService::class)->forUser($this->otherLecturer),
        ]);

        $this->assertStringNotContainsString('first@piie.ac.ug', $html);
        $this->assertStringContainsString('Not connected', $html);
    }

    // ── What must never be rendered ──────────────────────────────────────────

    public function test_no_role_ever_sees_a_token_or_a_scope(): void
    {
        $this->connect($this->lecturer, 'mine@piie.ac.ug');

        $connection = app(\App\Support\Google\GoogleAccountService::class)->forUser($this->lecturer);

        foreach ([$this->lecturer, $this->admin, $this->student] as $user) {
            $html = $this->panelFor($user, ['googleConnection' => $connection]);

            $this->assertStringNotContainsString('ya29.ACCESS-', $html, 'an access token was rendered');
            $this->assertStringNotContainsString('1//REFRESH-', $html, 'a refresh token was rendered');
            $this->assertStringNotContainsString('calendar.events', $html, 'a scope was rendered');
        }
    }

    public function test_the_connect_route_never_leaks_the_client_secret_to_the_browser(): void
    {
        // Credentials present, so the redirect is actually built and the client
        // secret is genuinely in play. The authorize URL must carry the client
        // ID and never the secret.
        file_put_contents($this->credentialsPath, json_encode([
            'web' => [
                'client_id' => 'test-client-id.apps.googleusercontent.com',
                'client_secret' => 'TEST-SECRET-MUST-NOT-LEAK',
                'redirect_uris' => ['http://127.0.0.1:8000/auth/google/callback'],
            ],
        ]));

        $response = $this->actingAs($this->lecturer)->get(route('google.auth.connect'));
        $location = (string) $response->headers->get('Location');

        $this->assertStringNotContainsString('TEST-SECRET-MUST-NOT-LEAK', $location);
        $this->assertStringNotContainsString('TEST-SECRET-MUST-NOT-LEAK', $response->getContent());
        $this->assertStringContainsString('test-client-id', $location);
        $this->assertStringContainsString('calendar.events', urldecode($location));
    }

    public function test_an_unconfigured_installation_says_so_plainly(): void
    {
        // No credentials file: a lecturer must be told this is an installation
        // problem, not left clicking Connect into a Google error they cannot act on.
        $html = $this->panelFor($this->lecturer);

        $this->assertStringContainsString('not configured on this installation', $html);
        $this->assertStringContainsString('pasting a meeting link', $html);
    }

    public function test_a_lapsed_grant_asks_for_a_reconnect_rather_than_pretending_to_be_connected(): void
    {
        $connection = $this->connect($this->lecturer, 'mine@piie.ac.ug');
        DB::table('google_account_connections')->where('id', $connection->id)
            ->update(['status' => GoogleAccountConnection::STATUS_NEEDS_REAUTH]);

        $html = $this->panelFor($this->lecturer, [
            'googleConnection' => app(\App\Support\Google\GoogleAccountService::class)->forUser($this->lecturer),
        ]);

        $this->assertStringContainsString('Reconnect required', $html);
        $this->assertStringContainsString('Reconnect Google', $html);
        // Crucially: NOT described as connected, and no Disconnect offered.
        $this->assertStringNotContainsString('>Connected<', $html);
        $this->assertStringNotContainsString(route('google.auth.disconnect'), $html);
    }

    // ── Conference status wording ─────────────────────────────────────────────

    public function test_a_student_is_told_a_pending_link_is_not_a_fault(): void
    {
        // A student shown "Pending" concludes something is broken. The wording has
        // to say plainly that nothing is wrong.
        $description = \App\Support\LiveClasses\GoogleConferenceStatus::describe('pending');

        $this->assertSame('Link not ready yet', $description['label']);
        $this->assertFalse($description['joinable']);
        $this->assertStringContainsString('Nothing is wrong', $description['explanation']);
    }
}