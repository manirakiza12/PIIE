<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\OnlineExamSubmission;
use App\Support\Permissions\OnlineExamPermissionService;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AcademicWorkspaceController extends Controller
{
    private const WORKSPACE_PERMISSIONS = [
        'academic.structure', 'academic.structure.manage', 'academic.departments', 'academic.programmes',
        'academic.curriculum.view', 'academic.curriculum.manage', 'academic.course_offering.view',
        'academic.course_registration.view', 'academic.routine', 'academic.attendance', 'academic.gradebook',
        'academic.assignments', 'live_classes.view', 'online_exams.view',
    ];

    public function index(Request $request, PermissionService $permissions): View
    {
        $user = $request->user();
        $decodedLegacyRoutes = json_decode((string) $user->menu_permission, true);
        $legacyRoutes = is_array($decodedLegacyRoutes) ? $decodedLegacyRoutes : [];
        $legacyAcademicRoutes = [
            'admin.department_list', 'admin.programmes.index', 'admin.academic_structure.index',
            'admin.settings.academic', 'admin.curricula.index', 'admin.programme_years.index',
            'admin.subject_list', 'admin.curriculum_units.index', 'admin.course_offerings.index',
            'admin.lecturer_assignments.index', 'admin.course_registrations.index', 'admin.routine',
            'admin.daily_attendance', 'admin.live_classes.index', 'admin.assignments.index',
            'admin.question_bank.index', 'admin.online_exams', 'admin.online_exams.index', 'admin.gradebook', 'admin.reports.index',
        ];
        $legacyAllowsRoutes = fn (array $routes): bool => empty($user->menu_permission)
            || $user->menu_permission === 'null'
            || (bool) array_intersect($routes, $legacyRoutes);
        $legacyAllows = $legacyAllowsRoutes($legacyAcademicRoutes);
        abort_unless($legacyAllows && $permissions->allowsAny($user, self::WORKSPACE_PERMISSIONS), 403);

        $schoolId = (int) $user->school_id;
        abort_unless($schoolId > 0 && DB::table('schools')->where('id', $schoolId)->exists(), 403);
        $configuredCalendarPattern = Schema::hasColumn('schools', 'academic_calendar_pattern')
            ? DB::table('schools')->where('id', $schoolId)->value('academic_calendar_pattern')
            : null;
        $schoolType = Schema::hasColumn('schools', 'school_type')
            ? strtolower((string) DB::table('schools')->where('id', $schoolId)->value('school_type'))
            : null;
        $defaultCalendarPattern = $schoolType
            ? ($schoolType === 'k12' ? 'term' : 'semester')
            : (academic_education_level($schoolId) === 'secondary' ? 'term' : 'semester');
        $calendarUnitLabel = ucfirst((string) ($configuredCalendarPattern ?: $defaultCalendarPattern));
        $usesClassBasedSessions = $schoolType !== null
            ? $schoolType !== 'higher_ed'
            : academic_education_level($schoolId) !== 'tertiary';
        $canStructure = $legacyAllowsRoutes(['admin.academic_structure.index'])
            && $permissions->allows($user, 'academic.structure.manage');
        $canCurriculum = $legacyAllowsRoutes(['admin.curricula.index', 'admin.programme_years.index'])
            && $permissions->allowsAny($user, ['academic.curriculum.view', 'academic.curriculum.manage']);
        $canOfferings = $legacyAllowsRoutes(['admin.course_offerings.index']) && $permissions->allows($user, 'academic.course_offering.view');
        $canRegistrations = $legacyAllowsRoutes(['admin.course_registrations.index']) && $permissions->allows($user, 'academic.course_registration.view');
        $examPermissions = app(OnlineExamPermissionService::class);
        $canViewOnlineExams = $legacyAllowsRoutes(['admin.online_exams', 'admin.online_exams.index'])
            && $examPermissions->has($user, 'view_online_exams');
        $hasAcademicCalendar = Schema::hasTable('academic_years') && Schema::hasColumn('academic_years', 'school_id')
            && Schema::hasColumn('academic_years', 'start_date') && Schema::hasColumn('academic_years', 'status')
            && Schema::hasTable('academic_periods') && Schema::hasColumn('academic_periods', 'school_id')
            && Schema::hasColumn('academic_periods', 'academic_year_id') && Schema::hasColumn('academic_periods', 'sequence')
            && Schema::hasColumn('academic_periods', 'status');
        $hasOfferings = Schema::hasTable('course_offerings') && Schema::hasColumn('course_offerings', 'id')
            && Schema::hasColumn('course_offerings', 'school_id') && Schema::hasColumn('course_offerings', 'academic_year_id')
            && Schema::hasColumn('course_offerings', 'academic_period_id') && Schema::hasColumn('course_offerings', 'status');
        $hasLecturerAllocations = Schema::hasTable('course_offering_lecturer_allocations')
            && Schema::hasColumn('course_offering_lecturer_allocations', 'course_offering_id')
            && Schema::hasColumn('course_offering_lecturer_allocations', 'school_id')
            && Schema::hasColumn('course_offering_lecturer_allocations', 'status');
        $hasRegistrations = Schema::hasTable('course_registrations') && Schema::hasColumn('course_registrations', 'course_offering_id')
            && Schema::hasColumn('course_registrations', 'school_id') && Schema::hasColumn('course_registrations', 'status');
        $hasCurricula = Schema::hasTable('curricula') && Schema::hasColumn('curricula', 'school_id')
            && Schema::hasColumn('curricula', 'status') && Schema::hasColumn('curricula', 'effective_academic_year_id');
        $hasOnlineExamSchedules = Schema::hasTable('online_exams')
            && Schema::hasColumn('online_exams', 'school_id') && Schema::hasColumn('online_exams', 'start_datetime');
        $hasOnlineExamWorkflowState = $hasOnlineExamSchedules && Schema::hasColumn('online_exams', 'workflow_state');
        $hasOnlineExamPublicationFlag = $hasOnlineExamSchedules && Schema::hasColumn('online_exams', 'is_published');
        $hasOnlineExamReviewQueue = $hasOnlineExamSchedules && Schema::hasTable('online_exam_submissions')
            && Schema::hasColumn('online_exam_submissions', 'school_id')
            && Schema::hasColumn('online_exam_submissions', 'status')
            && Schema::hasColumn('online_exam_submissions', 'result_review_state')
            && Schema::hasColumn('online_exam_submissions', 'submitted_at');
        $hasAttendanceQueue = Schema::hasTable('course_offering_attendance_sessions')
            && Schema::hasColumn('course_offering_attendance_sessions', 'school_id')
            && Schema::hasColumn('course_offering_attendance_sessions', 'course_offering_id')
            && Schema::hasColumn('course_offering_attendance_sessions', 'session_date')
            && Schema::hasColumn('course_offering_attendance_sessions', 'status');
        $canStructure = $canStructure && $hasAcademicCalendar;
        $canCurriculum = $canCurriculum && $hasCurricula;
        $canOfferings = $canOfferings && $hasAcademicCalendar && $hasOfferings;
        $canRegistrations = $canRegistrations && $hasRegistrations && $hasOfferings && $hasAcademicCalendar;
        $hasTimetableContext = Schema::hasTable('routines') && Schema::hasColumn('routines', 'school_id')
            && Schema::hasColumn('routines', 'session_id') && Schema::hasTable('sessions')
            && Schema::hasColumn('sessions', 'school_id') && Schema::hasColumn('schools', 'running_session');
        $canTimetable = $legacyAllowsRoutes(['admin.routine']) && $permissions->allows($user, 'academic.routine') && $hasTimetableContext;
        $canManageSessions = $legacyAllowsRoutes(['admin.settings.session_manager'])
            && $permissions->allows($user, 'academic.sessions');
        $canSelectAcademicContext = $canStructure || $canCurriculum || $canOfferings;
        $configuredYearId = Schema::hasColumn('schools', 'current_academic_year_id')
            ? DB::table('schools')->where('id', $schoolId)->value('current_academic_year_id')
            : null;
        $configuredPeriodId = Schema::hasColumn('schools', 'current_academic_period_id')
            ? DB::table('schools')->where('id', $schoolId)->value('current_academic_period_id')
            : null;

        $years = $canSelectAcademicContext
            ? AcademicYear::query()->where('school_id', $schoolId)->orderByDesc('start_date')->get()
            : collect();
        $year = $years->firstWhere('id', (int) $request->query('year'))
            ?? $years->firstWhere('id', (int) $configuredYearId)
            ?? $years->firstWhere('status', 'active')
            ?? $years->first();

        $periods = $year && $canSelectAcademicContext
            ? AcademicPeriod::query()->where('school_id', $schoolId)->where('academic_year_id', $year->id)->orderBy('sequence')->get()
            : collect();
        $period = $periods->firstWhere('id', (int) $request->query('period'))
            ?? $periods->firstWhere('id', (int) $configuredPeriodId)
            ?? $periods->firstWhere('status', 'active')
            ?? $periods->first();
        $calendarUnitLabel = ucfirst((string) ($period?->type ?? strtolower($calendarUnitLabel)));

        $offerings = CourseOffering::query()->where('school_id', $schoolId);
        if ($year) $offerings->where('academic_year_id', $year->id);
        if ($period) $offerings->where('academic_period_id', $period->id);
        $offerings = $offerings->whereNotIn('status', [CourseOffering::STATUS_CANCELLED, CourseOffering::STATUS_COMPLETED]);

        $offeringCount = $canOfferings ? (clone $offerings)->count() : null;
        $withoutLecturer = null;
        $registrationCount = null;
        $confirmedRegistrationCount = null;
        $awaitingConfirmationCount = null;
        $otherRegistrationStatusCount = null;
        if ($canOfferings && $hasLecturerAllocations) {
            $withoutLecturer = (clone $offerings)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('course_offering_lecturer_allocations as lecturer_allocations')
                        ->whereColumn('lecturer_allocations.course_offering_id', 'course_offerings.id')
                        ->whereColumn('lecturer_allocations.school_id', 'course_offerings.school_id')
                        ->whereIn('lecturer_allocations.status', ['planned', 'active']);
                })->count();
        }
        if ($canRegistrations && $year && $period) {
            $registrationQuery = DB::table('course_registrations')->where('school_id', $schoolId)
                ->where('status', '!=', CourseRegistration::STATUS_DROPPED);
            $registrationQuery->whereIn('course_offering_id', (clone $offerings)->select('id'));
            $registrationStatuses = (clone $registrationQuery)->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')->get()->keyBy('status');
            $registrationCount = (int) $registrationStatuses->sum('total');
            $confirmedRegistrationCount = (int) ($registrationStatuses->get(CourseRegistration::STATUS_CONFIRMED)->total ?? 0);
            $awaitingConfirmationCount = (int) ($registrationStatuses->get(CourseRegistration::STATUS_REGISTERED)->total ?? 0);
            $otherRegistrationStatusCount = max(0, $registrationCount - $confirmedRegistrationCount - $awaitingConfirmationCount);
        }

        $curriculumCount = $canCurriculum && $hasCurricula
            ? DB::table('curricula')->where('school_id', $schoolId)->where('status', 'approved')
                ->where(fn ($query) => $query->whereNull('effective_academic_year_id')->orWhere('effective_academic_year_id', $year?->id ?? 0))->count()
            : null;
        $timetableEntries = null;
        $activeTimetableSession = null;
        if ($canTimetable) {
            $activeSessionId = DB::table('schools')->where('id', $schoolId)->value('running_session');
            if ($activeSessionId) {
                $activeTimetableSession = DB::table('sessions')->where('id', $activeSessionId)
                    ->where('school_id', $schoolId)->first(['id', 'session_title']);
            }
            if ($activeTimetableSession) {
                $timetableEntries = DB::table('routines')->where('school_id', $schoolId)
                    ->where('session_id', $activeTimetableSession->id)->count();
            }
        }

        $upcomingOnlineExams = null;
        if ($hasOnlineExamSchedules && $canViewOnlineExams && ($hasOnlineExamWorkflowState || $hasOnlineExamPublicationFlag)) {
            $upcomingQuery = DB::table('online_exams')->where('school_id', $schoolId)->where('start_datetime', '>=', now());
            if ($hasOnlineExamWorkflowState) {
                $upcomingQuery->where('workflow_state', 'published');
            } elseif ($hasOnlineExamPublicationFlag) {
                $upcomingQuery->where('is_published', 1);
            }
            $upcomingOnlineExams = $upcomingQuery->count();
        }

        $pendingAttendanceSessions = null;
        $pendingAttendanceOfferingId = null;
        if ($hasAttendanceQueue && $canOfferings && $year && $period) {
            $attendanceOfferingIds = CourseOffering::query()->where('school_id', $schoolId)
                ->where('academic_year_id', $year->id)->where('academic_period_id', $period->id)
                ->where('status', '!=', CourseOffering::STATUS_CANCELLED)->select('id');
            $attendanceQuery = DB::table('course_offering_attendance_sessions')
                ->where('school_id', $schoolId)->where('status', 'draft')
                ->whereDate('session_date', '<=', now()->toDateString())
                ->whereIn('course_offering_id', $attendanceOfferingIds);
            $pendingAttendanceSessions = (clone $attendanceQuery)->count();
            $pendingAttendanceOfferingId = $attendanceQuery->orderBy('session_date')->value('course_offering_id');
        }

        $pendingResultReviews = null;
        $canReviewResults = $legacyAllowsRoutes(['admin.online_exams', 'admin.online_exams.index'])
            && ($examPermissions->has($user, 'mark_exam_answers') || $examPermissions->has($user, 'manage_exam_results'));
        if ($hasOnlineExamReviewQueue && $canReviewResults) {
            $pendingResultReviews = DB::table('online_exam_submissions')->where('school_id', $schoolId)
                ->where('status', OnlineExamSubmission::STATUS_FINALIZED)
                ->where('result_review_state', 'pending_review')->whereNotNull('submitted_at')->count();
        }

        $steps = [];
        if ($canStructure) {
            $steps[] = [
                'label' => 'Choose an academic year', 'state' => $years->isNotEmpty() ? 'Ready' : 'Action required',
                'detail' => $years->isNotEmpty() ? $year->label.' is selected. Existing years can be reused.' : 'Create the institution’s academic year before adding a teaching period.',
                'route' => 'admin.academic_structure.index', 'parameters' => [],
            ];
            $steps[] = [
                'label' => 'Choose a semester or term', 'state' => ! $year ? 'Blocked by a prerequisite' : ($periods->isNotEmpty() ? 'Ready' : 'Action required'),
                'detail' => ! $year ? 'Select or create an academic year first.' : ($periods->isNotEmpty() ? $period->label.' is selected.' : 'Add a teaching period to the selected year.'),
                'route' => 'admin.academic_structure.index', 'parameters' => ['section' => 'semesters'],
            ];
        }
        if ($canCurriculum && $curriculumCount !== null) {
            $steps[] = [
                'label' => 'Review programme curriculum', 'state' => $curriculumCount > 0 ? 'Ready' : 'Action required',
                'detail' => $curriculumCount > 0 ? $curriculumCount.' approved curriculum version(s) are available to reuse.' : 'No approved curriculum is available yet; draft or approve one if this period needs it.',
                'route' => 'admin.curricula.index', 'parameters' => [],
            ];
        }
        if ($canOfferings) {
            $allocationBlocked = ! $year || ! $period;
            $steps[] = [
                'label' => 'Review course allocations and lecturers', 'state' => $allocationBlocked ? 'Blocked by a prerequisite' : ($offeringCount === 0 ? 'Action required' : ($withoutLecturer === null ? 'In progress' : ($withoutLecturer === 0 ? 'Ready' : 'Action required'))),
                'detail' => ! $year ? 'Choose an academic year first.' : (! $period ? 'Choose a semester or term first.' : ($offeringCount === 0 ? 'No course allocations exist for the selected period.' : ($withoutLecturer === null ? 'Lecturer assignment status is unavailable because the allocation history table is missing.' : ($withoutLecturer > 0 ? $withoutLecturer.' allocation(s) have no active or planned lecturer.' : 'All current allocations have a lecturer record.')))),
                'route' => 'admin.course_offerings.index', 'parameters' => array_filter(['year_id' => $year?->id, 'period_id' => $period?->id]),
            ];
        }
        if ($canRegistrations && (! $year || ! $period || ($canOfferings && $offeringCount === 0))) {
            $registrationBlockedByAllocations = $year && $period && $canOfferings && $offeringCount === 0;
            $steps[] = [
                'label' => 'Review student course registrations', 'state' => 'Blocked by a prerequisite',
                'detail' => ! $year ? 'Choose an academic year first.' : (! $period ? 'Choose a semester or term first.' : 'Create or reuse course allocations for this period before reviewing student registrations.'),
                'route' => $registrationBlockedByAllocations ? 'admin.course_offerings.index' : 'admin.course_registrations.index',
                'parameters' => $registrationBlockedByAllocations
                    ? array_filter(['year_id' => $year?->id, 'period_id' => $period?->id])
                    : array_filter(['academic_year_id' => $year?->id, 'period_id' => $period?->id]),
            ];
        } elseif ($canRegistrations && $registrationCount !== null) {
            $steps[] = [
                'label' => 'Review student course registrations', 'state' => $registrationCount > 0 ? 'In progress' : 'Action required',
                'detail' => $registrationCount > 0 ? $registrationCount.' non-dropped registration(s) are recorded. Review eligible students and confirmation status in the registration screen.' : 'No non-dropped registrations are recorded for the selected period yet.',
                'route' => 'admin.course_registrations.index', 'parameters' => array_filter(['academic_year_id' => $year?->id, 'period_id' => $period?->id]),
            ];
        }
        if ($canOfferings && $hasAttendanceQueue) {
            $attendanceState = ! $year || ! $period ? 'Blocked by a prerequisite' : (($pendingAttendanceSessions ?? 0) > 0 ? 'Action required' : 'Ready');
            $attendanceDetail = ! $year ? 'Choose an academic year first.' : (! $period ? 'Choose a semester or term first.' : (($pendingAttendanceSessions ?? 0) > 0 ? $pendingAttendanceSessions.' past attendance session(s) remain in draft. Open the allocation to identify its teaching team.' : 'No past draft attendance sessions are recorded for this period.'));
            $steps[] = ['label' => 'Review attendance completion', 'state' => $attendanceState, 'detail' => $attendanceDetail,
                'route' => $pendingAttendanceOfferingId ? 'admin.course_offerings.show' : 'admin.course_offerings.index',
                'parameters' => $pendingAttendanceOfferingId ?: array_filter(['year_id' => $year?->id, 'period_id' => $period?->id])];
        }
        if ($canReviewResults && $pendingResultReviews !== null) {
            $steps[] = ['label' => 'Review exam results', 'state' => $pendingResultReviews > 0 ? 'Action required' : 'Ready',
                'detail' => $pendingResultReviews > 0 ? $pendingResultReviews.' finalized result(s) await an authorized decision.' : 'No finalized results are waiting for review.',
                'route' => 'admin.online_exams.result_review_queue', 'parameters' => []];
        }
        if ($canTimetable) {
            $timetableState = ! $activeTimetableSession
                ? 'Blocked by a prerequisite'
                : ($timetableEntries > 0 ? 'In progress' : 'Action required');
            $timetableRoute = ! $activeTimetableSession && $canManageSessions
                ? 'admin.settings.session_manager'
                : 'admin.routine';
            $steps[] = [
                'label' => 'Prepare the timetable', 'state' => $timetableState,
                'detail' => ! $activeTimetableSession
                    ? 'Set a running academic session before reviewing its timetable.'
                    : ($timetableEntries > 0
                        ? $timetableEntries.' timetable entries are saved for the active academic session. Review the saved schedule in the timetable screen.'
                        : 'No timetable entries are saved for the active academic session yet.'),
                'route' => $timetableRoute, 'parameters' => [],
            ];
        }

        return view('admin.academic.workspace', [
            'year' => $year, 'period' => $period, 'years' => $years, 'periods' => $periods, 'steps' => $steps,
            'workflow' => $request->query('mode') === 'prepare',
            'canStructure' => $canStructure, 'canOfferings' => $canOfferings, 'canRegistrations' => $canRegistrations,
            'canSelectAcademicContext' => $canSelectAcademicContext,
            'activeTimetableSession' => $activeTimetableSession,
            'canManageSessions' => $canManageSessions,
            'usesClassBasedSessions' => $usesClassBasedSessions,
            'calendarUnitLabel' => $calendarUnitLabel,
            'offeringCount' => $offeringCount, 'withoutLecturer' => $withoutLecturer, 'registrationCount' => $registrationCount,
            'confirmedRegistrationCount' => $confirmedRegistrationCount, 'awaitingConfirmationCount' => $awaitingConfirmationCount,
            'otherRegistrationStatusCount' => $otherRegistrationStatusCount,
            'timetableEntries' => $timetableEntries,
            'pendingAttendanceSessions' => $pendingAttendanceSessions, 'pendingAttendanceOfferingId' => $pendingAttendanceOfferingId,
            'upcomingOnlineExams' => $upcomingOnlineExams, 'pendingResultReviews' => $pendingResultReviews,
        ]);
    }
}
