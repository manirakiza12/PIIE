<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Models\ProgrammeCohort;
use App\Models\ProgrammeCohortMembership;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ProgrammeCohorts\ProgrammeCohortService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProgrammeCohortController extends Controller
{
    public function __construct(private ProgrammeCohortService $cohorts)
    {
    }

    public function index(Request $request)
    {
        $schoolId = $this->schoolContext($request);
        $query = ProgrammeCohort::query()->where('programme_cohorts.school_id', $schoolId)
            ->join('programmes', fn ($join) => $join->on('programmes.id', '=', 'programme_cohorts.programme_id')->where('programmes.school_id', $schoolId))
            ->join('intake_sessions', fn ($join) => $join->on('intake_sessions.id', '=', 'programme_cohorts.intake_session_id')->where('intake_sessions.school_id', $schoolId))
            ->join('academic_years', fn ($join) => $join->on('academic_years.id', '=', 'programme_cohorts.entry_academic_year_id')->where('academic_years.school_id', $schoolId))
            ->join('curricula', fn ($join) => $join->on('curricula.id', '=', 'programme_cohorts.curriculum_id')->on('curricula.programme_id', '=', 'programme_cohorts.programme_id')->where('curricula.school_id', $schoolId))
            ->select('programme_cohorts.*')
            ->with(['programme', 'intakeSession', 'entryAcademicYear', 'studyPlan'])
            ->withCount(['currentMemberships as members_count']);

        foreach (['programme_id', 'intake_session_id', 'entry_academic_year_id'] as $filter) {
            if ($request->filled($filter) && ctype_digit((string) $request->query($filter))) {
                $query->where("programme_cohorts.{$filter}", (int) $request->query($filter));
            }
        }
        if (in_array($request->query('status'), ProgrammeCohort::STATUSES, true)) {
            $query->where('programme_cohorts.status', $request->query('status'));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($where) => $where->where('programme_cohorts.name', 'like', "%{$search}%")
                ->orWhere('programme_cohorts.code', 'like', "%{$search}%"));
        }

        return view('admin.programme_cohorts.index', [
            'cohorts' => $query->orderByDesc('programme_cohorts.created_at')->paginate(20)->withQueryString(),
            'programmes' => Programme::where('school_id', $schoolId)->orderBy('name')->get(['id', 'code', 'name']),
            'intakes' => IntakeSession::where('school_id', $schoolId)->orderByDesc('id')->get(['id', 'name']),
            'years' => AcademicYear::where('school_id', $schoolId)->orderByDesc('start_date')->get(['id', 'label']),
        ]);
    }

    public function create(Request $request)
    {
        return redirect()->route('admin.academic.direct_placement')->with('info', 'Student groups are optional. Place each student directly in their Programme.');
    }

    public function store(Request $request)
    {
        return redirect()->route('admin.academic.direct_placement')->with('info', 'Student groups are optional. Place each student directly in their Programme.');
    }

    public function edit(Request $request, int $id)
    {
        return redirect()->route('admin.programme_cohorts.show', $id)->with('info', 'Student groups are retained for history. Use direct student placement for new students.');
    }

    public function update(Request $request, int $id)
    {
        return redirect()->route('admin.programme_cohorts.show', $id)->with('info', 'Student groups are retained for history. Use direct student placement for new students.');
    }

    public function show(Request $request, int $id)
    {
        $schoolId = $this->schoolContext($request);
        $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($id)
            ->with(['programme', 'intakeSession', 'entryAcademicYear', 'studyPlan.stages', 'creator'])
            ->withCount(['currentMemberships as members_count'])->firstOrFail();
        $cohort->setAttribute('pending_placement_count', $cohort->pendingPlacementMemberships()->count());
        $memberships = $cohort->memberships()->with(['student.studentProfile', 'assignedBy', 'admission', 'studentCurriculumAssignments'])
            ->orderByDesc('started_at')->paginate(25, ['*'], 'members_page');
        $students = User::where('school_id', $schoolId)->where('role_id', 7)
            ->whereNotExists(function ($query) use ($schoolId): void {
                $query->selectRaw('1')->from('programme_cohort_memberships as current_membership')
                    ->whereColumn('current_membership.student_id', 'users.id')
                    ->where('current_membership.school_id', $schoolId)->whereNull('current_membership.ended_at');
            })->orderBy('name')->get(['id', 'name', 'email']);
        $admissions = Admission::where('school_id', $schoolId)->where('programme_id', $cohort->programme_id)
            ->where('intake_session_id', $cohort->intake_session_id)->orderByDesc('created_at')->get(['id', 'app_number', 'first_name', 'last_name']);
        $transferCohorts = ProgrammeCohort::where('school_id', $schoolId)->where('status', 'active')->whereKeyNot($cohort->id)->orderBy('name')->get(['id', 'name', 'code']);
        $activity = DB::table('audit_logs')->where('school_id', $schoolId)->where('record_type', 'ProgrammeCohort')
            ->where('record_id', $cohort->id)->orderByDesc('created_at')->limit(20)->get();
        $activityLabels = [
            'PROGRAMME_COHORT_CREATED' => 'Programme Cohort created',
            'PROGRAMME_COHORT_UPDATED' => 'Draft details updated',
            'PROGRAMME_COHORT_ACTIVATED' => 'Programme Cohort activated',
            'PROGRAMME_COHORT_COMPLETED' => 'Programme Cohort completed',
            'PROGRAMME_COHORT_CANCELLED' => 'Programme Cohort cancelled',
        ];
        $activityDescriptions = [
            'PROGRAMME_COHORT_CREATED' => 'The draft was created.',
            'PROGRAMME_COHORT_UPDATED' => 'The draft details were updated.',
            'PROGRAMME_COHORT_ACTIVATED' => 'The cohort is now operational.',
            'PROGRAMME_COHORT_COMPLETED' => 'The cohort was completed and closed to new members.',
            'PROGRAMME_COHORT_CANCELLED' => 'The cohort was cancelled and retained in history.',
        ];
        $activity->transform(function ($entry) use ($activityLabels, $activityDescriptions, $cohort) {
            $entry->display_action = $activityLabels[$entry->action] ?? 'Programme Cohort activity';
            $entry->display_description = $activityDescriptions[$entry->action] ?? 'A change was recorded for this Programme Cohort.';

            return $entry;
        });

        return view('admin.programme_cohorts.show', compact('cohort', 'memberships', 'students', 'admissions', 'transferCohorts', 'activity'));
    }

    public function lifecycle(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'completed', 'cancelled'])]]);
        try {
            $cohort = $this->cohorts->transition($request->user(), $id, $data['status']);
        } catch (DomainException | QueryException $exception) {
            return $this->workflowFailure($exception);
        }

        return redirect()->route('admin.programme_cohorts.show', $cohort->id)->with('success', 'Programme Cohort status updated.');
    }

    public function member(Request $request, int $id)
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('school_id', (int) $request->user()->school_id)],
            'admission_id' => ['nullable', 'integer'],
        ]);
        try {
            $membership = $data['admission_id']
                ? $this->cohorts->assignFromAdmission($request->user(), $id, (int) $data['student_id'], (int) $data['admission_id'])
                : $this->cohorts->assign($request->user(), $id, (int) $data['student_id']);
        } catch (DomainException | QueryException $exception) {
            return $this->workflowFailure($exception);
        }

        return redirect()->route('admin.programme_cohorts.show', $id)->with('success', 'Student added to the Programme Cohort.');
    }

    public function membershipAction(Request $request, int $id, string $action)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'destination_cohort_id' => [$action === 'transfer' ? 'required' : 'nullable', 'integer'],
        ]);
        try {
            $membership = match ($action) {
                'defer' => $this->cohorts->defer($request->user(), $id, $data['reason'] ?? null),
                'resume' => $this->cohorts->resume($request->user(), $id),
                'transfer' => $this->cohorts->transfer($request->user(), $id, (int) $data['destination_cohort_id'], $data['reason'] ?? null),
                'withdraw' => $this->cohorts->withdraw($request->user(), $id, $data['reason'] ?? null),
                'complete' => $this->cohorts->completeMembership($request->user(), $id, $data['reason'] ?? null),
                default => abort(404),
            };
        } catch (DomainException | QueryException $exception) {
            return $this->workflowFailure($exception);
        }

        return redirect()->route('admin.programme_cohorts.show', $membership->programme_cohort_id)->with('success', 'Membership updated.');
    }

    public function placement(Request $request, int $id)
    {
        $schoolId = $this->schoolContext($request);
        $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($id)
            ->with(['programme', 'intakeSession', 'entryAcademicYear', 'studyPlan.stages'])->firstOrFail();
        abort_unless($cohort->status === 'active' && $cohort->studyPlan?->status === 'approved', 422, 'Academic placement requires an active cohort and approved Programme Study Plan.');
        $memberships = $cohort->currentMemberships()->where('status', 'active')->with('student')->orderBy('started_at')->get();

        return view('admin.programme_cohorts.placement', compact('cohort', 'memberships'));
    }

    public function place(Request $request, int $id, int $membershipId)
    {
        $data = $request->validate([
            'entry_curriculum_stage_id' => ['required', 'integer'],
            'year_of_study' => ['required', 'integer', 'between:1,255'],
        ]);
        try {
            $this->cohorts->placeStudent($request->user(), $id, $membershipId, (int) $data['entry_curriculum_stage_id'], (int) $data['year_of_study']);
        } catch (DomainException | QueryException $exception) {
            return $this->workflowFailure($exception);
        }

        return redirect()->route('admin.programme_cohorts.show', $id)->with('success', 'Student academic placement recorded.');
    }

    private function schoolContext(Request $request): int
    {
        $user = $request->user();
        $schoolId = (int) $user->school_id;
        abort_if($schoolId < 1, 403);
        $type = DB::table('schools')->where('id', $schoolId)->value('school_type');
        abort_if($type === 'k12', 404);

        return $schoolId;
    }

    private function plans(int $schoolId, ?int $programmeId)
    {
        return Curriculum::where('school_id', $schoolId)->when($programmeId, fn ($q) => $q->where('programme_id', $programmeId))
            ->orderBy('programme_id')->orderByDesc('created_at')->get(['id', 'programme_id', 'version', 'status']);
    }

    private function workflowFailure(DomainException|QueryException $exception)
    {
        if ($exception instanceof QueryException) {
            $technical = $exception->getMessage();
            $message = str_contains($technical, 'pcm_active_student_uq')
                ? 'This student already belongs to a current Programme Cohort.'
                : (str_contains($technical, 'pc_school_code_uq')
                    ? 'That Cohort Code is already in use. Choose a different code.'
                    : 'We couldn’t save that change because a related record conflicts. Review the selections and try again.');
        } else {
            $message = $exception->getMessage();
        }

        return redirect()->back()->withInput()->with('error', $message);
    }
}
