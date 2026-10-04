<?php

use App\Http\Controllers\AccountantController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\CommonController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IdCardVerificationController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LibrarianController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\RegionalPreferenceController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\Updater;
use App\Http\Controllers\WebsiteManagementController;
use App\Http\Controllers\WardenController;
// New HEI Controllers
use App\Http\Controllers\ProgrammeController;
use App\Http\Controllers\PublicEnquiryController;
use App\Http\Controllers\SuperAdminEnquiryController;
use App\Http\Controllers\Admin\AdmissionWizardController;
use App\Http\Controllers\AdmissionsController;
use App\Http\Controllers\FeeStructureController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\OnlineExamController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\TeacherCourseOfferingAttendanceController;
use App\Http\Controllers\TeacherCourseOfferingController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\LiveClassController;
use App\Http\Controllers\CourseOfferingContentController;
use App\Http\Controllers\TeacherCourseOfferingAssignmentController;
use App\Http\Controllers\TeacherCourseOfferingExamController;
use App\Http\Controllers\StudentCourseAssignmentController;
use App\Http\Controllers\StudentCourseContentController;
use App\Http\Controllers\StudentCourseController;
use App\Http\Controllers\StudentCourseExamsController;
use App\Http\Controllers\AcademicCalendarController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\GraduationController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Clear application cache:
Route::get('/clear-cache', function () {
    Artisan::call('cache:clear');

    //Artisan::call('route:cache');

    Artisan::call('config:cache');

    Artisan::call('view:clear');

    Artisan::call('optimize:clear');

    return 'Cache cleard';
})->name('clear.cache');

// MarzPay's server posts here directly — no login, no CSRF token (see
// VerifyCsrfToken::$except). Shared across every payment flow; the payload's
// metadata says which fee/subscription row it belongs to.
Route::post('webhooks/marzpay', [\App\Http\Controllers\MarzPayWebhookController::class, 'handle'])->name('webhooks.marzpay');

//Auth routes are here
Auth::routes();

Route::get('online-exams/notifications/{notification}/read', [OnlineExamController::class, 'markPortalNotificationRead'])
    ->middleware('auth')
    ->name('online_exam.notifications.read');

// Defensive fallback: some clients may hit logout with GET.
Route::get('/logout', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout.get');

//Landing page routes are here
Route::controller(HomeController::class)->group(function () {

    Route::match(['get', 'post'], '/', 'home')->name('landingPage');
    Route::get('website/{slug}', 'websitePage')->name('website.page');
    Route::get('download-brochure', 'downloadBrochure')->name('download.brochure');

    // ── PUBLIC ENQUIRY SUBMISSION ────────────────────────────────────────────
    // The Contact page form. `throttle:6,1` is six submissions per IP per minute
    // and is applied by the framework BEFORE the controller runs, so a flood never
    // reaches validation or the database. `PublicEnquiryController` adds a
    // per-email hourly limit, a honeypot, a link-count heuristic and a
    // duplicate-submission guard on top.
    //
    // There is deliberately NO public route that reads an enquiry. The only reader
    // is `SuperAdminEnquiryController`, behind `auth` + `superAdmin`. The brief's
    // "do not expose submitted enquiries publicly" is therefore satisfied by the
    // absence of a route rather than by remembering to filter one.
    Route::post('website/contact/enquiry', [PublicEnquiryController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('website.enquiry.store');

    Route::post('school/create', 'schoolCreate')->name('school.create');
    Route::get('web_redirect_to_pay_fee', 'webRedirectToPayFee')->name('webRedirectToPayFee');
    // Mobile → web payment handoff: temporary signed URL + single-use key (no credential in the URL).
    Route::get('web_pay_fee/{handoff}', 'webPayFeeHandoff')->middleware('signed')->name('webPayFeeHandoff');
});

// Public ID-card verification — reachable only via a signed URL embedded
// in a card's QR code (see IdCardVerificationController's own docblock).
Route::get('id-card/verify/{student}', [IdCardVerificationController::class, 'show'])
    ->name('id_card.verify')
    ->middleware('signed');

// ── Public admissions / Applicant Portal ──────────────────────────────────
// Independent of the authenticated HEI Admissions administration area, and on
// its own "applicant" auth guard — none of the role middleware above applies
// here and applicants are not `users`. No school selector: the institution
// context is resolved automatically (see App\Support\PublicTenantResolver).

// "Apply Now" landing page — the entry point linked from the public website.
Route::controller(\App\Http\Controllers\PublicApplicationController::class)->group(function () {
    Route::get('apply', 'showForm')->name('apply.form');
});

// Applicant account: registration, sign-in, password recovery.
Route::controller(\App\Http\Controllers\Applicant\AuthController::class)->group(function () {
    Route::middleware('applicant.guest')->group(function () {
        Route::get('applicant/register', 'showRegister')->name('applicant.register');
        Route::post('applicant/register', 'register')->middleware('throttle:10,1')->name('applicant.register.submit');
        Route::get('applicant/login', 'showLogin')->name('applicant.login');
        Route::post('applicant/login', 'login')->middleware('throttle:10,1')->name('applicant.login.submit');
        Route::get('applicant/forgot-password', 'showForgotPassword')->name('applicant.password.request');
        Route::post('applicant/forgot-password', 'sendResetLink')->middleware('throttle:5,1')->name('applicant.password.email');
        Route::get('applicant/reset-password/{token}', 'showResetPassword')->name('applicant.password.reset');
        Route::post('applicant/reset-password', 'resetPassword')->middleware('throttle:5,1')->name('applicant.password.update');
    });

    Route::post('applicant/logout', 'logout')->name('applicant.logout');
});

// Applicant portal. Every route resolves the application from the signed-in
// applicant — no application id is ever accepted from the request.
Route::middleware('applicant')->group(function () {
    Route::controller(\App\Http\Controllers\Applicant\PortalController::class)->group(function () {
        Route::get('applicant/dashboard', 'dashboard')->name('applicant.dashboard');
        Route::get('applicant/track', 'track')->name('applicant.track');
        Route::get('applicant/summary', 'summary')->name('applicant.summary');
        Route::get('applicant/offer-letter', 'offerLetter')->name('applicant.offer_letter');
        Route::get('applicant/document/{id}/view', 'viewDocument')->name('applicant.document.view');
        Route::get('applicant/profile', 'profile')->name('applicant.profile');
        Route::post('applicant/profile', 'updateProfile')->name('applicant.profile.update');
        Route::post('applicant/profile/password', 'updatePassword')->name('applicant.password.change');
    });

    Route::controller(\App\Http\Controllers\Applicant\ApplicationController::class)->group(function () {
        Route::get('applicant/application', 'index')->name('applicant.application');
        Route::get('applicant/application/{step}', 'step')->name('applicant.application.step');
        Route::post('applicant/application/personal', 'savePersonal')->name('applicant.application.personal');
        Route::post('applicant/application/programme', 'saveProgramme')->name('applicant.application.programme');
        Route::post('applicant/application/education', 'saveEducation')->name('applicant.application.education');
        Route::post('applicant/application/submit', 'submit')->name('applicant.application.submit');
    });

    Route::controller(\App\Http\Controllers\Applicant\DocumentController::class)->group(function () {
        Route::get('applicant/documents', 'index')->name('applicant.documents');
        Route::post('applicant/documents/upload', 'store')->name('applicant.documents.upload');
        Route::post('applicant/documents/{id}/delete', 'destroy')->name('applicant.documents.delete');
    });

    Route::controller(\App\Http\Controllers\Applicant\PaymentController::class)->group(function () {
        Route::get('applicant/payment', 'index')->name('applicant.payment');
        Route::post('applicant/payment/offline', 'submitOffline')->name('applicant.payment.offline');
        Route::post('applicant/payment/{gateway}/start', 'startGateway')->name('applicant.payment.gateway.start');
        Route::get('applicant/payment/{gateway}/return/{payment}', 'gatewayReturn')->name('applicant.payment.gateway.return');
        Route::get('applicant/payment/{gateway}/cancel/{payment}', 'gatewayCancel')->name('applicant.payment.gateway.cancel');
        Route::get('applicant/payment/marzpay/{payment}/status', 'checkMarzPayStatus')->name('applicant.payment.marzpay.status');
    });
});

// Website management routes for Superadmin
Route::controller(WebsiteManagementController::class)->middleware('auth', 'superAdmin')->group(function () {
    Route::get('superadmin/website-management', 'superadminIndex')->name('superadmin.website.index');
    Route::post('superadmin/website-management/page/store', 'storePage')->name('superadmin.website.page.store');
    Route::post('superadmin/website-management/page/update/{id}', 'updatePage')->name('superadmin.website.page.update');
    Route::get('superadmin/website-management/page/delete/{id}', 'deletePage')->name('superadmin.website.page.delete');

    Route::post('superadmin/website-management/section/store', 'storeSection')->name('superadmin.website.section.store');
    Route::post('superadmin/website-management/section/update/{id}', 'updateSection')->name('superadmin.website.section.update');
    Route::get('superadmin/website-management/section/delete/{id}', 'deleteSection')->name('superadmin.website.section.delete');

    Route::post('superadmin/website-management/item/store', 'storeItem')->name('superadmin.website.item.store');
    Route::post('superadmin/website-management/item/update/{id}', 'updateItem')->name('superadmin.website.item.update');
    Route::get('superadmin/website-management/item/delete/{id}', 'deleteItem')->name('superadmin.website.item.delete');

    Route::post('superadmin/website-management/settings/upsert', 'upsertSettings')->name('superadmin.website.settings.upsert');
    Route::post('superadmin/website-management/seo/upsert', 'upsertSeo')->name('superadmin.website.seo.upsert');
});

// ── SUPER ADMIN ENQUIRY INBOX ─────────────────────────────────────────────────
// Inside this application's existing `auth` + `superAdmin` gate, which is what makes
// it an *authorised* inbox. A student, lecturer, parent or ordinary school admin
// cannot reach any of it: the School Admin website-management group below has no
// equivalent routes, deliberately, because enquiry triage is an institution-level
// duty rather than a per-school one.
Route::controller(SuperAdminEnquiryController::class)->middleware('auth', 'superAdmin')->group(function () {
    Route::get('superadmin/enquiries', 'index')->name('superadmin.enquiries.index');
    Route::get('superadmin/enquiries/{id}', 'show')->whereNumber('id')->name('superadmin.enquiries.show');
    Route::post('superadmin/enquiries/{id}/status', 'update')->whereNumber('id')->name('superadmin.enquiries.status');
    Route::post('superadmin/enquiries/{id}/delete', 'destroy')->whereNumber('id')->name('superadmin.enquiries.destroy');
});

// Website management routes for School Admin
Route::controller(WebsiteManagementController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/website-management', 'adminIndex')->name('admin.website.index');
    Route::post('admin/website-management/page/store', 'storePage')->name('admin.website.page.store');
    Route::post('admin/website-management/page/update/{id}', 'updatePage')->name('admin.website.page.update');
    Route::get('admin/website-management/page/delete/{id}', 'deletePage')->name('admin.website.page.delete');

    Route::post('admin/website-management/section/store', 'storeSection')->name('admin.website.section.store');
    Route::post('admin/website-management/section/update/{id}', 'updateSection')->name('admin.website.section.update');
    Route::get('admin/website-management/section/delete/{id}', 'deleteSection')->name('admin.website.section.delete');

    Route::post('admin/website-management/item/store', 'storeItem')->name('admin.website.item.store');
    Route::post('admin/website-management/item/update/{id}', 'updateItem')->name('admin.website.item.update');
    Route::get('admin/website-management/item/delete/{id}', 'deleteItem')->name('admin.website.item.delete');

    Route::post('admin/website-management/settings/upsert', 'upsertSettings')->name('admin.website.settings.upsert');
    Route::post('admin/website-management/seo/upsert', 'upsertSeo')->name('admin.website.seo.upsert');
});

// Account Disable Route
//
// Each of these renders one role's "your account has been disabled" page.
// They carry no role middleware (a disabled/wrong-role user can't pass one
// to get here in the first place), so without a guard a logged-in user of
// ANY role could land on — or be linked to — another role's page directly.
// \App\Support\Permissions\RoleAccountDisableRoute::name() sends a
// logged-in user whose own role doesn't match this route back to their own
// disabled-account page instead of rendering the wrong one for them.

Route::get('admin/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'admin.account_disableview'))) {
        return $redirect;
    }
    return view('admin.account_disableview');
})->name('admin.account_disableview');

Route::get('accountant/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'accountant.account_disable'))) {
        return $redirect;
    }
    return view('accountant.account_disable');
})->name('accountant.account_disable');

Route::get('teacher/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'teacher.account_disable'))) {
        return $redirect;
    }
    return view('teacher.account_disable');
})->name('teacher.account_disable');

Route::get('librarian/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'librarian.account_disable'))) {
        return $redirect;
    }
    return view('librarian.account_disable');
})->name('librarian.account_disable');

Route::get('parent/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'parent.account_disable'))) {
        return $redirect;
    }
    return view('parent.account_disable');
})->name('parent.account_disable');

Route::get('student/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'student.account_disable'))) {
        return $redirect;
    }
    return view('student.account_disable');
})->name('student.account_disable');

// Was referenced by WardenMiddleware's redirect but never actually
// registered — any request that hit that branch threw a hard
// RouteNotFoundException instead of showing a message.
Route::get('warden/account-disable', function () {
    if (($redirect = \App\Support\Permissions\RoleAccountDisableRoute::redirectIfMismatched(auth()->user(), 'warden.account_disable'))) {
        return $redirect;
    }
    return view('warden.account_disable');
})->name('warden.account_disable');

//Superadmin routes are here
Route::controller(SuperAdminController::class)->middleware('auth', 'superAdmin')->group(function () {

    Route::get('superadmin/dashboard', 'superadminDashboard')->name('superadmin.dashboard')->middleware('role_id');

    //School routes
    Route::get('superadmin/school/list', 'schoolList')->name('superadmin.school.list');
    Route::get('superadmin/school/edit/{id}', 'editSchool')->name('superadmin.edit.school');
    Route::post('superadmin/school/update/{id}', 'schoolUpdate')->name('superadmin.school.update');
    Route::get('superadmin/school/add', 'schoolAdd')->name('superadmin.school.add');
    Route::post('superadmin/school/create', 'createSchool')->name('superadmin.school.create');
    Route::get('superadmin/school/status_update/{id}/{status}', 'schoolStatusUpdate')->name('superadmin.school.status_update');
    Route::get('superadmin/school/admin_list/{id}', 'adminList')->name('superadmin.school.admin_list');

    //Package routes
    Route::get('superadmin/package', 'superadminPackage')->name('superadmin.package');
    Route::get('superadmin/package/create', 'createPackage')->name('superadmin.create.package');
    Route::post('superadmin/package_add', 'packageCreate')->name('superadmin.package.create');
    Route::get('superadmin/package/{id}', 'editPackage')->name('superadmin.edit.package');
    Route::post('superadmin/package/{id}', 'packageUpdate')->name('superadmin.package.update');
    Route::get('superadmin/package/delete/{id}', 'packageDelete')->name('superadmin.package.delete');

    //Subscription routes
    Route::get('superadmin/subscription/report', 'subscriptionReport')->name('superadmin.subscription.report');
    Route::post('superadmin/subscription/report/filter', 'subscriptionFilterReport')->name('superadmin.subscription.filter_report');
    Route::get('superadmin/subscription/pending', 'subscriptionPendingPayment')->name('superadmin.subscription.pending');
    Route::post('superadmin/subscription/pending/filter', 'subscriptionFilterPendingPayment')->name('superadmin.subscription.filter_pending');
    Route::get('superadmin/subscription/{status}/{id}', 'subscriptionPaymentStatus')->name('superadmin.subscription.status');
    Route::get('superadmin/subscription/delete/{id}', 'subscriptionPaymentDelete')->name('superadmin.subscription.delete');
    Route::get('superadmin/subscription/expired_subcription', 'subscriptionExpired')->name('superadmin.subscription.expired_subcription');

    //Addon routes
    Route::get('superadmin/addon/list', 'addonList')->name('superadmin.addon.list');
    Route::get('superadmin/addon/install', 'addonInstall')->name('superadmin.addon.install');
    Route::get('superadmin/addon/status/{id}', 'addonStatus')->name('superadmin.addon.status');
    Route::get('superadmin/addon/delete/{id}', 'addonDelete')->name('superadmin.addon.delete');

    //System settings routes
    Route::get('superadmin/settings/system', 'systemSettings')->name('superadmin.system_settings');
    Route::post('superadmin/system/update', 'systemUpdate')->name('superadmin.system.update');

    //Frontend features
    Route::get('superadmin/settings/frontendFeaturesCreate', 'frontendFeaturesCreate')->name('superadmin.settings.frontendFeaturesCreate');
    Route::post('superadmin/system/frontendFeaturesadd', 'frontendFeaturesadd')->name('superadmin.system.frontendFeaturesadd');
    Route::get('superadmin/system/delete/{id}', 'frontFeaDlt')->name('superadmin.system.frontendFeaturesDlt');
    Route::post('superadmin/system/update/{id}', 'frontFeaUpdate')->name('superadmin.system.frontFeaUpdate');

    //Website settings routes
    Route::get('superadmin/settings/website', 'websiteSettings')->name('superadmin.website_settings');

    //FAQ
    Route::get('superadmin/settings/faq', 'faqViews')->name('superadmin.faq_views');
    Route::get('superadmin/settings/faq_add', 'faqAdd')->name('superadmin.faq_add');
    Route::post('superadmin/settings/faq_create', 'faqCreate')->name('superadmin.faq_create');
    Route::get('superadmin/settings/faq_edit/{id}', 'faqEdit')->name('superadmin.faq_edit');
    Route::post('superadmin/settings/faq_update/{id}', 'faqUpdate')->name('superadmin.faq_update');
    Route::get('superadmin/settings/faq/delete/{id}', 'faqDelete')->name('superadmin.faq.delete');

    //Language settings routes
    Route::get('superadmin/settings/language/{language?}', 'manageLanguage')->name('superadmin.language.manage');
    Route::post('superadmin/settings/language/add', 'addLanguage')->name('superadmin.language.add');
    Route::post('superadmin/settings/language/{language?}', 'updatedPhrase')->name('superadmin.language.update_phrase');
    Route::get('superadmin/settings/language/delete/{name}', 'deleteLanguage')->name('superadmin.language.delete');

    //Smtp settings routes
    Route::get('superadmin/settings/smtp', 'smtpSettings')->name('superadmin.smtp_settings');
    Route::post('superadmin/smtp/update', 'smtpUpdate')->name('superadmin.smtp.update');
    Route::post('superadmin/smtp/test-email', 'smtpTestEmail')->name('superadmin.smtp.test-email');

    //About routes
    Route::get('superadmin/settings/about', 'about')->name('superadmin.about');
    Route::any('superadmin/save_valid_purchase_code/{action_type?}', 'save_valid_purchase_code')->name('superadmin.save_valid_purchase_code');

    //Payment settings routes
    Route::get('superadmin/payment/settings', 'payment_settings')->name('superadmin.payment_settings');
    Route::post('superadmin/payment/settings/update', 'update_payment_settings')->name('superadmin.update_payment_settings');

    // Legacy PayPal/Stripe/Razorpay/Paytm gateway routes removed (Security Phase 2I): the checkout pages use MarzPay + offline payment only, the legacy gateway partials are never included and the controller methods never existed.


    //Profile
    Route::get('superadmin/profile', 'profile')->name('superadmin.profile');
    Route::post('superadmin/profile/update', 'profile_update')->name('superadmin.profile.update');
    Route::any('superadmin/password/{action_type}', 'password')->name('superadmin.password');
    Route::any('superadmin/admin_password/', 'admin_password')->name('superadmin.admin_list');
    Route::post('superadmin/language', 'user_language')->name('superadmin.language');

    //Logo update
    Route::post('superadmin/logo/update', 'update_logo')->name('superadmin.logo.update');
});
//Superadmin routes end here

