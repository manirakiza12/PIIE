<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\User;
use App\Models\Session;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Enrollment;
use Illuminate\Support\Str;
use App\Models\Gradebook;
use App\Models\Subject;
use App\Models\School;
use App\Models\Programme;
use App\Models\StudentProfile;
use DB;
use PDF;



class CommonController extends Controller
{

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function get_student_details_by_id($id = "", $api="")
    {

        //Fetch Details

        $enrol_data = Enrollment::where('user_id', $id)->first();

        $student = User::find($id);

        if (! $student) {
            $fallback = new Enrollment();
            $fallback['code'] = '';
            $fallback['user_id'] = $id;
            $fallback['parent_name'] = '';
            $fallback['name'] = '';
            $fallback['email'] = '';
            $fallback['role'] = '';
            $fallback['address'] = '';
            $fallback['phone'] = '';
            $fallback['birthday'] = '';
            $fallback['gender'] = '';
            $fallback['student_info'] = '';
            $fallback['photo'] = isset($api) && $api == 'api' ? get_user_image_url($id) : get_user_image($id);
            $fallback['school_id'] = null;
            $fallback['school_name'] = '';
            $fallback['running_session'] = '';
            $fallback['class_name'] = '';
            $fallback['class_id'] = '';
            $fallback['section_name'] = '';
            $fallback['section_id'] = '';
            return $fallback;
        }

        if (! $enrol_data) {
            $enrol_data = new Enrollment();
        }

        $info = json_decode($student->user_information ?? '') ?: (object) [];

        $parent_details = User::find($student->parent_id);

        $role = Role::where('role_id', $student->role_id)->first();

        $class_details = !empty($enrol_data->class_id) ? Classes::find($enrol_data->class_id) : null;

        $section_details = !empty($enrol_data->section_id) ? Section::find($enrol_data->section_id) : null;

        $active_session = get_school_settings($student->school_id)->value('running_session');

        $school_name = School::find($student->school_id)?->title ?? '';

        //End Fetch


        $enrol_data['code'] = $student->code;
        $enrol_data['user_id'] = $id;
        $enrol_data['parent_name'] = $parent_details->name??"";
        $enrol_data['name'] = $student->name;
        $enrol_data['email'] = $student->email;

        $enrol_data['role'] = $role->name ?? '';

        $enrol_data['address'] = $info->address ?? '';
        $enrol_data['phone'] = $info->phone ?? '';
        $enrol_data['birthday'] = $info->birthday ?? '';
        $enrol_data['gender'] = $info->gender ?? '';
        $enrol_data['student_info']    = $student->student_info;
        $enrol_data['photo'] = isset($api) && $api == 'api' ? get_user_image_url($id): get_user_image($id);
        $enrol_data['school_id'] = $student->school_id;
        $enrol_data['school_name'] = $school_name;
        $enrol_data['running_session'] = $active_session;

        $enrol_data['class_name'] = $class_details->name ??"";
        $enrol_data['class_id'] = $class_details->id ??"";
        $enrol_data['section_name'] = $section_details->name ??"";
        $enrol_data['section_id'] = $section_details->id ??"";

        return $enrol_data;
    }

    /**
     * Shared profile-data lookup for Admin/Teacher/Accountant/Librarian/
     * Warden/Parent profile views. Always scoped to the authenticated
     * admin's own school — a cross-school id simply resolves to "not
     * found" here, same as the 404 the other staff actions return.
     */
    public function getAdminDetails($id)
    {
        $user_details = User::where('id', $id)->where('school_id', auth()->user()->school_id)->first();

        if (! $user_details) {
            return [
                'id'          => null,
                'name'        => '',
                'email'       => '',
                'address'     => '',
                'phone'       => '',
                'birthday'    => '',
                'gender'      => '',
                'photo'       => get_user_image($id),
                'school_id'   => null,
                'code'        => '',
                'first_name'  => '',
                'last_name'   => '',
                'department'  => '',
                'designation' => '',
                'employment_type' => '',
                'staff_status'    => '',
                'account_status'  => '',
            ];
        }

        $info = json_decode($user_details->user_information ?? '') ?: (object) [];

        $user_data['id'] = $user_details->id;
        $user_data['name'] = $user_details->name;
        $user_data['email'] = $user_details->email;

        $user_data['address'] = $info->address ?? '';
        $user_data['phone'] = $info->phone ?? '';
        $user_data['birthday'] = $info->birthday ?? '';
        $user_data['gender'] = $info->gender ?? '';
        $user_data['photo'] = get_user_image($id);
        $user_data['school_id'] = $user_details->school_id;
        $user_data['code'] = $user_details->code;
        $user_data['first_name'] = $user_details->first_name;
        $user_data['last_name'] = $user_details->last_name;
        $user_data['department'] = optional($user_details->department)->name;
        $user_data['designation'] = optional($user_details->designationRecord)->name;
        $user_data['employment_type'] = $user_details->employment_type;
        $user_data['staff_status'] = $user_details->staff_status;
        $user_data['account_status'] = $user_details->account_status;

        return $user_data;
    }


