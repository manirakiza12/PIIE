<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonController;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Session;
use App\Models\School;
use App\Models\Addon;
use App\Models\Subscription;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Classes;
use App\Models\Subject;
use App\Models\Gradebook;
use App\Models\Grade;
use App\Models\Department;
use App\Models\ClassRoom;
use App\Models\ClassList;
use App\Models\Section;
use App\Models\Enrollment;
use App\Models\DailyAttendances;
use App\Models\Routine;
use App\Models\Syllabus;
use App\Models\ExpenseCategory;
use App\Models\Expense;
use App\Models\StudentFeeManager;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\Noticeboard;
use App\Models\Package;
use App\Models\PaymentHistory;
use App\Models\GlobalSettings;
use App\Models\Currency;
use App\Models\PaymentMethods;
use App\Models\Language;
use App\Models\Faq;
use App\Models\FrontendFeature;
use Mail;
use App\Mail\SchoolEmail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema;
use  Omnipay\Omnipay;
use Illuminate\Support\Str;
use Razorpay\Api\Api;
use Illuminate\Support\Facades\Auth;
use Stripe, DB;
use PaytmWallet;
use File;
use App\Mail\SuperAdminAproved;
use App\Mail\PlatformMailTestMessage;
use App\Support\Mail\PlatformSmtpConfiguration;
use App\Support\Mail\SmtpPasswordSecret;
use App\Support\ProfilePhoto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Support\TenantConfiguration;

