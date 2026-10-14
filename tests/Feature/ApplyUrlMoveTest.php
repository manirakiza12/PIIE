<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * `/admissions/apply` is the canonical application address; `/apply` redirects to it.
 *
 * WHAT MOVED, AND WHAT DELIBERATELY DID NOT
 *
 * The route's URI moved. Its NAME did not. Every internal caller says
 * `route('apply.form')` - the public header, footer, hero and CTA blocks, the
 * programme catalogue, and the existing tests - so all of them follow the new
 * address with no edit at all. A rename would have meant finding and editing every
 * one of those references, and any one missed would have silently kept pointing at
 * the old address while every test still passed, because the tests ask the route
 * helper for the URL rather than asserting the URL itself.
 *
 * WHY 301
 *
 * `/apply` has been published, printed on prospectuses, shared and possibly indexed.
 * None of that can be recalled by changing a route. A 302 would tell a crawler the
 * move is provisional and leave the old address sitting in the results.
 *
 * WHAT IS DELIBERATELY NOT ASSERTED HERE
 *
 * That validation works, that submissions persist, that intakes gate correctly, and
 * that payment initiation still runs. Those belong to `PublicApplicationTest`,
 * `ApplicantPortalTest` and the PesaPal suites, and duplicating them here would
 * create a second place to maintain for a workflow this change does not alter. What
 * is asserted here is that MOVING the URL did not break any of it - the only new
 * risk this change introduces - plus a scan proving no page or controller hard-codes
 * the old address.
 */
class ApplyUrlMoveTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── The new address is the real one ─────────────────────────────────────

    public function test_the_new_address_serves_the_application_page(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->makeProgramme($schoolId, ['name' => 'BSc Computer Science']);

        $this->get('/admissions/apply')->assertOk();
    }

    public function test_the_route_name_resolves_to_the_new_address(): void
    {
        // THE property everything else depends on. If this ever drifts back to
        // `/apply`, every internal link silently reverts with it and the redirect
        // becomes a loop: `/apply` -> `/apply`.
        $this->assertStringEndsWith('/admissions/apply', route('apply.form'));
    }

    public function test_the_old_address_is_not_the_canonical_one(): void
    {
        $this->assertNotSame(
            url('/apply'),
            route('apply.form'),
            '/apply and the canonical address must not be the same URL, or the redirect loops'
        );
    }

    // ── The old address keeps working ──────────────────────────────────────

    public function test_the_old_address_redirects_permanently(): void
    {
        // 301, not 302. Asserted exactly, because "it redirects" is satisfied by
        // the wrong status code and the damage - a provisional move, an old URL left
        // indexed - is invisible until months later.
        $this->get('/apply')->assertStatus(301);
    }

    public function test_the_old_address_points_at_the_new_one(): void
    {
        $response = $this->get('/apply');

        $response->assertStatus(301);
        $response->assertRedirect(route('apply.form'));
    }

    public function test_the_redirect_does_not_pin_a_host(): void
    {
        // WHY THIS ASSERTS THE ABSENCE OF A HOST.
        //
        // The destination is relative, so `Location` carries no hostname. That is
        // the loop protection: a redirect hard-coding `https://piie.ac.ug/...` will
        // loop the moment the site is reached over a different address or protocol
        // than the environment file names, because it sends the visitor somewhere
        // other than where they came from.
        $location = $this->get('/apply')->headers->get('Location');

        $this->assertNotNull($location, 'a 301 must carry a Location header');
        $this->assertNull(
            parse_url($location, PHP_URL_HOST),
            "the redirect must stay relative, not pinned to a host: {$location}"
        );
        $this->assertStringStartsWith('/admissions/apply', $location);
    }

    public function test_following_the_redirect_lands_on_a_working_page(): void
    {
        // The loop check, end to end. One hop must terminate on a real page.
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->makeProgramme($schoolId, ['name' => 'BSc Computer Science']);

        $this->followingRedirects()->get('/apply')->assertOk();
    }

    // ── Nothing internal still points at the old address ───────────────────

    public function test_no_view_or_controller_hard_codes_the_old_address(): void
    {
        // Scanned rather than rendered, and deliberately so.
        //
        // The public Apply Now control is hand-written markup in six partials, and
        // it is the most likely place for an old address to survive - but rendering
        // the whole public site to check it needs CMS fixtures this change has no
        // business depending on. A literal-address scan tests the thing that can
        // actually regress, needs no database, and cannot go stale.
        //
        // The route NAME is not an old address and is not matched: `apply.form` is
        // how every one of those partials is meant to build the link.
        $offenders = [];

        foreach ($this->siteFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('#[\'"(]/apply[\'"(\s>]#', $source, $match)) {
                $offenders[] = $file.' -> '.trim($match[0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these files still name the old address; use route('apply.form') instead:\n".implode("\n", $offenders)
        );
    }

    // ── The redirect is not a hole ─────────────────────────────────────────

    public function test_the_old_address_only_answers_a_get(): void
    {
        // A redirect route that accepted POST would answer something that is not an
        // application with a success code. Restricting it to GET/HEAD keeps `/apply`
        // meaning "the page", and nothing else.
        $this->post('/apply')->assertStatus(405);
    }

    public function test_the_new_address_still_refuses_when_no_institution_is_configured(): void
    {
        // The 503 has to survive the move. An unconfigured installation must say
        // applications are unavailable, not redirect somewhere that looks open.
        $this->get('/admissions/apply')->assertStatus(503);
    }

    // ── Registration ───────────────────────────────────────────────────────

    public function test_exactly_one_route_serves_the_application_page(): void
    {
        // Guards against a second copy of the page being registered at another
        // address later, which is how a duplicate-application form appears.
        $serving = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => $route->getActionName() === \App\Http\Controllers\PublicApplicationController::class.'@showForm'
        ));

        $this->assertCount(1, $serving, 'the application page must have exactly one address');
        $this->assertSame('admissions/apply', $serving[0]->uri());
    }

    public function test_the_legacy_route_is_a_redirect_and_not_a_second_page(): void
    {
        $legacy = Route::getRoutes()->getByName('apply.form.legacy');

        $this->assertNotNull($legacy, 'the old address must remain registered');
        $this->assertSame(['GET', 'HEAD'], $legacy->methods());
        $this->assertStringNotContainsString(
            'Closure',
            (string) $legacy->getActionName(),
            'the legacy redirect must use RedirectController, not an inline closure'
        );
    }

    public function test_the_redirect_destination_is_the_canonical_address(): void
    {
        // Closes the one gap this approach opens: the destination is a literal,
        // which makes it a second place the canonical address appears. If the
        // canonical address ever moves and this line is not updated, `/apply` would
        // keep redirecting to an address that no longer serves anything - and every
        // other assertion here would still pass.
        //
        // It lives in `$route->defaults`, not `getAction()`: RedirectController
        // reads its destination and status from the route defaults, and
        // `getAction('destination')` returns null, which is easy to misread as
        // "no destination configured".
        $legacy = Route::getRoutes()->getByName('apply.form.legacy');
        $this->assertNotNull($legacy);

        $this->assertSame(
            '/'.ltrim(parse_url(route('apply.form'), PHP_URL_PATH), '/'),
            $legacy->defaults['destination'] ?? null,
            'the legacy redirect must point at exactly the canonical path'
        );

        $this->assertSame(301, $legacy->defaults['status'] ?? null);
    }

    /**
     * The payment flow must be untouched by a change to the application page's
     * address. This is asserted rather than assumed because the callback and IPN
     * routes sit in the same file, and a careless edit to a neighbouring block is
     * exactly how a URL move ends up breaking payments.
     *
     * They are under `/payments/pesopal/...`, not under `/apply`, so nothing about
     * this change can reach them - and this test fails if that ever stops being so.
     */
    public function test_the_payment_callback_and_ipn_routes_are_untouched(): void
    {
        foreach (['payments/pesapal/callback', 'payments/pesapal/ipn'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $uri);

            $this->assertNotNull($route, "{$uri} must still be registered");
        }
    }

    /** @return list<string> */
    private function siteFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = [];

        foreach (['resources/views', 'app'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }
}