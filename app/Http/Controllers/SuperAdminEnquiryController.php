<?php

namespace App\Http\Controllers;

use App\Models\WebsiteEnquiry;
use App\Support\PublicTenantResolver;
use Illuminate\Http\Request;

/**
 * SUPER ADMIN ENQUIRY INBOX.
 *
 * Every route here sits inside the existing
 * `Route::controller(...)->middleware('auth', 'superAdmin')` group in routes/web.php,
 * which is what satisfies "an authorised Super Admin inbox". Unauthenticated
 * requests are redirected to login by the framework; an authenticated user without
 * the `superAdmin` role is refused by the middleware. There is deliberately no
 * equivalent route reachable by a lecturer, a student or a parent.
 *
 * The inbox is READ-ONLY with respect to the visitor's words. There is no edit
 * action on purpose: an enquiry is a record of what someone wrote, and letting an
 * administrator silently rewrite it would destroy the only thing the institution
 * has to show that a question was asked. The only mutations are `status` and
 * `delete`, both of which are recorded by `handled_by` / `handled_at`.
 */
class SuperAdminEnquiryController extends Controller
{
    /** Per page in the inbox. */
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $schoolId = $this->schoolId();

        $status = (string) $request->query('status', 'new');

        // An unrecognised status shows everything rather than erroring, so a stale
        // bookmark in the sidebar cannot present a 500 to an administrator.
        $filter = in_array($status, WebsiteEnquiry::STATUSES, true) ? $status : null;

        $query = WebsiteEnquiry::query()
            ->when($schoolId, fn ($q) => $q->where(fn ($w) => $w->where('school_id', $schoolId)->orWhereNull('school_id')))
            ->when($filter, fn ($q) => $q->where('status', $filter))
            // A free-text search across the fields an administrator would actually
            // search by. Scoped to LIKE with bound parameters, so there is no
            // injection surface here.
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->query('q')).'%';

                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', $term)
                      ->orWhere('email', 'like', $term)
                      ->orWhere('subject', 'like', $term)
                      ->orWhere('message', 'like', $term);
                });
            });

        // Counts for the sidebar tabs. Computed before pagination so the numbers are
        // totals, not the size of the current page.
        $counts = WebsiteEnquiry::query()
            ->when($schoolId, fn ($q) => $q->where(fn ($w) => $w->where('school_id', $schoolId)->orWhereNull('school_id')))
            ->select('status', \Illuminate\Support\Facades\DB::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $enquiries = $query->orderByDesc('created_at')->paginate(self::PER_PAGE)->withQueryString();

        return view('superadmin.enquiries.index', [
            'enquiries' => $enquiries,
            'status' => $status,
            'activeStatus' => $filter,
            'counts' => $counts + ['all' => array_sum($counts)],
            'search' => (string) $request->query('q', ''),
        ]);
    }

    public function show(Request $request, $id)
    {
        $enquiry = $this->findScoped($id);

        // Opening an unread enquiry marks it read. This is a convenience, not an
        // audit event, so it does not stamp `handled_by` / `handled_at`.
        if ($enquiry->status === WebsiteEnquiry::STATUS_NEW) {
            $enquiry->update(['status' => WebsiteEnquiry::STATUS_READ]);
        }

        return view('superadmin.enquiries.show', ['enquiry' => $enquiry]);
    }

    /**
     * Change an enquiry's inbox state.
     *
     * `status` is validated against the model's own constant list, so a crafted
     * request cannot write an arbitrary string into the enum column.
     */
    public function update(Request $request, $id)
    {
        $enquiry = $this->findScoped($id);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', WebsiteEnquiry::STATUSES)],
        ]);

        $enquiry->update([
            'status' => $validated['status'],
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
        ]);

        return back()->with('success', 'Enquiry marked as '.$validated['status'].'.');
    }

    public function destroy(Request $request, $id)
    {
        $this->findScoped($id)->delete();

        return back()->with('success', 'Enquiry deleted.');
    }

    /**
     * Tenant scope for the inbox.
     *
     * Reuses the public site's resolver so an administrator sees the enquiries for
     * the institution whose website they are looking at.
     */
    private function schoolId(): ?int
    {
        try {
            return PublicTenantResolver::resolveSchoolId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch an enquiry, refusing anything outside this tenant.
     *
     * A 404 rather than a 403 for another tenant's row: confirming that an id
     * exists but belongs to someone else is itself a disclosure.
     */
    private function findScoped($id): WebsiteEnquiry
    {
        $schoolId = $this->schoolId();

        $enquiry = WebsiteEnquiry::query()
            ->when($schoolId, fn ($q) => $q->where(fn ($w) => $w->where('school_id', $schoolId)->orWhereNull('school_id')))
            ->findOrFail($id);

        return $enquiry;
    }
}
