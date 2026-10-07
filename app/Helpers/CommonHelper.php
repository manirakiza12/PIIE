<?php
use App\Models\PaymentMethods;
use App\Models\GlobalSettings;
use App\Models\User;
//All common helper functions
if (! function_exists('get_user_image')) {
    function get_user_image($file_name_or_user_id = '') {
        if(is_numeric($file_name_or_user_id)){
            $user_id = $file_name_or_user_id;
            $file_name = "";
        }else{
            $user_id = "";
            $file_name = $file_name_or_user_id;
        }

        if($user_id > 0){
            $user_id = $file_name_or_user_id;
            $user_information = DB::table('users')->where('id', $user_id)->value('user_information');

            $decoded_info = json_decode($user_information);
            $file_name = (!empty($decoded_info) && !empty($decoded_info->photo)) ? $decoded_info->photo : '';

            if(!empty($file_name) && file_exists( public_path().'/assets/uploads/user-images/'.$file_name ) && is_file(public_path().'/assets/uploads/user-images/'.$file_name)){
                return asset('assets/uploads/user-images/'.$file_name);
            }else{
                return asset('assets/uploads/user-images/thumbnail.png');
            }
        }elseif(File::exists('public/assets/uploads/user-images/'.$file_name)){
            return asset('assets/uploads/user-images/'.$file_name);
        }else{
            return asset('assets/uploads/user-images/thumbnail.png');
        }
    }
}

if (!function_exists('addon_status')) {
    function addon_status($unique_identifier = '')
    {
        $result =  DB::table('addons')->where('unique_identifier', $unique_identifier)->value('status');
        return $result;
    }
}

if (! function_exists('phrase')) {
    function phrase($string = '') {
        return $string;
    }
}

if ( ! function_exists('get_all_language'))
{
    function get_all_language(){
        return DB::table('language')->select('name')->distinct()->get();
    }
}

if ( ! function_exists('get_phrase'))
{
    function get_phrase($phrase = '') {
        if(isset(auth()->user()->id)) {
            $active_language = User::where('id', auth()->user()->id)->value('language');
        } else {
            $active_language = get_settings('language');
        }
    

        $query = DB::table('language')->where('name', $active_language)->where('phrase', $phrase);
        if($query->get()->count() == 0){
            $translated = $phrase;

            $all_language = get_all_language();

            if($all_language->count() > 0){
                foreach($all_language as $language){

                    if(DB::table('language')->where('name', $language->name)->where('phrase', $phrase)->get()->count() == 0){
                        DB::table('language')->insert(array('name' => $language->name, 'phrase' => $phrase, 'translated' => $translated));
                    }
                }
            }else{
                DB::table('language')->insert(array('name' => 'english', 'phrase' => $phrase, 'translated' => $translated));
            }
            return $translated;
        }
        return $query->value('translated');
    }
}

if (! function_exists('remove_js')) {
    function remove_js($string = '') {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $string);
    }
}

if (!function_exists('date_formatter')) {
    function date_formatter($strtotime = "", $format = "")
    {
        if ($format == "") {
            return date('d', $strtotime) . ' ' . date('M', $strtotime) . ' ' . date('Y', $strtotime);
        }

        if ($format == 1) {
            return date('D', $strtotime) . ', ' . date('d', $strtotime) . ' ' . date('M', $strtotime) . ' ' . date('Y', $strtotime);
        }

        if($format == 2){
        	$time_difference = time() - $strtotime;
	        if( $time_difference < 1 ) { return 'less than 1 second ago'; }
	        //864000 = 10 days
	        if($time_difference > 864000){ return dateFormatter($strtotime, 1); }

	        $condition = array(
	        	12 * 30 * 24 * 60 * 60	=> 'year',
	        	30 * 24 * 60 * 60		=>  'month',
	        	24 * 60 * 60            =>  'day',
	        	60 * 60                 =>  'hour',
	        	60                      =>  'minute',
	        	1                       =>  'second'
	        );

	        foreach( $condition as $secs => $str ){
	            $d = $time_difference / $secs;
	            if( $d >= 1 ){
	                $t = round( $d );
	                return $t . ' ' . $str . ( $t > 1 ? 's' : '' ) . ' ago';
	            }
	        }
        }
    }
}

