<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Programme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProgrammeController extends Controller
{
    private $school_id;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->school_id = Auth::user()->school_id;
            return $next($request);
        });
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
        $programme   = $id ? Programme::where('school_id', $this->school_id)->findOrFail($id) : null;
        $departments = Department::where('school_id', $this->school_id)->orderBy('name')->get();
        return view('admin.programme.modal', compact('programme', 'departments'));
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
        ];
    }

    public function store(Request $request)
    {
        Log::info('ProgrammeController@store called', ['payload' => $request->all(), 'user_id' => Auth::id()]);
        $validated = $request->validate($this->validationRules());

        $validated['school_id']  = $this->school_id;
        $validated['is_active']  = 1;
        $programme = Programme::create($validated);
        AuditLog::record('create', 'Programmes', "Created programme: {$programme->name}");
        Log::info('Programme created', ['id' => $programme->id, 'attrs' => $programme->toArray()]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['status' => 'success', 'message' => get_phrase('Programme created successfully')]);
        }

        return redirect()->route('admin.programmes.index')->with('success', get_phrase('Programme created successfully'));
    }

    public function update(Request $request, $id)
    {
        $programme = Programme::where('school_id', $this->school_id)->findOrFail($id);
        $validated = $request->validate($this->validationRules($programme));

        $programme->update($validated);
        AuditLog::record('update', 'Programmes', "Updated programme: {$programme->name}");

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
        $programme = Programme::where('school_id', $this->school_id)->findOrFail($id);
        $programme->update(['is_active' => !$programme->is_active]);
        return redirect()->back()->with('success', get_phrase('Status updated'));
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
            fputcsv($out, ['#', 'Faculty/Department', 'Code', 'Name', 'Level', 'Mode', 'Duration', 'Tuition Fee', 'Status']);
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
                    fputcsv($out, [$i+1, optional($p->department)->name ?: 'Unassigned', $p->code, $p->name, $p->level, ucfirst($p->mode), $p->duration, $p->tuition_fee, $p->is_active ? 'Active' : 'Inactive']);
                });
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }
}
