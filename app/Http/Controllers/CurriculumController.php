<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\CurriculumStage;
use App\Models\Programme;
use App\Models\Subject;
use App\Support\Curriculum\CurriculumFoundationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CurriculumController extends Controller
{
    public function __construct(private CurriculumFoundationService $curricula)
    {
    }

    public function index(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $query = Curriculum::query()->where('curricula.school_id', $schoolId)
            ->join('programmes', function ($join) use ($schoolId): void {
                $join->on('programmes.id', '=', 'curricula.programme_id')->where('programmes.school_id', '=', $schoolId);
            })
            ->leftJoin('departments', function ($join) use ($schoolId): void {
                $join->on('departments.id', '=', 'programmes.department_id')->where('departments.school_id', '=', $schoolId);
            })
            ->leftJoin('academic_years', function ($join) use ($schoolId): void {
                $join->on('academic_years.id', '=', 'curricula.effective_academic_year_id')->where('academic_years.school_id', '=', $schoolId);
            })
            ->select([
                'curricula.*', 'programmes.name as programme_name', 'programmes.code as programme_code',
                'departments.name as department_name', 'academic_years.label as academic_year_label',
            ])
            ->withCount(['stages', 'memberships'])
            ->withSum('memberships as total_credits', 'credits');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search): void {
                $where->where('programmes.name', 'like', "%{$search}%")
                    ->orWhere('programmes.code', 'like', "%{$search}%")
                    ->orWhere('curricula.version', 'like', "%{$search}%");
            });
        }

        $query->when(in_array($request->query('status'), Curriculum::STATUSES, true), fn ($q) => $q->where('curricula.status', $request->query('status')))
            ->when($request->filled('programme_id'), fn ($q) => $q->where('curricula.programme_id', (int) $request->query('programme_id')))
            ->when($request->filled('department_id'), fn ($q) => $q->where('programmes.department_id', (int) $request->query('department_id')));
        if ($request->filled('academic_year_id')) {
            $applicableYear = AcademicYear::where('school_id', $schoolId)->findOrFail($request->integer('academic_year_id'));
            $query->where('academic_years.start_date', '<=', $applicableYear->start_date->toDateString());
        }

        $curriculumRows = $query->orderBy('programmes.name')->orderByDesc('curricula.created_at')->paginate(20)->withQueryString();
        $programmes = Programme::where('school_id', $schoolId)->orderBy('name')->get(['id', 'code', 'name', 'department_id']);
        $departments = DB::table('departments')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name']);
        $academicYears = AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->get(['id', 'label', 'start_date', 'end_date']);
        $selectedProgramme = $request->filled('programme_id')
            ? $programmes->firstWhere('id', (int) $request->query('programme_id'))
            : null;

        return view('admin.curricula.index', compact('curriculumRows', 'programmes', 'departments', 'academicYears', 'selectedProgramme'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->curricula->deleteDraft($this->tenantCurriculum($request, $id));
        return redirect()->route('admin.curricula.index')->with('success', 'Draft Programme Curriculum deleted.');
    }

    public function units(Request $request)
    {
        $curricula = Curriculum::where('school_id', $request->user()->school_id)->with('programme')->orderBy('programme_id')->orderBy('version')->get();
        $data = $request->filled('curriculum_id')
            ? $this->show($request, $request->integer('curriculum_id'))->getData()
            : [];
        return view('admin.curriculum_units.index', $data + compact('curricula'));
    }

    public function create(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $programmes = Programme::where('school_id', $schoolId)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $academicYears = AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->get(['id', 'label', 'start_date', 'end_date']);
        $selectedProgrammeId = $request->integer('programme_id') ?: null;

        return view('admin.curricula.create', compact('programmes', 'academicYears', 'selectedProgrammeId'));
    }

    public function store(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'programme_id' => ['required', 'integer', Rule::exists('programmes', 'id')->where('school_id', $schoolId)],
            'version' => ['required', 'string', 'max:50'],
            'effective_academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        $curriculum = $this->curricula->createDraft(
            $schoolId,
            (int) $data['programme_id'],
            $data['version'],
            isset($data['effective_academic_year_id']) ? (int) $data['effective_academic_year_id'] : null
        );

        return redirect()->route('admin.curricula.show', $curriculum->id)->with('success', 'Draft Programme Study Plan created.');
    }

    public function show(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $curriculum->load([
            'programme.department', 'effectiveAcademicYear',
            'stages.memberships.subject',
            'memberships.stage', 'memberships.subject', 'memberships.prerequisites.subject',
        ]);
        $schoolId = (int) $request->user()->school_id;
        $school = DB::table('schools')->where('id', $schoolId)->first(['academic_calendar_pattern', 'school_type']);
        $calendarPattern = $school->academic_calendar_pattern ?: ($school->school_type === 'higher_ed' ? 'semester' : 'term');
        $academicYears = AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->get(['id', 'label', 'start_date', 'end_date']);
        $canManage = $request->user()->hasPermission('academic.curriculum.manage');
        $canApprove = $request->user()->hasPermission('academic.curriculum.approve');
        $reviewBlockers = array_values($this->curricula->approvalBlockers($curriculum));

        return view('admin.curricula.show', compact('curriculum', 'academicYears', 'calendarPattern', 'canManage', 'canApprove', 'reviewBlockers'));
    }

    public function update(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'effective_academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);
        $this->curricula->updateDraftMetadata($curriculum, $data['version'], isset($data['effective_academic_year_id']) ? (int) $data['effective_academic_year_id'] : null);

        return back()->with('success', 'Draft metadata saved.');
    }

    public function storeStage(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'sequence' => ['required', 'integer', 'min:1', 'max:65535']]);
        $this->curricula->addStage($curriculum, $data['label'], (int) $data['sequence']);
        $request->session()->forget('errors');
        return back()->with('success', 'Programme Year added.');
    }

    public function updateStage(Request $request, int $id, int $stageId)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $stage = $this->tenantStage($curriculum, $stageId);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'sequence' => ['required', 'integer', 'min:1', 'max:65535']]);
        $this->curricula->updateStage($curriculum, $stage, $data['label'], (int) $data['sequence']);
        return back()->with('success', 'Programme Year updated.');
    }

    public function moveStage(Request $request, int $id, int $stageId)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $stage = $this->tenantStage($curriculum, $stageId);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $this->curricula->moveStage($curriculum, $stage, $data['direction']);
        return back()->with('success', 'Programme Year order updated.');
    }

    public function destroyStage(Request $request, int $id, int $stageId)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $this->curricula->removeStage($curriculum, $this->tenantStage($curriculum, $stageId));
        return back()->with('success', 'Programme Year removed.');
    }

    public function searchSubjects(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $search = trim((string) $request->query('search', ''));
        $subjects = Subject::where('school_id', $curriculum->school_id)
            ->whereNotIn('id', CurriculumMembership::where('school_id', $curriculum->school_id)->where('curriculum_id', $curriculum->id)->select('subject_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderByRaw('CASE WHEN programme_id = ? THEN 0 ELSE 1 END', [$curriculum->programme_id])
            ->orderBy('name')->select(['id', 'school_id', 'name', 'code', 'programme_id'])->paginate(20);

        return response()->json(['data' => $subjects->items(), 'next_page_url' => $subjects->nextPageUrl()]);
    }

    public function addMemberships(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $data = $request->validate([
            'curriculum_stage_id' => ['required', 'integer'],
            'subject_ids' => ['required', 'array', 'min:1', 'max:100'],
            'subject_ids.*' => ['required', 'integer'],
        ]);
        $stage = $this->tenantStage($curriculum, (int) $data['curriculum_stage_id']);
        DB::transaction(function () use ($curriculum, $stage, $data): void {
            foreach (array_unique(array_map('intval', $data['subject_ids'])) as $subjectId) {
                $this->curricula->addMembership($curriculum, $subjectId, (int) $stage->id, [
                    'classification' => 'compulsory', 'credits' => '0.00', 'sequence' => 0,
                ]);
            }
        });
        return back()->with('success', 'Course Unit(s) added as draft memberships. Set placement and credits before review.');
    }

    public function updateMembership(Request $request, int $id, int $membershipId)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $membership = $this->tenantMembership($curriculum, $membershipId);
        $data = $request->validate([
            'curriculum_stage_id' => ['required', 'integer'],
            'period_type' => ['nullable', Rule::in(['semester', 'term'])],
            'period_sequence' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'classification' => ['required', Rule::in(CurriculumMembership::CLASSIFICATIONS)],
            'credits' => ['required', 'numeric', 'min:0', 'max:9999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'sequence' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $data['curriculum_stage_id'] = (int) $data['curriculum_stage_id'];
        $this->curricula->updateMembership($curriculum, $membership, $data);
        return back()->with('success', 'Course Unit placement saved.');
    }

    public function destroyMembership(Request $request, int $id, int $membershipId)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $this->curricula->removeMembership($curriculum, $this->tenantMembership($curriculum, $membershipId));
        return back()->with('success', 'Course Unit removed from this draft.');
    }

    public function addPrerequisite(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $data = $request->validate(['membership_id' => ['required', 'integer'], 'prerequisite_membership_id' => ['required', 'integer']]);
        $this->curricula->addPrerequisite($curriculum, (int) $data['membership_id'], (int) $data['prerequisite_membership_id']);
        return back()->with('success', 'Prerequisite added.');
    }

    public function destroyPrerequisite(Request $request, int $id)
    {
        $curriculum = $this->tenantCurriculum($request, $id);
        $data = $request->validate(['membership_id' => ['required', 'integer'], 'prerequisite_membership_id' => ['required', 'integer']]);
        $this->curricula->removePrerequisite($curriculum, (int) $data['membership_id'], (int) $data['prerequisite_membership_id']);
        return back()->with('success', 'Prerequisite removed.');
    }

    public function approve(Request $request, int $id)
    {
        $this->curricula->approve($this->tenantCurriculum($request, $id));
        return redirect()->route('admin.curricula.show', $id)->with('success', 'Curriculum approved and now official.');
    }

    public function retire(Request $request, int $id)
    {
        $this->curricula->retire($this->tenantCurriculum($request, $id));
        return redirect()->route('admin.curricula.show', $id)->with('success', 'Curriculum retired and preserved as history.');
    }

    public function successor(Request $request, int $id)
    {
        $source = $this->tenantCurriculum($request, $id);
        $schoolId = (int) $request->user()->school_id;
        $data = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'effective_academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'mode' => ['required', Rule::in(['clone', 'blank'])],
        ]);
        $successor = $this->curricula->createSuccessor($source, $data['version'], isset($data['effective_academic_year_id']) ? (int) $data['effective_academic_year_id'] : null, $data['mode'] === 'clone');
        return redirect()->route('admin.curricula.show', $successor->id)->with('success', 'Successor draft created. Source history is unchanged.');
    }

    private function tenantCurriculum(Request $request, int $id): Curriculum
    {
        return Curriculum::where('school_id', (int) $request->user()->school_id)->findOrFail($id);
    }

    private function tenantStage(Curriculum $curriculum, int $id): CurriculumStage
    {
        return CurriculumStage::where('school_id', $curriculum->school_id)->where('curriculum_id', $curriculum->id)->findOrFail($id);
    }

    private function tenantMembership(Curriculum $curriculum, int $id): CurriculumMembership
    {
        return CurriculumMembership::where('school_id', $curriculum->school_id)->where('curriculum_id', $curriculum->id)->findOrFail($id);
    }
}