//Admin routes are here
Route::controller(AdminController::class)->middleware('admin', 'auth', 'rbac')->group(function () {

    Route::get('admin/dashboard', 'adminDashboard')->name('admin.dashboard')->middleware('role_id');

    //Common routes
    Route::get('admin/section/{id}', 'classWiseSections')->name('admin.class_wise_sections');
    Route::get('admin/subjects/{id}', 'classWiseSubject')->name('admin.class_wise_subject');
    Route::get('admin/students/{id}', 'classWiseStudents')->name('admin.class_wise_student');
    Route::get('admin/students_invoice/{id}', 'classWiseStudentsInvoice')->name('admin.class_wise_student_invoice');

    //Admin users route
    Route::get('admin/admin', 'adminList')->name('admin.admin')->middleware('admin_permission');
    Route::get('admin/admin/export', 'adminListExport')->name('admin.admin.export')->middleware('admin_permission');
    Route::get('admin/admin/create_modal', 'createModal')->name('admin.open_modal')->middleware('school_admin');
    Route::post('admin/admin', 'adminCreate')->name('admin.create')->middleware('school_admin');
    Route::get('admin/admin/edit_modal/{id}', 'editModal')->name('admin.open_edit_modal')->middleware('school_admin');
    Route::post('admin/admin/{id}', 'adminUpdate')->name('admin.update')->middleware('school_admin');
    Route::get('admin/admin/delete/{id}', 'adminDelete')->name('admin.admin.delete')->middleware('school_admin');
    Route::get('admin/admin/admin_profile/{id}', 'adminProfile')->name('admin.admin.admin_profile');
    Route::post('admin/user_password/', 'school_user_password')->name('admin.user_password');
    Route::get('admin/admin/menu_permission/{id}', 'menuSettingsView')->name('admin.admin.menu_permission')->middleware('school_admin:primary');
    Route::post('admin/admin/menu_permission_update/{id}', 'menuPermissionUpdate')->name('admin.admin.menu_permission_update')->middleware('school_admin:primary');
    Route::get('admin/admin/reset-password/{id}', 'adminResetPassword')->name('admin.admin.reset_password')->middleware('school_admin');
    Route::get('admin/admin/resend-activation/{id}', 'adminResendActivation')->name('admin.admin.resend_activation')->middleware('school_admin');
    Route::get('admin/admin/list-pdf', 'adminListPdf')->name('admin.admin.list_pdf');
    Route::get('admin/admin/export-excel', 'adminListExportExcel')->name('admin.admin.export_excel');
    Route::get('admin/admin/profile-pdf/{id}', 'adminProfilePdf')->name('admin.admin.profile_pdf');

    Route::get('admin/admin-documents/{id}', 'adminDocuments')->name('admin.documents');
    Route::get('admin/accountant-documents/{id}', 'accountantDocuments')->name('admin.accountant.documents');
    Route::get('admin/librarian-documents/{id}', 'librarianDocuments')->name('admin.librarian.documents');
    Route::get('admin/parent-documents/{id}', 'parentDocuments')->name('admin.parent.documents');
    Route::get('admin/student-documents/{id}', 'studentDocuments')->name('admin.student.documents');
    Route::get('admin/teacher-documents/{id}', 'teacherDocuments')->name('admin.teacher.documents');
    Route::get('admin/warden-documents/{id}', 'wardenDocuments')->name('admin.warden.documents');

    Route::any('admin/documents-upload/{id}', 'documentsUpload')->name('admin.documents.upload');
    Route::get('admin/documents-remove/{id}/{file_name}', 'documentsRemove')->name('admin.documents.remove');

    //Teacher users route
    Route::get('admin/teacher', 'teacherList')->name('admin.teacher')->middleware('admin_permission');
    Route::get('admin/teacher/export', 'teacherListExport')->name('admin.teacher.export')->middleware('admin_permission');
    Route::get('admin/teacher/create_modal', 'createTeacherModal')->name('admin.teacher.open_modal')->middleware('school_admin:hr');
    Route::post('admin/teacher', 'adminTeacherCreate')->name('admin.teacher.create')->middleware('school_admin:hr');
    Route::get('admin/teacher/edit/{id}', 'teacherEditModal')->name('admin.teacher_edit_modal')->middleware('school_admin:hr');
    Route::post('admin/teacher/{id}', 'teacherUpdate')->name('admin.teacher.update')->middleware('school_admin:hr');
    Route::get('admin/teacher/delete/{id}', 'teacherDelete')->name('admin.teacher.delete')->middleware('school_admin:hr');
    Route::get('admin/teacher/teacher_profile/{id}', 'teacherProfile')->name('admin.teacher.teacher_profile');
    Route::get('admin/teacher/reset-password/{id}', 'teacherResetPassword')->name('admin.teacher.reset_password')->middleware('school_admin');
    Route::get('admin/teacher/resend-activation/{id}', 'teacherResendActivation')->name('admin.teacher.resend_activation')->middleware('school_admin');
    Route::get('admin/teacher/list-pdf', 'teacherListPdf')->name('admin.teacher.list_pdf');
    Route::get('admin/teacher/export-excel', 'teacherListExportExcel')->name('admin.teacher.export_excel');
    Route::get('admin/teacher/profile-pdf/{id}', 'teacherProfilePdf')->name('admin.teacher.profile_pdf');

    //Accountant users route
    Route::get('admin/accountant', 'accountantList')->name('admin.accountant')->middleware('admin_permission');
    Route::get('admin/accountant/export', 'accountantListExport')->name('admin.accountant.export')->middleware('admin_permission');
    Route::get('admin/accountant/create_modal', 'createAccountantModal')->name('admin.accountant.open_modal')->middleware('school_admin:hr');
    Route::post('admin/accountant', 'accountantCreate')->name('admin.accountant.create')->middleware('school_admin:hr');
    Route::get('admin/accountant/edit/{id}', 'accountantEditModal')->name('admin.accountant_edit_modal')->middleware('school_admin:hr');
    Route::post('admin/accountant/{id}', 'accountantUpdate')->name('admin.accountant.update')->middleware('school_admin:hr');
    Route::get('admin/accountant/delete/{id}', 'accountantDelete')->name('admin.accountant.delete')->middleware('school_admin:hr');
    Route::get('admin/accountant/accountant_profile/{id}', 'accountantProfile')->name('admin.accountant.accountant_profile');
    Route::get('admin/accountant/reset-password/{id}', 'accountantResetPassword')->name('admin.accountant.reset_password')->middleware('school_admin');
    Route::get('admin/accountant/resend-activation/{id}', 'accountantResendActivation')->name('admin.accountant.resend_activation')->middleware('school_admin');
    Route::get('admin/accountant/list-pdf', 'accountantListPdf')->name('admin.accountant.list_pdf');
    Route::get('admin/accountant/export-excel', 'accountantListExportExcel')->name('admin.accountant.export_excel');
    Route::get('admin/accountant/profile-pdf/{id}', 'accountantProfilePdf')->name('admin.accountant.profile_pdf');

    //Librarian users route
    Route::get('admin/librarian', 'librarianList')->name('admin.librarian')->middleware('admin_permission');
    Route::get('admin/librarian/export', 'librarianListExport')->name('admin.librarian.export')->middleware('admin_permission');
    Route::get('admin/librarian/create_modal', 'createLibrarianModal')->name('admin.librarian.open_modal')->middleware('school_admin:hr');
    Route::post('admin/librarian', 'librarianCreate')->name('admin.librarian.create')->middleware('school_admin:hr');
    Route::get('admin/librarian/edit/{id}', 'librarianEditModal')->name('admin.librarian_edit_modal')->middleware('school_admin:hr');
    Route::post('admin/librarian/{id}', 'librarianUpdate')->name('admin.librarian.update')->middleware('school_admin:hr');
    Route::get('admin/librarian/delete/{id}', 'librarianDelete')->name('admin.librarian.delete')->middleware('school_admin:hr');
    Route::get('admin/librarian/librarian_profile/{id}', 'librarianProfile')->name('admin.librarian.librarian_profile');
    Route::get('admin/librarian/reset-password/{id}', 'librarianResetPassword')->name('admin.librarian.reset_password')->middleware('school_admin');
    Route::get('admin/librarian/resend-activation/{id}', 'librarianResendActivation')->name('admin.librarian.resend_activation')->middleware('school_admin');
    Route::get('admin/librarian/list-pdf', 'librarianListPdf')->name('admin.librarian.list_pdf');
    Route::get('admin/librarian/export-excel', 'librarianListExportExcel')->name('admin.librarian.export_excel');
    Route::get('admin/librarian/profile-pdf/{id}', 'librarianProfilePdf')->name('admin.librarian.profile_pdf');

    //Parent users route
    Route::get('admin/parent', 'parentList')->name('admin.parent')->middleware('admin_permission');
    Route::post('admin/parent', 'parentCreate')->name('admin.parent.create');
    Route::get('admin/parent/create', 'createParent')->name('admin.parent.create_form');
    Route::get('admin/parent/edit/{id}', 'parentEditModal')->name('admin.parent_edit_modal');
    Route::post('admin/parent/{id}', 'parentUpdate')->name('admin.parent.update');
    Route::get('admin/parent/delete/{id}', 'parentDelete')->name('admin.parent.delete');
    Route::get('admin/parent/parent_profile/{id}', 'parentProfile')->name('admin.parent.parent_profile');

    //Student users route
    Route::get('admin/student', 'studentList')->name('admin.student')->middleware('admin_permission');
    Route::get('admin/student/export', 'studentListExport')->name('admin.student.export')->middleware('admin_permission');
    Route::get('admin/student/create_modal', 'createStudentModal')->name('admin.student.open_modal');
    Route::post('admin/student', 'studentCreate')->name('admin.student.create');
    Route::get('admin/student/id_card/{id}', 'studentIdCardGenerate')->name('admin.student.id_card');
    Route::get('admin/student/edit/{id}', 'studentEditModal')->name('admin.student_edit_modal');
    Route::post('admin/student/{id}', 'studentUpdate')->name('admin.student.update');
    Route::get('admin/student/delete/{id}', 'studentDelete')->name('admin.student.delete');
    Route::get('admin/student/student_profile/{id}', 'studentProfile')->name('admin.student.student_profile');
    Route::get('admin/student/export-excel', 'studentListExportExcel')->name('admin.student.export_excel')->middleware('admin_permission');
    Route::get('admin/student/profile-pdf/{id}', 'studentProfilePdf')->name('admin.student.profile_pdf');
    Route::get('admin/student/profile-excel/{id}', 'studentProfileExportExcel')->name('admin.student.profile_excel');
    Route::get('admin/student/reset-password/{id}', 'studentResetPassword')->name('admin.student.reset_password');
    Route::get('admin/student/resend-activation/{id}', 'resendStudentActivationEmail')->name('admin.student.resend_activation');

    //Warden users route
    Route::get('admin/warden', 'wardenList')->name('admin.warden')->middleware('admin_permission');
    Route::get('admin/warden/export', 'wardenListExport')->name('admin.warden.export')->middleware('admin_permission');
    Route::post('admin/warden', 'wardenCreate')->name('admin.warden.create')->middleware('school_admin:hr');
    Route::get('admin/warden/create', 'createWarden')->name('admin.warden.create_form')->middleware('school_admin:hr');
    Route::get('admin/warden/edit/{id}', 'wardenEditModal')->name('admin.warden_edit_modal')->middleware('school_admin:hr');
    Route::post('admin/warden/{id}', 'wardenUpdate')->name('admin.warden.update')->middleware('school_admin:hr');
    Route::get('admin/warden/delete/{id}', 'wardenDelete')->name('admin.warden.delete')->middleware('school_admin:hr');
    Route::get('admin/warden/warden_profile/{id}', 'wardenProfile')->name('admin.warden.warden_profile');
    Route::get('admin/warden/reset-password/{id}', 'wardenResetPassword')->name('admin.warden.reset_password')->middleware('school_admin');
    Route::get('admin/warden/resend-activation/{id}', 'wardenResendActivation')->name('admin.warden.resend_activation')->middleware('school_admin');
    Route::get('admin/warden/list-pdf', 'wardenListPdf')->name('admin.warden.list_pdf');
    Route::get('admin/warden/export-excel', 'wardenListExportExcel')->name('admin.warden.export_excel');
    Route::get('admin/warden/profile-pdf/{id}', 'wardenProfilePdf')->name('admin.warden.profile_pdf');

    //User Account Status
    Route::get('admin/user_disable/{id}', 'account_disable')->name('admin.account_disable');
    Route::get('admin/user_enable/{id}', 'account_enable')->name('admin.account_enable');

    //Teacher permission route
    Route::get('admin/permission', 'teacherPermission')->name('admin.teacher.permission');
    Route::get('admin/permission/list/{filter}', 'teacherPermissionList')->name('admin.teacher.permission_list');
    Route::get('admin/teacher/permission_update', 'teacherPermissionUpdate')->name('admin.teacher.modify_permission');

    //Teacher programme assignment route (programme-based equivalent of the class-based permission above)
    Route::get('admin/programme-permission/list/{programme_id}', 'teacherProgrammeAssignmentList')->name('admin.teacher.programme_permission_list');
    Route::get('admin/teacher/programme_permission_update', 'teacherProgrammeAssignmentUpdate')->name('admin.teacher.modify_programme_permission');
    Route::get('admin/upgrade_subscription', 'upgreadeSubscription')->name('admin.subscription.upgrade_subscription');

    //Admissions routes
    Route::get('admin/offline_admission', function () {
        return redirect()->route('admin.offline_admission.single', ['type' => 'single']);
    })->middleware('admin_permission');
    Route::get('admin/offline_admission/{type}', 'offlineAdmissionForm')->name('admin.offline_admission.single')->middleware('admin_permission')->middleware('admin_permission');
    Route::post('admin/offline_admission', 'offlineAdmissionCreate')->name('admin.offline_admission.create');
    Route::post('admin/offline_admission/bulk', 'offlineAdmissionBulkCreate')->name('admin.offline_admission.bulk_create');
    Route::post('admin/offline_admission/excel', 'offlineAdmissionExcelCreate')->name('admin.offline_admission.excel_create');
    Route::get('admin/offline_admission_preview', function () {
        return view('admin.offline_admission.csv_preview');
    })->name('admin.offline_admission.preview');

    //Exam category routes
    Route::get('admin/exam_category', 'examCategoryList')->name('admin.exam_category')->middleware('admin_permission');
    Route::get('admin/exam_category/create', 'createExamCategory')->name('admin.exam_category.open_modal')->middleware('admin_permission');
    Route::post('admin/exam_category', 'examCategoryCreate')->name('admin.create.exam_category')->middleware('admin_permission');
    Route::get('admin/exam_category/{id}', 'editExamCategory')->name('admin.edit.exam_category')->middleware('admin_permission');
    Route::post('admin/exam_category/{id}', 'examCategoryUpdate')->name('admin.exam_category.update')->middleware('admin_permission');
    Route::get('admin/exam_category/delete/{id}', 'examCategoryDelete')->name('admin.exam_category.delete')->middleware('admin_permission');

    //Exam routes
    Route::get('admin/offline_exam', 'offlineExamList')->name('admin.offline_exam')->middleware('admin_permission');
    Route::get('admin/offline_exam/export/{id}', 'offlineExamExport')->name('admin.offline_exam.export')->middleware('admin_permission');
    Route::get('admin/exam', 'createOfflineExam')->name('admin.offline_exam.open_modal')->middleware('admin_permission');
    Route::post('admin/offline_exam', 'offlineExamCreate')->name('admin.create.offline_exam')->middleware('admin_permission');
    Route::get('admin/offline_exam/{id}', 'editOfflineExam')->name('admin.edit.offline_exam')->middleware('admin_permission');
    Route::post('admin/offline_exam/{id}', 'offlineExamUpdate')->name('admin.offline_exam.update')->middleware('admin_permission');
    Route::get('admin/offline_exam/delete/{id}', 'offlineExamDelete')->name('admin.offline_exam.delete')->middleware('admin_permission');
    Route::get('admin/exam_list_by_class/{id}', 'classWiseOfflineExam')->name('admin.class_wise_exam_list')->middleware('admin_permission');

    //Admit Card
    Route::get('admin/admit-card-list', 'admitCardList')->name('admin.examination.admit_card_list');
    Route::get('admin/admit-card-create', 'admitCardCreate')->name('admin.examination.admit_card_create');
    Route::post('admin/admit-card-upload', 'admitCardUpload')->name('admin.examination.admit_card_upload');
    Route::get('admin/admit-card-edit/{id}', 'admitCardEdit')->name('admin.examination.admit_card_edit');
    Route::post('admin/admit-card-update/{id}', 'admitCardUpdate')->name('admin.examination.admit_card_update');
    Route::get('admin/admit-card-delete/{id}', 'admitCardDelete')->name('admin.examination.admit_card_delete');

    Route::get('admin/print-admit-card', 'admitCardPrint')->name('admin.examination.admit_card_print');
    Route::get('admin/admitCardFilter', 'admitCardFilter')->name('admin.examination.admitCardFilter');

    //Attendance routes
    Route::get('admin/attendance', 'dailyAttendance')->name('admin.daily_attendance')->middleware('admin_permission');
    Route::get('admin/take_attendance', 'takeAttendance')->name('admin.take_attendance.open_modal')->middleware('admin_permission');
    Route::post('admin/attendance_take', 'attendanceTake')->name('admin.attendance_take')->middleware('admin_permission');
    Route::get('admin/attendance/student', 'studentListAttendance')->name('admin.attendance.student')->middleware('admin_permission');
    Route::get('admin/attendance/filter', 'dailyAttendanceFilter')->name('admin.daily_attendance.filter')->middleware('admin_permission');
    Route::get('admin/attendance/csv', 'dailyAttendanceFilter_csv')->name('admin.dailyAttendanceFilter_csv')->middleware('admin_permission');

    //Routine routes
    Route::get('admin/routine', 'routine')->name('admin.routine')->middleware('admin_permission');
    Route::get('admin/routine/add_routine', 'addRoutine')->name('admin.routine.open_modal')->middleware('admin_permission');
    Route::post('admin/routine/routine_add', 'routineAdd')->name('admin.routine.routine_add')->middleware('admin_permission');
    Route::get('admin/routine/list', 'routineList')->name('admin.routine.routine_list')->middleware('admin_permission');
    Route::get('admin/routine/edit/{id}', 'routineEditModal')->name('admin.routine_edit_modal')->middleware('admin_permission');
    Route::post('admin/routine/{id}', 'routineUpdate')->name('admin.routine.update')->middleware('admin_permission');
    Route::get('admin/routine/delete/{id}', 'routineDelete')->name('admin.routine.delete')->middleware('admin_permission');

    //Syllabus routes
    Route::get('admin/syllabus', 'syllabus')->name('admin.syllabus')->middleware('admin_permission');
    Route::get('admin/syllabus/add_routine', 'addSyllabus')->name('admin.syllabus.open_modal')->middleware('admin_permission');
    Route::post('admin/syllabus/routine_add', 'syllabusAdd')->name('admin.syllabus.syllabus_add')->middleware('admin_permission');
    Route::get('admin/syllabus/list', 'syllabusList')->name('admin.syllabus.syllabus_list')->middleware('admin_permission');
    Route::get('admin/syllabus/edit/{id}', 'syllabusEditModal')->name('admin.syllabus_edit_modal')->middleware('admin_permission');
    Route::post('admin/syllabus/{id}', 'syllabusUpdate')->name('admin.syllabus.update')->middleware('admin_permission');
    Route::get('admin/syllabus/delete/{id}', 'syllabusDelete')->name('admin.syllabus.delete')->middleware('admin_permission');

    //Gradebooks routes
    Route::get('admin/gradebook', 'gradebook')->name('admin.gradebook')->middleware('admin_permission');
    Route::get('admin/gradebook/list', 'gradebookList')->name('admin.gradebook.list')->middleware('admin_permission');
    Route::get('admin/gradebook/subjec_marks/{student_id}', 'subjectWiseMarks')->name('admin.gradebook.subject_wise_marks')->middleware('admin_permission');
    Route::get('admin/exam/mark', 'addmark')->name('admin.exam_mark.open_modal')->middleware('admin_permission');
    Route::post('admin/exam/mark_add', 'markAdd')->name('admin.add.exam_mark')->middleware('admin_permission');

    //Marks route
    Route::get('admin/marks', 'marks')->name('admin.marks')->middleware('admin_permission');
    Route::get('admin/marks/list', 'marksFilter')->name('admin.marks.list')->middleware('admin_permission');
    Route::get('admin/marks/list_pdf/{section_id?}/{class_id?}/{session_id?}/{exam_category_id?}/{subject_id?}', 'marksPdf')->name('admin.marks.list_pdf')->middleware('admin_permission');

    //Grade routes
    Route::get('admin/grade', 'gradeList')->name('admin.grade_list')->middleware('admin_permission');
    Route::get('admin/grade_create', 'createGrade')->name('admin.grade.open_modal')->middleware('admin_permission');
    Route::post('admin/grade', 'gradeCreate')->name('admin.create.grade')->middleware('admin_permission');
    Route::get('admin/grade/{id}', 'editGrade')->name('admin.edit.grade')->middleware('admin_permission');
    Route::post('admin/grade/{id}', 'gradeUpdate')->name('admin.grade.update')->middleware('admin_permission');
    Route::get('admin/grade/delete/{id}', 'gradeDelete')->name('admin.grade.delete')->middleware('admin_permission');

    //promotion routes
    Route::get('admin/promotion', 'promotionFilter')->name('admin.promotion')->middleware('admin_permission');
    Route::get('admin/promotion_list', 'promotionList')->name('admin.promotion.promotion_list');
    Route::get('admin/promote/{promotion_data}', 'promote')->name('admin.promotion.promote');

    //Subject routes
    Route::get('admin/subject', 'subjectList')->name('admin.subject_list')->middleware('admin_permission');
    Route::get('admin/subject_create', 'createSubject')->name('admin.subject.open_modal');
    Route::post('admin/subject', 'subjectCreate')->name('admin.create.subject');
    Route::get('admin/subject/{id}', 'editSubject')->name('admin.edit.subject');
    Route::post('admin/subject/{id}', 'subjectUpdate')->name('admin.subject.update');
    Route::get('admin/subject/delete/{id}', 'subjectDelete')->name('admin.subject.delete');

    //Depertment routes
    Route::get('admin/department', 'departmentList')->name('admin.department_list')->middleware('admin_permission');
    Route::get('admin/department_create', 'createDepartment')->name('admin.department.open_modal');
    Route::post('admin/department', 'departmentCreate')->name('admin.create.department');
    Route::get('admin/department/{id}', 'editDepartment')->name('admin.edit.department');
    Route::post('admin/department/{id}', 'departmentUpdate')->name('admin.department.update');
    Route::get('admin/department/delete/{id}', 'departmentDelete')->name('admin.department.delete');

    Route::get('admin/designation', 'designationList')->name('admin.designation_list')->middleware('admin_permission');
    Route::get('admin/designation_create', 'createDesignation')->name('admin.designation.open_modal');
    Route::post('admin/designation', 'designationCreate')->name('admin.create.designation');
    Route::get('admin/designation/{id}', 'editDesignation')->name('admin.edit.designation');
    Route::post('admin/designation/{id}', 'designationUpdate')->name('admin.designation.update');
    Route::get('admin/designation/delete/{id}', 'designationDelete')->name('admin.designation.delete');

    //Class room routes
    Route::get('admin/class_room', 'classRoomList')->name('admin.class_room_list')->middleware('admin_permission');
    Route::get('admin/class_room_create', 'createClassRoom')->name('admin.class_room.open_modal');
    Route::post('admin/class_room', 'classRoomCreate')->name('admin.create.class_room');
    Route::get('admin/class_room/{id}', 'editClassRoom')->name('admin.edit.class_room');
    Route::post('admin/class_room/{id}', 'classRoomUpdate')->name('admin.class_room.update');
    Route::get('admin/class_room/delete/{id}', 'classRoomDelete')->name('admin.class_room.delete');

    //Class list routes
    Route::get('admin/class_list', 'classList')->name('admin.class_list')->middleware('admin_permission');
    Route::get('admin/class_create', 'createClass')->name('admin.class.open_modal');
    Route::post('admin/class', 'classCreate')->name('admin.create.class');
    Route::get('admin/class/{id}', 'editClass')->name('admin.edit.class');
    Route::post('admin/class/{id}', 'classUpdate')->name('admin.class.update');
    Route::get('admin/class/section/{id}', 'editSection')->name('admin.edit.section');
    Route::post('admin/class/sections/{id}', 'sectionUpdate')->name('admin.section.update');
    Route::get('admin/class/delete/{id}', 'classDelete')->name('admin.class.delete');

    //Accounting route
    Route::get('admin/student_fee/delete/{id}/{status}', 'update_offline_payment')->name('admin.update_offline_payment');
    // Legacy PayPal/Stripe/Razorpay/Paytm gateway routes removed (Security Phase 2I): the checkout pages use MarzPay + offline payment only, the legacy gateway partials are never included and the controller methods never existed.
    Route::get('admin/subscription/payment/trail', 'admin_free_subcription')->name('admin_free_subcription');
    Route::post('admin/subscription/offline/payment/{id}', 'admin_subscription_offline_payment')->name('admin.admin_subscription_offline_payment');

    //Student fee manager routes
    Route::get('admin/fee_manager', 'studentFeeManagerList')->name('admin.fee_manager.list')->middleware('admin_permission');
    Route::get('admin/student_fee_manager/export/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExport')->name('admin.fee_manager.export');
    Route::get('admin/student_fee_manager/pdf_print/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExportPdfPrint')->name('admin.fee_manager.pdf_print');
    Route::get('admin/fee_manager_create/{value}', 'createFeeManager')->name('admin.fee_manager.open_modal');
    Route::post('admin/fee_manager/{value}', 'feeManagerCreate')->name('admin.create.fee_manager');
    Route::get('admin/fee_manager/{id}', 'editFeeManager')->name('admin.edit.fee_manager');
    Route::post('admin/fee_manager_list/{id}', 'feeManagerUpdate')->name('admin.fee_manager.update');
    Route::get('admin/student_fee/delete/{id}', 'studentFeeDelete')->name('admin.fee_manager.delete');
    Route::get('admin/student_fee/invoice/{id}', 'studentFeeinvoice')->name('admin.studentFeeinvoice');
    Route::get('admin/offline_payment/pending', 'offline_payment_pending')->name('admin.offline_payment_pending')->middleware('admin_permission');

    //Expense routes
    Route::get('admin/expenses/list', 'expenseList')->name('admin.expense.list')->middleware('admin_permission');
    Route::get('admin/expenses/create', 'createExpense')->name('admin.expenses.open_modal');
    Route::post('admin/expenses/added', 'expenseCreate')->name('admin.create.expenses');
    Route::get('admin/expenses/{id}', 'editExpense')->name('admin.edit.expenses');
    Route::post('admin/expenses/{id}', 'expenseUpdate')->name('admin.expenses.update');
    Route::get('admin/expenses/delete/{id}', 'expenseDelete')->name('admin.expense.delete');

    //Expense category routes
    Route::get('admin/expense_category/list', 'expenseCategoryList')->name('admin.expense.category_list')->middleware('admin_permission');
    Route::get('admin/expense_category/create', 'createExpenseCategory')->name('admin.expense_category.open_modal');
    Route::post('admin/expense_category/added', 'expenseCategoryCreate')->name('admin.create.expense_category');
    Route::get('admin/expense_category/{id}', 'editExpenseCategory')->name('admin.edit.expense_category');
    Route::post('admin/expense_category/{id}', 'expenseCategoryUpdate')->name('admin.expense_category.update');
    Route::get('admin/expense_category/delete/{id}', 'expenseCategoryDelete')->name('admin.expense.category_delete');

    //Book routes
    Route::get('admin/book/list', 'bookList')->name('admin.book.book_list')->middleware('admin_permission');
    Route::get('admin/book/create', 'createBook')->name('admin.book.open_modal');
    Route::post('admin/book/added', 'bookCreate')->name('admin.create.book');
    Route::get('admin/book/{id}', 'editBook')->name('admin.edit.book');
    Route::post('admin/book/{id}', 'bookUpdate')->name('admin.book.update');
    Route::get('admin/book/delete/{id}', 'bookDelete')->name('admin.book.delete');

    //Issue book routes
    Route::get('admin/book_issue', 'bookIssueList')->name('admin.book_issue.list')->middleware('admin_permission');
    Route::get('admin/book_issue/create', 'createBookIssue')->name('admin.book_issue.open_modal');
    Route::post('admin/book_issue/added', 'bookIssueCreate')->name('admin.create.book_issue');
    Route::get('admin/book_issue/{id}', 'editBookIssue')->name('admin.edit.book_issue');
    Route::post('admin/book_issue/{id}', 'bookIssueUpdate')->name('admin.book_issue.update');
    Route::get('admin/book_issue/return/{id}', 'bookIssueReturn')->name('admin.book_issue.return');
    Route::get('admin/book_issue/delete/{id}', 'bookIssueDelete')->name('admin.book_issue.delete');

    //Noticeboard routes
    Route::get('admin/noticeboard', 'noticeboardList')->name('admin.noticeboard.list')->middleware('admin_permission');
    Route::get('admin/noticeboard/create', 'createNoticeboard')->name('admin.noticeboard.open_modal');
    Route::post('admin/noticeboard/added', 'noticeboardCreate')->name('admin.create.noticeboard');
    Route::get('admin/noticeboard/{id}', 'editNoticeboard')->name('admin.edit.noticeboard');
    Route::post('admin/noticeboard/{id}', 'noticeboardUpdate')->name('admin.noticeboard.update');
    Route::get('admin/noticeboard/delete/{id}', 'noticeboardDelete')->name('admin.noticeboard.delete');

    //Subscription routes
    Route::get('admin/subscription', 'subscription')->name('admin.subscription')->middleware('admin_permission');
    Route::get('admin/subscription/purchase', 'subscriptionPurchase')->name('admin.subscription.purchase');
    Route::get('admin/subscription/payment/{package_id}', 'subscriptionPayment')->name('admin.subscription.payment');
    Route::post('admin/subscription/offline_payment/{id}', 'offlinePayment')->name('admin.subscription.offline_payment');
    Route::post('admin/subscription/payment/{package_id}/marzpay/start', 'startMarzpaySubscriptionPayment')->name('admin.subscription.marzpay.start');
    Route::get('admin/subscription/payment/marzpay/{id}/status', 'checkMarzpaySubscriptionStatus')->name('admin.subscription.marzpay.status');

    //Event routes
    Route::get('admin/events/list', 'eventList')->name('admin.events.list')->middleware('admin_permission');
    Route::get('admin/events/create', 'createEvent')->name('admin.events.open_modal');
    Route::post('admin/events/added', 'eventCreate')->name('admin.create.event');
    Route::get('admin/events/{id}', 'editEvent')->name('admin.edit.event');
    Route::post('admin/events/{id}', 'eventUpdate')->name('admin.event.update');
    Route::get('admin/events/delete/{id}', 'eventDelete')->name('admin.events.delete');


    // Club Management
    Route::get('admin/club/list', 'clubList')->name('admin.club.index')->middleware('admin_permission');
    Route::get('admin/club/create', 'createClub')->name('admin.club.create');
    Route::post('admin/club/store', 'store')->name('admin.club.store');
    Route::post('admin/club/toggle-status/{id}', 'toggleStatus')->name('admin.club.toggle_status');
    Route::get('admin/club/edit/{id}', 'editClub')->name('admin.club.edit');
    Route::post('admin/club/update/{id}', 'updateClub')->name('admin.club.update');
    Route::get('admin/club/delete/{id}', 'deleteClub')->name('admin.club.delete');
    Route::get('admin/club/members/{club}', 'clubMembers')->name('admin.club.members');
    Route::get('admin/club/member/approve/{id}', 'approveMember')->name('admin.club.member.approve');
    Route::get('admin/club/member/disable/{id}', 'member_disable')->name('admin.club.member.disable');
    Route::post('admin/club/member/reject/{id}', 'rejectMember')->name('admin.club.member.reject');
    Route::get('admin/club/member/delete/{id}', 'deleteMember')->name('admin.club.member.delete');
    Route::get('admin/club/member/add/{club}', 'addMemberForm')->name('admin.club.add_member');
    Route::post('admin/club/member/store', 'storeMember')->name('admin.club.member.store');
    Route::get('admin/club/{club}/members/search', 'searchMembers')->name('admin.club.members.search');
    Route::get('admin/club/students/search', 'searchStudents')->name('admin.club.students.search');


    // Club Notice
    Route::get('admin/club/notice/{club}', 'notice1_index')->name('admin.club.notice');
    Route::get('admin/club/notice/create/{club}', 'notice_create')->name('admin.club.notice.create');
    Route::post('admin/club/notice/store', 'notice_store')->name('admin.club.notice.store');
    Route::get('admin/club/notice/edit/{id}', 'notice_edit')->name('admin.club.notice.edit');
    Route::post('admin/club/notice/update/{id}', 'notice_update')->name('admin.club.notice.update');
    Route::get('admin/club/notice/delete/{id}', 'notice_delete')->name('admin.club.notice.delete');








    //Complain List routes
    Route::get('admin/complain/complainList', 'complainList')->name('admin.complain.complainList');

    //Settings routes
    Route::get('admin/settings/payment', 'paymentSettings')->name('admin.settings.payment')->middleware('admin_permission');
    Route::post('admin/settings/payment/post', 'paymentSettings_post')->name('admin.settings.payment_post')->middleware('admin_permission');
    Route::get('admin/settings/school', 'schoolSettings')->name('admin.settings.school')->middleware('admin_permission');
    Route::post('admin/settings/school', 'schoolUpdate')->name('admin.school.update');

    //Session routes
    Route::get('admin/academic-structure', [\App\Http\Controllers\AcademicStructureController::class, 'index'])->name('admin.academic_structure.index')->middleware('admin_permission');
    Route::post('admin/academic-structure/years', [\App\Http\Controllers\AcademicStructureController::class, 'storeYear'])->name('admin.academic_structure.years.store')->middleware('admin_permission');
    Route::post('admin/academic-structure/periods', [\App\Http\Controllers\AcademicStructureController::class, 'storePeriod'])->name('admin.academic_structure.periods.store')->middleware('admin_permission');
    Route::post('admin/academic-structure/current', [\App\Http\Controllers\AcademicStructureController::class, 'setCurrent'])->name('admin.academic_structure.current')->middleware('admin_permission');
    Route::post('admin/academic-structure/years/{yearId}/status', [\App\Http\Controllers\AcademicStructureController::class, 'transitionYear'])->name('admin.academic_structure.years.status')->middleware('admin_permission');
    Route::post('admin/academic-structure/periods/{periodId}/status', [\App\Http\Controllers\AcademicStructureController::class, 'transitionPeriod'])->name('admin.academic_structure.periods.status')->middleware('admin_permission');
    Route::get('admin/session_manager', 'sessionManager')->name('admin.settings.session_manager')->middleware('admin_permission');
    Route::get('admin/session_manager/active_session/{id}', 'activeSession')->name('admin.session_manager.active_session');
    Route::get('admin/session_manager/create', 'createSession')->name('admin.create.session');
    Route::post('admin/session_add', 'sessionCreate')->name('admin.session_manager.create');
    Route::get('admin/session_manager/{id}', 'editSession')->name('admin.edit.session');
    Route::post('admin/session_manager/{id}', 'sessionUpdate')->name('admin.session.update');
    Route::get('admin/session_manager/delete/{id}', 'sessionDelete')->name('admin.session.delete');

    //Profile
    Route::get('admin/profile', 'profile')->name('admin.profile')->middleware('admin_permission');
    Route::post('admin/profile/update', 'profile_update')->name('admin.profile.update');
    Route::any('admin/password/{action_type}', 'password')->name('admin.password');
    Route::post('admin/language', 'user_language')->name('admin.language');

    //Account disable navigation
    //Route::get('admin/account_disableview', 'account_disableview')->name('admin.account_disableview');

    // Student Feedback
    Route::get('admin/feedback-list', 'feedback_list')->name('admin.feedback.feedback_list');
    Route::get('admin/feedback-create', 'create_feedback')->name('admin.feedback.create_feedback');
    Route::post('admin/feedback-upload', 'upload_feedback')->name('admin.feedback.upload_feedback');
    Route::get('admin/feedback-edit/{id}', 'edit_feedback')->name('admin.feedback.edit_feedback');
    Route::post('admin/feedback-update/{id}', 'update_feedback')->name('admin.feedback.update_feedback');
    Route::get('admin/feedback-delete/{id}', 'delete_feedback')->name('admin.feedback.delete_feedback');

    // Hostel
    Route::get('admin/hostel-list', 'hostel_list')->name('admin.hostel.hostel_list');
    Route::get('admin/hostel-create', 'create_hostel')->name('admin.hostel.create_hostel');
    Route::post('admin/hostel-store', 'store_hostel')->name('admin.hostel.store_hostel');
    Route::get('admin/hostel-edit/{id}', 'edit_hostel')->name('admin.hostel.edit_hostel');
    Route::post('admin/hostel-update/{id}', 'update_hostel')->name('admin.hostel.update_hostel');
    Route::get('admin/hostel-delete/{id}', 'delete_hostel')->name('admin.hostel.delete_hostel');

    // Hostel Room
    Route::get('admin/hostel-room-list', 'hostel_room_list')->name('admin.hostel.room_list');
    Route::get('admin/hostel-room-create', 'create_hostel_room')->name('admin.hostel.create_room');
    Route::post('admin/hostel-room-store', 'store_hostel_room')->name('admin.hostel.store_room');
    Route::get('admin/hostel-room-edit/{id}', 'edit_hostel_room')->name('admin.hostel.edit_room');
    Route::post('admin/hostel-room-update/{id}', 'update_hostel_room')->name('admin.hostel.update_room');
    Route::get('admin/hostel-room-delete/{id}', 'delete_hostel_room')->name('admin.hostel.delete_room');

    // Hostel Room Allocation
    Route::get('admin/hostel-room-allocation-list', 'hostel_room_allocation_list')->name('admin.hostel.allocation_list');
    Route::get('admin/hostel-room-allocation-create', 'create_hostel_room_allocation')->name('admin.hostel.create_allocation');
    Route::post('admin/hostel-room-allocation-store', 'store_hostel_room_allocation')->name('admin.hostel.store_allocation');
    Route::get('admin/hostel-room-allocation-edit/{id}', 'edit_hostel_room_allocation')->name('admin.hostel.edit_allocation');
    Route::post('admin/hostel-room-allocation-update/{id}', 'update_hostel_room_allocation')->name('admin.hostel.update_allocation');
    Route::get('admin/hostel-room-allocation-delete/{id}', 'delete_hostel_room_allocation')->name('admin.hostel.delete_allocation');
    Route::get('admin/hostel-applications', 'applications')->name('admin.hostel.applications');
    Route::get('admin/hostel-applications/approve/{id}', 'approveApplication')->name('admin.hostel.applications.approve');
    Route::get('admin/hostel-applications/reject/{id}', 'rejectApplication')->name('admin.hostel.applications.reject');

    Route::get('admin/hostel-fee-manager', 'hostelFees')->name('admin.hostel_fee_manager.list');

    //Appraisal
    Route::get('admin/appraisal-question', 'appraisalQuestions')->name('admin.appraisal.appraisalQuestions');
    Route::get('admin/appraisal-create-question', 'createQuestion')->name('admin.appraisal.createQuestion');
    Route::post('admin/appraisal-store-question', 'storeQuestion')->name('admin.appraisal.storeQuestion');
    Route::get('admin/appraisal-edit/{id}', 'appraisalQuestionEdit')->name('admin.appraisal.appraisalQuestionEdit');
    Route::post('admin/appraisal-question-update/{id}', 'appraisalQuestionUpdate')->name('admin.appraisal.appraisalQuestionUpdate');
    Route::get('admin/appraisal-question-delete/{id}', 'appraisalQuestionDelete')->name('admin.appraisal.appraisalQuestionDelete');
    Route::get('admin/appraisal-student-feedback', 'appraisalFeedback')->name('admin.appraisal.studentFeedback');

    // Message
    Route::get('admin/message/all-message/{id}', 'allMessage')->name('admin.message.all_message');
    Route::get('admin/message/message-thrades/{id}', 'messagethrades')->name('admin.message.messagethrades');
    Route::post('admin/message/single-chat/save', 'chat_save')->name('admin.message.chat_save');
    Route::get('admin/message/chat_empty', 'chat_empty')->name('admin.message.chat_empty');

    // hostel
    Route::get('admin/hostel-fee/offline-payment/list', 'offlinePaymentList')->name('admin.offline.payment.hostel.list');

    Route::get('admin/hostel_fee/offline_payment/accept/{id}', 'acceptOfflinePaymentHostel')->name('admin.accept.offline.payment.hostel');
    Route::get('admin/hostel_fee/offline_payment/reject/{id}', 'rejectOfflinePaymentHostel')->name('admin.reject.offline.payment.hostel');
});
//Admin routes end here

