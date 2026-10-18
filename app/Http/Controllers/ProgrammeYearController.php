<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Support\Curriculum\CurriculumFoundationService;
use App\Support\Curriculum\CurriculumOwnerAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        return view('admin.programme_years.show', compact('year'));
    }

    public function update(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'sequence' => ['required', 'integer', 'min:1', 'max:65535']]);
        $this->mutate($request, $year->curriculum, fn () => $this->curricula->updateStage($year->curriculum, $year, $data['label'], (int) $data['sequence']));
        return back()->with('success', 'Programme Year updated.');
    }

    public function destroy(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        $this->mutate($request, $year->curriculum, fn () => $this->curricula->removeStage($year->curriculum, $year));
        return back()->with('success', 'Programme Year deleted.');
    }

    private function mutate(Request $request, Curriculum $curriculum, callable $mutation): void
    {
        DB::transaction(function () use ($request, $curriculum, $mutation): void {
            $locked = Curriculum::where('school_id', $curriculum->school_id)->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' && CurriculumOwnerAccess::allows($request->user(), (int) $locked->school_id)) {
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
}
