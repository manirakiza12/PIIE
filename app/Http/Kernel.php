<?php
namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \App\Http\Middleware\ResolveTenantLocale::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\TrackModuleAccess::class,
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'auth'             => \App\Http\Middleware\Authenticate::class,
        'auth.basic'       => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers'    => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can'              => \Illuminate\Auth\Middleware\Authorize::class,
        'guest'            => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed'           => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle'         => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified'         => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'role_id'          => \App\Http\Middleware\RoleId::class,
        'superAdmin'       => \App\Http\Middleware\SuperAdminMiddleware::class,
        'admin'            => \App\Http\Middleware\AdminMiddleware::class,
        'school_subscription' => \App\Http\Middleware\EnsureSchoolSubscription::class,
        'generic_staff'    => \App\Http\Middleware\GenericStaffMiddleware::class,
        'student'          => \App\Http\Middleware\StudentMiddleware::class,
        'parent'           => \App\Http\Middleware\ParentMiddleware::class,
        'accountant'       => \App\Http\Middleware\AccountantMiddleware::class,
        'librarian'        => \App\Http\Middleware\LibrarianMiddleware::class,
        'teacher'          => \App\Http\Middleware\TeacherMiddleware::class,
        'warden'           => \App\Http\Middleware\WardenMiddleware::class,
        'is_installed'     => \App\Http\Middleware\IsInstalled::class,
        'alumni'           => \App\Http\Middleware\AlumniMiddleware::class,
        'admin_permission' => \App\Http\Middleware\AdminPermission::class,
        'school_admin'     => \App\Http\Middleware\SchoolAdminMiddleware::class,
        'rbac'             => \App\Http\Middleware\EnforceRoutePermission::class,
        'registrar'        => \App\Http\Middleware\RegistrarMiddleware::class,
        'bursar'           => \App\Http\Middleware\BursarMiddleware::class,
        'hod'              => \App\Http\Middleware\HodMiddleware::class,
        'admissions_staff' => \App\Http\Middleware\AdmissionsMiddleware::class,
        'director'         => \App\Http\Middleware\DirectorMiddleware::class,
        'hr_manager'       => \App\Http\Middleware\HrManagerMiddleware::class,
        'procurement'      => \App\Http\Middleware\ProcurementMiddleware::class,
        'store_keeper'     => \App\Http\Middleware\StoreKeeperMiddleware::class,
        'receptionist'     => \App\Http\Middleware\ReceptionistMiddleware::class,
        'examinations'     => \App\Http\Middleware\ExaminationsMiddleware::class,
        'staff'            => \App\Http\Middleware\MultiStaffMiddleware::class,
        'applicant'        => \App\Http\Middleware\ApplicantMiddleware::class,
        'applicant.guest'  => \App\Http\Middleware\RedirectIfApplicant::class,
    ];
}
