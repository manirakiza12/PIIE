<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonController;
use App\Mail\FreeEmail;
use App\Mail\NewUserEmail;
use App\Mail\StudentsEmail;
use App\Models\Admin;
use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\ApplicationPayment;
use App\Models\AdmitCard;
use App\Models\AcademicCalendar;
use App\Models\Appraisal;
use App\Models\Appraisal_submit;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\BookIssue;
use App\Models\Chat;
use App\Models\Classes;
use App\Models\ClassList;
use App\Models\ClassRoom;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ClubNotice;
use App\Models\Currency;
use App\Models\DailyAttendances;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Feedback;
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
use App\Models\Package;
use App\Models\PaymentHistory;
use App\Models\PaymentMethods;
use App\Models\Payments;
use App\Models\Programme;
use App\Models\IntakeSession;
use App\Models\Routine;
use App\Models\School;
use App\Models\Section;
use App\Models\Session;
use App\Models\StudentFeeManager;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Subscription;
use App\Models\Syllabus;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Models\User;
use App\Support\Admissions\ApplicationDocuments;
use App\Support\ProfilePhoto;
use App\Support\Staff\StaffProvisioningException;
use App\Support\Staff\StaffProvisioningService;
use App\Support\StudentFeeInvoiceGenerator;
use App\Support\StudentPortalActivation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mail;
use PDF;
use App\Support\Audit\StatusChangeAudit;
use App\Support\SafeUpload;
use App\Support\Clubs\ClubTenancy;

class AdminController extends Controller
{

    private $user;
    /**
     * Show the admin dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->user = Auth()->user();

            // Super admin and unauthenticated requests should not be blocked by subscription checks.
            if ($this->user && (int) $this->user->role_id !== 1) {
                if ($denial = $this->check_subscription_status($this->user->school_id)) {
                    return $denial;
                }
            }

            $this->insert_gateways();
            return $next($request);
        });
    }

    /**
     * Development-only subscription bypass. Fails closed: every condition must hold.
     * Flag on, APP_ENV explicitly "local", default connection is MySQL/MariaDB on a
     * loopback host at port 3307, and the live server confirms port 3307 and a
     * datadir inside piie-dev-db (the isolated development instance).
     */
    public function subscriptionBypassPermitted(): bool
    {
        return \App\Support\Subscriptions\SchoolSubscriptionAccess::bypassPermitted();
    }

    public function check_subscription_status($school_id = "")
    {
        return \App\Support\Subscriptions\SchoolSubscriptionAccess::denial($school_id, Route::currentRouteName());
    }

    /**
     * Show the admin dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function check_admin_subscription($school_id)
    {
        $validity_of_current_package = Subscription::where('school_id', $school_id)->where('active', 1)->first();
        if (! empty($validity_of_current_package)) {
            $validity_of_current_package = $validity_of_current_package->toArray();

            $today      = date("Y-m-d");
            $today_time = strtotime($today);

            if ((int) $validity_of_current_package['expire_date'] < $today_time) {
                $this->adminDashboard();
            } else {
            }
        } else {
        }
    }

    public function adminDashboard()
    {
        if (auth()->user()->role_id != "") {
            $roleId = (int) auth()->user()->role_id;
            $data = $this->schoolDashboardData();

            // Role-specific dashboards for new HEI roles
            $roleDashboards = [
                14 => 'admin.dashboard_director',
                15 => 'admin.dashboard_hr',
                16 => 'admin.dashboard_procurement',
                17 => 'admin.dashboard_storekeeper',
                18 => 'admin.dashboard_receptionist',
                19 => 'admin.dashboard_examinations',
            ];
            if (isset($roleDashboards[$roleId])) {
                $viewName = $roleDashboards[$roleId];
                if (view()->exists($viewName)) {
                    return view($viewName, $data);
                }
            }
            return view('admin.dashboard', $data);
        } else {
            redirect()->route('login')
                ->with('error', 'You are not logged in.');
        }
    }

    /**
     * School-scoped stats for the (school) Admin dashboard.
     *
     * Deliberately never touches platform-wide tables (schools, subscriptions,
     * packages) — that data belongs solely to the Super Admin dashboard
     * (SuperAdminController::superadminDashboard()). Every query here is
     * filtered by the authenticated admin's own school_id so one school's
     * admin can never see another school's counts.
     */
    private function schoolDashboardData(): array
    {
        $schoolId = auth()->user()->school_id;
        // The Admissions module (and therefore this panel) only applies to
        // the one school the public Apply Now portal belongs to — same gate
        // AdmissionsController's middleware and the nav item already use.
        $admissionsAvailable = is_primary_school($schoolId);

        $todayStart = strtotime(date('d M Y'));
        $todayEnd   = $todayStart + 86400;
        $monthStart = strtotime(date('1 M Y'));
        $monthEnd   = strtotime(date('t M Y', $monthStart)) + 86400;

        return [
            'totalStudents'   => User::where('school_id', $schoolId)->where('role_id', 7)->count(),
            'activeStudents'  => User::where('school_id', $schoolId)->where('role_id', 7)->where('account_status', '!=', 'disable')->count(),
            'totalTeachers'   => User::where('school_id', $schoolId)->where('role_id', 3)->count(),
            'totalStaff'      => User::where('school_id', $schoolId)->whereNotIn('role_id', [6, 7])->count(),
            'totalParents'    => User::where('school_id', $schoolId)->where('role_id', 6)->count(),
            'totalClasses'    => Classes::where('school_id', $schoolId)->count(),
            'totalCourses'    => Programme::where('school_id', $schoolId)->where('is_active', 1)->count(),
            'totalSubjects'   => Subject::where('school_id', $schoolId)->count(),
            'attendanceToday' => DailyAttendances::where('school_id', $schoolId)->whereBetween('timestamp', [$todayStart, $todayEnd])->count(),
            'classesToday'    => Routine::where('school_id', $schoolId)->where('day', strtolower(date('l')))->count(),
            'feeCollectedThisMonth' => (float) StudentFeeManager::where('school_id', $schoolId)->whereBetween('timestamp', [$monthStart, $monthEnd])->sum('paid_amount'),
            'outstandingFees' => max(0, (float) StudentFeeManager::where('school_id', $schoolId)->sum('total_amount') - (float) StudentFeeManager::where('school_id', $schoolId)->sum('paid_amount')),
            'pendingApprovals'    => Admission::where('school_id', $schoolId)->whereIn('status', ['submitted', 'under_review'])->count(),
            'totalBooks'          => Book::where('school_id', $schoolId)->count(),
            'booksIssued'         => BookIssue::where('school_id', $schoolId)->where('status', 0)->count(),
            'recentAdmissions'    => Admission::where('school_id', $schoolId)->with('programme')->latest()->limit(5)->get(),
            'recentAnnouncements' => Noticeboard::where('school_id', $schoolId)->latest()->limit(5)->get(),
            'upcomingEvents'      => FrontendEvent::where('school_id', $schoolId)->where('timestamp', '>', time())->orderBy('timestamp')->limit(5)->get(),
            'upcomingCalendar'    => AcademicCalendar::where('school_id', $schoolId)->whereDate('event_date', '>=', now()->toDateString())->orderBy('event_date')->limit(5)->get(),
            'admissionsAvailable' => $admissionsAvailable,
            'admissionsActionItems' => $admissionsAvailable ? $this->admissionsActionItems($schoolId) : [],
        ];
    }

    /**
     * The "Needs Your Action" panel on the school Admin dashboard —
     * everything in the Admissions module currently waiting on a human
     * decision, computed live from existing rows rather than a separate
     * notifications table. Each entry links straight to the filtered queue
     * (or review screen) that resolves it, so the count is never stale and
     * there's nothing to mark read/unread.
     */
    private function admissionsActionItems(int $schoolId): array
    {
        $newApplications = Admission::where('school_id', $schoolId)
            ->where('status', Admission::STATUS_SUBMITTED)
            ->count();

        $pendingPayments = ApplicationPayment::where('school_id', $schoolId)
            ->where('status', ApplicationPayment::STATUS_PENDING)
            ->count();

        $pendingDocuments = AdmissionDocument::where('school_id', $schoolId)
            ->where('status', AdmissionDocument::STATUS_PENDING)
            ->whereHas('admission', fn ($q) => $q->submittedOnly())
            ->count();

        $items = [];

        if ($newApplications > 0) {
            $items[] = [
                'count' => $newApplications,
                'label' => get_phrase('New application(s) awaiting first review'),
                'url'   => route('admin.hei_admissions.index', ['status' => Admission::STATUS_SUBMITTED]),
                'icon'  => 'bi-file-earmark-person',
            ];
        }

        if ($pendingPayments > 0) {
            $items[] = [
                'count' => $pendingPayments,
                'label' => get_phrase('Application fee payment(s) awaiting confirmation'),
                'url'   => route('admin.hei_admissions.index', ['fee_status' => 'pending']),
                'icon'  => 'bi-credit-card',
            ];
        }

        if ($pendingDocuments > 0) {
            $items[] = [
                'count' => $pendingDocuments,
                'label' => get_phrase('Uploaded document(s) awaiting verification'),
                'url'   => route('admin.hei_admissions.index', ['has_pending_documents' => 1]),
                'icon'  => 'bi-folder2-open',
            ];
        }

        return $items;
    }