if (!function_exists('currency')) {
    function currency($price = "")
    {
        $symbol = DB::table('global_settings')->where('key', 'system_currency')->value('value');
        if(!empty($price)){
            return $price.' '.$symbol;
        } else {
            return $symbol;
        }
    }
}

if (!function_exists('school_currency')) {
    function school_currency($price = "")
    {
        $symbol = DB::table('schools')->where('id', auth()->user()->school_id)->value('school_currency');
        if(!empty($price)){
            return $price.' '.$symbol;
        } else {
            return $symbol;
        }
    }
}


if (!function_exists('slugify')) {
    function slugify($string)
    {
        $string = preg_replace('~[^\\pL\d]+~u', '-', $string);
        $string = trim($string, '-');
        return strtolower($string);
    }
}

if (!function_exists('get_video_extension')) {
    function get_video_extension($url)
    {
        if (strpos($url, '.mp4') > 0) {
            return 'mp4';
        } elseif (strpos($url, '.webm') > 0) {
            return 'webm';
        } else {
            return 'unknown';
        }
    }
}

if (!function_exists('ellipsis')) {
    function ellipsis($long_string, $max_character = 30)
    {
        $short_string = strlen($long_string) > $max_character ? mb_substr($long_string, 0, $max_character) . "..." : $long_string;
        return $short_string;
    }
}


// Global Settings
if (!function_exists('get_settings')) {
    function get_settings($key = '', $type='')
    {
        $global_settings = DB::table('global_settings')->where('key', $key)->value('value');

        if($type == 'json') {
            $global_settings = json_decode($global_settings);
        }

        return $global_settings;
    }
}

// School Settings
if (!function_exists('get_school_settings')) {
    function get_school_settings($school_id = '')
    {
        $running_session = DB::table('schools')->where('id', $school_id)->get();

        return $running_session;
    }
}

// Config Settings
if (!function_exists('set_config')) {
    function set_config($key = '', $value='')
    {
        $config = json_decode(file_get_contents(base_path('config/config.json')), true);

        $config[$key] = $value;

        file_put_contents(base_path('config/config.json'), json_encode($config));
    }
}

// Human readable time
if (!function_exists('time_formatter')) {
    function time_formatter($duration, $format = "")
    {
        if ($duration && $format == "") {
            $duration_array = explode(':', $duration);
            $hour   = $duration_array[0];
            $minute = $duration_array[1];
            $second = $duration_array[2];
            if ($hour > 0) {
                $duration = $hour . ' ' . 'hr' . ' ' . $minute . ' ' . 'min';
            } elseif ($minute > 0) {
                if ($second > 0) {
                    $duration = ($minute + 1) . ' ' . 'min';
                } else {
                    $duration = $minute . ' ' . 'min';
                }
            } elseif ($second > 0) {
                $duration = $second . ' ' . 'sec';
            } else {
                $duration = '00:00';
            }
        } elseif($seconds && $format == 'seconds_to_format') {
            $hours = floor($seconds / 3600);
            $mins = floor($seconds / 60 % 60);
            $secs = floor($seconds % 60);

            $duration = sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        } elseif($seconds && $format == 'format_to_seconds') {

        }
        return $duration;
    }
}

// RANDOM NUMBER GENERATOR FOR ELSEWHERE
if (!function_exists('random')) {
    function random($length_of_string, $lowercase = false)
    {
        // String of all alphanumeric character
        $str_result = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

        // Shufle the $str_result and returns substring
        // of specified length
        $randVal = substr(str_shuffle($str_result), 0, $length_of_string);
        if($lowercase){
        	$randVal = strtolower($randVal);
        }
        return $randVal;
    }
}

