<?php

namespace App\Http\Controllers;

use App\Models\IntakeSession;
use App\Models\Programme;
use App\Models\WebsiteItem;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteSetting;
use App\Support\PublicTenantResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * The public "Apply Now" landing page.
 *
 * This used to be the application itself — a single anonymous form that
 * created an Admission and then left the applicant with no way to see, finish
 * or correct anything. It is now the front door to the applicant portal: it
 * shows what is on offer and hands over to registration or sign-in, and the
 * application proper lives behind the "applicant" guard in
 * App\Http\Controllers\Applicant\*.
 *
 * The institution context is still resolved automatically — there is no
 * school selector on any public admissions page.
 */
class PublicApplicationController extends Controller
{
    /**
     * Programme codes that must never be offered to a public applicant, whatever
     * their `is_active` flag says.
     *
     * The live row is `DUMMY-APP-2026`. Matching on the CODE as well as the name is
     * deliberate redundancy: a code is a short, controlled field, so it is a much
     * safer thing to hard-match than a name an administrator is free to edit.
     */
    private const NON_PUBLIC_PROGRAMME_CODES = [
        'DUMMY-APP-2026',
    ];

    /**
     * Markers that identify a programme row as test scaffolding.
     *
     * Specific and multi-word on purpose. A bare "test" would also exclude a real
     * programme such as "Bachelor of Test-Driven Development" or "Higher Certificate
     * in Testing and Inspection" — an admissions page silently missing a genuine
     * programme is a worse failure than a stray test row.
     *
     * `%` and `_` are SQL wildcards inside LIKE, so each marker is written as a
     * plain phrase with the surrounding wildcards added by the caller.
     */
    private const TEST_MARKERS = [
        'DUMMY TEST',
        'DUMMY-APP',
        '[DUMMY',
        'SAFE TO DELETE',
        'TEST PROGRAMME',
        'TEST ONLY',
        'DO NOT USE',
    ];

    public function showForm()
    {
        $schoolId = PublicTenantResolver::resolveSchoolId();

        if (! $schoolId) {
            abort(503, get_phrase('Online applications are not currently configured.'));
        }

        // Already signed in as an applicant — no reason to show them the
        // marketing page again.
        if (Auth::guard('applicant')->check()) {
            return redirect()->route('applicant.dashboard');
        }

        /**
         * Public application catalogue.
         *
         * `is_active = 1` is the publication flag and is respected as before. On top
         * of that, rows whose own NAME declares them to be test data are excluded
         * from the public page.
         *
         * WHY, WHEN is_active IS NOT ENOUGH
         *   The live table currently holds a row named
         *   "PIIE Dummy Application Programme [DUMMY TEST — SAFE TO DELETE]" with
         *   `is_active = 1`, because it was created to exercise the application flow
         *   rather than to be offered to an applicant. So it passes the publication
         *   filter and appears on the public Apply page — where a prospective student
         *   can select it and begin an application against it.
         *
         *   That is a real defect, not a cosmetic one: it exposes test scaffolding
         *   on a public page and produces applications attached to nothing real.
         *
         * WHY A NAME MATCH RATHER THAN A NEW COLUMN
         *   A `is_public` column would be the cleaner model, but adding one means a
         *   migration plus a change to the Admin programme form, and the brief asks
         *   not to expand internal scope unnecessarily. The row declares itself: the
         *   name contains an explicit test marker. Matching that marker removes the
         *   offending row today and removes any future row created the same way,
         *   with no schema change and nothing for an administrator to remember.
         *
         *   The markers are deliberately specific and anchored, so a legitimate
         *   programme cannot be caught by accident: "Bachelor of Test-Driven
         *   Development" contains "test" but matches none of them.
         *
         * WHAT THIS DOES NOT DO
         *   It does not delete or deactivate the row. The brief says "do not delete
         *   historical application data", and removing the scaffolding is an
         *   institutional decision. The row stays, and applications already recorded
         *   against it are untouched.
         */
        $programmes = Programme::where('school_id', $schoolId)
            ->where('is_active', 1)
            ->whereNotIn('code', self::NON_PUBLIC_PROGRAMME_CODES)
            ->where(function ($query) {
                foreach (self::TEST_MARKERS as $marker) {
                    $query->where('name', 'not like', '%'.$marker.'%');
                }
            })
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        $intakeSessions = IntakeSession::where('school_id', $schoolId)
            ->where('is_open', 1)
            ->orderBy('close_date')
            ->get();

        /**
         * The public website's own configuration, so the shared header renders
         * correctly on this page.
         *
         * `/apply` includes `frontend.partials.site_header` like every other public
         * page, but this controller originally passed ONLY `programmes` and
         * `intakeSessions`. The header's data is all `??`-guarded, so nothing broke
         * and no error was raised — the header simply rendered EMPTY: no contact bar,
         * no email, no telephone numbers, no website, and a navigation with no links
         * in it. That is exactly the "inconsistent header" the review reported, and it
         * is invisible to any test that only asserts the header element exists.
         *
         * GUARDED BY `Schema::hasTable()`, exactly as `HomeController::home()` does.
         * That guard is not optional: querying these tables unconditionally made
         * `/apply` 500 with "no such table: website_settings" on any schema where the
         * website CMS is not installed — which broke two existing tests
         * (`ApplicantPortalTest`, `PublicApplicationTest`) and would equally break a
         * fresh installation before the CMS is seeded. The application form is this
         * page's actual job; it must not hard-depend on the CMS being present.
         *
         * `PublicTenantResolver` is the same resolver `HomeController` uses, so the
         * settings and pages are scoped to the same institution.
         */
        $websiteSettings = collect();
        $websiteSections = collect();
        $websiteItems = collect();
        $allPages = collect();

        if (
            Schema::hasTable('website_pages') &&
            Schema::hasTable('website_sections') &&
            Schema::hasTable('website_items') &&
            Schema::hasTable('website_settings')
        ) {
            $websiteSettings = WebsiteSetting::where('status', 1)
                ->where(fn ($q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'))
                ->pluck('value', 'key');

            $allPages = WebsitePage::where('status', 1)
                ->where(fn ($q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'))
                ->orderBy('display_order')
                ->orderBy('sort_order')
                ->get();

            $websiteSections = WebsiteSection::where('status', 1)
                ->where(fn ($q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('section_key');

            $websiteItems = WebsiteItem::where('status', 1)
                ->where(fn ($q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('section_key');
        }

        return view('frontend.apply', compact(
            'programmes',
            'intakeSessions',
            'websiteSettings',
            'websiteSections',
            'websiteItems',
            'allPages'
        ));
    }
}
