{{--
    The whole ADMISSIONS section of the admin sidebar.

    It is deliberately self-contained — it resolves its own permissions, its own
    legacy menu restriction and the authenticated school context — because two
    different layouts include it (resources/views/admin/navigation.blade.php and
    the legacy resources/views/layouts/app.blade.php) and those do not share a
    preamble.

    "APPLICATION WORKFLOW" and "ADMISSIONS" used to be two separate blocks
    describing one module: the same application queue, its intake configuration
    and its payment setup, spread across two section headers. They are merged
    here so the module is navigable in one place, and the duplicate link to the
    applications queue (the old "Application payment status" item pointed at the
    exact same URL as "Admissions") now opens that queue pre-filtered to
    applications whose fee is still pending.
--}}
@php
    $moduleUser = auth()->user();
    $modulePermissions = app(\App\Support\Permissions\PermissionService::class);
    $moduleLegacy = json_decode((string) $moduleUser?->menu_permission, true) ?: [];

    // The legacy per-user menu restriction is checked first, so an explicit
    // restriction is never widened by the RBAC layer underneath it.
    $moduleCan = function (string $route) use ($moduleUser, $modulePermissions, $moduleLegacy): bool {
        if (!$moduleUser || (!empty($moduleLegacy) && !in_array('admin.hei_admissions', $moduleLegacy, true))) {
            return false;
        }
        $permission = $modulePermissions->routePermission($route);
        return $permission !== null && $modulePermissions->allows($moduleUser, $permission);
    };

    // Internal queues, intakes and agents belong to the authenticated school.
    // Public applicants still use the separately configured public tenant.
    $moduleHasSchool = $moduleUser && $moduleUser->school_id;
    $moduleIsSchoolAdmin = (int) $moduleUser?->role_id === 2;

    // [label, url, is-active]
    $moduleLinks = [];
    if ($moduleHasSchool) {
        if ($moduleCan('admin.hei_admissions.index')) {
            $moduleLinks[] = [get_phrase('Applications'), route('admin.hei_admissions.index'), request()->is('admin/hei-admissions') && request()->query('fee_status') === null];
        }
        if ($moduleCan('admin.hei_admissions.wizard.create')) {
            $moduleLinks[] = [get_phrase('New Application'), route('admin.hei_admissions.wizard.create'), request()->is('admin/hei-admissions/wizard/*')];
        }
        // The pending-fee queue is a filter on the applications queue, not a
        // second screen: it reuses admissions.view exactly as before.
        if ($moduleCan('admin.hei_admissions.index')) {
            $moduleLinks[] = [get_phrase('Application Payments'), route('admin.hei_admissions.index', ['fee_status' => \App\Models\Admission::FEE_PENDING]), request()->is('admin/hei-admissions') && request()->query('fee_status') !== null];
        }
        if ($moduleCan('admin.intake_sessions.index')) {
            $moduleLinks[] = [get_phrase('Intake Sessions'), route('admin.intake_sessions.index'), request()->is('admin/intake-sessions*')];
        }
        // Required Documents is configuration for the application queue above,
        // so it is gated on the same permission rather than a separate one.
        if ($moduleCan('admin.admissions_documents.index')) {
            $moduleLinks[] = [get_phrase('Required Documents'), route('admin.admissions_documents.index'), request()->is('admin/admissions-documents*')];
        }
        if ($moduleCan('admin.admissions_agents.index')) {
            $moduleLinks[] = [get_phrase('Admission Agents'), route('admin.admissions_agents.index'), request()->is('admin/admissions-agents*')];
        }
    }
    if ($moduleIsSchoolAdmin && $moduleCan('admin.hei_admissions.payment.pesapal.settings')) {
        $moduleLinks[] = [get_phrase('PesaPal Settings'), route('admin.hei_admissions.payment.pesapal.settings'), request()->is('admin/applicant-pesapal-settings')];
        $moduleLinks[] = [get_phrase('Failed Payment Notifications'), route('admin.hei_admissions.payment.pesapal.settings') . '#failed-notifications', request()->is('admin/applicant-pesapal-settings')];
    }
@endphp
@if($moduleLinks)
<li class="nav-section-header">ADMISSIONS</li>
@foreach($moduleLinks as [$moduleLabel, $moduleUrl, $moduleActive])
    <li class="nav-links-li {{ $moduleActive ? 'showMenu' : '' }}">
        <div class="iocn-link">
            <a href="{{ $moduleUrl }}" class="{{ $moduleActive ? 'active' : '' }}">
                <div class="sidebar_icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
                <span class="link_name">{{ $moduleLabel }}</span>
            </a>
        </div>
    </li>
@endforeach
@endif