// Higher-education Programme Cohort administration. Route permissions are
// centrally mapped in the staff permission registry; tenant checks remain in
// the controller and domain service.
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\ProgrammeCohortController::class)->group(function () {
    Route::get('admin/programme-cohorts', 'index')->name('admin.programme_cohorts.index');
    Route::get('admin/programme-cohorts/create', 'create')->name('admin.programme_cohorts.create');
    Route::post('admin/programme-cohorts', 'store')->name('admin.programme_cohorts.store');
    Route::get('admin/programme-cohorts/{id}/edit', 'edit')->name('admin.programme_cohorts.edit');
    Route::put('admin/programme-cohorts/{id}', 'update')->name('admin.programme_cohorts.update');
    Route::get('admin/programme-cohorts/{id}', 'show')->name('admin.programme_cohorts.show');
    Route::post('admin/programme-cohorts/{id}/lifecycle', 'lifecycle')->name('admin.programme_cohorts.lifecycle');
    Route::post('admin/programme-cohorts/{id}/members', 'member')->name('admin.programme_cohorts.members.store');
    Route::post('admin/programme-cohort-memberships/{id}/{action}', 'membershipAction')->whereIn('action', ['defer', 'resume', 'transfer', 'withdraw', 'complete'])->name('admin.programme_cohorts.members.action');
    Route::get('admin/programme-cohorts/{id}/placement', 'placement')->name('admin.programme_cohorts.placement.index');
    Route::post('admin/programme-cohorts/{id}/placement/{membershipId}', 'place')->name('admin.programme_cohorts.placement.store');
});

// Personal timezone preference, shared by every portal so the rule
// "NULL means follow your institution" is written down exactly once.
// Presentation only: it can never change the institution's official
// timezone, another user's record, or any stored instant. Deliberately at
// root scope rather than inside a role group, because a lecturer, a student
// and a member of staff must all reach the same screen under the same rules.
Route::middleware('auth')->group(function () {
    Route::get('profile/regional-settings', [RegionalPreferenceController::class, 'edit'])->name('profile.regional.edit');
    Route::post('profile/regional-settings', [RegionalPreferenceController::class, 'update'])->name('profile.regional.update');
});

//Teacher routes are here


