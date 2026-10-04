<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonController;
use App\Models\Appraisal;
use App\Models\AuditLog;
use App\Models\Appraisal_submit;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\Chat;
use App\Models\Classes;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubNotice;
use App\Models\CourseRegistration;
use App\Models\DailyAttendances;
use App\Models\Enrollment;
use App\Models\ExamCategory;
use App\Models\FrontendEvent;
use App\Models\Grade;
use App\Models\Gradebook;
use App\Models\Hostel;
use App\Models\HostelApplication;
use App\Models\HostelFee;
use App\Models\HostelRoom;
use App\Models\HostelRoomAllocation;
use App\Models\MessageThrade;
use App\Models\Noticeboard;
use App\Models\OnlineExam;
use App\Models\Programme;
use App\Models\Routine;
use App\Models\Section;
use App\Models\StudentFeeManager;
use App\Support\Admissions\ApplicationDocuments;
use App\Models\StudentProfile;
use App\Models\School;
use App\Models\StudentRequest;
use App\Models\Subject;
use App\Models\Syllabus;
use App\Models\TeacherPermission;
use App\Models\User;
use App\Support\CourseRegistration\CourseRegistrationService;
use App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility;
use App\Support\CourseRegistration\StudentCourseOfferingDiscovery;
use App\Support\CourseRegistration\StudentCourseCatalogue;
use App\Support\Passwords\PortalPasswordChange;
use App\Support\TenantConfiguration;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use DomainException;
use PDF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\ProfilePhoto;
use App\Support\Audit\StatusChangeAudit;
use App\Support\SafeUpload;
use App\Support\Clubs\ClubTenancy;

class StudentController extends Controller
{
    /**
     * Show the student dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function studentDashboard()
    {
        if (auth()->user()->role_id != 7) {
            return redirect()->route('login')->with('error', 'You are not logged in.');
        }

        $student = auth()->user();
        $schoolId = $student->school_id;

        $enrollment = Enrollment::where('user_id', $student->id)->where('school_id', $schoolId)->first();
        // 0 is the "not class-based" sentinel (see EnrollmentDefaults) — a
        // Programme-track student with no class assigned yet has an
        // Enrollment row, but !empty(0) is false, so this correctly falls
        // through to the Programme branch below instead of showing a
        // nonexistent "class 0".
        $classRoom = !empty($enrollment?->class_id) ? Classes::where('school_id', $schoolId)->find($enrollment->class_id) : null;
        $section = !empty($enrollment?->section_id) ? Section::find($enrollment->section_id) : null;

        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $programme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;

        // My Teachers — only meaningful once the student has a real class,
        // same TeacherPermission source every gradebook/attendance roster
        // already trusts, so this always agrees with what those screens show.
        $myTeachers = collect();
        if ($classRoom) {
            $teacherIds = TeacherPermission::where('school_id', $schoolId)
                ->where('class_id', $classRoom->id)
                ->when($section, fn ($q) => $q->where('section_id', $section->id))
                ->pluck('teacher_id')
                ->unique();
            $myTeachers = User::whereIn('id', $teacherIds)->where('role_id', 3)->get();
        }

        // My Fee Balance
        $feeInvoices = StudentFeeManager::where('student_id', $student->id)->where('school_id', $schoolId)->get();
        $totalDue = (float) $feeInvoices->sum(fn ($f) => max(0, (float) $f->total_amount - (float) $f->paid_amount));
        $unpaidInvoiceCount = $feeInvoices->where('status', '!=', 'paid')->count();

        // My Exams — reuses the same scopes the teacher Live Monitor and
        // OnlineExam::scopeVisibleToStudent() already use, so this always
        // agrees with what the student's own online-exam list shows.
        $examClassId = $classRoom?->id;
        $ongoingExamsCount = OnlineExam::visibleToStudent($schoolId, $examClassId)->active()->count();
        $upcomingExamsCount = OnlineExam::visibleToStudent($schoolId, $examClassId)->upcoming()->count();

        // My Attendance — this month, this student only. Was previously
        // querying the empty, unused `enrollments` (plural) table for a
        // school-wide chart; this student's own daily_attendances rows are
        // the real source of truth (see TeacherController::attendanceTake()).
        $monthStart = strtotime(date('Y-m-01'));
        $monthEnd = strtotime(date('Y-m-t')) + 86399;
        $attendanceRows = DailyAttendances::where('student_id', $student->id)
            ->where('school_id', $schoolId)
            ->whereBetween('timestamp', [$monthStart, $monthEnd])
            ->get();
        $presentDays = $attendanceRows->where('status', 1)->count();
        $markedDays = $attendanceRows->count();

        // My Courses widget — the same active-registration scope
        // myCourses()/registerCourses() already use, so this widget always
        // agrees with what the full "My Courses" page shows.
        $myCourseRegistrations = CourseRegistration::forStudent($student->id)
            ->where('school_id', $schoolId)
            ->active()
            ->with('subject')
            ->latest('id')
            ->take(5)
            ->get();

        // Announcements — reuses the same noticeboard table/scoping the
        // "Back Office" sidebar's notice-count badge already trusts.
        $announcements = Noticeboard::where('school_id', $schoolId)
            ->where('status', 1)
            ->orderByDesc('id')
            ->take(5)
            ->get();

        // Course Progress / Overall Progress — both derived from the same
        // marks-JSON-on-Gradebook shape the transcript already decodes,
        // just aggregated across all of the student's gradebook rows.
        $allGrades = Gradebook::where('student_id', $student->id)->where('school_id', $schoolId)->get();
        $subjectPercentages = [];
        foreach ($allGrades as $g) {
            $marksData = is_string($g->marks) ? (json_decode($g->marks, true) ?: []) : [];
            $obtained = 0;
            $total = 0;
            foreach ($marksData as $m) {
                $obtained += (float) ($m['obtained'] ?? 0);
                $total += (float) ($m['total'] ?? 0);
            }
            if ($total > 0) {
                $subjectPercentages[] = $obtained / $total * 100;
            }
        }
        $overallProgressPercent = count($subjectPercentages) > 0 ? round(array_sum($subjectPercentages) / count($subjectPercentages), 1) : null;

        $totalSubjectsForCourse = $classRoom
            ? Subject::where('school_id', $schoolId)->where('class_id', $classRoom->id)->count()
            : ($programme ? Subject::where('school_id', $schoolId)->where('programme_id', $programme->id)->count() : 0);
        $courseProgressPercent = $totalSubjectsForCourse > 0
            ? round(min(count($subjectPercentages), $totalSubjectsForCourse) / $totalSubjectsForCourse * 100, 1)
            : null;

        // Exam Board — a short list (not just the counts) for the dashboard
        // panel, same visibleToStudent() scope as the counts above.
        $examBoardOngoing = OnlineExam::visibleToStudent($schoolId, $examClassId)->active()->take(3)->get();
        $examBoardUpcoming = OnlineExam::visibleToStudent($schoolId, $examClassId)->upcoming()->take(3)->get();

        return view('student.dashboard', compact(
            'student',
            'enrollment',
            'classRoom',
            'section',
            'studentProfile',
            'programme',
            'myTeachers',
            'totalDue',
            'unpaidInvoiceCount',
            'ongoingExamsCount',
            'upcomingExamsCount',
            'presentDays',
            'markedDays',
            'myCourseRegistrations',
            'announcements',
            'overallProgressPercent',
            'courseProgressPercent',
            'examBoardOngoing',
            'examBoardUpcoming',
            'feeInvoices'
        ));
    }

    /**
     * Self-service digital ID card — reuses the exact same
     * CommonController::get_student_details_by_id() data source and
     * id-card CSS classes as the existing admin/parent ID card views
     * (App\Http\Controllers\AdminController::studentIdCardGenerate(),
     * ParentController's own), just gated to the student themself and
     * extended with Programme/Intake for a Programme-track (HEI) student —
     * the existing card only ever showed Class/Section, which is blank for
     * that track.
     */
    public function idCardGenerate()
    {
        $student = auth()->user();
        $student_details = (new CommonController)->get_student_details_by_id($student->id);
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $programme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;
        $school = \App\Models\School::find($student->school_id);
        $cardNumber = \App\Support\IdCard::cardNumber($student);
        $validFor = \App\Support\IdCard::validFor($student->school_id);
        $qrDataUri = \App\Support\IdCard::qrDataUri($student);

        return view('student.id_card', compact('student_details', 'programme', 'school', 'cardNumber', 'validFor', 'qrDataUri'));
    }

