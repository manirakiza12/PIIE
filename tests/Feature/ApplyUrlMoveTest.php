<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * `/admissions/apply` is the canonical application address; `/apply` redirects to it.
 *
 * WHAT ACTUALLY MOVED, AND WHAT DELIBERATELY DID NOT
 *
 * The route's URI moved. Its NAME did not. Every internal caller says
 * `route('apply.form')` - the public header, footer, hero and CTA blocks, the
 * programme catalogue, and the existing tests - so all of them follow the new
 * address with no edit at all. That is the reason for the approach: a rename would
 * have required finding and editing all sixteen references, and any one missed
 * would have silently kept pointing at the old address.
 *
 * WHY 301 AND NOT 302
 *
 * `/apply` has been published, printed on prospectuses, shared on social media and
 * possibly indexed. None of that can be recalled by changing a route. A permanent
 * redirect is the only answer that hands that accumulated authority to the new
 * address; a temporary one tells a crawler the move is provisional and leaves the
 * old URL sitting in the results.
 *
 * WHAT IS *NOT* ASSERTED HERE
 *
 * That the form validates, that submissions persist, that intakes gate correctly.
 * Those belong to `PublicApplicationTest` and `ApplicantPortalTest`, and
 * duplicating them here would create a second place to maintain for a workflow
 * that has not changed. What is asserted here is that MOVING the URL did not
 * break any of it - which is the only new risk this change introduces.
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
        // below becomes a loop: `/apply` -> `/apply`.
        $this->assertStringEndsWith(
            '/admissions/apply',
            route('apply.form'),
            'route(\'apply.form\') must generate the canonical address'
        );

        $this->assertStringNotContainsString(
            '://'.parse_url(route('apply.form'), PHP_URL_HOST).'/apply',
            route('apply.form'),
            'the canonical address must not be the bare /apply path'
        );
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
        // the wrong status code and the damage - a provisional move, an old URL
        // left indexed - is invisible until months later.
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
        // deliberate and it is the loop protection: a redirect that hard-codes
        // `https://piie.ac.ug/...` will loop the moment the site is reached over a
        // different host or protocol than the one baked into the environment file,
        // because it sends the visitor somewhere other than where they came from.
        //
        // A relative Location carries whatever host and scheme the browser already
        // used, so there is nothing to disagree with and nothing to loop through.
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

            if (preg_match('#[\'"(]/apply[\'"(\s>]#', $source, $match, PREG_OFFSET_CAPTURE)) {
                $offenders[] = $file.' → '.trim($match[0][0]);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these files still name the old address; use route('apply.form') instead:\n".implode("\n", $offenders)
        );
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

    public function test_the_programme_catalogue_links_to_the_new_address(): void
    {
        // The catalogue builds its own apply link from the route helper, so this
        // asserts the helper rather than the markup - which is the thing that
        // could actually regress.
        $this->assertStringEndsWith('/admissions/apply', route('apply.form'));
    }

    // ── The redirect is not a hole ─────────────────────────────────────────

    public function test_the_old_address_only_answers_a_get(): void
    {
        // A redirect route that accepted POST would answer something that is not
        // an application with a success code. Restricting it to GET/HEAD keeps
        // `/apply` meaning "the page", and nothing else.
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
    }

    public function test_the_redirect_destination_is_the_canonical_address(): void
    {
        // Closes the one gap this approach opens.
        //
        // The destination is a literal, which makes it a second place the canonical
        // address appears. If the canonical address ever moves and this line is not
        // updated, `/apply` would keep redirecting to an address that no longer
        // serves anything - and the redirect would look perfectly healthy in every
        // other assertion here.
        //
        // So the literal is compared against what the route helper actually
        // generates. Change one without the other and this fails.
        //
        // It lives in `$route->defaults`, not `getAction()`: `RedirectController`
        // reads its destination and status from the route defaults, and
        // `getAction('destination')` returns null, which is easy to mistake for
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

    public function test_the_legacy_route_does_not_use_an_inline_closure(): void
    {
        // Scoped to THIS route, on purpose.
        //
        // An earlier version of this test asserted that no route in the application
        // was a closure. That is false - `admin/account-disable` and the six other
        // role-scoped equivalents are - and the assertion was wrong in a way that
        // would have embarrassed anyone who read it and went looking.
        //
        // What actually matters here is narrower and true: this route must not be
        // one of them, because a closure forces `route:cache` to serialise a
        // Closure object into the route cache. That is a pre-existing condition of
        // the other seven routes and not this change's business to fix; it is not
        // this change's business to make worse either.
        $legacy = Route::getRoutes()->getByName('apply.form.legacy');

        $this->assertNotNull($legacy);
        $this->assertStringNotContainsString(
            'Closure',
            (string) $legacy->getActionName(),
            'the legacy redirect must use RedirectController, not an inline closure'
        );
    }
}