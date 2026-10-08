<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfAuthenticated;
use App\Models\User;
use App\Support\Permissions\RoleAccountDisableRoute;
use App\Support\Permissions\RoleHomeRoute;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * RBAC Phase 1 — CHARACTERIZATION, not specification.
 *
 * Pins what login / post-login redirect / "already logged in" redirect /
 * RoleHomeRoute / RoleAccountDisableRoute do TODAY for every role_id 1–19,
 * so later RBAC phases can't change any of it by accident. Several of the
 * pinned behaviours are known-broken legacy (marked KNOWN LEGACY below) —
 * they are recorded here exactly as they are, NOT corrected. A later phase
 * that fixes one must update the matching assertion deliberately.
 */
class RbacLoginRedirectCharacterizationTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_status', 20)->nullable();
        });
    }

    private function loginAs(int $roleId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $schoolId = $this->makeSchool(['status' => 1]);
        $user = User::factory()->create(array_merge([
            'role_id' => $roleId,
            'school_id' => $roleId === 1 ? null : $schoolId,
            'account_status' => 'active',
        ], $overrides));

        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    /**
     * LoginController::login() post-login redirect, per role_id.
     * null = no redirect assertion (see the dedicated known-legacy tests).
     */
    public static function postLoginRedirects(): array
    {
        return [
            'role 1 super admin'      => [1, 'superadmin.dashboard'],
            'role 2 school admin'     => [2, 'admin.dashboard'],
            'role 3 teacher'          => [3, 'teacher.dashboard'],
            'role 4 accountant'       => [4, 'accountant.dashboard'],
            'role 5 librarian'        => [5, 'librarian.dashboard'],
            'role 6 parent'           => [6, 'parent.dashboard'],
            'role 7 student'          => [7, 'student.dashboard'],
            // KNOWN LEGACY: 9 is seeded as "alumni" but LoginController treats it as Registrar.
            'role 9 (alumni/registrar conflict)' => [9, 'admin.dashboard'],
            'role 10 warden'          => [10, 'warden.dashboard'],
            // KNOWN LEGACY: no branch for 11–14 / 16–19 — they land on the marketing site.
            'role 11 frozen'          => [11, 'landingPage'],
            'role 12 frozen'          => [12, 'landingPage'],
            'role 13 planned'         => [13, 'landingPage'],
            'role 14 planned'         => [14, 'landingPage'],
            'role 15 hr manager'      => [15, 'admin.leave.index'],
            'role 16 planned'         => [16, 'landingPage'],
            'role 17 planned'         => [17, 'landingPage'],
            'role 18 planned'         => [18, 'landingPage'],
            'role 19 planned'         => [19, 'landingPage'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postLoginRedirects')]
    public function test_post_login_redirect_per_role(int $roleId, string $expectedRoute): void
    {
        $response = $this->loginAs($roleId);

        $response->assertRedirect(route($expectedRoute));
        $this->assertAuthenticated();
    }

    public function test_known_legacy_role_8_login_authenticates_then_fails_on_missing_driver_route(): void
    {
        // KNOWN LEGACY: LoginController sends role 8 to route('driver.dashboard'),
        // which is not registered — the request errors after auth succeeds.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('driver.dashboard'));

        $response = $this->loginAs(8);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_login_itself_does_not_check_account_status_the_role_middleware_does(): void
    {
        // A disabled teacher is still authenticated and redirected to their
        // dashboard; the block happens in TeacherMiddleware on arrival.
        $response = $this->loginAs(3, ['account_status' => 'disable']);

        $response->assertRedirect(route('teacher.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_itself_does_not_check_staff_status(): void
    {
        $response = $this->loginAs(3, ['staff_status' => 'suspended']);

        $response->assertRedirect(route('teacher.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_school_role_login_is_refused_when_the_school_is_not_active(): void
    {
        $schoolId = $this->makeSchool(['status' => 0]);
        $user = User::factory()->create(['role_id' => 3, 'school_id' => $schoolId]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_school_admin_login_bypasses_the_inactive_school_check(): void
    {
        // user_role_check() == 1 || == 2 short-circuits school_status_check().
        $schoolId = $this->makeSchool(['status' => 0]);
        $user = User::factory()->create(['role_id' => 2, 'school_id' => $schoolId]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('admin.dashboard'));
    }

    /**
     * RedirectIfAuthenticated ('guest' middleware) — where an already
     * logged-in user is bounced to when they revisit /login. These are raw
     * paths in that class, not route names.
     */
    public static function alreadyAuthenticatedRedirects(): array
    {
        return [
            [1, '/superadmin/dashboard'],
            [2, '/admin/dashboard'],
            [3, '/teacher/dashboard'],
            [4, '/accountant/dashboard'],
            [5, '/librarian/dashboard'],
            [6, '/parent/dashboard'],
            [7, '/student/dashboard'],
            // KNOWN LEGACY: /alumni/dashboard is not a registered route.
            [9, '/alumni/dashboard'],
            [10, '/admin/dashboard'],
            [11, '/admin/dashboard'],
            [12, '/admin/dashboard'],
            [13, '/admin/dashboard'],
            [14, '/admin/dashboard'],
            [15, '/admin/dashboard'],
            [16, '/admin/dashboard'],
            [17, '/admin/dashboard'],
            [18, '/admin/dashboard'],
            [19, '/admin/dashboard'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('alreadyAuthenticatedRedirects')]
    public function test_already_authenticated_redirect_per_role(int $roleId, string $expectedPath): void
    {
        $this->actingAs(User::factory()->make(['role_id' => $roleId]));

        $response = (new RedirectIfAuthenticated())->handle(Request::create('/login'), fn () => new Response('passed'));

        $this->assertSame(url($expectedPath), $response->headers->get('Location'));
    }

    public function test_known_legacy_role_8_is_not_redirected_away_from_guest_pages(): void
    {
        // RedirectIfAuthenticated has no branch for 8 — the request passes through.
        $this->actingAs(User::factory()->make(['role_id' => 8]));

        $response = (new RedirectIfAuthenticated())->handle(Request::create('/login'), fn () => new Response('passed'));

        $this->assertSame('passed', $response->getContent());
    }

    public function test_role_home_route_mapping_for_every_role_id(): void
    {
        $expected = [
            1 => 'superadmin.dashboard', 2 => 'admin.dashboard', 3 => 'teacher.dashboard',
            4 => 'accountant.dashboard', 5 => 'librarian.dashboard', 6 => 'parent.dashboard',
            7 => 'student.dashboard', 8 => 'landingPage', 9 => 'admin.dashboard',
            10 => 'warden.dashboard', 11 => 'admin.dashboard', 12 => 'admin.dashboard',
            13 => 'admin.dashboard', 14 => 'admin.dashboard', 15 => 'admin.leave.index',
            16 => 'admin.dashboard', 17 => 'admin.dashboard', 18 => 'admin.dashboard',
            19 => 'admin.dashboard', 20 => 'landingPage',
        ];

        foreach ($expected as $roleId => $routeName) {
            $this->assertSame($routeName, RoleHomeRoute::name(User::factory()->make(['role_id' => $roleId])), "role {$roleId}");
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($routeName), "{$routeName} must resolve");
        }
        $this->assertSame('login', RoleHomeRoute::name(null));
    }

    public function test_role_account_disable_route_only_knows_the_eight_legacy_portals(): void
    {
        $mapped = [
            1 => 'admin.account_disableview', 2 => 'admin.account_disableview',
            3 => 'teacher.account_disable', 4 => 'accountant.account_disable',
            5 => 'librarian.account_disable', 6 => 'parent.account_disable',
            7 => 'student.account_disable', 10 => 'warden.account_disable',
        ];

        foreach (range(1, 19) as $roleId) {
            $user = User::factory()->make(['role_id' => $roleId]);
            // Probe with a route name that belongs to nobody.
            $redirect = RoleAccountDisableRoute::redirectIfMismatched($user, '__probe__');

            if (isset($mapped[$roleId])) {
                $this->assertSame(route($mapped[$roleId]), $redirect?->headers->get('Location'), "role {$roleId}");
            } else {
                $this->assertNull($redirect, "role {$roleId} has no own disabled page and is let through");
            }
        }
    }
}