// RANDOM NUMBER GENERATOR FOR STUDENT CODE (registration number)
// The authoritative source of truth for a student's registration number is
// users.code — this generator retries until it produces a value not already
// present there, so registration numbers can never collide.
if (! function_exists('student_code')) {
  function student_code($length_of_string = 8) {
    $running_year = date('Y');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $str_result = '0123456789';
        $unique_id = substr(str_shuffle($str_result), 0, $length_of_string);
        $splited_unique_id = str_split($unique_id, 4);
        $student_code = $running_year.'-'.$splited_unique_id[0].'-'.$splited_unique_id[1];

        if (! \App\Models\User::where('code', $student_code)->exists()) {
            return $student_code;
        }
    }

    // Exhausted the random attempts (astronomically unlikely) — fall back to
    // a sequential suffix so a registration number is still always returned.
    return $running_year.'-'.str_pad((string) \App\Models\User::where('code', 'like', $running_year.'-%')->count() + 1, 8, '0', STR_PAD_LEFT);
  }
}

// RANDOM NUMBER GENERATOR FOR STAFF NUMBER (employee number)
// Same mechanism as student_code() (retries against the shared users.code
// column so a staff number can never collide with anyone else's, staff or
// student), just with an "STF-" prefix so the two are visually distinct.
if (! function_exists('staff_code')) {
  function staff_code($length_of_string = 8) {
    $running_year = date('Y');

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $str_result = '0123456789';
        $unique_id = substr(str_shuffle($str_result), 0, $length_of_string);
        $splited_unique_id = str_split($unique_id, 4);
        $staff_code = 'STF-'.$running_year.'-'.$splited_unique_id[0].'-'.$splited_unique_id[1];

        if (! \App\Models\User::where('code', $staff_code)->exists()) {
            return $staff_code;
        }
    }

    return 'STF-'.$running_year.'-'.str_pad((string) \App\Models\User::where('code', 'like', 'STF-'.$running_year.'-%')->count() + 1, 8, '0', STR_PAD_LEFT);
  }
}

// TEACHER PERMISSION. PROVIDE MODULE NAME AND TEACHERS ID
if (! function_exists('null_checker')) {
  function null_checker($value = "") {
    if (trim($value, "") == "") {
      return '('.get_phrase('Not found').')';
    }else{
      return $value;
    }
  }
}

// GET GRADE
if ( ! function_exists('get_grade'))
{
  function get_grade($acquired_number = "", $type = "") {
    if (empty($acquired_number)) {
      return "N/A";
    }else{
      $acquired_grade = DB::table('grades')->where('school_id', auth()->user()->school_id)->distinct()->get();
      if ($acquired_grade->count() > 0) {
        $founder = false;
        foreach ($acquired_grade as $grade) {
          if ($acquired_number >= $grade->mark_from && $acquired_number <= $grade->mark_upto) {
            $founder = true;
            if (!empty($type)) {
              return $grade[$type];
            }else{
              return $grade->name.'('.$grade->grade_point.')';
            }
          }
        }
        if(!$founder){
          return "N/A";
        }
      }else{
        return "N/A";
      }
    }
  }
}

if (!function_exists('get_active_currency')) {
    function get_active_currency()
    {
        return GlobalSettings::where('key', 'system_currency')->value('value') ?: 'USD';
    }
}

if (!function_exists('relogin_user')) {
    function relogin_user($user_id='')
    {
        $user = User::find($user_id);
        Auth::login($user);

    }
}