    /**
     * Stream a CSV export of users for a given role, honoring the same
     * name/email search filter used by that role's list page.
     */
    private function exportUsersByRoleCsv($role_id, $search, $filename)
    {
        $school_id = auth()->user()->school_id;
        $users = User::where('school_id', $school_id)
            ->where('role_id', $role_id)
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($users) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Name', 'Email', 'Phone', 'Address', 'Account status']);
            foreach ($users as $i => $user) {
                $info = (object) array_merge(['phone' => null, 'address' => null], (array) (json_decode($user->user_information ?? '') ?: []));
                fputcsv($out, [
                    $i+1,
                    $user->name,
                    $user->email,
                    $info->phone,
                    $info->address,
                    $user->account_status == 'disable' ? 'Disabled' : 'Enabled',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Shared Staff Module field prep: split-name recombination plus
     * department/designation FK and employment type. Used by every
     * staff-role create/update (Admin/Teacher/Accountant/Librarian/Warden)
     * so the five roles stay consistent instead of drifting the way the
     * free-text designation field did before.
     */
    private function staffFieldsFromRequest(array $data): array
    {
        return StaffProvisioningService::staffFields($data);
    }

    /**
     * Resolves the two portal-password options from the create form: an
     * auto-generated temporary password (forces a change on first login,
     * same as the student activation flow) or an administrator-chosen one
     * (no forced change). The plaintext only ever lives in-memory long
     * enough to hash and email — it is never persisted or logged.
     */
    private function sendStaffCredentialsEmail(string $email, string $name, string $plainPassword): void
    {
        app(StaffProvisioningService::class)->sendCredentials($email, $name, $plainPassword);
    }

    /** Every staff single-record action goes through here — the one place cross-school access is denied. */
    private function findStaffOrFail($id, int $role_id): User
    {
        return User::where('id', $id)->where('school_id', auth()->user()->school_id)->where('role_id', $role_id)->firstOrFail();
    }

    /**
     * RBAC Phase 2B: credentials and account status of staff/administrator
     * accounts are School Administrator only. Student (7) and parent (6)
     * account actions keep their existing reach — student management is
     * outside this phase.
     */
    private function mayAdministerAccountSecurity(User $target): bool
    {
        if (in_array((int) $target->role_id, [6, 7], true)) {
            return true;
        }

        return (int) auth()->user()->role_id === 2;
    }

    /**
     * RBAC Phase 2D: every student action resolves its target through here —
     * a student (role 7) in the caller's own school — before it reads the
     * account or touches any related record.
     */
    private function findStudentOrFail($id): User
    {
        return $this->findStaffOrFail($id, 7);
    }

    /**
     * Security Phase 2E: a fee/invoice (student_fee_managers row) reached by
     * id must belong to the caller's own school.
     */
    private function findSchoolFeeOrFail($id): StudentFeeManager
    {
        return StudentFeeManager::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
    }

    /** Security Phase 2E: true when $studentId is a student (role 7) in the caller's own school. */
    private function isSchoolStudent($studentId): bool
    {
        return User::where('id', $studentId)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->exists();
    }

    /**
     * RBAC Phase 2D: a school must always keep at least one viable School
     * Administrator — role 2, account_status not 'disable' and staff_status
     * not suspended/inactive (exactly what AdminMiddleware requires). Guards
     * delete / disable / suspend of an administrator account: never your own
     * account, never the primary admin (school_role = 1) unless you are the
     * primary admin, and never the last viable admin. Returns a redirect when
     * the action must be refused, null when it may proceed.
     */
    private function rejectAdminLockout(User $target)
    {
        if ((int) $target->role_id !== 2) {
            return null;
        }

        $actor = auth()->user();
        if ((int) $target->id === (int) $actor->id) {
            return redirect()->back()->with('error', 'You cannot delete, disable or suspend your own administrator account.');
        }

        if ((int) $target->school_role === 1 && (int) $actor->school_role !== 1) {
            return redirect()->back()->with('error', 'Only the primary School Administrator can delete, disable or suspend the primary administrator.');
        }

        $anotherViableAdmin = User::where('school_id', $target->school_id)
            ->where('role_id', 2)
            ->where('id', '!=', $target->id)
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable'))
            // Same statuses as User::isStaffPortalBlocked().
            ->where(fn ($q) => $q->whereNull('staff_status')->orWhereNotIn('staff_status', \App\Support\Staff\StaffStatus::BLOCKED))
            ->exists();
        if (!$anotherViableAdmin) {
            return redirect()->back()->with('error', 'A school must keep at least one active School Administrator.');
        }

        return null;
    }

    /** True when $newEmail differs from $target's and already belongs to another account. */
    private function loginEmailTaken(User $target, $newEmail): bool
    {
        $newEmail = trim((string) $newEmail);
        if (strcasecmp($newEmail, (string) $target->email) === 0) {
            return false;
        }

        return User::where('email', $newEmail)->where('id', '!=', $target->id)->exists();
    }

    /**
     * RBAC Phase 2B: the login email is a security identity. Only a School
     * Administrator may change it (HR may still edit the rest of a staff
     * record), and it must stay unique. Returns a redirect when the request
     * must be rejected, null when it may proceed.
     */
    private function rejectLoginEmailChange(User $target, $newEmail)
    {
        $newEmail = trim((string) $newEmail);
        if (strcasecmp($newEmail, (string) $target->email) === 0) {
            return null;
        }

        if ((int) auth()->user()->role_id !== 2) {
            return redirect()->back()->with('error', 'Only a School Administrator can change a login email address.');
        }

        if ($this->loginEmailTaken($target, $newEmail)) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }

        return null;
    }

    private function resetStaffPassword($id, int $role_id)
    {
        $staff = $this->findStaffOrFail($id, $role_id);

        $plainPassword = Str::random(10);
        $staff->update(['password' => Hash::make($plainPassword)]);

        $this->sendStaffCredentialsEmail($staff->email, $staff->name, $plainPassword);

        AuditLog::record('update', 'Staff & Students', "Reset portal password for {$staff->auditLabel()} (#{$staff->id})");

        return redirect()->back()->with('message', get_phrase('Password has been reset and emailed to the staff member.'));
    }

    private function resendStaffActivation($id, int $role_id)
    {
        $staff = $this->findStaffOrFail($id, $role_id);

        $plainPassword = Str::random(10);
        $staff->update([
            'password'              => Hash::make($plainPassword),
            'force_password_change' => true,
        ]);

        $this->sendStaffCredentialsEmail($staff->email, $staff->name, $plainPassword);

        AuditLog::record('update', 'Staff & Students', "Resent portal activation email to {$staff->auditLabel()} (#{$staff->id})");

        return redirect()->back()->with('message', get_phrase('Activation email resent to the staff member.'));
    }

    private function staffListForExport(int $role_id)
    {
        return User::where('school_id', auth()->user()->school_id)->where('role_id', $role_id)->orderBy('name')->get();
    }

    private function staffListPdf(int $role_id, string $title)
    {
        $staffs = $this->staffListForExport($role_id);
        $pdf = PDF::loadView('admin.staff.list_pdf', ['staffs' => $staffs, 'title' => $title]);

        return $pdf->stream(str_replace(' ', '_', $title) . '_' . date('Y-m-d') . '.pdf');
    }

    private function staffListExcel(int $role_id, string $filenamePrefix)
    {
        $rows = $this->staffListForExport($role_id)->map(function ($staff) {
            return [
                $staff->code,
                $staff->name,
                $staff->email,
                optional($staff->department)->name,
                optional($staff->designationRecord)->name,
                $staff->employment_type,
                $staff->staff_status ?: 'active',
            ];
        });

        return \App\Support\Export\ExcelExportService::download(
            $filenamePrefix . '_' . date('Y-m-d'),
            ['Staff Number', 'Name', 'Email', 'Department', 'Designation', 'Employment Type', 'Status'],
            $rows
        );
    }

    private function staffProfilePdf($id, int $role_id)
    {
        $staff = $this->findStaffOrFail($id, $role_id);
        $pdf = PDF::loadView('admin.staff.profile_pdf', ['staff' => $staff]);

        return $pdf->stream('Staff_Profile_' . ($staff->code ?? $staff->id) . '.pdf');
    }

    /**
     * Show the admin list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function adminList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $admins = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 2);
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 2);
            })->paginate(10);
        } else {
            $admins = User::where('role_id', 2)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.admin.admin_list', compact('admins', 'search'));
    }

    public function adminListExport(Request $request)
    {
        return $this->exportUsersByRoleCsv(2, $request['search'] ?? '', 'admins_' . date('Y-m-d') . '.csv');
    }

    /**
     * Show the admin add modal.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function createModal()
    {
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.admin.add_admin', ['departments' => $departments, 'designations' => $designations]);
    }

    public function adminCreate(Request $request)
    {
        // Creation logic lives in StaffProvisioningService (identical behaviour; see StaffCreationCharacterizationTest).
        try {
            app(StaffProvisioningService::class)->provision(2, $request->all(), (int) auth()->user()->school_id);
        } catch (StaffProvisioningException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->back()->with('message', 'You have successfully add user.');
    }

    public function editModal($id)
    {
        $user         = $this->findStaffOrFail($id, 2);
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.admin.edit_admin', ['user' => $user, 'departments' => $departments, 'designations' => $designations]);
    }

    public function adminUpdate(Request $request, $id)
    {
        $data = $request->all();
        $user = $this->findStaffOrFail($id, 2);

        if ($rejected = $this->rejectLoginEmailChange($user, $data['email'] ?? null)) {
            return $rejected;
        }

        $newStaffStatus = $data['staff_status'] ?? null;
        if (in_array($newStaffStatus, \App\Support\Staff\StaffStatus::BLOCKED, true) && $newStaffStatus !== $user->staff_status
            && ($rejected = $this->rejectAdminLockout($user))) {
            return $rejected;
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $decoded_info = json_decode($user->user_information ?? '') ?: (object) [];
            $file_name    = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $user->update(array_merge($this->staffFieldsFromRequest($data), [
            'email'             => $data['email'],
            'user_information'  => $data['user_information'],
            'staff_status'      => $data['staff_status'] ?? $user->staff_status,
        ]));

        return redirect()->back()->with('message', 'You have successfully update user.');
    }

    public function adminDelete($id)
    {
        $user = $this->findStaffOrFail($id, 2);
        if ($rejected = $this->rejectAdminLockout($user)) {
            return $rejected;
        }
        $user->delete();
        return redirect()->route('admin.admin')->with('message', 'You have successfully deleted user.');
    }

    public function adminResetPassword($id)
    {
        return $this->resetStaffPassword($id, 2);
    }

    public function adminResendActivation($id)
    {
        return $this->resendStaffActivation($id, 2);
    }

    public function adminListPdf()
    {
        return $this->staffListPdf(2, get_phrase('Admins'));
    }

    public function adminListExportExcel()
    {
        return $this->staffListExcel(2, 'admins');
    }

    public function adminProfilePdf($id)
    {
        return $this->staffProfilePdf($id, 2);
    }

    public function menuSettingsView($id)
    {
        $user = $this->findStaffOrFail($id, 2);
        return view('admin.admin.menu_permission', ['user' => $user]);
    }

    public function menuPermissionUpdate(Request $request, $id)
    {
        $user = $this->findStaffOrFail($id, 2);

        $inputPermissions = $request->input('permissions', []);
        if (!is_array($inputPermissions)) {
            $inputPermissions = [];
        }

        $normalized = [];
        foreach ($inputPermissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission === '') {
                continue;
            }

            $normalized[] = $permission;

            if ($permission === 'admin.online_exams') {
                $normalized[] = 'admin.online_exams.index';
            } elseif ($permission === 'admin.online_exams.index') {
                $normalized[] = 'admin.online_exams';
            }

            if ($permission === 'admin.question_bank') {
                $normalized[] = 'admin.question_bank.index';
            } elseif ($permission === 'admin.question_bank.index') {
                $normalized[] = 'admin.question_bank';
            }
        }

        $normalized = array_values(array_unique($normalized));

        $user->update([
            'menu_permission' => json_encode($normalized),
        ]);

        return redirect()->back()->with('message', 'You have successfully updated user permissions.');
    }

    public function adminProfile($id)
    {
        $this->findStaffOrFail($id, 2);
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.admin.admin_profile', ['user_details' => $user_details]);
    }

    public function school_user_password(Request $request)
    {

        $userId = $request->input('user_id');

        // RBAC Phase 2B: the target must belong to the caller's own school
        // (previously User::find() reached any account, Super Admin
        // included), and staff/administrator credentials are School
        // Administrator only.
        $user = User::where('id', $userId)->where('school_id', auth()->user()->school_id)->first();
        if (!$user || !$this->mayAdministerAccountSecurity($user)) {
            return redirect()->back()->with('error', 'You do not have permission to change this password.');
        }

        $data['password'] = Hash::make($request->password);
        $user->update($data);

        return redirect()->back()->with('message', 'You have successfully update password.');
    }

    public function adminDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 2);
        return view('admin.admin.documents', ['user_details' => $user_details]);
    }

    public function accountantDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 4);
        return view('admin.accountant.documents', ['user_details' => $user_details]);
    }

    public function librarianDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 5);
        return view('admin.librarian.documents', ['user_details' => $user_details]);
    }

    public function parentDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 6);
        return view('admin.parent.documents', ['user_details' => $user_details]);
    }

    public function studentDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 7);
        return view('admin.student.documents', ['user_details' => $user_details]);
    }

    public function teacherDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 3);
        return view('admin.teacher.documents', ['user_details' => $user_details]);
    }

    public function wardenDocuments($id = "")
    {
        $user_details = $this->findStaffOrFail($id, 10);
        return view('admin.warden.documents', ['user_details' => $user_details]);
    }

    /**
     * RBAC Phase 2C: a document owner is always resolved within the
     * caller's own school — never from a bare user id — and uploads follow
     * the same file policy as admission documents (ApplicationDocuments):
     * PDF/JPG/JPEG/PNG only, checked by both extension and detected content,
     * at most MAX_FILE_MB. The file is stored under an application-generated
     * name; the client filename is never used on disk.
     */
    private function findDocumentOwnerOrFail($id): User
    {
        return User::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
    }

    public function documentsUpload(Request $request, $id = "")
    {
        $user = $this->findDocumentOwnerOrFail($id);

        $allowed = ApplicationDocuments::ALLOWED_EXTENSIONS;
        $request->validate([
            'file_name' => 'required|string|max:100',
            'file'      => 'required|file|mimes:' . implode(',', $allowed) . '|max:' . (ApplicationDocuments::MAX_FILE_MB * 1024),
        ], [
            'file.mimes' => 'Only PDF, JPG and PNG files are accepted.',
        ]);

        $file      = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowed, true)) {
            return redirect()->back()->with('error', 'Only PDF, JPG and PNG files are accepted.');
        }

        $fileName = bin2hex(random_bytes(20)) . '.' . $extension;
        $file->move(public_path('assets/uploads/user-docs/' . $user->id . '/'), $fileName);

        // Get existing documents or initialize as an empty array
        $documents = $user->documents ? json_decode($user->documents, true) : [];

        // Add the new document with the provided file name
        $documents[slugify($request->input('file_name')) ?: 'document'] = $fileName;

        // Update the user's documents
        $user->update(['documents' => json_encode($documents)]);

        return redirect()->back()->with('message', 'File uploaded successfully.');
    }

    public function documentsRemove($id = "", $file_name = "")
    {
        $user = $this->findDocumentOwnerOrFail($id);

        if ($user) {
            // Get the documents as an array
            $documents = json_decode($user->documents, true);

            // Check if the file with the given file_name exists
            if (isset($documents[$file_name])) {
                $stored    = (string) $documents[$file_name];
                $directory = public_path('assets/uploads/user-docs/' . $user->id);
                $file_path = $directory . DIRECTORY_SEPARATOR . $stored;

                // Only ever a plain file inside this owner's own folder.
                $isContained = $stored !== ''
                    && $stored === basename(str_replace('\\', '/', $stored))
                    && realpath($file_path) !== false
                    && str_starts_with(realpath($file_path), realpath($directory) . DIRECTORY_SEPARATOR);

                // Check if the file exists
                if ($isContained && file_exists($file_path)) {
                    // Delete the file
                    unlink($file_path);

                    // Remove the file entry from the documents array
                    unset($documents[$file_name]);

                    // Update the user's documents column
                    $user->update(['documents' => json_encode($documents)]);

                    return redirect()->back()->with('message', 'File removed successfully.');
                } else {
                    return redirect()->back()->with('error', 'File not found.');
                }
            } else {
                return redirect()->back()->with('error', 'File not found.');
            }
        } else {
            return redirect()->back()->with('error', 'User not found.');
        }
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
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 3);
            })->paginate(10);
        } else {
            $teachers = User::where('role_id', 3)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.teacher.teacher_list', compact('teachers', 'search'));
    }

    public function teacherListExport(Request $request)
    {
        return $this->exportUsersByRoleCsv(3, $request['search'] ?? '', 'teachers_' . date('Y-m-d') . '.csv');
    }

    /**
     * Show the teacher add modal.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function createTeacherModal(Request $request)
    {
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Create Teacher'),
                'inner_view' => 'admin.teacher.add_teacher',
                'view_data'  => ['departments' => $departments, 'designations' => $designations],
            ]);
        }
        return view('admin.teacher.add_teacher', ['departments' => $departments, 'designations' => $designations]);
    }

    public function adminTeacherCreate(Request $request)
    {
        // Creation logic lives in StaffProvisioningService (identical behaviour; see StaffCreationCharacterizationTest).
        try {
            app(StaffProvisioningService::class)->provision(3, $request->all(), (int) auth()->user()->school_id);
        } catch (StaffProvisioningException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->back()->with('message', 'You have successfully add teacher.');
    }

    public function teacherEditModal(Request $request, $id)
    {
        $user         = $this->findStaffOrFail($id, 3);
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Edit Teacher'),
                'inner_view' => 'admin.teacher.edit_teacher',
                'view_data'  => ['user' => $user, 'departments' => $departments, 'designations' => $designations],
            ]);
        }
        return view('admin.teacher.edit_teacher', ['user' => $user, 'departments' => $departments, 'designations' => $designations]);
    }

    public function teacherUpdate(Request $request, $id)
    {
        $data = $request->all();
        $user = $this->findStaffOrFail($id, 3);

        if ($rejected = $this->rejectLoginEmailChange($user, $data['email'] ?? null)) {
            return $rejected;
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $decoded_info = json_decode($user->user_information ?? '') ?: (object) [];
            $file_name    = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $user->update(array_merge($this->staffFieldsFromRequest($data), [
            'email'            => $data['email'],
            'user_information' => $data['user_information'],
            'staff_status'     => $data['staff_status'] ?? $user->staff_status,
        ]));

        return redirect()->back()->with('message', 'You have successfully update teacher.');
    }

    public function teacherDelete($id)
    {
        $user = $this->findStaffOrFail($id, 3);
        $user->delete();
        return redirect()->route('admin.teacher')->with('message', 'You have successfully deleted teacher.');
    }
    public function teacherProfile($id)
    {
        $this->findStaffOrFail($id, 3);
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.teacher.teacher_profile', ['user_details' => $user_details]);
    }

    public function teacherResetPassword($id)
    {
        return $this->resetStaffPassword($id, 3);
    }

    public function teacherResendActivation($id)
    {
        return $this->resendStaffActivation($id, 3);
    }

    public function teacherListPdf()
    {
        return $this->staffListPdf(3, get_phrase('Teachers'));
    }

    public function teacherListExportExcel()
    {
        return $this->staffListExcel(3, 'teachers');
    }

    public function teacherProfilePdf($id)
    {
        return $this->staffProfilePdf($id, 3);
    }

    /**
     * Show the accountant list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function accountantList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $accountants = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 4);
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 4);
            })->paginate(10);
        } else {
            $accountants = User::where('role_id', 4)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.accountant.accountant_list', compact('accountants', 'search'));
    }

    public function accountantListExport(Request $request)
    {
        return $this->exportUsersByRoleCsv(4, $request['search'] ?? '', 'accountants_' . date('Y-m-d') . '.csv');
    }

    public function createAccountantModal(Request $request)
    {
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        $view_data    = ['departments' => $departments, 'designations' => $designations];
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Create Accountant'),
                'inner_view' => 'admin.accountant.add_accountant',
                'view_data'  => $view_data,
            ]);
        }
        return view('admin.accountant.add_accountant', $view_data);
    }

    public function accountantCreate(Request $request)
    {
        // Creation logic lives in StaffProvisioningService (identical behaviour; see StaffCreationCharacterizationTest).
        try {
            app(StaffProvisioningService::class)->provision(4, $request->all(), (int) auth()->user()->school_id);
        } catch (StaffProvisioningException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->back()->with('message', 'You have successfully add accountant.');
    }

    public function accountantEditModal(Request $request, $id)
    {
        $user         = $this->findStaffOrFail($id, 4);
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        $view_data    = ['user' => $user, 'departments' => $departments, 'designations' => $designations];
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Edit Accountant'),
                'inner_view' => 'admin.accountant.edit_accountant',
                'view_data'  => $view_data,
            ]);
        }
        return view('admin.accountant.edit_accountant', $view_data);
    }

    public function accountantUpdate(Request $request, $id)
    {
        $data = $request->all();
        $user = $this->findStaffOrFail($id, 4);

        if ($rejected = $this->rejectLoginEmailChange($user, $data['email'] ?? null)) {
            return $rejected;
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $decoded_info = json_decode($user->user_information ?? '') ?: (object) [];
            $file_name    = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $user->update(array_merge($this->staffFieldsFromRequest($data), [
            'email'            => $data['email'],
            'user_information' => $data['user_information'],
            'staff_status'     => $data['staff_status'] ?? $user->staff_status,
        ]));

        return redirect()->back()->with('message', 'You have successfully update accountant.');
    }

    public function accountantDelete($id)
    {
        $user = $this->findStaffOrFail($id, 4);
        $user->delete();
        return redirect()->route('admin.accountant')->with('message', 'You have successfully deleted accountant.');
    }

    public function accountantResetPassword($id)
    {
        return $this->resetStaffPassword($id, 4);
    }

    public function accountantResendActivation($id)
    {
        return $this->resendStaffActivation($id, 4);
    }

    public function accountantListPdf()
    {
        return $this->staffListPdf(4, get_phrase('Accountants'));
    }

    public function accountantListExportExcel()
    {
        return $this->staffListExcel(4, 'accountants');
    }

    public function accountantProfilePdf($id)
    {
        return $this->staffProfilePdf($id, 4);
    }

    public function accountantProfile($id)
    {
        $this->findStaffOrFail($id, 4);
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.accountant.accountant_profile', ['user_details' => $user_details]);
    }

    /**
     * Show the librarian list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function librarianList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $librarians = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 5);
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 5);
            })->paginate(10);
        } else {
            $librarians = User::where('role_id', 5)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.librarian.librarian_list', compact('librarians', 'search'));
    }

    public function librarianListExport(Request $request)
    {
        return $this->exportUsersByRoleCsv(5, $request['search'] ?? '', 'librarians_' . date('Y-m-d') . '.csv');
    }

    public function createLibrarianModal()
    {
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.librarian.add_librarian', ['departments' => $departments, 'designations' => $designations]);
    }

    public function librarianCreate(Request $request)
    {
        // Creation logic lives in StaffProvisioningService (identical behaviour; see StaffCreationCharacterizationTest).
        try {
            app(StaffProvisioningService::class)->provision(5, $request->all(), (int) auth()->user()->school_id);
        } catch (StaffProvisioningException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->back()->with('message', 'You have successfully add librarian.');
    }

    public function librarianEditModal($id)
    {
        $user         = $this->findStaffOrFail($id, 5);
        $school_id    = auth()->user()->school_id;
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.librarian.edit_librarian', ['user' => $user, 'departments' => $departments, 'designations' => $designations]);
    }

    public function librarianUpdate(Request $request, $id)
    {
        $data = $request->all();
        $user = $this->findStaffOrFail($id, 5);

        if ($rejected = $this->rejectLoginEmailChange($user, $data['email'] ?? null)) {
            return $rejected;
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $decoded_info = json_decode($user->user_information ?? '') ?: (object) [];
            $file_name    = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $user->update(array_merge($this->staffFieldsFromRequest($data), [
            'email'            => $data['email'],
            'user_information' => $data['user_information'],
            'staff_status'     => $data['staff_status'] ?? $user->staff_status,
        ]));

        return redirect()->back()->with('message', 'You have successfully update librarian.');
    }

    public function librarianDelete($id)
    {
        $user = $this->findStaffOrFail($id, 5);
        $user->delete();
        return redirect()->route('admin.librarian')->with('message', 'You have successfully deleted librarian.');
    }

    public function librarianResetPassword($id)
    {
        return $this->resetStaffPassword($id, 5);
    }

    public function librarianResendActivation($id)
    {
        return $this->resendStaffActivation($id, 5);
    }

    public function librarianListPdf()
    {
        return $this->staffListPdf(5, get_phrase('Librarians'));
    }

    public function librarianListExportExcel()
    {
        return $this->staffListExcel(5, 'librarians');
    }

    public function librarianProfilePdf($id)
    {
        return $this->staffProfilePdf($id, 5);
    }

    public function librarianProfile($id)
    {
        $this->findStaffOrFail($id, 5);
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.librarian.librarian_profile', ['user_details' => $user_details]);
    }

    /**
     * Show the parent list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function parentList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $parents = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 6);
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 6);
            })->paginate(10);
        } else {
            $parents = User::where('role_id', 6)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.parent.parent_list', compact('parents', 'search'));
    }

    public function createParent()
    {
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.parent.add_parent', ['classes' => $classes]);
    }

    public function parentCreate(Request $request)
    {
        $data = $request->all();

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $photo = '';
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $duplicate_user_check = User::get()->where('email', $data['email']);

        if (count($duplicate_user_check) == 0) {

            $parent = User::create([
                'name'             => $data['name'],
                'email'            => $data['email'],
                'password'         => Hash::make($data['password']),
                'role_id'          => '6',
                'school_id'        => auth()->user()->school_id,
                'user_information' => $data['user_information'],
                'status'           => 1,
            ]);
        } else {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $students   = $data['student_id'] ?? [];
        $class_id   = $data['class_id'] ?? '';
        $section_id = $data['section_id'] ?? '';

        foreach ($students as $student) {
            if (empty($student)) {
                continue;
            }

            $users = User::where('id', $student)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->get();

            if (count($users) == 1) {
                $users->first()->update([
                    'parent_id' => $parent->id,
                ]);
            } else {
                if (count($users) > 1) {
                    foreach ($users as $user) {
                        $enrollment = Enrollment::where('class_id', $class_id)->where('section_id', $section_id)->where('user_id', $user->id)->where('school_id', auth()->user()->school_id)->first();

                        if ($enrollment != '') {
                            $user->update([
                                'parent_id' => $parent->id,
                            ]);
                        }
                    }
                }
            }
        }
        if (! empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))) {
            \App\Support\Mail\SafeMail::send($data['email'], new NewUserEmail($data), 'account');
        }

        return redirect()->back()->with('message', 'You have successfully add parent.');
    }

    public function parentEditModal($id)
    {
        $user    = $this->findStaffOrFail($id, 6);
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.parent.edit_parent', ['user' => $user, 'classes' => $classes]);
    }

    public function parentUpdate(Request $request, $id)
    {
        $data = $request->all();

        // RBAC Phase 2C: only ever a parent in the caller's own school
        // (previously User::find() reached any account, Super Admin
        // included), and the login email must stay unique.
        $parentUser = $this->findStaffOrFail($id, 6);
        if ($this->loginEmailTaken($parentUser, $data['email'] ?? null)) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {

            $user_information = User::where('id', $id)->value('user_information');
            $decoded_info     = json_decode($user_information ?? '') ?: (object) [];
            $file_name        = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }

        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $parentUser->update([
            'name'             => $data['name'],
            'email'            => $data['email'],
            'user_information' => $data['user_information'],
        ]);

        //Previous parent has been empty
        foreach (User::where('parent_id', $id)->get() as $previousChild) {
            $previousChild->update(['parent_id' => null]);
        }

        $students = $data['student_id'] ?? [];
        foreach ($students as $student) {
            if ($student != '') {
                $user = User::where('id', $student)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->first();

                if ($user != '') {
                    $user->update([
                        'parent_id' => $id,
                    ]);
                }
            }
        }

        return redirect()->back()->with('message', 'You have successfully update parent.');
    }

    public function parentDelete($id)
    {
        $user = $this->findStaffOrFail($id, 6);
        $user->delete();
        $admins = User::get()->where('role_id', 5);
        return redirect()->route('admin.parent')->with('message', 'You have successfully deleted parent.');
    }

    public function parentProfile($id)
    {
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.parent.parent_profile', ['user_details' => $user_details]);
    }
    public function wardenList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $wardens = User::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 10);
            })->orWhere(function ($query) use ($search) {
                $query->where('email', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id)
                    ->where('role_id', 10);
            })->paginate(10);
        } else {
            $wardens = User::where('role_id', 10)->where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.warden.warden_list', compact('wardens', 'search'));
    }

    public function wardenListExport(Request $request)
    {
        return $this->exportUsersByRoleCsv(10, $request['search'] ?? '', 'wardens_' . date('Y-m-d') . '.csv');
    }

    public function createWarden()
    {
        $school_id    = auth()->user()->school_id;
        $classes      = Classes::get()->where('school_id', $school_id);
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.warden.add_warden', ['classes' => $classes, 'departments' => $departments, 'designations' => $designations]);
    }

    public function wardenCreate(Request $request)
    {
        // Creation logic lives in StaffProvisioningService (identical behaviour; see StaffCreationCharacterizationTest).
        try {
            app(StaffProvisioningService::class)->provision(10, $request->all(), (int) auth()->user()->school_id);
        } catch (StaffProvisioningException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
        return redirect()->back()->with('message', 'You have successfully add warden.');
    }

    public function wardenEditModal($id)
    {
        $user         = $this->findStaffOrFail($id, 10);
        $school_id    = auth()->user()->school_id;
        $classes      = Classes::get()->where('school_id', $school_id);
        $departments  = Department::get()->where('school_id', $school_id);
        $designations = Designation::where('school_id', $school_id)->orderBy('name')->get();
        return view('admin.warden.edit_warden', ['user' => $user, 'classes' => $classes, 'departments' => $departments, 'designations' => $designations]);
    }

    public function wardenUpdate(Request $request, $id)
    {
        $data = $request->all();
        $user = $this->findStaffOrFail($id, 10);

        if ($rejected = $this->rejectLoginEmailChange($user, $data['email'] ?? null)) {
            return $rejected;
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $decoded_info = json_decode($user->user_information ?? '') ?: (object) [];
            $file_name    = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        $user->update(array_merge($this->staffFieldsFromRequest($data), [
            'email'            => $data['email'],
            'user_information' => $data['user_information'],
            'staff_status'     => $data['staff_status'] ?? $user->staff_status,
        ]));

        return redirect()->back()->with('message', 'You have successfully update warden.');
    }

    public function wardenDelete($id)
    {
        $user = $this->findStaffOrFail($id, 10);
        $user->delete();
        return redirect()->route('admin.warden')->with('message', 'You have successfully deleted warden.');
    }

    public function wardenResetPassword($id)
    {
        return $this->resetStaffPassword($id, 10);
    }

    public function wardenResendActivation($id)
    {
        return $this->resendStaffActivation($id, 10);
    }

    public function wardenListPdf()
    {
        return $this->staffListPdf(10, get_phrase('Wardens'));
    }

    public function wardenListExportExcel()
    {
        return $this->staffListExcel(10, 'wardens');
    }

    public function wardenProfilePdf($id)
    {
        return $this->staffProfilePdf($id, 10);
    }

    public function wardenProfile($id)
    {
        $this->findStaffOrFail($id, 10);
        $user_details = (new CommonController)->getAdminDetails($id);
        return view('admin.warden.warden_profile', ['user_details' => $user_details]);
    }
    /**
     * Show the student list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function studentList(Request $request)
    {
        $search     = $request['search'] ?? "";
        $class_id   = $request['class_id'] ?? "";
        $section_id = $request['section_id'] ?? "";

        $users = User::where(function ($query) use ($search) {
            $query->where('users.name', 'LIKE', "%{$search}%")
                ->orWhere('users.email', 'LIKE', "%{$search}%");
        });

        $users->where('users.school_id', auth()->user()->school_id)
            ->where('users.role_id', 7);

        $this->filterStudentsByClassAndSection($users, $class_id, $section_id);

        // Was an inner join on `enrollment` — that silently dropped every
        // programme-based (HEI) student from the list, since (per
        // resolve_student_academic_context() in CommonHelper.php) only a
        // class-based student ever gets an Enrollment row at all. Selecting
        // from `users` directly and using Enrollment only to *filter* (via
        // the whereExists calls above, when a class/section is actually
        // picked) includes both kinds of student instead of excluding one.
        $students = $users->select('users.id as user_id')->paginate(10);

        $classes = Classes::get()->where('school_id', auth()->user()->school_id);

        return view('admin.student.student_list', compact('students', 'search', 'classes', 'class_id', 'section_id'));
    }

    /**
     * Shared by studentList()/studentListExport()/studentListExportExcel():
     * narrows to students enrolled in a specific class/section without a
     * join, so a student who has no Enrollment row at all (every HEI
     * student) is only excluded when a class/section filter is actually
     * applied — never merely because the join had nothing to match.
     */
    private function filterStudentsByClassAndSection($query, $classId, $sectionId): void
    {
        if ($sectionId === 'all' || $sectionId !== '') {
            $query->whereExists(function ($sub) use ($sectionId) {
                $sub->selectRaw('1')->from('enrollment')
                    ->whereColumn('enrollment.user_id', 'users.id')
                    ->where('enrollment.section_id', $sectionId);
            });
        }

        if ($classId === 'all' || $classId !== '') {
            $query->whereExists(function ($sub) use ($classId) {
                $sub->selectRaw('1')->from('enrollment')
                    ->whereColumn('enrollment.user_id', 'users.id')
                    ->where('enrollment.class_id', $classId);
            });
        }
    }

    public function studentListExport(Request $request)
    {
        $search     = $request['search'] ?? "";
        $class_id   = $request['class_id'] ?? "";
        $section_id = $request['section_id'] ?? "";
        $school_id  = auth()->user()->school_id;

        $users = User::where(function ($query) use ($search) {
            $query->where('users.name', 'LIKE', "%{$search}%")
                ->orWhere('users.email', 'LIKE', "%{$search}%");
        });

        $users->where('users.school_id', $school_id)
            ->where('users.role_id', 7);

        $this->filterStudentsByClassAndSection($users, $class_id, $section_id);

        $students = $users->select('users.id as user_id')->get();

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="students_' . date('Y-m-d') . '.csv"',
        ];

        $callback = function () use ($students) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'Name', 'Email', 'Phone', 'Address', 'Account status']);
            foreach ($students as $i => $enrollment) {
                $student = DB::table('users')->where('id', $enrollment->user_id)->first();
                if (!$student) {
                    continue;
                }
                $info = (object) array_merge(['phone' => null, 'address' => null], (array) (json_decode($student->user_information ?? '') ?: []));
                fputcsv($out, [
                    $i+1,
                    $student->name,
                    $student->email,
                    $info->phone,
                    $info->address,
                    $student->account_status == 'disable' ? 'Disabled' : 'Enabled',
                ]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Genuine .xlsx export (PhpSpreadsheet — see App\Support\Export\ExcelExportService),
     * alongside the existing CSV export above, which is left untouched.
     */
    public function studentListExportExcel(Request $request)
    {
        $search     = $request['search'] ?? "";
        $class_id   = $request['class_id'] ?? "";
        $section_id = $request['section_id'] ?? "";
        $school_id  = auth()->user()->school_id;

        $users = User::where('users.school_id', $school_id)
            ->where('users.role_id', 7)
            ->where(function ($query) use ($search) {
                $query->where('users.name', 'LIKE', "%{$search}%")
                    ->orWhere('users.email', 'LIKE', "%{$search}%");
            });

        $this->filterStudentsByClassAndSection($users, $class_id, $section_id);

        $enrollments = $users->select('users.id as user_id')->get();

        $rows = [];
        foreach ($enrollments as $i => $enrollment) {
            $student = User::find($enrollment->user_id);
            if (! $student) {
                continue;
            }
            $profile = StudentProfile::where('user_id', $student->id)->first();
            $info = (object) array_merge(['phone' => null, 'gender' => null, 'blood_group' => null], (array) (json_decode($student->user_information ?? '') ?: []));

            $rows[] = [
                $i + 1,
                $student->code,
                $student->name,
                $student->email,
                $info->phone,
                $info->gender,
                $info->blood_group,
                optional($profile?->programme)->name,
                optional($profile?->intakeSession)->name,
                $profile->status ?? 'active',
                $student->account_status == 'disable' ? 'Disabled' : 'Enabled',
            ];
        }

        return \App\Support\Export\ExcelExportService::download(
            'students_' . date('Y-m-d'),
            ['#', 'Registration No.', 'Name', 'Email', 'Phone', 'Gender', 'Blood Group', 'Programme', 'Intake', 'Status', 'Account Status'],
            $rows
        );
    }

    /**
     * Printable / PDF student profile (dompdf — same package already used
     * for transcripts and admission offer letters). ?inline=1 streams it in
     * the browser for printing rather than forcing a file download.
     */
    public function studentProfilePdf(Request $request, $id)
    {
        $this->findStudentOrFail($id);
        $student_details = (new CommonController)->get_student_details_by_id($id);
        $profile = StudentProfile::where('user_id', $id)->first();
        $pdf = PDF::loadView('admin.student.profile_pdf', ['student_details' => $student_details, 'profile' => $profile]);
        $filename = 'Student_Profile_' . ($student_details['code'] ?? $id) . '.pdf';

        return $request->boolean('inline') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    /**
     * Single-student genuine .xlsx export (see App\Support\Export\ExcelExportService).
     */
    public function studentProfileExportExcel($id)
    {
        $this->findStudentOrFail($id);
        $student_details = (new CommonController)->get_student_details_by_id($id);
        $profile = StudentProfile::where('user_id', $id)->first();

        $rows = [[
            $student_details['code'] ?? '',
            $student_details['name'] ?? '',
            $student_details['email'] ?? '',
            $student_details['phone'] ?? '',
            $student_details['gender'] ?? '',
            strtoupper($student_details['blood_group'] ?? ''),
            optional($profile?->programme)->name,
            optional($profile?->intakeSession)->name,
            $profile->nationality ?? '',
            $profile->status ?? 'active',
        ]];

        return \App\Support\Export\ExcelExportService::download(
            'student_' . ($student_details['code'] ?? $id),
            ['Registration No.', 'Name', 'Email', 'Phone', 'Gender', 'Blood Group', 'Programme', 'Intake', 'Nationality', 'Status'],
            $rows
        );
    }

    /**
     * Admin-triggered password reset: generates a new random password (never
     * logged, never exported — see AuditLog::redact) and emails it to the
     * student the same way initial account creation does.
     */
    public function studentResetPassword($id)
    {
        $student = User::where('id', $id)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->firstOrFail();

        $plainPassword = Str::random(10);
        $student->update(['password' => Hash::make($plainPassword)]);

        if (! empty(get_settings('smtp_user')) && get_settings('smtp_pass') && get_settings('smtp_host') && get_settings('smtp_port')) {
            \App\Support\Mail\SafeMail::send($student->email, new NewUserEmail([
                'name'     => $student->name,
                'email'    => $student->email,
                'password' => $plainPassword,
            ]));
        }

        AuditLog::record('update', 'Staff & Students', "Reset portal password for student {$student->name} (#{$student->id})");

        return redirect()->back()->with('message', get_phrase('Password has been reset and emailed to the student.'));
    }

    /**
     * Re-sends the student portal activation email with a freshly generated
     * temporary password. Never creates a new User/StudentProfile/invoice —
     * it only reissues credentials on the existing account and forces a
     * password change on next login, same as a first-time activation.
     */
    public function resendStudentActivationEmail($id)
    {
        $student = User::where('id', $id)->where('school_id', auth()->user()->school_id)->where('role_id', 7)->firstOrFail();

        $plainPassword = Str::random(10);
        $student->update([
            'password'               => Hash::make($plainPassword),
            'force_password_change'  => true,
        ]);

        $profile = StudentProfile::where('user_id', $student->id)->first();
        $sent    = StudentPortalActivation::sendActivationEmail($student, $plainPassword, $profile->programme_id ?? null, $profile->intake_session_id ?? null);

        AuditLog::record('update', 'Staff & Students', "Resent portal activation email to student {$student->name} (#{$student->id})");

        return redirect()->back()->with('message', $sent
            ? get_phrase('Activation email resent to the student.')
            : get_phrase('Password reset, but the activation email could not be sent — check SMTP settings.'));
    }

    public function createStudentModal(Request $request)
    {
        $school_id  = auth()->user()->school_id;
        $classes    = Classes::get()->where('school_id', $school_id);
        $sessions   = Session::where('school_id', $school_id)->orderByDesc('id')->get();
        $departments = Department::where('school_id', $school_id)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $school_id)->where('is_active', 1)->orderBy('name')->get();
        $intakeSessions = IntakeSession::where('school_id', $school_id)->orderByDesc('id')->get();
        $view_data  = ['classes' => $classes, 'sessions' => $sessions, 'departments' => $departments, 'programmes' => $programmes, 'intakeSessions' => $intakeSessions];
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Create Student'),
                'inner_view' => 'admin.student.add_student',
                'view_data'  => $view_data,
            ]);
        }
        return view('admin.student.add_student', $view_data);
    }

    public function studentCreate(Request $request)
    {
        $data = $request->all();

        $request->validate([
            'name'             => 'required|max:255',
            'email'            => 'required|email|max:255',
            'password_option'  => 'nullable|in:auto,manual',
            'password'         => 'nullable|min:6',
            'programme_id'     => 'nullable|exists:programmes,id,school_id,' . auth()->user()->school_id,
            'intake_session_id' => 'nullable|exists:intake_sessions,id,school_id,' . auth()->user()->school_id,
            'nationality'      => 'nullable|max:80',
            'national_id_or_passport' => 'nullable|max:50',
            'year_of_study'    => 'nullable|integer|min:1|max:20',
            'next_of_kin_address'  => 'nullable|string',
            'next_of_kin_contact'  => 'nullable|max:30',
            'status'           => 'nullable|in:active,suspended,graduated,withdrawn,deferred',
            'additional_photo' => 'nullable|mimes:jpg,jpeg,png|max:4096',
            'class_id'         => 'required|integer|exists:classes,id',
            'section_id'       => 'nullable|integer|exists:sections,id',
            'session_id'       => 'nullable|integer|exists:sessions,id',
            'department_id'    => 'nullable|integer|exists:departments,id',
        ]);

        $this->validateStudentAcademicScope($data);

        if (!empty($data['section_id']) && !Section::where('id', $data['section_id'])->where('class_id', $data['class_id'])->exists()) {
            throw ValidationException::withMessages(['section_id' => 'The selected section does not belong to the selected class.']);
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $photo = '';
        }

        $additionalImageName = '';
        if ($request->hasFile('additional_photo')) {
            $additionalImageName = 'additional_' . time() . '.' . $request->file('additional_photo')->extension();
            $request->file('additional_photo')->move(public_path('assets/uploads/user-images/'), $additionalImageName);
        }

        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];

        $data['user_information'] = json_encode($info);

        // Provisioning (user + profile + enrolment + fee invoice) is shared with
        // the CSV bulk import so a student added by hand and one added from a
        // spreadsheet end up with identical records.
        $result = \App\Support\Students\StudentProvisioner::provision($data, auth()->user()->school_id, false);
        \App\Support\Students\StudentProvisioner::sendWelcomeEmail($result['user'], $result['password']);

        return redirect()->back()->with('message', 'You have successfully add student.');
    }

    public function studentIdCardGenerate($id)
    {
        $student = $this->findStudentOrFail($id);
        $student_details = (new CommonController)->get_student_details_by_id($id);
        $studentProfile = \App\Models\StudentProfile::where('user_id', $id)->first();
        $programme = $studentProfile?->programme_id ? \App\Models\Programme::find($studentProfile->programme_id) : null;
        $school = \App\Models\School::find($student->school_id);
        $cardNumber = \App\Support\IdCard::cardNumber($student);
        $validFor = \App\Support\IdCard::validFor($student->school_id);
        $qrDataUri = \App\Support\IdCard::qrDataUri($student);

        return view('admin.student.id_card', compact('student_details', 'programme', 'school', 'cardNumber', 'validFor', 'qrDataUri'));
    }
    public function studentProfile($id)
    {
        $this->findStudentOrFail($id);
        $student_details = (new CommonController)->get_student_details_by_id($id);
        return view('admin.student.student_profile', ['student_details' => $student_details]);
    }

    public function studentEditModal(Request $request, $id)
    {
        $user            = $this->findStudentOrFail($id);
        $student_details = (new CommonController)->get_student_details_by_id($id);
        $classes         = Classes::get()->where('school_id', auth()->user()->school_id);
        $sessions        = Session::where('school_id', auth()->user()->school_id)->orderByDesc('id')->get();
        $departments     = Department::where('school_id', auth()->user()->school_id)->orderBy('name')->get();
        $programmes      = Programme::where('school_id', auth()->user()->school_id)->where('is_active', 1)->orderBy('name')->get();
        $intakeSessions  = IntakeSession::where('school_id', auth()->user()->school_id)->orderByDesc('id')->get();
        $studentProfile  = StudentProfile::where('user_id', $id)->first();
        $view_data = [
            'user' => $user, 'student_details' => $student_details, 'classes' => $classes,
            'programmes' => $programmes, 'intakeSessions' => $intakeSessions, 'studentProfile' => $studentProfile,
            'sessions' => $sessions, 'departments' => $departments,
        ];
        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Edit Student'),
                'inner_view' => 'admin.student.edit_student',
                'view_data'  => $view_data,
            ]);
        }
        return view('admin.student.edit_student', $view_data);
    }

    public function studentUpdate(Request $request, $id)
    {
        $data = $request->all();

        // RBAC Phase 2D: only ever a student in the caller's own school
        // (previously User::find() reached any account, Super Admin
        // included), and the login email must stay unique.
        $student = $this->findStudentOrFail($id);
        if ($this->loginEmailTaken($student, $data['email'] ?? null)) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }

        $request->validate([
            'name'  => 'required|max:255',
            'email' => 'required|email|max:255',
            'programme_id'     => 'nullable|exists:programmes,id,school_id,' . auth()->user()->school_id,
            'intake_session_id' => 'nullable|exists:intake_sessions,id,school_id,' . auth()->user()->school_id,
            'nationality'       => 'nullable|max:80',
            'national_id_or_passport' => 'nullable|max:50',
            'year_of_study'     => 'nullable|integer|min:1|max:20',
            'next_of_kin_address'  => 'nullable|string',
            'next_of_kin_contact'  => 'nullable|max:30',
            'status'            => 'nullable|in:active,suspended,graduated,withdrawn,deferred',
            'additional_photo'  => 'nullable|mimes:jpg,jpeg,png|max:4096',
            'class_id'          => 'required|integer|exists:classes,id',
            'section_id'        => 'nullable|integer|exists:sections,id',
            'session_id'        => 'nullable|integer|exists:sessions,id',
            'department_id'     => 'nullable|integer|exists:departments,id',
        ]);

        $this->validateStudentAcademicScope($data);

        if (!empty($data['section_id']) && !Section::where('id', $data['section_id'])->where('class_id', $data['class_id'])->exists()) {
            throw ValidationException::withMessages(['section_id' => 'The selected section does not belong to the selected class.']);
        }

        if (! empty($data['photo'])) {

            $imageName = ProfilePhoto::store($data['photo']);
            if ($imageName === null) {
                return redirect()->back()->with('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
            }

            $photo = $imageName;
        } else {
            $user_information = User::where('id', $id)->value('user_information');
            $decoded_info     = json_decode($user_information ?? '') ?: (object) [];
            $file_name        = $decoded_info->photo ?? '';

            if ($file_name != '') {
                $photo = $file_name;
            } else {
                $photo = '';
            }
        }
        $info = [
            'gender'      => $data['gender'],
            'blood_group' => $data['blood_group'],
            'birthday'    => strtotime($data['birthday']),
            'phone'       => $data['phone'],
            'address'     => $data['address'],
            'photo'       => $photo,
        ];
        $data['user_information'] = json_encode($info);
        $user = User::find($id);
        if ($user) {
            $user->update([
                'name'             => $data['name'],
                'email'            => $data['email'],
                'user_information' => $data['user_information'],
            ]);
        }

        // A Programme-track (HEI) student may have no Enrollment row yet —
        // update() alone would silently no-op for them, so any class
        // assigned here through this shared edit form would appear to save
        // but never actually take effect. updateOrCreate() fixes that: it
        // creates the row the first time a class is assigned, and from then
        // on updates it in place, exactly like a class/section-track
        // student's row already behaved.
        $schoolId = auth()->user()->school_id;
        $runningSession = $data['session_id'] ?? get_school_settings($schoolId)->value('running_session')
            ?: (Session::where('school_id', $schoolId)->where('status', 1)->value('id') ?? Session::value('id') ?? 1);
        $previousEnrollment = Enrollment::where('user_id', $id)->where('school_id', $schoolId)->first();
        $previousPlacement  = $previousEnrollment ? StatusChangeAudit::placement($previousEnrollment) : null;
        $enrollment = Enrollment::updateOrCreate(
            ['user_id' => $id, 'school_id' => $schoolId],
            [
                'class_id' => (int) $data['class_id'],
                'section_id' => (int) ($data['section_id'] ?? 0),
                'department_id' => (int) ($data['department_id'] ?? 0),
                'session_id' => (int) ($runningSession ?? 0),
            ]
        );

        StatusChangeAudit::enrollment($enrollment, $previousPlacement, 'Student record edit');

        $additionalImageName = null;
        if ($request->hasFile('additional_photo')) {
            $additionalImageName = 'additional_' . time() . '.' . $request->file('additional_photo')->extension();
            $request->file('additional_photo')->move(public_path('assets/uploads/user-images/'), $additionalImageName);
        }

        $profileData = [
            'school_id'               => auth()->user()->school_id,
            'programme_id'            => $data['programme_id'] ?? null,
            'intake_session_id'       => $data['intake_session_id'] ?? null,
            'year_of_study'           => $data['year_of_study'] ?? null,
            'nationality'             => $data['nationality'] ?? null,
            'national_id_or_passport' => $data['national_id_or_passport'] ?? null,
            'next_of_kin_address'     => $data['next_of_kin_address'] ?? null,
            'next_of_kin_contact'     => $data['next_of_kin_contact'] ?? null,
            'status'                  => $data['status'] ?? 'active',
        ];

        if ($additionalImageName) {
            $profileData['additional_image'] = $additionalImageName;
        }

        StudentProfile::updateOrCreate(['user_id' => $id], $profileData);

        return redirect()->back()->with('message', 'You have successfully update student.');
    }

    /**
     * Keep student academic selections inside the administrator's school.
     * The generic exists rules above provide friendly field validation; this
     * guard prevents cross-school IDs from being attached to an enrollment.
     */
    private function validateStudentAcademicScope(array $data): void
    {
        $schoolId = (int) auth()->user()->school_id;

        if (! Classes::where('id', $data['class_id'] ?? null)->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['class_id' => 'The selected class is not available in this school.']);
        }

        if (! empty($data['session_id']) && ! Session::where('id', $data['session_id'])->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['session_id' => 'The selected academic session is not available in this school.']);
        }

        if (! empty($data['department_id']) && ! Department::where('id', $data['department_id'])->where('school_id', $schoolId)->exists()) {
            throw ValidationException::withMessages(['department_id' => 'The selected department is not available in this school.']);
        }

        if (! empty($data['section_id']) && ! Section::where('id', $data['section_id'])
            ->where('class_id', $data['class_id'])
            ->exists()) {
            throw ValidationException::withMessages(['section_id' => 'The selected section is not available for this school/class.']);
        }
    }

    public function studentDelete($id)
    {
        // RBAC Phase 2D: resolve the student (same school, role 7) before any
        // related record below is touched.
        $student = $this->findStudentOrFail($id);

        // A programme-based (HEI) student has no Enrollment row at all — only
        // a StudentProfile — so both must be handled without assuming either
        // exists, or this crashes/orphans data depending on which structure
        // the student actually belongs to.
        $enroll = Enrollment::where('user_id', $id)->first();
        if ($enroll) {
            $enroll->delete();
        }

        $studentProfile = StudentProfile::where('user_id', $id)->first();
        if ($studentProfile) {
            $studentProfile->delete();
        }

        $fee_history = StudentFeeManager::get()->where('student_id', $id);
        $fee_history->map->delete();

        $attendances = DailyAttendances::get()->where('student_id', $id);
        $attendances->map->delete();

        $book_issues = BookIssue::get()->where('student_id', $id);
        $book_issues->map->delete();

        $gradebooks = Gradebook::get()->where('student_id', $id);
        $gradebooks->map->delete();

        $payments = Payments::get()->where('user_id', $id);
        $payments->map->delete();

        $payment_history = PaymentHistory::get()->where('user_id', $id);
        $payment_history->map->delete();

        $student->delete();

        $students = User::get()->where('role_id', 7);
        return redirect()->back()->with('message', 'Student removed successfully.');
    }

    /**
     * Show the teacher permission form.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function teacherPermission(Request $request)
    {
        $classes  = Classes::get()->where('school_id', auth()->user()->school_id);
        $requestedClassId = (int) $request->input('class_id', 0);
        $default_class_id = $requestedClassId && $classes->contains('id', $requestedClassId)
            ? $requestedClassId : optional($classes->first())->id;
        $sections = collect();
        $default_section_id = 0;

        if (!empty($default_class_id)) {
            $sections = Section::get()->where('class_id', $default_class_id);
            $default_section_id = optional($sections->first())->id ?: 0;
        }

        $requestedSectionId = (int) $request->input('section_id', 0);
        if ($requestedSectionId && $sections->contains('id', $requestedSectionId)) {
            $default_section_id = $requestedSectionId;
        }

        $teachers = User::where('role_id', 3)
            ->where('school_id', auth()->user()->school_id)
            ->get();

        $programmes = Programme::where('school_id', auth()->user()->school_id)->where('is_active', 1)->orderBy('name')->get();
        $default_programme_id = optional($programmes->first())->id;

        return view('admin.permission.index', [
            'classes'            => $classes,
            'sections'           => $sections,
            'teachers'           => $teachers,
            'default_class_id'   => $default_class_id,
            'default_section_id' => $default_section_id,
            'programmes'            => $programmes,
            'default_programme_id'  => $default_programme_id,
        ]);
    }

    public function teacherProgrammeAssignmentList($programme_id = "")
    {
        $teachers = User::where('role_id', 3)
            ->where('school_id', auth()->user()->school_id)
            ->get();
        return view('admin.permission.programme_list', ['teachers' => $teachers, 'programme_id' => $programme_id]);
    }

    public function teacherProgrammeAssignmentUpdate(Request $request)
    {
        $data = $request->all();

        $programme_id = $data['programme_id'];
        $teacher_id    = $data['teacher_id'];
        $column_name   = $data['column_name'];

        TeacherProgrammeAssignment::updateOrCreate(
            [
                'programme_id' => $programme_id,
                'teacher_id'   => $teacher_id,
                'school_id'    => auth()->user()->school_id,
            ],
            [
                $column_name  => $data['value'],
                'updated_at'  => now(),
            ]
        );

        return response()->json(['status' => 'success', 'message' => get_phrase('Permission updated successfully.')]);
    }

    public function teacherPermissionList($value = "")
    {
        $data       = explode('-', $value);
        $class_id   = $data[0];
        $section_id = $data[1];
        $teachers   = User::where('role_id', 3)
            ->where('school_id', auth()->user()->school_id)
            ->get();
        return view('admin.permission.list', ['teachers' => $teachers, 'class_id' => $class_id, 'section_id' => $section_id]);
    }

    public function teacherPermissionUpdate(Request $request)
    {
        $data = $request->all();

        $class_id    = $data['class_id'];
        $section_id  = $data['section_id'];
        $teacher_id  = $data['teacher_id'];
        $column_name = $data['column_name'] ?? '';
        // Only the two assignment flags may be written (the column name comes from the request).
        abort_unless(in_array($column_name, ['marks', 'attendance'], true), 422, 'Unknown teacher permission.');
        $value       = (int) filter_var($data['value'] ?? 0, FILTER_VALIDATE_BOOLEAN);

        $check_row = TeacherPermission::where('class_id', $class_id)
            ->where('section_id', $section_id)
            ->where('teacher_id', $teacher_id)
            ->where('school_id', auth()->user()->school_id)
            ->get();

        if (count($check_row) > 0) {

            TeacherPermission::where('class_id', $class_id)
                ->where('section_id', $section_id)
                ->where('teacher_id', $teacher_id)
                ->where('school_id', auth()->user()->school_id)
                ->update([
                    'class_id'   => $class_id,
                    'section_id' => $section_id,
                    'school_id'  => auth()->user()->school_id,
                    'teacher_id' => $teacher_id,
                    $column_name => $value,
                ]);
        } else {
            // teacher_permissions.marks / attendance / updated_at are NOT NULL integers with no
            // default (migration 2022_07_24_134113): the first assignment writes all of them.
            TeacherPermission::create([
                'class_id'   => $class_id,
                'section_id' => $section_id,
                'school_id'  => auth()->user()->school_id,
                'teacher_id' => $teacher_id,
                'marks'      => $column_name === 'marks' ? $value : 0,
                'attendance' => $column_name === 'attendance' ? $value : 0,
                'updated_at' => time(),
            ]);
        }
    }

    /**
     * Show the offline_admission form.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function offlineAdmissionForm($type = '')
    {
        // Single Student Admission has been superseded by the staff-entry
        // admission wizard (same Admission model/workflow as the online
        // applicant portal — see App\Http\Controllers\Admin\AdmissionWizardController).
        // This route/name is kept so existing bookmarks, the "Create Student"
        // button (resources/views/admin/student/student_list.blade.php) and
        // this page's own nav-permission key keep working; it now redirects
        // into the wizard instead of rendering the old one-page form. Bulk
        // and Excel admission are untouched — they still render this page.
        if ($type === 'single' || $type === '') {
            return redirect()->route('admin.hei_admissions.wizard.create');
        }

        $data['parents']     = User::where(['role_id' => 6, 'school_id' => 1])->get();
        $data['departments'] = Department::get()->where('school_id', auth()->user()->school_id);
        $data['classes']     = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.offline_admission.offline_admission', ['aria_expand' => $type, 'data' => $data]);
    }

    public function offlineAdmissionCreate(Request $request)
    {
        $package = Subscription::where('school_id', auth()->user()->school_id)->latest()->first();

        // Legacy schools may not have a subscription row yet; treat as unlimited access.
        $student_limit = $package->studentLimit ?? ($package->student_limit ?? 'unlimited');

        $student_count = User::where(['role_id' => 7, 'school_id' => auth()->user()->school_id])->count();
        $department_id = $request->department_id ?? Department::where('school_id', auth()->user()->school_id)->value('id') ?? 0;

        if ($student_limit == 'unlimited' || $student_limit > $student_count) {

            $data           = $request->all();
            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
            if (empty($active_session)) {
                $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                    ?? Session::value('id')
                    ?? 1;
            }

            if (! empty($data['photo'])) {

                $imageName = ProfilePhoto::store($data['photo']) ?? '';

                $photo = $imageName;
            } else {
                $photo = '';
            }

            $info = [
                'gender'      => $data['gender'],
                'blood_group' => $data['blood_group'],
                'birthday'    => strtotime($data['eDefaultDateRange']),
                'phone'       => $data['phone'],
                'address'     => $data['address'],
                'photo'       => $photo,
            ];
            $data['user_information'] = json_encode($info);

            $duplicate_user_check = User::get()->where('email', $data['email']);

            if (count($duplicate_user_check) == 0) {

                $user = User::create([
                    'name'             => $data['name'],
                    'email'            => $data['email'],
                    'password'         => Hash::make($data['password']),
                    'code'             => student_code(),
                    'role_id'          => '7',
                    'school_id'        => auth()->user()->school_id,
                    'user_information' => $data['user_information'],
                    'status'           => 1,
                ]);

                Enrollment::create([
                    'user_id'       => $user->id,
                    'class_id'      => $data['class_id'],
                    'section_id'    => $data['section_id'],
                    'school_id'     => auth()->user()->school_id,
                    'department_id' => $department_id,
                    'session_id'    => $active_session,
                ]);

                \App\Support\StudentFeeInvoiceGenerator::generateForClassBasedStudent($user, (int) $data['class_id'], auth()->user()->school_id);

                if (! empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))) {
                    \App\Support\Mail\SafeMail::send($data['email'], new NewUserEmail($data), 'account');
                }
                return redirect()->back()->with('message', 'Admission successfully done.');
            } else {

                return redirect()->back()->with('error', 'Sorry this email has been taken');
            }
        } else {
            return redirect()->back()->with('error', 'Your students limit out.Please upgrade to add more students');
        }
    }

    public function offlineAdmissionBulkCreate(Request $request)
    {
        $data = $request->all();

        $duplication_counter = 0;
        $class_id            = $data['class_id'];
        $section_id          = $data['section_id'];
        $department_id       = $data['department_id'];

        $students_name     = $data['name'];
        $students_email    = $data['email'];
        $students_password = $data['password'];
        $students_gender   = $data['gender'];
        $students_parent   = $data['parent_id'];

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? 1;
        }

        foreach ($students_name as $key => $value) {
            $duplicate_user_check = User::get()->where('email', $students_email[$key]);

            if (count($duplicate_user_check) == 0) {

                $info = [
                    'gender'      => $students_gender[$key],
                    'blood_group' => '',
                    'birthday'    => '',
                    'phone'       => '',
                    'address'     => '',
                    'photo'       => '',
                ];
                $data['user_information'] = json_encode($info);

                $user = User::create([
                    'name'             => $students_name[$key],
                    'email'            => $students_email[$key],
                    'password'         => Hash::make($students_password[$key]),
                    'code'             => student_code(),
                    'role_id'          => '7',
                    // RBAC Phase 2D: only a parent in this school may be linked.
                    'parent_id'        => User::where('id', $students_parent[$key] ?? null)->where('school_id', auth()->user()->school_id)->where('role_id', 6)->value('id'),
                    'school_id'        => auth()->user()->school_id,
                    'user_information' => $data['user_information'],
                    'status'           => 1,
                ]);

                Enrollment::create([
                    'user_id'       => $user->id,
                    'class_id'      => $class_id,
                    'section_id'    => $section_id,
                    'school_id'     => auth()->user()->school_id,
                    'department_id' => $department_id,
                    'session_id'    => $active_session,
                ]);

                \App\Support\StudentFeeInvoiceGenerator::generateForClassBasedStudent($user, (int) $class_id, auth()->user()->school_id);
            } else {
                $duplication_counter++;
            }
        }

        if ($duplication_counter > 0) {

            return redirect()->back()->with('warning', 'Some of the emails have been taken.');
        } else {

            return redirect()->back()->with('message', 'Students added successfully');
        }
    }

    public function offlineAdmissionExcelCreate(Request $request)
    {
        $data = $request->all();

        $class_id   = $data['class_id'];
        $section_id = $data['section_id'];
        $school_id  = auth()->user()->school_id;
        $session_id = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($session_id)) {
            $session_id = Session::where('school_id', $school_id)->value('id')
                ?? Session::value('id')
                ?? 1;
        }
        $department_id = $request->department_id ?? Department::where('school_id', $school_id)->value('id') ?? 0;
        $package    = Subscription::where('school_id', auth()->user()->school_id)->latest()->first();

        // Keep same fallback behavior as single admission to avoid null crashes.
        $student_limit = $package->studentLimit ?? ($package->student_limit ?? 'unlimited');

        $student_count = User::where(['role_id' => 7, 'school_id' => auth()->user()->school_id])->count();

        $file = $data['csv_file'];
        if ($file) {
            $filename = SafeUpload::store($file, public_path('assets/csv_file/'), ['csv', 'txt']) ?? abort(422, 'This file type is not allowed.');

            // In case the uploaded file path is to be stored in the database
            $filepath = url('public/assets/csv_file/' . $filename);
        }

        if (($handle = fopen($filepath, 'r')) !== false) { // Check the resource is valid
            $count               = 0;
            $duplication_counter = 0;

            while (($all_data = fgetcsv($handle, 1000, ",")) !== false) { // Check opening the file is OK!
                if ($student_limit == 'unlimited' || $student_limit > $student_count) {
                    if ($count > 0) {

                        $duplicate_user_check = User::get()->where('email', $all_data[1]);

                        if (count($duplicate_user_check) == 0) {

                            $info = [
                                'gender'      => $all_data[5],
                                'blood_group' => $all_data[4],
                                'birthday'    => strtotime($all_data[6]),
                                'phone'       => $all_data[3],
                                'address'     => $all_data[7],
                                'photo'       => '',
                            ];

                            $data['user_information'] = json_encode($info);

                            $user = User::create([
                                'name'             => $all_data[0],
                                'email'            => $all_data[1],
                                'password'         => Hash::make($all_data[2]),
                                'code'             => student_code(),
                                'role_id'          => '7',
                                'school_id'        => $school_id,
                                'user_information' => $data['user_information'],
                                'status'           => 1,
                            ]);

                            Enrollment::create([
                                'user_id'       => $user->id,
                                'class_id'      => $class_id,
                                'section_id'    => $section_id,
                                'school_id'     => $school_id,
                                'department_id' => $department_id,
                                'session_id'    => $session_id,
                            ]);

                            \App\Support\StudentFeeInvoiceGenerator::generateForClassBasedStudent($user, (int) $class_id, $school_id);
                        } else {
                            $duplication_counter++;
                        }

                        // check email duplication

                    }
                } else {
                    return redirect()->back()->with('error', 'Your students limit out.Please upgrade to add more students.');
                }
                $count++;
            }

            fclose($handle);
        }

        if ($duplication_counter > 0) {

            return redirect()->back()->with('warning', 'Some of the emails have been taken.');
        } else {

            return redirect()->back()->with('message', 'Students added successfully');
        }
    }

    /**
     * Show the exam category list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function examCategoryList()
    {
        $exam_categories = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.exam_category.exam_category', ['exam_categories' => $exam_categories]);
    }

    public function createExamCategory()
    {
        return view('admin.exam_category.create');
    }

    public function examCategoryCreate(Request $request)
    {
        $data           = $request->all();
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? 1;
        }

        ExamCategory::create([
            'name'       => $data['name'],
            'school_id'  => auth()->user()->school_id,
            'session_id' => $active_session,
            'timestamp'  => strtotime(date('Y-m-d')),
        ]);
        return redirect()->back()->with('message', 'Exam category created successfully.');
    }

    public function editExamCategory($id = '')
    {
        $exam_category = ExamCategory::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $exam_category) {
            return redirect()->back()->with('error', 'Exam category not found.');
        }
        return view('admin.exam_category.edit', ['exam_category' => $exam_category]);
    }

    public function examCategoryUpdate(Request $request, $id)
    {
        $data           = $request->all();
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? 1;
        }

        $exam_category = ExamCategory::where('id', $id)
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $exam_category) {
            return redirect()->back()->with('error', 'Exam category not found.');
        }

        $exam_category->update([
            'name'       => $data['name'],
            'session_id' => $active_session,
            'timestamp'  => strtotime(date('Y-m-d')),
        ]);

        return redirect()->back()->with('message', 'Exam category updated successfully.');
    }

    public function examCategoryDelete($id = '')
    {
        $exam_category = ExamCategory::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $exam_category) {
            return redirect()->back()->with('error', 'Exam category not found.');
        }
        $exam_category->delete();
        return redirect()->back()->with('message', 'You have successfully delete exam category.');
    }

    /**
     * Show the exam list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function offlineExamList()
    {
        $id      = "all";
        $exams   = Exam::get()->where('exam_type', 'offline')->where('school_id', auth()->user()->school_id);
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.examination.offline_exam_list', ['exams' => $exams, 'classes' => $classes, 'id' => $id]);
    }

    public function offlineExamExport($id = "")
    {
        if ($id != "all") {
            $exams = Exam::where([
                'exam_type' => 'offline',
                'class_id'  => $id,
                'school_id' => auth()->user()->school_id,
            ])->get();
        } else {
            $exams = Exam::where('exam_type', 'offline')->where('school_id', auth()->user()->school_id)->get();
        }
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.examination.offline_exam_export', ['exams' => $exams, 'classes' => $classes]);
    }

    public function classWiseOfflineExam($id)
    {
        $exams = Exam::where([
            'exam_type' => 'offline',
            'class_id'  => $id,
            'school_id' => auth()->user()->school_id,
        ])->get();
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.examination.exam_list', ['exams' => $exams, 'classes' => $classes, 'id' => $id]);
    }

    public function createOfflineExam(Request $request)
    {
        $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
        $exam_categories = ExamCategory::where('school_id', auth()->user()->school_id)->get();

        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Create Offline Exam'),
                'inner_view' => 'admin.examination.add_offline_exam',
                'view_data'  => ['classes' => $classes, 'exam_categories' => $exam_categories],
            ]);
        }

        return view('admin.examination.add_offline_exam', ['classes' => $classes, 'exam_categories' => $exam_categories]);
    }

    public function classWiseSubject($id)
    {
        $subjects = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->exists() ? Subject::get()->where('class_id', $id) : collect();
        $options  = '<option value="">' . 'Select a subject' . '</option>';
        foreach ($subjects as $subject):
            $options .= '<option value="' . $subject->id . '">' . $subject->name . '</option>';
        endforeach;
        echo $options;
    }

    public function offlineExamCreate(Request $request)
    {

        // Retrieve request data
        $data         = $request->input('class_room_id');
        $startingTime = strtotime($request->starting_date . '' . $request->starting_time);
        $endingTime   = strtotime($request->ending_date . '' . $request->ending_time);

        // Check if the room is occupied for the specified time range
        $occupiedExams = Exam::where('school_id', auth()->user()->school_id)
            ->where('room_number', $data)
            ->where(function ($query) use ($startingTime, $endingTime) {
                $query->whereBetween('starting_time', [$startingTime, $endingTime])
                    ->orWhereBetween('ending_time', [$startingTime, $endingTime])
                    ->orWhere(function ($query) use ($startingTime, $endingTime) {
                        $query->where('starting_time', '<=', $startingTime)
                            ->where('ending_time', '>=', $endingTime);
                    });
            })
            ->get();
        // Return response based on room availability
        if (count($occupiedExams) != 0) {
            return redirect()->back()->with(['warning' => 'The room is occupied for the specified time range'], 409);
        } else {
            $data           = $request->all();
            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
            $exam_category  = ExamCategory::where('id', $data['exam_category_id'])
                ->where('school_id', auth()->user()->school_id)
                ->first();

            if (! $exam_category) {
                return redirect()->back()->with(['warning' => 'Invalid exam category.'], 422);
            }

            Exam::create([
                'name'             => $exam_category->name,
                'exam_category_id' => $data['exam_category_id'],
                'exam_type'        => 'offline',
                'room_number'      => $data['class_room_id'],
                'starting_time'    => strtotime($data['starting_date'] . '' . $data['starting_time']),
                'ending_time'      => strtotime($data['ending_date'] . '' . $data['ending_time']),
                'total_marks'      => $data['total_marks'],
                'status'           => 'pending',
                'class_id'         => $data['class_id'],
                'subject_id'       => $data['subject_id'],
                'school_id'        => auth()->user()->school_id,
                'session_id'       => $active_session,
            ]);

            return redirect()->back()->with(['message' => 'You have successfully create exam'], 200);
        }
    }

    public function editOfflineExam(Request $request, $id)
    {
        $exam = Exam::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $exam) {
            return redirect()->back()->with('error', 'Exam not found.');
        }
        $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
        $subjects        = Subject::get()->where('class_id', $exam->class_id);
        $exam_categories = ExamCategory::where('school_id', auth()->user()->school_id)->get();

        if (! $request->ajax()) {
            return view('admin.common.modal_standalone_wrapper', [
                'page_title' => get_phrase('Edit Offline Exam'),
                'inner_view' => 'admin.examination.edit_offline_exam',
                'view_data'  => [
                    'exam'            => $exam,
                    'classes'         => $classes,
                    'subjects'        => $subjects,
                    'exam_categories' => $exam_categories,
                ],
            ]);
        }

        return view('admin.examination.edit_offline_exam', ['exam' => $exam, 'classes' => $classes, 'subjects' => $subjects, 'exam_categories' => $exam_categories]);
    }

    public function offlineExamUpdate(Request $request, $id)
    {
        $data           = $request->all();
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        $exam_category  = ExamCategory::where('id', $data['exam_category_id'])
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $exam_category) {
            return redirect()->back()->with(['warning' => 'Invalid exam category.'], 422);
        }

        $updated = Exam::where('id', $id)
            ->where('school_id', auth()->user()->school_id)
            ->update([
                'name'             => $exam_category->name,
                'exam_category_id' => $exam_category->id,
                'exam_type'        => 'offline',
                'room_number'      => $data['class_room_id'],
                'starting_time'    => strtotime($data['starting_date'] . '' . $data['starting_time']),
                'ending_time'      => strtotime($data['ending_date'] . '' . $data['ending_time']),
                'total_marks'      => $data['total_marks'],
                'status'           => 'pending',
                'class_id'         => $data['class_id'],
                'subject_id'       => $data['subject_id'],
                'session_id'       => $active_session,
            ]);

        if (! $updated) {
            return redirect()->back()->with('error', 'Exam not found.');
        }
        return redirect()->back()->with('message', 'You have successfully update exam.');
    }

    public function offlineExamDelete($id)
    {
        $exam = Exam::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $exam) {
            return redirect()->back()->with('error', 'Exam not found.');
        }
        $exam->delete();
        return redirect()->back()->with('message', 'You have successfully delete exam.');
    }

    /**
     * Show the grade daily attendance.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function dailyAttendance()
    {
        $classes                = Classes::where('school_id', auth()->user()->school_id)->get();
        $attendance_of_students = [];
        $no_of_users            = 0;

        return view('admin.attendance.daily_attendance', ['classes' => $classes, 'attendance_of_students' => $attendance_of_students, 'no_of_users' => $no_of_users]);
    }

    public function dailyAttendanceFilter(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['month' => 'present', 'year' => 'present', 'class_id' => 'present', 'section_id' => 'present']);
        $data       = $request->all();
        $date       = '01 ' . $data['month'] . ' ' . $data['year'];
        $first_date = strtotime($date);
        $last_date  = date("Y-m-t", strtotime($date));
        $last_date  = strtotime($last_date);

        $page_data['attendance_date'] = strtotime($date);
        $page_data['class_id']        = $data['class_id'];
        $page_data['section_id']      = $data['section_id'];
        $page_data['month']           = $data['month'];
        $page_data['year']            = $data['year'];

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $attendance_of_students = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get()->toArray();

        $students_details = Enrollment::where('class_id', $page_data['class_id'])
            ->where('section_id', $page_data['section_id'])
            ->get();

        $no_of_users = DailyAttendances::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->distinct()->count('student_id');

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('admin.attendance.attendance_list', ['page_data' => $page_data, 'classes' => $classes, 'attendance_of_students' => $attendance_of_students, 'students_details' => $students_details, 'no_of_users' => $no_of_users]);
    }

    public function takeAttendance()
    {
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.attendance.take_attendance', ['classes' => $classes]);
    }

    public function studentListAttendance(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['date' => 'present', 'class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $page_data['attendance_date'] = $data['date'];
        $page_data['class_id']        = $data['class_id'];
        $page_data['section_id']      = $data['section_id'];

        return view('admin.attendance.student', ['page_data' => $page_data]);
    }

    public function attendanceTake(Request $request)
    {
        $att_data = $request->all();

        $students       = $att_data['student_id'];
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['timestamp']  = strtotime($att_data['date']);
        $data['class_id']   = $att_data['class_id'];
        $data['section_id'] = $att_data['section_id'];
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;

        $check_data = DailyAttendances::where(['timestamp' => $data['timestamp'], 'class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'session_id' => $active_session, 'school_id' => auth()->user()->school_id])->get();
        if (count($check_data) > 0) {
            foreach ($students as $key => $student):
                $data['status']     = $att_data['status-' . $student];
                $data['student_id'] = $student;
                $attendance_id      = $att_data['attendance_id'];

                if (isset($attendance_id[$key])) {

                    DailyAttendances::where('id', $attendance_id[$key])->update($data);
                } else {
                    DailyAttendances::create($data);
                }
            endforeach;
        } else {
            foreach ($students as $student):
                $data['status']     = $att_data['status-' . $student];
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
        $attendance_of_students = DailyAttendances::whereBetween('timestamp', [$first_date, $last_date])->where(['school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get()->toArray();

        $csv_content    = "Student" . "/" . get_phrase('Date');
        $number_of_days = date('m', $page_data['attendance_date']) == 2 ? (date('Y', $page_data['attendance_date']) % 4 ? 28 : (date('m', $page_data['attendance_date']) % 100 ? 29 : (date('m', $page_data['attendance_date']) % 400 ? 28 : 29))) : ((date('m', $page_data['attendance_date']) - 1) % 7 % 2 ? 30 : 31);
        for ($i = 1; $i <= $number_of_days; $i++) {
            $csv_content .= ',' . get_phrase($i);
        }

        $file = "Attendance_report.csv";

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
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.routine.routine', ['classes' => $classes]);
    }

    public function routineList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $class_id   = $data['class_id'];
        $section_id = $data['section_id'];
        $classes    = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('admin.routine.routine_list', ['class_id' => $class_id, 'section_id' => $section_id, 'classes' => $classes]);
    }

    public function addRoutine()
    {
        $classes     = Classes::get()->where('school_id', auth()->user()->school_id);
        $teachers    = User::where(['role_id' => 3, 'school_id' => auth()->user()->school_id])->get();
        $class_rooms = ClassRoom::get()->where('school_id', auth()->user()->school_id);
        return view('admin.routine.add_routine', ['classes' => $classes, 'teachers' => $teachers, 'class_rooms' => $class_rooms]);
    }

    public function routineAdd(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? null;
        }

        if (empty($active_session)) {
            return redirect()->back()->with('error', 'Please create or set an active academic session before adding routine.');
        }

        Routine::create([
            'class_id'        => $data['class_id'],
            'section_id'      => $data['section_id'],
            'subject_id'      => $data['subject_id'],
            'teacher_id'      => $data['teacher_id'],
            'room_id'         => $data['class_room_id'],
            'day'             => $data['day'],
            'starting_hour'   => $data['starting_hour'],
            'starting_minute' => $data['starting_minute'],
            'ending_hour'     => $data['ending_hour'],
            'ending_minute'   => $data['ending_minute'],
            'school_id'       => auth()->user()->school_id,
            'session_id'      => $active_session,
        ]);

        return redirect('/admin/routine/list?class_id=' . $data['class_id'] . '&section_id=' . $data['section_id'])->with('message', 'You have successfully create a class routine.');
    }

    public function routineEditModal($id)
    {
        $routine     = Routine::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes     = Classes::get()->where('school_id', auth()->user()->school_id);
        $teachers    = User::where(['role_id' => 3, 'school_id' => auth()->user()->school_id])->get();
        $class_rooms = ClassRoom::get()->where('school_id', auth()->user()->school_id);
        return view('admin.routine.edit_routine', ['routine' => $routine, 'classes' => $classes, 'teachers' => $teachers, 'class_rooms' => $class_rooms]);
    }

    public function routineUpdate(Request $request, $id)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? null;
        }

        if (empty($active_session)) {
            return redirect()->back()->with('error', 'Please create or set an active academic session before updating routine.');
        }

        $routine = Routine::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($routine) {
            $routine->update([
                'class_id'        => $data['class_id'],
                'section_id'      => $data['section_id'],
                'subject_id'      => $data['subject_id'],
                'teacher_id'      => $data['teacher_id'],
                'room_id'         => $data['class_room_id'],
                'day'             => $data['day'],
                'starting_hour'   => $data['starting_hour'],
                'starting_minute' => $data['starting_minute'],
                'ending_hour'     => $data['ending_hour'],
                'ending_minute'   => $data['ending_minute'],
                'school_id'       => auth()->user()->school_id,
                'session_id'      => $active_session,
            ]);
        }

        return redirect()->back()->with('message', 'You have successfully update routine.');
    }

    public function routineDelete($id)
    {
        $routine = Routine::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $routine->delete();
        return redirect()->back()->with('message', 'You have successfully delete routine.');
    }

    /**
     * Show the syllabus.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function syllabus()
    {
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.syllabus.syllabus', ['classes' => $classes]);
    }

    public function syllabusList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present']);
        $data = $request->all();

        $class_id   = $data['class_id'];
        $section_id = $data['section_id'];
        $classes    = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('admin.syllabus.syllabus_list', ['class_id' => $class_id, 'section_id' => $section_id, 'classes' => $classes]);
    }

    public function addSyllabus()
    {
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.syllabus.add_syllabus', ['classes' => $classes]);
    }

    public function syllabusAdd(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? null;
        }

        if (empty($active_session)) {
            return redirect()->back()->with('error', 'Please create or set an active academic session before adding syllabus.');
        }

        $file = $data['syllabus_file'] ?? null;
        $filename = '';

        if ($file) {
            $filename = SafeUpload::store($file, public_path('assets/uploads/syllabus/'), null) ?? abort(422, 'This file type is not allowed.');

            $filepath = asset('assets/uploads/syllabus/' . $filename);
        }

        Syllabus::create([
            'title'      => $data['title'],
            'class_id'   => $data['class_id'],
            'section_id' => $data['section_id'],
            'subject_id' => $data['subject_id'],
            'file'       => $filename,
            'school_id'  => auth()->user()->school_id,
            'session_id' => $active_session,
        ]);

        return redirect('/admin/syllabus/list?class_id=' . $data['class_id'] . '&section_id=' . $data['section_id'])->with('message', 'You have successfully create a syllabus.');
    }

    public function syllabusEditModal($id)
    {
        $syllabus = Syllabus::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes  = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.syllabus.edit_syllabus', ['syllabus' => $syllabus, 'classes' => $classes]);
    }

    public function syllabusUpdate(Request $request, $id)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (empty($active_session)) {
            $active_session = Session::where('school_id', auth()->user()->school_id)->value('id')
                ?? Session::value('id')
                ?? null;
        }

        if (empty($active_session)) {
            return redirect()->back()->with('error', 'Please create or set an active academic session before updating syllabus.');
        }

        $syllabus = Syllabus::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $file = $data['syllabus_file'] ?? null;
        $filename = $syllabus->file ?? '';

        if ($file) {
            $filename = SafeUpload::store($file, public_path('assets/uploads/syllabus/'), null) ?? abort(422, 'This file type is not allowed.');

            $filepath = asset('assets/uploads/syllabus/' . $filename);
        }

        if ($syllabus) {
            $syllabus->update([
                'title'      => $data['title'],
                'class_id'   => $data['class_id'],
                'section_id' => $data['section_id'],
                'subject_id' => $data['subject_id'],
                'file'       => $filename,
                'school_id'  => auth()->user()->school_id,
                'session_id' => $active_session,
            ]);
        }

        return redirect('/admin/syllabus/list?class_id=' . $data['class_id'] . '&section_id=' . $data['section_id'])->with('message', 'You have successfully update a syllabus.');
    }

    public function syllabusDelete($id)
    {
        $syllabus = Syllabus::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $syllabus->delete();
        return redirect()->back()->with('message', 'You have successfully delete syllabus.');
    }

    /**
     * Show the gradebook.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function gradebook(Request $request)
    {

        $classes         = Classes::get()->where('school_id', auth()->user()->school_id);
        $exam_categories = ExamCategory::get()->where('school_id', auth()->user()->school_id);

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if (count($request->all()) > 0) {

            $data = $request->all();

            $filter_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get();

            $class_id         = $data['class_id'];
            $section_id       = $data['section_id'];
            $exam_category_id = $data['exam_category_id'];
            $subjects         = Subject::where(['class_id' => $class_id, 'school_id' => auth()->user()->school_id])->get();
        } else {
            $filter_list = [];

            $class_id         = '';
            $section_id       = '';
            $exam_category_id = '';
            $subjects         = '';
        }

        return view('admin.gradebook.gradebook', ['filter_list' => $filter_list, 'class_id' => $class_id, 'section_id' => $section_id, 'exam_category_id' => $exam_category_id, 'classes' => $classes, 'exam_categories' => $exam_categories, 'subjects' => $subjects]);
    }

    public function gradebookList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present', 'exam_category_id' => 'present']);
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $exam_wise_student_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get();
        echo view('admin.gradebook.list', ['exam_wise_student_list' => $exam_wise_student_list, 'class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session]);
    }

    public function subjectWiseMarks(Request $request, $student_id = "")
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $subject_wise_mark_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'student_id' => $student_id, 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->first();

        echo view('admin.gradebook.subject_marks', ['subject_wise_mark_list' => $subject_wise_mark_list]);
    }

    public function addMark()
    {
        $classes         = Classes::get()->where('school_id', auth()->user()->school_id);
        $exam_categories = ExamCategory::get()->where('school_id', auth()->user()->school_id);
        return view('admin.gradebook.add_mark', ['classes' => $classes, 'exam_categories' => $exam_categories]);
    }

    public function markAdd(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $subject_wise_mark_list = Gradebook::where(['class_id' => $data['class_id'], 'section_id' => $data['section_id'], 'exam_category_id' => $data['exam_category_id'], 'student_id' => $data['student_id'], 'school_id' => auth()->user()->school_id, 'session_id' => $active_session])->get();

        $result = $subject_wise_mark_list->count();

        if ($result > 0) {

            return redirect()->back()->with('message', 'Mark added successfully.');
        } else {

            $mark = [$data['subject_id'] => $data['mark']];

            $marks = json_encode($mark);

            $data['marks']      = $marks;
            $data['school_id']  = auth()->user()->school_id;
            $data['session_id'] = $active_session;
            $data['timestamp']  = strtotime(date('Y-m-d'));

            Gradebook::create($data);

            return redirect()->back()->with('message', 'Mark added successfully.');
        }
    }

    public function marks($value = '')
    {
        $page_data['exam_categories'] = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $page_data['classes']         = Classes::where('school_id', auth()->user()->school_id)->get();
        $page_data['sessions']        = Session::where('school_id', auth()->user()->school_id)->get();

        return view('admin.marks.index', $page_data);
    }

    public function marksFilter(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['exam_category_id' => 'present', 'class_id' => 'present', 'section_id' => 'present', 'subject_id' => 'present', 'session_id' => 'present']);
        $data = $request->all();

        $page_data['exam_category_id'] = $data['exam_category_id'];
        $page_data['class_id']         = $data['class_id'];
        $page_data['section_id']       = $data['section_id'];
        $page_data['subject_id']       = $data['subject_id'];
        $page_data['session_id']       = $data['session_id'];

        // Pre-RBAC cleanup: these ids come from the request — resolve them within this school
        // (a section through its class), so another school's names are never echoed.
        $class = Classes::where('school_id', auth()->user()->school_id)->findOrFail($data['class_id']);
        $page_data['class_name']    = $class->name;
        $page_data['section_name']  = Section::where('class_id', $class->id)->findOrFail($data['section_id'])->name;
        $page_data['subject_name']  = Subject::where('school_id', auth()->user()->school_id)->findOrFail($data['subject_id'])->name;
        $page_data['session_title'] = Session::where('school_id', auth()->user()->school_id)->findOrFail($data['session_id'])->session_title;

        $enroll_students = Enrollment::where('class_id', $page_data['class_id'])
            ->where('section_id', $page_data['section_id'])
            ->get();

        $page_data['exam_categories'] = ExamCategory::where('school_id', auth()->user()->school_id)->get();
        $page_data['classes']         = Classes::where('school_id', auth()->user()->school_id)->get();

        $exam = Exam::where('exam_type', 'offline')
            ->where('class_id', $data['class_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('session_id', $data['session_id'])
            ->where('exam_category_id', $data['exam_category_id'])
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if ($exam) {
            $response = view('admin.marks.marks_list', ['enroll_students' => $enroll_students, 'page_data' => $page_data])->render();
            return response()->json(['status' => 'success', 'html' => $response]);
        } else {
            return response()->json(['status' => 'error', 'message' => 'No records found for the specified filter. First create exam for the selected filter.']);
        }
    }

    public function marksPdf($section_id = "", $class_id = "", $session_id = "", $exam_category_id = "", $subject_id = "")
    {

        $enroll_students = Enrollment::where('class_id', $class_id)
            ->where('section_id', $section_id)
            ->get();

        $data = [
            'enroll_students'  => $enroll_students,
            'section_id'       => $section_id,
            'class_id'         => $class_id,
            'session_id'       => $session_id,
            'exam_category_id' => $exam_category_id,
            'subject_id'       => $subject_id,
        ];

        $pdf = PDF::loadView('admin.marks.markPdf', $data);

        return $pdf->download('webappfix.pdf');

        // return $pdf->stream('webappfix.pdf');
    }

    /**
     * Show the grade list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function gradeList()
    {
        $grades = Grade::get()->where('school_id', auth()->user()->school_id);
        return view('admin.grade.grade_list', ['grades' => $grades]);
    }

    public function createGrade()
    {
        return view('admin.grade.add_grade');
    }

    public function gradeCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_grade_check = Grade::get()->where('name', $data['grade'])->where('school_id', auth()->user()->school_id);

        if (count($duplicate_grade_check) == 0) {
            Grade::create([
                'name'        => $data['grade'],
                'grade_point' => $data['grade_point'],
                'mark_from'   => $data['mark_from'],
                'mark_upto'   => $data['mark_upto'],
                'school_id'   => auth()->user()->school_id,
            ]);

            return redirect()->back()->with('message', 'You have successfully create a new grade.');
        } else {
            return back()
                ->with('error', 'Sorry this grade already exists');
        }
    }

    public function editGrade($id)
    {
        $grade = Grade::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $grade) {
            return redirect()->back()->with('error', 'Grade not found.');
        }
        return view('admin.grade.edit_grade', ['grade' => $grade]);
    }

    public function gradeUpdate(Request $request, $id)
    {
        $data  = $request->all();
        $grade = Grade::where('id', $id)
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $grade) {
            return redirect()->back()->with('error', 'Grade not found.');
        }

        $grade->update([
            'name'        => $data['grade'],
            'grade_point' => $data['grade_point'],
            'mark_from'   => $data['mark_from'],
            'mark_upto'   => $data['mark_upto'],
        ]);

        return redirect()->back()->with('message', 'You have successfully update grade.');
    }

    public function gradeDelete($id)
    {
        $grade = Grade::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if (! $grade) {
            return redirect()->back()->with('error', 'Grade not found.');
        }
        $grade->delete();
        return redirect()->back()->with('message', 'You have successfully delete grade.');
    }

    public function promotionFilter()
    {
        $sessions = Session::where('school_id', auth()->user()->school_id)->get();
        $classes  = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.promotion.promotion', ['sessions' => $sessions, 'classes' => $classes]);
    }

    public function promotionList(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['session_id_from' => 'present', 'class_id_from' => 'present', 'section_id_from' => 'present', 'class_id_to' => 'present', 'section_id_to' => 'present', 'session_id_to' => 'present']);
        $data           = $request->all();
        $promotion_list = Enrollment::where(['session_id' => $data['session_id_from'], 'class_id' => $data['class_id_from'], 'section_id' => $data['section_id_from']])->where('school_id', auth()->user()->school_id)->get();
        echo view('admin.promotion.promotion_list', ['promotion_list' => $promotion_list, 'class_id_to' => $data['class_id_to'], 'section_id_to' => $data['section_id_to'], 'session_id_to' => $data['session_id_to'], 'class_id_from' => $data['class_id_from'], 'section_id_from' => $data['section_id_from']]);
    }

    public function promote($promotion_data = '')
    {
        $promotion_data = explode('-', $promotion_data);
        $enroll_id      = $promotion_data[0];
        $class_id       = $promotion_data[1];
        $section_id     = $promotion_data[2];
        $session_id     = $promotion_data[3];

        // Security Phase 2E: the enrollment and every destination (class,
        // section of that class, session) must belong to the caller's school.
        $schoolId = auth()->user()->school_id;
        $enroll = Enrollment::where('id', $enroll_id)->where('school_id', $schoolId)->firstOrFail();
        abort_unless(
            Classes::where('id', $class_id)->where('school_id', $schoolId)->exists()
            && Section::where('id', $section_id)->where('class_id', $class_id)->exists()
            && Session::where('id', $session_id)->where('school_id', $schoolId)->exists(),
            404
        );

        $placementBefore = StatusChangeAudit::placement($enroll);
        Enrollment::where('id', $enroll->id)->update([
            'class_id'   => $class_id,
            'section_id' => $section_id,
            'session_id' => $session_id,
        ]);
        StatusChangeAudit::enrollment($enroll->fresh(), $placementBefore, 'Promotion');

        return true;
    }

    public function classWiseSections($id)
    {
        $sections = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->exists() ? Section::get()->where('class_id', $id) : collect();
        $options  = '<option value="">' . 'Select a section' . '</option>';
        foreach ($sections as $section):
            $options .= '<option value="' . $section->id . '">' . $section->name . '</option>';
        endforeach;
        echo $options;
    }

    /**
     * Show the subject list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function subjectList(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $educationLevel = academic_education_level($schoolId);
        $isHigherEducation = in_array($educationLevel, ['tertiary', 'vocational'], true);
        $isMixed = $educationLevel === 'mixed';
        $classes = Classes::where('school_id', $schoolId)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $schoolId)->orderBy('name')->get();
        $search = trim((string) $request->query('search', ''));
        $classId = $request->query('class_id', '');
        $programmeId = $request->query('programme_id', '');

        $query = Subject::query()->where('school_id', $schoolId)
            ->with([
                'programme' => fn ($q) => $q->where('school_id', $schoolId),
                'classes' => fn ($q) => $q->where('school_id', $schoolId),
            ]);

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhere('code', 'like', '%'.$search.'%'));
        }
        if ($isHigherEducation && $request->filled('programme_id')) {
            $query->where('programme_id', $programmeId);
        } elseif (! $isHigherEducation && ! $isMixed && $request->filled('class_id')) {
            $query->where('class_id', $classId);
        } elseif ($isMixed) {
            if ($request->filled('class_id')) {
                $query->where('class_id', $classId);
            }
            if ($request->filled('programme_id')) {
                $query->where('programme_id', $programmeId);
            }
        }

        $subjects = $query->orderBy('name')->paginate(15)->appends($request->query());

        return view('admin.subject.subject_list', compact(
            'subjects', 'classes', 'programmes', 'classId', 'programmeId', 'search',
            'isHigherEducation', 'isMixed'
        ));
    }

    public function createSubject()
    {
        $schoolId = (int) auth()->user()->school_id;
        $educationLevel = academic_education_level($schoolId);
        $isHigherEducation = in_array($educationLevel, ['tertiary', 'vocational'], true);
        $isMixed = $educationLevel === 'mixed';
        $classes = Classes::where('school_id', $schoolId)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $schoolId)->where('is_active', 1)->orderBy('name')->get();

        return view('admin.subject.add_subject', compact('classes', 'programmes', 'isHigherEducation', 'isMixed'));
    }

    public function subjectCreate(Request $request)
    {
        $schoolId = (int) $request->user()->school_id;
        $educationLevel = academic_education_level($schoolId);
        $isHigherEducation = in_array($educationLevel, ['tertiary', 'vocational'], true);
        $isMixed = $educationLevel === 'mixed';
        $data = $request->validate($this->subjectCatalogueRules($schoolId, $isHigherEducation, $isMixed));
        $this->validateSubjectCatalogueAssociation($data, $isHigherEducation, $isMixed);

        $subject_data = [
            'name'      => $data['name'],
            'school_id' => $schoolId,
            'code'      => trim($data['code']),
        ];

        if ($isHigherEducation || ! empty($data['programme_id'])) {
            $subject_data['programme_id'] = $data['programme_id'];
            $subject_data['class_id']     = null;
            $subject_data['session_id']   = null;
        } else {
            $active_session = get_school_settings($schoolId)->value('running_session');
            if (empty($active_session)) {
                $active_session = Session::where('school_id', $schoolId)->value('id');
            }

            if (empty($active_session)) {
                return redirect()->back()->with('error', get_phrase('Please create or set an active academic session before adding subjects.'));
            }

            $subject_data['class_id']   = $data['class_id'];
            $subject_data['session_id'] = $active_session;
        }

        try {
            Subject::create($subject_data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return redirect()->back()->withErrors(['code' => get_phrase('A Course Unit or Subject with this code already exists in this institution.')])->withInput();
            }
            report($e);

            return redirect()->back()->with('error', get_phrase('The catalogue record could not be saved. Check the Programme or Class selection and try again.'))->withInput();
        }

        return redirect()->route('admin.subject_list')->with('message', get_phrase('Course Unit or Subject created successfully. Add it separately to the appropriate Programme Study Plan stage if needed.'));
    }

    public function editSubject($id)
    {
        $schoolId = (int) auth()->user()->school_id;
        $educationLevel = academic_education_level($schoolId);
        $isHigherEducation = in_array($educationLevel, ['tertiary', 'vocational'], true);
        $isMixed = $educationLevel === 'mixed';
        $subject = Subject::where('school_id', $schoolId)->findOrFail($id);
        $classes = Classes::where('school_id', $schoolId)->orderBy('name')->get();
        $programmes = Programme::where('school_id', $schoolId)->where('is_active', 1)->orderBy('name')->get();

        return view('admin.subject.edit_subject', compact('subject', 'classes', 'programmes', 'isHigherEducation', 'isMixed'));
    }

    public function subjectUpdate(Request $request, $id)
    {
        $schoolId = (int) $request->user()->school_id;
        $educationLevel = academic_education_level($schoolId);
        $isHigherEducation = in_array($educationLevel, ['tertiary', 'vocational'], true);
        $isMixed = $educationLevel === 'mixed';
        $subject = Subject::where('school_id', $schoolId)->findOrFail($id);
        $data = $request->validate($this->subjectCatalogueRules($schoolId, $isHigherEducation, $isMixed, $subject->id));
        $this->validateSubjectCatalogueAssociation($data, $isHigherEducation, $isMixed);

        $subject_data = [
            'name'      => $data['name'],
            'school_id' => $schoolId,
            'code'      => trim($data['code']),
        ];

        if ($isHigherEducation || ! empty($data['programme_id'])) {
            $subject_data['programme_id'] = $data['programme_id'];
            $subject_data['class_id']     = null;
            $subject_data['session_id']   = null;
        } else {
            $subject_data['class_id']     = $data['class_id'];
            $subject_data['programme_id'] = null;
        }

        try {
            $subject->update($subject_data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return redirect()->back()->withErrors(['code' => get_phrase('A Course Unit or Subject with this code already exists in this institution.')])->withInput();
            }
            report($e);

            return redirect()->back()->with('error', get_phrase('The catalogue record could not be saved. Check the Programme or Class selection and try again.'))->withInput();
        }

        return redirect()->route('admin.subject_list')->with('message', get_phrase('Course Unit or Subject updated successfully.'));
    }

    private function subjectCatalogueRules(int $schoolId, bool $isHigherEducation, bool $isMixed, ?int $ignoreId = null): array
    {
        $codeRule = \Illuminate\Validation\Rule::unique('subjects', 'code')->where('school_id', $schoolId);
        if ($ignoreId !== null) {
            $codeRule->ignore($ignoreId);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $codeRule],
            'class_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'programme_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('programmes', 'id')->where('school_id', $schoolId)],
        ];
    }

    private function validateSubjectCatalogueAssociation(array $data, bool $isHigherEducation, bool $isMixed): void
    {
        $hasClass = ! empty($data['class_id']);
        $hasProgramme = ! empty($data['programme_id']);

        if ($isHigherEducation && (! $hasProgramme || $hasClass)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'programme_id' => get_phrase('Select a Programme for this Course Unit; Study Plan placement is managed separately.'),
            ]);
        }

        if (! $isHigherEducation && ! $isMixed && (! $hasClass || $hasProgramme)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'class_id' => get_phrase('Select a Class for this Subject.'),
            ]);
        }

        if ($isMixed && ($hasClass === $hasProgramme)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'class_id' => get_phrase('Select exactly one catalogue association: a Class or a Programme.'),
            ]);
        }
    }

    public function subjectDelete($id)
    {
        $subject = Subject::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $subject->delete();
        $subjects = Subject::get()->where('school_id', auth()->user()->school_id);
        return redirect()->back()->with('message', 'You have successfully delete subject.');
    }

    /**
     * Show the department list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function departmentList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $departments = Department::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id);
            })->paginate(10);
        } else {
            $departments = Department::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.department.department_list', compact('departments', 'search'));
    }

    public function createDepartment()
    {
        return view('admin.department.add_department');
    }

    public function departmentCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_department_check = Department::get()->where('name', $data['name'])->where('school_id', auth()->user()->school_id);

        if (count($duplicate_department_check) == 0) {
            Department::create([
                'name'      => $data['name'],
                'school_id' => auth()->user()->school_id,
            ]);

            return redirect()->back()->with('message', 'You have successfully create a new department.');
        } else {
            return back()
                ->with('error', 'Sorry this department already exists');
        }
    }

    public function editDepartment($id)
    {
        $department = Department::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.department.edit_department', ['department' => $department]);
    }

    public function departmentUpdate(Request $request, $id)
    {
        $data = $request->all();

        $duplicate_department_check = Department::get()->where('name', $data['name'])->where('school_id', auth()->user()->school_id);

        if (count($duplicate_department_check) == 0) {
            $department = Department::where('school_id', auth()->user()->school_id)->findOrFail($id);

            if ($department) {
                $department->update([
                    'name'      => $data['name'],
                    'school_id' => auth()->user()->school_id,
                ]);
            }

            return redirect()->back()->with('message', 'You have successfully update subject.');
        } else {
            return back()
                ->with('error', 'Sorry this department already exists');
        }
    }

    public function departmentDelete($id)
    {
        $department = Department::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $department->delete();
        return redirect()->back()->with('message', 'You have successfully delete department.');
    }

    // ── Designations (job titles, used by the Staff module) ────────────────

    public function designationList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {
            $designations = Designation::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id);
            })->paginate(10);
        } else {
            $designations = Designation::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        // How many staff records hold each designation, so the list can explain
        // why a referenced designation may not be deleted.
        $school = (int) auth()->user()->school_id;
        $usage = User::where('school_id', $school)->whereNotNull('designation_id')
            ->select('designation_id')->selectRaw('COUNT(*) as staff_count')
            ->groupBy('designation_id')->pluck('staff_count', 'designation_id');

        return view('admin.designation.designation_list', compact('designations', 'search', 'usage'));
    }

    public function createDesignation()
    {
        return view('admin.designation.add_designation');
    }

    public function designationCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_designation_check = Designation::where('name', $data['name'])->where('school_id', auth()->user()->school_id)->exists();

        if (! $duplicate_designation_check) {
            Designation::create([
                'name'      => $data['name'],
                'school_id' => auth()->user()->school_id,
            ]);

            return redirect()->back()->with('message', 'You have successfully created a new designation.');
        }

        return back()->with('error', 'Sorry this designation already exists');
    }

    public function editDesignation($id)
    {
        $designation = Designation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.designation.edit_designation', ['designation' => $designation]);
    }

    public function designationUpdate(Request $request, $id)
    {
        $data = $request->all();

        $duplicate_designation_check = Designation::where('name', $data['name'])->where('school_id', auth()->user()->school_id)->where('id', '!=', $id)->exists();

        if (! $duplicate_designation_check) {
            $designation = Designation::where('school_id', auth()->user()->school_id)->findOrFail($id);
            $designation->update(['name' => $data['name']]);

            return redirect()->back()->with('message', 'You have successfully updated the designation.');
        }

        return back()->with('error', 'Sorry this designation already exists');
    }

    /**
     * A designation is master data referenced by staff records, so it is never
     * hard-deleted while it is in use. Deleting an unreferenced one stays
     * available; a referenced one is refused with the reason and the count, so
     * the administrator moves those staff members to another designation first.
     */
    public function designationDelete($id)
    {
        $designation = Designation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $inUse = (int) User::where('designation_id', $designation->id)->count();

        if ($inUse > 0) {
            return back()->with('error', "This designation is used by {$inUse} staff member(s) and cannot be deleted. Reassign them to another designation first, then delete it.");
        }

        $designation->delete();

        return redirect()->back()->with('message', 'You have successfully deleted the designation.');
    }

    /**
     * Show the class room list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function classRoomList()
    {
        $class_rooms = ClassRoom::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.class_room.class_room_list', compact('class_rooms'));
    }

    public function createClassRoom()
    {
        return view('admin.class_room.add_class_room');
    }

    public function classRoomCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_class_room_check = ClassRoom::get()->where('name', $data['name']);

        if (count($duplicate_class_room_check) == 0) {
            ClassRoom::create([
                'name'      => $data['name'],
                'school_id' => auth()->user()->school_id,
            ]);

            return redirect()->back()->with('message', 'You have successfully create a new class room.');
        } else {
            return back()
                ->with('error', 'Sorry this class room already exists');
        }
    }

    public function editClassRoom($id)
    {
        $class_room = ClassRoom::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.class_room.edit_class_room', ['class_room' => $class_room]);
    }

    public function classRoomUpdate(Request $request, $id)
    {
        $data = $request->all();

        $duplicate_class_room_check = ClassRoom::get()->where('name', $data['name']);

        if (count($duplicate_class_room_check) == 0) {
            ClassRoom::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
                'name'      => $data['name'],
                'school_id' => auth()->user()->school_id,
            ]);

            return redirect()->back()->with('message', 'You have successfully update class room.');
        } else {
            return back()
                ->with('error', 'Sorry this class room already exists');
        }
    }

    public function classRoomDelete($id)
    {
        $department = ClassRoom::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $department->delete();
        return redirect()->back()->with('message', 'You have successfully delete class room.');
    }

    /**
     * Show the class list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function classList(Request $request)
    {
        $search = $request['search'] ?? "";

        if ($search != "") {

            $class_lists = Classes::where(function ($query) use ($search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('school_id', auth()->user()->school_id);
            })->paginate(10);
        } else {
            $class_lists = Classes::where('school_id', auth()->user()->school_id)->paginate(10);
        }

        return view('admin.class.class_list', compact('class_lists', 'search'));
    }

    public function createClass()
    {
        return view('admin.class.add_class');
    }

    public function classCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_class_check = Classes::get()->where('name', $data['name'])->where('school_id', auth()->user()->school_id);

        if (count($duplicate_class_check) == 0) {
            $id = Classes::create([
                'name'      => $data['name'],
                'school_id' => auth()->user()->school_id,
            ])->id;

            Section::create([
                'name'     => 'A',
                'class_id' => $id,
            ]);

            return redirect()->back()->with('message', 'You have successfully create a new class.');
        } else {
            return back()
                ->with('error', 'Sorry this class already exists');
        }
    }

    public function editClass($id)
    {
        $class = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
        return view('admin.class.edit_class', ['class' => $class]);
    }

    public function classUpdate(Request $request, $id)
    {
        $data = $request->all();

        $class = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();

        $duplicate_class_check = Classes::where('id', '!=', $id)->where('name', $data['name'])->where('school_id', auth()->user()->school_id);

        if ($duplicate_class_check->count() == 0) {
            $class->update([
                'name' => $data['name'],
            ]);

            return redirect()->back()->with('message', 'You have successfully update class.');
        } else {
            return back()
                ->with('error', 'Sorry this class already exists');
        }
    }

    public function editSection($id)
    {
        Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
        $sections = Section::get()->where('class_id', $id);
        return view('admin.class.sections', ['class_id' => $id, 'sections' => $sections]);
    }

    public function sectionUpdate(Request $request, $id)
    {
        Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();

        $data = $request->all();

        $section_id   = $data['section_id'];
        $section_name = $data['name'];

        foreach ($section_id as $key => $value) {
            if ($value == 0) {
                Section::create([
                    'name'     => $section_name[$key],
                    'class_id' => $id,
                ]);
            }
            if ($value != 0 && is_numeric($value)) {
                Section::where(['id' => $value, 'class_id' => $id])->update([
                    'name' => $section_name[$key],
                ]);
            }

            $section_value = null;
            if (strpos($value, 'delete') == true) {
                $section_value = str_replace('delete', '', $value);

                $section = Section::find(['id' => $section_value, 'class_id' => $id]);
                $section->map->delete();
            }
        }

        return redirect()->back()->with('message', 'You have successfully update sections.');
    }

    public function classDelete($id)
    {
        $class = Classes::where('id', $id)->where('school_id', auth()->user()->school_id)->firstOrFail();
        $class->delete();
        $sections = Section::get()->where('class_id', $id);
        $sections->map->delete();
        $subjects = Subject::get()->where('class_id', $id);
        $subjects->map->delete();
        return redirect()->back()->with('message', 'You have successfully delete class.');
    }

    /**
     * Show the student fee manager.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function studentFeeManagerList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        $programmes = Programme::where('school_id', auth()->user()->school_id)->where('is_active', 1)->orderBy('name')->get();

        if (count($request->all()) > 0) {
            $data              = $request->all();
            $date              = explode('-', $data['eDateRange']);
            $date_from         = strtotime($date[0] . ' 00:00:00');
            $date_to           = strtotime($date[1] . ' 23:59:59');
            $selected_class    = $data['class'];
            $selected_status   = $data['status'];
            $selected_programme = $data['programme'] ?? 'all';

            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)
                ->where('school_id', auth()->user()->school_id)
                ->where('session_id', $active_session)
                ->when($selected_class != "all", fn($q) => $q->where('class_id', $selected_class))
                ->when($selected_status != "all", fn($q) => $q->where('status', $selected_status))
                ->when($selected_programme != "all", fn($q) => $q->where('programme_id', $selected_programme))
                ->get();

            $classes = Classes::where('school_id', auth()->user()->school_id)->get();

            return view('admin.student_fee_manager.student_fee_manager', ['classes' => $classes, 'programmes' => $programmes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status, 'selected_programme' => $selected_programme]);
        } else {
            $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
            $date_from       = strtotime(date('d-m-Y', strtotime('first day of this month')) . ' 00:00:00');
            $date_to         = strtotime(date('d-m-Y', strtotime('last day of this month')) . ' 23:59:59');
            $selected_class  = "";
            $selected_status = "";
            $selected_programme = "";
            $invoices        = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            return view('admin.student_fee_manager.student_fee_manager', ['classes' => $classes, 'programmes' => $programmes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status, 'selected_programme' => $selected_programme]);
        }
    }

    public function feeManagerExport($date_from = "", $date_to = "", $selected_class = "", $selected_status = "")
    {

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($selected_class != "all" && $selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_class != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        }

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        $file = "student_fee-" . date('d-m-Y', $date_from) . '-' . date('d-m-Y', $date_to) . '-' . $selected_class . '-' . $selected_status . ".csv";

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

    public function feeManagerExportPdfPrint($date_from = "", $date_to = "", $selected_class = "", $selected_status = "")
    {

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($selected_class != "all" && $selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_class != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else if ($selected_status != "all") {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        } else {
            $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
        }

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        return view('admin.student_fee_manager.pdf_print', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);
    }

    public function createFeeManager($value = "")
    {

        $classes = Classes::where('school_id', auth()->user()->school_id)->get();

        if ($value == 'single') {
            return view('admin.student_fee_manager.single', ['classes' => $classes]);
        } else if ($value == 'mass') {
            return view('admin.student_fee_manager.mass', ['classes' => $classes]);
        }
    }

    public function feeManagerCreate(Request $request, $value = "")
    {
        $data = $request->all();

        if ($value == 'single') {

            if ($data['paid_amount'] > $data['amount']) {

                return back()->with('error', 'Paid amount can not get bigger than total amount');
            }
            if ($data['status'] == 'paid' && $data['amount'] != $data['paid_amount']) {

                return back()->with('error', 'Paid amount is not equal to total amount');
            }

            if (!$this->isSchoolStudent($data['student_id'] ?? null)) {
                return back()->with('error', 'Student not found.');
            }
            $parent_id         = User::find($data['student_id'])->toArray();
            $parent_id         = $parent_id['parent_id'];
            $data['parent_id'] = $parent_id;

            $active_session       = get_school_settings(auth()->user()->school_id)->value('running_session');
            $data['total_amount'] = $data['amount'] - ($data['discounted_price'] ?? 0);
            $data['timestamp']    = strtotime(date('d-M-Y'));
            $data['school_id']    = auth()->user()->school_id;
            $data['session_id']   = $active_session;

            StudentFeeManager::create($data);

            return redirect()->back()->with('message', 'You have successfully create a new invoice.');
        } else if ($value == 'mass') {

            if ($data['paid_amount'] > $data['amount']) {

                return back()->with('error', 'Paid amount can not get bigger than total amount');
            }
            if ($data['status'] == 'paid' && $data['amount'] != $data['paid_amount']) {

                return back()->with('error', 'Paid amount is not equal to total amount');
            }

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            $data['timestamp']    = strtotime(date('d-M-Y'));
            $data['school_id']    = auth()->user()->school_id;
            $data['session_id']   = $active_session;
            $data['total_amount'] = $data['amount'] - ($data['discounted_price'] ?? 0);
            $enrolments           = Enrollment::where('class_id', $data['class_id'])
                ->where('section_id', $data['section_id'])
                ->where('school_id', auth()->user()->school_id)
                ->get();

            foreach ($enrolments as $enrolment) {

                $data['student_id'] = $enrolment['user_id'];
                $parent_id          = User::find($data['student_id'])->toArray();
                $parent_id          = $parent_id['parent_id'];
                $data['parent_id']  = $parent_id;
                StudentFeeManager::create($data);
            }

            if (sizeof($enrolments) > 0) {

                return redirect()->back()->with('message', 'Invoice added successfully');
            } else {

                return back()->with('error', 'No student found');
            }
        }
    }

    public function classWiseStudents($id = '')
    {
        $enrollments = Enrollment::where('class_id', $id)->where('school_id', auth()->user()->school_id)->get();
        $options     = '<option value="">' . 'Select a student' . '</option>';
        foreach ($enrollments as $enrollment):
            $student = User::find($enrollment->user_id);
            $options .= '<option value="' . $student->id . '">' . $student->name . '</option>';
        endforeach;
        echo $options;
    }

    public function classWiseStudentsInvoice($id = '')
    {
        $enrollments = Enrollment::where('section_id', $id)->where('school_id', auth()->user()->school_id)->get();
        $options     = '<option value="">' . 'Select a student' . '</option>';
        foreach ($enrollments as $enrollment):
            $student = User::find($enrollment->user_id);
            $options .= '<option value="' . $student->id . '">' . $student->name . '</option>';
        endforeach;
        echo $options;
    }

    public function editFeeManager($id = '')
    {
        $this->findSchoolFeeOrFail($id);
        $invoice_details = StudentFeeManager::find($id);
        $enrollments     = Enrollment::get()->where('class_id', $invoice_details->class_id);
        $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
        return view('admin.student_fee_manager.edit', ['invoice_details' => $invoice_details, 'classes' => $classes, 'enrollments' => $enrollments]);
    }

    public function feeManagerUpdate(Request $request, $id = '')
    {
        $data = $request->all();

        /*GET THE PREVIOUS INVOICE DETAILS FOR GETTING THE PAID AMOUNT*/
        $this->findSchoolFeeOrFail($id);
        if (!$this->isSchoolStudent($data['student_id'] ?? null)) {
            return redirect()->back()->with('error', 'Student not found.');
        }
        $previous_invoice_data = StudentFeeManager::find($id);

        if ($data['paid_amount'] > $data['total_amount']) {

            return redirect()->back()->with('error', 'Paid amount can not get bigger than total amount');
        }
        if ($data['status'] == 'paid' && $data['total_amount'] != $data['paid_amount']) {
            return redirect()->back()->with('error', 'Paid amount is not equal to total amount');
        }

        /*KEEPING TRACK OF PAYMENT DATE*/
        if ($data['paid_amount'] != $previous_invoice_data['paid_amount'] && $data['paid_amount'] == $previous_invoice_data['total_amount']) {
            $timestamp = strtotime(date('d-M-Y'));
        } else {
            $timestamp = $previous_invoice_data['timestamp'];
        }

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        StudentFeeManager::where('id', $id)->update([
            'title'          => $data['title'],
            'total_amount'   => $data['total_amount'],
            'class_id'       => $data['class_id'],
            'student_id'     => $data['student_id'],
            'paid_amount'    => $data['paid_amount'],
            'payment_method' => $data['payment_method'],
            'timestamp'      => $timestamp,
            'status'         => $data['status'],
            'school_id'      => auth()->user()->school_id,
            'session_id'     => $active_session,
        ]);

        return redirect()->back()->with('message', 'You have successfully update invoice.');
    }

    public function studentFeeDelete($id)
    {
        $this->findSchoolFeeOrFail($id);
        $invoice = StudentFeeManager::find($id);
        $invoice->delete();
        return redirect()->back()->with('message', 'You have successfully delete invoice.');
    }

    /**
     * Show the expense expense list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function expenseList(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (count($request->all()) > 0) {
            $data = $request->all();

            $date                = explode('-', $data['eDateRange']);
            $date_from           = strtotime($date[0] . ' 00:00:00');
            $date_to             = strtotime($date[1] . ' 23:59:59');
            $expense_category_id = $data['expense_category_id'];

            $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
            $selected_category  = ExpenseCategory::where('school_id', auth()->user()->school_id)->find($expense_category_id);
            if ($expense_category_id != 'all') {
                $expenses = Expense::where('expense_category_id', $expense_category_id)
                    ->where('date', '>=', $date_from)
                    ->where('date', '<=', $date_to)
                    ->where('school_id', auth()->user()->school_id)
                    ->where('session_id', $active_session)
                    ->get();
            } else {
                $expenses = Expense::where('date', '>=', $date_from)
                    ->where('date', '<=', $date_to)
                    ->where('school_id', auth()->user()->school_id)
                    ->where('session_id', $active_session)
                    ->get();
            }

            return view('admin.expenses.expense_manager', ['expense_categories' => $expense_categories, 'expenses' => $expenses, 'selected_category' => $selected_category, 'date_from' => $date_from, 'date_to' => $date_to]);
        } else {
            $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
            $selected_category  = "";
            $date_from          = strtotime(date('d-m-Y', strtotime('first day of this month')) . ' 00:00:00');
            $date_to            = strtotime(date('d-m-Y', strtotime('last day of this month')) . ' 23:59:59');
            $expenses           = Expense::where('date', '>=', $date_from)
                ->where('date', '<=', $date_to)
                ->where('school_id', auth()->user()->school_id)
                ->where('session_id', $active_session)
                ->get();
            return view('admin.expenses.expense_manager', ['expense_categories' => $expense_categories, 'expenses' => $expenses, 'selected_category' => $selected_category, 'date_from' => $date_from, 'date_to' => $date_to]);
        }
    }

    public function createExpense()
    {
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
        return view('admin.expenses.create', ['expense_categories' => $expense_categories]);
    }

    public function expenseCreate(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        Expense::create([
            'expense_category_id' => $data['expense_category_id'],
            'date'                => strtotime($data['date']),
            'amount'              => $data['amount'],
            'school_id'           => auth()->user()->school_id,
            'session_id'          => $active_session,
        ]);

        return redirect()->back()->with('message', 'You have successfully create a new expense.');
    }

    public function editExpense($id)
    {
        $expense_details    = Expense::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->get();
        return view('admin.expenses.edit', ['expense_categories' => $expense_categories, 'expense_details' => $expense_details]);
    }

    public function expenseUpdate(Request $request, $id)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        Expense::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
            'expense_category_id' => $data['expense_category_id'],
            'date'                => strtotime($data['date']),
            'amount'              => $data['amount'],
            'school_id'           => auth()->user()->school_id,
            'session_id'          => $active_session,
        ]);

        return redirect()->back()->with('message', 'You have successfully update expense.');
    }

    public function expenseDelete($id)
    {
        $expense = Expense::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense->delete();
        return redirect()->back()->with('message', 'You have successfully delete expense.');
    }

    /**
     * Show the expense category list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function expenseCategoryList()
    {
        $expense_categories = ExpenseCategory::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.expense_category.expense_category_list', compact('expense_categories'));
    }

    public function createExpenseCategory()
    {
        return view('admin.expense_category.create');
    }

    public function expenseCategoryCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_category_check = ExpenseCategory::get()->where('name', $data['name']);

        if (count($duplicate_category_check) == 0) {

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            ExpenseCategory::create([
                'name'       => $data['name'],
                'school_id'  => auth()->user()->school_id,
                'session_id' => $active_session,
            ]);

            return redirect()->back()->with('message', 'You have successfully create a new expense category.');
        } else {
            return back()
                ->with('error', 'Sorry this expense category already exists');
        }
    }

    public function editExpenseCategory($id)
    {
        $expense_category = ExpenseCategory::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.expense_category.edit', ['expense_category' => $expense_category]);
    }

    public function expenseCategoryUpdate(Request $request, $id)
    {
        $data = $request->all();

        $duplicate_category_check = ExpenseCategory::get()->where('name', $data['name']);

        if (count($duplicate_category_check) == 0) {

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            ExpenseCategory::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
                'name'       => $data['name'],
                'school_id'  => auth()->user()->school_id,
                'session_id' => $active_session,
            ]);

            return redirect()->back()->with('message', 'You have successfully update expense category.');
        } else {
            return back()
                ->with('error', 'Sorry this expense category already exists');
        }
    }

    public function expenseCategoryDelete($id)
    {
        $expense_category = ExpenseCategory::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $expense_category->delete();
        return redirect()->back()->with('message', 'You have successfully delete expense category.');
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

        return view('admin.book.list', compact('books', 'search'));
    }

    public function createBook()
    {
        return view('admin.book.create');
    }

    public function bookCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_book_check = Book::get()->where('name', $data['name']);

        if (count($duplicate_book_check) == 0) {

            $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

            $data['school_id']  = auth()->user()->school_id;
            $data['session_id'] = $active_session;
            $data['timestamp']  = strtotime(date('d-M-Y'));

            Book::create($data);

            return redirect()->back()->with('message', 'You have successfully create a book.');
        } else {
            return back()
                ->with('error', 'Sorry this book already exists');
        }
    }

    public function editBook($id = "")
    {
        $book_details = Book::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.book.edit', ['book_details' => $book_details]);
    }

    public function bookUpdate(Request $request, $id = '')
    {
        $data = $request->all();

        $duplicate_book_check = Book::get()->where('name', $data['name']);

        if (count($duplicate_book_check) == 0) {
            $book = Book::where('school_id', auth()->user()->school_id)->findOrFail($id);

            if ($book) {
                $book->update([
                    'name'      => $data['name'],
                    'author'    => $data['author'],
                    'copies'    => $data['copies'],
                    'timestamp' => strtotime(date('d-M-Y')),
                ]);
            }

            return redirect()->back()->with('message', 'You have successfully update book.');
        } else {
            return back()
                ->with('error', 'Sorry this book already exists');
        }
    }

    public function bookDelete($id)
    {
        $book = Book::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $book->delete();
        return redirect()->back()->with('message', 'You have successfully delete book.');
    }

    /**
     * Show the book list.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
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

            return view('admin.book_issue.book_issue', ['book_issues' => $book_issues, 'date_from' => $date_from, 'date_to' => $date_to]);
        } else {
            $date_from   = strtotime(date('d-m-Y', strtotime('first day of this month')) . ' 00:00:00');
            $date_to     = strtotime(date('d-m-Y', strtotime('last day of this month')) . ' 23:59:59');
            $book_issues = BookIssue::where('issue_date', '>=', $date_from)
                ->where('issue_date', '<=', $date_to)
                ->where('school_id', auth()->user()->school_id)
                ->where('session_id', $active_session)
                ->get();

            return view('admin.book_issue.book_issue', ['book_issues' => $book_issues, 'date_from' => $date_from, 'date_to' => $date_to]);
        }
    }

    public function createBookIssue()
    {
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        $books   = Book::get()->where('school_id', auth()->user()->school_id);
        return view('admin.book_issue.create', ['classes' => $classes, 'books' => $books]);
    }

    public function bookIssueCreate(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['status']     = 0;
        $data['issue_date'] = strtotime($data['issue_date']);
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;
        $data['timestamp']  = strtotime(date('d-M-Y'));

        BookIssue::create($data);

        return redirect()->back()->with('message', 'You have successfully issued a book.');
    }

    public function editBookIssue($id = "")
    {
        $book_issue_details = BookIssue::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes            = Classes::get()->where('school_id', auth()->user()->school_id);
        $books              = Book::get()->where('school_id', auth()->user()->school_id);
        return view('admin.book_issue.edit', ['book_issue_details' => $book_issue_details, 'classes' => $classes, 'books' => $books]);
    }

    public function bookIssueUpdate(Request $request, $id = "")
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['issue_date'] = strtotime($data['issue_date']);
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;
        $data['timestamp']  = strtotime(date('d-M-Y'));

        unset($data['_token']);

        $book_issue = BookIssue::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($book_issue) {
            $book_issue->update($data);
        }

        return redirect()->back()->with('message', 'Updated successfully.');
    }

    public function bookIssueReturn($id)
    {
        $book_issue = BookIssue::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($book_issue) {
            $book_issue->update([
                'status'    => 1,
                'timestamp' => strtotime(date('d-M-Y')),
            ]);
        }

        return redirect()->back()->with('message', 'Return successfully.');
    }

    public function bookIssueDelete($id)
    {
        $book_issue = BookIssue::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $book_issue->delete();
        return redirect()->back()->with('message', 'You have successfully delete a issued book.');
    }

    /**
     * Show the noticeboard.
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

        return view('admin.noticeboard.noticeboard', ['events' => $events]);
    }

    public function createNoticeboard()
    {
        return view('admin.noticeboard.create');
    }

    public function noticeboardCreate(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['status']     = 1;
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;

        if (! empty($data['image'])) {

            $imageName = SafeUpload::store($data['image'], public_path('assets/uploads/noticeboard/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');

            $data['image'] = $imageName;
        }

        Noticeboard::create($data);

        return redirect()->back()->with('message', 'You have successfully create a notice.');
    }

    public function editNoticeboard($id = "")
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.noticeboard.edit', ['notice' => $notice]);
    }

    public function noticeboardUpdate(Request $request, $id = "")
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['status']     = 1;
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;

        if (! empty($data['image'])) {

            $imageName = SafeUpload::store($data['image'], public_path('assets/uploads/noticeboard/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');

            $data['image'] = $imageName;
        }

        unset($data['_token']);

        Noticeboard::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);

        return redirect()->back()->with('message', 'Updated successfully.');
    }

    public function noticeboardDelete($id = '')
    {
        $notice = Noticeboard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $notice->delete();
        return redirect()->back()->with('message', 'You have successfully delete a notice.');
    }

    /**
     * Show the subscription.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function subscription(Request $request)
    {

        $if_pending_payment = PaymentHistory::where('user_id', auth()->user()->id)->where('status', 'pending')->get()->count();

        $date_from = strtotime('first day of january this year');
        $date_to   = strtotime('last day of december this year');

        if (count($request->all()) > 0 && !empty($request->eDateRange)) {
            $data = $request->all();
            $date = explode('-', $data['eDateRange']);
            if (count($date) === 2) {
                $date_from = strtotime(trim($date[0]) . ' 00:00:00');
                $date_to   = strtotime(trim($date[1]) . ' 23:59:59');
            }
        }

        $subscriptionQuery = Subscription::where('school_id', auth()->user()->school_id);
        if (Schema::hasColumn('subscriptions', 'date_added')) {
            $subscriptionQuery->where('date_added', '>=', $date_from)
                ->where('date_added', '<=', $date_to);
        }
        $subscriptions = $subscriptionQuery->get();

        $subscription_details = Subscription::where('school_id', auth()->user()->school_id);
        if (Schema::hasColumn('subscriptions', 'active')) {
            $subscription_details->where('active', '1');
        } elseif (Schema::hasColumn('subscriptions', 'status')) {
            $subscription_details->where('status', '1');
        } else {
            $subscription_details->whereRaw('1 = 0');
        }

        if ($subscription_details->get()->count() > 0) {
            $package_details = Package::find($subscription_details->first()->package_id);
        } else {
            $subscription_details = Subscription::where('school_id', auth()->user()->school_id);
            if (Schema::hasColumn('subscriptions', 'status')) {
                $subscription_details->where('status', '0');
            } else {
                $subscription_details->whereRaw('1 = 0');
            }

            if ($subscription_details->get()->count() > 0) {
                $package_details = Package::find($subscription_details->first()->package_id);
            } else {
                $package_details = '';
            }
        }
        return view('admin.subscription.subscription', ['if_pending_payment' => $if_pending_payment, 'subscriptions' => $subscriptions, 'subscription_details' => $subscription_details, 'package_details' => $package_details, 'date_from' => $date_from, 'date_to' => $date_to]);
    }

    public function subscriptionPurchase()
    {
        $packages = Package::where('status', 1)->get();
        return view('admin.subscription.purchase', ['packages' => $packages]);
    }

    public function upgreadeSubscription()
    {
        $packages = Package::where('status', 1)->get();
        return view('admin.subscription.upgrade_subscription', ['packages' => $packages]);
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

        return view('admin.events.events', compact('events', 'search'));
    }

    public function createEvent()
    {
        return view('admin.events.create_event');
    }

    public function eventCreate(Request $request)
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['timestamp']  = strtotime($data['timestamp']);
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;
        $data['created_by'] = auth()->user()->id;

        FrontendEvent::create($data);

        return redirect()->back()->with('message', 'You have successfully create a event.');
    }

    public function editEvent($id = "")
    {
        $event = FrontendEvent::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.events.edit_event', ['event' => $event]);
    }

    public function eventUpdate(Request $request, $id = "")
    {
        $data = $request->all();

        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');

        $data['timestamp']  = strtotime($data['timestamp']);
        $data['school_id']  = auth()->user()->school_id;
        $data['session_id'] = $active_session;
        $data['created_by'] = auth()->user()->id;

        unset($data['_token']);

        FrontendEvent::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);

        return redirect()->back()->with('message', 'Updated successfully.');
    }

    public function eventDelete($id)
    {
        $event = FrontendEvent::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $event->delete();
        return redirect()->back()->with('message', 'You have successfully delete a event.');
    }

    // Complain List
    public function complainList()
    {
        return view('admin.complain.complainList');
    }

    public function schoolSettings()
    {
        $school_details = School::find(auth()->user()->school_id);
        return view('admin.settings.school_settings', ['school_details' => $school_details]);
    }

    public function schoolUpdate(Request $request)
    {

        $data = $request->all();

        unset($data['_token']);

        $school_data = School::where('id', auth()->user()->school_id)->first();
        if ($request->school_logoo) {

            $old_image = $school_data->school_logo;

            $newFileName = SafeUpload::store($request->school_logoo, public_path() . '/assets/uploads/school_logo', SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $school_data->school_logo = $newFileName;
            $school_data->save();
        }

        if ($request->email_logo) {

            $old_image = $school_data->email_logo;

            $newFileName = SafeUpload::store($request->email_logo, public_path() . '/assets/uploads/school_logo', SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $school_data->email_logo = $newFileName;
            $school_data->save();
        }
        if ($request->socialLogo1) {

            $old_image = $school_data->socialLogo1;

            $newFileName = SafeUpload::store($request->socialLogo1, public_path() . '/assets/uploads/school_logo', SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $school_data->socialLogo1 = $newFileName;
            $school_data->save();
        }
        if ($request->socialLogo2) {

            $old_image = $school_data->socialLogo2;

            $newFileName = SafeUpload::store($request->socialLogo2, public_path() . '/assets/uploads/school_logo', SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $school_data->socialLogo2 = $newFileName;
            $school_data->save();
        }
        if ($request->socialLogo3) {

            $old_image = $school_data->socialLogo3;

            $newFileName = SafeUpload::store($request->socialLogo3, public_path() . '/assets/uploads/school_logo', SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $school_data->socialLogo3 = $newFileName;
            $school_data->save();
        }

        School::where('id', auth()->user()->school_id)->update([
            'title'         => $data['school_name'],
            'phone'         => $data['phone'],
            'address'       => $data['address'],
            'email_title'   => $data['email_title'],
            'email_details' => $data['email_details'],
            'warning_text'  => $data['warning_text'],
            'socialLink1'   => $data['socialLink1'],
            'socialLink2'   => $data['socialLink2'],
            'socialLink3'   => $data['socialLink3'],

        ]);

        return redirect()->back()->with('message', 'School details updated successfully.');
    }

    public function studentFeeinvoice($id)
    {
        $this->findSchoolFeeOrFail($id);
        $invoice_details = StudentFeeManager::find($id)->toArray();
        $student_details = (new CommonController)->get_student_details_by_id($invoice_details['student_id'])->toArray();

        return view('admin.student_fee_manager.invoice', ['invoice_details' => $invoice_details, 'student_details' => $student_details]);
    }

    public function offline_payment_pending(Request $request)
    {
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        if (count($request->all()) > 0) {
            $data            = $request->all();
            $date            = explode('-', $data['eDateRange']);
            $date_from       = strtotime($date[0] . ' 00:00:00');
            $date_to         = strtotime($date[1] . ' 23:59:59');
            $selected_class  = $data['class'];
            $selected_status = 'pending';

            if ($selected_class != "all" && $selected_status != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else if ($selected_class != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('class_id', $selected_class)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else if ($selected_status != "all") {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', $selected_status)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            } else {
                $invoices = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            }

            $classes = Classes::where('school_id', auth()->user()->school_id)->get();

            return view('admin.student_fee_manager.student_fee_manager_pending', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);
        } else {
            $classes         = Classes::where('school_id', auth()->user()->school_id)->get();
            $date_from       = strtotime(date('d-m-Y', strtotime('first day of this month')) . ' 00:00:00');
            $date_to         = strtotime(date('d-m-Y', strtotime('last day of this month')) . ' 23:59:59');
            $selected_class  = "";
            $selected_status = "";
            $invoices        = StudentFeeManager::where('timestamp', '>=', $date_from)->where('timestamp', '<=', $date_to)->where('status', 'pending')->where('school_id', auth()->user()->school_id)->where('session_id', $active_session)->get();
            return view('admin.student_fee_manager.student_fee_manager_pending', ['classes' => $classes, 'invoices' => $invoices, 'date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $selected_class, 'selected_status' => $selected_status]);
        }
    }

    public function update_offline_payment($id, $status)
    {
        $feeBefore = $this->findSchoolFeeOrFail($id);

        $amount = StudentFeeManager::find($id)->toArray();
        $amount = $amount['total_amount'];

        $studentFeeManager = StudentFeeManager::find($id);
        $students_id       = User::find($studentFeeManager->student_id);
        $student_email     = $students_id->email;
        $parents_id        = User::find($studentFeeManager->parent_id);

        if (! empty($parents_id)) {
            $parents_email = $parents_id->email;
        }

        if ($status == 'approve') {
            $studentsemail = StudentFeeManager::where('id', $id)->update([
                'status'         => 'paid',
                'updated_at'     => date("Y-m-d H:i:s"),
                'paid_amount'    => $amount,
                'payment_method' => 'offline'
            ]);

            if (! empty(get_settings('smtp_user')) && (get_settings('smtp_pass')) && (get_settings('smtp_host')) && (get_settings('smtp_port'))) {
                if (! empty($parents_id)) {
                    \App\Support\Mail\SafeMail::send($student_email, new StudentsEmail($studentFeeManager), 'fee-invoice');
                    \App\Support\Mail\SafeMail::send($parents_email, new StudentsEmail($studentFeeManager), 'fee-invoice');
                } else {
                    \App\Support\Mail\SafeMail::send($student_email, new StudentsEmail($studentFeeManager), 'fee-invoice');
                }
            }

            StatusChangeAudit::feePayment($feeBefore, 'approved');
            return redirect()->back()->with('message', 'Payment Approved');
        } elseif ($status == 'decline') {
            StudentFeeManager::where('id', $id)->update([
                'status'         => 'unpaid',
                'updated_at'     => date("Y-m-d H:i:s"),
                'paid_amount'    => $amount,
                'payment_method' => 'offline'
            ]);

            StatusChangeAudit::feePayment($feeBefore, 'declined');
            return redirect()->back()->with('message', 'Payment Decline');
        }
    }

    public function paymentSettings()
    {
        // Payment-gateway settings (including their secret keys) need the sensitive finance.settings
        // permission — today School Admin and Director via their base role (RBAC Phase 3A).
        abort_unless(auth()->user()->hasPermission('finance.settings'), 403);


        $payment_gateways = PaymentMethods::where('school_id', auth()->user()->school_id)->get();

        $school = School::where('id', auth()->user()->school_id)->first();
        $school_currency = $school ? $school->toArray() : ['currency' => get_active_currency()];
        $currencies       = Currency::all()->toArray();
        $paypal           = "";
        $paypal_keys      = "";
        $stripe           = "";
        $stripe_keys      = "";
        $razorpay         = "";
        $razorpay_keys    = "";
        $paytm            = "";
        $paytm_keys       = "";
        $flutterwave      = "";
        $flutterwave_keys = "";
        $paystack         = "";
        $paystack_keys    = "";
        $marzpay          = "";
        $marzpay_keys     = "";

        foreach ($payment_gateways as $single_gateway) {

            if ($single_gateway->name == "paypal") {

                $paypal      = $single_gateway->toArray();
                $paypal_keys = json_decode($paypal['payment_keys']);
            } elseif ($single_gateway->name == "stripe") {
                $stripe      = $single_gateway->toArray();
                $stripe_keys = json_decode($stripe['payment_keys']);
            } elseif ($single_gateway->name == "razorpay") {
                $razorpay      = $single_gateway->toArray();
                $razorpay_keys = json_decode($razorpay['payment_keys']);
            } elseif ($single_gateway->name == "paytm") {
                $paytm      = $single_gateway->toArray();
                $paytm_keys = json_decode($paytm['payment_keys']);
            } elseif ($single_gateway->name == "flutterwave") {
                $flutterwave      = $single_gateway->toArray();
                $flutterwave_keys = json_decode($flutterwave['payment_keys']);
            } elseif ($single_gateway->name == "paystack") {
                $paystack      = $single_gateway->toArray();
                $paystack_keys = json_decode($paystack['payment_keys']);
            } elseif ($single_gateway->name == "marzpay") {
                $marzpay      = $single_gateway->toArray();
                $marzpay_keys = json_decode($marzpay['payment_keys']);
            }
        }

        return view('admin.payment_settings.key_settings', ['paytm' => $paytm, 'paytm_keys' => $paytm_keys, 'razorpay' => $razorpay, 'razorpay_keys' => $razorpay_keys, 'stripe' => $stripe, 'stripe_keys' => $stripe_keys, 'paypal' => $paypal, 'paypal_keys' => $paypal_keys, 'flutterwave' => $flutterwave, 'flutterwave_keys' => $flutterwave_keys, 'paystack' => $paystack, 'paystack_keys' => $paystack_keys, 'marzpay' => $marzpay, 'marzpay_keys' => $marzpay_keys, 'school_currency' => $school_currency, 'currencies' => $currencies]);
    }

    public function install_paystack()
    {
        $keys                     = [];
        $paystack                 = new PaymentMethods;
        $paystack['name']         = "paystack";
        $paystack['image']        = "paystack.png";
        $paystack['status']       = 1;
        $paystack['mode']         = "test";
        $keys['test_key']         = "pk_test_xxxxxxxxxxxxx";
        $keys['test_secret_key']  = "sk_test_xxxxxxxxxxxxxxx";
        $keys['public_live_key']  = "pk_live_xxxxxxxxxxxxxx";
        $keys['secret_live_key']  = "sk_live_xxxxxxxxxxxxxx";
        $paystack['payment_keys'] = json_encode($keys);
        $paystack['school_id']    = auth()->user()->school_id;
        $paystack->save();
    }

    public function paymentSettings_post(Request $request)
    {
        // Payment-gateway settings (including their secret keys) need the sensitive finance.settings
        // permission — today School Admin and Director via their base role (RBAC Phase 3A).
        abort_unless(auth()->user()->hasPermission('finance.settings'), 403);

        $data = $request->all();

        unset($data['_token']);

        $school_data = School::where('id', auth()->user()->school_id)->first();

        if ($request->off_pay_ins_file || $request->off_pay_ins_text) {

            if ($request->off_pay_ins_file) {

                $old_image = $school_data->off_pay_ins_file;

                $newFileName = SafeUpload::store($request->off_pay_ins_file, public_path() . '/assets/uploads/offline_payment/', null) ?? abort(422, 'This file type is not allowed.');
                $school_data->off_pay_ins_file = $newFileName;
                $school_data->save();
            }

            School::where('id', auth()->user()->school_id)->update([
                'off_pay_ins_text' => $data['off_pay_ins_text'],

            ]);

            return redirect()->back()->with('message', 'Offline payment instruction update.');
        }
        $method    = $data['method'];
        $update_id = $data['update_id'];
        // Security Phase 2G: update_id comes from the request — only this school's own school/gateway rows may be updated.

        if ($method == 'currency') {
            $Currency                      = School::where('id', auth()->user()->school_id)->findOrFail($update_id);
            $Currency['school_currency']   = $data['school_currency'];
            $Currency['currency_position'] = $data['currency_position'];
            $Currency->save();
        } elseif ($method == 'paypal') {

            $keys                    = [];
            $paypal                  = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $paypal['status']        = $data['status'];
            $paypal['mode']          = $data['mode'];
            $keys['test_client_id']  = $data['test_client_id'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['live_client_id']  = $data['live_client_id'];
            $keys['live_secret_key'] = $data['live_secret_key'];
            $paypal['payment_keys']  = json_encode($keys);
            $paypal['school_id']     = auth()->user()->school_id;
            $paypal->save();
        } elseif ($method == 'stripe') {
            $keys                    = [];
            $stripe                  = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $stripe['status']        = $data['status'];
            $stripe['mode']          = $data['mode'];
            $keys['test_key']        = $data['test_key'];
            $keys['test_secret_key'] = $data['test_secret_key'];
            $keys['public_live_key'] = $data['public_live_key'];
            $keys['secret_live_key'] = $data['secret_live_key'];
            $stripe['payment_keys']  = json_encode($keys);
            $stripe['school_id']     = auth()->user()->school_id;
            $stripe->save();
        } elseif ($method == 'razorpay') {
            $keys                     = [];
            $razorpay                 = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $razorpay['status']       = $data['status'];
            $razorpay['mode']         = $data['mode'];
            $keys['test_key']         = $data['test_key'];
            $keys['test_secret_key']  = $data['test_secret_key'];
            $keys['live_key']         = $data['live_key'];
            $keys['live_secret_key']  = $data['live_secret_key'];
            $keys['theme_color']      = $data['theme_color'];
            $razorpay['payment_keys'] = json_encode($keys);
            $razorpay['school_id']    = auth()->user()->school_id;
            $razorpay->save();
        } elseif ($method == 'paytm') {
            $keys                      = [];
            $paytm                     = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $paytm['status']           = $data['status'];
            $paytm['mode']             = $data['mode'];
            $keys['test_merchant_id']  = $data['test_merchant_id'];
            $keys['test_merchant_key'] = $data['test_merchant_key'];
            $keys['live_merchant_id']  = $data['live_merchant_id'];
            $keys['live_merchant_key'] = $data['live_merchant_key'];
            $keys['environment']       = $data['environment'];
            $keys['merchant_website']  = $data['merchant_website'];
            $keys['channel']           = $data['channel'];
            $keys['industry_type']     = $data['industry_type'];
            $paytm['payment_keys']     = json_encode($keys);
            $paytm['school_id']        = auth()->user()->school_id;
            $paytm->save();
        } elseif ($method == 'flutterwave') {
            $keys                        = [];
            $flutterwave                 = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $flutterwave['status']       = $data['status'];
            $flutterwave['mode']         = $data['mode'];
            $keys['test_key']            = $data['test_key'];
            $keys['test_secret_key']     = $data['test_secret_key'];
            $keys['test_encryption_key'] = $data['test_encryption_key'];
            $keys['public_live_key']     = $data['public_live_key'];
            $keys['secret_live_key']     = $data['secret_live_key'];
            $keys['encryption_live_key'] = $data['encryption_live_key'];
            $flutterwave['payment_keys'] = json_encode($keys);
            $flutterwave['school_id']    = auth()->user()->school_id;
            $flutterwave->save();
        } elseif ($method == 'paystack') {
            $keys                     = [];
            $paystack                 = new PaymentMethods;
            $paystack['name']         = "paystack";
            $paystack['image']        = "paystack.png";
            $paystack['status']       = 1;
            $paystack['mode']         = "test";
            $keys['test_key']         = "pk_test_xxxxxxxxxxx";
            $keys['test_secret_key']  = "sk_test_xxxxxxxxxxx";
            $keys['public_live_key']  = "pk_live_xxxxxxxxxxxxxx";
            $keys['secret_live_key']  = "sk_live_xxxxxxxxxxxxxx";
            $paystack['payment_keys'] = json_encode($keys);
            $paystack['school_id']    = auth()->user()->school_id;
            $paystack->save();
        } elseif ($method == 'marzpay') {
            $keys                       = [];
            $marzpay                    = PaymentMethods::where('school_id', auth()->user()->school_id)->findOrFail($update_id);
            $marzpay['status']          = $data['status'];
            $marzpay['mode']            = $data['mode'];
            $keys['sandbox_api_key']    = $data['sandbox_api_key'];
            $keys['sandbox_api_secret'] = $data['sandbox_api_secret'];
            $keys['live_api_key']       = $data['live_api_key'];
            $keys['live_api_secret']    = $data['live_api_secret'];
            $keys['country']            = $data['country'] ?: 'UG';
            $marzpay['payment_keys']    = json_encode($keys);
            $marzpay['school_id']       = auth()->user()->school_id;
            $marzpay->save();
        }

        return redirect()->route('admin.settings.payment')->with('message', 'key has been updated');
    }

    public function insert_gateways()
    {
        $paypal = PaymentMethods::where(['name' => 'paypal', 'school_id' => auth()->user()->school_id])->first();

        if (empty($paypal)) {
            $keys                    = [];
            $paypal                  = new PaymentMethods;
            $paypal['name']          = "paypal";
            $paypal['image']         = "paypal.png";
            $paypal['status']        = 1;
            $paypal['mode']          = "test";
            $keys['test_client_id']  = "snd_cl_id_xxxxxxxxxxxxx";
            $keys['test_secret_key'] = "snd_cl_sid_xxxxxxxxxxxx";
            $keys['live_client_id']  = "lv_cl_id_xxxxxxxxxxxxxxx";
            $keys['live_secret_key'] = "lv_cl_sid_xxxxxxxxxxxxxx";
            $paypal['payment_keys']  = json_encode($keys);
            $paypal['school_id']     = auth()->user()->school_id;
            $paypal->save();
        }

        $stripe = PaymentMethods::where(['name' => 'stripe', 'school_id' => auth()->user()->school_id])->first();

        if (empty($stripe)) {
            $keys                    = [];
            $stripe                  = new PaymentMethods;
            $stripe['name']          = "stripe";
            $stripe['image']         = "stripe.png";
            $stripe['status']        = 1;
            $stripe['mode']          = "test";
            $keys['test_key']        = "pk_test_xxxxxxxxxxxxx";
            $keys['test_secret_key'] = "sk_test_xxxxxxxxxxxxxx";
            $keys['public_live_key'] = "pk_live_xxxxxxxxxxxxxx";
            $keys['secret_live_key'] = "sk_live_xxxxxxxxxxxxxx";
            $stripe['payment_keys']  = json_encode($keys);
            $stripe['school_id']     = auth()->user()->school_id;
            $stripe->save();
        }

        $razorpay = PaymentMethods::where(['name' => 'razorpay', 'school_id' => auth()->user()->school_id])->first();

        if ((empty($razorpay))) {
            $keys                     = [];
            $razorpay                 = new PaymentMethods;
            $razorpay['name']         = "razorpay";
            $razorpay['image']        = "razorpay.png";
            $razorpay['status']       = 1;
            $razorpay['mode']         = "test";
            $keys['test_key']         = "rzp_test_xxxxxxxxxxxxx";
            $keys['test_secret_key']  = "rzs_test_xxxxxxxxxxxxx";
            $keys['live_key']         = "rzp_live_xxxxxxxxxxxxx";
            $keys['live_secret_key']  = "rzs_live_xxxxxxxxxxxxx";
            $keys['theme_color']      = "#c7a600";
            $razorpay['payment_keys'] = json_encode($keys);
            $razorpay['school_id']    = auth()->user()->school_id;
            $razorpay->save();
        }

        $paytm = PaymentMethods::where(['name' => 'paytm', 'school_id' => auth()->user()->school_id])->first();

        if (empty($paytm)) {
            $keys                      = [];
            $paytm                     = new PaymentMethods;
            $paytm['name']             = "paytm";
            $paytm['image']            = "paytm.png";
            $paytm['status']           = 1;
            $paytm['mode']             = "test";
            $keys['test_merchant_id']  = "tm_id_xxxxxxxxxxxx";
            $keys['test_merchant_key'] = "tm_key_xxxxxxxxxx";
            $keys['live_merchant_id']  = "lv_mid_xxxxxxxxxxx";
            $keys['live_merchant_key'] = "lv_key_xxxxxxxxxxx";
            $keys['environment']       = "provide-a-environment";
            $keys['merchant_website']  = "merchant-website";
            $keys['channel']           = "provide-channel-type";
            $keys['industry_type']     = "provide-industry-type";
            $paytm['payment_keys']     = json_encode($keys);
            $paytm['school_id']        = auth()->user()->school_id;
            $paytm->save();
        }

        $flutterwave = PaymentMethods::where(['name' => 'flutterwave', 'school_id' => auth()->user()->school_id])->first();

        if (empty($flutterwave)) {
            $keys                        = [];
            $flutterwave                 = new PaymentMethods;
            $flutterwave['name']         = "flutterwave";
            $flutterwave['image']        = "flutterwave.png";
            $flutterwave['status']       = 1;
            $flutterwave['mode']         = "test";
            $keys['test_key']            = "flwp_test_xxxxxxxxxxxxx";
            $keys['test_secret_key']     = "flws_test_xxxxxxxxxxxxx";
            $keys['test_encryption_key'] = "flwe_test_xxxxxxxxxxxxx";
            $keys['public_live_key']     = "flwp_live_xxxxxxxxxxxxxx";
            $keys['secret_live_key']     = "flws_live_xxxxxxxxxxxxxx";
            $keys['encryption_live_key'] = "flwe_live_xxxxxxxxxxxxxx";
            $flutterwave['payment_keys'] = json_encode($keys);
            $flutterwave['school_id']    = auth()->user()->school_id;
            $flutterwave->save();
        }

        $paystack = PaymentMethods::where(['name' => 'paystack', 'school_id' => auth()->user()->school_id])->first();

        if (empty($paystack)) {
            $keys                     = [];
            $paystack                 = new PaymentMethods;
            $paystack['name']         = "paystack";
            $paystack['image']        = "paystack.png";
            $paystack['status']       = 1;
            $paystack['mode']         = "test";
            $keys['test_key']         = "pk_test_xxxxxxxxxx";
            $keys['test_secret_key']  = "sk_test_xxxxxxxxxxxxxx";
            $keys['public_live_key']  = "pk_live_xxxxxxxxxxxxxx";
            $keys['secret_live_key']  = "sk_live_xxxxxxxxxxxxxx";
            $paystack['payment_keys'] = json_encode($keys);
            $paystack['school_id']    = auth()->user()->school_id;
            $paystack->save();
        }

        $marzpay = PaymentMethods::where(['name' => 'marzpay', 'school_id' => auth()->user()->school_id])->first();

        if (empty($marzpay)) {
            $keys                      = [];
            $marzpay                   = new PaymentMethods;
            $marzpay['name']           = "marzpay";
            $marzpay['image']          = "marzpay.png";
            // Unlike the other gateways above, this starts disabled — those
            // ship with obviously-fake placeholder keys but status=1 anyway,
            // which is why their payment buttons show up broken on the fee
            // page today. MarzPay stays off until real keys are saved.
            $marzpay['status']         = 0;
            $marzpay['mode']           = "test";
            $keys['sandbox_api_key']    = "";
            $keys['sandbox_api_secret'] = "";
            $keys['live_api_key']       = "";
            $keys['live_api_secret']    = "";
            $keys['country']            = "UG";
            $marzpay['payment_keys']   = json_encode($keys);
            $marzpay['school_id']      = auth()->user()->school_id;
            $marzpay->save();
        }
    }

    public function subscriptionPayment($package_id)
    {
        $selectedPackageModel = Package::find($package_id);
        if (!$selectedPackageModel) {
            return redirect()->route('admin.subscription')->with('error', 'Selected package not found.');
        }

        $userModel = User::where('id', auth()->user()->id)->first();
        if (!$userModel) {
            return redirect()->route('login')->with('error', 'User account not found. Please login again.');
        }

        $selected_package = $selectedPackageModel->toArray();
        $user_info        = $userModel->toArray();

        if ($selected_package['price'] == 0) {
            $check_duplication = Subscription::where('package_id', $selected_package['id'])->where('school_id', auth()->user()->school_id)->get()->count();
            if ($check_duplication == 0) {
                return redirect()->route('admin_free_subcription', ['user_id' => auth()->user()->id, 'package_id' => $selected_package['id']]);
            } else {
                return redirect()->back()->with('error', 'you can not subscribe the free trail twice');
            }
        }

        return view('admin.subscription.payment_gateway', ['selected_package' => $selected_package, 'user_info' => $user_info]);
    }

    /**
     * Starts a MarzPay mobile-money collection for a subscription
     * purchase/upgrade. The subscription itself is only activated once
     * MarzPayWebhookController confirms the collection completed (see
     * App\Support\Subscriptions\SubscriptionActivator) — same rule as
     * every other MarzPay flow: never trust a redirect/click alone.
     */
    public function startMarzpaySubscriptionPayment(Request $request, $package_id)
    {
        $package = Package::find($package_id);

        if (! $package) {
            return redirect()->route('admin.subscription')->with('error', 'Selected package not found.');
        }

        $request->validate(['phone_number' => 'required|string|min:9|max:15']);

        $schoolId = auth()->user()->school_id;
        $subscription = Subscription::where('school_id', $schoolId)->orderBy('id', 'desc')->first();

        $amount = (float) $package->price;
        if ($subscription && (string) $subscription->active === '1') {
            $amount = (float) $package->price - (float) $subscription->paid_amount;
        }

        $payment = new PaymentHistory;
        $payment['payment_type']     = 'subscription';
        $payment['user_id']          = auth()->user()->id;
        $payment['package_id']       = $package_id;
        $payment['amount']           = $amount;
        $payment['school_id']        = $schoolId;
        $payment['transaction_keys'] = '[]';
        $payment['paid_by']          = 'marzpay';
        $payment['status']           = 'pending';
        $payment['timestamp']        = strtotime(date('Y-m-d H:i:s'));
        $payment->save();

        $reference = (string) \Illuminate\Support\Str::uuid();

        $result = \App\Support\Payments\MarzPayService::initiateMobileMoneyCollection(
            (int) $schoolId,
            $request->phone_number,
            $amount,
            $reference,
            'Subscription: ' . $package->name,
            route('webhooks.marzpay'),
            ['context' => 'subscription', 'context_id' => $payment->id]
        );

        if (! $result['ok']) {
            $payment->delete();

            return redirect()->back()->with('error', $result['error'] ?: get_phrase('We could not start the MarzPay payment. Please try again.'));
        }

        $payment->transaction_keys = json_encode(['gateway' => 'marzpay', 'reference' => $result['transaction_uuid'] ?: $reference]);
        $payment->save();

        return view('admin.subscription.marzpay_pending', ['payment' => $payment]);
    }

    public function checkMarzpaySubscriptionStatus($id)
    {
        $payment = PaymentHistory::where('id', $id)
            ->where('school_id', auth()->user()->school_id)
            ->first();

        if (! $payment) {
            return response()->json(['status' => 'not_found']);
        }

        if ($payment->status === 'approve') {
            return response()->json(['status' => 'paid']);
        }

        $keys = json_decode((string) $payment->transaction_keys, true) ?: [];
        $reference = $keys['reference'] ?? null;

        if (! $reference) {
            return response()->json(['status' => 'processing']);
        }

        $verified = \App\Support\Payments\MarzPayService::getCollectionStatus($reference, (int) $payment->school_id);
        $verifiedStatus = $verified['transaction']['status'] ?? null;

        if (in_array($verifiedStatus, ['successful', 'completed'], true)) {
            \App\Support\Subscriptions\SubscriptionActivator::activate((int) $payment->id);

            return response()->json(['status' => 'paid']);
        }

        if (in_array($verifiedStatus, ['failed', 'cancelled'], true)) {
            PaymentHistory::where('id', $id)->update(['status' => 'failed']);

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => 'processing']);
    }

    public function admin_free_subcription(Request $request)
    {
        $data = $request->all();

        $selectedPackageModel = Package::find($data['package_id'] ?? null);
        $userModel = User::where('id', $data['user_id'] ?? null)->first();
        if (!$selectedPackageModel || !$userModel) {
            return redirect()->route('admin.subscription')->with('error', 'Unable to process free subscription. Missing user or package.');
        }

        $selected_package = $selectedPackageModel->toArray();
        $user_info        = $userModel->toArray();
        $school_email     = School::where('id', auth()->user()->school_id)->value('email');

        $data['document_file'] = "sample-payment.pdf";

        $transaction_keys = json_encode($data);
        if ($selected_package['package_type'] == 'life_time') {
            $status = Subscription::create([
                'package_id'       => $selected_package['id'],
                'school_id'        => auth()->user()->school_id,
                'paid_amount'      => $selected_package['price'],
                'payment_method'   => 'free',
                'transaction_keys' => $transaction_keys,
                'date_added'       => strtotime(date("Y-m-d H:i:s")),
                'expire_date'      => 'life_time',
                'studentLimit'     => $selected_package['studentLimit'],
                'status'           => '1',
                'active'           => '1',
            ]);
        } else {
            $status = Subscription::create([
                'package_id'       => $selected_package['id'],
                'school_id'        => auth()->user()->school_id,
                'paid_amount'      => $selected_package['price'],
                'payment_method'   => 'free',
                'transaction_keys' => $transaction_keys,
                'date_added'       => strtotime(date("Y-m-d H:i:s")),
                'expire_date'      => strtotime('+' . $selected_package['days'] . ' days', strtotime(date("Y-m-d H:i:s"))),
                'studentLimit'     => $selected_package['studentLimit'],
                'status'           => '1',
                'active'           => '1',
            ]);
        }

        \App\Support\Mail\SafeMail::send($school_email, new FreeEmail($status), 'school-status');

        return redirect()->route('admin.subscription')->with('message', 'Free Subscription Completed Successfully');
    }

    public function admin_subscription_offline_payment(Request $request, $id = "")
    {
        $data = $request->all();

        if ($data['amount'] > 0) {

            $file = $data['document_image'];

            if ($file) {
                $filename = SafeUpload::store($file, public_path('assets/uploads/offline_payment'), null) ?? abort(422, 'This file type is not allowed.');
                $data['document_image'] = $filename;
            } else {
                $data['document_image'] = '';
            }

            $pending_payment = new PaymentHistory;

            $pending_payment['payment_type']     = 'subscription';
            $pending_payment['user_id']          = auth()->user()->id;
            $pending_payment['package_id']       = $id;
            $pending_payment['amount']           = $data['amount'];
            $pending_payment['school_id']        = auth()->user()->school_id;
            $pending_payment['transaction_keys'] = '[]';
            $pending_payment['document_image']   = $data['document_image'];
            $pending_payment['paid_by']          = 'offline';
            $pending_payment['status']           = 'pending';
            $pending_payment['timestamp']        = strtotime(date("Y-m-d H:i:s"));

            $pending_payment->save();

            return redirect()->route('admin.subscription')->with('message', 'offline payment requested successfully');
        } else {
            return redirect()->route('admin.subscription')->with('message', 'offline payment requested fail');
        }
    }

    public function offlinePayment(Request $request, $id = "")
    {
        $data = $request->all();

        if ($data['amount'] > 0):

            $file = $data['document_image'];

            if ($file) {
                $filename = SafeUpload::store($file, public_path('assets/uploads/offline_payment'), null) ?? abort(422, 'This file type is not allowed.');
                $data['document_image'] = $filename;
            } else {
                $data['document_image'] = '';
            }

            PaymentHistory::create([
                'payment_type'     => 'subscription',
                'user_id'          => auth()->user()->id,
                'amount'           => $data['amount'],
                'school_id'        => $id,
                'transaction_keys' => json_encode([]),
                'document_image'   => $data['document_image'],
                'paid_by'          => 'offline',
                'status'           => 'pending',
                'timestamp'        => strtotime(date('Y-m-d')),
            ]);

            return redirect('admin/subscription')->with('message', 'Your document will be reviewd.');

        else:
            return redirect('admin/subscription')->with('warning', 'Session timed out. Please try again');
        endif;
    }

    public function profile()
    {
        return view('admin.profile.view');
    }

    public function profile_update(Request $request)
    {
        $data['name']        = $request->name;
        $data['email']       = $request->email;
        // Security Phase 2F: a self-service profile edit must not claim another account's login email.
        if (User::where('email', $request->email)->where('id', '!=', auth()->user()->id)->exists()) {
            return redirect()->back()->with('error', 'Email was already taken.');
        }
        $data['designation'] = $request->designation;

        $user_info['birthday'] = strtotime($request->eDefaultDateRange);
        $user_info['gender']   = $request->gender;
        $user_info['phone']    = $request->phone;
        $user_info['address']  = $request->address;

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

        return redirect(route('admin.profile'))->with('message', get_phrase('Profile info updated successfully'));
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

            if ($request->new_password != $request->confirm_password) {
                return back()->with("error", "Confirm Password Doesn't match!");
            }

            if (! Hash::check($request->old_password, auth()->user()->password)) {
                return back()->with("error", "Current Password Doesn't match!");
            }

            $data['password'] = Hash::make($request->new_password);
            auth()->user()->update($data);

            return redirect(route('admin.password', 'edit'))->with('message', get_phrase('Password changed successfully'));
        }

        return view('admin.profile.password');
    }

    /**
     * Show the session manager.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */

    public function sessionManager()
    {
        $sessions = Session::where('school_id', auth()->user()->school_id)->get();
        return view('admin.session.session_manager', ['sessions' => $sessions]);
    }

    public function activeSession($id)
    {
        // Security Phase 2G: the session must belong to this school before it can become the running session.
        Session::where('school_id', auth()->user()->school_id)->findOrFail($id);

        $previous_session_id = get_school_settings(auth()->user()->school_id)->value('running_session');

        Session::where('id', $previous_session_id)->where('school_id', auth()->user()->school_id)->update([
            'status' => '0',
        ]);

        $session = Session::where('id', $id)->where('school_id', auth()->user()->school_id)->update([
            'status' => '1',
        ]);

        School::where('id', auth()->user()->school_id)->update([
            'running_session' => $id,
        ]);

        $response = [
            'status'       => true,
            'notification' => get_phrase('Session has been activated'),
        ];
        $response = json_encode($response);

        echo $response;
    }

    public function createSession()
    {
        return view('admin.session.create');
    }

    public function sessionCreate(Request $request)
    {
        $data = $request->all();

        $duplicate_session_check = Session::get()->where('session_title', $data['session_title'])->where('school_id', auth()->user()->school_id);

        if (count($duplicate_session_check) == 0) {

            $data['status']    = '0';
            $data['school_id'] = auth()->user()->school_id;

            Session::create($data);

            return redirect()->back()->with('message', 'You have successfully create a session.');
        } else {
            return redirect()->back()->with('error', 'Sorry this session already exists');
        }
    }

    public function editSession($id = '')
    {
        $session = Session::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.session.edit', ['session' => $session]);
    }

    public function sessionUpdate(Request $request, $id)
    {
        $data = $request->all();

        unset($data['_token']);

        Session::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);

        return redirect()->back()->with('message', 'You have successfully update session.');
    }

    public function sessionDelete($id = '')
    {
        $previous_session_id = get_school_settings(auth()->user()->school_id)->value('running_session');

        if ($previous_session_id != $id) {
            $session = Session::where('school_id', auth()->user()->school_id)->findOrFail($id);
            $session->delete();
            return redirect()->back()->with('message', 'You have successfully delete a session.');
        } else {
            return redirect()->back()->with('error', 'Can not delete active session.');
        }
    }

    // Account Disable
    public function account_disable($id)
    {
        $user = User::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if ($user && !$this->mayAdministerAccountSecurity($user)) {
            return redirect()->back()->with('error', 'You do not have permission to change this account status.');
        }
        if ($user && ($rejected = $this->rejectAdminLockout($user))) {
            return $rejected;
        }
        if ($user) {
            $user->update([
                'account_status' => 'disable',
            ]);
        }
        return redirect()->back()->with('message', 'Account Disable Successfully');
    }

    // Account Enable
    public function account_enable($id)
    {
        $user = User::where('id', $id)->where('school_id', auth()->user()->school_id)->first();
        if ($user && !$this->mayAdministerAccountSecurity($user)) {
            return redirect()->back()->with('error', 'You do not have permission to change this account status.');
        }
        if ($user) {
            $user->update([
                'account_status' => 'enable',
            ]);
        }
        return redirect()->back()->with('message', 'Account Enable Successfully');
    }

    public function feedback_list()
    {
        $feedbacks = Feedback::where('school_id', auth()->user()->school_id)->orderBy('created_at', 'DESC')->paginate(20);
        return view('admin.feedback.feedback_list', ['feedbacks' => $feedbacks]);
    }

    public function create_feedback()
    {
        $classes = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.feedback.create_feedback', ['classes' => $classes]);
    }

    public function upload_feedback(Request $request)
    {
        $data           = $request->all();
        $active_session = get_school_settings(auth()->user()->school_id)->value('running_session');
        //$admin_id = auth()->user()->id;

        $feedbackData = [
            'class_id'      => $data['class_id'],
            'section_id'    => $data['section_id'],
            'student_id'    => isset($data['student_id'][0]) ? $data['student_id'][0] : null, // Assuming single student for feedback
            'parent_id'     => isset($data['parent_id'][0]) ? $data['parent_id'][0] : null,   // Assuming single parent for feedback
            'feedback_text' => $data['feedback_text'],
            'school_id'     => auth()->user()->school_id,
            'admin_id'      => auth()->user()->id,
            'session_id'    => $active_session,
            'title'         => $data['title'],

        ];

        // Create feedback entry
        Feedback::create($feedbackData);

        return redirect()->back()->with('message', 'Feedback Sent Successfully');
    }

    public function edit_feedback($id)
    {

        $feedback = Feedback::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes  = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.feedback.edit_feedback', ['classes' => $classes], ['feedback' => $feedback]);
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

                if (! empty($user)) {
                    $userInfo = json_decode($user->user_information);

                    $user_image = ! empty($userInfo->photo)
                        ? asset('assets/uploads/user-images/' . $userInfo->photo)
                        : asset('assets/uploads/user-images/thumbnail.png');

                    $html .= '
                        <div class="user-item d-flex align-items-center msg_us_src_list">
                            <a href="' . route('admin.message.messagethrades', ['id' => $user->id]) . '">
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

        if (! empty($counter_condition->sender_id)) {
            if ($counter_condition->sender_id != auth()->user()->id) {
                Chat::where('message_thrade', $id)->update(['read_status' => 1]);
            }
        }

        return view('admin.message.all_message', ['msg_user_details' => $msg_user_details], ['chat_datas' => $chat_datas]);
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
            return view('admin.message.all_message', ['id' => $msg_trd_id, 'msg_user_details' => $msg_user_details, 'chat_datas' => $chat_datas]);
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
                        <a href="' . route('admin.message.messagethrades', ['id' => $user->id]) . '">
                            <img src="' . $user_image . '" alt="User Image" style="width: 50px; height: 50px; border-radius: 50%;">
                            <span class="ms-3">' . $user->name . '</span>
                        </a>
                    </div>
                ';
            }

            return response()->json($html);
        }

        // Pass the data to the view only if msg_user_details is not null
        return view('admin.message.chat_empty');
    }

    // Appraisal

    public function appraisalQuestions()
    {
        $appraisals = Appraisal::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.appraisal.appraisalQuestions', ['appraisals' => $appraisals]);
    }

    public function createQuestion()
    {
        $teachers = User::get()->where('role_id', 3)->where('school_id', auth()->user()->school_id);
        $classes  = Classes::get()->where('school_id', auth()->user()->school_id);
        return view('admin.appraisal.createQuestion', ['classes' => $classes], ['teachers' => $teachers]);
    }

    public function storeQuestion(Request $request)
    {

        $request->validate([
            'class_id'     => 'required|integer',
            'teacher_id'   => 'required|array',
            'teacher_id.*' => 'integer',
            'ans_type'     => 'required|string|in:mcq,rating,binary,text',
            'title'        => 'required|string|max:255',
            'question'     => 'required|array',
            'question.*'   => 'required|string|max:500',
            'status'       => 'required|in:0,1',
        ]);

        Appraisal::create([
            'class_id'   => $request->class_id,
            'teacher_id' => json_encode($request->teacher_id),
            'ans_type'   => $request->ans_type,
            'title'      => $request->title,
            'question'   => json_encode($request->question),
            'status'     => $request->status,
            'school_id'  => auth()->user()->school_id,
        ]);

        return redirect()->back()->with('message', 'You have successfully created questions.');
    }

    public function appraisalQuestionEdit($id)
    {
        $appraisal = Appraisal::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $classes   = Classes::get()->where('school_id', auth()->user()->school_id);
        $teachers  = User::get()->where('role_id', 3)->where('school_id', auth()->user()->school_id);
        return view('admin.appraisal.appraisalQuestionEdit', ['classes' => $classes, 'appraisal' => $appraisal, 'teachers' => $teachers]);
    }

    public function appraisalQuestionUpdate(Request $request, $id)
    {
        $request->validate([
            'class_id'   => 'required',
            'teacher_id' => 'required|array',
            'ans_type'   => 'required',
            'title'      => 'required|string',
            'question'   => 'required|array',
            'status'     => 'required|in:0,1',
        ]);

        $appraisal = Appraisal::where('school_id', auth()->user()->school_id)->findOrFail($id);

        $appraisal->class_id   = $request->input('class_id');
        $appraisal->teacher_id = json_encode($request->input('teacher_id'));
        $appraisal->ans_type   = $request->input('ans_type');
        $appraisal->title      = $request->input('title');
        $appraisal->question   = json_encode($request->input('question'));
        $appraisal->status     = $request->input('status');

        $appraisal->save();

        return redirect()->back()->with('message', 'You have successfully updated.');
    }

    public function appraisalQuestionDelete($id)
    {
        Appraisal::where('id', $id)->where('school_id', auth()->user()->school_id)->delete();
        return redirect()->back()->with('message', 'Delete successfully.');
    }

    public function appraisalFeedback()
    {
        $feedbacks = Appraisal_submit::where('school_id', auth()->user()->school_id)->get();

        $teacher_ids = [];
        foreach ($feedbacks as $feedback) {
            $decodedAnswers = json_decode($feedback->answers, true);
            $teacher_ids    = array_merge($teacher_ids, array_keys($decodedAnswers));
        }
        $teacher_ids = array_unique($teacher_ids);

        $teachers = DB::table('users')->whereIn('id', $teacher_ids)->pluck('name', 'id');

        $appraisals = DB::table('appraisals')->pluck('title', 'id');

        $students = DB::table('users')->whereIn('id', $feedbacks->pluck('student_id'))->pluck('name', 'id');

        return view('admin.appraisal.studentFeedback', compact('feedbacks', 'teachers', 'appraisals', 'students'));
    }

    // Hostel
    public function hostel_list()
    {
        $page_data['hostels'] = Hostel::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.hostel.list', $page_data);
    }
    public function create_hostel()
    {
        $page_data['wardens'] = User::where('role_id', 10)->where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel.create', $page_data);
    }
    public function store_hostel(Request $request)
    {
        $data      = $request->all();
        $school_id = auth()->user()->school_id;

        $duplicate_check = Hostel::where('name', $data['name'])
            ->where('school_id', $school_id)
            ->get();

        if (count($duplicate_check) == 0) {
            $data['school_id'] = $school_id;
            Hostel::create($data);

            return redirect()->route('admin.hostel.hostel_list')
                ->with('message', 'Hostel created successfully');
        } else {
            return back()->with('error', 'Sorry, this hostel already exists.');
        }
    }
    public function edit_hostel($id)
    {
        $page_data['hostel']  = Hostel::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $page_data['wardens'] = User::where('role_id', 10)->where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel.edit', $page_data);
    }
    public function update_hostel(Request $request, $id)
    {
        $data = $request->all();
        unset($data['_token']);
        Hostel::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);
        return redirect()->route('admin.hostel.hostel_list')->with('message', 'Hostel updated successfully');
    }
    public function delete_hostel($id)
    {
        Hostel::where('id', $id)->where('school_id', auth()->user()->school_id)->delete();
        return redirect()->route('admin.hostel.hostel_list')->with('message', 'Hostel deleted successfully');
    }
    // Hostel Room Management
    public function hostel_room_list()
    {
        $page_data['hostel_rooms'] = HostelRoom::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.hostel_room.list', $page_data);
    }
    public function create_hostel_room()
    {
        $page_data['hostels'] = Hostel::where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel_room.create', $page_data);
    }
    public function store_hostel_room(Request $request)
    {
        $data              = $request->all();
        $data['school_id'] = auth()->user()->school_id;
        HostelRoom::create($data);
        return redirect()->route('admin.hostel.room_list')->with('message', 'Hostel Room created successfully');
    }
    public function edit_hostel_room($id)
    {
        $page_data['hostel_room'] = HostelRoom::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $page_data['hostels']     = Hostel::where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel_room.edit', $page_data);
    }
    public function update_hostel_room(Request $request, $id)
    {
        $data = $request->all();
        unset($data['_token']);
        HostelRoom::where('id', $id)->where('school_id', auth()->user()->school_id)->update($data);
        return redirect()->route('admin.hostel.room_list')->with('message', 'Hostel Room updated successfully');
    }
    public function delete_hostel_room($id)
    {
        HostelRoom::where('id', $id)->where('school_id', auth()->user()->school_id)->delete();
        return redirect()->route('admin.hostel.room_list')->with('message', 'Hostel Room deleted successfully');
    }
    public function hostel_room_allocation_list()
    {
        $page_data['hostel_room_allocations'] = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->paginate(10);
        return view('admin.hostel_room_allocation.list', $page_data);
    }
    public function create_hostel_room_allocation()
    {
        $page_data['hostel_rooms'] = HostelRoom::where('school_id', auth()->user()->school_id)->get();
        $page_data['students']     = User::where('role_id', 7)->where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel_room_allocation.create', $page_data);
    }
    public function store_hostel_room_allocation(Request $request)
    {
        $data              = $request->all();
        $data['school_id'] = auth()->user()->school_id;
        HostelRoomAllocation::create($data);

        $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($data['room_id']);
        if ($room) {
            $currentOccupied = HostelRoomAllocation::where('room_id', $room->id)->count();
            $room->update(['occupied' => $currentOccupied]);
        }

        $application              = new HostelApplication();
        $application->student_id  = $data['student_id'];
        $application->school_id   = auth()->user()->school_id;
        $application->hostel_id   = $room->hostel_id;
        $application->room_id     = $data['room_id'];
        $application->status      = 1;
        $application->accepted_at = now();
        $application->save();

        return redirect()->route('admin.hostel.allocation_list')->with('message', 'Hostel Room Allocation created successfully');
    }
    public function edit_hostel_room_allocation($id)
    {
        $page_data['hostel_room_allocation'] = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $page_data['hostel_rooms']           = HostelRoom::where('school_id', auth()->user()->school_id)->get();
        $page_data['students']               = User::where('role_id', 7)->where('school_id', auth()->user()->school_id)->get();
        return view('admin.hostel_room_allocation.edit', $page_data);
    }
    public function update_hostel_room_allocation(Request $request, $id)
    {
        $allocation = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);
        $oldRoomId  = $allocation->room_id;

        $data = $request->all();
        unset($data['_token']);
        $allocation->update($data);

        // Update occupied count for old and new room
        if ($oldRoomId != $data['room_id']) {
            $oldRoom = HostelRoom::where('school_id', auth()->user()->school_id)->find($oldRoomId);
            if ($oldRoom) {
                $oldRoom->update([
                    'occupied' => HostelRoomAllocation::where('room_id', $oldRoom->id)->count(),
                ]);
            }
        }

        $newRoom = HostelRoom::where('school_id', auth()->user()->school_id)->find($data['room_id']);
        if ($newRoom) {
            $newRoom->update([
                'occupied' => HostelRoomAllocation::where('room_id', $newRoom->id)->count(),
            ]);
        }
        return redirect()->route('admin.hostel.allocation_list')->with('message', 'Hostel Room Allocation updated successfully');
    }
    public function delete_hostel_room_allocation($id)
    {
        $allocation = HostelRoomAllocation::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($allocation) {
            $roomId = $allocation->room_id;
            $allocation->delete();

            // Update occupied count
            $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($roomId);
            if ($room) {
                $room->update([
                    'occupied' => HostelRoomAllocation::where('room_id', $room->id)->count(),
                ]);
            }
        }

        return redirect()->route('admin.hostel.allocation_list')
            ->with('message', 'Hostel Room Allocation deleted successfully');
    }
    public function applications()
    {
        $applications = HostelApplication::where('school_id', auth()->user()->school_id)
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.hostel_applications.list', compact('applications'));
    }

    public function approveApplication($id)
    {
        $application = HostelApplication::where('school_id', auth()->user()->school_id)->findOrFail($id);

        $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($application->room_id);
        if ($room->occupied >= $room->capacity) {
            return redirect()->back()->with('error', 'Room is already full');
        }

        $application->accepted_at = now();
        $application->status      = 1;
        $application->save();

        $room->occupied += 1;
        $room->save();

        $this->createRoomAllocation($application->student_id, $room->id);

        return redirect()->back()->with('success', 'Application approved successfully');
    }

    private function createRoomAllocation($studentId, $roomId)
    {
        HostelRoomAllocation::create([
            'student_id'   => $studentId,
            'room_id'      => $roomId,
            'allocated_on' => now(),
            'status'       => 1,
            'school_id'    => auth()->user()->school_id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function rejectApplication($id)
    {
        $application = HostelApplication::where('school_id', auth()->user()->school_id)->findOrFail($id);

        if ($application->status == 1) {
            $room = HostelRoom::where('school_id', auth()->user()->school_id)->find($application->room_id);

            if ($room && $room->occupied > 0) {
                $room->occupied -= 1;
                $room->save();
            }

            HostelRoomAllocation::where('student_id', $application->student_id)
                ->where('room_id', $application->room_id)
                ->where('school_id', auth()->user()->school_id)
                ->delete();

            // Delete created fee records
            HostelFee::where('student_id', $application->student_id)
                ->where('room_id', $application->room_id)
                ->where('school_id', auth()->user()->school_id)
                ->where('status', 0) // only unpaid fees
                ->delete();
        }

        $application->status = 2;
        $application->save();

        return redirect()->back()->with('success', 'Application rejected successfully');
    }
    public function hostelFees(Request $request)
    {
        $search = $request->search ?? "";

        $studentsId = HostelApplication::where('status', 1)
            ->where('school_id', auth()->user()->school_id)
            ->distinct()
            ->pluck('student_id')
            ->toArray();

        $studentsQuery = User::whereIn('id', $studentsId)
            ->where('school_id', auth()->user()->school_id);

        if ($search != "") {

            $controller = new \App\Http\Controllers\CommonController();

            $studentsIdsFiltered = [];

            foreach ($studentsId as $sid) {

                $details = $controller->get_student_details_by_id($sid);

                // FIXED — GET STUDENT NAME CORRECTLY
                $studentObj  = User::find($sid);
                $studentName = strtolower($studentObj->name ?? '');

                $className = strtolower($details['class_name'] ?? '');

                if (
                    str_contains($studentName, strtolower($search)) ||
                    str_contains($className, strtolower($search))
                ) {
                    $studentsIdsFiltered[] = $sid;
                }
            }

            $studentsQuery->whereIn('id', $studentsIdsFiltered);
        }

        $students = $studentsQuery->paginate(20);

        return view('admin.hostel_fee_manager.list', compact('students', 'search'));
    }

    public function offlinePaymentList()
    {
        $pendingPayments = HostelFee::where('school_id', auth()->user()->school_id)
            ->where('status', 0)
            ->orderBy('fee_payment_date', 'DESC')
            ->paginate(20);

        return view('admin.hostel_fee_manager.offline_payments', compact('pendingPayments'));
    }

    public function acceptOfflinePaymentHostel($id)
    {
        $fee = HostelFee::where('status', 0)->where('school_id', auth()->user()->school_id)->findOrFail($id);

        $fee->status = 1;
        $fee->save();

        StatusChangeAudit::hostelPayment($fee, 0, 'accepted');
        return redirect()->back()->with('message', get_phrase('Offline payment accepted successfully.'));
    }
    public function rejectOfflinePaymentHostel($id)
    {
        $fee = HostelFee::where('status', 0)->where('school_id', auth()->user()->school_id)->findOrFail($id);

        $fee->status = 2;
        $fee->save();

        StatusChangeAudit::hostelPayment($fee, 0, 'rejected');
        return redirect()->back()->with('message', get_phrase('Offline payment rejected successfully.'));
    }

    public function paymentDetailsHostel($id)
    {
        $fee         = HostelFee::findOrFail($id);
        $student     = User::find($fee->student_id);
        $application = HostelApplication::where('student_id', $fee->student_id)
            ->where('status', 1)
            ->where('school_id', auth()->user()->school_id)
            ->first();

        return view('admin.hostel_fee_manager.payment_details', compact('fee', 'student', 'application'));
    }



        public function clubList(Request $request)
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

        return view('admin.club.index', compact(
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
        return view('admin.club.create_club', compact('teachers'));
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

        return redirect()->route('admin.club.index')
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
        return view('admin.club.edit_club', compact('club', 'teachers'));
    }

    public function updateClub(Request $request, $id)
    {
        $club = ClubTenancy::findClubOrFail($id);
        $request->validate(['advisor_id' => 'nullable|' . ClubTenancy::schoolUserRule()]);
        $club->update($request->all());

        return redirect()->route('admin.club.index')
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


        return view('admin.club.members', compact(
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

        return view('admin.club.add_member', compact('club', 'students'));
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

        $students = User::where('school_id', auth()->user()->school_id)->where('role_id', 7) // students; users.role does not exist
            ->where('name', 'LIKE', '%' . $request->q . '%')
            ->with(['enrollment.class', 'enrollment.section'])
            ->limit(20)
            ->get();

        $results = [];

        foreach ($students as $student) {
            $class = $student->enrollment?->class?->name ?? 'N/A';
            $section = $student->enrollment?->section?->name ?? 'N/A';

            $results[] = [
                'id' => $student->id,
                'text' => "{$student->name} | Class: {$class} | Section: {$section}"
            ];
        }

        return response()->json($results);
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

        return view('admin.club.notice.index', compact('notices', 'club'));
    }

    public function notice_create(Club $club)
    {
        ClubTenancy::assertOwned($club);

        return view('admin.club.notice.create', compact('club'));
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

    public function notice_edit($id)
    {
        $notice = ClubTenancy::findNoticeOrFail($id);
        return view('admin.club.notice.edit', compact('notice'));
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










    
    public function admitCardList()
    {
        $admit_cards = AdmitCard::where('school_id', auth()->user()->school_id)->get();

        return view('admin.examination.admit_card_list', ['admit_cards' => $admit_cards]);
    }

    public function admitCardCreate()
    {

        return view('admin.examination.admit_card_create');
    }

    public function admitCardUpload(Request $request)
    {
        $data = $request->all();

        $admitCardData = [
            'template'    => $data['template'],
            'heading'     => $data['heading'],
            'title'       => $data['title'],
            'school_id'   => auth()->user()->school_id,
            'exam_center' => $data['exam_center'],
            'footer_text' => $data['footer_text'],
        ];

        if ($request->hasFile('sign')) {
            $newFileName = SafeUpload::store($request->file('sign'), public_path('assets/upload/user-docs/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');
            $admitCardData['sign'] = $newFileName; // Add the sign filename to $admitCardData
        }

        AdmitCard::create($admitCardData);

        return redirect()->back()->with('message', 'You have successfully created an Admit Card');
    }

    public function admitCardEdit($id)
    {
        $admitCardEdit = AdmitCard::where('school_id', auth()->user()->school_id)->findOrFail($id);
        return view('admin.examination.admit_card_edit', ['admitCardEdit' => $admitCardEdit]);
    }

    public function admitCardUpdate(Request $request, $id)
    {
        $admitCard = AdmitCard::where('school_id', auth()->user()->school_id)->findOrFail($id);

        $admitCard->template = $request->template;
        $admitCard->heading = $request->heading;
        $admitCard->title = $request->title;
        $admitCard->exam_center = $request->exam_center;
        $admitCard->footer_text = $request->footer_text;


        // Check if a new image is uploaded
        if ($request->hasFile('sign')) {
            // Store the new image
            $newImage = $request->file('sign');
            $newFileName = SafeUpload::store($newImage, public_path('assets/upload/user-docs/'), SafeUpload::IMAGES) ?? abort(422, 'This file type is not allowed.');

            // Delete the old image if it exists
            if ($admitCard->sign && file_exists(public_path() . 'assets/upload/user-docs/' . $admitCard->sign)) {
                unlink(public_path() . 'assets/upload/user-docs/' . $admitCard->sign);
            }

            // Update testimonial with the new image path
            $admitCard->sign = $newFileName;
        }

        // Save changes
        $admitCard->save();

        // Redirect back or wherever needed
        return redirect()->back()->with('message', 'Admit Card Updated Successfully');
    }

    public function admitCardDelete($id)
    {
        AdmitCard::where('id', $id)->where('school_id', auth()->user()->school_id)->delete();
        return redirect()->back()->with('message', 'Delete successfully.');
    }

    public function admitCardPrint()
    {
        $page_data['admit_cards'] = AdmitCard::where('school_id', auth()->user()->school_id)->get();
        $page_data['classes'] = Classes::where('school_id', auth()->user()->school_id)->get();
        $page_data['sessions'] = Session::where('school_id', auth()->user()->school_id)->get();

        return view('admin.examination.admit_card_print', $page_data);
    }

    public function admitCardFilter(Request $request)
    {
        // Filter parameters are required; without them answer with a validation error (302 back / 422), never HTTP 500.
        $request->validate(['class_id' => 'present', 'section_id' => 'present', 'session_id' => 'present', 'admit_card_id' => 'present']);
        $data = $request->all();

        $page_data['class_id'] = $data['class_id'];
        $page_data['section_id'] = $data['section_id'];
        $page_data['session_id'] = $data['session_id'];

        // Security Phase 2G: class/section come from the request — the class must be this school's and the section that class's.
        $class = Classes::where('school_id', auth()->user()->school_id)->findOrFail($data['class_id']);
        $page_data['class_name'] = $class->name;
        $page_data['section_name'] = Section::where('class_id', $class->id)->findOrFail($data['section_id'])->name;
        $page_data['session_title'] = Session::where('school_id', auth()->user()->school_id)->findOrFail($data['session_id'])->session_title;
        $admit_cards = AdmitCard::where('school_id', auth()->user()->school_id)->get();
        $classes = Classes::where('school_id', auth()->user()->school_id)->get();
        $sessions = Session::where('school_id', auth()->user()->school_id)->get();

        $enroll_students = Enrollment::where('class_id', $page_data['class_id'])
            ->where('section_id', $page_data['section_id'])
            ->paginate(10);

        $selected_admit_card = AdmitCard::where('id', $data['admit_card_id'])->where('school_id', auth()->user()->school_id)->first();
        $page_data['classes'] = Classes::where('school_id', auth()->user()->school_id)->get();


        return view('admin.examination.admitCardFilter', [
            'enroll_students' => $enroll_students,
            'page_data' => $page_data,
            'selected_admit_card' => $selected_admit_card,
            'admit_cards' => $admit_cards,
            'classes' => $classes,
            'sessions' => $sessions
        ]);
    }
}
