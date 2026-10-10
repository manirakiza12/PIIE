<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonController;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Session;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Classes;
use App\Models\Subject;
use App\Models\Gradebook;
use App\Models\Grade;
use App\Models\ClassList;
use App\Models\Section;
use App\Models\Enrollment;
use App\Models\DailyAttendances;
use App\Models\Routine;
use App\Models\Syllabus;
use App\Models\Noticeboard;
use App\Models\FrontendEvent;
use App\Models\Admin;
use App\Models\ExpenseCategory;
use App\Models\Expense;
use App\Models\StudentFeeManager;
use App\Models\TeacherPermission;
use App\Models\Feedback;
use App\Models\MessageThrade;
use App\Models\Chat;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubNotice;
use Illuminate\Foundation\Auth\User as AuthUser;
use Stripe\Exception\PermissionException;
use App\Support\ProfilePhoto;
use App\Support\SafeUpload;
use App\Support\Clubs\ClubTenancy;


class TeacherController extends Controller
{
    /**
     * Show the teacher dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function teacherDashboard()
    {
        return view('teacher.dashboard');
    }


    /**
     * Show the grade list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function marks($value = '')
    {
        $exam_categories = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $sessions = Session::where('school_id', auth()->user()->school_id)->get();
        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->where('marks', 1)->get()->toArray();
        $permitted_classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            // A permission row can outlive its class; skip it rather than 500.
            $class_details = Classes::where('id', $distinct_class['class_id'])->first()?->toArray();
            if ($class_details === null) {
                continue;
            }
            $permitted_classes[$key] = $class_details;
        }

        $classes = $permitted_classes;

        return view('teacher.marks.index', ['exam_categories' => $exam_categories, 'classes' => $classes, 'sessions' => $sessions]);
    }

    public function marksFilter(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['exam_category_id' => 'present', 'class_id' => 'present', 'section_id' => 'present', 'subject_id' => 'present', 'session_id' => 'present']);
        $data = $request->all();

        $page_data['exam_category_id'] = $data['exam_category_id'];
        $page_data['class_id'] = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];
        $page_data['subject_id'] = $data['subject_id'];
        $page_data['session_id'] = $data['session_id'];

        // Pre-RBAC cleanup: these ids come from the request — resolve them within this school
        // (a section through its class), so another school's names are never echoed.
        $class = Classes::where('school_id', auth()->user()->school_id)->findOrFail($data['class_id']);
        $page_data['class_name'] = $class->name;
        $page_data['section_name'] = Section::where('class_id', $class->id)->findOrFail($data['section_id'])->name;
        $page_data['subject_name'] = Subject::where('school_id', auth()->user()->school_id)->findOrFail($data['subject_id'])->name;
        $page_data['session_title'] = Session::where('school_id', auth()->user()->school_id)->findOrFail($data['session_id'])->session_title;

        $enroll_students = Enrollment::where('class_id', $page_data['class_id'])
            ->where('section_id', $page_data['section_id'])
            ->where('school_id', auth()->user()->school_id)
            ->get();

        $page_data['exam_categories'] = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $permissions = TeacherPermission::where('class_id', $data['class_id'])->where('section_id', $data['section_id'])->where('marks', 1)->where('teacher_id', auth()->user()->id)->get()->toArray();
        $permitted_classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            $class_details = Classes::where('id', $distinct_class['class_id'])->first()->toArray();
            $permitted_classes[$key] = $class_details;
        }

        $page_data['classes'] = $permitted_classes;

        $exam = Exam::where('exam_type', 'offline')
            ->where('class_id', $data['class_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('session_id', $data['session_id'])
            ->where('exam_category_id', $data['exam_category_id'])
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if ($exam) {
            $response = view('teacher.marks.marks_list', ['enroll_students' => $enroll_students, 'page_data' => $page_data])->render();
            return response()->json(['status' => 'success', 'html' => $response]);
        } else {
            return response()->json(['status' => 'error', 'message' => 'No records found for the specified filter.']);
        }
    }

    /**
     * Show the exam list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function offlineExamList()
    {
        $id = "all";
        $exams = Exam::where('exam_type', 'offline')->paginate(10);
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('teacher.examination.offline_exam_list', compact('exams', 'classes', 'id'));
    }

    public function offlineExamExport($id = "")
    {
        if ($id != "all") {
            $exams = Exam::where([
                'exam_type' => 'offline',
                'class_id' => $id
            ])->get();
        } else {
            $exams = Exam::get()->where('exam_type', 'offline');
        }
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('teacher.examination.offline_exam_export', ['exams' => $exams, 'classes' => $classes]);
    }

    public function classWiseOfflineExam($id)
    {
        $exams = Exam::where([
            'exam_type' => 'offline',
            'class_id' => $id
        ])->get();
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('teacher.examination.exam_list', ['exams' => $exams, 'classes' => $classes, 'id' => $id]);
    }

    /**
     * Show the routine.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function routine()
    {
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('teacher.routine.routine', ['classes' => $classes]);
    }

    public function routineList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $class_id = $data['class_id'];
        $section_id = $data['section_id'];
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('teacher.routine.routine_list', ['class_id' => $class_id, 'section_id' => $section_id, 'classes' => $classes]);
    }


    /**
     * Show the subject list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function subjectList(Request $request)
    {
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        if (count($request->all()) > 0 && $request->class_id != '') {

            $data = $request->all();
            $class_id = $data['class_id'];
            $subjects = Subject::where('class_id', $class_id)->paginate(10);
        } else {
            $subjects = Subject::where('school_id', auth()->user()->school_id)->paginate(10);
            $class_id = '';
        }

        return view('teacher.subject.subject_list', compact('subjects', 'classes', 'class_id'));
    }

    public function createSubject()
    {
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('teacher.subject.create_subject', compact('classes'));
    }

    public function subjectCreate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'class_id' => 'required|integer|exists:classes,id',
        ]);

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->max('id');
        }

        if (empty($active_session)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Please create or set an active academic session before adding subjects.');
        }

        Subject::create([
            'name' => $request->name,
            'class_id' => $request->class_id,
            'school_id' => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect('/teacher/subject?class_id=' . $request->class_id)
            ->with('message', 'You have successfully created subject.');
    }

    /**
     * Show the gradebook.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function gradebook(Request $request)
    {

        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        $exam_categories = ExamCategory::get()->where('school_id', auth()->user()->school_id);

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if (count($request->all()) > 0) {

            $data = $request->all();

            $filter_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get();

            $class_id = $data['class_id'];
            $section_id = $data['section_id'];
            $exam_category_id = $data['exam_category_id'];
            $subjects = Subject::where(['class_id' => $class_id, 'school_id' => auth()->user()->school_id])->get();
        } else {
            $filter_list = [];

            $class_id = '';
            $section_id = '';
            $exam_category_id = '';
            $subjects = '';
        }

        return view('teacher.gradebook.gradebook', ['filter_list' => $filter_list, 'class_id' => $class_id, 'section_id' => $section_id, 'exam_category_id' => $exam_category_id, 'classes' => $classes, 'exam_categories' => $exam_categories, 'subjects' => $subjects]);
    }

    public function gradebookList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present', 'exam_category_id' => 'present']);
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $exam_wise_student_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get();
        echo view('teacher.gradebook.list', ['exam_wise_student_list' => $exam_wise_student_list, 'class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session]);
    }

    public function subjectWiseMarks(Request $request, $student_id = "")
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $subject_wise_mark_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'student_id' => $student_id, 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->first();

        echo view('teacher.gradebook.subject_marks', ['subject_wise_mark_list' => $subject_wise_mark_list]);
    }

    public function list_of_syllabus(Request $request)
    {
        $data = $request->all();
        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->select('class_id')->distinct()->get()->toArray();
        $permitted_classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            // A permission row can outlive its class; skip it rather than 500.
            $class_details = Classes::where('id', $distinct_class['class_id'])->first()?->toArray();
            if ($class_details === null) {
                continue;
            }
            $permitted_classes[$key] = $class_details;
        }


        return view('teacher.syllabus.index', ['permitted_classes' => $permitted_classes]);
    }

    public function class_wise_section_for_syllabus(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['classId' => 'present']);
        $data = $request->all();
        $permissions = TeacherPermission::where('class_id', $data['classId'])->where('teacher_id', auth()->user()->id)->get()->toArray();
        $permitted_sections = array();

        foreach ($permissions as $key => $distinct_section) {


            $section_details = Section::where('id', $distinct_section['section_id'])->first()->toArray();
            $permitted_sections[$key] = $section_details;
        }

        $options = '<option value="">' . 'Select a section' . '</option>';
        foreach ($permitted_sections as $section) :
            $options .= '<option value="' . $section['id'] . '">' . $section['name'] . '</option>';
        endforeach;
        echo $options;
    }

    public function syllabus_details(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();
        $syllabuses = Syllabus::where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'])
            ->where('school_id', auth()->user()->school_id)
            ->get()->toArray();

        return view('teacher.syllabus.list', ['syllabuses' => $syllabuses]);
    }

    public function show_syllabus_modal(Request $request)
    {
        $data = $request->all();

        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->select('class_id')->distinct()->get()->toArray();
        $classes = array();

        foreach ($permissions  as  $key => $distinct_class) {
            // A permission row can outlive its class; skip it rather than 500.
            $class_details = Classes::where('id', $distinct_class['class_id'])->first()?->toArray();
            if ($class_details === null) {
                continue;
            }
            $classes[$key] = $class_details;
        }

        return view('teacher.syllabus.create', ['classes' => $classes]);
    }
    public function show_syllabus_modal_post(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $file = $data['syllabus_file'];

        if ($file) {
            $filename = SafeUpload::store($file, public_path('assets/uploads/syllabus/'), null) ?? abort(422, 'This file type is not allowed.');

            $filepath = asset('assets/uploads/syllabus/' . $filename);
        }

        Syllabus::create([
            'title' => $data['title'],
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'],
            'subject_id' => $data['subject_id'],
            'file' => $filename,
            'school_id' => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect()->back()->with('message', 'You have successfully create a syllabus.');
    }

    public function syllabusDelete($id = '')
    {
        $syllabus = Syllabus::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $syllabus->delete();
        return redirect()->back()->with('message', 'You have successfully delete syllabus.');
    }

    function profile()
    {
        return view('teacher.profile.view');
    }

    function profile_update(Request $request)
    {
        $data['name'] = $request->name;
        $data['email'] = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $data['designation'] = $request->designation;

        $user_info['birthday'] = strtotime($request->eDefaultDateRange);
        $user_info['gender'] = $request->gender;
        $user_info['phone'] = $request->phone;
        $user_info['address'] = $request->address;


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

        User::where('id', auth()->user()->id)->update($data);

        return redirect(route('teacher.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }

    function user_language(Request $request)
    {
        $data['language'] = $request->language;
        User::where('id', auth()->user()->id)->update($data);

        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    function password($action_type = null, Request $request)
    {



        if ($action_type == 'update') {



            if ($request->new_password != $request->confirm_password) {
                return back()->with("error", "Confirm Password Doesn't match!");
            }


            if (!Hash::check($request->old_password, auth()->user()->password)) {
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            User::where('id', auth()->user()->id)->update($data);

            return redirect(route('teacher.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('teacher.profile.password');
    }

    /**
     * Show the noticeboard list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function noticeboardList()
    {
        $notices = Noticeboard::get()->where('school_id', auth()->user()->school_id);

        $events = array();

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
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            } else if ($notice['start_time'] != "" && ($notice['end_date'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time']
                );
            } else if ($notice['end_date'] != "" && ($notice['start_time'] == "" && $notice['end_time'] == "")) {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])),
                    'end' => $end_date
                );
            } else if ($notice['end_date'] != "" && $notice['start_time'] != "" && $notice['end_time'] != "") {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date'])) . 'T' . $notice['start_time'],
                    'end' => date('Y-m-d', strtotime($notice['end_date'])) . 'T' . $notice['end_time']
                );
            } else {
                $info = array(
                    'id' => $notice['id'],
                    'title' => $notice['notice_title'],
                    'start' => date('Y-m-d', strtotime($notice['start_date']))
                );
            }
            array_push($events, $info);
        }

        $events = json_encode($events);

        return view('teacher.noticeboard.noticeboard', ['events' => $events]);
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('teacher.noticeboard.edit', ['notice' => $notice]);
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

        return view('teacher.events.events', compact('events', 'search'));
    }


    /**
     * Show the grade daily attendance.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function dailyAttendance()
    {
        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->select('class_id')->distinct()->get()->toArray();
        $classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            // A permission row can outlive its class; skip it rather than 500.
            $class = Classes::where('id', $distinct_class['class_id'])->first();
            if (! $class) {
                continue;
            }
            $classes[$key] = $class->toArray();
        }

        $attendance_of_students = array();
        $no_of_users = 0;

        return view('teacher.attendance.daily_attendance', ['classes' => $classes, 'attendance_of_students' => $attendance_of_students, 'no_of_users' => $no_of_users]);
    }

    public function dailyAttendanceFilter(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['month' => 'present', 'year' => 'present', 'class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $date = '01 ' . $data['month'] . ' ' . $data['year'];
        $first_date = strtotime($date);
        $last_date = date("Y-m-t", strtotime($date));
        $last_date = strtotime($last_date);

        $page_data['attendance_date'] = strtotime($date);
        $page_data['class_id'] = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];
        $page_data['month'] = $data['month'];
        $page_data['year'] = $data['year'];

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $attendance_of_students = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get()->toArray();

        $no_of_users = DailyAttendances::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->distinct()->count('student_id');

        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->select('class_id')->distinct()->get()->toArray();
        $classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            // A permission row can outlive its class; skip it rather than 500.
            $class_details = Classes::where('id', $distinct_class['class_id'])->first()?->toArray();
            if ($class_details === null) {
                continue;
            }
            $classes[$key] = $class_details;
        }

        return view('teacher.attendance.attendance_list', ['page_data' => $page_data, 'classes' => $classes, 'attendance_of_students' => $attendance_of_students, 'no_of_users' => $no_of_users]);
    }

    public function takeAttendance()
    {
        $permissions = TeacherPermission::where('teacher_id', auth()->user()->id)->select('class_id')->distinct()->get()->toArray();
        $classes = array();

        foreach ($permissions  as  $key => $distinct_class) {

            // A permission row can outlive its class; skip it rather than 500.
            $class_details = Classes::where('id', $distinct_class['class_id'])->first()?->toArray();
            if ($class_details === null) {
                continue;
            }
            $classes[$key] = $class_details;
        }

        return view('teacher.attendance.take_attendance', ['classes' => $classes]);
    }

    public function studentListAttendance(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['date' => 'present', 'class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $page_data['attendance_date'] = $data['date'];
        $page_data['class_id'] = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];

        return view('teacher.attendance.student', ['page_data' => $page_data]);
    }

    public function attendanceTake(Request $request)
    {
        $att_data = $request->all();

        $students = $att_data['student_id'];
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['timestamp'] = strtotime($att_data['date']);
        $data['class_id'] = $att_data['class_id'];
        $data['section_id'] = $att_data['section_id'];
        $data['school_id'] = auth()->user()->school_id;
        $data['session_id'] = $active_session;

        $check_data = DailyAttendances::where(['timestamp' => $data['timestamp'], 'class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'session_id' => $active_session, 'school_id' => auth()->user()->school_id])->get();
        if (count($check_data) > 0) {
            foreach ($students as $key => $student):
                $data['status'] = $att_data['status-' . $student];
                $data['student_id'] = $student;
                $attendance_id = $att_data['attendance_id'];

                DailyAttendances::where('id', $attendance_id[$key])->update($data);

            endforeach;
        } else {
            foreach ($students as $student):
                $data['status'] = $att_data['status-' . $student];
                $data['student_id'] = $student;

                DailyAttendances::create($data);

            endforeach;
        }

        return redirect()->back()->with('message', 'Student attendance updated successfully.');
    }

    public function dailyAttendanceFilter_csv(Request $request)
    {
        // The export encodes month/year in its first query key; without it answer with a validation error, never HTTP 500.
        if (empty($request->all())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['month' => get_phrase('Choose a month to export.')]);
        }

        $data = $request->all();

        $store_get_data = array_keys($data);


        $data['month'] = substr($store_get_data[0], 0, 3);
        $data['year'] = substr($store_get_data[0], 4, 4);
        $data['role_id'] = substr($store_get_data[0], 9, 5);

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');


        $date = '01 ' . $data['month'] . ' ' . $data['year'];


        $first_date = strtotime($date);

        $last_date = date("Y-m-t", strtotime($date));
        $last_date = strtotime($last_date);

        $page_data['month'] = $data['month'];
        $page_data['year'] = $data['year'];
        $page_data['attendance_date'] = $first_date;
        $no_of_users = 0;

        $no_of_users = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['school_id' => auth()->user()->school_id,  'session_id' => $active_session])->distinct()->count('student_id');
        $attendance_of_students = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['school_id' => auth()->user()->school_id,  'session_id' => $active_session])->get()->toArray();


        $csv_content = "Student" . "/" . get_phrase('Date');
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
                        $timestamp = strtotime($page_data['date']);

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

    public function feedback_list()
    {
        $feedbacks = Feedback::where('school_id', auth()->user()->school_id)->orderBy('created_at', 'DESC')->paginate(20);
        return view('teacher.feedback.feedback_list', ['feedbacks' => $feedbacks]);
    }

    public function create_feedback()
    {
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('teacher.feedback.create_feedback', ['classes' => $classes]);
    }

    public function upload_feedback(Request $request)
    {
        $data = $request->all();
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        //$admin_id = auth()->user()->id;

        $feedbackData = [
            'class_id' => $data['class_id'],
            'section_id' => $data['section_id'],
            'student_id' => isset($data['student_id'][0]) ? $data['student_id'][0] : null, // Assuming single student for feedback
            'parent_id' => isset($data['parent_id'][0]) ? $data['parent_id'][0] : null, // Assuming single parent for feedback
            'feedback_text' => $data['feedback_text'],
            'school_id' => auth()->user()->school_id,
            'admin_id' => auth()->user()->id,
            'session_id' => $active_session,
            'title' => $data['title']

        ];

        // Create feedback entry
        Feedback::create($feedbackData);

        return redirect()->back()->with('message', 'Feedback Sent Successfully');
    }

    public function edit_feedback($id)
    {

        $feedback = Feedback::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('teacher.feedback.edit_feedback', ['classes' => $classes],  ['feedback' => $feedback]);
    }

    public function update_feedback(Request $request, $id)
    {
        $data = $request->all();

        unset($data['_token']);

        Feedback::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);

        return redirect()->back()->with('message', 'You have successfully update feedback.');
    }

    public function delete_feedback($id)
    {
        Feedback::where('id', $id)->where('school_id', auth()->user()->school_id)->delete();
        return redirect()->back()->with('message', 'Delete successfully.');
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

                if (!empty($user)) {
                    $userInfo = json_decode($user->user_information);

                    $user_image = !empty($userInfo->photo)
                        ? asset('assets/uploads/user-images/' . $userInfo->photo)
                        : asset('assets/uploads/user-images/thumbnail.png');

                    $html .= '
                        <div class="user-item d-flex align-items-center msg_us_src_list">
                            <a href="' . route('teacher.message.messagethrades', ['id' => $user->id]) . '">
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

        return view('teacher.message.all_message', ['msg_user_details' => $msg_user_details], ['chat_datas' => $chat_datas]);
    }

    public function messagethrades($id)
    {

        $exists = MessageThrade::where('reciver_id', $id)
            ->where('sender_id', auth()->user()->id)
            ->exists();
        if ($id != auth()->user()->id) {
            if (!$exists) {
                $message_thrades_data = [
                    'reciver_id' => $id,
                    'sender_id' => auth()->user()->id,
                    'school_id' => auth()->user()->school_id,
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
            return view('teacher.message.all_message', ['id' => $msg_trd_id, 'msg_user_details' => $msg_user_details, 'chat_datas' => $chat_datas,]);
        }
        return redirect()->back()->with('error', 'You can not add you');
    }


    public function chat_save(Request $request)
    {
        $data = $request->all();
        $chat_data = [
            'message_thrade' => $data['message_thrade'],
            'reciver_id' => $data['reciver_id'],
            'message' => $data['message'],
            'school_id' => auth()->user()->school_id,
            'sender_id' => auth()->user()->id,
            'read_status' => 0,

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
                $userInfo = json_decode($user->user_information);
                $user_image = !empty($userInfo->photo)
                    ? asset('assets/uploads/user-images/' . $userInfo->photo)
                    : asset('assets/uploads/user-images/thumbnail.png');

                $html .= '
                    <div class="user-item d-flex align-items-center msg_us_src_list">
                        <a href="' . route('teacher.message.messagethrades', ['id' => $user->id]) . '">
                            <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                            <span class="ms-3">' . $user->name . '</span>
                        </a>
                    </div>
                ';
            }

            return response()->json($html);
        }

        // Pass the data to the view only if msg_user_details is not null
        return view('teacher.message.chat_empty');
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

        return view('teacher.club.index', compact(
            'clubs',
            'search',
            'advisorId',
            'teachers'
        ));
    }


    public function createClub()
    {
        $teachers = User::where('school_id', auth()->user()->school_id)->where('role_id', 3)
            ->where('status', 1)
            ->get();
        return view('teacher.club.create_club', compact('teachers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'club_name'  => 'required|string|max:255',
            'advisor_id' => 'nullable|' . ClubTenancy::schoolUserRule(),
            'status'    => 'nullable|in:0,1',
        ]);
        ClubTenancy::createClub([
            'club_name'   => $request->club_name,
            'advisor_id'  => $request->advisor_id,
            'description' => $request->description,
            'status'      => $request->status ?? 1,
        ]);

        return redirect()->route('teacher.club.list')
            ->with('success', 'Club created successfully');
    }

    public function toggleStatus($id)
    {
        $club = ClubTenancy::findClubOrFail($id);
        $club->status = !$club->status;
        $club->save();

        return back()->with('success', 'Club status updated successfully.');
    }



    public function editClub($id)
    {
        $club = ClubTenancy::findClubOrFail($id);
        $teachers = User::where('school_id', auth()->user()->school_id)->where('role_id', 3)->get();
        return view('teacher.club.edit_club', compact('club', 'teachers'));
    }

    public function updateClub(Request $request, $id)
    {
        $club = ClubTenancy::findClubOrFail($id);
        $request->validate(['advisor_id' => 'nullable|' . ClubTenancy::schoolUserRule()]);
        $club->update($request->all());

        return redirect()->route('teacher.club.list')
            ->with('success', 'Club updated successfully');
    }
    public function deleteClub($id)
    {
        ClubTenancy::findClubOrFail($id)->delete();
        return back()->with('success', 'Club deleted');
    }
    public function clubMembers(Request $request, Club $club)
    {
        ClubTenancy::assertOwned($club);

        $search     = $request->search;
        $class_id   = $request->class_id;
        $section_id = $request->section_id;

        $members = ClubMember::with(['student.enrollment.class', 'student.enrollment.section'])
            ->where('club_id', $club->id)
            ->when($search, function ($q) use ($search) {
                $q->whereHas('student', function ($s) use ($search) {
                    $s->where('name', 'LIKE', "%{$search}%");
                });
            })
            ->when($class_id, function ($q) use ($class_id) {
                $q->whereHas('student.enrollment', function ($e) use ($class_id) {
                    $e->where('class_id', $class_id);
                });
            })
            ->when($section_id, function ($q) use ($section_id) {
                $q->whereHas('student.enrollment', function ($e) use ($section_id) {
                    $e->where('section_id', $section_id);
                });
            })
            ->get();


        return view('teacher.club.members', compact(
            'club',
            'members',
            'search',
            'class_id',
            'section_id'
        ));
    }
    public function addMemberForm(Club $club)
    {
        ClubTenancy::assertOwned($club);

        $students = User::where('school_id', auth()->user()->school_id)->where('role_id', 7)
            ->whereNotIn('id', function ($q) use ($club) {
                $q->select('student_id')
                    ->from('club_members')
                    ->where('club_id', $club->id);
            })
            ->get();

        return view('teacher.club.add_member', compact('club', 'students'));
    }



    public function storeMember(Request $request)
    {
        $request->validate([
            'club_id'    => 'required|exists:clubs,id,school_id,' . ClubTenancy::schoolId(),
            'student_id' => 'required|' . ClubTenancy::schoolUserRule(),
        ]);

        $member = ClubMember::where('club_id', $request->club_id)
            ->where('student_id', $request->student_id)
            ->first();

        if ($member) {
            return redirect()->back()
                ->with('warning', 'Student is already a club member');
        }

        ClubMember::create([
            'club_id'    => $request->club_id,
            'student_id' => $request->student_id,
            'status'     => 1,
        ]);

        return redirect()->back()
            ->with('success', 'Member added successfully');
    }
    public function searchMembers(Request $request, $clubId)
    {
        ClubTenancy::findClubOrFail($clubId);

        $search = $request->q ?? '';

        $members = ClubMember::with('student')
            ->where('club_id', $clubId)
            ->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            })
            ->limit(20)
            ->get();

        return response()->json(
            $members->map(function ($member) {
                return [
                    'id'   => $member->student->id,   // ✅ MUST be student_id
                    'text' => $member->student->name, // shown text
                ];
            })
        );
    }


    public function searchStudents(Request $request)
    {
        $search  = $request->q;
        $clubId  = $request->club_id;

        $students = User::where('school_id', auth()->user()->school_id)->where('role_id', 7)
            ->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            })
            ->whereNotIn('id', function ($query) use ($clubId) {
                $query->select('student_id')
                    ->from('club_members')
                    ->where('club_id', $clubId);
            })
            ->limit(20)
            ->get();

        return response()->json(
            $students->map(function ($student) {
                return [
                    'id'   => $student->id,
                    'text' => $student->name . ' (' . $student->email . ')',
                ];
            })
        );
    }
    public function approveMember($id)
    {
        ClubTenancy::findMemberOrFail($id)->update([
            'status' => 1,
        ]);

        return back()->with('success', 'Approved');
    }
    public function member_disable($id)
    {
        ClubTenancy::findMemberOrFail($id)->update([
            'status' => 0,
        ]);

        return back()->with('message', 'Account Disabled Successfully');
    }

    public function rejectMember($id)
    {
        ClubTenancy::findMemberOrFail($id)->update(['status' => 2]);
        return back()->with('success', 'Rejected');
    }
    public function deleteMember($id)
    {
        ClubTenancy::findMemberOrFail($id)->delete();
        return back()->with('success', 'Member removed');
    }


    public function notice1_index(Club $club)
    {
        ClubTenancy::assertOwned($club);

        $notices = ClubNotice::where('club_id', $club->id)
            ->latest()
            ->get();

        return view('teacher.club.notice.index', compact('notices', 'club'));
    }


    public function notice_create(Club $club)
    {
        ClubTenancy::assertOwned($club);

        return view('teacher.club.notice.create', compact('club'));
    }

    public function notice_store(Request $request)
    {
        $data = $request->validate([
            'club_id' => 'required|exists:clubs,id,school_id,' . ClubTenancy::schoolId(),
            'title' => 'required',
            'description' => 'nullable|required',
            'notice_date' => 'required',
            'image' => 'nullable|image',
            'status' => 'required'
        ]);

        if (! empty($data['image'])) {

            $imageName = SafeUpload::store($data['image'], public_path('assets/uploads/club/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');

            $data['image'] = $imageName;
        }


        $data['club_id'] = $request->club_id;

        if (auth()->user()->role === 'admin') {
            $data['admin_id'] = auth()->id();
        } else {
            $data['advisor_id'] = auth()->id();
        }
        ClubNotice::create($data);
        return back()->with('success', 'Notice created');
    }

    public function notice_edit(ClubNotice $notice)
    {
        ClubTenancy::findNoticeOrFail($notice->id);

        return view('teacher.club.notice.edit', compact('notice'));
    }


    public function notice_update(Request $request, $id)
    {
        $notice = ClubTenancy::findNoticeOrFail($id);

        $data = $request->validate([
            'title' => 'required',
            'description' => 'required',
            'notice_date' => 'required',
            'image' => 'nullable|image',
            'status' => 'required'
        ]);

        if ($request->hasFile('image')) {

            if ($notice->image && file_exists(public_path('assets/uploads/club/' . $notice->image))) {
                unlink(public_path('assets/uploads/club/' . $notice->image));
            }
            $imageName = SafeUpload::store($request->image, public_path('assets/uploads/club/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');

            $data['image'] = $imageName;
        }

        $notice->update($data);

        return back()->with('success', 'Notice updated successfully');
    }

    public function notice_delete($id)
    {
        ClubTenancy::findNoticeOrFail($id)->delete();
        return back()->with('success', 'Notice deleted');
    }
}