if (!function_exists('get_payment_keys')) {
    function get_payment_keys($payment_method = '',$returnItem='')
    {
        $return_value = [];
        $global_system_currency = get_active_currency();

        if ($payment_method == "flutterwave") {
            $flutterwave = \App\Models\GlobalSettings::where('key', 'flutterwave')->first();
            if (!$flutterwave || empty($flutterwave->value)) {
                return null;
            }

            $flutterwave_keys = json_decode($flutterwave->value);
            if (!$flutterwave_keys) {
                return null;
            }

            $return_value['status'] = $flutterwave_keys->status ?? null;
            $return_value['mode'] = $flutterwave_keys->mode ?? null;
            $return_value['test_key'] = $flutterwave_keys->test_key ?? null;
            $return_value['test_secret_key'] = $flutterwave_keys->test_secret_key ?? null;
            $return_value['public_live_key'] = $flutterwave_keys->public_live_key ?? null;
            $return_value['secret_live_key'] = $flutterwave_keys->secret_live_key ?? null;
            $return_value['currency'] = $global_system_currency;

            return $returnItem !== '' ? ($return_value[$returnItem] ?? null) : $return_value;
        }

        if ($payment_method == "stripe") {
            $stripe = PaymentMethods::where('name', 'stripe')->first();
            if (!$stripe) {
                return null;
            }

            $stripe_keys = json_decode($stripe['payment_keys']);
            if (!$stripe_keys) {
                return null;
            }

            if ($stripe['mode'] == "test") {
                $return_value['test_key'] = $stripe_keys->test_key ?? null;
                $return_value['test_secret_key'] = $stripe_keys->test_secret_key ?? null;
                $return_value['currency'] = $global_system_currency;
                return $return_value[$returnItem] ?? null;
            } elseif ($stripe['mode'] == "live") {
                $return_value['public_live_key'] = $stripe_keys->public_live_key ?? null;
                $return_value['secret_live_key'] = $stripe_keys->secret_live_key ?? null;
                $return_value['currency'] = $global_system_currency;
                return $return_value[$returnItem] ?? null;
            }
        }


        if ($payment_method == "paytm") {
            $paytm = PaymentMethods::where('name', 'paytm')->first();
            if (!$paytm) {
                return null;
            }

            $paytm_keys = json_decode($paytm['payment_keys']);
            if (!$paytm_keys) {
                return null;
            }

            if ($paytm['mode'] == "test") {
                $return_value['environment'] = $paytm_keys->environment ?? null;
                $return_value['merchant_id'] = $paytm_keys->test_merchant_id ?? null;
                $return_value['merchant_key'] = $paytm_keys->test_merchant_key ?? null;
                $return_value['merchant_website'] = $paytm_keys->merchant_website ?? null;
                $return_value['channel'] = $paytm_keys->channel ?? null;
                $return_value['industry_type'] = $paytm_keys->industry_type ?? null;
                $return_value['currency'] = $global_system_currency;
                return $return_value[$returnItem] ?? null;

            } elseif ($paytm['mode'] == "live") {
                $return_value['environment'] = $paytm_keys->environment ?? null;
                $return_value['merchant_id'] = $paytm_keys->live_merchant_id ?? null;
                $return_value['merchant_key'] = $paytm_keys->live_merchant_key ?? null;
                $return_value['merchant_website'] = $paytm_keys->merchant_website ?? null;
                $return_value['channel'] = $paytm_keys->channel ?? null;
                $return_value['industry_type'] = $paytm_keys->industry_type ?? null;
                $return_value['currency'] = $global_system_currency;
                return $return_value[$returnItem] ?? null;

            }
        }

        return null;
    }
}

if (!function_exists('user_name')) {
    function user_name($id = '')
    {
        $result =  DB::table('users')->where('id', $id)->value('name');
        return $result;
    }
}


if (!function_exists('class_name')) {
    function class_name($id = '', $school_id="")
    {
        $result =  DB::table('classes')->where('id', $id)->where('school_id', $school_id)->value('name');
        return $result;
    }
}

//school status check
if (!function_exists('school_status_check')) {
    function school_status_check($email = '')
    {
        $school_id =  DB::table('users')->where('email', $email)->value('school_id');
        $status =  DB::table('schools')->where('id', $school_id)->value('status');

        return $status;
    }
}

