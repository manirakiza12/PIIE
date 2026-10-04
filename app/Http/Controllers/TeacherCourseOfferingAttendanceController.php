<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\CourseOfferingAttendanceSession;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceService;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionStatus;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceSessionType;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceStatus;
use App\Support\TenantConfiguration;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lecturer attendance for a Course Offering.
 *
 * Every action resolves the offering through LecturerCourseOfferingAccess
 * first, so a changed URL id reaches nothing. A session is then re-checked
 * against that offering and the lecturer's institution, so a session belonging
 * to another lecturer's offering cannot be marked through a borrowed URL.
 *
 * The roster is the offering's confirmed course registrations, resolved by the
 * attendance service. Nothing here can register, confirm or withdraw a student.
 */
class TeacherCourseOfferingAttendanceController extends Controller
{
    public function __construct(
        private readonly LecturerCourseOfferingAccess $access,
        private readonly CourseOfferingAttendanceService $attendance,
    ) {
    }

    public function index(Request $request, int $id): View
    {
        [$offering, $terms, $canTeach] = $this->resolve($request, $id);

        return view('teacher.course_offerings.attendance.index', [
            'offering' => $offering,
            'terms' => $terms,
            'canTeach' => $canTeach,
            'sessions' => $this->attendance->sessionsForOffering($offering),
            'history' => $this->attendance->historyForOffering($offering),
        ]);
    }

    public function create(Request $request, int $id)
    {
        [$offering, $terms, $canTeach] = $this->resolve($request, $id);
        if (! $canTeach) {
            return redirect()->route('teacher.course_offerings.attendance.index', $offering->id)
                ->withErrors(['attendance' => 'Attendance can be recorded once this Course Offering is in progress and you hold a current teaching allocation.']);
        }

        return view('teacher.course_offerings.attendance.create', [
            'offering' => $offering,
            'terms' => $terms,
            'types' => CourseOfferingAttendanceSessionType::LABELS,
        ]);
    }