class SuperAdminController extends Controller
{
    /**
     * Show the superadmin dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    private $publicly_user_id;
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(function ($request, $next) {
            $this->id = Auth::user()->id;
            $this->publicly_user_id = $this->id;
            $this->school_id = Auth::user()->school_id;

    
            return $next($request);
        });
    }


    public function superadminDashboard()
    {
        return view('superadmin.dashboard');
    }

    /**
     * Show the school list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function schoolList(Request $request)
    {
        $search = $request['search'] ?? "";

        if($search != "") {

            $schools = School::where(function ($query) use($search) {
                    $query->where('title', 'LIKE', "%{$search}%");
                })->paginate(10);

        } else {
            $schools = School::paginate(10);
        }

        return view('superadmin.school.list', compact('schools', 'search'));
    }

    public function editSchool($id)
    {
        $school = School::findOrFail($id);
        return view('superadmin.school.edit_school', [
            'school' => $school,
            'currencies' => Currency::all(),
            'countryCodes' => config('tenant.country_codes'),
            // Every identifier PHP supports, grouped by region. The value
            // stored is the IANA identifier; the label a person reads.
            'timezoneOptions' => app(\App\Support\TenantTimezone::class)->groupedOptions(),
        ]);
    }

    public function schoolUpdate(Request $request, $id)
    {
        $school = School::findOrFail($id);
        $rules = array_merge(TenantConfiguration::configurationRules(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'phone' => ['sometimes', 'required', 'integer'],
            'school_info' => ['sometimes', 'required', 'string'],
            'school_currency' => ['nullable', 'string', 'max:20'],
            'currency_position' => ['nullable', 'in:left,right,left-space,right-space'],
            // Required here, on the one screen an administrator uses to set it.
            // Left nullable everywhere else so no other write path is forced
            // to supply it, and so a tenant created before this field existed
            // stays editable.
            'timezone' => ['required', 'timezone'],
        ]);
        $validated = $request->validate($rules, [
            'timezone.required' => get_phrase('Please choose your institution timezone.'),
            'timezone.timezone' => get_phrase('That is not a recognised timezone.'),
        ]);

        // Update only fields intentionally exposed by this school form. In particular,
        // request data cannot alter running_session, school_id, or arbitrary columns.
        $school->fill(TenantConfiguration::schoolUpdateAttributes($validated))->save();

        return redirect()->back()->with('message', 'You have successfully update school.');
    }

    public function schoolAdd()
    {
        return view('superadmin.school.add_school', [
            'currencies' => Currency::all(),
            'countryCodes' => config('tenant.country_codes'),
        ]);
    }

    public function createSchool(Request $request)
    {
        // Uploads are checked before anything is created (a failed/invalid file must not leave a half-created school).
        $request->validate(array_merge(TenantConfiguration::configurationRules(), [
            'school_name' => ['required', 'string', 'max:255'],
            'school_email' => ['required', 'email', 'max:255'],
            'school_phone' => ['required', 'integer'],
            'school_address' => ['required', 'string', 'max:500'],
            'school_info' => ['required', 'string'],
            'school_currency' => ['nullable', 'string', 'max:20'],
            'currency_position' => ['nullable', 'in:left,right,left-space,right-space'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8'],
            'school_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'],
            'photo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:4096'],
        ]));
        $data = $request->all();
        $school_email = $data['school_email'];
        $admin_email = $data['admin_email'];
        $duplicate_school_email_check = School::get()->where('email', $school_email);
        $duplicate_admin_email_check = User::get()->where('email', $admin_email);

        if(count($duplicate_school_email_check) == 0 && count($duplicate_admin_email_check) == 0) {
        $school = School::create([
            'title' => $data['school_name'],
            'email' => $data['school_email'],
            'phone' => $data['school_phone'],
            'address' => $data['school_address'],
            'school_info' => $data['school_info'],
            'status' => '2',
            'education_level' => $data['education_level'] ?? null,
            'school_type' => $data['school_type'] ?? 'k12',
            'primary_locale' => $data['primary_locale'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'academic_calendar_pattern' => $data['academic_calendar_pattern'] ?? (($data['school_type'] ?? 'k12') === 'higher_ed' ? 'semester' : 'term'),
            'school_currency' => $data['school_currency'] ?? null,
            'currency_position' => $data['currency_position'] ?? null,
        ]);
        
        if($request->school_logo){
            $ext = $request->school_logo->extension();   // from the validated content, not the client's file name
            $newFileName = time().'.'.$ext;
            $request->school_logo->move(public_path('assets/uploads/school_logo'),$newFileName); // This will save file in a folder.  
            $school->school_logo =$newFileName;
            $school->save();
        }  
        
        if (isset($school->id) && $school->id != "") {

            $data['status'] = '1';
            $data['session_title'] = date("Y");
            $data['school_id'] = $school->id;

            $session = Session::create($data);

            School::where('id', $school->id)->update([
                'running_session' => $session->id,
            ]);
            
            if (!empty($data['photo'])) {

                $imageName = time() . '.' . $data['photo']->extension();

                $data['photo']->move(public_path('assets/uploads/user-images/'), $imageName);

                $photo  = $imageName;
            } else {
                $photo = '';
            }

            $info = array(
                'gender' => $data['gender'],
                'blood_group' => $data['blood_group'],
                'birthday' => isset($data['birthday']) ? strtotime($data['birthday']) : time(),
                'phone' => $data['admin_phone'],
                'address' => $data['admin_address'],
                'photo' => $photo
            );
            $data['user_information'] = json_encode($info);
            User::create([
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']),
                'role_id' => '2',
                'school_id' => $school->id,
                'user_information' => $data['user_information'],
                'status' => 1,
                'school_role' => 1,
                
            ]);
        }
        if(!empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))){
            \App\Support\Mail\SafeMail::send($data['admin_email'], new SchoolEmail($data), 'school-registration');
        }
            return redirect()->back()->with('message','School created successfully');
        } else {
            return redirect()->back()->with('warning','Some of the emails have been taken.');
        }

    }

    public function schoolStatusUpdate($id='', $status='')
    {
        $school = School::find($id);
        School::where('id', $id)->update([
            'status' => $status,
        ]);
        return redirect()->back()->with('message', 'School status updated successfully.');
    }
    public function adminList($id){
        $admins = User::where('role_id', 2)->where('school_id', $id)->get();
        $data_id = $id;

        return view('superadmin.school.admin_list', compact('admins','data_id'));
    }

    // School Admin password change
    function admin_password(Request $request){

        $userId = $request->input('user_id');

        $data['password'] = Hash::make($request->password);
            User::where('id', $userId)->update($data);

            return redirect()->back()->with('message', 'You have successfully update password.');
    }

    /**
     * Show the package list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function superadminPackage(Request $request)
    {
        $page_data['search'] = $request['search'] ?? "";

        if($page_data['search'] != "") {

            $query = Package::query();

            $page_data['active_packages'] = $query->where('name', 'LIKE', "%{$page_data['search']}%")
                                                ->where('status', '1')
                                                ->paginate(10);

            $page_data['archive_packages'] = $query->where('name', 'LIKE', "%{$page_data['search']}%")
                                                ->where('status', '0')
                                                ->paginate(10);

        } else {

            $page_data['active_packages'] = Package::where('status', '1')->paginate(10);

            $page_data['archive_packages'] = Package::where('status', '0')->paginate(10);
        }

        return view('superadmin.package.package', $page_data);
    }

    public function createPackage()
    {
        return view('superadmin.package.add_package');
    }


    public function packageCreate(Request $request)
    {
        $data = $request->all();

        $data['features'] = json_encode(array_filter($request->features));
        $data['days'] = $data['days'] ?? 0;

        $interval = Package::where('interval', 'life_time')->first();
        
       if ($interval && $request->interval == 'life_time') {
        return redirect()->back()->with('error', 'You cannot create life-time package second time');
       }else{
           Package::create($data);
       }


        return redirect()->back()->with('message', 'You have successfully create a package.');
    }

    public function editPackage($id)
    {
        $package = Package::find($id);
        return view('superadmin.package.edit_package', ['package' => $package]);
    }

    public function packageUpdate(Request $request, $id)
    {  
        $data = $request->all();

        unset($data['_token']);
        $data['days'] = $data['days'] ?? 0;
        $package = Package::find($id);

        $interval = $package->interval;
        
       if ($interval && $request->interval == 'life_time') {
        return redirect()->back()->with('error', 'You cannot create life-time package second time');
       }else{

       Package::where('id', $id)->update($data);

       if ($interval == 'life_time') {
        $test = Package::where('id', $id)->update(['days' => '', ]);
       }

        return redirect()->back()->with('message', 'You have successfully update package.');
        }   
    }

    public function packageDelete($id)
    {
        $check_subscription = Subscription::where('package_id', $id)->get();

        $check_history = PaymentHistory::where('package_id', $id)->get();

        if(count($check_subscription) > 0){
            return redirect()->back()->with('warning', 'This Package can not be deleted because package is subscripbed by a school.');
        } else if(count($check_history) > 0){
            return redirect()->back()->with('warning', 'This Package can not be deleted because package is subscripbed by a school.');
        } else {

            $package = Package::find($id);
            $package->delete();
            return redirect()->back()->with('message', 'You have successfully delete a package.');
        }
    }

    /**
     * Show the subscription.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function subscriptionReport(Request $request)
    {
        $date_from = strtotime('first day of january this year');
        $date_to = strtotime('last day of december this year');
        $subscriptions = Subscription::where('date_added', '>=', $date_from)
            ->where('date_added', '<=', $date_to)
            ->paginate(10);

        return view('superadmin.subscription.subscription_report', compact('subscriptions', 'date_from', 'date_to'));
    }

    public function subscriptionFilterReport(Request $request)
    {
        $data = $request->all();
        $date = explode('-', $data['eDateRange']);
        $date_from = strtotime($date[0] . ' 00:00:00');
        $date_to  = strtotime($date[1] . ' 23:59:59');
        $subscriptions = Subscription::where('date_added', '>=', $date_from)
            ->where('date_added', '<=', $date_to)
            ->paginate(10);

        return view('superadmin.subscription.subscription_report', compact('subscriptions', 'date_from', 'date_to'));
    }

    public function subscriptionPendingPayment($status = "")
    {
        $date_from = strtotime('first day of january this year');
        $date_to = strtotime('last day of december this year');
        $payment_histories = PaymentHistory::where('paid_by', 'offline')    
            ->where('status', 'pending' )
            ->orwhere('status', 'suspended' )
            ->where('timestamp', '>=', $date_from)
            ->where('timestamp', '<=', $date_to)
            ->paginate(10);
        return view('superadmin.subscription.pending', compact('payment_histories', 'date_from', 'date_to'));
    }

    public function subscriptionFilterPendingPayment(Request $request)
    {
        $data = $request->all();
        $date = explode('-', $data['eDateRange']);
        $date_from = strtotime($date[0] . ' 00:00:00');
        $date_to  = strtotime($date[1] . ' 23:59:59');
        $payment_histories = PaymentHistory::where('paid_by', 'offline')
            ->where('status', 'pending')
            ->where('timestamp', '>=', $date_from)
            ->where('timestamp', '<=', $date_to)
            ->paginate(10);

        return view('superadmin.subscription.pending', compact('payment_histories', 'date_from', 'date_to'));
    }

    public function subscriptionPaymentStatus($status = "", $id = "")
    {
        if ($status == 'approve') {
            \App\Support\Subscriptions\SubscriptionActivator::activate((int) $id);

            return redirect()->back()->with('message', 'You have successfully update status.');
        } else {
            PaymentHistory::where('id', $id)->update([
                'status' => $status,
            ]);
            return redirect()->back()->with('message', 'Status Suspended .');
        }
    }

    public function subscriptionPaymentDelete($id)
    {
        $payment_history = PaymentHistory::find($id);
        $payment_history->delete();
        return redirect()->back()->with('message', 'You have successfully delete a payment history.');
    }

    public function subscriptionExpired(Request $request)
    {   
        $search = $request['search'] ?? "";

        if($search != "") {

            $schools = School::where(function ($query) use($search) {
                    $query->where('title', 'LIKE', "%{$search}%");
                })->paginate(10);

        } else {
            $schools = School::paginate(10);
        }

            $date_from = strtotime('first day of january this year');
            $date_to = strtotime('last day of december this year');
            $subscriptions = Subscription::where('expire_date', '<', strtotime(date('Y-m-d', time())))->where('active','=', 0)->paginate(10);

        return view('superadmin.subscription.expired_subcription', compact('subscriptions', 'date_from', 'date_to', 'search'));   
    }


    /**
     * Show the addon list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function addonList()
    {
        $addons = Addon::all();
        return view('superadmin.addons.list', ['addons' => $addons]);
    }

    public function addonInstall()
    {
        return view('superadmin.addons.create');
    }

    public function addonCreate(Request $request)
    {
        // code...
    }

    public function addonStatus($id = '')
    {
        $addon = Addon::find($id);
        if ($addon->status == 1) {
            Addon::where('id', $id)->update([
                'status' => '0',
            ]);
        } else {
            Addon::where('id', $id)->update([
                'status' => '1',
            ]);
        }

        return to_route('superadmin.addon.list');
    }

    public function addonDelete($id)
    {
        $addon = Addon::find($id);
        $addon->delete();
        $child_addons = Addon::where('parent_id', $id)->get();
        if(count($child_addons) > 0) {
            foreach($child_addons as $child_addon) {
                $sub_addon = Addon::find($child_addon->id);
                $sub_addon->delete();
            }
        }
        return redirect()->back()->with('message', 'You have successfully delete a addon.');
    }


    /**
     * Show the system settings.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function systemSettings()
    {
        return view('superadmin.settings.system_settings');
    }

    public function frontendFeaturesCreate()
    {
        return view('superadmin.settings.frontendFeaturesCreate');
    }

    public function frontendFeaturesadd(Request $request)
    {

        $data = new FrontendFeature();
        $data->id = $request->id;
        $data->title = $request->title;         
        $data->description = $request->description;     
        $data->icon = $request->icon;     
        $data->save();

        return redirect()->back()->with('message', 'Features insert successfully.');
    }

    public function frontFeaDlt($id)
    {
        FrontendFeature::where('id', $id)->delete();
         return redirect()->back()->with('message', 'Deleted successfully.');
    }



    public function frontFeaUpdate( Request  $request, $id)
    {
        $data = request()->except(['_token']);

        FrontendFeature::where('id', $id)->update($data);

        return redirect()->back()->with('message', 'Updated successfully.');
    }

    public function systemUpdate(Request $request)
    {
        // Files are checked before any setting is written (no partial update on an invalid upload).
        $request->validate([
            'email_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'], 'socialLogo1' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'], 'socialLogo2' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'], 'socialLogo3' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'],
            'front_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'],
            'off_pay_ins_file' => ['nullable', 'file', 'mimes:jpg,png,pdf', 'max:10240'],
        ]);
        $data = $request->all();

        unset($data['_token']);
        foreach ($data as $key => $value) {
            if(DB::table('global_settings')->where('key', $key)->get()->count() > 0) {
                GlobalSettings::where('key', $key)->update([
                    'key' => $key,
                    'value' => $value,
                ]);
            } else {
               GlobalSettings::create([
                    'key' => $key,
                    'value' => $value,
                ]); 
            }
        }
        $file = $request->file('off_pay_ins_file');

        if ($file) {
            $extension = $file->getClientOriginalExtension();

            if ($extension == 'jpg' || $extension == 'png') {
                $off_pay_ins_file = time().'6.png';

            } else {
                $off_pay_ins_file = time().'6.pdf';
            }
        } else {
            
        }

        $email_logo = time().'1.png';
        $socialLogo1 = time().'2.png';
        $socialLogo2 = time().'3.png';
        $socialLogo3 = time().'4.png';
        $front_logo = time().'5.png';
        
       

        if(!empty($request->email_logo)){
            $request->email_logo->move(public_path('assets/uploads/email_logo/'), $email_logo);
            GlobalSettings::where('key', 'email_logo')->update(['value' => $email_logo]);
        }
        if(!empty($request->socialLogo1)){
            $request->socialLogo1->move(public_path('assets/uploads/email_logo/'), $socialLogo1);
            GlobalSettings::where('key', 'socialLogo1')->update(['value' => $socialLogo1]);
        }
        if(!empty($request->socialLogo2)){
            $request->socialLogo2->move(public_path('assets/uploads/email_logo/'), $socialLogo2);
            GlobalSettings::where('key', 'socialLogo2')->update(['value' => $socialLogo2]);
        }
        if(!empty($request->socialLogo3)){
            $request->socialLogo3->move(public_path('assets/uploads/email_logo/'), $socialLogo3);
            GlobalSettings::where('key', 'socialLogo3')->update(['value' => $socialLogo3]);
        }
        if(!empty($request->front_logo)){
            $request->front_logo->move(public_path('assets/uploads/logo/'), $front_logo);
            GlobalSettings::where('key', 'front_logo')->update(['value' => $front_logo]);
        }
        if (!empty($request->off_pay_ins_file)) {
            $request->off_pay_ins_file->move(public_path('assets/uploads/offline_payment/'), $off_pay_ins_file);
            GlobalSettings::where('key', 'off_pay_ins_file')->update(['value' => $off_pay_ins_file]);
        }

        return redirect()->back()->with('message', 'System settings updated successfully.');
    }


    /**
     * Show the smtp settings.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function smtpSettings()
    {
        $passwordConfigured = false;
        try {
            $storedPassword = DB::table('global_settings')->where('key', 'smtp_pass')->value('value');
            $passwordConfigured = is_string($storedPassword) && $storedPassword !== '';
        } catch (\Throwable $exception) {
            $this->logMailSettingsFailure($exception, 'display');
        }

        return view('superadmin.settings.smtp_settings', compact('passwordConfigured'));
    }

    public function smtpUpdate(Request $request)
    {
        $oldInput = $request->only([
            'smtp_protocol', 'smtp_crypto', 'smtp_host', 'smtp_port', 'smtp_user', 'from_email', 'from_name',
        ]);

        try {
            $storedPassword = DB::table('global_settings')->where('key', 'smtp_pass')->value('value');
            $passwordConfigured = is_string($storedPassword) && $storedPassword !== '';

            $validator = Validator::make($request->only([
                'smtp_protocol', 'smtp_crypto', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'from_email', 'from_name',
            ]), [
                'smtp_protocol' => ['required', 'in:smtp'],
                'smtp_crypto' => ['required', 'in:tls,ssl'],
                'smtp_host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
                'smtp_port' => ['required', 'integer', 'between:1,65535'],
                'smtp_user' => ['required', 'string', 'email:rfc', 'max:191'],
                'smtp_pass' => $passwordConfigured
                    ? ['nullable', 'string', 'max:4096']
                    : ['required', 'string', 'max:4096'],
                'from_email' => ['required', 'string', 'email:rfc', 'max:191'],
                'from_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            ], [
                'smtp_protocol.required' => 'Select a supported mail protocol.',
                'smtp_protocol.in' => 'The mail protocol must be SMTP.',
                'smtp_crypto.required' => 'Select TLS or SSL encryption.',
                'smtp_crypto.in' => 'Select TLS or SSL encryption.',
                'smtp_host.required' => 'Enter the SMTP host.',
                'smtp_host.regex' => 'Enter a valid SMTP host name.',
                'smtp_port.required' => 'Enter the SMTP port.',
                'smtp_port.integer' => 'Enter a whole-number SMTP port.',
                'smtp_port.between' => 'The SMTP port must be between 1 and 65535.',
                'smtp_user.required' => 'Enter the SMTP username.',
                'smtp_user.email' => 'Enter a valid SMTP mailbox email address.',
                'smtp_pass.required' => 'Enter the SMTP password for the first configuration.',
                'from_email.required' => 'Enter the platform sender email address.',
                'from_email.email' => 'Enter a valid platform sender email address.',
                'from_name.required' => 'Enter the platform sender name.',
                'from_name.not_regex' => 'The platform sender name may not contain line breaks.',
            ]);

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator)->withInput($oldInput);
            }

            $data = $validator->validated();
            DB::transaction(function () use ($data): void {
                $passwordToStore = filled($data['smtp_pass'] ?? null)
                    ? SmtpPasswordSecret::protect((string) $data['smtp_pass'])
                    : SmtpPasswordSecret::protectStored(DB::table('global_settings')->where('key', 'smtp_pass')->value('value'));

                if (!$passwordToStore) {
                    throw new \RuntimeException('SMTP authentication password is unavailable.');
                }

                $settings = [
                    'smtp_protocol' => 'smtp',
                    'smtp_crypto' => $data['smtp_crypto'],
                    'smtp_host' => trim($data['smtp_host']),
                    'smtp_port' => (string) $data['smtp_port'],
                    'smtp_user' => trim($data['smtp_user']),
                    'smtp_pass' => $passwordToStore,
                    // Reuse the existing platform sender identity settings.
                    'system_email' => trim($data['from_email']),
                    'system_title' => trim($data['from_name']),
                ];

                foreach ($settings as $key => $value) {
                    DB::table('global_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
                }
            });

            return redirect()->back()->with('message', 'Email settings saved successfully.');
        } catch (\Throwable $exception) {
            $this->logMailSettingsFailure($exception, 'save');

            return redirect()->back()
                ->withErrors(['mail_settings' => "We couldn't save the email settings. Please review the highlighted fields and try again."])
                ->withInput($oldInput);
        }
    }

    public function smtpTestEmail(Request $request, PlatformSmtpConfiguration $configuration)
    {
        $validator = Validator::make($request->only('recipient_email'), [
            'recipient_email' => ['required', 'string', 'email:rfc', 'max:191'],
        ], [
            'recipient_email.required' => 'Enter a test recipient email address.',
            'recipient_email.email' => 'Enter a valid test recipient email address.',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput($request->except('smtp_pass'));
        }

        try {
            $configuration->apply();
            $sent = \App\Support\Mail\SafeMail::send(
                $validator->validated()['recipient_email'],
                new PlatformMailTestMessage(),
                'platform-smtp-test'
            );

            if (!$sent) {
                return redirect()->back()->with('error', "We couldn't send the test email. Please check the email settings and try again.");
            }

            return redirect()->back()->with('message', 'The test email was sent using the configured mail settings.');
        } catch (\Throwable $exception) {
            $this->logMailSettingsFailure($exception, 'test-email');

            return redirect()->back()->with('error', "We couldn't send the test email. Please check the email settings and try again.");
        }
    }

    private function logMailSettingsFailure(\Throwable $exception, string $operation): void
    {
        Log::error('Platform mail settings operation failed', [
            'operation' => $operation,
            'exception' => get_class($exception),
            'actor_id' => auth()->id(),
        ]);
    }


    
    public function about()
    {

        $purchase_code = get_settings('purchase_code');
        $returnable_array = array(
            'purchase_code_status' => get_phrase('Not found'),
            'support_expiry_date'  => get_phrase('Not found'),
            'customer_name'        => get_phrase('Not found')
        );

        $personal_token = "gC0J1ZpY53kRpynNe4g2rWT5s4MW56Zg";
        $url = "https://api.envato.com/v3/market/author/sale?code=" . $purchase_code;
        $curl = curl_init($url);

        //setting the header for the rest of the api
        $bearer   = 'bearer ' . $personal_token;
        $header   = array();
        $header[] = 'Content-length: 0';
        $header[] = 'Content-type: application/json; charset=utf-8';
        $header[] = 'Authorization: ' . $bearer;

        $verify_url = 'https://api.envato.com/v1/market/private/user/verify-purchase:' . $purchase_code . '.json';
        $ch_verify = curl_init($verify_url . '?code=' . $purchase_code);

        curl_setopt($ch_verify, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch_verify, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_verify, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch_verify, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch_verify, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

        $cinit_verify_data = curl_exec($ch_verify);
        curl_close($ch_verify);

        $response = json_decode($cinit_verify_data, true);

        if (is_array($response) && isset($response['verify-purchase']) && count($response['verify-purchase']) > 0) {

            //print_r($response);
            $item_name         = $response['verify-purchase']['item_name'];
            $purchase_time       = $response['verify-purchase']['created_at'];
            $customer         = $response['verify-purchase']['buyer'];
            $licence_type       = $response['verify-purchase']['licence'];
            $support_until      = $response['verify-purchase']['supported_until'];
            $customer         = $response['verify-purchase']['buyer'];

            $purchase_date      = date("d M, Y", strtotime($purchase_time));

            $todays_timestamp     = strtotime(date("d M, Y"));
            $support_expiry_timestamp = strtotime($support_until);

            $support_expiry_date  = date("d M, Y", $support_expiry_timestamp);

            if ($todays_timestamp > $support_expiry_timestamp)
                $support_status    = 'expired';
            else
                $support_status    = 'valid';

            $returnable_array = array(
                'purchase_code_status' => $support_status,
                'support_expiry_date'  => $support_expiry_date,
                'customer_name'        => $customer,
                'product_license'      => 'valid',
                'license_type'         => $licence_type
            );
        } else {
            $returnable_array = array(
                'purchase_code_status' => 'invalid',
                'support_expiry_date'  => 'invalid',
                'customer_name'        => 'invalid',
                'product_license'      => 'invalid',
                'license_type'         => 'invalid'
            );
        }


        $data['application_details'] = $returnable_array;
        return view('superadmin.settings.about', $data);
    }


    function curl_request($code = '')
    {

        $purchase_code = $code;

        $personal_token = "FkA9UyDiQT0YiKwYLK3ghyFNRVV9SeUn";
        $url = "https://api.envato.com/v3/market/author/sale?code=" . $purchase_code;
        $curl = curl_init($url);

        //setting the header for the rest of the api
        $bearer   = 'bearer ' . $personal_token;
        $header   = array();
        $header[] = 'Content-length: 0';
        $header[] = 'Content-type: application/json; charset=utf-8';
        $header[] = 'Authorization: ' . $bearer;

        $verify_url = 'https://api.envato.com/v1/market/private/user/verify-purchase:' . $purchase_code . '.json';
        $ch_verify = curl_init($verify_url . '?code=' . $purchase_code);

        curl_setopt($ch_verify, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch_verify, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch_verify, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch_verify, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch_verify, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

        $cinit_verify_data = curl_exec($ch_verify);
        curl_close($ch_verify);

        $response = json_decode($cinit_verify_data, true);

        if (is_array($response) && count($response['verify-purchase']) > 0) {
            return true;
        } else {
            return false;
        }
    }


    //Don't remove this code for security reasons
    function save_valid_purchase_code($action_type, Request $request){

        if($action_type == 'update'){
            $data['value'] = $request->purchase_code;

            $status = $this->curl_request($data['value']);
            if($status){  
                GlobalSettings::where('key', 'purchase_code')->update($data);
                session()->flash('message', get_phrase('Purchase code has been updated'));
                echo 1;
            }else{
                echo 0;
            }
        }else{
            return view('superadmin.settings.save_purchase_code_form');
        }
        
    }

    public function payment_settings()
    {


        $global_currency = GlobalSettings::where('key', 'system_currency')->value('value') ?: 'USD';
        $global_currency_position = GlobalSettings::where('key', 'currency_position')->value('value') ?: 'left';

        $currencies = Currency::all()->toArray();

        $paypal = "";
        $stripe = "";
        $razorpay = "";
        $paytm = "";
        $flutterwave = "";
        $paystack = "";

        // None of these global_settings rows are seeded by an installer on
        // this deployment — a fresh key defaults to an empty string, which
        // the Blade template then tries to index (['status'], ['mode'], ...)
        // for every field. That's a PHP warning per field rather than a
        // fatal error normally, but it's still wrong output, and warnings
        // become fatal under stricter error handling (e.g. PHPUnit). Each
        // gateway gets the same shape it would have once configured, all
        // blank, instead of an empty string.
        $paypal = GlobalSettings::where('key', 'paypal')->first();
        $paypal = $paypal ? json_decode($paypal['value'], true) : [
            'status' => 0, 'mode' => 'test',
            'test_client_id' => '', 'test_secret_key' => '',
            'live_client_id' => '', 'live_secret_key' => '',
        ];

        $stripe = GlobalSettings::where('key', 'stripe')->first();
        $stripe = $stripe ? json_decode($stripe['value'], true) : [
            'status' => 0, 'mode' => 'test',
            'test_key' => '', 'test_secret_key' => '',
            'public_live_key' => '', 'secret_live_key' => '',
        ];

        $razorpay = GlobalSettings::where('key', 'razorpay')->first();
        $razorpay = $razorpay ? json_decode($razorpay['value'], true) : [
            'status' => 0, 'mode' => 'test',
            'test_key' => '', 'test_secret_key' => '',
            'live_key' => '', 'live_secret_key' => '', 'theme_color' => '',
        ];

        $paytm = GlobalSettings::where('key', 'paytm')->first();
        $paytm = $paytm ? json_decode($paytm['value'], true) : [
            'status' => 0, 'mode' => 'test',
            'test_merchant_id' => '', 'test_merchant_key' => '',
            'live_merchant_id' => '', 'live_merchant_key' => '',
            'environment' => '', 'merchant_website' => '', 'channel' => '', 'industry_type' => '',
        ];

        $flutterwave = GlobalSettings::where('key', 'flutterwave')->first();
        $flutterwave = $flutterwave ? json_decode($flutterwave['value'], true) : [
            'status' => 0, 'mode' => 'test',
            'test_key' => '', 'test_secret_key' => '', 'test_encryption_key' => '',
            'public_live_key' => '', 'secret_live_key' => '', 'encryption_live_key' => '',
        ];

        $paystack = GlobalSettings::where('key', 'paystack')->first();
        if (!empty($paystack)) {

            $paystack = json_decode($paystack['value'], true);
        }




        return view('superadmin.payment_credentials.payment_settings', ['paystack' => $paystack, 'paytm' => $paytm, 'razorpay' => $razorpay, 'stripe' => $stripe, 'paypal' => $paypal, 'flutterwave' => $flutterwave, 'global_currency' => $global_currency, 'global_currency_position' => $global_currency_position, 'currencies' => $currencies]);
    }



    public function update_payment_settings(Request $request)
    {

        $data = $request->all();
        $update_id = $data['method'];



        if ($data['method'] == 'currency') {

            // updateOrCreate, not ::where(...)->update(...) — that silently
            // affects zero rows (no error, no effect) when the key hasn't
            // been seeded yet, which is exactly the state this row was
            // found in on this deployment.
            GlobalSettings::updateOrCreate(['key' => 'system_currency'], ['value' => $data['global_currency']]);
            GlobalSettings::updateOrCreate(['key' => 'currency_position'], ['value' => $data['currency_position']]);
        }
        elseif ($data['method'] == 'paypal') {
            $keys = array();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_client_id'] = $data['test_client_id'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['live_client_id'] = $data['live_client_id'];
            $keys['live_secret_key'] = $data['live_secret_key'];
            // updateOrCreate, not ::where(...)->first()->save() — none of
            // these gateway rows are seeded by an installer, so on a fresh
            // setup ->first() returns null and ->save() on it fatal-errors
            // the very first time anyone tries to save these credentials.
            GlobalSettings::updateOrCreate(['key' => 'paypal'], ['value' => json_encode($keys)]);
        }
        elseif ($data['method'] == 'stripe') {
            $keys = array();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_key'] = $data['test_key'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['public_live_key'] = $data['public_live_key'];
            $keys['secret_live_key'] = $data['secret_live_key'];
            GlobalSettings::updateOrCreate(['key' => 'stripe'], ['value' => json_encode($keys)]);
        }
        elseif ($data['method'] == 'razorpay') {
            $keys = array();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_key'] = $data['test_key'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['live_key'] = $data['live_key'];
            $keys['live_secret_key'] = $data['live_secret_key'];
            $keys['theme_color'] = $data['theme_color'];
            GlobalSettings::updateOrCreate(['key' => 'razorpay'], ['value' => json_encode($keys)]);
        }
        elseif ($data['method'] == 'paytm') {
            $keys = array();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_merchant_id'] = $data['test_merchant_id'];
            $keys['test_merchant_key'] = $data['test_merchant_key'];
            $keys['live_merchant_id'] = $data['live_merchant_id'];
            $keys['live_merchant_key'] = $data['live_merchant_key'];
            $keys['environment'] = $data['environment'];
            $keys['merchant_website'] = $data['merchant_website'];
            $keys['channel'] = $data['channel'];
            $keys['industry_type'] = $data['industry_type'];
            GlobalSettings::updateOrCreate(['key' => 'paytm'], ['value' => json_encode($keys)]);
        }
        elseif($data['method'] =='flutterwave') {
            $keys = array();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_key'] = $data['test_key'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['test_encryption_key']=$data['test_encryption_key'];
            $keys['public_live_key'] = $data['public_live_key'];
            $keys['secret_live_key'] = $data['secret_live_key'];
            $keys['encryption_live_key'] = $data['encryption_live_key'];
            // updateOrCreate, not ::where(...)->first()->save() — this row
            // is never seeded by an installer/seeder, so on a fresh setup
            // (like this one) ->first() returns null and ->save() on it
            // fatal-errors the very first time anyone tries to save
            // Flutterwave credentials.
            GlobalSettings::updateOrCreate(['key' => 'flutterwave'], ['value' => json_encode($keys)]);

        } elseif ($data['method'] == 'paystack') {
            $keys = array();
            $stripe = GlobalSettings::where('key', 'paystack')->first();
            $keys['status'] = $data['status'];
            $keys['mode'] = $data['mode'];
            $keys['test_key'] = $data['test_key'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['public_live_key'] = $data['public_live_key'];
            $keys['secret_live_key'] = $data['secret_live_key'];
            $stripe['value'] = json_encode($keys);
            $stripe->save();
        }


        return redirect()->route('superadmin.payment_settings')->with('message', 'key has been updated');
    }


    function profile(){
        return view('superadmin.profile.view');
    }

    function profile_update(Request $request){
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


        if(empty($request->photo)){
            $user_info['photo'] = $request->old_photo;
        }else{
            $file_name = ProfilePhoto::store($request->photo);
            if ($file_name === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }
            $user_info['photo'] = $file_name;
        }

        $data['user_information'] = json_encode($user_info);

        User::where('id', auth()->user()->id)->update($data);
        
        return redirect(route('superadmin.profile'))->with('message', get_phrase('Profile info updated successfully'));
    }

    function user_language(Request $request){
        $data['language'] = $request->language;
        User::where('id', auth()->user()->id)->update($data);
        
        return redirect()->back()->with('message', 'You have successfully transleted language.');
    }

    function password($action_type = null, Request $request){



        if($action_type == 'update'){

            

            if($request->new_password != $request->confirm_password){
                return back()->with("error", "Confirm Password Doesn't match!");
            }


            if(!Hash::check($request->old_password, auth()->user()->password)){
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            User::where('id', auth()->user()->id)->update($data);

            return redirect(route('superadmin.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('superadmin.profile.password');
    }

   

    //logo update
    function update_logo(Request $request){
        $request->validate([
            'dark_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'], 'light_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'], 'white_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:4096'],
            'favicon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp,ico', 'max:1024'],
        ]);
        $dark_logo = time().'1.png';
        $light_logo = time().'2.png';
        $favicon = time().'3.png';
        $white_logo = time().'4.png';

        if(!empty($request->dark_logo)){
            $request->dark_logo->move(public_path('assets/uploads/logo/'), $dark_logo);
            GlobalSettings::where('key', 'dark_logo')->update(['value' => $dark_logo]);
        }
        if(!empty($request->light_logo)){
            $request->light_logo->move(public_path('assets/uploads/logo/'), $light_logo);
            GlobalSettings::where('key', 'light_logo')->update(['value' => $light_logo]);
        }
        if(!empty($request->favicon)){
            $request->favicon->move(public_path('assets/uploads/logo/'), $favicon);
            GlobalSettings::where('key', 'favicon')->update(['value' => $favicon]);
        }
        if(!empty($request->white_logo)){
            $request->white_logo->move(public_path('assets/uploads/logo/'), $white_logo);
            GlobalSettings::where('key', 'white_logo')->update(['value' => $white_logo]);
        }

        return redirect('superadmin/settings/system')->with('message', "Logo updated successfully");

    }

    public function manageLanguage($language = '')
    {
        if(!empty($language)) {

            $edit_profile = $language;
            $phrases = Language::where('name', $language)->get();
            $languages = get_all_language();

            return view('superadmin.language.manage_language', ['languages' => $languages, 'edit_profile' => $edit_profile, 'phrases' => $phrases]);
        } else {

            $languages = get_all_language();
            return view('superadmin.language.manage_language', ['languages' => $languages]);

        }
    }

    public function addLanguage(Request $request){

        $language = $request->language;
        if ($language == 'n-a') {
            return redirect('superadmin/settings/language')->with('error', "Language name can not be empty or can not have special characters");
        }

        $phrases = Language::where('name', 'english')->get();

        foreach($phrases as $phrase){
            Language::create([
                'name' => $language,
                'phrase' => $phrase->phrase,
                'translated' => $phrase->translated,
            ]);
        }

        return redirect('superadmin/settings/language')->with('message', "Language added successfully");
    }

    public function updatedPhrase(Request $request)
    {
        $current_editing_language = $request->currentEditingLanguage;
        $updatedValue = $request->updatedValue;
        $phrase = $request->phrase;

        $query = Language::where('name', $current_editing_language)
            ->where('phrase', $phrase)
            ->first();

        if (!empty($query) && $query->count() > 0) {
            $query->translated = $updatedValue;
            $query->save();
        }
    }

    public function deleteLanguage($name='')
    {
        $language = Language::where('name', $name)->get();
        $language->map->delete();
        return redirect()->back()->with('message', 'You have successfully delete a language.');
    }


    /**
     * Show the website settings.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function websiteSettings()
    {
        return view('superadmin.settings.website_settings');
    }


    /**
     * Show the faq.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function faqViews()
    {
        $faqs = Faq::all();
        return view('superadmin.settings.faq_views', ['faqs' => $faqs]);
    }

    public function faqAdd()
    {
        return view('superadmin.settings.add_faq');
    }

    public function faqCreate(Request $request)
    {
        $data = $request->all();

        Faq::create($data);

        return redirect()->back()->with('message', 'You have successfully create a faq.');
    }

    public function faqEdit($id="")
    {
        $faq = Faq::find($id);
        return view('superadmin.settings.edit_faq', ['faq' => $faq]);
    }

    public function faqUpdate(Request $request, $id="")
    {
        $data = $request->all();

        unset($data['_token']);

        Faq::where('id', $id)->update($data);

        return redirect()->back()->with('message', 'You have successfully create a faq.');
    }

    public function faqDelete($id='')
    {
        $faq = Faq::find($id);
        $faq->delete();
        return redirect()->back()->with('message', 'You have successfully delete a faq.');
    }

}