    /**
     * A class-based (K-12) student has an Enrollment row; a programme-based
     * (HEI) student never does and uses StudentProfile instead (see
     * resolve_student_academic_context() in CommonHelper.php, which
     * establishes the same split for a single-student lookup). This used to
     * assume every student has an Enrollment row and dereference it
     * unconditionally — a fatal "read property on null" for any HEI
     * student, in every caller (the admin/teacher/parent feedback lists and
     * the admin Students list all call this for each row they render).
     */
    public function get_student_academic_info($id = "")
    {
        $student = User::find($id);
        if (!$student) {
            return (object) [
                'parent_id' => null, 'code' => null, 'user_id' => $id, 'name' => null, 'email' => null,
                'class_name' => '', 'class_id' => '', 'section_name' => '', 'section_id' => '',
                'programme_name' => '', 'programme_id' => '',
            ];
        }

        $enrol_data = Enrollment::where('user_id', $id)->first();

        if ($enrol_data) {
            $class_details = Classes::find($enrol_data->class_id);
            $section_details = Section::find($enrol_data->section_id);

            $enrol_data['parent_id'] = $student->parent_id;
            $enrol_data['code'] = $student->code;
            $enrol_data['user_id'] = $id;
            $enrol_data['name'] = $student->name;
            $enrol_data['email'] = $student->email;

            $enrol_data['class_name'] = $class_details->name ?? "";
            $enrol_data['class_id'] = $class_details->id ?? "";
            $enrol_data['section_name'] = $section_details->name ?? "";
            $enrol_data['section_id'] = $section_details->id ?? "";
            $enrol_data['programme_name'] = "";
            $enrol_data['programme_id'] = "";

            return $enrol_data;
        }

        // No Enrollment row — try the programme-based (HEI) profile instead
        // of returning a row that looks like a deleted/removed class.
        $profile = StudentProfile::where('user_id', $id)->first();
        $programme = $profile && $profile->programme_id ? Programme::find($profile->programme_id) : null;

        return (object) [
            'parent_id' => $student->parent_id,
            'code' => $student->code,
            'user_id' => $id,
            'name' => $student->name,
            'email' => $student->email,
            'class_name' => '',
            'class_id' => '',
            'section_name' => '',
            'section_id' => '',
            'programme_name' => $programme->name ?? '',
            'programme_id' => $programme->id ?? '',
        ];
    }



    public function classWiseStudents($id = '')
    {
        $enrollments = Enrollment::where('class_id', $id)->where('school_id', auth()->user()->school_id)->get();
        $options = '<option value="">' . 'Select a student' . '</option>';
        foreach ($enrollments as $enrollment) :
            $student = User::find($enrollment->user_id);
            $options .= '<option value="' . $student->id . '">' . $student->name . '</option>';
        endforeach;
        echo $options;
    }

    public function classWiseSubject($id)
    {
        $subjects = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->exists() ? Subject::get()->where('class_id', $id) : collect();
        $options = '<option value="">' . 'Select a subject' . '</option>';
        foreach ($subjects as $subject) :
            $options .= '<option value="' . $subject->id . '">' . $subject->name . '</option>';
        endforeach;
        echo $options;
        // return view('admin.examination.add_offline_exam', ['subjects' => $subjects]);
    }

    public function classWiseSections($id)
    {
        $sections = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->exists() ? Section::get()->where('class_id', $id) : collect();
        $options = '<option value="">' . 'Select a section' . '</option>';
        foreach ($sections as $section) :
            $options .= '<option value="' . $section->id . '">' . $section->name . '</option>';
        endforeach;
        echo $options;
    }

    public function sectionWiseStudents($id)
    {
        $enrollments = Enrollment::where('section_id', $id)->where('school_id', auth()->user()->school_id)->get();
        $options = '<option value="">' . 'Select a student' . '</option>';
        foreach ($enrollments as $enrollment) :
            $student = User::find($enrollment->user_id);
            $options .= '<option value="' . $student->id . '">' . $student->name . '</option>';
        endforeach;
        echo $options;
    }

    public function studentWiseParent($id)
    {
        // Security Phase 2F: only a student in the caller's own school.
        $student = User::where('id', $id)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->firstOrFail();
        
        $parent_details = User::find($student->parent_id);

        $options = '<option value="">' . 'Select Parent' . '</option>';
        
            $options .= '<option value="' . $parent_details['id'] . '">' . $parent_details['name'] . '</option>';
        
        echo $options;
    }


    public function getGrade($acquired_mark = '')
    {
        echo get_grade($acquired_mark);
    }

    public function markUpdate(Request $request)
    {
        $data = $request->all();

        if (!empty($data['session_id'])) {
            $active_session  = $data['session_id'];
        } else {
            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        }

        $data['school_id'] = auth()->user()->school_id;
        $data['session_id'] = $active_session;

        $query = Gradebook::where('exam_category_id', $data['exam_category_id'])
            ->where('class_id', $data['class_id'])
            ->where('section_id', $data['section_id'])
            ->where('student_id', $data['student_id'])
            ->where('school_id', $data['school_id'])
            ->where('session_id', $data['session_id'])
            ->first();

        if (!empty($query) && $query->count() > 0) {

            $marks = json_decode($query->marks, true);
            $marks[$data['subject_id']] = $data['mark'];
            $query->marks = json_encode($marks);
            $query->comment = $data['comment'];
            $query->save();


        } else {
            $mark[$data['subject_id']] = $data['mark'];
            $marks = json_encode($mark);
            $data['marks'] = $marks;
            $data['timestamp'] = strtotime(date('Y-m-d'));
            Gradebook::create($data);
        }
    }

    public function get_user_by_id_from_user_table($id)
    {
        $user = User::find($id);

        return $user;
    }

    public function idWiseUserName($id='')
    {
        // Security Phase 2F: names are only resolved within the caller's own school.
        $result = User::where('id', $id)->where('school_id', auth()->user()->school_id)->value('name');
        return $result;
    }

    public function getClassDetails($id='')
    {
        $class_details = Classes::find($id);
        return $class_details;
    }

    public function getSectionDetails($id='')
    {
        $section_details = Section::find($id);
        return $section_details;
    }

    public function getSubjectDetails($id='')
    {
        $subject_details = Subject::find($id);
        return $subject_details;
    }

   

  

}