Route::controller(TeacherController::class)->middleware('teacher', 'auth')->group(function () {

    Route::get('teacher/dashboard', 'teacherDashboard')->name('teacher.dashboard')->middleware('role_id');

    //Attendance routes
    Route::get('teacher/attendance', 'dailyAttendance')->name('teacher.daily_attendance');
    Route::get('teacher/take_attendance', 'takeAttendance')->name('teacher.take_attendance.open_modal');
    Route::post('teacher/attendance_take', 'attendanceTake')->name('teacher.attendance_take');
    Route::get('teacher/attendance/student', 'studentListAttendance')->name('teacher.attendance.student');
    Route::get('teacher/attendance/filter', 'dailyAttendanceFilter')->name('teacher.daily_attendance.filter');
    Route::get('teacher/attendance/csv', 'dailyAttendanceFilter_csv')->name('teacher.dailyAttendanceFilter_csv');

    //Marks routes
    Route::get('teacher/marks', 'marks')->name('teacher.marks');
    Route::get('teacher/marks/list', 'marksFilter')->name('teacher.marks.list');

    //Offline exam routes
    Route::get('teacher/offline_exam', 'offlineExamList')->name('teacher.offline_exam');
    Route::get('teacher/offline_exam/export/{id}', 'offlineExamExport')->name('teacher.offline_exam.export');
    Route::get('teacher/exam_list_by_class/{id}', 'classWiseOfflineExam')->name('teacher.class_wise_exam_list');

    //Routine routes
    Route::get('teacher/routine', 'routine')->name('teacher.routine');
    Route::get('teacher/routine/list', 'routineList')->name('teacher.routine.routine_list');

    //Subject routes
    Route::get('teacher/subject', 'subjectList')->name('teacher.subject_list');
    Route::get('teacher/subject/create', 'createSubject')->name('teacher.subject.create');
    Route::post('teacher/subject', 'subjectCreate')->name('teacher.subject.store');

    //Gradebook routes
    Route::get('teacher/gradebook', 'gradebook')->name('teacher.gradebook');
    Route::get('teacher/gradebook/list', 'gradebookList')->name('teacher.gradebook.list');
    Route::get('teacher/gradebook/subjec_marks/{student_id}', 'subjectWiseMarks')->name('teacher.gradebook.subject_wise_marks');

    //Syllabus routes
    Route::get('teacher/syllabus', 'list_of_syllabus')->name('teacher.list_of_syllabus');
    Route::get('teacher/class_wise_section_for_syllabus', 'class_wise_section_for_syllabus')->name('teacher.class_wise_section_for_syllabus');
    Route::get('teacher/syllabus_details', 'syllabus_details')->name('teacher.syllabus_details');
    Route::get('teacher/create/syllabus/modal', 'show_syllabus_modal')->name('teacher.show_syllabus_modal');
    Route::post('teacher/create/syllabus/modal/post', 'show_syllabus_modal_post')->name('teacher.show_syllabus_modal_post');
    Route::get('teacher/syllabus/delete/{id}', 'syllabusDelete')->name('teacher.syllabus.delete');

    //Noticeboard routes
    Route::get('teacher/noticeboard', 'noticeboardList')->name('teacher.noticeboard.list');
    Route::get('teacher/noticeboard/{id}', 'editNoticeboard')->name('teacher.edit.noticeboard');

    //Event routes
    Route::get('teacher/events/list', 'eventList')->name('teacher.events.list');

    // Club Management
    Route::get('teacher/club/list', 'club')->name('teacher.club.list');
    Route::get('teacher/club/create', 'createClub')->name('teacher.club.create');
    Route::post('teacher/club/store', 'store')->name('teacher.club.store');
    Route::post('teacher/club/toggle-status/{id}', 'toggleStatus')->name('teacher.club.toggle_status');
    Route::get('teacher/club/edit/{id}', 'editClub')->name('teacher.club.edit');
    Route::post('teacher/club/update/{id}', 'updateClub')->name('teacher.club.update');
    Route::get('teacher/club/delete/{id}', 'deleteClub')->name('teacher.club.delete');
    Route::get('teacher/club/members/{club}', 'clubMembers')->name('teacher.club.members');
    Route::get('teacher/club/member/approve/{id}', 'approveMember')->name('teacher.club.member.approve');
    Route::get('teacher/club/member/disable/{id}', 'member_disable')->name('teacher.club.member.disable');
    Route::post('teacher/club/member/reject/{id}', 'rejectMember')->name('teacher.club.member.reject');
    Route::get('teacher/club/member/delete/{id}', 'deleteMember')->name('teacher.club.member.delete');
    Route::get('teacher/club/member/add/{club}', 'addMemberForm')->name('teacher.club.add_member');
    Route::post('teacher/club/member/store', 'storeMember')->name('teacher.club.member.store');
    Route::get('teacher/club/{club}/members/search', 'searchMembers')->name('teacher.club.members.search');
    Route::get('teacher/club/students/search', 'searchStudents')->name('teacher.club.students.search');

    // club Notice
    Route::get('teacher/club/notice/{club}', 'notice1_index')->name('teacher.club.notice');
    Route::get('teacher/club/{club}/notice/create', 'notice_create')->name('teacher.club.notice.create');
    Route::post('teacher/club/notice/store', 'notice_store')->name('teacher.club.notice.store');
    Route::get('teacher/club/notice/edit/{notice}', 'notice_edit')->name('teacher.club.notice.edit');
    Route::post('teacher/club/notice/update/{notice}', 'notice_update')->name('teacher.club.notice.update');
    Route::get('teacher/club/notice/delete/{notice}', 'notice_delete')->name('teacher.club.notice.delete');


    //Profile
    Route::get('teacher/profile', 'profile')->name('teacher.profile');
    Route::post('teacher/profile/update', 'profile_update')->name('teacher.profile.update');
    Route::any('teacher/password/{action_type}', 'password')->name('teacher.password');
    Route::post('teacher/language', 'user_language')->name('teacher.language');

    // Student Feedback
    Route::get('teacher/feedback-list', 'feedback_list')->name('teacher.feedback.feedback_list');
    Route::get('teacher/feedback-create', 'create_feedback')->name('teacher.feedback.create_feedback');
    Route::post('teacher/feedback-upload', 'upload_feedback')->name('teacher.feedback.upload_feedback');
    Route::get('teacher/feedback-edit/{id}', 'edit_feedback')->name('teacher.feedback.edit_feedback');
    Route::post('teacher/feedback-update/{id}', 'update_feedback')->name('teacher.feedback.update_feedback');
    Route::get('teacher/feedback-delete/{id}', 'delete_feedback')->name('teacher.feedback.delete_feedback');

    // Message
    Route::get('teacher/message/all-message/{id}', 'allMessage')->name('teacher.message.all_message');
    Route::get('teacher/message/message-thrades/{id}', 'messagethrades')->name('teacher.message.messagethrades');
    Route::post('teacher/message/single-chat/save', 'chat_save')->name('teacher.message.chat_save');
    Route::get('teacher/message/chat_empty', 'chat_empty')->name('teacher.message.chat_empty');
});
//Teacher routes end here

//Parent routes are here
Route::controller(ParentController::class)->middleware('parent', 'auth')->group(function () {

    Route::get('parent/dashboard', 'parentDashboard')->name('parent.dashboard')->middleware('role_id');

    //User routes
    Route::get('parent/teacherlist', 'teacherList')->name('parent.teacherlist');
    Route::get('parent/childlist', 'childList')->name('parent.childlist');
    Route::get('parent/student/id_card/{id}', 'studentIdCardGenerate')->name('parent.student.id_card');

    //Fee manager routes
    Route::get('parent/fee_manager', 'FeeManagerList')->name('parent.fee_manager.list');
    Route::get('parent/fee_manager/payment/{id}', 'FeePayment')->name('parent.FeePayment');
    Route::post('parent/fee_manager/payment/{id}/marzpay/start', 'startMarzpayTuitionPayment')->name('parent.payment.marzpay.start');
    Route::get('parent/fee_manager/payment/{id}/marzpay/status', 'checkMarzpayTuitionStatus')->name('parent.payment.marzpay.status');
    Route::get('parent/fee_manager/export/{date_from}/{date_to}/{selected_status}', 'feeManagerExport')->name('parent.fee_manager.export');
    Route::get('parent/student_fee/invoice/{id}', 'studentFeeinvoice')->name('parent.studentFeeinvoice');

    //Grade rotues
    Route::get('parent/grade', 'gradeList')->name('parent.grade_list');

    //Subject routes
    Route::get('parent/child/subjects', 'subjectList')->name('parent.subject_list');
    Route::get('parent/child/subject/list', 'subjectList_by_student_name')->name('parent.subjectList_by_student_name');

    //Syllabus routes
    Route::get('parent/child/syllabus', 'syllabusList')->name('parent.syllabus_list');
    Route::get('parent/child/syllabus/list', 'syllabusList_by_student_name')->name('parent.syllabusList_by_student_name');

    // Legacy PayPal/Stripe/Razorpay/Paytm gateway routes removed (Security Phase 2I): the checkout pages use MarzPay + offline payment only, the legacy gateway partials are never included and the controller methods never existed.


    //Offline payment routes
    Route::post('parent/student_fee/offline_payment/{id}', 'offlinePayment')->name('parent.offline_payment');

    //Routine routes
    Route::get('parent/routine', 'routine')->name('parent.routine');
    Route::get('parent/routine/list', 'routineList')->name('parent.routine.routine_list');

    //Attendence routes
    Route::get('parent/attendence/list', 'list_of_attendence')->name('parent.list_of_attendence');
    Route::get('parent/attendance/filter', 'list_of_attendence')->name('parent.daily_attendance.filter');
    Route::get('parent/attendance/csv', 'dailyAttendanceFilter_csv')->name('parent.dailyAttendanceFilter_csv');

    //Marks routes
    Route::get('parent/marks', 'marks')->name('parent.marks');
    Route::get('parent/marks/list', 'marks_list')->name('parent.marks_list');

    //Noticeboard routes
    Route::get('parent/noticeboard', 'noticeboardList')->name('parent.noticeboard.list');
    Route::get('parent/noticeboard/{id}', 'editNoticeboard')->name('parent.edit.noticeboard');

    //Event routes
    Route::get('parent/events/list', 'eventList')->name('parent.events.list');

    //Profile
    Route::get('parent/profile', 'profile')->name('parent.profile');
    Route::post('parent/profile/update', 'profile_update')->name('parent.profile.update');
    Route::any('parent/password/{action_type}', 'password')->name('parent.password');
    Route::post('parent/language', 'user_language')->name('parent.language');

    // Student Feedback
    Route::get('parent/feedback/filter', 'filter')->name('parent.feedback.filter');
    Route::get('parent/feedback-list', 'feedback_list')->name('parent.feedback.feedback_list');

    // Message
    Route::get('parent/message/all-message/{id}', 'allMessage')->name('parent.message.all_message');
    Route::get('parent/message/message-thrades/{id}', 'messagethrades')->name('parent.message.messagethrades');
    Route::post('parent/message/single-chat/save', 'chat_save')->name('parent.message.chat_save');
    Route::get('parent/message/chat_empty', 'chat_empty')->name('parent.message.chat_empty');
});
//Parent routes end here

//Student routes are here
Route::controller(StudentController::class)->middleware('student', 'auth')->group(function () {

    Route::get('student/dashboard', 'studentDashboard')->name('student.dashboard')->middleware('role_id');
    Route::get('student/id-card', 'idCardGenerate')->name('student.id_card');
    Route::get('student/id-card/pdf', 'idCardPdf')->name('student.id_card.pdf');
    Route::get('student/my-courses', 'myCourses')->name('student.my_courses');

    // ── Course Assignments (student) ─────────────────────────────────────
    // Reads and submissions only. There is deliberately no student route that
    // can create, edit, publish, close or grade: authority over an assignment is
    // an allocation, and a registration must never imply it.
    Route::controller(StudentCourseAssignmentController::class)
        ->prefix('student/courses/{id}/assignments')->whereNumber('id')
        ->name('student.courses.assignments.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/{assignment}', 'show')
                ->whereNumber('assignment')->name('show');
            // Both are POSTs. Saving a draft is NOT submitting, and submitting
            // consumes an attempt - neither may ever be a link.
            Route::post('/{assignment}/draft', 'saveDraft')->whereNumber('assignment')->name('draft');
            Route::post('/{assignment}/submit', 'submit')->whereNumber('assignment')->name('submit');
            Route::get('/{assignment}/submissions/{submission}', 'submission')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.show');
            // The student's own work for one attempt. Authorised exactly as the
            // page that links it, and the only way to reach it: stored paths are
            // outside the web root.
            Route::get('/{assignment}/submissions/{submission}/file', 'submissionFile')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.file');

            // Play back the student's OWN audio or video inline, so a recording they
            // attach can be listened to and watched rather than downloaded and
            // opened in another program.
            //
            // Authorised through resolveSubmissionForStudent(), which proves the
            // attempt belongs to the authenticated student inside the released
            // assignment - so another student's recording is a 404, and the bytes
            // live outside the web root so there is no other way in. A SEPARATE
            // route from evidence because only a strict mime allowlist may be
            // rendered inside a page.
            Route::get('/{assignment}/submissions/{submission}/media/{item}', 'evidenceMedia')
                ->whereNumber('assignment')->whereNumber('submission')->whereNumber('item')
                ->name('submissions.media');

            // ONE piece of that evidence. A submission can carry a written
            // response, a document, a photograph, a recording and a link at once,
            // so each is addressed separately - addressing the attempt alone would
            // assume one file per attempt, which this feature removes.
            //
            // Authorised as above, then scoped to THIS attempt: a confirmed
            // registration, the released assignment, the attempt belonging to THIS
            // student, and the item belonging to THAT attempt.
            Route::get('/{assignment}/submissions/{submission}/evidence/{item}', 'evidenceFile')
                ->whereNumber('assignment')->whereNumber('submission')->whereNumber('item')
                ->name('submissions.evidence');

        });

    // A Course Offering cover image, on the STUDENT's own route and under the
    // student's middleware. The lecturer route cannot serve a student: its
    // middleware group redirects them away before any authorisation runs.
    //
    // A SIBLING of the assignments group rather than a member of it, so the
    // group's own `/{id}/assignments` prefix is not applied again. Both routes
    // call the same service method, so there is one rule with two doors.
    Route::controller(StudentCourseAssignmentController::class)->group(function () {
        Route::get('student/courses/{id}/cover-image', 'coverImage')
            ->whereNumber('id')
            ->name('student.courses.cover');
    });
    // A lecturer's handout, for a student.
    //
    // A SIBLING of the assignments group, not a member of it: the group above
    // carries ->prefix('student/courses/{id}/assignments') and a name prefix, so
    // a route placed inside it gets both applied again. The handout row already
    // carries the Offering, so the URL needs no Offering segment at all.
    //
    // Its own controller group is stated explicitly. The enclosing
    // Route::controller(StudentController::class) block contributes the middleware
    // but NOT the controller, and StudentController has no resource() method - so
    // left to inherit, this binds to nothing and 404s with nothing in the log.
    Route::controller(StudentCourseAssignmentController::class)->group(function () {
        Route::get('student/courses/assignment-resources/{resource}', 'resource')
            ->whereNumber('resource')
            ->name('student.courses.assignments.resources.file');
    });

    // Course Home (the landing page for a confirmed Course Offering).
    //
    // Reads only. There is deliberately no student route that can change anything
    // about a course: authority over content and over assignments is an
    // ALLOCATION, and a confirmed registration must never imply it.
    //
    // Its own controller group, named explicitly - the enclosing
    // `Route::controller(StudentController::class)` block contributes middleware
    // but not a controller binding, so a route left to inherit it would bind to
    // StudentController, which has no `show()` for this, and 404 with nothing in
    // the log to say why.
    Route::controller(StudentCourseController::class)
        ->prefix('student/courses/{id}')
        ->whereNumber('id')
        ->name('student.courses.')
        ->group(function () {
            Route::get('/', 'show')->name('show');

            // Quizzes & Exams.
            //
            // A LIST, and nothing else. Starting, resuming, saving, submitting and
            // reading a released result are all the online exam engine's existing
            // `student.online_exam.*` routes - deliberately not re-registered here,
            // because a second attempt lifecycle would be a second engine.
            Route::get('/exams', [StudentCourseExamsController::class, 'index'])->name('exams');
        });

    // ── Course Content (student reader) ───────────────────────────────────
    // Reads and progress only. There is deliberately no student route that can
    // create, edit, reorder or publish anything: authority for content changes
    // is an allocation, not a registration, and registration must never imply
    // it.
    Route::controller(StudentCourseContentController::class)->prefix('student/courses/{id}')->whereNumber('id')->name('student.courses.')->group(function () {
        Route::get('/content', 'index')->name('content');
        Route::get('/content/lessons/{lesson}', 'show')->whereNumber('lesson')->name('content.lessons.show');
        // Completion is a POST, never a GET: a link prefetch or a crawler
        // following a URL must not be able to mark a lesson complete.
        Route::post('/content/lessons/{lesson}/complete', 'complete')->whereNumber('lesson')->name('content.lessons.complete');
    });
    // Attachment bytes, in their OWN controller group.
    //
    // The group is repeated rather than nested so the URL carries only the
    // resource id: the row already carries the Offering through its lesson, so
    // an Offering id in the path would be a value nobody reads and a second
    // thing that could only contradict the real one. The controller group must
    // be stated explicitly - inside the enclosing `Route::controller(StudentController::class)`
    // this route would silently bind to StudentController and its authorisation
    // would never run at all.
    Route::controller(StudentCourseContentController::class)->group(function () {
        Route::get('student/course-content/resources/{resource}', 'resource')
            ->whereNumber('resource')
            ->name('student.courses.content.resources.show');
    });
    Route::post('student/my-courses/register', 'registerCourses')->name('student.my_courses.register');
    Route::post('student/my-courses/{id}/confirm', 'confirmCourse')->name('student.my_courses.confirm');
    Route::post('student/my-courses/{id}/drop', 'dropCourse')->name('student.my_courses.drop');
    Route::get('student/requests', 'requestsIndex')->name('student.requests.index');
    Route::post('student/requests', 'storeRequest')->name('student.requests.store');
    Route::get('student/transfers', 'transfersIndex')->name('student.transfers.index');
    Route::post('student/transfers', 'storeTransfer')->name('student.transfers.store');

    //User routes
    Route::get('student/teacher', 'teacherList')->name('student.teacher');

    //Attendance routes
    Route::get('student/attendance', 'dailyAttendance')->name('student.daily_attendance');
    Route::get('student/attendance/filter', 'dailyAttendance')->name('student.daily_attendance.filter');
    Route::get('student/attendance/csv', 'dailyAttendanceFilter_csv')->name('student.dailyAttendanceFilter_csv');

    //Routine routes
    Route::get('student/routine', 'routine')->name('student.routine');

    //Subject routes
    Route::get('student/subject', 'subjectList')->name('student.subject_list');

    //Syllabus routes
    Route::get('student/syllabus', 'syllabus')->name('student.syllabus');

    //Grade routes
    Route::get('student/grade', 'gradeList')->name('student.grade_list');

    //Book routes
    Route::get('student/book/list', 'bookList')->name('student.book.book_list');
    Route::get('student/book_issue', 'bookIssueList')->name('student.book.issued_list');

    //Marks routes
    Route::get('student/marks', 'marks')->name('student.marks');

    //Noticeboard routes
    Route::get('student/noticeboard', 'noticeboardList')->name('student.noticeboard.list');
    Route::get('student/noticeboard/{id}', 'editNoticeboard')->name('student.edit.noticeboard');

    //Event routes
    Route::get('student/events/list', 'eventList')->name('student.events.list');

    //Complain routes
    Route::get('student/complain/complain', 'complain')->name('student.complain.complain');
    Route::get('student/complain/complainUser', 'complainUser')->name('student.complain.complainUser');

    //Fee manager routes
    Route::get('student/fee_manager', 'FeeManagerList')->name('student.fee_manager.list');
    Route::get('student/fee_manager/payment/{id}', 'FeePayment')->name('student.FeePayment');
    Route::post('student/fee_manager/payment/{id}/marzpay/start', 'startMarzpayTuitionPayment')->name('student.payment.marzpay.start');
    Route::get('student/fee_manager/payment/{id}/marzpay/status', 'checkMarzpayTuitionStatus')->name('student.payment.marzpay.status');
    Route::get('student/fee_manager/export/{date_from}/{date_to}/{selected_status}', 'feeManagerExport')->name('student.fee_manager.export');
    // Legacy PayPal/Stripe/Razorpay/Paytm gateway routes removed (Security Phase 2I): the checkout pages use MarzPay + offline payment only, the legacy gateway partials are never included and the controller methods never existed.
    Route::post('student/student_fee/offline_payment/{id}', 'offlinePaymentStudent')->name('student.offline_payment');
    Route::get('student/student_fee/invoice/{id}', 'studentFeeinvoice')->name('student.studentFeeinvoice');

    //Profile
    Route::get('student/profile', 'profile')->name('student.profile');
    Route::post('student/profile/update', 'profile_update')->name('student.profile.update');
    Route::any('student/password/{action_type}', 'password')->name('student.password');
    Route::post('student/language', 'user_language')->name('student.language');

    //Appraisal
    Route::get('student/appraisal-list', 'appraisalList')->name('student.appraisal.appraisalList');
    Route::get('student/start-appraisal/{id}', 'singleAppraisal')->name('student.appraisal.singleAppraisal');
    Route::post('student/submit-appraisal/{id}', 'appraisalSubmit')->name('student.appraisal.submit');

    // Message
    Route::get('student/message/all-message/{id}', 'allMessage')->name('student.message.all_message');
    Route::get('student/message/message-thrades/{id}', 'messagethrades')->name('student.message.messagethrades');
    Route::post('student/message/single-chat/save', 'chat_save')->name('student.message.chat_save');
    Route::get('student/message/chat_empty', 'chat_empty')->name('student.message.chat_empty');

    // Hostel
    Route::get('student/hostel-applications', 'hostelApplications')->name('student.hostel.applications');
    Route::get('student/hostel-applications/create', 'applicationCreate')->name('student.hostel.applications.create');
    Route::post('student/hostel-applications/store', 'applicationStore')->name('student.hostel.applications.store');
    Route::get('student/hostel-applications/get-rooms/{hostel_id}', 'applicationRooms')->name('student.hostel.applications.rooms');
    Route::get('student/hostel-applications/{id}/edit', 'applicationEdit')->name('student.hostel.applications.edit');
    Route::post('student/hostel-applications/{id}/update', 'applicationUpdate')->name('student.hostel.applications.update');
    Route::get('student/hostel-applications/{id}/delete', 'applicationDelete')->name('student.hostel.applications.delete');

    // Hostel Fee manager routes
    Route::get('student/hostel_fee_manager', 'hostelFeeManagerList')->name('student.hostel_fee_manager.list');
    Route::get('student/hostel_fee_payment', 'hostelFeePayment')->name('student.hostel_fee.payment');
    Route::post('student/hostel_fee_payment/{id}/marzpay/start', 'startMarzpayHostelPayment')->name('student.hostel.payment.marzpay.start');
    Route::get('student/hostel_fee_payment/{id}/marzpay/status', 'checkMarzpayHostelStatus')->name('student.hostel.payment.marzpay.status');
    Route::get('student/hostel_fee_manager/export/{date_from}/{date_to}/{selected_status}', 'hostelFeeManagerExport')->name('student.hostel_fee_manager.export');
    // Legacy hostel-fee gateway callbacks removed (Security Phase 2I): nothing linked to them and the success
    // callback marked the invoice paid from URL data alone. Hostel fees are paid via MarzPay or offline payment.
    Route::post('student/hostel_fee/offline_payment', 'offlinePaymentHostel')->name('student.offline.payment.hostel');
    Route::get('student/hostel_fee/invoice/{id}', 'hostelFeeInvoice')->name('student.hostel_fee.invoice');
    Route::get('student/hostel_fee/pay/{month}/{year}', 'payMonthlyFee')->name('student.hostel_fee.pay_monthly');

    // Club Management
    Route::get('student/club/list', 'club')->name('student.club.list');
    // student.club.toggle_status removed: StudentController has no toggleStatus and students do not manage
    // club status (they join / cancel a request / leave, below); club status is managed by admin and teacher.
    Route::get('student/club/join/{club}', 'join')->name('club.join');
    Route::get('student/club/remove-request/{club}', 'removeRequest')->name('club.removeRequest');
    Route::get('student/club/leave/{club}', 'leave')->name('club.leave');
    Route::get('student/club/notices/{club}', 'notice_index')->name('student.club.notice');
});
//Student routes end here

