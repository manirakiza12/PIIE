<?php

namespace App\Http\Controllers;

use App\Support\PublicTenantResolver;
use App\Models\FrontendFeature;
use App\Models\Package;
use App\Models\User;
use App\Models\Session;
use App\Models\School;
use App\Models\Faq;
use App\Models\WebsitePage;
use App\Models\WebsiteItem;
use App\Models\WebsiteSection;
use App\Models\WebsiteSetting;
use App\Models\WebsiteSeoSetting;
use Mail;
use App\Mail\SchoolEmail;
use App\Support\ProfilePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class HomeController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function home(Request $request)
    {
        if(get_settings('frontend_view') == '1') {
            $packages = Schema::hasTable('packages')
                ? Package::where('status', 1)->get()
                : collect();

            $faqs = Schema::hasTable('faq')
                ? Faq::all()
                : collect();

            $users = Schema::hasTable('users')
                ? User::all()
                : collect();

            $schools = Schema::hasTable('schools')
                ? School::all()
                : collect();

            $frontendFeatures = Schema::hasTable('frontend_features')
                ? ($request->has('see_all') ? FrontendFeature::all() : FrontendFeature::limit(8)->get())
                : collect();

            $websiteSections = collect();
            $websiteItems = collect();
            $websiteSettings = collect();
            $allPages = collect();
            $seo = null;

            if (
                Schema::hasTable('website_pages') &&
                Schema::hasTable('website_sections') &&
                Schema::hasTable('website_items') &&
                Schema::hasTable('website_settings') &&
                Schema::hasTable('website_seo_settings')
            ) {
                // Security Phase 2H: only the public-site school's CMS content (App\Support\PublicTenantResolver).
                $publicSchoolId = PublicTenantResolver::resolveSchoolId();

                /**
                 * THE PUBLISHED PAGES, for the redesigned header and footer.
                 *
                 * Additive only. `websitePage()` already loads exactly this list, and
                 * the redesign needs it on the homepage as well so the navigation and
                 * the footer can link to pages that ACTUALLY EXIST.
                 *
                 * Without it the footer had to hardcode slugs, and a hardcoded slug
                 * that no page carries renders a 404 - which is how a Privacy Policy
                 * link that pointed nowhere got into the first version of this design.
                 * Filtering on `status` here means an UNPUBLISHED page cannot appear
                 * in the menu either.
                 */
                $allPages = WebsitePage::where('status', 1)
                    ->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                    ->orderBy('display_order')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $websiteSections = WebsiteSection::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->keyBy('section_key');

                $websiteItems = WebsiteItem::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('section_key');

                $websiteSettings = WebsiteSetting::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                    ->pluck('value', 'key');

                $seo = WebsiteSeoSetting::where('page_key', 'home')->where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))->first();
            }

            return view('frontend.landing_page', [
                'packages' => $packages,
                'faqs' => $faqs,
                'users' => $users,
                'schools' => $schools,
                'frontendFeatures' => $frontendFeatures,
                'websiteSections' => $websiteSections,
                'websiteItems' => $websiteItems,
                'websiteSettings' => $websiteSettings,
                'websiteSeo' => $seo,
                'allPages' => $allPages,
            ]);
        } else {
            return redirect(route('login'));
        }
    }

    /**
     * Show website page by slug
     */
    public function websitePage($slug)
    {
        if(get_settings('frontend_view') != '1') {
            return redirect(route('login'));
        }

        $websitePage = null;
        $websiteSections = collect();
        $websiteItems = collect();
        $websiteSettings = collect();
        $allPages = collect();
        $websiteSeo = null;

        if (
            Schema::hasTable('website_pages') &&
            Schema::hasTable('website_sections') &&
            Schema::hasTable('website_items') &&
            Schema::hasTable('website_settings') &&
            Schema::hasTable('website_seo_settings')
        ) {
            // Security Phase 2H: only the public-site school's CMS content (App\Support\PublicTenantResolver).
            $publicSchoolId = PublicTenantResolver::resolveSchoolId();

            $websitePage = \App\Models\WebsitePage::where('slug', $slug)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->where('status', 1)
                ->first();

            if (!$websitePage) {
                abort(404);
            }

            $allPages = \App\Models\WebsitePage::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->orderBy('display_order')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $websiteSections = WebsiteSection::where('page_key', $websitePage->page_key)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->where('status', 1)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->keyBy('section_key');

            $websiteItems = WebsiteItem::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('section_key');

            $websiteSettings = WebsiteSetting::where('status', 1)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->pluck('value', 'key');

            $websiteSeo = WebsiteSeoSetting::where('page_key', $websitePage->page_key)->where(fn ($q) => $q->where('school_id', $publicSchoolId)->orWhereNull('school_id'))
                ->where('status', 1)
                ->first();
        }

        return view('frontend.website_page', [
            'websitePage' => $websitePage,
            'websiteSections' => $websiteSections,
            'websiteItems' => $websiteItems,
            'websiteSettings' => $websiteSettings,
            'allPages' => $allPages,
            'websiteSeo' => $websiteSeo,
        ]);
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function superadminHome()
    {
        return view('superadminHome');
    }
    public function adminDashboard()
    {
        return view('admin.dashboard');
    }


    public function schoolCreate(Request $request)
    {
        $data = $request->all();
        $school_email = $data['school_email'];
        $admin_email = $data['admin_email'];
        $duplicate_school_email_check = School::get()->where('email', $school_email);
        $duplicate_admin_email_check = User::get()->where('email', $admin_email);
        $recaptcha_secret = get_settings('recaptcha_secret_key');
        $recaptcha_switch = get_settings('recaptcha_switch_value');
        
        if($recaptcha_switch == 'Yes'){
            $response = file_get_contents("https://www.google.com/recaptcha/api/siteverify?secret=".$recaptcha_secret."&response=".$data['g-recaptcha-response']);
        
                $response = json_decode($response, true);
            if($response['success'] === true){
                if(count($duplicate_school_email_check) == 0 && count($duplicate_admin_email_check) == 0) {
                $school = School::create([
                    'title' => $data['school_name'],
                    'email' => $data['school_email'],
                    'phone' => $data['school_phone'],
                    'address' => $data['school_address'],
                    'school_info' => $data['school_info'],
                    'status' => '2',
                ]);
    
                if (isset($school->id) && $school->id != "") {
    
                    $data['status'] = '1';
                    $data['session_title'] = date("Y");
                    $data['school_id'] = $school->id;
    
                    $session = Session::create($data);
    
                    School::where('id', $school->id)->update([
                        'running_session' => $session->id,
                    ]);
                    
                    if (!empty($data['photo'])) {
    
                        $photo = ProfilePhoto::store($data['photo']) ?? '';
                    } else {
                        $photo = '';
                    }
                    $info = array(
                        'gender' => $data['gender'],
                        'blood_group' => $data['blood_group'],
                        'birthday' => isset($data['birthday'])? strtotime($data['birthday']):"",
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
                    ]);
                }
                if(!empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))){
                    \App\Support\Mail\SafeMail::send($data['admin_email'], new SchoolEmail($data), 'school-registration');
                }
    
                return redirect()->route('login')->with('message', 'School Created Successfully');
            } else {
                return redirect()->back()->with('warning','Some of the emails have been taken.');
            }
            }else{
            return redirect()->back()->with('warning', 'Something went wrong');
            }
        }else{
            if(count($duplicate_school_email_check) == 0 && count($duplicate_admin_email_check) == 0) {
                $school = School::create([
                    'title' => $data['school_name'],
                    'email' => $data['school_email'],
                    'phone' => $data['school_phone'],
                    'address' => $data['school_address'],
                    'school_info' => $data['school_info'],
                    'status' => '2',
                ]);
    
                if (isset($school->id) && $school->id != "") {
    
                    $data['status'] = '1';
                    $data['session_title'] = date("Y");
                    $data['school_id'] = $school->id;
    
                    $session = Session::create($data);
    
                    School::where('id', $school->id)->update([
                        'running_session' => $session->id,
                    ]);
                    
                    if (!empty($data['photo'])) {
    
                        $photo = ProfilePhoto::store($data['photo']) ?? '';
                    } else {
                        $photo = '';
                    }
                    $info = array(
                        'gender' => $data['gender'],
                        'blood_group' => $data['blood_group'],
                        'birthday' => isset($data['birthday'])? strtotime($data['birthday']):"",
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
                    ]);
                }
                if(!empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))){
                    \App\Support\Mail\SafeMail::send($data['admin_email'], new SchoolEmail($data), 'school-registration');
                }
    
                return redirect()->route('login')->with('message', 'School Created Successfully');
            } else {
                return redirect()->back()->with('warning','Some of the emails have been taken.');
            }
        }
        
    }
    
    /**
     * Legacy mobile deep link (/web_redirect_to_pay_fee?auth=Basic base64(email:password:timestamp)).
     * It carried the user's PASSWORD in the URL (visible in server logs, proxies and browser
     * history), so it no longer authenticates anyone and never reads the credential. The mobile
     * app must request a link from POST /api/payment_link instead (see webPayFeeHandoff()).
     */
    public function webRedirectToPayFee(Request $request)
    {
        return redirect()->route('login')->withErrors([
            'email' => 'This payment link is no longer supported. Please sign in to pay your fee, or update the mobile app.',
        ]);
    }

    /**
     * Mobile → web payment handoff. Reached only through a temporary signed URL (the 'signed'
     * middleware rejects tampering and expiry with 403) carrying a single-use key issued by
     * POST /api/payment_link. The key is consumed atomically, so a replay or refresh of the
     * link cannot log anyone in again. The fee must still belong to the student.
     */
    public function webPayFeeHandoff(Request $request, string $handoff)
    {
        $record = \App\Support\Payments\PaymentHandoff::redeem($handoff);
        $student = $record ? User::where('id', $record['user_id'])->where('role_id', 7)->where('school_id', $record['school_id'])->first() : null;
        $ownsFee = $student && \App\Models\StudentFeeManager::where('id', $record['fee_id'])
            ->where('student_id', $student->id)->where('school_id', $student->school_id)->exists();

        if (!$student || !$ownsFee || $student->account_status === 'disable') {
            return redirect()->route('login')->withErrors([
                'email' => 'This payment link has expired or was already used. Please sign in to pay your fee.',
            ]);
        }

        auth()->login($student);
        $request->session()->regenerate();

        return redirect()->route('student.FeePayment', $record['fee_id']);
    }

    /**
     * Download the institutional brochure
     */
    public function downloadBrochure()
    {
        $filePath = public_path('assets/uploads/documents/PRIME INTERNATIONAL INSTITUTE BROCHURE.pdf');
        
        if (!file_exists($filePath)) {
            abort(404, 'Brochure file not found');
        }
        
        return response()->download($filePath, 'PRIME INTERNATIONAL INSTITUTE BROCHURE.pdf');
    }
}
