<?php

/*
|--------------------------------------------------------------------------
| PIIE staff permission registry (RBAC Phase 3A) — read via PermissionRegistry.
| Kept in app/ (not config/, which this repository does not version).
|--------------------------------------------------------------------------
|
| The single authoritative list of staff capabilities. Every permission key
| used anywhere must be declared here; App\Support\Permissions\PermissionService
| rejects unknown keys. Keys are module.action and reflect real PIIE routes.
|
| A user's effective permissions are:
|   Super Admin (role 1) / School Admin (role 2)  → everything (explicit bypass)
|   Student / Parent                              → none (their portals use ownership rules)
|   other staff → base-role compatibility grants  (what the role can do today)
|               + direct user grants              (user_permissions, own school only)
|               + custom staff-role grants        (staff_roles, own school only)
|
| Permission never overrides tenant isolation: controllers still resolve
| every record within the user's school.
|
| 'sensitive'  → only a School Admin (or Super Admin) may grant it (Phase 3B UI).
| 'delegable'  → false means it can never be granted to staff at all; only the
|                School Admin holds it (RBAC administration, admin accounts,
|                subscription, backups, API keys).
*/

$p = fn (string $module, string $label, string $description, bool $sensitive = false, bool $delegable = true) => compact('module', 'label', 'description', 'sensitive', 'delegable');

