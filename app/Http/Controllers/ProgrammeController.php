<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Programme;
use App\Support\ProgrammeCatalogue\ProgrammeCatalogueSchema;
use App\Support\ProgrammeCatalogue\ProgrammeCoverImage;
use App\Support\ProgrammeCatalogue\ProgrammePrice;
use App\Support\ProgrammeCatalogue\ProgrammePublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProgrammeController extends Controller
{
    private $school_id;

    public function __construct(
        private ProgrammePublisher $publisher,
        private ProgrammeCoverImage $covers,
        private ProgrammeCatalogueSchema $catalogueSchema,
    ) {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
    }

    /**
     * The tenant's own programme, never another school's.
     *
     * Every mutating route resolves through this. Scoping on `school_id` at the
     * query — rather than finding by id and checking afterwards — means an id
     * belonging to another institution produces a 404 with no existence
     * information leaked, which is the same rule the cover image read route uses.
     */
    private function findOwned($id): Programme
    {
        return Programme::where('school_id', $this->school_id)->findOrFail($id);
    }

    /**
     * Grouped by faculty (Department) rather than paginated — the same
     * layout the admission portal's programme step and the review screen
     * both need to make sense of "what do we offer, and under which
     * faculty". An institution's programme catalogue is a few dozen to a
     * few hundred rows at most (nothing like the students/admissions
     * lists), so fetching the lot and grouping in memory is simpler and
     * more useful here than page-by-page pagination, which would risk
     * splitting one faculty's programmes across two pages.
     */
    public function index(Request $request)
    {
        $search       = $request->search ?? '';
        $departmentId = $request->department_id ?? '';

        $programmes = Programme::where('school_id', $this->school_id)
            ->when($search, fn($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%");
            }))
            ->when($departmentId === 'none', fn($q) => $q->whereNull('department_id'))
            ->when($departmentId !== '' && $departmentId !== 'none', fn($q) => $q->where('department_id', $departmentId))
            ->with('department')
            ->orderBy('name')
            ->get();

        $departments = Department::where('school_id', $this->school_id)->orderBy('name')->get();
        $schoolType = \Illuminate\Support\Facades\DB::table('schools')->where('id', $this->school_id)->value('school_type') ?: 'k12';
        $canSeeProgrammes = $schoolType !== 'k12';

        // The departments that actually exist in THIS tenant. A programme may
        // only be filed under one of these; anything else is unresolved.
        $knownDepartmentIds = $departments->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Every configured faculty gets a section even when it currently has
        // no programmes — an empty faculty is exactly when an admin most
        // wants to be reminded "you haven't added anything here yet", not
        // something to hide. Programmes whose faculty cannot be resolved get
        // their own clearly-labelled group at the end rather than being
        // silently dropped (see $unresolved below).
        $groups = $departments->map(function ($department) use ($programmes) {
            $id = (int) $department->id;

            return [
                'department' => $department,
                'programmes' => $programmes
                    ->filter(fn ($p) => $p->department_id !== null && (int) $p->department_id === $id)
                    ->values(),
            ];
        })->values();

        // Deliberately WIDER than "no faculty set". A programme whose
        // department_id points at a department that no longer exists — the
        // usual cause is a faculty deleted after the programme was created,
        // which nothing warns about — used to match no faculty group and was
        // not in the unassigned group either, so it was fetched, counted in
        // totalCount, and then silently dropped from a screen that reported
        // it as present. This bucket is "I cannot show you a faculty for
        // this", which is both honest and never lossy.
        //
        // Tenant isolation: $programmes is already scoped to this school and
        // $knownDepartmentIds holds only this school's departments, so a
        // programme belonging to another tenant can neither be grouped nor
        // counted here. A programme pointing at another school's department
        // correctly lands here rather than leaking that department's name.
        $unresolved = $programmes->filter(function ($programme) use ($knownDepartmentIds) {
            return $programme->department_id === null
                || ! in_array((int) $programme->department_id, $knownDepartmentIds, true);
        })->values();

        if ($unresolved->isNotEmpty() || $departments->isEmpty()) {
            $groups->push([
                'department' => null,
                'isUnresolvedGroup' => true,
                'programmes' => $unresolved,
            ]);
        }

        // A search or department filter narrows the results; drop the
        // groups it emptied out so a text search doesn't render an empty
        // accordion section for every faculty that just didn't match.
        // Browsing with no filter at all is the one case every configured
        // faculty stays visible, including the ones with nothing in them yet.
        if ($search !== '' || $departmentId !== '') {
            $groups = $groups->filter(fn ($group) => $group['programmes']->isNotEmpty())->values();
        }

        return view('admin.programme.list', compact('groups', 'departments', 'search', 'departmentId', 'canSeeProgrammes'))
            ->with('totalCount', $programmes->count());
    }

    public function openModal(Request $request)
    {
        $id          = $request->id;
        $programme   = $id ? $this->findOwned($id) : null;
        $departments = Department::where('school_id', $this->school_id)->orderBy('name')->get();

        return view('admin.programme.modal', compact('programme', 'departments'))
            ->with([
                'coverUrl'      => $programme ? $this->covers->url($programme) : null,
                'priceSummary'  => ProgrammePrice::adminSummary($programme),
                'tenantCurrency' => ProgrammePrice::tenantCurrency($programme),
                'catalogueSections' => ProgrammePublisher::CATALOGUE_SECTIONS,
            ]);
    }

    private function validationRules(?Programme $existing = null): array
    {
        $allowedLevels = array_merge(Programme::LEVELS, Programme::LEVELS_LEGACY);
        $allowedModes  = array_merge(Programme::MODES, Programme::MODES_LEGACY);

        return [
            'code'          => [
                'required', 'max:20',
                Rule::unique('programmes', 'code')->where(fn ($q) => $q->where('school_id', $this->school_id))->ignore($existing?->id),
            ],
            'name'          => 'required|max:255',
            'level'         => ['required', Rule::in($allowedLevels)],
            'duration'      => 'nullable|max:50',
            'mode'          => ['required', Rule::in($allowedModes)],
            'tuition_fee'   => 'nullable|numeric|min:0',
            'department_id' => [
                'nullable',
                Rule::exists('departments', 'id')->where(fn ($q) => $q->where('school_id', $this->school_id)),
            ],

            // Catalogue metadata. A CURRENCY is only meaningful when an amount is
            // present, and a BASIS is only meaningful when an amount is present —
            // so both are cleared together rather than left describing a figure
            // that no longer exists. `nullable` on its own would accept "amount
            // removed, still labelled UGX per semester".
            'tuition_currency' => ['nullable', 'string', 'max:10'],
            'tuition_fee_basis' => [
                'nullable', Rule::in(ProgrammePrice::SELECTABLE_BASES),
            ],
            // Display order. Capped so a mistaken 1e9 cannot push a programme to
            // the far end of the catalogue unnoticed.
            'website_sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * Whether the catalogue columns exist yet.
     *
     * Delegated to `ProgrammeCatalogueSchema`, which is resolved from the
     * container so the memo lives for one application instance. A static here
     * would be wrong twice over: it would be stale after a migration run in the
     * same process, and it would leak across tests, which share one PHP process
     * while each building a different schema.
     */
    private function catalogueColumnsPresent(): bool
    {
        return $this->catalogueSchema->columnsExist();
    }

    /**
     * Reconcile price metadata after validation.
     *
     * Removing the amount removes its unit with it, because a currency or basis
     * left behind describes nothing and will be rendered against a future amount
     * by mistake.
     *
     * On an installation where the catalogue migration has not run, the
     * catalogue keys are dropped rather than written. A rolling deploy can
     * briefly present new code against an unmigrated database, and writing an
     * unknown column there is a hard SQL error that takes out programme editing
     * entirely — a much worse failure than the price metadata being briefly
     * unavailable.
     */
    private function normalisePriceMetadata(array $validated): array
    {
        if (! $this->catalogueColumnsPresent()) {
            foreach (['tuition_currency', 'tuition_fee_basis', 'website_sort_order'] as $key) {
                unset($validated[$key]);
            }

            return $validated;
        }

        $hasAmount = array_key_exists('tuition_fee', $validated)
            && $validated['tuition_fee'] !== null
            && $validated['tuition_fee'] !== '';

        if (! $hasAmount) {
            // The units are cleared with the amount, because a currency or basis left
            // behind describes nothing and would be rendered against a future amount
            // by mistake.
            $validated['tuition_currency'] = null;
            $validated['tuition_fee_basis'] = null;

            // `programmes.tuition_fee` is `decimal(15,2) NOT NULL DEFAULT 0.00` on a
            // real installation — it was never made nullable. An empty form field
            // arrives as null (ConvertEmptyStringsToNull), so writing it straight
            // through is an integrity-constraint error and a 500 on the programme
            // save. Storing 0 is correct here and is exactly what "no amount set"
            // means: `ProgrammePrice` treats 0 as unpriced and the public card shows
            // the contact line rather than a price of zero.
            if (array_key_exists('tuition_fee', $validated)) {
                $validated['tuition_fee'] = 0;
            }

            return $validated;
        }

        // An amount with no currency falls back to the tenant's, which is the
        // inherited default rather than a stored one.
        if (empty($validated['tuition_currency'])) {
            $validated['tuition_currency'] = ProgrammePrice::tenantCurrency();
        }

        return $validated;
    }

    public function store(Request $request)
    {
        // NOTE: the payload is logged by NAME only. The previous version logged
        // `$request->all()`, which would put an uploaded file's contents and any
        // future secret-bearing field into the application log. The route's
        // intent is what is useful for support.
        Log::info('ProgrammeController@store called', [
            'user_id' => Auth::id(),
            'school_id' => $this->school_id,
        ]);

        $validated = $request->validate($this->validationRules());
        $validated = $this->normalisePriceMetadata($validated);

        $validated['school_id']  = $this->school_id;
        $validated['is_active']  = 1;
        $programme = Programme::create($validated);
        AuditLog::record('create', 'Programmes', "Created programme: {$programme->name}");

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['status' => 'success', 'message' => get_phrase('Programme created successfully')]);
        }

        return redirect()->route('admin.programmes.index')->with('success', get_phrase('Programme created successfully'));
    }

    public function update(Request $request, $id)
    {
        $programme = $this->findOwned($id);
        $validated = $request->validate($this->validationRules($programme));
        $validated = $this->normalisePriceMetadata($validated);

        $programme->update($validated);
        AuditLog::record('update', 'Programmes', "Updated programme: {$programme->name}");

        // A published programme's public card shows its name and level. Refreshing
        // the projection here is what makes the academic record authoritative:
        // editing the name updates the website without a second, manual step.
        // Publication state is untouched — a rename must not withdraw a live card.
        if ($programme->is_published) {
            $this->publisher->refreshProjection($programme);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['status' => 'success', 'message' => get_phrase('Programme updated successfully')]);
        }

        return redirect()->route('admin.programmes.index')->with('success', get_phrase('Programme updated successfully'));
    }

    public function destroy($id)
    {
        $programme = Programme::where('school_id', $this->school_id)->findOrFail($id);

        // A programme selectable in the admission portal (or already chosen
        // by a live application) should never disappear out from under an
        // applicant mid-application — deactivating hides it from new
        // applicants without corrupting an application that already
        // references it. Only a programme nobody has ever touched is
        // actually deletable.
        if ($programme->admissions()->exists() || $programme->studentProfiles()->exists()) {
            return redirect()->back()->with('error', get_phrase('This programme has applications or students linked to it and cannot be deleted. Deactivate it instead.'));
        }

        AuditLog::record('delete', 'Programmes', "Deleted programme: {$programme->name}");
        $programme->delete();
        return redirect()->back()->with('success', get_phrase('Programme deleted'));
    }

    public function toggleStatus($id)
    {
        $programme = $this->findOwned($id);
        $programme->update(['is_active' => !$programme->is_active]);

        // Deactivating a programme withdraws it from the applicant-facing
        // catalogue without touching `is_published`. The two states are kept
        // separate so re-activating does NOT silently re-advertise a programme an
        // administrator had deliberately unpublished — but a programme that is no
        // longer academically active must stop being advertised, so its CMS row is
        // deactivated here and `is_published` is left as the administrator set it.
        if (! $programme->is_active && $programme->is_published) {
            $item = $this->publisher->projectionFor($programme->refresh());
            if ($item !== null) {
                $item->status = 0;
                $item->save();
            }
        }

        return redirect()->back()->with('success', get_phrase('Status updated'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // CATALOGUE: COVER IMAGE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Upload or replace a programme's public cover.
     *
     * Separate from `update()` because the file needs the cover service's
     * content-validation and 16:9 crop, which must not run on every unrelated
     * save of a programme's name.
     */
    public function storeCover(Request $request, $id)
    {
        $programme = $this->findOwned($id);

        $request->validate([
            'cover_image' => ['required', 'file', 'mimes:'.implode(',', ProgrammeCoverImage::ALLOWED), 'max:8192'],
        ], [
            'cover_image.mimes' => 'The cover must be a JPG, PNG or WebP image.',
            'cover_image.max'   => 'The cover must be smaller than 8 MB.',
        ]);

        // ImageOptimizer re-validates by CONTENT, so a file whose bytes are not a
        // real image is refused here even though its extension passed the rule.
        // The service raises a ValidationException, which renders as a field error.
        $this->covers->set($programme, $request->file('cover_image'));

        // A published programme's catalogue card must show the new photograph
        // now, without requiring an unpublish/republish cycle that would briefly
        // withdraw the programme from the site.
        if ($programme->is_published) {
            $this->publisher->refreshProjection($programme);
        }

        AuditLog::record('update', 'Programmes', "Updated catalogue cover: {$programme->name}");

        return redirect()->back()->with('success', get_phrase('Programme cover updated'));
    }

    public function removeCover(Request $request, $id)
    {
        $programme = $this->findOwned($id);

        $this->covers->clear($programme);

        AuditLog::record('update', 'Programmes', "Removed catalogue cover: {$programme->name}");

        return redirect()->back()->with('success', get_phrase('Programme cover removed'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // CATALOGUE: PUBLICATION
    // ══════════════════════════════════════════════════════════════════════

    public function publish(Request $request, $id)
    {
        $programme = $this->findOwned($id);

        // An academically inactive programme cannot be advertised. Refusing here
        // is better than publishing and hoping the scope filter hides it: the
        // administrator is told the actual reason.
        if (! $programme->is_active) {
            return redirect()->back()->with(
                'error',
                get_phrase('This programme is deactivated. Activate it before publishing it to the website.')
            );
        }

        $request->validate([
            'section_key' => ['nullable', 'string', 'in:'.implode(',', ProgrammePublisher::CATALOGUE_SECTIONS)],
        ]);

        $this->publisher->publish($programme, $request->input('section_key'));

        AuditLog::record('update', 'Programmes', "Published programme to website: {$programme->name}");

        return redirect()->back()->with('success', get_phrase('Programme published to the website catalogue'));
    }

    public function unpublish(Request $request, $id)
    {
        $programme = $this->findOwned($id);

        $this->publisher->unpublish($programme);

        AuditLog::record('update', 'Programmes', "Unpublished programme from website: {$programme->name}");

        return redirect()->back()->with('success', get_phrase('Programme removed from the website catalogue'));
    }

    /**
     * Preview how a programme will appear as a public catalogue card.
     *
     * Rendered from the SAME card partial the live catalogue uses, so a preview
     * cannot drift from the published result. It reads the programme record
     * directly rather than the CMS projection, which means it also works before
     * publication — the point of a preview.
     */
    public function preview(Request $request, $id)
    {
        $programme = $this->findOwned($id);

        $price = ProgrammePrice::forDisplay($programme);

        return response()->view('admin.programme.preview', [
            'programme' => $programme,
            'coverUrl'  => $this->covers->url($programme),
            'price'     => $price,
            'contactLabel' => ProgrammePrice::contactLabel(),
            // The faculty name is what the branded fallback renders, so the
            // preview must resolve it the same way the card does.
            'fallback'  => optional($programme->department)->name
                ?: \Illuminate\Support\Str::headline((string) $programme->level),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function exportCsv(Request $request)
    {
        $search       = $request->search ?? '';
        $departmentId = $request->department_id ?? '';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="programmes_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($search, $departmentId) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                '#', 'Faculty/Department', 'Code', 'Name', 'Level', 'Mode', 'Duration',
                // The tuition columns are the AMOUNT and the BASIS separately, not
                // a merged figure. An export that flattened them would reproduce in
                // a spreadsheet exactly the ambiguity this feature exists to remove:
                // a reader could not tell whether 4,500,000 was per year or for the
                // whole programme.
                'Tuition Amount', 'Tuition Currency', 'Tuition Basis',
                'Academic Status', 'Website Status', 'Published At',
            ]);
            Programme::where('school_id', $this->school_id)
                ->when($search, fn($q) => $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%");
                }))
                ->when($departmentId === 'none', fn($q) => $q->whereNull('department_id'))
                ->when($departmentId !== '' && $departmentId !== 'none', fn($q) => $q->where('department_id', $departmentId))
                ->with('department')
                ->orderBy('name')
                ->get()
                ->each(function ($p, $i) use ($out) {
                    fputcsv($out, [
                        $i+1,
                        optional($p->department)->name ?: 'Unassigned',
                        $p->code, $p->name, $p->level, ucfirst($p->mode), $p->duration,
                        // The ADMIN summary, which states the missing-basis problem
                        // rather than hiding it behind a blank cell.
                        ProgrammePrice::adminSummary($p),
                        ProgrammePrice::currency($p) ?: '',
                        ProgrammePrice::basis($p) ?: '',
                        $p->is_active ? 'Active' : 'Inactive',
                        $p->is_published ? 'Published' : 'Not published',
                        $p->published_at?->toDateTimeString() ?: '',
                    ]);
                });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}