// Self-service Exam Results / Transcript (TranscriptController — see its
// studentShow()/studentDownloadPdf(), reusing the exact same
// buildTranscriptViewData()/PDF template the admin-facing transcript uses).
Route::controller(\App\Http\Controllers\TranscriptController::class)->middleware('student', 'auth')->group(function () {
    Route::get('student/exam-results', 'studentShow')->name('student.exam_results');
    Route::get('student/exam-results/pdf', 'studentDownloadPdf')->name('student.exam_results.pdf');
});

// Student Elections / Voting
Route::controller(\App\Http\Controllers\ElectionController::class)->middleware('student', 'auth')->group(function () {
    Route::get('student/elections', 'studentIndex')->name('student.elections.index');
    Route::get('student/elections/{id}', 'studentShow')->name('student.elections.show');
    Route::post('student/elections/{id}/verify', 'verifyIdentity')->name('student.elections.verify');
    Route::post('student/elections/{id}/vote', 'castVote')->name('student.elections.vote');
    Route::get('student/elections/{id}/results', 'studentResults')->name('student.elections.results');
});

//Common routes are here
Route::controller(CommonController::class)->middleware('auth')->group(function () {

    //Filter object routes
    Route::get('section/{id}', 'classWiseSections')->name('class_wise_sections');
    Route::get('subjects/{id}', 'classWiseSubject')->name('class_wise_subject');
    Route::get('students/{id}', 'classWiseStudents')->name('class_wise_student');
    Route::get('class/section/{id}', 'sectionWiseStudents')->name('section_wise_students');
    Route::get('class/section/student/{id}', 'studentWiseParent')->name('student_wise_parent');

    //Grade crud routes
    Route::get('grade/get/{exam_mark}', 'getGrade')->name('get.grade');
    Route::get('mark/update', 'markUpdate')->name('update.mark');

    Route::get('user/{id}', 'idWiseUserName')->name('id_wise_user_name');
});
//Common routes end here

// Notifications — a per-user inbox shared across every role (student,
// teacher, parent, admin, ...), same "Common routes, middleware('auth')
// only" pattern as the CommonController block just above. See
// NotificationController's own docblock: nothing here is role-scoped,
// only auth()->user()->id-scoped.
Route::controller(\App\Http\Controllers\NotificationController::class)->middleware('auth')->group(function () {
    Route::get('notifications', 'index')->name('notifications.index');
    Route::get('notifications/dropdown', 'dropdown')->name('notifications.dropdown');
    Route::get('notifications/unread-count', 'unreadCount')->name('notifications.unread_count');
    Route::post('notifications/{id}/read', 'markRead')->name('notifications.read');
    Route::post('notifications/read-all', 'markAllRead')->name('notifications.read_all');
});
//Notification routes end here

//Accountant routes are here
Route::controller(AccountantController::class)->middleware('accountant', 'auth')->group(function () {

    Route::get('accountant/dashboard', 'accountantDashboard')->name('accountant.dashboard')->middleware('role_id');

    //Fee manager routes
    Route::get('accountant/student_fee_manager', 'studentFeeManagerList')->name('accountant.fee_manager.list');
    Route::get('accountant/student_fee_manager/export/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExport')->name('accountant.fee_manager.export');
    Route::get('accountant/student_fee_manager/pdf_print/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExportPdfPrint')->name('accountant.fee_manager.pdf_print');
    Route::get('accountant/fee_manager_create/{value}', 'createFeeManager')->name('accountant.fee_manager.open_modal');
    Route::post('accountant/fee_manager/{value}', 'feeManagerCreate')->name('accountant.create.fee_manager');
    Route::get('accountant/fee_manager/{id}', 'editFeeManager')->name('accountant.edit.fee_manager');
    Route::post('accountant/fee_manager_list/{id}', 'feeManagerUpdate')->name('accountant.fee_manager.update');
    Route::get('accountant/student_fee/delete/{id}', 'studentFeeDelete')->name('accountant.fee_manager.delete');
    Route::get('accountant/student_fee/invoice/{id}', 'studentFeeinvoice')->name('accountant.studentFeeinvoice');
    Route::get('accountant/student_fee_manager/sync', 'feeSyncForm')->name('accountant.fee_manager.sync');
    Route::post('accountant/student_fee_manager/sync', 'feeSyncGenerate')->name('accountant.fee_manager.sync.generate');

    //Offline payment routes
    Route::get('accountant/offline_payment/pending', 'offline_payment_pending')->name('accountant.offline_payment_pending');
    Route::get('accountant/student_fee/delete/{id}/{status}', 'update_offline_payment')->name('accountant.update_offline_payment');

    //Expenses routes
    Route::get('accountant/expenses/list', 'expenseList')->name('accountant.expense.list');
    Route::get('accountant/expenses/create', 'createExpense')->name('accountant.expenses.open_modal');
    Route::post('accountant/expenses/added', 'expenseCreate')->name('accountant.create.expenses');
    Route::get('accountant/expenses/{id}', 'editExpense')->name('accountant.edit.expenses');
    Route::post('accountant/expenses/{id}', 'expenseUpdate')->name('accountant.expenses.update');
    Route::get('accountant/expenses/delete/{id}', 'expenseDelete')->name('accountant.expense.delete');

    //Expenses category routes
    Route::get('accountant/expense_category/list', 'expenseCategoryList')->name('accountant.expense.category_list');
    Route::get('accountant/expense_category/create', 'createExpenseCategory')->name('accountant.expense_category.open_modal');
    Route::post('accountant/expense_category/added', 'expenseCategoryCreate')->name('accountant.create.expense_category');
    Route::get('accountant/expense_category/{id}', 'editExpenseCategory')->name('accountant.edit.expense_category');
    Route::post('accountant/expense_category/{id}', 'expenseCategoryUpdate')->name('accountant.expense_category.update');
    Route::get('accountant/expense_category/delete/{id}', 'expenseCategoryDelete')->name('accountant.expense.category_delete');

    //Noticeboard routes
    Route::get('accountant/noticeboard', 'noticeboardList')->name('accountant.noticeboard.list');
    Route::get('accountant/noticeboard/{id}', 'editNoticeboard')->name('accountant.edit.noticeboard');

    //Event routes
    Route::get('accountant/events/list', 'eventList')->name('accountant.events.list');

    //Profile
    Route::get('accountant/profile', 'profile')->name('accountant.profile');
    Route::post('accountant/profile/update', 'profile_update')->name('accountant.profile.update');
    Route::any('accountant/password/{action_type}', 'password')->name('accountant.password');
    Route::post('accountant/language', 'user_language')->name('accountant.language');

    //Route::get('accountant/account_disable', 'account_disable')->name('accountant.account_disable');
    // Message
    Route::get('accountant/message/all-message/{id}', 'allMessage')->name('accountant.message.all_message');
    Route::get('accountant/message/message-thrades/{id}', 'messagethrades')->name('accountant.message.messagethrades');
    Route::post('accountant/message/single-chat/save', 'chat_save')->name('accountant.message.chat_save');
    Route::get('accountant/message/chat_empty', 'chat_empty')->name('accountant.message.chat_empty');
});

// ── Bursar ───────────────────────────────────────────────────
// "Bursar" is this school's own name for the Accountant role (role_id 4,
// see BursarMiddleware) — same person, same permissions, reachable under a
// /bursar/... URL too rather than only /accountant/.... Only the finance
// surface is mirrored here (dashboard, fee manager, offline payments); the
// rest of that role's day-to-day work (expenses, messaging, profile) stays
// reachable at its one existing /accountant/... address.
Route::controller(AccountantController::class)->middleware('bursar', 'auth')->group(function () {
    Route::get('bursar/dashboard', 'accountantDashboard')->name('bursar.dashboard')->middleware('role_id');
    Route::get('bursar/student_fee_manager', 'studentFeeManagerList')->name('bursar.fee_manager.list');
    Route::get('bursar/student_fee_manager/export/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExport')->name('bursar.fee_manager.export');
    Route::get('bursar/student_fee_manager/pdf_print/{date_from}/{date_to}/{selected_class}/{selected_status}', 'feeManagerExportPdfPrint')->name('bursar.fee_manager.pdf_print');
    Route::get('bursar/fee_manager_create/{value}', 'createFeeManager')->name('bursar.fee_manager.open_modal');
    Route::post('bursar/fee_manager/{value}', 'feeManagerCreate')->name('bursar.create.fee_manager');
    Route::get('bursar/fee_manager/{id}', 'editFeeManager')->name('bursar.edit.fee_manager');
    Route::post('bursar/fee_manager_list/{id}', 'feeManagerUpdate')->name('bursar.fee_manager.update');
    Route::get('bursar/student_fee/delete/{id}', 'studentFeeDelete')->name('bursar.fee_manager.delete');
    Route::get('bursar/student_fee/invoice/{id}', 'studentFeeinvoice')->name('bursar.studentFeeinvoice');
    Route::get('bursar/student_fee_manager/sync', 'feeSyncForm')->name('bursar.fee_manager.sync');
    Route::post('bursar/student_fee_manager/sync', 'feeSyncGenerate')->name('bursar.fee_manager.sync.generate');
    Route::get('bursar/offline_payment/pending', 'offline_payment_pending')->name('bursar.offline_payment_pending');
    Route::get('bursar/student_fee/delete/{id}/{status}', 'update_offline_payment')->name('bursar.update_offline_payment');
});

//Warden routes are here
Route::controller(WardenController::class)->middleware('warden', 'auth')->group(function () {

    Route::get('warden/dashboard', 'wardenDashboard')->name('warden.dashboard')->middleware('role_id');

    // Hostel Room
    Route::get('warden/hostel-room-list', 'hostel_room_list')->name('warden.hostel.room_list');
    // Room create/edit/delete routes removed: WardenController never had those methods (every call was a 500)
    // and the Warden room page is read-only; rooms are managed in the admin portal.

    // Hostel Room Allocation
    Route::get('warden/hostel-room-allocation-list', 'hostel_room_allocation_list')->name('warden.hostel.allocation_list');
    Route::get('warden/hostel-room-allocation-create', 'create_hostel_room_allocation')->name('warden.hostel.create_allocation');
    Route::post('warden/hostel-room-allocation-store', 'store_hostel_room_allocation')->name('warden.hostel.store_allocation');
    Route::get('warden/hostel-room-allocation-edit/{id}', 'edit_hostel_room_allocation')->name('warden.hostel.edit_allocation');
    Route::post('warden/hostel-room-allocation-update/{id}', 'update_hostel_room_allocation')->name('warden.hostel.update_allocation');
    Route::get('warden/hostel-room-allocation-delete/{id}', 'delete_hostel_room_allocation')->name('warden.hostel.delete_allocation');
    Route::get('warden/hostel-applications', 'applications')->name('warden.hostel.applications');
    Route::get('warden/hostel-applications/approve/{id}', 'approveApplication')->name('warden.hostel.applications.approve');
    Route::get('warden/hostel-applications/reject/{id}', 'rejectApplication')->name('warden.hostel.applications.reject');

    Route::get('warden/hostel-fee-manager', 'hostelFees')->name('warden.hostel_fee_manager.list');
    Route::get('warden/hostel-fee/offline-payment/list', 'offlinePaymentList')->name('warden.offline.payment.hostel.list');

    Route::get('warden/hostel_fee/offline_payment/{id}', 'acceptOfflinePaymentHostel')->name('warden.accept.offline.payment.hostel');
    Route::get('warden/hostel_fee/offline_payment/reject/{id}', 'rejectOfflinePaymentHostel')->name('warden.reject.offline.payment.hostel');

    //Noticeboard routes
    Route::get('warden/noticeboard', 'noticeboardList')->name('warden.noticeboard.list');
    Route::get('warden/noticeboard/{id}', 'editNoticeboard')->name('warden.edit.noticeboard');

    //Event routes
    Route::get('warden/events/list', 'eventList')->name('warden.events.list');

    //Profile
    Route::get('warden/profile', 'profile')->name('warden.profile');
    Route::post('warden/profile/update', 'profile_update')->name('warden.profile.update');
    Route::any('warden/password/{action_type}', 'password')->name('warden.password');
    Route::post('warden/language', 'user_language')->name('warden.language');

    // Message
    Route::get('warden/message/all-message/{id}', 'allMessage')->name('warden.message.all_message');
    Route::get('warden/message/message-thrades/{id}', 'messagethrades')->name('warden.message.messagethrades');
    Route::post('warden/message/single-chat/save', 'chat_save')->name('warden.message.chat_save');
    Route::get('warden/message/chat_empty', 'chat_empty')->name('warden.message.chat_empty');
});
//Accountant routes end here

//Librarian routes are here
Route::controller(LibrarianController::class)->middleware('librarian', 'auth')->group(function () {

    Route::get('librarian/dashboard', 'librarianDashboard')->name('librarian.dashboard')->middleware('role_id');

    //Book routes
    Route::get('librarian/book/list', 'bookList')->name('librarian.book.book_list');
    Route::get('librarian/book/create', 'createBook')->name('librarian.book.open_modal');
    Route::post('librarian/book/added', 'bookCreate')->name('librarian.create.book');
    Route::get('librarian/book/{id}', 'editBook')->name('librarian.edit.book');
    Route::post('librarian/book/{id}', 'bookUpdate')->name('librarian.book.update');
    Route::get('librarian/book/delete/{id}', 'bookDelete')->name('librarian.book.delete');

    //Book issue routes
    Route::get('librarian/book_issue', 'bookIssueList')->name('librarian.book_issue.list');
    Route::get('librarian/book_issue/create', 'createBookIssue')->name('librarian.book_issue.open_modal');
    Route::post('librarian/book_issue/added', 'bookIssueCreate')->name('librarian.create.book_issue');
    Route::get('librarian/book_issue/{id}', 'editBookIssue')->name('librarian.edit.book_issue');
    Route::post('librarian/book_issue/{id}', 'bookIssueUpdate')->name('librarian.book_issue.update');
    Route::get('librarian/book_issue/return/{id}', 'bookIssueReturn')->name('librarian.book_issue.return');
    Route::get('librarian/book_issue/delete/{id}', 'bookIssueDelete')->name('librarian.book_issue.delete');

    //Noticeboard routes
    Route::get('librarian/noticeboard', 'noticeboardList')->name('librarian.noticeboard.list');
    Route::get('librarian/noticeboard/{id}', 'editNoticeboard')->name('librarian.edit.noticeboard');

    //Event routes
    Route::get('librarian/events/list', 'eventList')->name('librarian.events.list');

    //Profile
    Route::get('librarian/profile', 'profile')->name('librarian.profile');
    Route::post('librarian/profile/update', 'profile_update')->name('librarian.profile.update');
    Route::any('librarian/password/{action_type}', 'password')->name('librarian.password');
    Route::post('librarian/language', 'user_language')->name('librarian.language');
    Route::get('librarian/message/chat_empty', 'chat_empty')->name('librarian.message.chat_empty');
});
//Librarian routes end here

//Updater routes are here
Route::controller(Updater::class)->middleware('superAdmin', 'auth')->group(function () {

    Route::post('superadmin/addon/create', 'update')->name('superadmin.addon.create');
    Route::post('superadmin/addon/update', 'update')->name('superadmin.addon.update');
    Route::post('superadmin/product/update', 'update')->name('superadmin.product.update');
});
//Updater routes end here

//Installation routes are here
Route::controller(InstallController::class)->middleware('is_installed')->group(function () {

    Route::get('install/step0', 'step0')->name('step0');
    Route::get('install/step1', 'step1')->name('step1');
    Route::get('install/step2', 'step2')->name('step2');
    Route::any('install/step3', 'step3')->name('step3');
    Route::get('install/step4', 'step4')->name('step4');
    Route::get('install/step4/{confirm_import}', 'confirmImport')->name('step4.confirm_import');
    Route::get('install/install', 'confirmInstall')->name('confirm_install');
    Route::post('install/validate', 'validatePurchaseCode')->name('install.validate');
    Route::any('install/finalizing_setup', 'finalizingSetup')->name('finalizing_setup');
    Route::get('install/success', 'success')->name('success');
});
//Installation routes end here

// ═══════════════════════════════════════════════════════════════
// HEI FEATURE ROUTES — Phase 1-4 Implementation
// ═══════════════════════════════════════════════════════════════

// ── Programmes ────────────────────────────────────────────────
Route::controller(ProgrammeController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/programmes',                  'index')->name('admin.programmes.index');
    Route::get('admin/programmes/export',           'exportCsv')->name('admin.programmes.export');
    Route::get('admin/programmes/open_modal',       'openModal')->name('admin.programmes.open_modal');
    Route::post('admin/programmes/store',           'store')->name('admin.programmes.store');
    Route::post('admin/programmes/update/{id}',     'update')->name('admin.programmes.update');
    Route::get('admin/programmes/delete/{id}',      'destroy')->name('admin.programmes.destroy');
    Route::get('admin/programmes/toggle/{id}',      'toggleStatus')->name('admin.programmes.toggle');
});

// ── Governed Programme Curricula ────────────────────────────────
Route::controller(\App\Http\Controllers\CurriculumController::class)->middleware('auth', 'admin', 'rbac')->prefix('admin/curricula')->name('admin.curricula.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/{id}/subjects/search', 'searchSubjects')->whereNumber('id')->name('subjects.search');
    Route::get('/{id}', 'show')->whereNumber('id')->name('show');
    Route::put('/{id}', 'update')->whereNumber('id')->name('update');
    Route::post('/{id}/stages', 'storeStage')->whereNumber('id')->name('stages.store');
    Route::put('/{id}/stages/{stageId}', 'updateStage')->whereNumber('id')->whereNumber('stageId')->name('stages.update');
    Route::post('/{id}/stages/{stageId}/move', 'moveStage')->whereNumber('id')->whereNumber('stageId')->name('stages.move');
    Route::delete('/{id}/stages/{stageId}', 'destroyStage')->whereNumber('id')->whereNumber('stageId')->name('stages.destroy');
    Route::post('/{id}/memberships', 'addMemberships')->whereNumber('id')->name('memberships.store');
    Route::put('/{id}/memberships/{membershipId}', 'updateMembership')->whereNumber('id')->whereNumber('membershipId')->name('memberships.update');
    Route::delete('/{id}/memberships/{membershipId}', 'destroyMembership')->whereNumber('id')->whereNumber('membershipId')->name('memberships.destroy');
    Route::post('/{id}/prerequisites', 'addPrerequisite')->whereNumber('id')->name('prerequisites.store');
    Route::delete('/{id}/prerequisites', 'destroyPrerequisite')->whereNumber('id')->name('prerequisites.destroy');
    Route::post('/{id}/approve', 'approve')->whereNumber('id')->name('approve');
    Route::post('/{id}/retire', 'retire')->whereNumber('id')->name('retire');
    Route::post('/{id}/successor', 'successor')->whereNumber('id')->name('successor');
});

