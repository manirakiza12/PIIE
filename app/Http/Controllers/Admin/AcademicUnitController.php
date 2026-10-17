<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicUnit;
use App\Models\Department;
use App\Models\Programme;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicUnitController extends Controller
{
    public function index()
    {
        return redirect()->route('admin.department.schools_faculties');
    }

    public function schoolsAndFaculties(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $units = AcademicUnit::query()->where('school_id', $schoolId)->with('parent.parent')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->withCount(['departments' => fn ($q) => $q->where('school_id', $schoolId)])
            ->selectSub(DB::table('programmes')->join('departments', 'departments.id', '=', 'programmes.department_id')
                ->whereColumn('departments.academic_unit_id', 'academic_units.id')
                ->where('departments.school_id', $schoolId)->where('programmes.school_id', $schoolId)->selectRaw('count(*)'), 'programmes_count')
            ->orderByRaw("CASE WHEN type = 'school' THEN 0 ELSE 1 END")->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.department.schools_faculties', compact('units'));
    }

    public function departments(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $departments = Department::query()->where('school_id', $schoolId)->with('academicUnit.parent.parent')
            ->withCount(['programmes' => fn ($q) => $q->where('school_id', $schoolId)])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->when($request->filled('academic_unit_id'), fn ($q) => $q->where('academic_unit_id', (int) $request->query('academic_unit_id')))
            ->orderBy('name')->paginate(15)->withQueryString();
        $parents = AcademicUnit::query()->where('school_id', $schoolId)->where('status', 'active')->with('parent.parent')->orderBy('name')->get();

        return view('admin.department.departments', compact('departments', 'parents'));
    }

    public function createUnit(Request $request)
    {
        $type = $request->query('type');
        abort_unless($type === null || in_array($type, AcademicUnit::TYPES, true), 404);
        $unit = null;
        $parents = $this->parentOptions((int) $request->user()->school_id, null);
        return view('admin.department.unit_form', compact('unit', 'type', 'parents'));
    }

    public function storeUnit(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $this->validateUnit($request, $schoolId);
        $this->assertNoCycle($schoolId, $data['parent_id'] ?? null, null);

        DB::transaction(fn () => AcademicUnit::query()->create([
            'school_id' => $schoolId,
            'type' => $data['type'],
            'name' => trim($data['name']),
            'code' => $this->cleanOptional($data['code'] ?? null),
            'head_title' => $this->cleanOptional($data['head_title'] ?? null),
            'head_name' => $this->cleanOptional($data['head_name'] ?? null),
            'description' => $this->cleanOptional($data['description'] ?? null),
            'status' => $data['status'],
            'parent_id' => $data['type'] === AcademicUnit::TYPE_FACULTY ? (int) $data['parent_id'] : null,
        ]));

        return redirect()->route('admin.department.schools_faculties')->with('success', ucfirst($data['type']).' created.');
    }

    public function showUnit(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $unit = AcademicUnit::query()->where('school_id', $schoolId)
            ->with(['parent.parent', 'children' => fn ($q) => $q->where('school_id', $schoolId), 'departments' => fn ($q) => $q->where('school_id', $schoolId)])
            ->findOrFail($id);
        $programmesCount = Programme::query()->where('school_id', $request->user()->school_id)
            ->whereIn('department_id', $unit->departments->pluck('id'))->count();
        return view('admin.department.unit_show', compact('unit', 'programmesCount'));
    }

    public function editUnit(Request $request, int $id)
    {
        $unit = AcademicUnit::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        $parents = $this->parentOptions((int) $request->user()->school_id, $unit);
        $type = $unit->type;
        return view('admin.department.unit_form', compact('unit', 'type', 'parents'));
    }

    public function updateUnit(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $unit = AcademicUnit::query()->where('school_id', $schoolId)->findOrFail($id);
        $data = $this->validateUnit($request, $schoolId, $unit);
        $this->assertNoCycle($schoolId, $data['parent_id'] ?? null, $unit->id);

        DB::transaction(fn () => $unit->update([
            'name' => trim($data['name']),
            'code' => $this->cleanOptional($data['code'] ?? null),
            'head_title' => $this->cleanOptional($data['head_title'] ?? null),
            'head_name' => $this->cleanOptional($data['head_name'] ?? null),
            'description' => $this->cleanOptional($data['description'] ?? null),
            'status' => $data['status'],
            'parent_id' => $unit->type === AcademicUnit::TYPE_FACULTY ? (int) $data['parent_id'] : null,
        ]));

        return redirect()->route('admin.department.schools_faculties')->with('success', ucfirst($unit->type).' updated.');
    }

    public function createDepartment(Request $request)
    {
        $department = null;
        $parents = AcademicUnit::query()->where('school_id', $request->user()->school_id)->where('status', 'active')->with('parent.parent')->orderBy('name')->get();
        return view('admin.department.add_department', compact('department', 'parents'));
    }

    public function createDepartmentPage(Request $request)
    {
        $department = null;
        $parents = AcademicUnit::query()->where('school_id', $request->user()->school_id)->where('status', 'active')->with('parent.parent')->orderBy('name')->get();
        return view('admin.department.department_form', compact('department', 'parents'));
    }

    public function storeDepartment(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $this->validateDepartment($request, $schoolId);
        DB::transaction(fn () => Department::query()->create([
            'school_id' => $schoolId,
            'name' => trim($data['name']),
            'code' => $this->cleanOptional($data['code'] ?? null),
            'hod_name' => $this->cleanOptional($data['hod_name'] ?? null),
            'academic_unit_id' => (int) $data['academic_unit_id'],
        ]));

        return redirect()->route('admin.department.departments')->with('success', 'Department created.');
    }

    public function showDepartment(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $department = Department::query()->where('school_id', $schoolId)->with('academicUnit.parent.parent')
            ->withCount(['programmes' => fn ($q) => $q->where('school_id', $schoolId)])->findOrFail($id);
        return view('admin.department.department_show', compact('department'));
    }

    public function editDepartment(Request $request, int $id)
    {
        $department = Department::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        $parents = AcademicUnit::query()->where('school_id', $request->user()->school_id)->where('status', 'active')->with('parent.parent')->orderBy('name')->get();
        return view('admin.department.edit_department', compact('department', 'parents'));
    }

    public function editDepartmentPage(Request $request, int $id)
    {
        $department = Department::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        $parents = AcademicUnit::query()->where('school_id', $request->user()->school_id)->where('status', 'active')->with('parent.parent')->orderBy('name')->get();
        return view('admin.department.department_form', compact('department', 'parents'));
    }

    public function updateDepartment(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $department = Department::query()->where('school_id', $schoolId)->findOrFail($id);
        $data = $this->validateDepartment($request, $schoolId, $department);
        DB::transaction(fn () => $department->update([
            'name' => trim($data['name']),
            'code' => $this->cleanOptional($data['code'] ?? null),
            'hod_name' => $this->cleanOptional($data['hod_name'] ?? null),
            'academic_unit_id' => (int) $data['academic_unit_id'],
        ]));

        return redirect()->route('admin.department.departments')->with('success', 'Department updated.');
    }

    public function deleteDepartment(Request $request, int $id)
    {
        Department::query()->where('school_id', $request->user()->school_id)->findOrFail($id);
        return redirect()->route('admin.department.departments')->with('error', 'Department removal is disabled on this legacy link. Academic records must be reassigned through an approved workflow first.');
    }

    private function validateUnit(Request $request, int $schoolId, ?AcademicUnit $unit = null): array
    {
        $type = $unit?->type ?? $request->input('type');
        if (! in_array($type, AcademicUnit::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose School or Faculty.']);
        }

        $parentRule = Rule::exists('academic_units', 'id')->where(fn (Builder $query) => $query
            ->where('school_id', $schoolId)->whereIn('type', AcademicUnit::TYPES));
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique('academic_units', 'name')->where(fn (Builder $query) => $query->where('school_id', $schoolId)->where('type', $type))->ignore($unit?->id)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('academic_units', 'code')->where(fn (Builder $query) => $query->where('school_id', $schoolId)->where('type', $type))->ignore($unit?->id)],
            'head_title' => ['nullable', 'string', 'max:100'],
            'head_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'parent_id' => $type === AcademicUnit::TYPE_FACULTY
                ? ['required', 'integer', $parentRule]
                : ['nullable', 'prohibited'],
        ];
        return array_merge(['type' => $type], $request->validate($rules, [
            'parent_id.required' => 'Select the School or Faculty this Faculty belongs to.',
            'parent_id.exists' => 'Select a School or Faculty from this institution.',
            'parent_id.prohibited' => 'A School is a top-level academic unit and cannot have a parent.',
        ]));
    }

    private function validateDepartment(Request $request, int $schoolId, ?Department $department = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where(fn (Builder $query) => $query->where('school_id', $schoolId))->ignore($department?->id)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('departments', 'code')->where(fn (Builder $query) => $query->where('school_id', $schoolId))->ignore($department?->id)],
            'hod_name' => ['nullable', 'string', 'max:255'],
            'academic_unit_id' => ['required', 'integer', Rule::exists('academic_units', 'id')->where(fn (Builder $query) => $query->where('school_id', $schoolId)->whereIn('type', AcademicUnit::TYPES)->where('status', 'active'))],
        ], [
            'academic_unit_id.required' => 'Select the School or Faculty this Department belongs to.',
            'academic_unit_id.exists' => 'Select an active School or Faculty from this institution.',
        ]);
    }

    private function cleanOptional(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function parentOptions(int $schoolId, ?AcademicUnit $editing)
    {
        $excluded = [];
        if ($editing) {
            $excluded = [$editing->id];
            $frontier = [$editing->id];
            while ($frontier !== []) {
                $children = AcademicUnit::query()->where('school_id', $schoolId)->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $children = array_values(array_diff($children, $excluded));
                $excluded = array_merge($excluded, $children);
                $frontier = $children;
            }
        }

        return AcademicUnit::query()->where('school_id', $schoolId)->whereIn('type', AcademicUnit::TYPES)
            ->whereNotIn('id', $excluded)->with('parent.parent')->orderBy('name')->get();
    }

    private function assertNoCycle(int $schoolId, mixed $parentId, ?int $unitId): void
    {
        if ($parentId === null || $parentId === '') return;
        $cursor = AcademicUnit::query()->where('school_id', $schoolId)->findOrFail($parentId);
        $visited = [];
        while ($cursor) {
            if (($unitId !== null && (int) $cursor->id === $unitId) || in_array((int) $cursor->id, $visited, true)) {
                throw ValidationException::withMessages(['parent_id' => 'This parent would create a circular academic hierarchy.']);
            }
            $visited[] = (int) $cursor->id;
            $cursor = $cursor->parent_id
                ? AcademicUnit::query()->where('school_id', $schoolId)->find($cursor->parent_id)
                : null;
        }
    }
}