return [

    'modules' => [
        'students' => 'Students', 'parents' => 'Parents', 'admissions' => 'Admissions', 'staff' => 'Staff',
        'admins' => 'School Administrators', 'academic' => 'Academic', 'online_exams' => 'Online Exams',
        'live_classes' => 'Live Classes',
    // Course Content, so the capability keys declared below belong to a module
    // the RBAC screens already know how to display and group. Without a
    // declaration here the permission would be unassignable through the UI.
    'course_content' => 'Course Content',
    'course_assignments' => 'Course Assignments',
    'finance' => 'Finance', 'hostel' => 'Hostel', 'library' => 'Library',
        'hr' => 'HR', 'clubs' => 'Clubs', 'communications' => 'Noticeboard & Events', 'elections' => 'Elections',
        'cms' => 'Website CMS', 'reports' => 'Reports', 'audit' => 'Audit Log', 'assets' => 'Assets',
        'procurement' => 'Procurement', 'settings' => 'Settings', 'subscription' => 'Subscription',
        'rbac' => 'Roles & Permissions',
        'system_testing' => 'System Testing',
    ],

    'permissions' => [
        // Students & parents
        'students.view' => $p('students', 'View students', 'Student lists, profiles, ID cards and exports.'),
        'students.create' => $p('students', 'Create students', 'Add student records.'),
        'students.edit' => $p('students', 'Edit students', 'Edit student records and documents.'),
        'students.delete' => $p('students', 'Delete students', 'Permanently delete student records.', true),
        'students.accounts' => $p('students', 'Student account access', 'Reset passwords and resend activation for student logins.', true),
        'students.promote' => $p('students', 'Promote students', 'Move students to the next class/session.'),
        'students.requests' => $p('students', 'Student requests', 'Handle student affairs requests (transfers, complaints, appeals).'),
        'parents.view' => $p('parents', 'View parents', 'Parent lists and profiles.'),
        'parents.manage' => $p('parents', 'Manage parents', 'Create, edit and delete parent records.'),

        // Admissions (applications and their payments are separate)
        'admissions.view' => $p('admissions', 'View applications', 'Application lists, details and exports.'),
        'admissions.create' => $p('admissions', 'Enter applications', 'Staff-entered applications, admission wizard and offline admission.'),
        'admissions.review' => $p('admissions', 'Review applications', 'Review, request corrections, notes and document review.'),
        'admissions.decide' => $p('admissions', 'Decide applications', 'Change application status (approve/reject) and issue offer letters.'),
        'admissions.delete' => $p('admissions', 'Delete applications', 'Delete applications.', true),
        'admissions.payments' => $p('admissions', 'Application payments', 'Review, request, record and waive application fees.', true),
        'admissions.settings' => $p('admissions', 'Admissions setup', 'Intake sessions, agents and document requirements.'),

        // Staff & school administrators
        'staff.view' => $p('staff', 'View staff', 'Teacher, accountant, librarian and warden lists and profiles.'),
        'staff.create' => $p('staff', 'Create staff', 'Add staff records.'),
        'staff.edit' => $p('staff', 'Edit staff', 'Edit staff records and documents.'),
        'staff.delete' => $p('staff', 'Delete staff', 'Delete staff records.', true),
        'staff.accounts' => $p('staff', 'Staff account access', 'Reset passwords and resend activation for staff logins.', true),
        'staff.teacher_assignments' => $p('staff', 'Teacher assignments', 'Assign teachers to classes, sections and programmes.'),
        // Sensitive staff records — never implied by staff.view (safe directory/profile only).
        'staff.documents.view' => $p('staff', 'View staff documents', 'Open protected staff documents (national ID, CV, certificates, contracts).', true),
        'staff.documents.upload' => $p('staff', 'Upload staff documents', 'Upload protected staff documents.', true),
        'staff.documents.verify' => $p('staff', 'Verify staff documents', 'Mark staff documents as verified or rejected.', true),
        'staff.qualifications.verify' => $p('staff', 'Verify qualifications', 'Mark staff qualifications as verified or rejected.', true),
        'staff.nin.view' => $p('staff', 'View staff NIN', 'See the full National Identification Number of staff.', true),
        'staff.nin.manage' => $p('staff', 'Record staff NIN', 'Record or replace the National Identification Number of staff.', true),
        'staff.export' => $p('staff', 'Export staff data', 'Export staff records.', true),
        'staff.audit.view' => $p('staff', 'Staff audit history', 'View the audit history of staff records.', true),
        'admins.manage' => $p('admins', 'Manage school administrators', 'Create, edit, delete and secure School Administrator accounts.', true, false),

        // Academic
        'academic.structure' => $p('academic', 'Classes, sections & subjects', 'Manage classes, sections, subjects and class rooms.'),
        'academic.structure.manage' => $p('academic', 'Academic Years & Periods', 'Create academic years and periods and set the current academic context.'),
        'academic.departments' => $p('academic', 'Departments', 'Manage departments.'),
        'academic.sessions' => $p('academic', 'Academic sessions', 'Create sessions and change the running session.', true),
        'academic.programmes' => $p('academic', 'Programmes', 'Manage HEI programmes.'),
        'academic.curriculum.view' => $p('academic', 'View Curricula', 'View Programme Curriculum versions and review summaries.'),
        'academic.curriculum.manage' => $p('academic', 'Manage Curriculum drafts', 'Create successor versions and edit draft stages, Course Units and prerequisites.'),
        'academic.curriculum.approve' => $p('academic', 'Approve and retire Curricula', 'Approve official Curricula and retire approved versions.', true),
        'academic.programme_cohort.view' => $p('academic', 'View Programme Cohorts', 'View institution Programme Cohorts and their membership history.'),
        'academic.programme_cohort.manage' => $p('academic', 'Manage Programme Cohorts', 'Create and edit draft Programme Cohorts.'),
        'academic.programme_cohort.lifecycle' => $p('academic', 'Manage Programme Cohort lifecycle', 'Activate, complete or safely cancel Programme Cohorts.', true),
        'academic.programme_cohort.membership' => $p('academic', 'Manage Programme Cohort membership', 'Assign, place, defer, resume, transfer, withdraw or complete cohort memberships.', true),
        'academic.course_offering.view' => $p('academic', 'View Course Offerings', 'View academic delivery instances.'),
        'academic.course_offering.manage' => $p('academic', 'Manage Course Offering drafts', 'Create and edit draft Offerings and Curriculum applicability.'),
        'academic.course_offering.lifecycle' => $p('academic', 'Manage Course Offering lifecycle', 'Open, start, complete or cancel Offerings.'),
        'academic.course_offering.lecturer.view' => $p('academic', 'View Offering lecturers', 'View teaching allocations and their history for Course Offerings.'),
        'academic.course_offering.lecturer.manage' => $p('academic', 'Manage Offering lecturers', 'Assign, edit, activate, end, cancel and replace Course Offering lecturers.'),
        'academic.course_registration.view' => $p('academic', 'View Course Registrations', 'View tenant-local student Course Offering registrations and their academic provenance.'),
        'academic.course_registration.manage' => $p('academic', 'Manage Course Registrations', 'Create exceptional Offering registrations and manage withdrawals.'),
        'academic.course_registration.confirm' => $p('academic', 'Confirm Course Registrations', 'Confirm tenant-local student Course Offering registrations.'),
        'academic.routine' => $p('academic', 'Class routine', 'Manage timetables.'),
        'academic.syllabus' => $p('academic', 'Syllabus', 'Manage syllabus files.'),
        'academic.attendance' => $p('academic', 'Attendance', 'Take and review daily attendance.'),
        'academic.gradebook' => $p('academic', 'Gradebook & offline exams', 'Exam categories, offline exams, marks, grades and gradebook.'),
        'academic.admit_cards' => $p('academic', 'Admit cards', 'Create and print admit cards.'),
        'academic.assignments' => $p('academic', 'Assignments', 'Manage and grade assignments.'),
        'academic.calendar' => $p('academic', 'Academic calendar', 'Manage the academic calendar.'),
        'academic.transcripts' => $p('academic', 'Transcripts', 'Search, view and print transcripts.'),
        'academic.graduation' => $p('academic', 'Graduation', 'Graduation lists and approvals.'),
        'academic.feedback' => $p('academic', 'Student feedback', 'Record feedback on students.'),

        // Online Exams — bridged to App\Support\Permissions\OnlineExamPermissionService (authoritative)
        'online_exams.view' => $p('online_exams', 'View online exams', 'See online exams.'),
        'online_exams.create' => $p('online_exams', 'Create online exams', 'Author new online exams.'),
        'online_exams.edit' => $p('online_exams', 'Edit own online exams', 'Edit exams they created.'),
        'online_exams.edit_all' => $p('online_exams', 'Edit all online exams', 'Edit any online exam in the school.'),
        'online_exams.delete' => $p('online_exams', 'Delete online exams', 'Delete online exams.'),
        'online_exams.publish' => $p('online_exams', 'Publish online exams', 'Publish exams to students.', true),
        'online_exams.cancel' => $p('online_exams', 'Cancel online exams', 'Cancel scheduled exams.'),
        'online_exams.questions' => $p('online_exams', 'Question bank', 'Manage exam questions and the question bank.'),
        'online_exams.attempts' => $p('online_exams', 'Monitor attempts', 'View student attempts.'),
        'online_exams.mark' => $p('online_exams', 'Mark answers', 'Mark and grade answers.'),
        'online_exams.results' => $p('online_exams', 'Exam results', 'View and release results.', true),
        'online_exams.settings' => $p('online_exams', 'Exam settings', 'Online exam configuration.', true),
        'online_exams.proctoring' => $p('online_exams', 'Proctoring review', 'Review proctoring events.'),

        // Live Classes — bridged to App\Policies\LiveClassPolicy (authoritative)
        // Course Content. Authoring is a Course Offering concern, so these are
    // capabilities rather than roles: a lecturer additionally needs an
    // allocation on the exact Offering (CourseContentAccess), and a student
    // additionally needs a confirmed registration - neither is satisfied by
    // holding a permission.
    'course_content.view' => $p('course_content', 'View Course Content', 'See the modules and lessons of a Course Offering.'),
    'course_content.manage' => $p('course_content', 'Manage Course Content', 'Create, edit, order and publish the modules and lessons of a Course Offering.'),
    // Course Offering Assignments. Authoring, publishing, grading and returning
    // are all gated on a current lecturer allocation in addition to these, so a
    // grant here is a CAPABILITY and never by itself an authorisation.
    'course_assignments.view' => $p('course_assignments', 'View Course Assignments', 'See the assignments of a Course Offering and the submissions against them.'),
    'course_assignments.manage' => $p('course_assignments', 'Manage Course Assignments', 'Create, edit, publish, close and grade the assignments of a Course Offering.'),

    'live_classes.view' => $p('live_classes', 'View live classes', 'See live classes and their materials.'),
        'live_classes.create' => $p('live_classes', 'Schedule live classes', 'Create live classes.'),
        'live_classes.manage_all' => $p('live_classes', 'Manage all live classes', 'Edit, cancel, publish and manage materials of any live class in the school.'),
        'live_classes.platforms' => $p('live_classes', 'Live class platforms', 'Configure Zoom / Google Meet / Jitsi integration.', true),

        // Finance (settings separated from operations)
        'finance.view' => $p('finance', 'View student fees', 'Fee invoice lists, invoices and exports.'),
        'finance.invoices' => $p('finance', 'Manage invoices', 'Create, edit and delete student fee invoices.'),
        'finance.payments' => $p('finance', 'Approve payments', 'Approve or reject offline fee payments.', true),
        'finance.fee_structures' => $p('finance', 'Fee structures', 'Manage fee structures.'),
        'finance.expenses' => $p('finance', 'Expenses', 'Manage expenses and expense categories.'),
        'finance.payroll' => $p('finance', 'Payroll', 'Generate, approve and pay payroll; salary structures.', true),
        'finance.reports' => $p('finance', 'Finance reports', 'Finance reports and exports.'),
        'finance.settings' => $p('finance', 'Payment gateway settings', 'Currency, gateway keys and offline payment instructions.', true),

        // Hostel
        'hostel.view' => $p('hostel', 'View hostels', 'Hostel, room and allocation lists.'),
        'hostel.manage' => $p('hostel', 'Manage hostels & rooms', 'Create, edit and delete hostels and rooms.'),
        'hostel.allocate' => $p('hostel', 'Room allocation', 'Allocate and vacate rooms.'),
        'hostel.applications' => $p('hostel', 'Hostel applications', 'Approve or reject hostel applications.'),
        'hostel.payments' => $p('hostel', 'Hostel fees', 'Hostel fee lists and offline hostel payment approval.'),

        // Library
        'library.view' => $p('library', 'View library', 'Book and issue lists.'),
        'library.manage_books' => $p('library', 'Manage books', 'Create, edit and delete books.'),
        'library.issue' => $p('library', 'Issue & return', 'Issue, update and return books.'),

        // HR
        'hr.designations' => $p('hr', 'Designations', 'Manage staff designations.'),
        'hr.leave' => $p('hr', 'Leave management', 'Approve, return and deny leave requests.'),
        'hr.leave_types' => $p('hr', 'Leave types', 'Manage leave types.'),
        'hr.appraisal' => $p('hr', 'Appraisal', 'Appraisal questions and feedback.'),

        // Clubs — advisor-level ownership is a separate, later rule (RBAC Phase 3C)
        'clubs.view' => $p('clubs', 'View clubs', 'Club lists.'),
        'clubs.manage' => $p('clubs', 'Manage clubs', 'Create, edit, activate and delete clubs.'),
        'clubs.members' => $p('clubs', 'Club members', 'Add, approve, reject and remove club members.'),
        'clubs.notices' => $p('clubs', 'Club notices', 'Create, edit and delete club notices.'),

        // Communications & elections
        'communications.noticeboard' => $p('communications', 'Noticeboard', 'Manage notices.'),
        'communications.events' => $p('communications', 'Events', 'Manage events.'),
        'elections.manage' => $p('elections', 'Elections', 'Create elections, positions, candidates and publish results.'),

        // Website CMS
        'cms.manage' => $p('cms', 'Manage website', 'Edit the school website pages, sections, items, settings and SEO.', true),

        // Reports & audit
        'reports.view' => $p('reports', 'Reports', 'Student, attendance and exam reports.'),
        'audit.view' => $p('audit', 'Audit log', 'View the audit log.', true),

        // Assets & procurement
        'assets.manage' => $p('assets', 'Assets', 'Manage assets and asset categories.'),
        'procurement.manage' => $p('procurement', 'Procurement', 'Manage procurement requests.'),

        // Settings & subscription
        'settings.school' => $p('settings', 'School settings', 'School, academic and notification settings.', true),
        'settings.backup' => $p('settings', 'Backups', 'Run and download backups.', true, false),
        'settings.api' => $p('settings', 'API keys', 'View and regenerate API keys.', true, false),
        'subscription.manage' => $p('subscription', 'Subscription', 'Purchase and pay for the school subscription.', true, false),

        // RBAC administration — reserved for the School Admin (Phase 3B)
        'roles.view' => $p('rbac', 'View roles', 'View staff roles and their permissions.', true, false),
        'roles.manage' => $p('rbac', 'Manage roles', 'Create, edit and delete staff roles.', true, false),
        'permissions.assign' => $p('rbac', 'Assign permissions', 'Grant and revoke staff permissions.', true, false),
        'users.assign_roles' => $p('rbac', 'Assign staff roles', 'Assign staff roles to users.', true, false),

        // System Testing: an ADDITIONAL capability, never a role of its own. A tester
        // keeps their real identity and authority (a Lecturer stays a Lecturer) and
        // users.role_id is never touched. It relaxes exactly ONE condition, the
        // pre-start DATE gate on an allocation, and only for a Course Offering that
        // was deliberately early-started through the governed workflow. It is NOT a
        // bypass of authentication, tenant isolation, Lecturer identity, allocation
        // existence or status, Offering lifecycle, or IDOR protection. See
        // App\Support\CourseOffering\SystemTesterAccess.
        //
        // Student testing is deliberately absent: there is currently no student-side
        // pre-start date gate requiring an exception, and a Student must never be
        // given a staff permission merely to label the account.
        'system.testing.prestart_lecturer' => $p('system_testing', 'Pre-start Lecturer testing', 'Allows a Lecturer who already holds a valid allocation to exercise approved teaching and testing functionality before the Academic Period start date, on a Course Offering that was deliberately started early for testing. The Lecturer keeps their Lecturer role; this does not bypass any other authorization.', true),
    ],

    /*
    | Backward compatibility: what each existing base role can do TODAY, in
    | permission terms, so no current user loses access. Derived from the
    | existing navigation map (get_role_nav_permissions(): section keys →
    | permissions below) plus each role's own portal. Module-level, because
    | the navigation map is module-level; narrowing per action is Phase 3B/3C.
    */
    'nav_sections' => [
        'students' => ['students.view', 'students.create', 'students.edit', 'students.delete', 'students.accounts', 'students.promote', 'parents.view', 'parents.manage'],
        'staff' => ['staff.view', 'staff.create', 'staff.edit', 'staff.delete', 'staff.accounts', 'staff.teacher_assignments', 'hr.designations'],
        'attendance' => ['academic.attendance'],
        'admissions' => ['admissions.create'],
        'hei_admissions' => ['admissions.view', 'admissions.create', 'admissions.review', 'admissions.decide', 'admissions.delete', 'admissions.payments'],
        'intake_sessions' => ['admissions.settings'],
        'admissions_agents' => ['admissions.settings'],
        'programmes' => ['academic.programmes'],
        'assignments' => ['academic.assignments'],
        'gradebook' => ['academic.gradebook'],
        'exams' => ['academic.gradebook', 'academic.admit_cards'],
        'results' => ['academic.gradebook'],
        'routine' => ['academic.routine'],
        'academic_calendar' => ['academic.calendar'],
        'departments' => ['academic.departments'],
        'transcripts' => ['academic.transcripts'],
        'graduation' => ['academic.graduation'],
        'noticeboard' => ['communications.noticeboard'],
        'fees' => ['finance.view', 'finance.invoices', 'finance.payments'],
        'payments' => ['finance.view', 'finance.payments'],
        'fee_structures' => ['finance.fee_structures'],
        'expenses' => ['finance.expenses'],
        'payroll' => ['finance.payroll'],
        'salary_structures' => ['finance.payroll'],
        'hostel_fee' => ['hostel.payments'],
        'reports' => ['reports.view', 'finance.reports'],
        'library' => ['library.view', 'library.manage_books', 'library.issue'],
        'leave' => ['hr.leave'],
        'leave_types' => ['hr.leave_types'],
        'appraisal' => ['hr.appraisal'],
        'procurement' => ['procurement.manage'],
        'assets' => ['assets.manage'],
        'asset_categories' => ['assets.manage'],
        'inventory' => ['assets.manage'],
        'settings' => ['settings.school', 'finance.settings'],
        // Sections with no mapped permission today (dashboard, chat, online_exams, live_classes,
        // question_bank, enrolment): those modules keep their own existing authorization.
    ],

    /* Capabilities a base role has through its own portal (outside the admin navigation map). */
    'portal_grants' => [
        3 => ['academic.syllabus', 'clubs.view', 'clubs.manage', 'clubs.members', 'clubs.notices', 'academic.feedback'],   // teacher portal
        4 => ['finance.view', 'finance.invoices', 'finance.payments', 'finance.expenses', 'communications.noticeboard'],   // accountant portal
        5 => ['library.view', 'library.manage_books', 'library.issue', 'communications.noticeboard'],                       // librarian portal
        10 => ['hostel.view', 'hostel.allocate', 'hostel.applications', 'hostel.payments', 'communications.noticeboard'],  // warden portal
    ],

    /*
    | Admin-portal route enforcement (middleware alias 'rbac' on the admin route
    | groups). Route-name patterns (Str::is), first match wins. Routes that match
    | nothing keep their existing authorization unchanged — including Online
    | Exams and Live Classes (their controllers/policies enforce permissions),
    | shared AJAX helpers (admin.class_wise_*), dashboard, profile and chat.
    */
    'routes' => [
        'admin.course_offerings.index' => 'academic.course_offering.view',
        'admin.course_offerings.create' => 'academic.course_offering.manage',
        'admin.course_offerings.show' => 'academic.course_offering.view',
        'admin.course_offerings.store' => 'academic.course_offering.manage',
        'admin.course_offerings.update' => 'academic.course_offering.manage',
        'admin.course_offerings.applicability.*' => 'academic.course_offering.manage',
        'admin.course_offerings.open' => 'academic.course_offering.lifecycle',
        'admin.course_offerings.start' => 'academic.course_offering.lifecycle',
    'admin.course_offerings.start_early' => 'academic.course_offering.lifecycle',
        'admin.course_offerings.complete' => 'academic.course_offering.lifecycle',
        'admin.course_offerings.cancel' => 'academic.course_offering.lifecycle',
        'admin.course_offerings.eligible_students' => 'academic.course_registration.view',
        'admin.course_offerings.registrations' => 'academic.course_registration.view',
        'admin.course_offerings.registrations.store' => 'academic.course_registration.manage',
        'admin.course_offerings.registrations.drop' => 'academic.course_registration.manage',
        'admin.course_offerings.registrations.bulk' => 'academic.course_registration.manage',
        'admin.course_offerings.registrations.confirm' => 'academic.course_registration.confirm',
        'admin.course_offerings.registrations.confirm_bulk' => 'academic.course_registration.confirm',
        'admin.course_offerings.lecturers.index' => 'academic.course_offering.lecturer.view',
        'admin.course_offerings.lecturers.history' => 'academic.course_offering.lecturer.view',
        'admin.course_offerings.lecturers.create' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.store' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.update' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.activate' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.end' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.cancel' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.lecturers.replace' => 'academic.course_offering.lecturer.manage',
        'admin.course_offerings.live_classes.create' => 'live_classes.create',
        'admin.course_offerings.live_classes.store' => 'live_classes.create',
        // Canonical academic year/period management (separate from class structure).
        'admin.academic_structure.*' => 'academic.structure.manage',

        'admin.programme_cohorts.index' => 'academic.programme_cohort.view',
        'admin.programme_cohorts.create' => 'academic.programme_cohort.manage',
        'admin.programme_cohorts.store' => 'academic.programme_cohort.manage',
        'admin.programme_cohorts.edit' => 'academic.programme_cohort.manage',
        'admin.programme_cohorts.update' => 'academic.programme_cohort.manage',
        'admin.programme_cohorts.show' => 'academic.programme_cohort.view',
        'admin.programme_cohorts.lifecycle' => 'academic.programme_cohort.lifecycle',
        'admin.programme_cohorts.members.*' => 'academic.programme_cohort.membership',
        'admin.programme_cohorts.placement.*' => 'academic.programme_cohort.membership',

        // Curriculum read and governance routes are specific before the manage wildcard.
        'admin.curricula.approve' => 'academic.curriculum.approve',
        'admin.curricula.retire' => 'academic.curriculum.approve',
        'admin.curricula.index' => 'academic.curriculum.view',
        'admin.curricula.show' => 'academic.curriculum.view',
        'admin.curricula.subjects.search' => 'academic.curriculum.view',
        'admin.curricula.*' => 'academic.curriculum.manage',

        // Finance
        'admin.settings.payment' => 'finance.settings',
        'admin.settings.payment_post' => 'finance.settings',
        'admin.fee_manager.list' => 'finance.view',
        'admin.fee_manager.export' => 'finance.view',
        'admin.fee_manager.pdf_print' => 'finance.view',
        'admin.studentFeeinvoice' => 'finance.view',
        'admin.fee_manager.*' => 'finance.invoices',
        'admin.create.fee_manager' => 'finance.invoices',
        'admin.edit.fee_manager' => 'finance.invoices',
        'admin.class_wise_student_invoice' => 'finance.invoices',
        'admin.offline_payment_pending' => 'finance.payments',
        'admin.update_offline_payment' => 'finance.payments',
        'admin.fee_structures.*' => 'finance.fee_structures',
        'admin.expense.*' => 'finance.expenses',
        'admin.expenses.*' => 'finance.expenses',
        'admin.expense_category.*' => 'finance.expenses',
        'admin.create.expenses' => 'finance.expenses',
        'admin.edit.expenses' => 'finance.expenses',
        'admin.create.expense_category' => 'finance.expenses',
        'admin.edit.expense_category' => 'finance.expenses',
        'admin.payroll.*' => 'finance.payroll',
        'admin.salary_structures.*' => 'finance.payroll',
        'admin.reports.finance' => 'finance.reports',

        // Hostel
        'admin.hostel.hostel_list' => 'hostel.view',
        'admin.hostel.room_list' => 'hostel.view',
        'admin.hostel.allocation_list' => 'hostel.view',
        'admin.hostel.*_hostel' => 'hostel.manage',
        'admin.hostel.*_room' => 'hostel.manage',
        'admin.hostel.*_allocation' => 'hostel.allocate',
        'admin.hostel.applications*' => 'hostel.applications',
        'admin.hostel_fee_manager.*' => 'hostel.payments',
        'admin.offline.payment.hostel.list' => 'hostel.payments',
        'admin.accept.offline.payment.hostel' => 'hostel.payments',
        'admin.reject.offline.payment.hostel' => 'hostel.payments',

        // Library
        'admin.book.book_list' => 'library.view',
        'admin.book_issue.list' => 'library.view',
        'admin.book.*' => 'library.manage_books',
        'admin.create.book' => 'library.manage_books',
        'admin.edit.book' => 'library.manage_books',
        'admin.book_issue.*' => 'library.issue',
        'admin.create.book_issue' => 'library.issue',
        'admin.edit.book_issue' => 'library.issue',

        // Admissions
        'admin.hei_admissions.payment.*' => 'admissions.payments',
        'admin.hei_admissions.status' => 'admissions.decide',
        'admin.hei_admissions.offer_letter' => 'admissions.decide',
        'admin.hei_admissions.destroy' => 'admissions.delete',
        'admin.hei_admissions.review' => 'admissions.review',
        'admin.hei_admissions.correction' => 'admissions.review',
        'admin.hei_admissions.notes' => 'admissions.review',
        'admin.hei_admissions.document.review' => 'admissions.review',
        'admin.hei_admissions.store' => 'admissions.create',
        'admin.hei_admissions.open_modal' => 'admissions.create',
        'admin.hei_admissions.wizard.*' => 'admissions.create',
        'admin.offline_admission.*' => 'admissions.create',
        'admin.hei_admissions.*' => 'admissions.view',
        'admin.intake_sessions.*' => 'admissions.settings',
        'admin.admissions_agents.*' => 'admissions.settings',
        'admin.admissions_documents.*' => 'admissions.settings',

        // Students & parents
        'admin.student.delete' => 'students.delete',
        'admin.student.reset_password' => 'students.accounts',
        'admin.student.resend_activation' => 'students.accounts',
        'admin.student.create' => 'students.create',
        'admin.student.open_modal' => 'students.create',
        'admin.student_edit_modal' => 'students.edit',
        'admin.student.update' => 'students.edit',
        'admin.student.documents' => 'students.edit',
        'admin.student' => 'students.view',
        'admin.student.*' => 'students.view',
        'admin.promotion*' => 'students.promote',
        'admin.student_requests.*' => 'students.requests',
        'admin.parent' => 'parents.view',
        'admin.parent.parent_profile' => 'parents.view',
        'admin.parent.*' => 'parents.manage',
        'admin.parent_edit_modal' => 'parents.manage',

        // Staff & HR. Staff and administrator directory pages (lists, profiles, exports) are
        // deliberately NOT mapped: Phase 2A/2B keep them open to the staff roles that use them,
        // with privileged controls hidden. Administrator-account actions stay guarded by the
        // existing school_admin middleware (admins.manage is registered for Phase 3B).
        'admin.teacher.permission*' => 'staff.teacher_assignments',
        'admin.teacher.modify_permission' => 'staff.teacher_assignments',
        'admin.teacher.programme_permission_list' => 'staff.teacher_assignments',
        'admin.teacher.modify_programme_permission' => 'staff.teacher_assignments',
        'admin.teacher.delete' => 'staff.delete',
        'admin.accountant.delete' => 'staff.delete',
        'admin.librarian.delete' => 'staff.delete',
        'admin.warden.delete' => 'staff.delete',
        'admin.teacher.reset_password' => 'staff.accounts',
        'admin.accountant.reset_password' => 'staff.accounts',
        'admin.librarian.reset_password' => 'staff.accounts',
        'admin.warden.reset_password' => 'staff.accounts',
        'admin.teacher.resend_activation' => 'staff.accounts',
        'admin.accountant.resend_activation' => 'staff.accounts',
        'admin.librarian.resend_activation' => 'staff.accounts',
        'admin.warden.resend_activation' => 'staff.accounts',
        'admin.staff.documents.download' => 'staff.documents.view',
        'admin.teacher.create' => 'staff.create',
        'admin.accountant.create' => 'staff.create',
        'admin.librarian.create' => 'staff.create',
        'admin.warden.create' => 'staff.create',
        'admin.teacher.open_modal' => 'staff.create',
        'admin.accountant.open_modal' => 'staff.create',
        'admin.librarian.open_modal' => 'staff.create',
        'admin.warden.create_form' => 'staff.create',
        'admin.teacher_edit_modal' => 'staff.edit',
        'admin.accountant_edit_modal' => 'staff.edit',
        'admin.librarian_edit_modal' => 'staff.edit',
        'admin.warden_edit_modal' => 'staff.edit',
        'admin.teacher.update' => 'staff.edit',
        'admin.accountant.update' => 'staff.edit',
        'admin.librarian.update' => 'staff.edit',
        'admin.warden.update' => 'staff.edit',
        'admin.teacher.documents' => 'staff.edit',
        'admin.accountant.documents' => 'staff.edit',
        'admin.librarian.documents' => 'staff.edit',
        'admin.warden.documents' => 'staff.edit',
        'admin.designation*' => 'hr.designations',
        'admin.create.designation' => 'hr.designations',
        'admin.edit.designation' => 'hr.designations',
        'admin.appraisal.*' => 'hr.appraisal',

        // Academic
        'admin.class_list' => 'academic.structure',
        'admin.class.*' => 'academic.structure',
        'admin.create.class' => 'academic.structure',
        'admin.edit.class' => 'academic.structure',
        'admin.edit.section' => 'academic.structure',
        'admin.section.*' => 'academic.structure',
        'admin.subject_list' => 'academic.structure',
        'admin.subject.*' => 'academic.structure',
        'admin.create.subject' => 'academic.structure',
        'admin.edit.subject' => 'academic.structure',
        'admin.class_room_list' => 'academic.structure',
        'admin.class_room.*' => 'academic.structure',
        'admin.create.class_room' => 'academic.structure',
        'admin.edit.class_room' => 'academic.structure',
        'admin.department_list' => 'academic.departments',
        'admin.department.*' => 'academic.departments',
        'admin.create.department' => 'academic.departments',
        'admin.edit.department' => 'academic.departments',
        'admin.settings.session_manager' => 'academic.sessions',
        'admin.session_manager.*' => 'academic.sessions',
        'admin.create.session' => 'academic.sessions',
        'admin.edit.session' => 'academic.sessions',
        'admin.session.*' => 'academic.sessions',
        'admin.programmes.*' => 'academic.programmes',
        'admin.routine*' => 'academic.routine',
        'admin.syllabus*' => 'academic.syllabus',
        'admin.daily_attendance*' => 'academic.attendance',
        'admin.take_attendance.*' => 'academic.attendance',
        'admin.attendance_take' => 'academic.attendance',
        'admin.attendance.student' => 'academic.attendance',
        'admin.dailyAttendanceFilter_csv' => 'academic.attendance',
        'admin.gradebook*' => 'academic.gradebook',
        'admin.marks*' => 'academic.gradebook',
        'admin.exam_mark.*' => 'academic.gradebook',
        'admin.add.exam_mark' => 'academic.gradebook',
        'admin.offline_exam*' => 'academic.gradebook',
        'admin.exam_category*' => 'academic.gradebook',
        'admin.create.exam_category' => 'academic.gradebook',
        'admin.edit.exam_category' => 'academic.gradebook',
        'admin.create.offline_exam' => 'academic.gradebook',
        'admin.edit.offline_exam' => 'academic.gradebook',
        'admin.class_wise_exam_list' => 'academic.gradebook',
        'admin.grade_list' => 'academic.gradebook',
        'admin.grade.*' => 'academic.gradebook',
        'admin.create.grade' => 'academic.gradebook',
        'admin.edit.grade' => 'academic.gradebook',
        'admin.examination.*' => 'academic.admit_cards',
        'admin.assignments.*' => 'academic.assignments',
        'admin.academic_calendar.*' => 'academic.calendar',
        'admin.transcripts.*' => 'academic.transcripts',
        'admin.graduation.*' => 'academic.graduation',
        'admin.feedback.*' => 'academic.feedback',

        // Clubs
        'admin.club.index' => 'clubs.view',
        'admin.club.members' => 'clubs.members',
        'admin.club.members.search' => 'clubs.members',
        'admin.club.students.search' => 'clubs.members',
        'admin.club.add_member' => 'clubs.members',
        'admin.club.member.*' => 'clubs.members',
        'admin.club.notice*' => 'clubs.notices',
        'admin.club.*' => 'clubs.manage',

        // Communications, elections, CMS
        'admin.noticeboard.*' => 'communications.noticeboard',
        'admin.create.noticeboard' => 'communications.noticeboard',
        'admin.edit.noticeboard' => 'communications.noticeboard',
        'admin.events.*' => 'communications.events',
        'admin.event.*' => 'communications.events',
        'admin.create.event' => 'communications.events',
        'admin.edit.event' => 'communications.events',
        'admin.elections.*' => 'elections.manage',
        'admin.website.*' => 'cms.manage',

        // Reports, audit, assets, procurement
        'admin.reports.*' => 'reports.view',
        'admin.audit_log.*' => 'audit.view',
        'admin.assets.*' => 'assets.manage',
        'admin.asset_categories.*' => 'assets.manage',
        'admin.procurement.*' => 'procurement.manage',

        // Settings, subscription, RBAC administration
        'admin.settings.school' => 'settings.school',
        'admin.school.update' => 'settings.school',
        'admin.settings.academic*' => 'settings.school',
        'admin.settings.notifications*' => 'settings.school',
        'admin.settings.backup*' => 'settings.backup',
        'admin.settings.api*' => 'settings.api',
        'admin.settings.permissions' => 'roles.view',
        'admin.rbac.roles.index' => 'roles.view',
        'admin.rbac.roles.show' => 'roles.view',
        'admin.rbac.roles.*' => 'roles.manage',
        'admin.rbac.staff.permissions.*' => 'permissions.assign',
        'admin.rbac.staff.*' => 'users.assign_roles',
        // Staff record management (HR profile), NOT access governance. Editing a
        // designation is therefore gated by staff.edit and never by
        // users.assign_roles, and holding it grants no permission whatsoever.
        'admin.staff.profile.show' => 'staff.view',
        'admin.staff.profile.edit' => 'staff.edit',
        'admin.staff.profile.update' => 'staff.edit',
        'admin.staff.profile.status' => 'staff.edit',
        // The governed account setup workflow, for any staff base role. Uses the
        // platform's own staff.accounts permission, so a password setup link is
        // an account concern and is not tied to access governance.
        'admin.staff.account-access.*' => 'staff.accounts',
        'admin.settings.permissions.save' => 'roles.manage',
        'admin.subscription*' => 'subscription.manage',
        'admin_free_subcription' => 'subscription.manage',
        'admin.admin_subscription_offline_payment' => 'subscription.manage',
    ],

    /* RBAC key → OnlineExamPermissionService key (the exam module's authoritative names). */
    'online_exam_keys' => [
        'online_exams.view' => 'view_online_exams',
        'online_exams.create' => 'create_online_exams',
        'online_exams.edit' => 'edit_own_online_exams',
        'online_exams.edit_all' => 'edit_all_online_exams',
        'online_exams.delete' => 'delete_online_exams',
        'online_exams.publish' => 'publish_online_exams',
        'online_exams.cancel' => 'cancel_online_exams',
        'online_exams.questions' => 'manage_exam_questions',
        'online_exams.attempts' => 'view_exam_attempts',
        'online_exams.mark' => 'mark_exam_answers',
        'online_exams.results' => 'view_exam_results',
        'online_exams.settings' => 'manage_exam_settings',
        'online_exams.proctoring' => 'review_exam_proctoring',
    ],

    /*
    | RBAC Phase 3B — permission dependencies. An action is useless (and its
    | pages unreachable) without its module's view permission, so granting the
    | action also grants the view, and revoking the view also revokes the
    | actions that depend on it. Only non-sensitive view permissions are ever
    | added this way, so a dependency never escalates privilege.
    */
    'requires' => [
        'academic.curriculum.manage' => ['academic.curriculum.view'],
        'academic.curriculum.approve' => ['academic.curriculum.view'],
        'academic.programme_cohort.manage' => ['academic.programme_cohort.view'],
        'academic.programme_cohort.lifecycle' => ['academic.programme_cohort.view'],
        'academic.programme_cohort.membership' => ['academic.programme_cohort.view'],
        'academic.course_offering.manage' => ['academic.course_offering.view'],
        'academic.course_offering.lifecycle' => ['academic.course_offering.view'],
        'academic.course_offering.lecturer.manage' => ['academic.course_offering.lecturer.view'],
        'academic.course_registration.manage' => ['academic.course_registration.view'],
        'academic.course_registration.confirm' => ['academic.course_registration.view'],
        'students.create' => ['students.view'], 'students.edit' => ['students.view'], 'students.delete' => ['students.view'],
        'students.accounts' => ['students.view'], 'students.promote' => ['students.view'], 'students.requests' => ['students.view'],
        'parents.manage' => ['parents.view'],
        'admissions.create' => ['admissions.view'], 'admissions.review' => ['admissions.view'], 'admissions.decide' => ['admissions.view'],
        'admissions.delete' => ['admissions.view'], 'admissions.payments' => ['admissions.view'],
        'staff.create' => ['staff.view'], 'staff.edit' => ['staff.view'], 'staff.delete' => ['staff.view'],
        'staff.accounts' => ['staff.view'], 'staff.teacher_assignments' => ['staff.view'],
        'staff.documents.view' => ['staff.view'], 'staff.documents.upload' => ['staff.view'], 'staff.documents.verify' => ['staff.documents.view'],
        'staff.qualifications.verify' => ['staff.view'], 'staff.nin.view' => ['staff.view'], 'staff.nin.manage' => ['staff.view'],
        'staff.export' => ['staff.view'], 'staff.audit.view' => ['staff.view'],
        'online_exams.create' => ['online_exams.view'], 'online_exams.edit' => ['online_exams.view'], 'online_exams.edit_all' => ['online_exams.view'],
        'online_exams.delete' => ['online_exams.view'], 'online_exams.publish' => ['online_exams.view'], 'online_exams.cancel' => ['online_exams.view'],
        'online_exams.questions' => ['online_exams.view'], 'online_exams.attempts' => ['online_exams.view'], 'online_exams.mark' => ['online_exams.view'],
        'online_exams.results' => ['online_exams.view'], 'online_exams.settings' => ['online_exams.view'], 'online_exams.proctoring' => ['online_exams.view'],
        'course_content.manage' => ['course_content.view'],
    'course_assignments.manage' => ['course_assignments.view'],
        'live_classes.create' => ['live_classes.view'], 'live_classes.manage_all' => ['live_classes.view'], 'live_classes.platforms' => ['live_classes.view'],
        'finance.invoices' => ['finance.view'], 'finance.payments' => ['finance.view'],
        'hostel.manage' => ['hostel.view'], 'hostel.allocate' => ['hostel.view'], 'hostel.applications' => ['hostel.view'], 'hostel.payments' => ['hostel.view'],
        'library.manage_books' => ['library.view'], 'library.issue' => ['library.view'],
        'clubs.manage' => ['clubs.view'], 'clubs.members' => ['clubs.view'], 'clubs.notices' => ['clubs.view'],
    ],

    /*
    | RBAC Phase 3B — suggested starting points for custom staff roles. Built
    | only from delegable registry permissions and never including a
    | sensitive one: the School Admin reviews/extends them before saving, and
    | sensitive permissions (e.g. finance.settings, online_exams.publish) must
    | always be ticked deliberately. Templates are not roles and never create
    | anything by themselves.
    */
    'templates' => [
        'admissions_officer' => ['name' => 'Admissions Officer', 'description' => 'Receives, reviews and decides applications for this school.',
            'permissions' => ['admissions.view', 'admissions.create', 'admissions.review', 'admissions.decide', 'admissions.settings']],
        'examinations_officer' => ['name' => 'Examinations Officer', 'description' => 'Prepares, marks and monitors online exams for this school.',
            'permissions' => ['online_exams.view', 'online_exams.create', 'online_exams.edit', 'online_exams.edit_all', 'online_exams.questions', 'online_exams.attempts', 'online_exams.mark', 'online_exams.proctoring', 'academic.gradebook', 'academic.admit_cards']],
        'academic_registrar' => ['name' => 'Academic Registrar', 'description' => 'Maintains student records, programmes, transcripts and graduation.',
            'permissions' => ['students.view', 'students.create', 'students.edit', 'students.promote', 'students.requests', 'academic.programmes', 'academic.transcripts', 'academic.graduation']],
        'finance_officer' => ['name' => 'Finance Officer', 'description' => 'Handles student fee invoices, fee structures, expenses and finance reports.',
            'permissions' => ['finance.view', 'finance.invoices', 'finance.fee_structures', 'finance.expenses', 'finance.reports']],
        'hr_officer' => ['name' => 'HR Officer', 'description' => 'Handles staff records, leave, designations and appraisal.',
            'permissions' => ['staff.view', 'staff.create', 'staff.edit', 'hr.designations', 'hr.leave', 'hr.leave_types', 'hr.appraisal']],
        'hostel_officer' => ['name' => 'Hostel Officer', 'description' => 'Manages hostels, rooms, allocations and hostel applications.',
            'permissions' => ['hostel.view', 'hostel.manage', 'hostel.allocate', 'hostel.applications', 'hostel.payments']],
        'library_officer' => ['name' => 'Library Officer', 'description' => 'Manages books and book issues.',
            'permissions' => ['library.view', 'library.manage_books', 'library.issue']],
        'live_classes_coordinator' => ['name' => 'Live Classes Coordinator', 'description' => 'Schedules and manages live classes across the school.',
            'permissions' => ['live_classes.view', 'live_classes.create', 'live_classes.manage_all']],
        'cms_manager' => ['name' => 'CMS Manager', 'description' => 'Maintains the school website. Tick "Manage website" deliberately: it is sensitive.',
            'permissions' => []],
    ],
];
