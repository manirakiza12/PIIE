<?php

namespace App\Http\Controllers;

use App\Support\Curriculum\{CurriculumAssignmentProposals, CurriculumProposalBlocked};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CurriculumAssignmentProposalController extends Controller
{
    public function index(Request $request)
    {
        $school = (int) $request->user()->school_id;
        $mappings = DB::table('curriculum_applicability_mappings')->where('school_id', $school)->orderByDesc('id')->paginate(15, ['*'], 'mappings_page')->withQueryString();
        $proposals = DB::table('curriculum_assignment_proposals as p')
            ->join('users as u', fn ($q) => $q->on('u.id', '=', 'p.student_id')->where('u.school_id', $school))
            ->where('p.school_id', $school)->select('p.*', 'u.name as student_name', 'u.code as student_code')
            ->orderByDesc('p.id')->paginate(15, ['*'], 'proposals_page')->withQueryString();
        $options = [];
        foreach (['programmes', 'intake_sessions', 'curricula', 'academic_years', 'curriculum_stages'] as $table) {
            $options[$table] = DB::table($table)->where('school_id', $school)->orderBy('id')->get();
        }
        $exceptions = DB::table('automation_exceptions')->where('school_id', $school)->whereIn('workflow', ['curriculum_assignment', 'curriculum_mapping'])->whereNull('resolved_at')->orderByDesc('last_seen_at')->paginate(15, ['*'], 'exceptions_page')->withQueryString();
        $admissions = DB::table('admissions as a')->join('users as u', fn ($q) => $q->on('u.id', '=', 'a.automation_student_id')->where('u.school_id', $school))
            ->where('a.school_id', $school)->whereIn('a.status', ['accepted', 'enrolled'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s->where('u.name', 'like', '%'.$request->string('search').'%')->orWhere('u.code', 'like', '%'.$request->string('search').'%')->orWhere('a.app_number', 'like', '%'.$request->string('search').'%')))
            ->select('a.id', 'a.app_number', 'u.name', 'u.code')->orderBy('a.id')->paginate(15, ['*'], 'admissions_page')->withQueryString();
        return view('admin.academic.curriculum_assignment_proposals', compact('mappings', 'proposals', 'options', 'exceptions', 'admissions'));
    }

    public function storeMapping(Request $request, CurriculumAssignmentProposals $service)
    {
        $data = $request->validate([
            'programme_id' => 'required|integer|min:1', 'intake_session_id' => 'required|integer|min:1',
            'curriculum_id' => 'required|integer|min:1', 'entry_academic_year_id' => 'required|integer|min:1',
            'entry_curriculum_stage_id' => 'required|integer|min:1', 'reason' => 'required|string|max:2000',
        ]);
        return $this->perform(fn () => $service->createMapping($request->user(), $data), 'Draft mapping saved. It requires explicit Registrar approval.');
    }

    public function approveMapping(Request $request, int $id, CurriculumAssignmentProposals $service)
    {
        return $this->perform(fn () => $service->approveMapping($request->user(), $id), 'Applicability mapping approved. No placement has been created.');
    }

    public function revokeMapping(Request $request, int $id, CurriculumAssignmentProposals $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        return $this->perform(fn () => $service->revokeMapping($request->user(), $id, $data['reason']), 'Mapping revoked. Existing academic records are preserved.');
    }

    public function propose(Request $request, CurriculumAssignmentProposals $service)
    {
        $data = $request->validate(['admission_id' => 'required|integer|min:1', 'ordinary_entry_evidence' => 'required|string|max:2000']);
        return $this->perform(fn () => $service->propose($request->user(), $data['admission_id'], $data['ordinary_entry_evidence']), 'Proposal recorded or existing decision retained. Registrar review is required.');
    }

    public function review(Request $request, int $id, CurriculumAssignmentProposals $service)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|max:2000', 'acknowledge_enrolment' => 'nullable|boolean']);
        return $this->perform(fn () => $service->review($request->user(), $id, $data['decision'] === 'approve', $data['reason'], $request->boolean('acknowledge_enrolment')), 'Registrar review saved. Approval alone does not apply placement.');
    }

    public function apply(Request $request, int $id, CurriculumAssignmentProposals $service)
    {
        $request->validate(['acknowledge_enrolment' => 'required|accepted']);
        return $this->perform(fn () => $service->apply($request->user(), $id, $request->boolean('acknowledge_enrolment')), 'Approved placement applied. Existing automatic enrolment may subsequently process eligible courses.');
    }

    private function perform(callable $action, string $message)
    {
        try { $action(); }
        catch (CurriculumProposalBlocked $e) { return back()->withInput()->withErrors(['curriculum_assignment' => $e->getMessage()]); }
        return redirect()->route('admin.curriculum_assignment.index')->with('success', $message);
    }
}
