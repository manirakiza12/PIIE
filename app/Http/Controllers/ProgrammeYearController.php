<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Support\Curriculum\CurriculumFoundationService;
use App\Support\Curriculum\CurriculumOwnerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProgrammeYearController extends Controller
{
    public function __construct(private CurriculumFoundationService $curricula) {}

    public function index(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $curricula = Curriculum::where('school_id', $schoolId)->with('programme')->orderBy('programme_id')->orderBy('version')->get();
        $selected = $request->filled('curriculum_id')
            ? Curriculum::where('school_id', $schoolId)->with('programme')->findOrFail($request->integer('curriculum_id')) : null;
        $years = CurriculumStage::where('school_id', $schoolId)->with('curriculum.programme')->withCount('memberships')
            ->when($selected, fn ($q) => $q->where('curriculum_id', $selected->id))
            ->orderBy('curriculum_id')->orderBy('sequence')->paginate(25)->withQueryString();
        $assignmentCounts = [];
        if (Schema::hasTable('student_curriculum_assignments')
            && Schema::hasColumn('student_curriculum_assignments', 'entry_curriculum_stage_id')
            && $years->getCollection()->isNotEmpty()) {
            $query = DB::table('student_curriculum_assignments')
                ->whereIn('entry_curriculum_stage_id', $years->getCollection()->pluck('id'));
            if (Schema::hasColumn('student_curriculum_assignments', 'school_id')) {
                $query->where('school_id', $schoolId);
            }
            $assignmentCounts = $query->select('entry_curriculum_stage_id', DB::raw('COUNT(*) as references_count'))
                ->groupBy('entry_curriculum_stage_id')->pluck('references_count', 'entry_curriculum_stage_id')->all();
        }
        $years->getCollection()->each(function (CurriculumStage $year) use ($assignmentCounts): void {
            $year->setAttribute('student_assignments_count', (int) ($assignmentCounts[$year->id] ?? 0));
            $year->setAttribute('deletion_impact', $this->curricula->stageDeletionImpact($year->curriculum, $year));
        });
        return view('admin.programme_years.index', compact('curricula', 'selected', 'years'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request));
        $curriculum = Curriculum::where('school_id', $request->user()->school_id)->findOrFail($data['curriculum_id']);
        $this->mutate($request, $curriculum, fn () => $this->curricula->addStage($curriculum, $data['label'], (int) $data['sequence']));
        $request->session()->forget('errors');
        return back()->with('success', 'Programme Year created.');
    }

    public function show(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with(['curriculum.programme', 'memberships.subject'])->findOrFail($id);
        $year->setAttribute('student_assignments_count', Schema::hasTable('student_curriculum_assignments')
            && Schema::hasColumn('student_curriculum_assignments', 'entry_curriculum_stage_id')
            ? DB::table('student_curriculum_assignments')->where('entry_curriculum_stage_id', $year->id)
                ->when(Schema::hasColumn('student_curriculum_assignments', 'school_id'), fn ($query) => $query->where('school_id', $year->school_id))
                ->count()
            : 0);
        $year->setAttribute('deletion_impact', $this->curricula->stageDeletionImpact($year->curriculum, $year));
        return view('admin.programme_years.show', compact('year'));
    }

    public function update(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        abort_if($year->archived_at, 404);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'sequence' => ['required', 'integer', 'min:1', 'max:65535']]);
        $this->mutate($request, $year->curriculum, fn () => $this->curricula->updateStage($year->curriculum, $year, $data['label'], (int) $data['sequence']));
        return back()->with('success', 'Programme Year updated.');
    }

    public function destroy(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('academic.curriculum.manage'), 403);
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        abort_if($year->archived_at, 404);
        $phrase = $year->label . ' — ' . ($year->curriculum->programme?->name ?? 'Programme') . ' — ' . $year->curriculum->version;
        $request->validate(['confirmation' => ['required', 'string', Rule::in([$phrase])]]);
        $result = null;
        $this->mutate($request, $year->curriculum, function () use ($year, &$result): void {
            $result = $this->curricula->removeStage($year->curriculum, $year);
        });
        return back()->with('success', $result['outcome'] === 'archived'
            ? 'Programme Year archived because student or academic history references it. Its Course Unit placements are hidden from new selections; historical records were preserved.'
            : 'Programme Year and its Course Unit placements and prerequisite links were deleted. Shared Course Unit definitions and historical records were preserved.');
    }

    private function mutate(Request $request, Curriculum $curriculum, callable $mutation): void
    {
        abort_unless($request->user()->hasPermission('academic.curriculum.manage'), 403);
        DB::transaction(function () use ($request, $curriculum, $mutation): void {
            $locked = Curriculum::where('school_id', $curriculum->school_id)->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                abort_unless(CurriculumOwnerAccess::allows($request->user(), (int) $locked->school_id), 403);
                $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
                $this->curricula->ownerChangeStatus($locked, 'draft', $data['reason']);
            }
            $mutation();
            if ($locked->status !== 'draft' && CurriculumOwnerAccess::allows($request->user(), (int) $locked->school_id)) {
                $request->session()->flash('curriculum_reopened', true);
            }
        });
    }

    private function rules(Request $request): array
    {
        return [
            'curriculum_id' => ['required', 'integer', Rule::exists('curricula', 'id')->where('school_id', $request->user()->school_id)],
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }

    public function impact(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum.programme')->findOrFail($id);
        return response()->json($this->curricula->stageDeletionImpact($year->curriculum, $year));
    }

}