//user role check
if (!function_exists('user_role_check')) {
    function user_role_check($email = '')
    {
        $role_id =  DB::table('users')->where('email', $email)->value('role_id');

        return $role_id;
    }
}

//subscription check
if (!function_exists('subscription_check')) {
    function subscription_check($school_id = '')
    {
        $expire_date =  DB::table('subscriptions')->where('school_id', $school_id)->orderBy('id', 'DESC')->value('expire_date');

        $current_date = strtotime(date("Y-m-d H:i:s"));

        if($expire_date > $current_date){
            return true;
        } else {
            return false;
        }
    }
}

if (!function_exists('get_role_nav_permissions')) {
    /**
     * Returns the list of nav section keys this role is allowed to see.
     * 'all' means no filtering (show everything).
     */
    function get_role_nav_permissions(int $role_id): array
    {
        $map = [
            1  => ['all'], // Super Admin
            2  => ['all'], // Institution Admin
            3  => ['dashboard', 'students', 'attendance', 'assignments', 'online_exams', 'live_classes',
                   'gradebook', 'routine', 'noticeboard', 'chat', 'academic_calendar', 'question_bank'],
            4  => ['dashboard', 'fees', 'expenses', 'payments', 'payroll', 'salary_structures',
                   'fee_structures', 'hostel_fee', 'reports'],
            5  => ['dashboard', 'library', 'noticeboard', 'chat'],
            10 => ['dashboard', 'students', 'admissions', 'hei_admissions', 'intake_sessions',
                   'admissions_agents', 'programmes', 'enrolment', 'transcripts', 'graduation',
                   'noticeboard', 'reports'],
            11 => ['dashboard', 'fees', 'expenses', 'payments', 'payroll', 'fee_structures',
                   'hostel_fee', 'reports', 'noticeboard'],
            12 => ['dashboard', 'students', 'attendance', 'assignments', 'online_exams',
                   'gradebook', 'question_bank', 'routine', 'academic_calendar',
                   'departments', 'noticeboard', 'chat', 'reports'],
            13 => ['dashboard', 'students', 'admissions', 'hei_admissions', 'intake_sessions',
                   'admissions_agents', 'programmes', 'noticeboard', 'chat'],
            14 => ['dashboard', 'reports', 'transcripts', 'graduation', 'programmes',
                   'students', 'staff', 'payroll', 'expenses', 'procurement', 'assets',
                   'noticeboard', 'settings'],
            15 => ['dashboard', 'staff', 'leave', 'leave_types', 'appraisal', 'payroll',
                   'salary_structures', 'attendance', 'departments', 'noticeboard', 'reports'],
            16 => ['dashboard', 'procurement', 'assets', 'asset_categories', 'noticeboard'],
            17 => ['dashboard', 'assets', 'asset_categories', 'inventory', 'noticeboard'],
            18 => ['dashboard', 'students', 'fees', 'payments', 'admissions',
                   'noticeboard', 'chat'],
            19 => ['dashboard', 'online_exams', 'exams', 'question_bank', 'gradebook',
                   'transcripts', 'results', 'noticeboard'],
        ];

        return $map[$role_id] ?? [];
    }
}

if (!function_exists('role_can_see')) {
    /**
     * Quick check: can the given role see a navigation section?
     */
    function role_can_see(int $role_id, string $section): bool
    {
        $perms = get_role_nav_permissions($role_id);
        return in_array('all', $perms) || in_array($section, $perms);
    }
}

if (!function_exists('is_primary_school')) {
    /**
     * The Admissions module (Admissions, Intake Sessions, Agents) is only
     * meaningful for the one school the public Apply Now portal currently
     * belongs to (global_settings.primary_school_id — see
     * App\Support\PublicTenantResolver). Other schools created via Super
     * Admin have no public application intake yet, so the module is hidden
     * from their nav and blocked at the route level rather than shown
     * empty and useless.
     */
    function is_primary_school($schoolId): bool
    {
        $primarySchoolId = get_settings('primary_school_id');

        if (empty($primarySchoolId)) {
            // Not configured yet — fail open rather than lock everyone out.
            return true;
        }

        return (int) $schoolId === (int) $primarySchoolId;
    }
}

