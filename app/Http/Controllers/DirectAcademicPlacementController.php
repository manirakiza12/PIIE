<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Models\StudentCurriculumAssignment;
use App\Models\User;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DirectAcademicPlacementController extends Controller
{
    public function edit(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $studentId = $request->integer('student_id');
        $student = $studentId ? User::where('school_id', $schoolId)->where('role_id', 7)->findOrFail($studentId) : null;
        $students = User::where('school_id', $schoolId)->where('role_id', 7)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('student_curriculum_assignments as active_placement')
                ->whereColumn('active_placement.student_id', 'users.id')->where('active_placement.school_id', $schoolId)->whereNull('active_placement.ended_at'))
            ->orderBy('name')->get(['id', 'name', 'email']);
        $years = AcademicYear::where('school_id', $schoolId)->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('start_date')->get(['id', 'label', 'status']);
        $curricula = Curriculum::where('school_id', $schoolId)->where('status', 'approved')->with('programme')->orderByDesc('created_at')->get(['id', 'programme_id', 'version']);
        $stages = CurriculumStage::where('curriculum_stages.school_id', $schoolId)
            ->join('curricula', function ($join) use ($schoolId): void {
                $join->on('curricula.id', '=', 'curriculum_stages.curriculum_id')->where('curricula.school_id', $schoolId)->where('curricula.status', 'approved');
            })->orderBy('curriculum_stages.sequence')->get(['curriculum_stages.id', 'curriculum_stages.curriculum_id', 'curriculum_stages.label', 'curriculum_stages.sequence']);
        $existing = $student ? StudentCurriculumAssignment::where('school_id', $schoolId)->where('student_id', $student->id)->whereNull('ended_at')->exists() : false;
        return view('admin.academic.direct_placement', compact('student', 'students', 'years', 'curricula', 'stages', 'existing'));
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
        return redirect()->route('admin.student.student_profile', $data['student_id'])->with('success', 'Student academic placement saved. No student group was needed.');
    }
}