    public function idCardPdf()
    {
        $student = auth()->user();
        $student_details = (new CommonController)->get_student_details_by_id($student->id);
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $programme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;
        $school = \App\Models\School::find($student->school_id);
        $cardNumber = \App\Support\IdCard::cardNumber($student);
        $validFor = \App\Support\IdCard::validFor($student->school_id);
        $qrDataUri = \App\Support\IdCard::qrDataUri($student);

        $pdf = PDF::loadView('student.id_card_pdf', compact('student_details', 'programme', 'school', 'cardNumber', 'validFor', 'qrDataUri'));

        return $pdf->download('ID_Card_' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($student_details['code'] ?: $student->id)) . '.pdf');
    }

    /**
     * My Courses — pick subjects from the student's own programme/class
     * catalog for the current session, then confirm once fees are settled.
     * Nothing before this let a student register for specific courses at
     * all; Gradebook (marks) assumed the course list came from somewhere
     * else, and Subject's credits/code/course_type columns (added earlier
     * this session) had no student-facing consumer yet.
     */
    public function myCourses(Request $request)
    {
        $student = auth()->user();
        $schoolId = $student->school_id;

        $path = $this->studentCoursePath($student);
        if ($path !== 'legacy') {
            if ($path === 'configuration') {
                $courseData = [
                    'state' => 'configuration',
                    'message' => 'Your academic pathway could not be determined. Contact the academic office.',
                    'year' => null, 'period' => null, 'offerings' => collect(), 'registrations' => collect(),
                    'pending' => collect(), 'confirmed' => collect(), 'history' => collect(), 'finance_eligible' => false,
                ];
            } else {
                try {
                    $courseData = app(StudentCourseOfferingDiscovery::class)->discover($student);
                } catch (DomainException) {
                    $courseData = [
                        'state' => 'integrity_review',
                        'message' => 'Your academic registration record needs review. Contact the academic office.',
                        'year' => null, 'period' => null, 'offerings' => collect(), 'registrations' => collect(),
                        'pending' => collect(), 'confirmed' => collect(), 'history' => collect(), 'finance_eligible' => false,
                    ];
                }
            }
            $school = School::query()->whereKey($schoolId)->first();
            $terminology = app(TenantConfiguration::class)->terminology($school);

            // Search and filters arrive as query string on a GET, and are applied by
            // the catalogue to the rows `discover()` already authorised — there is no
            // new query for a student to widen, so a crafted ?year= or ?q= cannot
            // surface a course that was not already theirs.
            $catalogue = app(StudentCourseCatalogue::class, ['schoolId' => $schoolId])->build(
                $courseData,
                $request->only(['q', 'year', 'period'])
            );

            // Namespaced under `catalogue` rather than merged flat. Flat, its `sections` and
            // `finance_eligible` keys would sit loose in the view namespace next to
            // discovery's identically-named keys — two meanings for one name in the
            // same template, resolved only by the `+` operator's left-wins rule.
            return view('student.my_courses', $courseData + [
                'catalogue' => $catalogue,
                'workflow' => 'hei',
                'courseUnitLabel' => $terminology['course_unit'] ?? 'Course Unit',
            ]);
        }

        $enrollment = Enrollment::where('user_id', $student->id)->where('school_id', $schoolId)->first();
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $programme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;
        $classId = !empty($enrollment?->class_id) ? $enrollment->class_id : null;

        $availableSubjects = $programme
            ? Subject::where('programme_id', $programme->id)->where('school_id', $schoolId)->get()
            : ($classId ? Subject::where('class_id', $classId)->where('school_id', $schoolId)->get() : collect());

        $registrations = CourseRegistration::forStudent($student->id)
            ->where('school_id', $schoolId)
            ->active()
            ->with('subject')
            ->get();

        $registeredSubjectIds = $registrations->pluck('subject_id');

        $feeInvoices = StudentFeeManager::where('student_id', $student->id)->where('school_id', $schoolId)->get();
        $totalDue = (float) $feeInvoices->sum(fn ($f) => max(0, (float) $f->total_amount - (float) $f->paid_amount));

        return view('student.my_courses', compact(
            'programme',
            'availableSubjects',
            'registrations',
            'registeredSubjectIds',
            'totalDue'
        ));
    }

    public function registerCourses(Request $request)
    {
        $student = auth()->user();
        $schoolId = $student->school_id;

        $path = $this->studentCoursePath($student);
        if ($path === 'configuration') {
            return redirect()->route('student.my_courses')->with('error', 'Your academic pathway could not be determined. Contact the academic office.');
        }
        if ($path === 'hei') {
            $validated = $request->validate(['course_offering_id' => ['required', 'integer', 'min:1']]);
            try {
                $discovery = app(StudentCourseOfferingDiscovery::class);
                $membershipId = $discovery->assignmentMembershipForOffering($student, (int) $validated['course_offering_id']);
                if ($membershipId === null) {
                    return redirect()->route('student.my_courses')->with('error', 'That Course Offering is no longer available for your current academic context. Refresh the page or contact the academic office.');
                }
                app(CourseRegistrationService::class)->registerStudentForOffering(
                    (int) $schoolId,
                    (int) $student->id,
                    (int) $validated['course_offering_id'],
                    $membershipId,
                    (int) $student->id,
                );
                return redirect()->route('student.my_courses')->with('message', 'Course registration saved. Confirm it after your financial eligibility is cleared.');
            } catch (DomainException) {
                return redirect()->route('student.my_courses')->with('error', 'The Course Offering could not be registered. Refresh the page or contact the academic office.');
            }
        }

        $validated = $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')->where(fn ($query) => $query->where('school_id', $schoolId))],
        ]);

        $sessionId = get_school_settings($schoolId)->value('running_session') ?: null;

        foreach ($validated['subject_ids'] as $subjectId) {
            CourseRegistration::firstOrCreate(
                ['student_id' => $student->id, 'subject_id' => $subjectId, 'session_id' => $sessionId],
                ['school_id' => $schoolId, 'status' => CourseRegistration::STATUS_REGISTERED]
            );
        }

        return redirect()->back()->with('message', get_phrase('Courses registered. Confirm them once your fees are settled.'));
    }

    public function confirmCourse($id)
    {
        $student = auth()->user();
        $registration = CourseRegistration::forStudent($student->id)->where('school_id', $student->school_id)->findOrFail((int) $id);

        if ($registration->isOfferingBacked()) {
            try {
                app(CourseRegistrationService::class)->confirmRegistration((int) $student->school_id, (int) $registration->id, (int) $student->id);
                return redirect()->back()->with('message', get_phrase('Course confirmed.'));
            } catch (ValidationException) {
                return redirect()->back()->with('error', 'Registration is saved, but confirmation is currently unavailable until your outstanding balance is resolved.');
            } catch (DomainException) {
                return redirect()->back()->with('error', 'Registration could not be confirmed. Contact the academic office if the problem continues.');
            }
        }

        if (! app(StudentRegistrationConfirmationEligibility::class)->allows($student)) {
            return redirect()->back()->with('error', get_phrase('You must clear your fee balance before confirming course registration.'));
        }

        $registration->update(['status' => CourseRegistration::STATUS_CONFIRMED]);

        return redirect()->back()->with('message', get_phrase('Course confirmed.'));
    }

    public function dropCourse($id)
    {
        $student = auth()->user();
        $registration = CourseRegistration::forStudent($student->id)->where('school_id', $student->school_id)->findOrFail((int) $id);
        if ($registration->isOfferingBacked()) {
            $offering = $registration->courseOffering()->first();
            if (! $offering) abort(404);
            if ($offering->status === \App\Models\CourseOffering::STATUS_IN_PROGRESS) {
                return redirect()->back()->with('error', 'Withdrawal from an in-progress Course Offering requires Academic Office assistance.');
            }
            try {
                app(CourseRegistrationService::class)->dropRegistration((int) $student->school_id, (int) $registration->id, (int) $student->id);
                return redirect()->back()->with('message', get_phrase('Course dropped.'));
            } catch (DomainException) {
                return redirect()->back()->with('error', 'This registration cannot be dropped in its current state. Contact the academic office for assistance.');
            }
        }
        $registration->update(['status' => CourseRegistration::STATUS_DROPPED]);

        return redirect()->back()->with('message', get_phrase('Course dropped.'));
    }

    private function studentCoursePath(User $student): string
    {
        $schoolId = (int) $student->school_id;
        $school = School::query()->whereKey($schoolId)->first();
        if (! $school) return 'configuration';
        $structure = $school->academicStructure();
        if ($structure === 'class_based') return 'legacy';
        if ($structure === 'programme_based') return 'hei';

        $profile = StudentProfile::query()->where('school_id', $schoolId)->where('user_id', $student->id)->first();
        if ($profile?->programme_id !== null || \App\Models\StudentCurriculumAssignment::query()
            ->where('school_id', $schoolId)->where('student_id', $student->id)->exists()) {
            return 'hei';
        }
        $enrollment = Enrollment::query()->where('school_id', $schoolId)->where('user_id', $student->id)->first();
        if ($enrollment && (int) $enrollment->class_id > 0) return 'legacy';

        return 'configuration';
    }

    /**
     * Student Affairs — a formal channel for a student to submit a
     * transfer application, complaint, or fee-discount appeal and track its
     * status, reviewed by admin (see AdminController::studentRequestsIndex/
     * studentRequestsUpdate). Nothing like this existed before: a student
     * had no way to request anything through the system and get a tracked
     * response — only informal, out-of-band channels.
     */
    public function requestsIndex()
    {
        $student = auth()->user();

        $requests = StudentRequest::forStudent($student->id)
            ->where('school_id', $student->school_id)
            ->latest('id')
            ->get();

        return view('student.requests.index', [
            'requests' => $requests,
            'types' => StudentRequest::TYPES,
        ]);
    }

    public function storeRequest(Request $request)
    {
        $student = auth()->user();

        $validated = $request->validate([
            'type' => ['required', Rule::in(array_keys(StudentRequest::TYPES))],
            'subject' => ['required', 'string', 'max:191'],
            'details' => ['required', 'string'],
        ]);

        StudentRequest::create([
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'type' => $validated['type'],
            'subject' => $validated['subject'],
            'details' => $validated['details'],
            'status' => StudentRequest::STATUS_PENDING,
        ]);

        return redirect()->route('student.requests.index')->with('message', get_phrase('Your request has been submitted.'));
    }

    /**
     * "My Transfers" — a dedicated, structured Transfer Application form
     * (current programme, transfer type, target programme, reason) rather
     * than the generic free-text Student Affairs request, matching the
     * reference HEI portal's own Studentship > Transfers screen. Still
     * backed by StudentRequest (type=transfer) so it shows up in the same
     * admin review queue as every other request type.
     */
    public function transfersIndex()
    {
        $student = auth()->user();

        $transfers = StudentRequest::forStudent($student->id)
            ->where('school_id', $student->school_id)
            ->where('type', StudentRequest::TYPE_TRANSFER)
            ->with('transferToProgramme')
            ->latest('id')
            ->get();

        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $currentProgramme = $studentProfile?->programme_id ? Programme::find($studentProfile->programme_id) : null;
        $programmes = Programme::where('school_id', $student->school_id)
            ->where('is_active', 1)
            ->when($currentProgramme, fn ($q) => $q->where('id', '!=', $currentProgramme->id))
            ->orderBy('name')
            ->get();
        $userInfo = json_decode((string) $student->user_information);

        return view('student.transfers.index', [
            'transfers' => $transfers,
            'currentProgramme' => $currentProgramme,
            'programmes' => $programmes,
            'transferTypes' => StudentRequest::TRANSFER_TYPES,
            'transferReasons' => StudentRequest::TRANSFER_REASONS,
            'defaultPhone' => $userInfo->phone ?? '',
        ]);
    }

    public function storeTransfer(Request $request)
    {
        $student = auth()->user();

        $validated = $request->validate([
            'transfer_type' => ['required', Rule::in(array_keys(StudentRequest::TRANSFER_TYPES))],
            'transfer_to_programme_id' => ['required', 'exists:programmes,id'],
            'transfer_reason' => ['required', Rule::in(array_keys(StudentRequest::TRANSFER_REASONS))],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'details' => ['nullable', 'string'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        // users.phone isn't a real column — phone lives inside the
        // user_information JSON blob, same as everywhere else this app
        // reads a student's contact details (see CommonController).
        $userInfo = json_decode((string) $student->user_information);
        $defaultPhone = $userInfo->phone ?? null;

        $documentPath = null;
        if ($request->hasFile('document')) {
            $fileName = SafeUpload::store($request->file('document'), public_path('assets/uploads/student_transfers'), ApplicationDocuments::ALLOWED_EXTENSIONS, ApplicationDocuments::MAX_FILE_MB * 1024) ?? abort(422, 'This file type is not allowed.');
            // stored by SafeUpload::store() above
            $documentPath = 'assets/uploads/student_transfers/' . $fileName;
        }

        StudentRequest::create([
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'type' => StudentRequest::TYPE_TRANSFER,
            'subject' => get_phrase('Inter/Intra Programme Transfer Application'),
            'details' => $validated['details'] ?? '',
            'status' => StudentRequest::STATUS_PENDING,
            'transfer_type' => $validated['transfer_type'],
            'transfer_to_programme_id' => $validated['transfer_to_programme_id'],
            'transfer_reason' => $validated['transfer_reason'],
            'phone_number' => $validated['phone_number'] ?? $defaultPhone,
            'document_path' => $documentPath,
        ]);

        return redirect()->route('student.transfers.index')->with('message', get_phrase('Your transfer application has been submitted.'));
    }

    /**
     * Show the teacher list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function teacherList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $teachers = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 3);
            })->paginate(10);
        } else {
            $teachers = User::where('role_id', 3)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('student.teacher.teacher_list', compact('teachers', 'search'));
    }

    /**
     * Show the daily attendance.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function dailyAttendance(Request $request)
    {
        if (! empty($request->all())) {
            $data                         = $request->all();
            $date                         = '01 ' . $data['month'] . ' ' . $data['year'];
            $page_data['attendance_date'] = strtotime($date);
            $page_data['month']           = $data['month'];
            $page_data['year']            = $data['year'];

            $student_data = (new CommonController)->get_student_details_by_id(auth()->user()->id);
            $classes      = Classes::where('school_id', auth()->user()->school_id)->get();
            $sections     = Section::where(['class_id' => $student_data['class_id']])->get();

            return view('student.attendance.daily_attendance', ['student_data' => $student_data, 'classes' => $classes, 'sections' => $sections, 'page_data' => $page_data]);
        } else {

            $date                         = '01 ' . date('M') . ' ' . date('Y');
            $page_data['attendance_date'] = strtotime($date);
            $page_data['month']           = date('M');
            $page_data['year']            = date('Y');

            $student_data = (new CommonController)->get_student_details_by_id(auth()->user()->id);
            $classes      = Classes::where('school_id', auth()->user()->school_id)->get();
            $sections     = Section::where(['class_id' => $student_data['class_id']])->get();
            return view('student.attendance.daily_attendance', ['student_data' => $student_data, 'classes' => $classes, 'sections' => $sections, 'page_data' => $page_data]);
        }
    }

    public function dailyAttendanceFilter_csv(Request $request)
    {
        // The export encodes month/year in its first query key; without it answer with a validation error, never HTTP 500.
        if (empty($request->all())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['month' => get_phrase('Choose a month to export.')]);
        }

        $data = $request->all();

        $store_get_data = array_keys($data);

        $data['month']   = substr($store_get_data[0], 0, 3);
        $data['year']    = substr($store_get_data[0], 4, 4);
        $data['role_id'] = substr($store_get_data[0], 9, 5);

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $date = '01 ' . $data['month'] . ' ' . $data['year'];

        $first_date = strtotime($date);

        $last_date = date("Y-m-t", strtotime($date));
        $last_date = strtotime($last_date);

        $page_data['month']           = $data['month'];
        $page_data['year']            = $data['year'];
        $page_data['attendance_date'] = $first_date;
        $no_of_users                  = 0;

        $no_of_users            = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['school_id' => auth()->user()->school_id, 'session_id' => $active_session])->distinct()->count('student_id');
        $attendance_of_students = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['school_id' => auth()->user()->school_id, 'student_id' => auth()->user()->id, 'session_id' => $active_session])->get()->toArray();

        $csv_content    = "Student" . "/" . get_phrase('Date');
        $number_of_days = date('m', $page_data['attendance_date']) == 2 ? (date('Y', $page_data['attendance_date']) % 4 ? 28 : (date('m', $page_data['attendance_date']) % 100 ? 29 : (date('m', $page_data['attendance_date']) % 400 ? 28 : 29))) : ((date('m', $page_data['attendance_date']) - 1) % 7 % 2 ? 30 : 31);
        for ($i = 1; $i <= $number_of_days; $i++) {
            $csv_content .= ',' . get_phrase($i);
        }

        $file = "Attendence_report.csv";

        $student_id_count = 0;

        foreach (array_slice($attendance_of_students, 0, $no_of_users) as $attendance_of_student) {
            $csv_content .= "\n";

            $user_details = (new CommonController)->get_user_by_id_from_user_table($attendance_of_student['student_id']);
            if (date('m', $page_data['attendance_date']) == date('m', $attendance_of_student['timestamp'])) {

                if ($student_id_count != $attendance_of_student['student_id']) {

                    $csv_content .= $user_details['name'] . ',';

                    for ($i = 1; $i <= $number_of_days; $i++) {

                        $page_data['date'] = $i . ' ' . $page_data['month'] . ' ' . $page_data['year'];
                        $timestamp         = strtotime($page_data['date']);

                        $attendance_by_id = DailyAttendances::where(['student_id' => $attendance_of_student['student_id'], 'school_id' => auth()->user()->school_id, 'timestamp' => $timestamp])->first();

                        if (isset($attendance_by_id->status) && $attendance_by_id->status == 1) {
                            $csv_content .= "P,";
                        } elseif (isset($attendance_by_id->status) && $attendance_by_id->status == 0) {
                            $csv_content .= "A,";
                        } else {
                            $csv_content .= ",";
                        }

                        if ($i == $number_of_days) {
                            $csv_content = substr_replace($csv_content, "", -1);
                        }
                    }
                }

                $student_id_count = $attendance_of_student['student_id'];
            }
        }

        // Security Phase 2F: streamed to the requester — no copy is written to
        // the working directory (public/ under a web server) any more.
        return response($csv_content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '/', '\\'], '', $file) . '"',
            'Cache-Control'       => 'must-revalidate',
            'Expires'             => '0',
            'Pragma'              => 'public',
        ]);
    }

    /**
     * Show the routine.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function routine()
    {
        $student_data = (new CommonController)->get_student_details_by_id(auth()->user()->id);
        $class_id     = $student_data['class_id'];
        $section_id   = $student_data['section_id'];
        $classes      = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('student.routine.routine', ['class_id' => $class_id, 'section_id' => $section_id, 'classes' => $classes]);
    }

    /**
     * Show the subject list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function subjectList()
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $student_data = (new CommonController)->get_student_details_by_id(auth()->user()->id);
        $subjects     = Subject::where('class_id', $student_data['class_id'])
            ->where('school_id', auth()->user()->school_id)
            ->where('session_id', $active_session)
            ->paginate(10);

        return view('student.subject.subject_list', compact('subjects'));
    }

    /**
     * Show the syllabus.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function syllabus()
    {
        if (auth()->user()->role_id != "" && auth()->user()->role_id == 7) {
            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
            $student_data   = (new CommonController)->get_student_details_by_id(auth()->user()->id);

            $syllabuses = Syllabus::where(['class_id' => $student_data['class_id'], 'section_id' => $student_data['section_id'], 'session_id' => $active_session, 'school_id' => auth()->user()->school_id])->paginate(10);

            return view('student.syllabus.syllabus', compact('syllabuses'));
        } else {
            return redirect('login')->with('error', "Please login first.");
        }
    }

    /**
     * Show the grade list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function marks($value = '')
    {
        $exam_categories = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $user_id         = auth()->user()->id;
        $student_details = (new CommonController)->get_student_details_by_id($user_id);

        $subjects = Subject::where(['class_id' => $student_details['class_id'], 'school_id' => auth()->user()->school_id])->get();

        return view('student.marks.index', ['exam_categories' => $exam_categories, 'student_details' => $student_details, 'subjects' => $subjects]);
    }

    public function gradeList()
    {
        $grades = Grade::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('student.grade.grade_list', compact('grades'));
    }

    /**
     * Show the book list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function bookList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $books = Book::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id);
            })->orWhere(function ($query) use ($search) {
                $query->where('author', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id);
            })->paginate(10);
        } else {
            $books = Book::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('student.book.list', compact('books', 'search'));
    }

    public function bookIssueList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if (count($request->all()) > 0) {

            $data = $request->all();

            $date        = explode('-', $data['eDateRange']);
            $date_from   = strtotime($date[0] . ' 00:00:00');
            $date_to     = strtotime($date[1] . ' 23:59:59');
            $book_issues = BookIssue::where('issue_date', '>=', $date_from)
                ->where('issue_date', '<=', $date_to)
                ->where('school_id', auth()->user()->school_id)
                ->where('session_id', $active_session)
                ->get();

            return view('student.book.book_issue', ['book_issues' => $book_issues, 'date_from' => $date_from, 'date_to' => $date_to]);
        } else {

            $date_from   = strtotime(date('d-M-Y', strtotime(' -30 day')) . ' 00:00:00');
            $date_to     = strtotime(date('d-M-Y') . ' 23:59:59');
            $book_issues = BookIssue::where('issue_date', '>=', $date_from)
                ->where('issue_date', '<=', $date_to)
                ->where('school_id', auth()->user()->school_id)
                ->where('session_id', $active_session)
                ->get();

            return view('student.book.book_issue', ['book_issues' => $book_issues, 'date_from' => $date_from, 'date_to' => $date_to]);
        }
    }

    /**
     * Show the noticeboard list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function noticeboardList()
    {
        $notices = Noticeboard::get()->where('school_id', auth()->user()->school_id);

        $events = [];

        foreach ($notices as $notice) {
            if ($notice['end_date'] != "") {
                if ($notice['start_date'] != $notice['end_date']) {
                    $end_date = strtotime($notice['end_date']) + 24 * 60 * 60;
                    $end_date = date('Y-m-d', $end_date);
                } else {
                    $end_date = date('Y-m-d', strtotime($notice['end_date']));
                }
            }

            if ($notice['end_date'] == "" && $notice['start_time'] == "" && $notice['end_time'] == "") {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                ];
            } else if ($notice['start_time'] != "" && ($notice['end_date'] == "" && $notice['end_time'] == "")) {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                ];
            } else if ($notice['end_date'] != "" && ($notice['start_time'] == "" && $notice['end_time'] == "")) {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                    'end'   => $end_date,
                ];
            } else if ($notice['end_date'] != "" && $notice['start_time'] != "" && $notice['end_time'] != "") {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                    'end'   => date('Y-m-d', strtotime($notice['end_date'])) . 'T' . $notice['end_time'],
                ];
            } else {
                $info = [
                    'id'    => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                ];
            }
            array_push($events, $info);
        }

        $events = json_encode($events);

        return view('student.noticeboard.noticeboard', ['events' => $events]);
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('student.noticeboard.edit', ['notice' => $notice]);
    }

    /**
     * Show the live class.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function FeeManagerList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        $student        = auth()->user();

        if (count($request->all()) > 0) {
            $data            = $request->all();
            $date            = explode('-', $data['eDateRange']);
            $date_from       = strtotime($date[0] . ' 00:00:00');
            $date_to         = strtotime($date[1] . ' 23:59:59');
            $selected_status = $data['status'];

            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)
                ->where('timestamp', '<=', $date_to)
                ->where('student_id', $student->id)
                ->where('school_id', $student->school_id)
                ->where('session_id', $active_session)
                ->when($selected_status !== 'all', fn ($q) => $q->where('status', $selected_status))
                ->orderByDesc('timestamp')
                ->get();
        } else {
            // No filter applied yet — show every invoice this student has for
            // the running session, not just the last 30 days. The previous
            // default silently hid invoices older than 30 days behind a date
            // range the page never explained, making a student with real,
            // unpaid fees see "No data found" and assume they had none.
            $date_from       = null;
            $date_to         = null;
            $selected_status = "";

            $invoices = StudentFeeManager::where('student_id', $student->id)
                ->where('school_id', $student->school_id)
                ->where('session_id', $active_session)
                ->orderByDesc('timestamp')
                ->get();
        }

        $feeStructures = \App\Models\FeeStructure::whereIn('id', $invoices->pluck('fee_structure_id')->filter()->unique())
            ->pluck('name', 'id');

        $totalDue = (float) $invoices->sum(fn ($i) => max(0, (float) $i->total_amount - (float) $i->paid_amount));

        return view('student.fee_manager.student_fee_manager', [
            'invoices'        => $invoices,
            'feeStructures'   => $feeStructures,
            'totalDue'        => $totalDue,
            'date_from'       => $date_from,
            'date_to'         => $date_to,
            'selected_status' => $selected_status,
        ]);
    }

    public function feeManagerExport($date_from = "", $date_to = "", $selected_status = "")
    {

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('student_id', auth()->user()->id)->where('session_id', $active_session)->get();
        } else if ($selected_status == "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('student_id', auth()->user()->id)->where('session_id', $active_session)->get();
        }

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        $file = "student_fee-" . date('d-m-Y', $date_from) . '-' . date('d-m-Y', $date_to) . '-' . $selected_status . ".csv";

        $csv_content = get_phrase('Invoice No') . ', ' . get_phrase('Student') . ', ' . get_phrase('Class') . ', ' . get_phrase('Invoice Title') . ', ' . get_phrase('Total Amount') . ', ' . get_phrase('Created At') . ', ' . get_phrase('Paid Amount') . ', ' . get_phrase('Status');

        foreach ($invoices as $invoice) {
            $csv_content .= "\n";

            $student_details = (new CommonController)->get_student_details_by_id($invoice['student_id']);
            $invoice_no      = sprintf('%08d', $invoice['id']);

            $csv_content .= $invoice_no . ', ' . $student_details['name'] . ', ' . $student_details['class_name'] . ', ' . $invoice['title'] . ', ' . currency($invoice['total_amount']) . ', ' . date('d-M-Y', $invoice['timestamp']) . ', ' . currency($invoice['paid_amount']) . ', ' . $invoice['status'];
        }
        // Security Phase 2F: streamed to the requester — no copy is written to
        // the working directory (public/ under a web server) any more.
        return response($csv_content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '/', '\\'], '', $file) . '"',
            'Cache-Control'       => 'must-revalidate',
            'Expires'             => '0',
            'Pragma'              => 'public',
        ]);
    }

    public function FeePayment(Request $request, $id)
    {
        $this->findOwnFeeOrFail($id);

        $fee_details = StudentFeeManager::where('id', $id)->first()->toArray();
        $user_info   = User::where('id', $fee_details['student_id'])->first()->toArray();
        return view('student.payment.payment_gateway', ['fee_details' => $fee_details, 'user_info' => $user_info]);
    }

    /**
     * Starts a MarzPay mobile-money collection for a tuition fee invoice.
     * Nothing is marked paid here — that only happens once MarzPayWebhookController
     * confirms the collection actually completed.
     */
    public function startMarzpayTuitionPayment(Request $request, $id)
    {
        $fee = StudentFeeManager::where('id', $id)
            ->where('student_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $fee || $fee->status === 'paid') {
            return redirect()->route('student.FeePayment', $id)->with('error', get_phrase('Invoice not found or already paid.'));
        }

        $request->validate([
            'phone_number' => 'required|string|min:9|max:15',
        ]);

        $reference = (string) \Illuminate\Support\Str::uuid();

        $result = \App\Support\Payments\MarzPayService::initiateMobileMoneyCollection(
            (int) $fee->school_id,
            $request->phone_number,
            (float) $fee->total_amount,
            $reference,
            'Tuition fee: ' . $fee->title,
            route('webhooks.marzpay'),
            ['context' => 'tuition', 'context_id' => $fee->id]
        );

        if (! $result['ok']) {
            return redirect()->route('student.FeePayment', $id)->with('error', $result['error'] ?: get_phrase('We could not start the MarzPay payment. Please try again.'));
        }

        $fee->update([
            'status'            => 'processing',
            'payment_method'    => 'marzpay',
            'gateway_reference' => $result['transaction_uuid'] ?: $reference,
        ]);

        return view('student.payment.marzpay_pending', ['fee_details' => $fee->toArray(), 'context' => 'tuition']);
    }

    /** AJAX poll from the pending page — checks MarzPay directly and updates the row if MarzPay has already settled it. */
    public function checkMarzpayTuitionStatus($id)
    {
        $fee = StudentFeeManager::where('id', $id)
            ->where('student_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $fee) {
            return response()->json(['status' => 'not_found']);
        }

        if ($fee->status === 'paid') {
            return response()->json(['status' => 'paid']);
        }

        if (! $fee->gateway_reference) {
            return response()->json(['status' => $fee->status]);
        }

        $verified = \App\Support\Payments\MarzPayService::getCollectionStatus($fee->gateway_reference, (int) $fee->school_id);
        $verifiedStatus = $verified['transaction']['status'] ?? null;

        if (in_array($verifiedStatus, ['successful', 'completed'], true)) {
            $fee->update([
                'status'          => 'paid',
                'paid_amount'     => $verified['collection']['amount']['raw'] ?? $fee->total_amount,
                'payment_method'  => 'marzpay',
                'gateway_payload' => $verified,
            ]);

            return response()->json(['status' => 'paid']);
        }

        if (in_array($verifiedStatus, ['failed', 'cancelled'], true)) {
            $fee->update(['status' => 'failed']);

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => 'processing']);
    }

    public function studentFeeinvoice(Request $request, $id)
    {
        $this->findOwnFeeOrFail($id);

        $invoice_details = StudentFeeManager::find($id)->toArray();
        $student_details = (new CommonController)->get_student_details_by_id($invoice_details['student_id'])->toArray();

        return view('student.fee_manager.invoice', ['invoice_details' => $invoice_details, 'student_details' => $student_details]);
    }

    /**
     * Security Phase 2E: a fee reached by id must be this student's own
     * invoice — the same rule FeeManagerList() uses.
     */
    private function findOwnFeeOrFail($id): StudentFeeManager
    {
        return StudentFeeManager::where('id', $id)->where('student_id', auth()->user()->id)->where('school_id', auth()->user()->school_id)->firstOrFail();
    }

    public function offlinePaymentStudent(Request $request, $id = "")
    {
        $feeBefore = $this->findOwnFeeOrFail($id);
        $request->validate(['document_image' => 'nullable|file|mimes:' . implode(',', ApplicationDocuments::ALLOWED_EXTENSIONS) . '|max:' . (ApplicationDocuments::MAX_FILE_MB * 1024)]);
        if ($request->hasFile('document_image') && !in_array(strtolower($request->file('document_image')->getClientOriginalExtension()), ApplicationDocuments::ALLOWED_EXTENSIONS, true)) {
            return redirect()->back()->with('error', 'Only PDF, JPG and PNG files are accepted.');
        }
        $data = $request->all();

        if ($data['amount'] > 0) {

            $file = $data['document_image'];

            if ($file) {
                $filename  = bin2hex(random_bytes(20)) . '.' . strtolower($file->getClientOriginalExtension());
                $extension = $file->getClientOriginalExtension(); //Get extension of uploaded file

                $file->move(public_path('assets/uploads/offline_payment'), $filename);
                $data['document_image'] = $filename;
            } else {
                $data['document_image'] = '';
            }

            StudentFeeManager::where('id', $id)->update([
                'status'         => 'pending',
                'document_image' => $data['document_image'],
                'payment_method' => 'offline',
            ]);

            StatusChangeAudit::feePayment($feeBefore, 'submitted');
            return redirect()->route('student.fee_manager.list')->with('message', 'offline payment requested successfully');
        } else {
            return redirect()->route('student.fee_manager.list')->with('message', 'offline payment requested fail');
        }
    }

    public function profile()
    {
        return view('student.profile.view');
    }

    public function profile_update(Request $request)
    {
        $data['name']  = $request->name;
        $data['email'] = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }

        $user_info['birthday']     = strtotime($request->eDefaultDateRange);
        $user_info['gender']       = $request->gender;
        $user_info['phone']        = $request->phone;
        $user_info['address']      = $request->address;
        $user_info['blood_group']  = $request->blood_group;

        if (empty($request->photo)) {
            $user_info['photo'] = $request->old_photo;
        } else {
            $file_name = ProfilePhoto::store($request->photo);
            if ($file_name === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }
            $user_info['photo'] = $file_name;
        }

        $data['user_information'] = json_encode($user_info);

        auth()->user()->update($data);

        return redirect(route('student.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }

    public function user_language(Request $request)
    {
        $data['language'] = $request->language;
        auth()->user()->update($data);

        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    public function password($action_type = null, Request $request)
    {

        if ($action_type == 'update') {

            $user = auth()->user();

            // When this page is reached because of portal activation, the
            // "current" password is the temporary password from the activation
            // email. Say so, instead of reporting a bare mismatch.
            $hint = $user->force_password_change
                ? 'It is the temporary password in your activation email, not your new password.'
                : '';

            // One shared authority for the rules and the wording. On failure this
            // throws ValidationException, which renders a per-field message
            // beside the input and never shows Laravel's internal terminology.
            PortalPasswordChange::validate($request, $hint);

            if (! PortalPasswordChange::currentPasswordMatches($request->input('old_password'), $user->password)) {
                return back()
                    ->withInput($request->except('old_password', 'new_password', 'confirm_password'))
                    ->withErrors(['old_password' => $user->force_password_change
                        ? 'That is not the temporary password from your activation email. Please check the email and try again.'
                        : 'The current password you entered is not correct. Please try again.']);
            }

            $wasForced = (bool) $user->force_password_change;

            $user->update([
                'password' => Hash::make($request->input('new_password')),
                'force_password_change' => false,
            ]);

            if ($wasForced) {
                AuditLog::record('update', 'Staff & Students', "Student completed the forced password change after portal activation (#" . auth()->id() . ").");
            }

            return redirect(route('student.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('student.profile.password');
    }

    /**
     * Show the event list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function eventList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $events = FrontendEvent::where(function ($query) use ($search) {
                $query->where('title', 'LIKE', "%{$search}%");
            })->paginate(10);
        } else {
            $events = FrontendEvent::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('student.events.events', compact('events', 'search'));
    }

    public function complain()
    {
        return view('student.complain.complain');
    }
    public function complainUser(Request $request)
    {
        $data = $request->all();

        $page_data['class_id']   = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];
        $page_data['receiver']   = $data['receiver'];
        return view('student.complain.complainUser', ['page_data' => $page_data]);
    }

    public function receivers(Request $request)
    {
        $data = $request->all();

        $page_data['class_id']   = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];
        $page_data['receiver']   = $data['receiver'];
        return view('student.complain.complain', ['page_data' => $page_data]);
    }

    //  Message

    public function allMessage(Request $request, $id)
    {

        $msg_user_details = DB::table('users')
            ->join('message_thrades', function ($join) {
                // Join where the user is the sender
                $join->on('users.id', '=', 'message_thrades.sender_id')
                    ->orWhere(function ($query) {
                        // Join where the user is the receiver
                        $query->on('users.id', '=', 'message_thrades.reciver_id');
                    });
            })
            ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
            ->where('message_thrades.id', $id)
            ->where('message_thrades.school_id', auth()->user()->school_id)
            ->where('users.id', '<>', auth()->user()->id) // Exclude the authenticated user
            ->first();

        if ($request->ajax()) {
            $query = $request->input('query');

            // Search users by name or any other criteria
            $users = User::where('name', 'LIKE', "%{$query}%")
                ->where('school_id', auth()->user()->school_id)
                ->get();

            // Prepare HTML response
            $html = '';

            // Check if any users were found
            if ($users->isEmpty()) {
                return response()->json('No User found');
            }

            foreach ($users as $user) {

                if (! empty($user)) {
                    $userInfo = json_decode($user->user_information);

                    $user_image = ! empty($userInfo->photo)
                        ? asset('assets/uploads/user-images/' . $userInfo->photo)
                        : asset('assets/uploads/user-images/thumbnail.png');

                    $html .= '
                          <div class="user-item d-flex align-items-center msg_us_src_list">
                              <a href="' . route('student.message.messagethrades', ['id' => $user->id]) . '">
                                  <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                                  <span class="ms-3">' . $user->name . '</span>
                              </a>
                          </div>
                      ';
                }
            }

            return response()->json($html);
        }

        $chat_datas = Chat::where('school_id', auth()->user()->school_id)->get();

        $counter_condition = Chat::where('message_thrade', $id)->orderBy('id', 'desc')->first();

        if ($counter_condition->sender_id != auth()->user()->id) {
            Chat::where('message_thrade', $id)->update(['read_status' => 1]);
        }

        return view('student.message.all_message', ['msg_user_details' => $msg_user_details], ['chat_datas' => $chat_datas]);
    }

    public function messagethrades($id)
    {

        $exists = MessageThrade::where('reciver_id', $id)
            ->where('sender_id', auth()->user()->id)
            ->exists();
        if ($id != auth()->user()->id) {
            if (! $exists) {
                $message_thrades_data = [
                    'reciver_id' => $id,
                    'sender_id'  => auth()->user()->id,
                    'school_id'  => auth()->user()->school_id,
                ];

                MessageThrade::create($message_thrades_data);

                //return redirect()->back()->with('message', 'User added successfully');
            }

            $message_thrades = MessageThrade::where('reciver_id', $id)
                ->where('sender_id', auth()->user()->id)
                ->first();
            $msg_trd_id = $message_thrades->id;

            $msg_user_details = DB::table('users')
                ->join('message_thrades', 'users.id', '=', 'message_thrades.reciver_id')
                ->select('users.id as user_id', 'message_thrades.id as thread_id', 'users.*', 'message_thrades.*')
                ->where('message_thrades.id', $msg_trd_id)
                ->first();

            $chat_datas = Chat::where('school_id', auth()->user()->school_id)->get();

            // Combine all data into a single array
            return view('student.message.all_message', ['id' => $msg_trd_id, 'msg_user_details' => $msg_user_details, 'chat_datas' => $chat_datas]);
        }
        return redirect()->back()->with('error', 'You can not add you');
    }

    public function chat_save(Request $request)
    {
        $data      = $request->all();
        $chat_data = [
            'message_thrade' => $data['message_thrade'],
            'reciver_id'     => $data['reciver_id'],
            'message'        => $data['message'],
            'school_id'      => auth()->user()->school_id,
            'sender_id'      => auth()->user()->id,
            'read_status'    => 0,

        ];

        // Create feedback entry
        Chat::create($chat_data);

        return redirect()->back();
    }

    public function chat_empty(Request $request)
    {

        if ($request->ajax()) {
            $query = $request->input('query');

            $users = User::where('name', 'LIKE', "%{$query}%")
                ->where('school_id', auth()->user()->school_id)
                ->get();

            $html = '';

            if ($users->isEmpty()) {
                return response()->json('No User found');
            }

            foreach ($users as $user) {
                $userInfo   = json_decode($user->user_information);
                $user_image = ! empty($userInfo->photo)
                    ? asset('assets/uploads/user-images/' . $userInfo->photo)
                    : asset('assets/uploads/user-images/thumbnail.png');

                $html .= '
                      <div class="user-item d-flex align-items-center msg_us_src_list">
                          <a href="' . route('student.message.messagethrades', ['id' => $user->id]) . '">
                              <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                              <span class="ms-3">' . $user->name . '</span>
                          </a>
                      </div>
                  ';
            }

            return response()->json($html);
        }

        // Pass the data to the view only if msg_user_details is not null
        return view('student.message.chat_empty');
    }

    public function appraisalList()
    {
        $user_info = auth()->user();

        $student_details = (new CommonController)->get_student_details_by_id($user_info->id);

        $appraisals = Appraisal::where('class_id', $student_details->class_id)->where('school_id', auth()->user()->school_id)->where('status', 1)->paginate(10);

        return view('student.appraisal.appraisalList', ['appraisals' => $appraisals]);
    }

    public function singleAppraisal($id)
    {
        $student_id = auth()->user()->id;

        $appraisal = Appraisal::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        // Check if the student has already submitted
        $submission = Appraisal_submit::where([
            ['apprasial_id', $id],
            ['student_id', $student_id],
        ])->first();

        $submittedAnswers = $submission ? json_decode($submission->answers, true) : [];
        return view('student.appraisal.singleAppraisal', compact('appraisal', 'submittedAnswers'));
    }

    public function appraisalSubmit(Request $request, $id)
    {
        $student_id = auth()->user()->id;

        // Check if already submitted
        if (Appraisal_submit::where('apprasial_id', $id)->where('student_id', $student_id)->exists()) {
            return redirect()->back()->with('error', 'You have already submitted this appraisal.');
        }

        $appraisal  = Appraisal::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $student_id = auth()->user()->id;
        $school_id  = auth()->user()->school_id;
        $answers    = $request->input('answers');

        Appraisal_submit::create([
            'apprasial_id' => $id,
            'student_id'   => $student_id,
            'school_id'    => $school_id,
            'answers'      => json_encode($answers),
        ]);

        return redirect()->back()->with('message', 'Appraisal submitted successfully!');
    }

    public function hostelApplications()
    {
        $student_id = auth()->user()->id;

        $applications = HostelApplication::where('student_id', $student_id)->where('school_id', auth()->user()->school_id)->paginate(10);

        return view('student.hostel.applications.list', compact('applications'));
    }
    public function applicationCreate()
    {
        // Security Phase 2I: only this school's hostels and rooms (applicationStore() only accepts those).
        $hostels = Hostel::where('school_id', auth()->user()->school_id)->get();

        $hostel_rooms = HostelRoom::where('school_id', auth()->user()->school_id)->where('status', 1)
            ->whereRaw('occupied < capacity')
            ->with('hostel')
            ->get();

        return view('student.hostel.applications.create', compact('hostels', 'hostel_rooms'));
    }
    public function applicationStore(Request $request)
    {
        // Security Phase 2G: the hostel and room must belong to the student's own school.
        $request->validate([
            'hostel_id' => 'required|exists:hostels,id,school_id,' . auth()->user()->school_id,
            'room_id'   => 'required|exists:hostel_rooms,id,school_id,' . auth()->user()->school_id,
            'note'      => 'nullable|string|max:500',
        ]);

        $existingApplication = HostelApplication::where('student_id', auth()->user()->id)
            ->whereIn('status', [0, 1])
            ->first();

        if ($existingApplication) {
            return redirect()->back()->with('error', get_phrase('You already have an active hostel application'));
        }

        $existingAllocation = HostelRoomAllocation::where('student_id', auth()->user()->id)
            ->where('status', 1)
            ->first();

        if ($existingAllocation) {
            return redirect()->back()->with('error', get_phrase('You are already allocated to a hostel room'));
        }

        $room = HostelRoom::find($request->room_id);
        if ($room->occupied >= $room->capacity) {
            return redirect()->back()->with('error', get_phrase('Selected room is already full'));
        }

        if ($room->hostel_id != $request->hostel_id) {
            return redirect()->back()->with('error', get_phrase('Selected room does not belong to the chosen hostel'));
        }

        $application             = new HostelApplication();
        $application->student_id = auth()->user()->id;
        $application->school_id  = auth()->user()->school_id;
        $application->hostel_id  = $request->hostel_id;
        $application->room_id    = $request->room_id;
        $application->status     = 0;
        $application->note       = $request->note;
        $application->save();

        return redirect()->route('student.hostel.applications')->with('success', get_phrase('Hostel application submitted successfully'));
    }

    /**
     * Security Phase 2I: the student's own application, in their own school, that is still
     * pending (status 0) — the only state the application list offers Edit and Delete for.
     * Once staff approve or reject it, the application is staff-controlled.
     */
    private function findOwnPendingApplicationOrFail($id): HostelApplication
    {
        return HostelApplication::where('student_id', auth()->user()->id)
            ->where('school_id', auth()->user()->school_id)
            ->where('status', 0)
            ->findOrFail($id);
    }

    public function applicationEdit($id)
    {
        $application = $this->findOwnPendingApplicationOrFail($id);
        $hostels     = Hostel::where('school_id', auth()->user()->school_id)->get();

        return view('student.hostel.applications.edit', compact('application', 'hostels'));
    }

    public function applicationUpdate(Request $request, $id)
    {
        $application = $this->findOwnPendingApplicationOrFail($id);

        // Same rules as applicationStore().
        $request->validate([
            'hostel_id' => 'required|exists:hostels,id,school_id,' . auth()->user()->school_id,
            'room_id'   => 'required|exists:hostel_rooms,id,school_id,' . auth()->user()->school_id,
            'note'      => 'nullable|string|max:500',
        ]);

        $room = HostelRoom::where('school_id', auth()->user()->school_id)->findOrFail($request->room_id);
        if ($room->occupied >= $room->capacity) {
            return redirect()->back()->with('error', get_phrase('Selected room is already full'));
        }

        if ($room->hostel_id != $request->hostel_id) {
            return redirect()->back()->with('error', get_phrase('Selected room does not belong to the chosen hostel'));
        }

        $application->hostel_id = $request->hostel_id;
        $application->room_id   = $request->room_id;
        $application->note      = $request->note;
        $application->save();

        return redirect()->route('student.hostel.applications')->with('success', get_phrase('Hostel application updated successfully'));
    }

    public function applicationDelete($id)
    {
        $this->findOwnPendingApplicationOrFail($id)->delete();

        return redirect()->route('student.hostel.applications')->with('success', get_phrase('Hostel application deleted successfully'));
    }

    /** Rooms of one of this school's hostels, in the shape edit.blade.php's room picker reads. */
    public function applicationRooms($hostel_id)
    {
        return response()->json(
            HostelRoom::where('school_id', auth()->user()->school_id)
                ->where('hostel_id', $hostel_id)
                ->where('status', 1)
                ->get(['id', 'room_no', 'capacity', 'occupied', 'seat_fee'])
        );
    }
    /**
     * Show the hostel fee manager list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function hostelFeeManagerList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        $student_id     = auth()->user()->id;

        if (count($request->all()) > 0) {
            $data            = $request->all();
            $date            = explode('-', $data['eDateRange']);
            $date_from       = strtotime($date[0] . ' 00:00:00');
            $date_to         = strtotime($date[1] . ' 23:59:59');
            $selected_status = $data['status'];

            if ($selected_status != "all") {
                $invoices = HostelFee::where('created_at', '>=', date('Y-m-d H:i:s', $date_from))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', $date_to))
                    ->where('status', $selected_status)
                    ->where('student_id', $student_id)
                    ->where('school_id', auth()->user()->school_id)
                    ->get();
            } else {
                $invoices = HostelFee::where('created_at', '>=', date('Y-m-d H:i:s', $date_from))
                    ->where('created_at', '<=', date('Y-m-d H:i:s', $date_to))
                    ->where('student_id', $student_id)
                    ->where('school_id', auth()->user()->school_id)
                    ->get();
            }

            return view('student.hostel.fee_manager.student_hostel_fee_manager', [
                'invoices'        => $invoices,
                'date_from'       => $date_from,
                'date_to'         => $date_to,
                'selected_status' => $selected_status,
            ]);
        } else {
            $date_from       = strtotime(date('d-M-Y', strtotime(' -30 day')) . ' 00:00:00');
            $date_to         = strtotime(date('d-M-Y') . ' 23:59:59');
            $selected_status = "";

            $invoices = HostelFee::where('created_at', '>=', date('Y-m-d H:i:s', $date_from))
                ->where('created_at', '<=', date('Y-m-d H:i:s', $date_to))
                ->where('student_id', $student_id)
                ->where('school_id', auth()->user()->school_id)
                ->get();

            return view('student.hostel.fee_manager.student_hostel_fee_manager', [
                'invoices'        => $invoices,
                'date_from'       => $date_from,
                'date_to'         => $date_to,
                'selected_status' => $selected_status,
            ]);
        }
    }

    public function hostelFeeManagerExport($date_from = "", $date_to = "", $selected_status = "")
    {
        $student_id = auth()->user()->id;

        if ($selected_status != "all") {
            $invoices = HostelFee::where('created_at', '>=', date('Y-m-d H:i:s', $date_from))
                ->where('created_at', '<=', date('Y-m-d H:i:s', $date_to))
                ->where('status', $selected_status)
                ->where('student_id', $student_id)
                ->where('school_id', auth()->user()->school_id)
                ->get();
        } else {
            $invoices = HostelFee::where('created_at', '>=', date('Y-m-d H:i:s', $date_from))
                ->where('created_at', '<=', date('Y-m-d H:i:s', $date_to))
                ->where('student_id', $student_id)
                ->where('school_id', auth()->user()->school_id)
                ->get();
        }

        $file        = "hostel_fee-" . date('d-m-Y', $date_from) . '-' . date('d-m-Y', $date_to) . '-' . $selected_status . ".csv";
        $csv_content = get_phrase('Invoice No') . ', ' . get_phrase('Student') . ', ' . get_phrase('Hostel') . ', ' . get_phrase('Invoice Title') . ', ' . get_phrase('Total Amount') . ', ' . get_phrase('Created At') . ', ' . get_phrase('Paid Amount') . ', ' . get_phrase('Status');

        foreach ($invoices as $invoice) {
            $csv_content .= "\n";
            $student_details = (new CommonController)->get_student_details_by_id($invoice['student_id']);
            $hostel          = Hostel::find($invoice['hostel_id']);
            $invoice_no      = sprintf('%08d', $invoice['id']);

            $csv_content .= $invoice_no . ', ' . $student_details['name'] . ', ' . ($hostel ? $hostel->name : 'N/A') . ', ' . $invoice['title'] . ', ' . currency($invoice['amount']) . ', ' . date('d-M-Y', strtotime($invoice['created_at'])) . ', ' . currency($invoice['paid_amount']) . ', ' . $invoice['status'];
        }

        // Security Phase 2F: streamed to the requester — no copy is written to
        // the working directory (public/ under a web server) any more.
        return response($csv_content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', '/', '\\'], '', $file) . '"',
            'Cache-Control'       => 'must-revalidate',
            'Expires'             => '0',
            'Pragma'              => 'public',
        ]);
    }

    /**
     * Show hostel fee invoice.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function hostelFeeInvoice(Request $request, $id)
    {
        $invoice_details = HostelFee::where('id', $id)->where('student_id', auth()->user()->id)->where('school_id', auth()->user()->school_id)->firstOrFail()->toArray();
        $student_details = (new CommonController)->get_student_details_by_id($invoice_details['student_id'])->toArray();
        $hostel          = Hostel::find($invoice_details['hostel_id']);

        return view('student.hostel.fee_manager.invoice', [
            'invoice_details' => $invoice_details,
            'student_details' => $student_details,
            'hostel'          => $hostel,
        ]);
    }

    public function hostelFeePayment(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer',
        ]);

        $application = HostelApplication::where('student_id', auth()->user()->id)
            ->where('school_id', auth()->user()->school_id)
            ->first();

        $fee_amount = HostelRoom::where('id', $application->room_id)
            ->where('school_id', auth()->user()->school_id)
            ->value('seat_fee');

        $hostelFee = HostelFee::create([
            'student_id'       => auth()->user()->id,
            'school_id'        => auth()->user()->school_id,
            'hostel_id'        => $application->hostel_id,
            'title'            => get_phrase('Hostel Fee Payment'),
            'amount'           => $fee_amount,
            'status'           => 0,
            'fee_payment_date' => \Carbon\Carbon::createFromDate($validated['year'], $validated['month'], 1)->format('Y-m-d'),
        ]);

        $fee_details = [
            'id'               => $hostelFee->id,
            'title'            => 'hostel_fee',
            'total_amount'     => $fee_amount,
            'class_id'         => null,
            'parent_id'        => null,
            'student_id'       => auth()->user()->id,
            'payment_method'   => null,
            'paid_amount'      => 0,
            'status'           => 'unpaid',
            'school_id'        => auth()->user()->school_id,
            'session_id'       => get_school_settings(auth()->user()->school_id)->value('running_session'),
            'timestamp'        => time(),
            'discounted_price' => 0,
            'amount'           => $fee_amount,
        ];
        $user_info = User::where('id', auth()->user()->id)->first()->toArray();

        session()->put([
            'hostel_fee_month' => $validated['month'],
            'hostel_fee_year'  => $validated['year'],

        ]);
        return view('student.hostel.payment_gateway', compact('fee_details', 'user_info'));
    }

    public function startMarzpayHostelPayment(Request $request, $id)
    {
        $fee = HostelFee::where('id', $id)
            ->where('student_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $fee || (string) $fee->status === '1') {
            return redirect()->route('student.hostel_fee_manager.list')->with('error', get_phrase('Invoice not found or already paid.'));
        }

        $request->validate([
            'phone_number' => 'required|string|min:9|max:15',
        ]);

        $reference = (string) \Illuminate\Support\Str::uuid();

        $result = \App\Support\Payments\MarzPayService::initiateMobileMoneyCollection(
            (int) $fee->school_id,
            $request->phone_number,
            (float) $fee->amount,
            $reference,
            'Hostel fee: ' . $fee->title,
            route('webhooks.marzpay'),
            ['context' => 'hostel', 'context_id' => $fee->id]
        );

        if (! $result['ok']) {
            return redirect()->back()->with('error', $result['error'] ?: get_phrase('We could not start the MarzPay payment. Please try again.'));
        }

        $fee->update([
            'payment_method'    => 'marzpay',
            'gateway_reference' => $result['transaction_uuid'] ?: $reference,
        ]);

        return view('student.payment.marzpay_pending', ['fee_details' => $fee->toArray(), 'context' => 'hostel']);
    }

    public function checkMarzpayHostelStatus($id)
    {
        $fee = HostelFee::where('id', $id)
            ->where('student_id', auth()->id())
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $fee) {
            return response()->json(['status' => 'not_found']);
        }

        if ((string) $fee->status === '1') {
            return response()->json(['status' => 'paid']);
        }

        if ((string) $fee->status === '2') {
            return response()->json(['status' => 'failed']);
        }

        if (! $fee->gateway_reference) {
            return response()->json(['status' => 'processing']);
        }

        $verified = \App\Support\Payments\MarzPayService::getCollectionStatus($fee->gateway_reference, (int) $fee->school_id);
        $verifiedStatus = $verified['transaction']['status'] ?? null;

        if (in_array($verifiedStatus, ['successful', 'completed'], true)) {
            $fee->update([
                'status'          => 1,
                'paid_amount'     => $verified['collection']['amount']['raw'] ?? $fee->amount,
                'payment_method'  => 'marzpay',
                'payment_date'    => now()->toDateString(),
                'gateway_payload' => $verified,
            ]);

            return response()->json(['status' => 'paid']);
        }

        if (in_array($verifiedStatus, ['failed', 'cancelled'], true)) {
            $fee->update(['status' => 2]);

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => 'processing']);
    }

    public function offlinePaymentHostel(Request $request)
    {
        $data = $request->all();

        $application = HostelApplication::where('student_id', auth()->user()->id)
            ->where('school_id', auth()->user()->school_id)
            ->firstOrFail();

        if ($data['amount'] <= 0) {
            return redirect()->route('student.hostel_fee_manager.list')
                ->with('message', 'Offline payment request failed');
        }

        // Security Phase 2F: same proof-file policy as tuition offline payments —
        // PDF/JPG/PNG by extension and detected content, size-capped, stored
        // under a generated name (never the client's filename).
        $fileName = '';
        if ($request->hasFile('document_image')) {
            $request->validate(['document_image' => 'file|mimes:' . implode(',', ApplicationDocuments::ALLOWED_EXTENSIONS) . '|max:' . (ApplicationDocuments::MAX_FILE_MB * 1024)]);
            $file      = $request->file('document_image');
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ApplicationDocuments::ALLOWED_EXTENSIONS, true)) {
                return redirect()->back()->with('error', 'Only PDF, JPG and PNG files are accepted.');
            }
            $fileName = bin2hex(random_bytes(20)) . '.' . $extension;
            $file->move(public_path('assets/uploads/hostel_fees'), $fileName);
        }

        $feePaymentDate = Carbon::createFromDate(
            session('hostel_fee_year'),
            session('hostel_fee_month'),
            1
        )->format('Y-m-d');

        // Check if a rejected payment already exists for this month/year
        $existingFee = HostelFee::where('student_id', auth()->user()->id)
            ->where('school_id', auth()->user()->school_id)
            ->where('fee_payment_date', $feePaymentDate)
            ->whereIn('status', [0, 2])
            ->first();

        if ($existingFee) {
            // Update the rejected or pending payment
            $existingFee->amount         = $data['amount'];
            $existingFee->document_image = $fileName;
            $existingFee->status         = 0;
            $existingFee->save();
        } else {
            // Create new payment record
            HostelFee::create([
                'school_id'        => auth()->user()->school_id,
                'student_id'       => auth()->user()->id,
                'hostel_id'        => $application->hostel_id,
                'title'            => get_phrase('Hostel Fee Payment'),
                'amount'           => $data['amount'],
                'status'           => 0,
                'fee_payment_date' => $feePaymentDate,
                'document_image'   => $fileName,
            ]);
        }

        // Clear session data
        session()->forget(['payment_for', 'fee_details', 'hostel_fee_month', 'hostel_fee_year']);

        // Redirect to hostel fee manager list with success message
        return redirect()->route('student.hostel_fee_manager.list')->with('message', 'Offline payment requested successfully');
    }

    // student_hostel_fee_success_payment_student / student_hostel_fee_fail_payment_student removed
    // (Security Phase 2I): legacy gateway callbacks with no caller; the success one marked the invoice
    // paid from URL data alone. Hostel fees are paid via MarzPay (verified) or offline payment.

    public function hostelFeeMonthlyList()
    {
        $student_id = auth()->user()->id;

        // Check if student is allocated to any hostel
        $allocation = HostelRoomAllocation::where('student_id', $student_id)
            ->where('status', 1)
            ->first();

        if (! $allocation) {
            return redirect()->back()->with('error', 'You are not allocated to any hostel.');
        }

        $currentYear = now()->year;
        $monthlyFees = [];

        // Get all months from January to current month
        for ($month = 1; $month <= now()->month; $month++) {
            $monthName  = date('F', mktime(0, 0, 0, $month, 1));
            $monthStart = \Carbon\Carbon::create($currentYear, $month, 1)->startOfDay();
            $monthEnd   = \Carbon\Carbon::create($currentYear, $month, 1)->endOfMonth();

            $feePaid = HostelFee::where('school_id', auth()->user()->school_id)
                ->where('student_id', $student_id)
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->first();

            $monthlyFees[] = [
                'month'              => $monthName,
                'year'               => $currentYear,
                'status'             => $feePaid ? 'Paid' : 'Unpaid',
                'fee_details'        => $feePaid,
                'month_number'       => $month,
                'is_current_or_past' => $month <= now()->month,
            ];
        }

        return view('student.hostel.fee_manager.monthly_list', [
            'monthlyFees' => $monthlyFees,
            'allocation'  => $allocation,
            'currentYear' => $currentYear,
        ]);
    }

    /**
     * Pay specific month hostel fee.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function payMonthlyFee(Request $request, $month, $year)
    {
        $student_id = auth()->user()->id;

        // Check if student is allocated to any hostel
        $allocation = HostelRoomAllocation::where('student_id', $student_id)
            ->where('status', 1)
            ->first();

        if (! $allocation) {
            return redirect()->back()->with('error', 'You are not allocated to any hostel.');
        }

        // Check if fee already paid for this month
        $monthStart = \Carbon\Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd   = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();

        $existingFee = HostelFee::where('school_id', auth()->user()->school_id)
            ->where('student_id', $student_id)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->first();

        if ($existingFee) {
            return redirect()->route('student.hostel_fee.monthly_list')->with('error', 'Fee for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)) . ' already paid.');
        }

        // Get hostel details
        $hostel = Hostel::find($allocation->hostel_id);
        $room   = HostelRoom::find($allocation->room_id);

        // Create fee record
        $fee                   = new HostelFee();
        $fee->student_id       = $student_id;
        $fee->school_id        = auth()->user()->school_id;
        $fee->hostel_id        = $allocation->hostel_id;
        $fee->room_id          = $allocation->room_id;
        $fee->title            = 'Hostel Fee for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year));
        $fee->amount           = $hostel->fee ?? 0;
        $fee->fee_payment_date = \Carbon\Carbon::createFromDate($year, $month, 1)->format('Y-m-d');
        $fee->status           = 0;
        $fee->due_date         = $monthEnd;
        $fee->save();

        $fee_details = [
            'id'               => $fee->id,
            'title'            => $fee->title,
            'total_amount'     => $fee->amount,
            'class_id'         => null,
            'parent_id'        => null,
            'student_id'       => $student_id,
            'payment_method'   => null,
            'paid_amount'      => 0,
            'status'           => 'unpaid',
            'school_id'        => auth()->user()->school_id,
            'session_id'       => get_school_settings(auth()->user()->school_id)->value('running_session'),
            'timestamp'        => time(),
            'discounted_price' => 0,
            'amount'           => $fee->amount,
        ];
        $user_info = User::where('id', $student_id)->first()->toArray();
        return view('student.payment.payment_gateway', compact('fee_details', 'user_info'));
    }

    public function club(Request $request)
    {
        $search     = $request->search;
        $advisorId = $request->advisor_id;

        $clubs = ClubTenancy::clubs()->with('advisor')
            ->when($search, function ($query) use ($search) {
                $query->where('club_name', 'LIKE', "%{$search}%");
            })
            ->when($advisorId, function ($query) use ($advisorId) {
                $query->where('advisor_id', $advisorId);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $teachers = User::where('school_id', auth()->user()->school_id)->where('role_id', 3)
            ->where('status', 1)
            ->get();

        return view('student.club.index', compact(
            'clubs',
            'search',
            'advisorId',
            'teachers'
        ));
    }


    public function join(Club $club)
    {
        ClubTenancy::assertOwned($club);

        $studentId = auth()->id();

        $exists = ClubMember::where('club_id', $club->id)
            ->where('student_id', $studentId)
            ->first();

        if ($exists) {
            return back()->with('error', 'Already requested');
        }

        ClubMember::create([
            'club_id' => $club->id,
            'student_id' => $studentId,
            'status' => 0 // pending
        ]);

        return back()->with('success', 'Join request sent');
    }
    public function removeRequest(Club $club)
    {
        ClubTenancy::assertOwned($club);

        ClubMember::where('club_id', $club->id)
            ->where('student_id', auth()->id())
            ->where('status', 0)
            ->delete();

        return back()->with('success', 'Join request removed');
    }
    public function leave(Club $club)
    {
        ClubTenancy::assertOwned($club);

        ClubMember::where('club_id', $club->id)
            ->where('student_id', auth()->id())
            ->where('status', 1)
            ->delete();

        return back()->with('success', 'You left the club');
    }
    public function notice_index($clubId)
    {
        $club = ClubTenancy::findClubOrFail($clubId);

        $notices = ClubNotice::where('club_id', $clubId)
            ->orderBy('notice_date', 'desc')
            ->get();

        return view('student.club.notice', compact('club', 'notices'));
    }
    public function notice_store(Request $request)
    {
        $request->validate([
            'club_id' => 'required',
            'title' => 'required',
            'description' => 'required',
            'notice_date' => 'required|date'
        ]);

        ClubNotice::create($request->all());

        return redirect()->back()->with('success', 'Notice created successfully');
    }
}
