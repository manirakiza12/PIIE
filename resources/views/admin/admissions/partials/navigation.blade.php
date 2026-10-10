@php
    $moduleUser = auth()->user();
    $modulePermissions = app(\App\Support\Permissions\PermissionService::class);
    $moduleLegacy = json_decode((string) $moduleUser?->menu_permission, true) ?: [];
    $moduleCan = function (string $route) use ($moduleUser, $modulePermissions, $moduleLegacy): bool {
        if (!$moduleUser || (!empty($moduleLegacy) && !in_array('admin.hei_admissions', $moduleLegacy, true))) { return false; }
        $permission = $modulePermissions->routePermission($route);
        return $permission !== null && $modulePermissions->allows($moduleUser, $permission);
    };
    $moduleLinks = [];
    if ($moduleUser && is_primary_school($moduleUser->school_id)) {
        if ($moduleCan('admin.hei_admissions.wizard.create')) { $moduleLinks[] = ['admin.hei_admissions.wizard.create', 'Create application', '', 'admin/hei-admissions/wizard/*']; }
        if ($moduleCan('admin.hei_admissions.index')) { $moduleLinks[] = ['admin.hei_admissions.index', 'Application payment status', '', '']; }
    }
    if ((int) $moduleUser?->role_id === 2 && $moduleCan('admin.hei_admissions.payment.pesapal.settings')) {
        $moduleLinks[] = ['admin.hei_admissions.payment.pesapal.settings', 'Applicant PesaPal settings', '', 'admin/applicant-pesapal-settings'];
        $moduleLinks[] = ['admin.hei_admissions.payment.pesapal.settings', 'Failed admission notifications', '#failed-notifications', ''];
    }
@endphp
@if($moduleLinks)<li class="nav-section-header">APPLICATION WORKFLOW</li>@endif
@foreach($moduleLinks as [$moduleRoute, $moduleLabel, $moduleAnchor, $moduleActive])
    <li class="nav-links-li {{ $moduleActive && request()->is($moduleActive) ? 'showMenu' : '' }}">
        <div class="iocn-link">
            <a href="{{ route($moduleRoute) }}{{ $moduleAnchor }}" class="{{ $moduleActive && request()->is($moduleActive) ? 'active' : '' }}">
                <div class="sidebar_icon"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
                <span class="link_name">{{ $moduleLabel }}</span>
            </a>
        </div>
    </li>
@endforeach