    public function store(Request $request, int $id)
    {
        $offering = $this->offering($request, $id);

        $created = null;
        try {
            $data = $request->validate([
                'session_date' => ['required', 'date'],
                'starts_at' => ['nullable', 'date_format:H:i'],
                'ends_at' => ['nullable', 'date_format:H:i'],
                'type' => ['required', Rule::in(array_keys(CourseOfferingAttendanceSessionType::LABELS))],
                'topic' => ['nullable', 'string', 'max:255'],
                'live_class_id' => ['nullable', 'integer', 'min:1'],
            ], [
                'starts_at.date_format' => 'The start time must be a valid HH:MM time.',
                'ends_at.date_format' => 'The end time must be a valid HH:MM time.',
                'type.in' => 'Choose a valid Attendance Session type.',
            ]);

            $created = $this->attendance->createSession(
                $request->user(),
                $offering->id,
                $data['session_date'],
                $data['starts_at'] ?? null,
                $data['ends_at'] ?? null,
                $data['type'],
                $data['topic'] ?? null,
                isset($data['live_class_id']) ? (int) $data['live_class_id'] : null,
            );
        } catch (ValidationException $exception) {
            // Validation failure must land on THIS form too, with the typed
            // values preserved, rather than on url()->previous() - which is the
            // generic Course Offering page when the lecturer arrived from there,
            // and that page renders no validation errors at all.
            return redirect()
                ->route('teacher.course_offerings.attendance.create', $offering->id)
                ->withInput($request->except('live_class_id'))
                ->withErrors($exception->errors());
        } catch (DomainException $exception) {
            return $this->backToForm($offering, $request, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->backToForm(
                $offering,
                $request,
                'The Attendance Session could not be created. Please try again, or contact support if this continues.'
            );
        }

        // Land on the new session's register, which is where the confirmed
        // roster is actually marked - not the generic Course Offering page.
        return redirect()
            ->route('teacher.course_offerings.attendance.show', [$offering->id, $created->id])
            ->with('success', 'Attendance session created. Mark the attendance for this session below.');
    }

    /**
     * A refused submission must go back to THIS form, never to whatever page the
     * lecturer happened to arrive from. back() silently dropped the error on a
     * page that does not render validation errors, which is how a genuine
     * refusal looked like a successful submission.
     */
    private function backToForm(CourseOffering $offering, Request $request, string $message)
    {
        return redirect()
            ->route('teacher.course_offerings.attendance.create', $offering->id)
            ->withInput($request->except('live_class_id'))
            ->withErrors(['session' => $message]);
    }

    public function show(Request $request, int $id, int $session): View
    {
        [$offering, $terms, $canTeach] = $this->resolve($request, $id);
        $attendanceSession = $this->session($request, $offering, $session);

        return view('teacher.course_offerings.attendance.register', [
            'offering' => $offering,
            'terms' => $terms,
            'canTeach' => $canTeach,
            'session' => $attendanceSession,
            'roster' => $this->attendance->rosterFor($attendanceSession),
            'summary' => $this->attendance->summaryForSession($attendanceSession),
            'evidence' => $this->attendance->liveClassEvidence($attendanceSession),
            'statuses' => CourseOfferingAttendanceStatus::LABELS,
        ]);
    }

    public function mark(Request $request, int $id, int $session)
    {
        $offering = $this->offering($request, $id);
        $attendanceSession = $this->session($request, $offering, $session);

        // A row left on "Not marked" submits an empty status. It is dropped here
        // so a partially completed register saves cleanly, and an unmarked
        // student is never silently turned into Absent.
        $marks = collect((array) $request->input('marks', []))
            ->filter(fn ($mark) => is_array($mark)
                && array_key_exists('status', $mark)
                && $mark['status'] !== ''
                && $mark['status'] !== null)
            ->values()
            ->all();

        if ($marks === []) {
            return back()
                ->withInput()
                ->withErrors(['attendance' => 'Choose a status for at least one student before saving.']);
        }

        // Validate the FILTERED set, not the raw request: the raw payload still
        // contains the "Not marked" empty statuses, which must not fail the
        // whole register when the lecturer deliberately left those rows blank.
        $data = Validator::make(['marks' => $marks], [
            'marks' => ['required', 'array'],
            'marks.*.course_registration_id' => ['required', 'integer', 'min:1'],
            'marks.*.status' => ['required', 'integer', Rule::in(CourseOfferingAttendanceStatus::ALL)],
        ], [
            'marks.required' => 'Mark at least one student before saving.',
            'marks.*.status.in' => 'Choose a valid attendance status.',
        ]);

        if ($data->fails()) {
            return back()
                ->withInput()
                ->withErrors($data->errors());
        }

        // The roster identity is course_registrations.id, and the browser's copy
        // is not trusted: every submitted id must belong to THIS Offering, in
        // this tenant, confirmed, and actually present on this register. The
        // array key is deliberately ignored.
        $rosterIds = $this->attendance->rosterFor($attendanceSession)->pluck('id')
            ->map(fn ($value) => (int) $value)->all();

        foreach ($marks as $index => $mark) {
            $registrationId = (int) ($mark['course_registration_id'] ?? 0);
            if (! in_array($registrationId, $rosterIds, true)) {
                return back()
                    ->withInput()
                    ->withErrors(['attendance' => 'One of the selected students is not a confirmed registration on this register. Nothing was saved.']);
            }
            $marks[$index]['course_registration_id'] = $registrationId;
        }

        try {
            $result = $this->attendance->markBulk($request->user(), $attendanceSession, $marks);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['attendance' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['attendance' => 'Attendance could not be saved. Please try again.']);
        }

        $unmarked = count($rosterIds) - $result['applied'];

        return redirect()->route('teacher.course_offerings.attendance.show', [$offering->id, $attendanceSession->id])
            ->with('success', 'Attendance saved for '.$result['applied'].' '.\Illuminate\Support\Str::plural('student', $result['applied'])
                .($unmarked > 0
                    ? '. '.$unmarked.' '.\Illuminate\Support\Str::plural('student', $unmarked).' still not marked.'
                    : ''));
    }

    public function finalise(Request $request, int $id, int $session)
    {
        $offering = $this->offering($request, $id);
        $attendanceSession = $this->session($request, $offering, $session);

        try {
            $this->attendance->finalise($request->user(), $attendanceSession);
        } catch (DomainException $exception) {
            return back()->withErrors(['attendance' => $exception->getMessage()]);
        }

        return redirect()->route('teacher.course_offerings.attendance.index', $offering->id)
            ->with('success', 'Attendance Session finalised.');
    }

    // ------------------------------------------------------------ internals

    private function offering(Request $request, int $id): CourseOffering
    {
        $offering = $this->access->resolveForLecturer($request->user(), $id);
        abort_if($offering === null, 404);

        return $offering;
    }

    /** @return array{0: CourseOffering, 1: array, 2: bool} */
    private function resolve(Request $request, int $id): array
    {
        $offering = $this->offering($request, $id);

        return [
            $offering,
            app(TenantConfiguration::class)->terminology($request->user()->school),
            $this->access->teachingActionsAllowed($offering),
        ];
    }

    /**
     * A session is only ever reachable through its own offering, in the
     * lecturer's own institution. This is the IDOR boundary for attendance.
     */
    private function session(Request $request, CourseOffering $offering, int $sessionId): CourseOfferingAttendanceSession
    {
        $attendanceSession = CourseOfferingAttendanceSession::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($sessionId)
            ->first();
        abort_if($attendanceSession === null, 404);

        return $attendanceSession;
    }
}