if (!function_exists('resolve_student_academic_context')) {
    /**
     * The one shared way to answer "what class/programme is this student
     * currently in" — generalizes the branching already used ad-hoc in
     * TranscriptController::buildTranscriptViewData(). A K-12 (class-based)
     * student has an Enrollment row (class_id/section_id/session_id); a
     * programme-based (HEI) student has no Enrollment row at all and uses
     * StudentProfile (programme_id/intake_session_id) instead. The two are
     * fully disjoint — a student is one or the other, never both.
     *
     * Every module that needs "this student's current class/programme"
     * should call this rather than re-querying Enrollment directly with no
     * StudentProfile fallback — that ad-hoc pattern, with varying (and
     * sometimes missing) null-safety, was found duplicated across
     * CommonController, AssignmentController, OnlineExamController,
     * ParentController and others during the Phase 0 audit. This helper
     * does not change any of those existing call sites by itself — modules
     * are migrated to it individually, one at a time, in later phases.
     *
     * Returns an array shaped:
     *   mode              'class' | 'programme' | null (no academic record at all)
     *   class_id          int|null
     *   section_id        int|null
     *   session_id        int|null
     *   programme_id      int|null
     *   intake_session_id int|null
     */
    function resolve_student_academic_context(int $userId, int $schoolId): array
    {
        $context = [
            'mode' => null,
            'class_id' => null,
            'section_id' => null,
            'session_id' => null,
            'programme_id' => null,
            'intake_session_id' => null,
        ];

        $enrollment = \App\Models\Enrollment::where('user_id', $userId)
            ->where('school_id', $schoolId)
            ->latest()
            ->first();

        if ($enrollment && $enrollment->class_id) {
            $context['mode'] = 'class';
            $context['class_id'] = $enrollment->class_id;
            $context['section_id'] = $enrollment->section_id;
            $context['session_id'] = $enrollment->session_id;

            return $context;
        }

        $studentProfile = \App\Models\StudentProfile::where('user_id', $userId)
            ->where('school_id', $schoolId)
            ->first();

        if ($studentProfile && $studentProfile->programme_id) {
            $context['mode'] = 'programme';
            $context['programme_id'] = $studentProfile->programme_id;
            $context['intake_session_id'] = $studentProfile->intake_session_id;
        }

        return $context;
    }
}

if (!function_exists('academic_education_level')) {
    /**
     * Return the institution's configured education level for contextual
     * academic language. education_level is preferred when present; the
     * legacy school_type remains the structural fallback.
     */
    function academic_education_level(?int $schoolId = null): string
    {
        $schoolId = $schoolId ?: (int) (auth()->user()->school_id ?? 0);

        if (!$schoolId || !\Illuminate\Support\Facades\Schema::hasTable('schools')) {
            return 'secondary';
        }

        $columns = ['id'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('schools', 'education_level')) {
            $columns[] = 'education_level';
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('schools', 'school_type')) {
            $columns[] = 'school_type';
        }
        $school = \Illuminate\Support\Facades\DB::table('schools')->where('id', $schoolId)->first($columns);
        $level = strtolower(trim((string) ($school->education_level ?? '')));
        if (!in_array($level, ['primary', 'secondary', 'tertiary', 'vocational', 'mixed'], true)) {
            $level = match (strtolower((string) ($school->school_type ?? 'k12'))) {
                'higher_ed' => 'tertiary',
                'mixed' => 'mixed',
                default => 'secondary',
            };
        }

        return $level;
    }
}