// Course Offering administration (delivery instances; distinct from the Subject catalogue).
Route::controller(\App\Http\Controllers\CourseOfferingController::class)->middleware('auth', 'admin', 'rbac')->prefix('admin/course-offerings')->name('admin.course_offerings.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::post('/{id}/applicability', 'addApplicability')->whereNumber('id')->name('applicability.store');
    Route::delete('/{id}/applicability/{membershipId}', 'removeApplicability')->whereNumber('id')->whereNumber('membershipId')->name('applicability.destroy');
    Route::post('/{id}/open', 'lifecycle')->defaults('action', 'open')->whereNumber('id')->name('open');
    Route::post('/{id}/start', 'lifecycle')->defaults('action', 'start')->whereNumber('id')->name('start');
    Route::post('/{id}/start-early', 'lifecycle')->defaults('action', 'startEarly')->whereNumber('id')->name('start_early');
    Route::post('/{id}/complete', 'lifecycle')->defaults('action', 'complete')->whereNumber('id')->name('complete');
    Route::post('/{id}/cancel', 'lifecycle')->defaults('action', 'cancel')->whereNumber('id')->name('cancel');
    Route::get('/{id}/eligible-students', 'eligibleStudents')->whereNumber('id')->name('eligible_students');
    Route::get('/{id}/registrations', 'registeredStudents')->whereNumber('id')->name('registrations');
    Route::post('/{id}/registrations', 'registerStudent')->whereNumber('id')->name('registrations.store');
    Route::post('/{id}/registrations/{registration}/drop', 'dropStudent')->whereNumber('id')->whereNumber('registration')->name('registrations.drop');
    Route::post('/{id}/registrations/bulk', 'registerBulk')->whereNumber('id')->name('registrations.bulk');
    Route::post('/{id}/registrations/confirm', 'confirmBulk')->whereNumber('id')->name('registrations.confirm_bulk');
    Route::post('/{id}/registrations/{registration}/confirm', 'confirmStudent')->whereNumber('id')->whereNumber('registration')->name('registrations.confirm');
    Route::controller(\App\Http\Controllers\CourseOfferingLecturerController::class)->prefix('/{offering}/lecturers')->name('lecturers.')->group(function () {
        Route::get('/', 'index')->whereNumber('offering')->name('index');
        Route::get('/history', 'history')->whereNumber('offering')->name('history');
        Route::get('/create', 'create')->whereNumber('offering')->name('create');
        Route::post('/', 'store')->whereNumber('offering')->name('store');
        Route::put('/{allocation}', 'update')->whereNumber('offering')->whereNumber('allocation')->name('update');
        Route::post('/{allocation}/activate', 'activate')->whereNumber('offering')->whereNumber('allocation')->name('activate');
        Route::post('/{allocation}/end', 'end')->whereNumber('offering')->whereNumber('allocation')->name('end');
        Route::post('/{allocation}/cancel', 'cancel')->whereNumber('offering')->whereNumber('allocation')->name('cancel');
        Route::post('/{allocation}/replace', 'replace')->whereNumber('offering')->whereNumber('allocation')->name('replace');
    });
    Route::put('/{id}', 'update')->whereNumber('id')->name('update');
    Route::get('/{id}', 'show')->whereNumber('id')->name('show');
});

// Offering-contextual Live Class creation. The Offering ID is route context,
// never a client-supplied field on the legacy K12 creation endpoints.
Route::controller(LiveClassController::class)->middleware('auth', 'admin', 'rbac')
    ->prefix('admin/course-offerings/{courseOffering}/live-classes')
    ->name('admin.course_offerings.live_classes.')
    ->group(function () {
        Route::get('/create', 'createForOffering')->whereNumber('courseOffering')->name('create');
        Route::post('/', 'storeForOffering')->whereNumber('courseOffering')->name('store');
    });

// ── Admissions ────────────────────────────────────────────────
Route::controller(AdmissionsController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    // Applications
    Route::get('admin/hei-admissions',                         'index')->name('admin.hei_admissions.index');
    Route::get('admin/hei-admissions/open_modal',              'openModal')->name('admin.hei_admissions.open_modal');
    Route::post('admin/hei-admissions/store',                  'store')->name('admin.hei_admissions.store');
    Route::post('admin/hei-admissions/status/{id}',            'updateStatus')->name('admin.hei_admissions.status');
    Route::get('admin/hei-admissions/delete/{id}',             'destroy')->name('admin.hei_admissions.destroy');
    Route::get('admin/hei-admissions/offer-letter/{id}',       'printOfferLetter')->name('admin.hei_admissions.offer_letter');
    Route::get('admin/hei-admissions/export',                  'exportApplicationsCsv')->name('admin.hei_admissions.export');
    // Review workspace — must stay below /export and /open_modal so those
    // literal segments are not swallowed by the {id} wildcard.
    Route::get('admin/hei-admissions/review/{id}',             'review')->name('admin.hei_admissions.review');
    Route::post('admin/hei-admissions/review/{id}/correction', 'requestCorrection')->name('admin.hei_admissions.correction');
    Route::post('admin/hei-admissions/review/{id}/notes',      'saveNotes')->name('admin.hei_admissions.notes');
    Route::post('admin/hei-admissions/document/{id}/review',   'reviewDocument')->name('admin.hei_admissions.document.review');
    Route::post('admin/hei-admissions/payment/{id}/review',    'reviewPayment')->name('admin.hei_admissions.payment.review');
    Route::post('admin/hei-admissions/payment/{id}/request',   'sendPaymentRequest')->name('admin.hei_admissions.payment.request');
    Route::post('admin/hei-admissions/payment/{id}/record',    'recordPayment')->name('admin.hei_admissions.payment.record');
    Route::post('admin/hei-admissions/payment/{id}/waive',     'waiveFee')->name('admin.hei_admissions.payment.waive');
    // Document requirements
    Route::get('admin/admissions-documents',                   'documentRequirements')->name('admin.admissions_documents.index');
    Route::post('admin/admissions-documents/store',            'storeDocumentRequirement')->name('admin.admissions_documents.store');
    Route::post('admin/admissions-documents/update/{id}',      'storeDocumentRequirement')->name('admin.admissions_documents.update');
    Route::get('admin/admissions-documents/delete/{id}',       'destroyDocumentRequirement')->name('admin.admissions_documents.destroy');
    Route::get('admin/admissions-documents/restore-defaults',  'restoreDefaultDocumentRequirements')->name('admin.admissions_documents.restore');
    // Intake Sessions
    Route::get('admin/intake-sessions',                        'sessions')->name('admin.intake_sessions.index');
    Route::get('admin/intake-sessions/open_modal',             'openSessionModal')->name('admin.intake_sessions.open_modal');
    Route::post('admin/intake-sessions/store',                 'storeSession')->name('admin.intake_sessions.store');
    Route::post('admin/intake-sessions/update/{id}',           'updateSession')->name('admin.intake_sessions.update');
    Route::get('admin/intake-sessions/delete/{id}',            'destroySession')->name('admin.intake_sessions.destroy');
    Route::get('admin/intake-sessions/export',                 'exportSessionsCsv')->name('admin.intake_sessions.export');
    // Agents
    Route::get('admin/admissions-agents',                      'agents')->name('admin.admissions_agents.index');
    Route::get('admin/admissions-agents/open_modal',           'openAgentModal')->name('admin.admissions_agents.open_modal');
    Route::post('admin/admissions-agents/store',               'storeAgent')->name('admin.admissions_agents.store');
    Route::post('admin/admissions-agents/update/{id}',         'updateAgent')->name('admin.admissions_agents.update');
    Route::get('admin/admissions-agents/delete/{id}',          'destroyAgent')->name('admin.admissions_agents.destroy');
    Route::get('admin/admissions-agents/export',               'exportAgentsCsv')->name('admin.admissions_agents.export');
});

// ── Staff-entry Admission Wizard ──────────────────────────────
// The admin-side counterpart of the Applicant Portal's 5-step wizard —
// same Admission model/workflow, reached from "New Student Admission"
// instead of the public /apply flow. Academic assignment (Step 6) is not
// here — it is the existing admin.hei_admissions.review screen, shared
// with online-application decisions.
Route::controller(AdmissionWizardController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/hei-admissions/wizard/create',              'create')->name('admin.hei_admissions.wizard.create');
    Route::get('admin/hei-admissions/wizard/{id}/{step}',         'step')->name('admin.hei_admissions.wizard.step');
    Route::post('admin/hei-admissions/wizard/{id}/personal',      'savePersonal')->name('admin.hei_admissions.wizard.personal');
    Route::post('admin/hei-admissions/wizard/{id}/programme',     'saveProgramme')->name('admin.hei_admissions.wizard.programme');
    Route::post('admin/hei-admissions/wizard/{id}/education',     'saveEducation')->name('admin.hei_admissions.wizard.education');
    Route::post('admin/hei-admissions/wizard/{id}/documents',            'saveDocument')->name('admin.hei_admissions.wizard.documents.store');
    Route::post('admin/hei-admissions/wizard/{id}/documents/{documentId}/delete', 'destroyDocument')->name('admin.hei_admissions.wizard.documents.destroy');
    Route::post('admin/hei-admissions/wizard/{id}/submit',        'submit')->name('admin.hei_admissions.wizard.submit');
});

// ── Fee Structures ────────────────────────────────────────────
Route::controller(FeeStructureController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/fee-structures',              'index')->name('admin.fee_structures.index');
    Route::get('admin/fee-structures/open_modal',   'openModal')->name('admin.fee_structures.open_modal');
    Route::post('admin/fee-structures/store',       'store')->name('admin.fee_structures.store');
    Route::post('admin/fee-structures/update/{id}', 'update')->name('admin.fee_structures.update');
    Route::get('admin/fee-structures/delete/{id}',  'destroy')->name('admin.fee_structures.destroy');
});

// ── Leave Management ──────────────────────────────────────────
Route::controller(LeaveController::class)->middleware('auth', 'hr_manager')->group(function () {
    Route::get('admin/leave',                    'index')->name('admin.leave.index');
    Route::post('admin/leave/approve/{id}',      'approve')->name('admin.leave.approve');
    Route::post('admin/leave/return/{id}',       'returnLeave')->name('admin.leave.return');
    Route::post('admin/leave/reject/{id}',       'reject')->name('admin.leave.reject');
    Route::get('admin/leave/delete/{id}',        'destroy')->name('admin.leave.destroy');
    // Leave Types
    Route::get('admin/leave-types',             'types')->name('admin.leave_types.index');
    Route::get('admin/leave-types/modal',       'typeModal')->name('admin.leave_types.modal');
    Route::post('admin/leave-types/store',      'storeType')->name('admin.leave_types.store');
    Route::get('admin/leave-types/delete/{id}', 'destroyType')->name('admin.leave_types.destroy');
});

// ── Leave Management: Staff Self-Service ────────────────────────
Route::controller(LeaveController::class)->middleware('auth', 'staff')->group(function () {
    Route::get('staff/leave',              'myIndex')->name('staff.leave.index');
    Route::get('staff/leave/open_modal',   'myOpenModal')->name('staff.leave.open_modal');
    Route::post('staff/leave/store',       'myStore')->name('staff.leave.store');
});

// ── Online Exams / CBT ────────────────────────────────────────
Route::controller(OnlineExamController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/online-exams',                         'index')->name('admin.online_exams.index');
    Route::get('admin/online-exams/create',                  'create')->name('admin.online_exams.create');
    // Must stay above the admin.online_exams.show {id} route below — Laravel
    // matches routes in registration order, and a literal single-segment
    // path like this one is otherwise swallowed by {id} (id becomes the
    // literal string "open_modal", (int) casts to 0, the exam lookup 404s,
    // and the "Create Exam" modal hangs on "Loading..." forever since its
    // AJAX call has no error handler to surface the failure).
    Route::get('admin/online-exams/open_modal',              'openModal')->name('admin.online_exams.open_modal');
    Route::get('admin/online-exams/{id}',                    'show')->name('admin.online_exams.show');
    Route::get('admin/online-exams/{id}/edit',               'edit')->name('admin.online_exams.edit');
    Route::post('admin/online-exams/store',                  'store')->name('admin.online_exams.store');
    Route::post('admin/online-exams/update/{id}',            'update')->name('admin.online_exams.update');
    Route::post('admin/online-exams/publish/{id}',           'publish')->name('admin.online_exams.publish');
    Route::post('admin/online-exams/unpublish/{id}',         'unpublish')->name('admin.online_exams.unpublish');
    Route::post('admin/online-exams/cancel/{id}',            'cancel')->name('admin.online_exams.cancel');
    Route::post('admin/online-exams/lock/{id}',              'lock')->name('admin.online_exams.lock');
    Route::delete('admin/online-exams/delete/{id}',          'destroy')->name('admin.online_exams.destroy');
    Route::get('admin/online-exams/{id}/questions',          'questions')->name('admin.online_exams.questions');
    Route::post('admin/online-exams/{id}/questions/store',   'storeQuestion')->name('admin.online_exams.questions.store');
    Route::post('admin/online-exams/questions/update/{id}',  'updateQuestion')->name('admin.online_exams.questions.update');
    Route::post('admin/online-exams/questions/remove/{id}',  'deleteQuestion')->name('admin.online_exams.questions.remove');
    Route::delete('admin/online-exams/questions/delete/{id}', 'destroyQuestion')->name('admin.online_exams.questions.destroy');
    Route::get('admin/online-exams/{id}/submissions',        'submissions')->name('admin.online_exams.submissions');
    Route::get('admin/online-exams/{id}/results',            'results')->name('admin.online_exams.results');
    // Results AWAITING AN ADMINISTRATOR'S DECISION, across every exam in the
    // institution. Registered here, ABOVE `admin/online-exams/{id}`, because that
    // route's `{id}` is unconstrained and would otherwise swallow the literal.
    Route::get('admin/online-exams-results-review',           'resultReviewQueue')->name('admin.online_exams.result_review_queue');
    Route::get('admin/online-exams/{id}/proctoring/{submission}', 'reviewProctoring')->name('admin.online_exams.proctoring.review');
    Route::post('admin/online-exams/answers/{answer}/manual-mark', 'manualMarking')->name('admin.online_exams.answers.manual_mark');
    Route::post('admin/online-exams/submissions/{submission}/finalize', 'finalizeResult')->name('admin.online_exams.submissions.finalize');
    Route::post('admin/online-exams/submissions/{submission}/publish-result', 'publishResult')->name('admin.online_exams.submissions.publish_result');
    Route::post('admin/online-exams/submissions/{submission}/return', 'returnResultForCorrection')->name('admin.online_exams.submissions.return');
    // Recovery for a result the old student-submit path marked `finalized` WITHOUT
    // handing it over - a state no current path can reach, and which therefore
    // belongs to no queue and admits no action.
    //
    // ADMINISTRATOR ONLY. Deliberately NOT a lecturer route, and deliberately NOT
    // named with "reopen":
    // `CourseOfferingAttendanceTest::test_lecturer_can_never_reopen_a_finalised_register`
    // sweeps EVERY route URI in the application for the substring "reopen", because a
    // lecturer able to undo a state transition is the hazard that test guards
    // against. Rather than narrow that guard, this is (a) an admin action, and
    // (b) named `return-to-marking`, which matches the vocabulary already used for
    // `.../return` (returned_for_correction). The lecturer is shown the anomaly and
    // escalates.
    //
    // Audited, and refused for any coherent row. See adminReopenMarkingForReview().
    Route::post('admin/online-exams/submissions/{submission}/return-to-marking', 'adminReopenMarkingForReview')->name('admin.online_exams.submissions.return_to_marking');
    // Question Bank
    Route::get('admin/question-bank',                        'questionBank')->name('admin.question_bank.index');
    Route::get('admin/question-bank/metadata',               'questionMetadata')->name('admin.question_bank.metadata');
    Route::post('admin/question-bank/metadata/topics',       'storeQuestionTopic')->name('admin.question_bank.metadata.topics.store');
    Route::post('admin/question-bank/metadata/subtopics',    'storeQuestionSubtopic')->name('admin.question_bank.metadata.subtopics.store');
    Route::put('admin/question-bank/metadata/topics/{id}',   'updateQuestionTopic')->name('admin.question_bank.metadata.topics.update');
    Route::post('admin/question-bank/metadata/topics/{id}/toggle', 'toggleQuestionTopic')->name('admin.question_bank.metadata.topics.toggle');
    Route::post('admin/question-bank/metadata/tags',         'storeQuestionTag')->name('admin.question_bank.metadata.tags.store');
    Route::put('admin/question-bank/metadata/tags/{id}',     'updateQuestionTag')->name('admin.question_bank.metadata.tags.update');
    Route::post('admin/question-bank/metadata/tags/{id}/toggle', 'toggleQuestionTag')->name('admin.question_bank.metadata.tags.toggle');
    Route::get('admin/question-bank/modal',                  'bankModal')->name('admin.question_bank.modal');
    Route::get('admin/question-bank/import/modal',            'bankImportModal')->name('admin.question_bank.import_modal');
    Route::post('admin/question-bank/store',                 'storeBankQuestion')->name('admin.question_bank.store');
    Route::post('admin/question-bank/import',                'importBankQuestions')->name('admin.question_bank.import');
    Route::get('admin/question-bank/import/template',        'downloadBankImportTemplate')->name('admin.question_bank.import_template');
    Route::put('admin/question-bank/update/{id}',            'updateBankQuestion')->name('admin.question_bank.update');
    Route::delete('admin/question-bank/delete/{id}',         'destroyBankQuestion')->name('admin.question_bank.delete');
    Route::post('admin/online-exams/{exam}/question-bank/import', 'adminImportQuestion')->name('admin.online_exams.question_bank.import');
    // Per-exam question modal
    Route::get('admin/online-exams/{id}/question_modal',     'questionModal')->name('admin.online_exams.question_modal');
});

// ── Student CBT exam routes ────────────────────────────────────
Route::controller(OnlineExamController::class)->middleware('auth', 'student')->group(function () {
    Route::get('student/online-exams',              'studentExams')->name('student.online_exam.list');
    Route::get('student/online-exams/{id}/instructions', 'instructions')->name('student.online_exam.instructions');
    Route::post('student/online-exams/{id}/start',  'start')->name('student.online_exam.start');
    Route::get('student/online-exams/submissions/{submission}/resume', 'resume')->name('student.online_exam.resume');
    Route::post('student/online-exams/submissions/{submission}/readiness', 'readiness')->name('student.online_exam.readiness');
    Route::post('student/online-exams/submissions/{submission}/save-answer', 'saveAnswer')->name('student.online_exam.save_answer');
    Route::post('student/online-exams/submissions/{submission}/heartbeat', 'heartbeat')->name('student.online_exam.heartbeat');

    // Restricted-mode incidents (focus lost/returned, tab hidden, connection lost or
    // restored) recorded against the student's OWN attempt. Records evidence only: it
    // never submits, penalises or disqualifies, because no institutional policy
    // authorises an automatic consequence for leaving the page.
    Route::post('student/online-exams/submissions/{submission}/incident', 'recordExamIncident')->name('student.online_exam.incident');
    Route::post('student/online-exams/submissions/{submission}/proctoring-event', 'proctoringEvent')->name('student.online_exam.proctoring_event');
    Route::post('student/online-exams/submissions/{submission}/timeout-submit', 'timeoutSubmit')->name('student.online_exam.timeout_submit');
    Route::get('student/online-exams/{id}/take',    'takeExam')->name('student.online_exam.take');
    Route::post('student/online-exams/{id}/submit', 'submitExam')->name('student.online_exam.submit');
    Route::get('student/online-exams/result/{id}',  'examResult')->name('student.online_exam.result');
});

