<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Models\StudentCurriculumAssignment;
use App\Models\User;
use App\Support\Academic\StudentPlacementDirectory;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DirectAcademicPlacementController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $placements = \Illuminate\Support\Facades\DB::table('student_curriculum_assignments as p')
            ->join('users as u', fn ($q) => $q->on('u.id', '=', 'p.student_id')->where('u.school_id', $schoolId))
            ->join('programmes as pr', fn ($q) => $q->on('pr.id', '=', 'p.programme_id')->where('pr.school_id', $schoolId))
            ->join('curricula as c', fn ($q) => $q->on('c.id', '=', 'p.curriculum_id')->where('c.school_id', $schoolId))
            ->leftJoin('curriculum_stages as y', fn ($q) => $q->on('y.id', '=', 'p.entry_curriculum_stage_id')->where('y.school_id', $schoolId))
            ->join('academic_years as ay', fn ($q) => $q->on('ay.id', '=', 'p.entry_academic_year_id')->where('ay.school_id', $schoolId))
            ->where('p.school_id', $schoolId)->select('p.*', 'u.name as student_name', 'u.code as student_code', 'pr.name as programme_name', 'c.version', 'y.label as year_label', 'ay.label as academic_year')
            ->selectSub(fn ($q) => $q->from('course_registrations as cr')->selectRaw('COUNT(*)')->where('cr.school_id', $schoolId)->whereColumn('cr.student_id', 'p.student_id'), 'has_registrations')
            ->when($request->integer('student_id'), fn ($q, $id) => $q->where('p.student_id', $id))
            ->orderByDesc('p.id')->paginate(25, ['*'], 'history_page')->withQueryString();
        $studentStatuses = $this->studentStatuses($request);
        return view('admin.academic.placements', compact('placements', 'studentStatuses'));
    }

    public function update(Request $request, int $id, StudentCurriculumAssignmentService $assignments)
    {
        $data = $request->validate(['curriculum_id' => ['required', 'integer'], 'entry_curriculum_stage_id' => ['required', 'integer'], 'academic_year_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:500']]);
        try {
            $assignments->correctUnusedDirectPlacement((int)$request->user()->school_id, $id, (int)$data['curriculum_id'], (int)$data['entry_curriculum_stage_id'], (int)$data['academic_year_id'], (int)$request->user()->id, $data['reason']);
        } catch (DomainException $e) { return back()->withInput()->withErrors(['placement' => $e->getMessage()]); }
        return redirect()->route('admin.academic.placements.index')->with('success', 'Student placement updated. Matching course allocations will now include this student for eligibility review.');
    }

    public function destroy(Request $request, int $id, StudentCurriculumAssignmentService $assignments)
    {
        try { $assignments->deleteUnusedDirectPlacement((int)$request->user()->school_id, $id, (int)$request->user()->id); }
        catch (DomainException $e) { return back()->withErrors(['placement' => $e->getMessage()]); }
        return redirect()->route('admin.academic.placements.index')->with('success', 'Unused placement deleted. The student account remains.');
    }

    public function edit(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $placement = $request->filled('placement_id') ? StudentCurriculumAssignment::where('school_id', $schoolId)->findOrFail($request->integer('placement_id')) : null;
        $studentId = $placement ? (int)$placement->student_id : $request->integer('student_id');
        $student = $studentId ? User::where('school_id', $schoolId)->where('role_id', 7)->findOrFail($studentId) : null;
        $students = User::where('school_id', $schoolId)->where('role_id', 7)
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable'))
            ->whereHas('studentProfile', fn ($q) => $q->where('school_id', $schoolId))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('student_curriculum_assignments as active_placement')
                ->whereColumn('active_placement.student_id', 'users.id')->where('active_placement.school_id', $schoolId)->whereNull('active_placement.ended_at'))
            ->orderBy('name')->get(['id', 'name', 'email', 'code']);
        $years = AcademicYear::where('school_id', $schoolId)->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('start_date')->get(['id', 'label', 'status', 'start_date', 'end_date']);
        $curricula = Curriculum::where('school_id', $schoolId)->where('status', 'approved')->with('programme')->orderByDesc('created_at')->get(['id', 'programme_id', 'version']);
        $stages = CurriculumStage::where('curriculum_stages.school_id', $schoolId)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('curriculum_stages', 'archived_at'), fn ($query) => $query->whereNull('curriculum_stages.archived_at'))
            ->join('curricula', function ($join) use ($schoolId): void {
                $join->on('curricula.id', '=', 'curriculum_stages.curriculum_id')->where('curricula.school_id', $schoolId)->where('curricula.status', 'approved');
            })->orderBy('curriculum_stages.sequence')->get(['curriculum_stages.id', 'curriculum_stages.curriculum_id', 'curriculum_stages.label', 'curriculum_stages.sequence']);
        $existing = $student ? StudentCurriculumAssignment::where('school_id', $schoolId)->where('student_id', $student->id)->whereNull('ended_at')->exists() : false;
        if ($placement) $existing = $placement->ended_at !== null || $placement->programme_cohort_membership_id !== null || \Illuminate\Support\Facades\DB::table('course_registrations')->where('school_id', $schoolId)->where('student_id', $studentId)->exists();
        $studentStatuses = $this->studentStatuses($request);
        $selectedStatus = $student ? app(StudentPlacementDirectory::class)->query($schoolId)->where('u.id', $student->id)->first() : null;
        return view('admin.academic.direct_placement', compact('student', 'students', 'years', 'curricula', 'stages', 'existing', 'placement', 'studentStatuses', 'selectedStatus'));
    }

    private function studentStatuses(Request $request)
    {
        $query = app(StudentPlacementDirectory::class)->query((int) $request->user()->school_id);
        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('u.name', 'like', "%{$search}%")
                ->orWhere('u.email', 'like', "%{$search}%")->orWhere('u.code', 'like', "%{$search}%")
                ->orWhere('u.id', $search));
        }
        if ($request->input('source') === 'existing') $query->where('sp.was_existing_at_portal_creation', true);
        if ($request->input('placement_status') === 'unplaced') $query->whereNull('p.id');
        if ($request->input('placement_status') === 'assigned') $query->whereNotNull('p.id');
        if ($request->integer('student_id')) $query->where('u.id', $request->integer('student_id'));
        return $query->orderBy('u.name')->orderBy('u.id')->paginate(25, ['*'], 'students_page')->withQueryString();
    }

    public function store(Request $request, StudentCurriculumAssignmentService $assignments)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('school_id', $schoolId)->where('role_id', 7)],
            'curriculum_id' => ['required', 'integer', Rule::exists('curricula', 'id')->where('school_id', $schoolId)->where('status', 'approved')],
            'entry_curriculum_stage_id' => ['required', 'integer'],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);
        try {
            $curriculum = Curriculum::where('school_id', $schoolId)->whereKey($data['curriculum_id'])->where('status', 'approved')->firstOrFail();
            $assignments->assignDirectPlacement($schoolId, (int) $data['student_id'], (int) $curriculum->programme_id, (int) $data['curriculum_id'], (int) $data['entry_curriculum_stage_id'], (int) $data['academic_year_id'], (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['placement' => $exception->getMessage()]);
        }
        $student = User::where('school_id', $schoolId)->findOrFail($data['student_id']);
        $stage = CurriculumStage::where('school_id', $schoolId)->findOrFail($data['entry_curriculum_stage_id']);
        $year = AcademicYear::where('school_id', $schoolId)->findOrFail($data['academic_year_id']);
        $programme = $curriculum->programme;
        return redirect()->route('admin.academic.placements.index', ['student_id' => $student->id])->with('success',
            "Placement saved for {$student->name} ({$student->code}): {$programme->name}, curriculum {$curriculum->version}, {$stage->label}, Academic Year {$year->label}. Find this student under Not Registered in matching Course Allocations, then register and confirm. No class/section or course registration was created.");
    }
}
