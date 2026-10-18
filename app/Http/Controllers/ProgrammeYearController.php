<?php

namespace App\Http\Controllers;

use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Support\Curriculum\CurriculumFoundationService;
use Illuminate\Http\Request;
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
        $this->curricula->addStage($curriculum, $data['label'], (int) $data['sequence']);
        $request->session()->forget('errors');
        return back()->with('success', 'Programme Year created.');
    }

    public function update(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        $data = $request->validate(['label' => ['required', 'string', 'max:100'], 'sequence' => ['required', 'integer', 'min:1', 'max:65535']]);
        $this->curricula->updateStage($year->curriculum, $year, $data['label'], (int) $data['sequence']);
        return back()->with('success', 'Programme Year updated.');
    }

    public function destroy(Request $request, int $id)
    {
        $year = CurriculumStage::where('school_id', $request->user()->school_id)->with('curriculum')->findOrFail($id);
        $this->curricula->removeStage($year->curriculum, $year);
        return back()->with('success', 'Programme Year deleted.');
    }

    private function rules(Request $request): array
    {
        return [
            'curriculum_id' => ['required', 'integer', Rule::exists('curricula', 'id')->where('school_id', $request->user()->school_id)->where('status', 'draft')],
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ];
    }
}