// ── Teacher CBT exam routes ───────────────────────────────────
Route::controller(OnlineExamController::class)->middleware('auth', 'teacher')->group(function () {
    Route::get('teacher/online-exams', 'teacherIndex')->name('teacher.online_exams.index');
    Route::get('teacher/online-exams/create', 'teacherCreate')->name('teacher.online_exams.create');
    Route::post('teacher/online-exams', 'teacherStore')->name('teacher.online_exams.store');
    // Must stay above the teacher.online_exams.show {exam} route below — Laravel
    // matches routes in declaration order, and {exam} would otherwise swallow
    // this literal path first and 404 on model binding.
    Route::get('teacher/online-exams/question-bank', 'teacherQuestionBank')->name('teacher.online_exams.question_bank');
    Route::post('teacher/online-exams/question-bank', 'teacherStoreBankQuestion')->name('teacher.online_exams.question_bank.store');
    Route::get('teacher/online-exams/marking/queue', 'teacherMarking')->name('teacher.online_exams.marking');
    // Live Monitor — teacher-facing real-time view of who's taking an exam
    // right now; not present on the governed-workflow branch, so it must
    // stay explicit here rather than being assumed to survive a merge.
    Route::get('teacher/online-exams/live-monitor', 'teacherLiveMonitor')->name('teacher.online_exams.live_monitor');
    Route::get('teacher/online-exams/{exam}', 'teacherShow')->name('teacher.online_exams.show');
    Route::get('teacher/online-exams/{exam}/edit', 'teacherEdit')->name('teacher.online_exams.edit');
    Route::put('teacher/online-exams/{exam}', 'teacherUpdate')->name('teacher.online_exams.update');
    Route::delete('teacher/online-exams/{exam}', 'teacherDestroy')->name('teacher.online_exams.destroy');

    Route::get('teacher/online-exams/{exam}/preview', 'teacherPreview')->name('teacher.online_exams.preview');
    Route::post('teacher/online-exams/{exam}/submit-review', 'teacherSubmitForReview')->name('teacher.online_exams.submit_review');
    Route::post('teacher/online-exams/{exam}/publish', 'teacherPublish')->name('teacher.online_exams.publish');
    Route::post('teacher/online-exams/{exam}/unpublish', 'teacherUnpublish')->name('teacher.online_exams.unpublish');
    Route::post('teacher/online-exams/{exam}/cancel', 'teacherCancel')->name('teacher.online_exams.cancel');

    Route::get('teacher/online-exams/{exam}/questions', 'teacherQuestions')->name('teacher.online_exams.questions.index');
    Route::post('teacher/online-exams/{exam}/questions', 'teacherStoreQuestion')->name('teacher.online_exams.questions.store');
    Route::put('teacher/online-exams/questions/{question}', 'teacherUpdateQuestion')->name('teacher.online_exams.questions.update');
    Route::delete('teacher/online-exams/questions/{question}', 'teacherDeleteQuestion')->name('teacher.online_exams.questions.destroy');
    Route::post('teacher/online-exams/{exam}/questions/reorder', 'teacherReorderQuestions')->name('teacher.online_exams.questions.reorder');

    Route::post('teacher/online-exams/{exam}/question-bank/import', 'teacherImportQuestion')->name('teacher.online_exams.question_bank.import');

    Route::get('teacher/online-exams/question-bank/modal', 'teacherBankModal')->name('teacher.online_exams.question_bank.modal');
    Route::get('teacher/online-exams/question-bank/import/modal', 'teacherBankImportModal')->name('teacher.online_exams.question_bank.import_modal');
    Route::post('teacher/online-exams/question-bank/upload', 'teacherImportBankQuestions')->name('teacher.online_exams.question_bank.upload');
    Route::get('teacher/online-exams/question-bank/import/template', 'teacherDownloadBankImportTemplate')->name('teacher.online_exams.question_bank.import_template');
    Route::get('teacher/online-exams/question-bank/delete/{id}', 'teacherDestroyBankQuestion')->name('teacher.online_exams.question_bank.delete');

    Route::get('teacher/online-exams/{exam}/attempts', 'teacherAttempts')->name('teacher.online_exams.attempts');
    Route::get('teacher/online-exams/{exam}/proctoring/{submission_id}', 'teacherReviewProctoring')->name('teacher.online_exams.proctoring.review');
    Route::get('teacher/online-exams/{exam}/results', 'teacherResults')->name('teacher.online_exams.results');
    Route::post('teacher/online-exams/answers/{answer}/mark', 'teacherMarkAnswer')->name('teacher.online_exams.answers.mark');

    // Recording a marking decision keyed by (submission, question) rather than by
    // answer id, because a question the student left blank may have NO answer row at
    // all — and that is exactly the case a lecturer must still be able to decide.
    // Without this, exam 17 submission 12's question 39 was visible as outstanding but
    // had nothing to act on, so "Submit Marks for Admin Review" could only ever 422.
    // Registered above the results/{submission} routes so it is never shadowed.
    Route::post('teacher/online-exams/submissions/{submission}/questions/{question}/decision', 'recordQuestionDecision')
        ->name('teacher.online_exams.submissions.record_decision');
    Route::post('teacher/online-exams/results/{submission}/finalize', 'teacherFinalizeResult')->name('teacher.online_exams.results.finalize');
    // Recovery for a result that was marked finalized WITHOUT being handed over -
    // the unreachable state written by the old student-submit path. ADMINISTRATOR
    // ONLY: no lecturer route may reopen a state transition. See
    // adminReopenMarkingForReview().
    Route::post('teacher/online-exams/results/{submission}/publish', 'publishResult')->name('teacher.online_exams.results.publish');
});

// ── Assignments (admin/teacher) ───────────────────────────────
Route::controller(AssignmentController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/assignments',                            'index')->name('admin.assignments.index');
    Route::get('admin/assignments/open_modal',                 'openModal')->name('admin.assignments.open_modal');
    Route::post('admin/assignments/store',                     'store')->name('admin.assignments.store');
    Route::post('admin/assignments/update/{id}',               'update')->name('admin.assignments.update');
    Route::get('admin/assignments/delete/{id}',                'destroy')->name('admin.assignments.destroy');
    Route::get('admin/assignments/{id}/submissions',           'submissions')->name('admin.assignments.submissions');
    Route::post('admin/assignments/grade/{submission_id}',     'gradeSubmission')->name('admin.assignments.grade');
});

// ── Student Assignment routes ──────────────────────────────────
Route::controller(AssignmentController::class)->middleware('auth', 'student')->group(function () {
    Route::get('student/assignments',                'studentList')->name('student.assignments.list');
    Route::get('student/assignments/{id}/modal',     'submitModal')->name('student.assignments.modal');
    Route::post('student/assignments/{id}/submit',   'studentSubmit')->name('student.assignments.submit');
});

// ── Live Classes ──────────────────────────────────────────────
Route::controller(LiveClassController::class)->middleware('auth')->group(function () {
    Route::get('live-classes/{liveClass}/materials/{material}/access', 'accessMaterial')->name('live_classes.materials.access');
    Route::get('live-classes/{liveClass}/recording/access', 'accessRecording')->name('live_classes.recording.access');
});

Route::controller(LiveClassController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/live-classes',                   'index')->name('admin.live_classes.index');
    Route::get('admin/live-classes/create',            'create')->name('admin.live_classes.create');
    Route::post('admin/live-classes',                  'store')->name('admin.live_classes.store');
    Route::post('admin/live-classes/meet-now',         'meetNow')->name('admin.live_classes.meet_now');
    // Must stay above the admin.live_classes.show {liveClass} route below —
    // Laravel matches routes in declaration order, and {liveClass} would
    // otherwise swallow this literal path first and 404 on model binding.
    Route::get('admin/live-classes/meet-guests',           'meetGuests')->name('admin.live_classes.meet_guests');
    Route::post('admin/live-classes/meet-guests',          'storeMeetGuest')->name('admin.live_classes.meet_guests.store');
    Route::delete('admin/live-classes/meet-guests/{id}',   'destroyMeetGuest')->name('admin.live_classes.meet_guests.destroy');
    Route::get('admin/live-classes/{liveClass}',       'show')->name('admin.live_classes.show');
    Route::get('admin/live-classes/{liveClass}/edit',  'edit')->name('admin.live_classes.edit');
    Route::put('admin/live-classes/{liveClass}',       'update')->name('admin.live_classes.update');
    Route::delete('admin/live-classes/{liveClass}',    'destroy')->name('admin.live_classes.destroy');

    Route::post('admin/live-classes/{liveClass}/cancel',  'cancel')->name('admin.live_classes.cancel');
Route::post('admin/live-classes/{liveClass}/recording', 'attachRecording')->name('admin.live_classes.recording.attach');
    // "End Class": the lecturer finished teaching. Deliberately a different
    // action from cancel (the class will not run) and from unpublish (withdraw
    // it from students). It records completion and preserves all history.
    Route::post('admin/live-classes/{liveClass}/end',     'end')->name('admin.live_classes.end');
    Route::post('admin/live-classes/{liveClass}/publish', 'publish')->name('admin.live_classes.publish');
    Route::get('admin/live-classes/{liveClass}/join',     'join')->name('admin.live_classes.join');
    Route::post('admin/live-classes/{liveClass}/attendance-leave', 'attendanceLeave')->name('admin.live_classes.attendance_leave');

    Route::get('admin/live-classes/{liveClass}/attendance',        'attendance')->name('admin.live_classes.attendance');
    Route::get('admin/live-classes/{liveClass}/attendance/export', 'attendanceExport')->name('admin.live_classes.attendance_export');

    Route::get('admin/live-classes/{liveClass}/materials',         'materials')->name('admin.live_classes.materials');
    Route::post('admin/live-classes/{liveClass}/materials',        'storeMaterial')->name('admin.live_classes.materials.store');
    Route::delete('admin/live-classes/materials/{material}',       'destroyMaterial')->name('admin.live_classes.materials.destroy');

    // Backward compatibility with existing modal workflow.
    Route::get('admin/live-classes/open_modal',           'openModal')->name('admin.live_classes.open_modal');
    Route::post('admin/live-classes/store',               'store')->name('admin.live_classes.store_legacy');
    Route::post('admin/live-classes/update/{liveClass}',  'update')->name('admin.live_classes.update_legacy');
    Route::get('admin/live-classes/delete/{liveClass}',   'destroy')->name('admin.live_classes.destroy_legacy');
});

Route::controller(LiveClassController::class)->middleware('auth', 'student')->group(function () {
    Route::get('student/live-classes',                    'studentIndex')->name('student.live_classes.index');
    // The student's read-only detail page. A notification's "View Live Class"
    // lands here, NOT on /join: joining is a separate, deliberate action that
    // is only available inside the join window. Pointing the CTA straight at
    // /join meant a student who clicked "View" on a perfectly valid scheduled
    // class was told "Joining is not available for this meeting right now",
    // which reads as a broken class rather than a class that has not started.
    Route::get('student/live-classes/{liveClass}',         'studentShow')->name('student.live_classes.show');
    Route::get('student/live-classes/{liveClass}/join',   'join')->name('student.live_classes.join');
    Route::post('student/live-classes/{liveClass}/attendance-leave', 'attendanceLeave')->name('student.live_classes.attendance_leave');
    Route::get('student/live-classes/{liveClass}/materials', 'materials')->name('student.live_classes.materials');
});

// Lecturer academic workspace (HEI Course Offerings).
// My Course Offerings and the per-Offering teaching workspace are resolved
// exclusively from the signed-in lecturer's own CourseOfferingLecturerAllocation,
// tenant-checked in LecturerCourseOfferingAccess; no hidden navigation, no id trust.
Route::controller(TeacherCourseOfferingController::class)->middleware('auth', 'teacher')->prefix('teacher/course-offerings')->name('teacher.course_offerings.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{id}', 'show')->whereNumber('id')->name('show');
                // A Course Offering cover image, served through an authorising route
            // because the bytes live OUTSIDE the web root. Nothing renders this
            // yet: the card design is deferred, and a link with no design around
            // it is a stub. The capability and the safe read path exist first so
            // the image never has to be moved later.
                        // A LEAF name, not a full one: the enclosing group already applies
            // ->name('teacher.course_offerings.'), and a full name here would
            // register DOUBLED. The URI above is relative for the same reason.
            Route::get('/{id}/cover-image', 'coverImage')->whereNumber('id')->name('cover');

    // The course cover UPLOAD, on the same group as the read above - so the same
    // prefix, the same name prefix and the same `auth, teacher` middleware apply
    // without being restated, and a URL cannot end up doubled.
    //
    // The bytes now HAVE somewhere to come from. `CourseCoverImage` was written as
    // capability with no interface: the relationship, the safe read path and
    // `set()`/`clear()` behind an allocation check all existed, and nothing called
    // them - so a lecturer had no way to give a course a cover and the read path
    // led to nothing. The service enforces the allocation, the extension
    // allowlist, the 4 MB limit, the private location and the disposal of the
    // superseded file; these routes only hand it the upload.
    Route::post('/{id}/cover-image', 'setCoverImage')->whereNumber('id')->name('cover.set');
    Route::post('/{id}/cover-image/remove', 'clearCoverImage')->whereNumber('id')->name('cover.clear');
Route::get('/{id}/students', 'students')->whereNumber('id')->name('students');
    // ── Course Offering Quizzes & Exams ─────────────────────────────────────
    //
    // A CONTEXT BINDER, not an exam engine. `store()` creates an assessment bound
    // to this Offering and then redirects to the ENGINE's own question page
    // (`teacher.online_exams.questions.index`), so authoring, the Question Bank,
    // the manual marking queue, publication and result release all stay in the one
    // place they have always lived.
    //
    // The Offering in the URL is the authority for both sides: the lecturer must be
    // allocated to it, and the exam must belong to it. Neither is inferred from
    // the request body.
    Route::controller(TeacherCourseOfferingExamController::class)
        ->prefix('/{id}/exams')->whereNumber('id')->name('exams.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
        });

    // ── Course Offering Assignments (lecturer author) ───────────────────
    // Authoring is a full page, never a drawer: an assignment is a document.
    // See TeacherCourseOfferingAssignmentController for why every write
    // re-resolves the assignment inside the Offering in the URL.
    Route::controller(TeacherCourseOfferingAssignmentController::class)
        ->prefix('/{id}/assignments')->whereNumber('id')->name('assignments.')
        ->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{assignment}', 'show')->whereNumber('assignment')->name('show');
            Route::get('/{assignment}/edit', 'edit')->whereNumber('assignment')->name('edit');
            Route::put('/{assignment}', 'update')->whereNumber('assignment')->name('update');
            Route::delete('/{assignment}', 'destroy')->whereNumber('assignment')->name('destroy');

            // Preview as a student will read it. Also POSTs, so an unsaved
            // draft can be checked before committing.
            Route::get('/{assignment}/preview', 'preview')->whereNumber('assignment')->name('preview');
            Route::post('/{assignment}/preview', 'preview')->whereNumber('assignment')->name('preview.draft');

            // Lifecycle: draft -> scheduled/published -> closed. One action, with
            // the target state in the URL, so the permitted moves are the ones
            // AssignmentLifecycle allows and nothing else is reachable.
            Route::post('/{assignment}/state/{to}', 'transition')
                ->whereNumber('assignment')->whereIn('to', ['draft', 'scheduled', 'published', 'closed'])
                ->name('state');

            // -- The question builder ------------------------------------------------
            //
            // RELATIVE paths and LEAF names throughout: the enclosing group already
            // applies ->prefix('/{id}/assignments') and ->name('assignments.'). A
            // leading slash or a dotted name here would register
            // teacher/course-offerings/teacher/course-offerings/... and present as a
            // 404 with nothing in the log. Declared before the /{question} routes so
            // the file reads in the order a browser matches.
            Route::get('/{assignment}/questions', 'questions')->whereNumber('assignment')->name('questions');
            Route::post('/{assignment}/questions', 'storeQuestion')->whereNumber('assignment')->name('questions.store');
            Route::post('/{assignment}/questions/order', 'reorderQuestions')->whereNumber('assignment')->name('questions.reorder');
            // Make the assignment total equal the sum of its questions: the one-click
            // that makes mark integrity achievable without a lecturer adding up the
            // paper they just wrote.
            Route::post('/{assignment}/questions/adopt-marks', 'adoptQuestionMarks')
                ->whereNumber('assignment')->name('questions.adopt_marks');
            Route::put('/{assignment}/questions/{question}', 'updateQuestion')
                ->whereNumber('assignment')->whereNumber('question')->name('questions.update');
            Route::delete('/{assignment}/questions/{question}', 'destroyQuestion')
                ->whereNumber('assignment')->whereNumber('question')->name('questions.destroy');

            // Marking
            Route::get('/{assignment}/submissions', 'submissions')->whereNumber('assignment')->name('submissions');
            Route::get('/{assignment}/submissions/{submission}', 'submission')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.show');
            // The file a student submitted, for marking. Authorised exactly as
            // the page that links it, and it is the only way to reach one: the
            // stored path is outside the web root.
            Route::get('/{assignment}/submissions/{submission}/file', 'submissionFile')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.file');

            // Play back the student's OWN audio or video inline, so a recording they
            // attach can be listened to and watched rather than downloaded and
            // opened in another program.
            //
            // Authorised through resolveSubmissionForStudent(), which proves the
            // attempt belongs to the authenticated student inside the released
            // assignment - so another student's recording is a 404, and the bytes
            // live outside the web root so there is no other way in. A SEPARATE
            // route from evidence because only a strict mime allowlist may be
            // rendered inside a page.
            Route::get('/{assignment}/submissions/{submission}/media/{item}', 'evidenceMedia')
                ->whereNumber('assignment')->whereNumber('submission')->whereNumber('item')
                ->name('submissions.media');
            // ONE piece of a student's evidence, for marking. The submission is
            // resolved through the manager path first, which proves a current
            // allocation on the Offering in the URL and that the submission
            // belongs to the assignment in that Offering; the item is then scoped
            // to THAT attempt.
            Route::get('/{assignment}/submissions/{submission}/evidence/{item}', 'evidenceFile')
                ->whereNumber('assignment')->whereNumber('submission')->whereNumber('item')
                ->name('submissions.evidence');

            // Mark a QUESTION-BASED attempt question by question.
            //
            // This route HAS NO marks_awarded INPUT, by design. The total is the sum
            // of the per-question marks, so there is nothing a lecturer could type
            // that would contradict the marks they just gave.
            Route::post('/{assignment}/submissions/{submission}/grade-by-question', 'gradeByQuestion')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.grade_by_question');

            // Render ONE evidence item inline, for a marker.
            //
            // A SEPARATE route from evidence, not a ?inline=1 flag on it. Whether a
            // file is safe to render inside a page belongs in the route table, not
            // in whatever built the URL. This one streams and refuses any mime not
            // on a strict allowlist; evidence always downloads.
            Route::get('/{assignment}/submissions/{submission}/media/{item}', 'evidenceMedia')
                ->whereNumber('assignment')->whereNumber('submission')->whereNumber('item')
                ->name('submissions.media');

            // Recording a mark and RETURNING it to the student are separate
            // actions, because recording one must not be the same as publishing it.
            Route::post('/{assignment}/submissions/{submission}/grade', 'grade')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.grade');
            Route::post('/{assignment}/submissions/{submission}/release', 'release')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.release');
            Route::post('/{assignment}/submissions/{submission}/unrelease', 'unrelease')
                ->whereNumber('assignment')->whereNumber('submission')->name('submissions.unrelease');

            // Lecturer handouts
            Route::post('/{assignment}/resources', 'storeResource')->whereNumber('assignment')->name('resources.store');
        });

    // Handout bytes. RELATIVE paths, because the enclosing group already applies
    // ->prefix('teacher/course-offerings') and ->name('teacher.course_offerings.')
    // to every route declared inside it. A full path AND a full name here
    // registers DOUBLED - teacher/course-offerings/teacher/course-offerings/...
    // - which presents as a puzzling failure only on the data shape that
    // reaches the bad line. The resource row already carries the Offering, so
    // the Offering id does not need to be in this path at all.
    //
    // The controller group is stated explicitly: left to inherit the enclosing
    // TeacherCourseOfferingController, these bind to a class with no
    // resourceFile() method and 404 with nothing in the log.
    Route::controller(TeacherCourseOfferingAssignmentController::class)->group(function () {
        Route::post('assignment-resources/{resource}/delete', 'destroyResource')
            ->whereNumber('resource')
            ->name('assignments.resources.destroy');
        Route::get('assignment-resources/{resource}', 'resourceFile')
            ->whereNumber('resource')
            ->name('assignments.resources.file');
    });

    // ── Course Content (lecturer author) ─────────────────────────────────
    // Full-page authoring, never a drawer or modal. Content lives inside a
    // module, which lives inside this Offering - the Offering is the only
    // delivery container, so there is no second Course and no second content
    // tree to drift from it.
    //
    // The group sets the route-name PREFIX and the prefix is applied by the
    // enclosing group's own name(), so the `name()` here supplies only the
    // leaf. Chaining a second name() would OVERWRITE the prefix rather than
    // append to it, which is a silent and very confusing way to lose a route.
    Route::controller(CourseOfferingContentController::class)->prefix('/{id}/content')->whereNumber('id')->name('content.')->group(function () {
        Route::get('/', 'index')->name('index');

        Route::post('/modules', 'storeModule')->name('modules.store');
        Route::put('/modules/{module}', 'updateModule')->whereNumber('module')->name('modules.update');
        // THE MODULE LIFECYCLE, as ONE route with the target state in the URL.
        //
        // The same shape `assignments/{assignment}/state/{to}` already uses in this
        // file, and for the same reason: the legal moves are decided in
        // `CourseOfferingModuleLifecycle`, so the reachable moves are exactly those
        // the rules permit and nothing else can be reached by editing the address.
        //
        // `scheduled` is NOT a status. It is `published` with a future
        // `released_at`, already how `displayStatusLabel()` renders it and how
        // `isReleasedToStudents()` decides visibility - so a fourth column value
        // would create a SECOND answer to "may a student see this" rather than
        // removing one. `released_at` is optional here: absent means publish NOW.
        Route::post('/modules/{module}/state/{to}', 'transitionModule')
        ->whereNumber('module')
        ->whereIn('to', ['draft', 'published'])
        ->name('modules.transition');
        Route::post('/modules/order', 'reorderModules')->name('modules.reorder');
        // Lesson order is scoped to a module, and the module is re-resolved
        // inside the Offering - so a reorder cannot move a lesson between
        // modules, Offerings or tenants, only renumber within one module.
        Route::post('/modules/{module}/lessons/order', 'reorderLessons')
            ->whereNumber('module')
            ->name('lessons.reorder');

        Route::get('/lessons/create', 'createLesson')->name('lessons.create');
        Route::post('/lessons', 'storeLesson')->name('lessons.store');
        Route::get('/lessons/{lesson}/edit', 'editLesson')->whereNumber('lesson')->name('lessons.edit');
        Route::put('/lessons/{lesson}', 'updateLesson')->whereNumber('lesson')->name('lessons.update');
        Route::get('/lessons/{lesson}/preview', 'previewLesson')->whereNumber('lesson')->name('lessons.preview');
        // The editor also POSTs here, carrying the fields currently in the form,
        // so "Preview" shows unsaved work rather than the last saved version.
        // `preview_draft` is the flag that tells the controller to prefer the
        // request body - and to hold it to the same sanitizer as a save, so
        // preview cannot become a way to render unfiltered HTML.
        Route::post('/lessons/{lesson}/preview', 'previewLesson')->whereNumber('lesson')->name('lessons.preview.draft');

        Route::post('/lessons/{lesson}/resources', 'storeResource')->whereNumber('lesson')->name('resources.store');
    });

    // Attachment bytes.
    //
    // The controller group is stated explicitly. Left to inherit the enclosing
    // `Route::controller(TeacherCourseOfferingController::class)`, this binds to
    // a controller that has no `resourceFile` method - a 404 with no error
    // anywhere, because the method simply does not exist over there.
    //
    // The path and the name are RELATIVE, and that is the load-bearing part.
    // The enclosing group already carries ->prefix('teacher/course-offerings')
    // and ->name('teacher.course_offerings.'), and Laravel applies BOTH to
    // every route declared inside it - including routes in a nested group. So
    // writing the full path and the full name here does not make the route
    // absolute; it prefixes them a SECOND time and registers
    //   uri   teacher/course-offerings/teacher/course-offerings/content/...
    //   name  teacher.course_offerings.teacher.course_offerings.content....
    // A view asking for `route('teacher.course_offerings.content.resources.file')`
    // then throws "Route not defined" - and ONLY on a lesson that actually has
    // an attachment, because the template resolves that route inside the loop
    // over the lesson's resources. Every other route in this group is relative
    // for exactly this reason.
    Route::controller(CourseOfferingContentController::class)->group(function () {
        Route::get('content/resources/{resource}', 'resourceFile')
            ->whereNumber('resource')
            ->name('content.resources.file');
    });

    // Offering-contextual Live Class creation for a LECTURER, mirroring the
    // admin group. These are the SAME controller actions the admin uses, so the
    // Offering remains the single authoritative delivery container: the Offering
    // id is route context and is never a client-supplied field, and the
    // authorization is the identical LiveClassAccessService check
    // (canLecturerCreateForOffering), which routes through the single shared
    // SystemTesterAccess definition for a pre-start tester. The generic
    // teacher/live-classes/create form is left alone: it is the legacy
    // Class/Section route and cannot express Offering context.
    Route::controller(LiveClassController::class)->prefix('/{id}/live-classes')->name('live_classes.')->group(function () {
        Route::get('/create', 'createForOffering')->whereNumber('id')->name('create');
        Route::post('/', 'storeForOffering')->whereNumber('id')->name('store');
    });
});

