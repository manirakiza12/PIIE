<?php
namespace App\Support\Settings;

use App\Models\User;
use App\Support\Permissions\PermissionService;

final class SettingsNavigation
{
    public function items(User $user): array
    {
        $permissions = app(PermissionService::class);
        $legacy = json_decode((string) $user->menu_permission, true) ?: [];
        $items = [
            ['general', 'General Settings', 'admin.settings.school', 'bi-gear'],
            ['notifications', 'Notifications', 'admin.settings.notifications', 'bi-bell'],
            ['platforms', 'Teaching Platforms', 'admin.settings.teaching_platforms', 'bi-camera-video'],
            ['permissions', 'Advanced Permissions', 'admin.settings.permissions', 'bi-shield-check'],
            ['backup', 'Backup', 'admin.settings.backup', 'bi-cloud-arrow-down'],
            ['api', 'API Access', 'admin.settings.api', 'bi-code-square'],
        ];
        return array_values(array_filter(array_map(function ($item) use ($permissions, $user, $legacy) {
            [$key, $label, $route, $icon] = $item;
            $required = $permissions->routePermission($route);
            if (!$required || !$permissions->allows($user, $required)) return null;
            if ($legacy && !array_intersect(['admin.settings.school', $route], $legacy)) return null;
            return compact('key', 'label', 'route', 'icon');
        }, $items)));
    }
}