if (!function_exists('academic_term')) {
    /**
     * Resolve user-facing academic terminology without changing database
     * names, routes, or model names.
     */
    function academic_term(string $term, ?int $schoolId = null): string
    {
        $level = academic_education_level($schoolId);
        $matrix = [
            'primary' => [
                'teacher' => 'Teacher', 'teachers' => 'Teachers',
                'class' => 'Class', 'classes' => 'Classes',
                'section' => 'Section / Stream', 'sections' => 'Sections / Streams',
                'subject' => 'Subject', 'subjects' => 'Subjects',
                'programme' => 'Programme', 'programmes' => 'Programmes',
                'department' => 'Department', 'departments' => 'Departments',
                'session' => 'Academic Term', 'term' => 'Term',
                'course' => 'Course', 'enrollment' => 'Enrollment',
                'teacher_assignment' => 'Teacher Assignments', 'marks' => 'Marks',
                'exam' => 'Exam', 'assignment' => 'Assignment',
            ],
            'secondary' => [
                'teacher' => 'Teacher', 'teachers' => 'Teachers',
                'class' => 'Class', 'classes' => 'Classes',
                'section' => 'Section / Stream', 'sections' => 'Sections / Streams',
                'subject' => 'Subject', 'subjects' => 'Subjects',
                'programme' => 'Programme', 'programmes' => 'Programmes',
                'department' => 'Department', 'departments' => 'Departments',
                'session' => 'Academic Session', 'term' => 'Term',
                'course' => 'Course', 'enrollment' => 'Enrollment',
                'teacher_assignment' => 'Teacher Assignments', 'marks' => 'Marks',
                'exam' => 'Exam', 'assignment' => 'Assignment',
            ],
            'tertiary' => [
                'teacher' => 'Lecturer', 'teachers' => 'Lecturers',
                'class' => 'Class', 'classes' => 'Classes',
                'section' => 'Group', 'sections' => 'Groups',
                'subject' => 'Course Unit', 'subjects' => 'Course Units',
                'programme' => 'Programme', 'programmes' => 'Programmes',
                'department' => 'Department', 'departments' => 'Departments',
                'session' => 'Academic Semester', 'term' => 'Semester',
                'course' => 'Course Unit', 'enrollment' => 'Enrollment',
                'teacher_assignment' => 'Lecturer Assignments', 'marks' => 'Marks',
                'exam' => 'Exam', 'assignment' => 'Assignment',
            ],
            'vocational' => [
                'teacher' => 'Instructor', 'teachers' => 'Instructors',
                'class' => 'Class', 'classes' => 'Classes',
                'section' => 'Group', 'sections' => 'Groups',
                'subject' => 'Course Unit', 'subjects' => 'Course Units',
                'programme' => 'Programme', 'programmes' => 'Programmes',
                'department' => 'Department', 'departments' => 'Departments',
                'session' => 'Academic Term', 'term' => 'Term',
                'course' => 'Course Unit', 'enrollment' => 'Enrollment',
                'teacher_assignment' => 'Instructor Assignments', 'marks' => 'Marks',
                'exam' => 'Exam', 'assignment' => 'Assignment',
            ],
            'mixed' => [
                'teacher' => 'Teacher / Lecturer', 'teachers' => 'Teachers / Lecturers',
                'class' => 'Class', 'classes' => 'Classes',
                'section' => 'Section / Group', 'sections' => 'Sections / Groups',
                'subject' => 'Subject / Course Unit', 'subjects' => 'Subjects / Course Units',
                'programme' => 'Programme', 'programmes' => 'Programmes',
                'department' => 'Department', 'departments' => 'Departments',
                'session' => 'Academic Session / Semester', 'term' => 'Term / Semester',
                'course' => 'Course / Course Unit', 'enrollment' => 'Enrollment',
                'teacher_assignment' => 'Teacher / Lecturer Assignments', 'marks' => 'Marks',
                'exam' => 'Exam', 'assignment' => 'Assignment',
            ],
        ];

        return get_phrase($matrix[$level][$term] ?? ucfirst(str_replace('_', ' ', $term)));
    }
}