// Lecturer attendance for a Course Offering. Separate tables from the K12 daily
// register and from Live Class participation: a session is one teaching
// occurrence and a record is one student's status in it. Read-only for a
// lecturer without a current allocation on an in-progress Offering.
Route::controller(TeacherCourseOfferingAttendanceController::class)->middleware('auth', 'teacher')->prefix('teacher/course-offerings')->name('teacher.course_offerings.attendance.')->group(function () {
    Route::get('/{id}/attendance', 'index')->whereNumber('id')->name('index');
    Route::get('/{id}/attendance/create', 'create')->whereNumber('id')->name('create');
    Route::post('/{id}/attendance', 'store')->whereNumber('id')->name('store');
    Route::get('/{id}/attendance/{session}', 'show')->whereNumber('id')->whereNumber('session')->name('show');
    Route::post('/{id}/attendance/{session}', 'mark')->whereNumber('id')->whereNumber('session')->name('mark');
    Route::post('/{id}/attendance/{session}/finalise', 'finalise')->whereNumber('id')->whereNumber('session')->name('finalise');
    // No lecturer reopen route: a finalised register is read-only to the lecturer.
    // Reopening belongs to a future governed Admin/Academic Office workflow.
});

/*
| ── Google OAuth ───────────────────────────────────────────────────────────
|
| The callback path `/auth/google/callback` is the URI registered in the Google
| Cloud console and MUST NOT be changed without changing the registration too.
| Google compares it exactly; a mismatch is `redirect_uri_mismatch` at the token
| exchange, which reads like a credentials problem and is not one.
|
| `auth` only — no role middleware. The flow itself authorises nothing: it
| connects a Google account to whoever is signed in. What that account may then
| be used FOR is decided per-request by the lecturer's own allocation
| (LiveClassAccessService), not here. Putting a role gate on the OAuth handshake
| would couple "can start a class" to "may connect an account", which are not the
| same question.
|
| Disconnect is POST: it changes state, so it must not be reachable by a GET that
| a prefetcher, a chat client or a crawler's link preview could trigger.
*/
Route::controller(GoogleAuthController::class)->middleware('auth')->group(function () {
    Route::get('auth/google/connect',     'redirect')->name('google.auth.connect');
    Route::get('auth/google/callback',    'callback')->name('google.auth.callback');
    Route::post('auth/google/disconnect', 'disconnect')->name('google.auth.disconnect');
});

Route::controller(LiveClassController::class)->middleware('auth', 'teacher')->group(function () {
    Route::get('teacher/live-classes',                    'index')->name('teacher.live_classes.index');
    Route::get('teacher/live-classes/create',             'create')->name('teacher.live_classes.create');
    Route::post('teacher/live-classes',                   'store')->name('teacher.live_classes.store');
    Route::post('teacher/live-classes/meet-now',          'meetNow')->name('teacher.live_classes.meet_now');
    Route::get('teacher/live-classes/{liveClass}',        'show')->name('teacher.live_classes.show');
    Route::get('teacher/live-classes/{liveClass}/edit',   'edit')->name('teacher.live_classes.edit');
    Route::put('teacher/live-classes/{liveClass}',        'update')->name('teacher.live_classes.update');
    Route::delete('teacher/live-classes/{liveClass}',     'destroy')->name('teacher.live_classes.destroy');

    Route::post('teacher/live-classes/{liveClass}/cancel',  'cancel')->name('teacher.live_classes.cancel');
Route::post('teacher/live-classes/{liveClass}/recording', 'attachRecording')->name('teacher.live_classes.recording.attach');
    Route::post('teacher/live-classes/{liveClass}/end',     'end')->name('teacher.live_classes.end');
    Route::post('teacher/live-classes/{liveClass}/publish', 'publish')->name('teacher.live_classes.publish');
    Route::get('teacher/live-classes/{liveClass}/join',     'join')->name('teacher.live_classes.join');
    Route::post('teacher/live-classes/{liveClass}/attendance-leave', 'attendanceLeave')->name('teacher.live_classes.attendance_leave');

    Route::get('teacher/live-classes/{liveClass}/attendance',        'attendance')->name('teacher.live_classes.attendance');
    Route::get('teacher/live-classes/{liveClass}/attendance/export', 'attendanceExport')->name('teacher.live_classes.attendance_export');

    Route::get('teacher/live-classes/{liveClass}/materials',         'materials')->name('teacher.live_classes.materials');
    Route::post('teacher/live-classes/{liveClass}/materials',        'storeMaterial')->name('teacher.live_classes.materials.store');
    Route::delete('teacher/live-classes/materials/{material}',       'destroyMaterial')->name('teacher.live_classes.materials.destroy');

    Route::get('teacher/live-classes/open_modal',           'openModal')->name('teacher.live_classes.open_modal');
    Route::post('teacher/live-classes/store',               'store')->name('teacher.live_classes.store_legacy');
    Route::post('teacher/live-classes/update/{liveClass}',  'update')->name('teacher.live_classes.update_legacy');
    Route::get('teacher/live-classes/delete/{liveClass}',   'destroy')->name('teacher.live_classes.destroy_legacy');
});

// ── Academic Calendar ─────────────────────────────────────────
Route::controller(AcademicCalendarController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/academic-calendar',              'index')->name('admin.academic_calendar.index');
    Route::get('admin/academic-calendar/open_modal',   'openModal')->name('admin.academic_calendar.open_modal');
    Route::post('admin/academic-calendar/store',       'store')->name('admin.academic_calendar.store');
    Route::post('admin/academic-calendar/update/{id}', 'update')->name('admin.academic_calendar.update');
    Route::get('admin/academic-calendar/delete/{id}',  'destroy')->name('admin.academic_calendar.destroy');
    Route::get('admin/academic-calendar/events.json',  'eventsJson')->name('admin.academic_calendar.events_json');
});

Route::get('calendar/events.json', [AcademicCalendarController::class, 'eventsJson'])
    ->middleware('auth')->name('calendar.events_json');

// ── Payroll ───────────────────────────────────────────────────
Route::controller(PayrollController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/payroll',                      'index')->name('admin.payroll.index');
    Route::post('admin/payroll/generate',            'generate')->name('admin.payroll.generate');
    Route::get('admin/payroll/approve/{id}',         'approve')->name('admin.payroll.approve');
    Route::get('admin/payroll/paid/{id}',            'markPaid')->name('admin.payroll.paid');
    Route::get('admin/payroll/print/{id}',           'printSlip')->name('admin.payroll.print');
    Route::get('admin/payroll/export',               'exportCsv')->name('admin.payroll.export');
    Route::get('admin/salary-structures',            'salaryIndex')->name('admin.salary_structures.index');
    Route::get('admin/salary-structures/modal',      'salaryModal')->name('admin.salary_structures.modal');
    Route::post('admin/salary-structures/store',     'storeSalary')->name('admin.salary_structures.store');
});

Route::controller(PayrollController::class)->middleware('auth', 'teacher')->group(function () {
    Route::get('teacher/payslips', 'staffPayslips')->name('teacher.payroll.index');
});

// ── Graduation ────────────────────────────────────────────────
Route::controller(GraduationController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/graduation',               'index')->name('admin.graduation.index');
    Route::get('admin/graduation/open_modal',    'openApplyModal')->name('admin.graduation.open_modal');
    Route::post('admin/graduation/store',        'store')->name('admin.graduation.store');
    Route::get('admin/graduation/approve/{id}',  'approve')->name('admin.graduation.approve');
    Route::get('admin/graduation/graduate/{id}', 'graduate')->name('admin.graduation.graduate');
    Route::get('admin/graduation/delete/{id}',   'destroy')->name('admin.graduation.destroy');
});

Route::controller(GraduationController::class)->middleware('auth', 'student')->group(function () {
    Route::get('student/graduation/apply',   'studentApply')->name('student.graduation.apply');
    Route::post('student/graduation/submit', 'studentStore')->name('student.graduation.store');
});

// ── Assets ────────────────────────────────────────────────────
Route::controller(AssetController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/assets',                        'index')->name('admin.assets.index');
    Route::get('admin/assets/open_modal',             'openModal')->name('admin.assets.open_modal');
    Route::post('admin/assets/store',                 'store')->name('admin.assets.store');
    Route::post('admin/assets/update/{id}',           'update')->name('admin.assets.update');
    Route::get('admin/assets/delete/{id}',            'destroy')->name('admin.assets.destroy');
    Route::get('admin/assets/export',                 'exportCsv')->name('admin.assets.export');
    Route::get('admin/asset-categories',              'categories')->name('admin.asset_categories.index');
    Route::get('admin/asset-categories/modal',        'categoryModal')->name('admin.asset_categories.modal');
    Route::post('admin/asset-categories/store',       'storeCategory')->name('admin.asset_categories.store');
    Route::get('admin/asset-categories/delete/{id}',  'destroyCategory')->name('admin.asset_categories.destroy');
});

// ── Procurement ───────────────────────────────────────────────
Route::controller(ProcurementController::class)->middleware('auth', 'admin', 'rbac')->group(function () {
    Route::get('admin/procurement',                'index')->name('admin.procurement.index');
    Route::get('admin/procurement/open_modal',     'openModal')->name('admin.procurement.open_modal');
    Route::post('admin/procurement/store',         'store')->name('admin.procurement.store');
    Route::post('admin/procurement/status/{id}',   'updateStatus')->name('admin.procurement.status');
    Route::get('admin/procurement/delete/{id}',    'destroy')->name('admin.procurement.destroy');
    Route::get('admin/procurement/export',         'exportCsv')->name('admin.procurement.export');
});

// ── Audit Log ─────────────────────────────────────────────────
Route::controller(AuditLogController::class)->middleware('auth')->group(function () {
    Route::get('admin/audit-log', 'index')->name('admin.audit_log.index')->middleware('admin', 'rbac');
    Route::get('admin/audit-log/{id}', 'show')->name('admin.audit_log.show')->middleware('admin', 'rbac');
    Route::get('superadmin/audit-log', 'index')->name('superadmin.audit_log.index')->middleware('superAdmin');
    Route::get('superadmin/audit-log/{id}', 'show')->name('superadmin.audit_log.show')->middleware('superAdmin');
});

// ── Transcripts ───────────────────────────────────────────────
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\TranscriptController::class)->group(function () {
    Route::get('admin/transcripts',              'index')->name('admin.transcripts.index');
    Route::get('admin/transcripts/search',       'search')->name('admin.transcripts.search');
    Route::get('admin/transcripts/{id}/view',    'show')->name('admin.transcripts.show');
    Route::get('admin/transcripts/{id}/pdf',     'downloadPdf')->name('admin.transcripts.pdf');
});

// ── Student Affairs (requests review) ──────────────────────────
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\StudentRequestController::class)->group(function () {
    Route::get('admin/student-requests',          'index')->name('admin.student_requests.index');
    Route::post('admin/student-requests/{id}',    'update')->name('admin.student_requests.update');
});

// ── Elections / Voting ──────────────────────────────────────────
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\ElectionController::class)->group(function () {
    Route::get('admin/elections',                       'index')->name('admin.elections.index');
    Route::get('admin/elections/create',                'create')->name('admin.elections.create');
    Route::post('admin/elections',                      'store')->name('admin.elections.store');
    Route::get('admin/elections/{id}',                  'show')->name('admin.elections.show');
    Route::post('admin/elections/{electionId}/positions', 'storePosition')->name('admin.elections.positions.store');
    Route::post('admin/election-positions/{positionId}/candidates', 'storeCandidate')->name('admin.elections.candidates.store');
    Route::post('admin/elections/{id}/publish-results', 'publishResults')->name('admin.elections.publish_results');
});

// ── Reports & Analytics ───────────────────────────────────────
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\ReportsController::class)->group(function () {
    Route::get('admin/reports',                  'index')->name('admin.reports.index');
    Route::get('admin/reports/students',         'studentsReport')->name('admin.reports.students');
    Route::get('admin/reports/finance',          'financeReport')->name('admin.reports.finance');
    Route::get('admin/reports/attendance',       'attendanceReport')->name('admin.reports.attendance');
    Route::get('admin/reports/exams',            'examsReport')->name('admin.reports.exams');
    Route::get('admin/reports/export/{type}',    'export')->name('admin.reports.export');
});

// ── Enhanced Settings ─────────────────────────────────────────
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\EnhancedSettingsController::class)->group(function () {
    Route::get('admin/settings/academic',        'academic')->name('admin.settings.academic');
    Route::post('admin/settings/academic/save',  'saveAcademic')->name('admin.settings.academic.save');
    Route::get('admin/settings/notifications',   'notifications')->name('admin.settings.notifications');
    Route::post('admin/settings/notifications/save', 'saveNotifications')->name('admin.settings.notifications.save');
    Route::get('admin/settings/permissions',     'permissions')->name('admin.settings.permissions');
    Route::post('admin/settings/permissions/save','savePermissions')->name('admin.settings.permissions.save')->middleware('school_admin');
    Route::get('admin/settings/backup',          'backup')->name('admin.settings.backup');
    Route::post('admin/settings/backup/run',     'runBackup')->name('admin.settings.backup.run');
    Route::get('admin/settings/api',             'apiSettings')->name('admin.settings.api');
    Route::post('admin/settings/api/regenerate', 'regenerateKey')->name('admin.settings.api.regenerate');
});

// RBAC Phase 3B — Administration → Roles & Permissions (School Admin only: every route below is
// mapped to a non-delegable RBAC permission in app/Support/Permissions/registry.php). State
// changes use POST/PUT/DELETE; school scope always comes from the signed-in administrator.
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\Admin\RolePermissionController::class)->group(function () {
    Route::get('admin/roles-permissions', 'rolesIndex')->name('admin.rbac.roles.index');
    Route::get('admin/roles-permissions/roles/create', 'roleCreate')->name('admin.rbac.roles.create');
    Route::post('admin/roles-permissions/roles', 'roleStore')->name('admin.rbac.roles.store');
    Route::get('admin/roles-permissions/roles/{id}', 'roleShow')->name('admin.rbac.roles.show');
    Route::get('admin/roles-permissions/roles/{id}/edit', 'roleEdit')->name('admin.rbac.roles.edit');
    Route::put('admin/roles-permissions/roles/{id}', 'roleUpdate')->name('admin.rbac.roles.update');
    Route::post('admin/roles-permissions/roles/{id}/duplicate', 'roleDuplicate')->name('admin.rbac.roles.duplicate');
    Route::post('admin/roles-permissions/roles/{id}/status', 'roleStatus')->name('admin.rbac.roles.status');
    Route::delete('admin/roles-permissions/roles/{id}', 'roleDestroy')->name('admin.rbac.roles.destroy');

    Route::get('admin/roles-permissions/staff', 'staffIndex')->name('admin.rbac.staff.index');
    Route::get('admin/roles-permissions/staff/{id}', 'staffShow')->name('admin.rbac.staff.show');
    Route::get('admin/roles-permissions/staff/{id}/account-access', [\App\Http\Controllers\Admin\GenericStaffAccountAccessController::class, 'show'])->name('admin.rbac.staff.account-access');
    Route::post('admin/roles-permissions/staff/{id}/account-access/setup-link', [\App\Http\Controllers\Admin\GenericStaffAccountAccessController::class, 'sendSetupLink'])->name('admin.rbac.staff.account-access.send');
    Route::post('admin/roles-permissions/staff/{id}/roles', 'staffAssignRole')->name('admin.rbac.staff.roles.assign');
    Route::delete('admin/roles-permissions/staff/{id}/roles/{roleId}', 'staffRemoveRole')->name('admin.rbac.staff.roles.remove');
    Route::post('admin/roles-permissions/staff/{id}/permissions', 'staffGrant')->name('admin.rbac.staff.permissions.grant');
    Route::delete('admin/roles-permissions/staff/{id}/permissions', 'staffRevokeAll')->name('admin.rbac.staff.permissions.clear');
    Route::delete('admin/roles-permissions/staff/{id}/permissions/{permission}', 'staffRevoke')->name('admin.rbac.staff.permissions.revoke');
});

// Staff record management reached from the Staff Directory: View Profile and
// the full-page Edit Staff correction form.
//
// Deliberately NOT under the admin.rbac.staff.* prefix, which is access
// governance (users.assign_roles). These are HR profile routes and are gated by
// staff.view / staff.edit instead, so correcting a designation never requires —
// and never implies — the right to change somebody's permissions.
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\Admin\StaffProfileController::class)->group(function () {
    Route::get('admin/staff/{id}/profile', 'show')->name('admin.staff.profile.show');
    Route::get('admin/staff/{id}/profile/edit', 'edit')->name('admin.staff.profile.edit');
    Route::put('admin/staff/{id}/profile', 'update')->name('admin.staff.profile.update');
    Route::post('admin/staff/{id}/employment-status', 'status')->name('admin.staff.profile.status');
});

// Staff account access: the EXISTING governed setup workflow
// (GenericStaffAccountAccessController), reachable for every staff base role.
// Gated by staff.accounts — the platform's own "Staff account access" permission —
// and NOT by users.assign_roles, because issuing a password setup link is an
// account concern, not the Roles & Permissions surface. Same controller, same
// broker, same mail, same audit action as the other-staff entry points below.
Route::middleware(['auth', 'admin', 'rbac'])->controller(\App\Http\Controllers\Admin\GenericStaffAccountAccessController::class)->group(function () {
    Route::get('admin/staff/{id}/account-access', 'show')->name('admin.staff.account-access.show');
    Route::post('admin/staff/{id}/account-access/setup-link', 'sendSetupLink')->name('admin.staff.account-access.send');
});

// Staff → Add Staff: a launcher into the existing per-role create workflows (no creation logic of
// its own). Same 'school_admin:hr' guard as the create routes it opens.
Route::middleware(['auth', 'admin', 'rbac', 'school_admin:hr'])->group(function () {
    Route::get('admin/staff/add', [\App\Http\Controllers\Admin\StaffLauncherController::class, 'index'])->name('admin.staff.add');
    Route::get('admin/staff/add/other', [\App\Http\Controllers\Admin\OtherStaffController::class, 'create'])->name('admin.staff.other.create');
    Route::post('admin/staff/add/other', [\App\Http\Controllers\Admin\OtherStaffController::class, 'store'])->name('admin.staff.other.store');
// One professional full-page Create Staff form for every staff type, replacing
// the narrow drawer. Additive: the per-role create routes above are unchanged
// and remain the authority when a form is opened directly.
Route::get('admin/staff/create/{type}', [\App\Http\Controllers\Admin\StaffCreateController::class, 'create'])
    ->whereIn('type', array_keys(\App\Http\Controllers\Admin\StaffCreateController::TYPES))
    ->name('admin.staff.create');
Route::post('admin/staff/create/{type}', [\App\Http\Controllers\Admin\StaffCreateController::class, 'store'])
    ->whereIn('type', array_keys(\App\Http\Controllers\Admin\StaffCreateController::TYPES))
    ->name('admin.staff.create.store');
// Post-creation confirmation. Reachable only for the record created in the
// current session, and only once, so it exposes no other staff record.
Route::get('admin/staff/created', [\App\Http\Controllers\Admin\StaffCreateController::class, 'created'])->name('admin.staff.created');
    Route::get('admin/staff/other/{id}/edit', [\App\Http\Controllers\Admin\OtherStaffController::class, 'edit'])->name('admin.staff.other.edit');
    Route::put('admin/staff/other/{id}', [\App\Http\Controllers\Admin\OtherStaffController::class, 'update'])->name('admin.staff.other.update');
});

Route::get('staff/dashboard', \App\Http\Controllers\GenericStaffDashboardController::class)
    ->middleware(['auth', 'generic_staff'])->name('staff.dashboard');

// Protected staff documents: served by database id only (staff.documents.view, own school; 404 otherwise).
Route::middleware(['auth', 'admin', 'rbac'])->group(function () {
    Route::get('admin/staff/documents/{id}/download', [\App\Http\Controllers\Admin\StaffDocumentController::class, 'download'])->name('admin.staff.documents.download');
});
