<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\CurriculumStage;
use App\Models\Programme;
use App\Models\StudentAcademicExemption;
use App\Models\User;
use App\Support\Exemptions\ExemptionService;
use Illuminate\Http\Request;

class StudentExemptionController extends Controller
{
    public function __construct(private ExemptionService $exemptions) {}

    public function index(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $exemptions = StudentAcademicExemption::query()
            ->where('school_id', $schoolId)
            ->with(['student', 'programme', 'curriculum', 'requester', 'decider'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.exemptions.index', compact('exemptions'));
    }

    public function create(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $students = User::query()->where('school_id', $schoolId)->where('role_id', 7)->orderBy('name')->get(['id', 'name', 'code']);
        $programmes = Programme::query()->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'code']);
        return view('admin.exemptions.create', compact('students', 'programmes'));
    }

    public function store(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'programme_id' => ['required', 'integer'],
            'curriculum_id' => ['required', 'integer'],
            'exemption_type' => ['required', 'in:level,course_unit'],
            'target_curriculum_stage_id' => ['nullable', 'integer'],
            'previous_institution' => ['nullable', 'string', 'max:255'],
            'previous_qualification' => ['nullable', 'string', 'max:255'],
            'previous_results' => ['nullable', 'string'],
            'reason' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.curriculum_membership_id' => ['nullable', 'integer'],
            'items.*.subject_id' => ['nullable', 'integer'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $data['school_id'] = $schoolId;
        $data['requested_by'] = $request->user()->id;

        $exemption = $this->exemptions->submit($data);

        return redirect()->route('admin.exemptions.show', $exemption->id)
            ->with('message', 'Exemption request submitted for approval.');
    }

    public function show(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $exemption = StudentAcademicExemption::query()
            ->where('school_id', $schoolId)
            ->with(['student', 'programme', 'curriculum', 'targetStage', 'requester', 'decider', 'items.curriculumMembership', 'items.subject'])
            ->findOrFail($id);

        $curriculumStages = $exemption->curriculum
            ? $exemption->curriculum->stages()->orderBy('sequence')->get()
            : collect();
        $curriculumMemberships = $exemption->curriculum
            ? $exemption->curriculum->memberships()->with('subject')->orderBy('sequence')->get()
            : collect();

        return view('admin.exemptions.show', compact('exemption', 'curriculumStages', 'curriculumMemberships'));
    }

    public function approve(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $exemption = StudentAcademicExemption::query()->where('school_id', $schoolId)->findOrFail($id);
        $this->exemptions->approve($exemption, $request->user()->id, $request->input('decision_notes'));
        return redirect()->route('admin.exemptions.show', $id)->with('message', 'Exemption approved.');
    }

    public function reject(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $exemption = StudentAcademicExemption::query()->where('school_id', $schoolId)->findOrFail($id);
        $this->exemptions->reject($exemption, $request->user()->id, $request->input('decision_notes'));
        return redirect()->route('admin.exemptions.show', $id)->with('message', 'Exemption rejected.');
    }

    public function cancel(Request $request, int $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $exemption = StudentAcademicExemption::query()->where('school_id', $schoolId)->findOrFail($id);
        $this->exemptions->cancel($exemption);
        return redirect()->route('admin.exemptions.show', $id)->with('message', 'Exemption cancelled.');
    }

    public function curriculumStages(Request $request, int $curriculumId)
    {
        $schoolId = (int) $request->user()->school_id;
        $curriculum = Curriculum::query()->where('school_id', $schoolId)->findOrFail($curriculumId);
        return response()->json($curriculum->stages()->orderBy('sequence')->get(['id', 'name']));
    }

    public function curriculaByProgramme(Request $request, int $programmeId)
    {
        $schoolId = (int) $request->user()->school_id;
        $curricula = Curriculum::query()
            ->where('school_id', $schoolId)
            ->where('programme_id', $programmeId)
            ->orderByDesc('id')
            ->get(['id', 'name', 'version']);
        return response()->json($curricula);
    }
    public function curriculumMemberships(Request $request, int $curriculumId)
    {
        $schoolId = (int) $request->user()->school_id;
        return response()->json($memberships->map(fn ($m) => [
            'id' => $m->id,
            'subject_id' => $m->subject_id,
            'name' => $m->subject?->name,
            'code' => $m->subject?->code,
            'stage_id' => $m->curriculum_stage_id,
        ]));
    }
}