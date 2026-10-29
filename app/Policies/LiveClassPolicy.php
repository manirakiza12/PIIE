<?php

namespace App\Policies;

use App\Models\LiveClass;
use App\Models\User;
use App\Support\Permissions\PermissionService;

class LiveClassPolicy
{
    /** Base-role rules, unchanged; also read by PermissionService for the live_classes.* permissions. */
    public const STAFF_ROLES = [1, 2, 3, 4, 5, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];
    public const CREATE_ROLES = [1, 2, 3, 10, 12, 14];
    public const MANAGE_ALL_ROLES = [1, 2, 10, 12, 14];
    public const PLATFORM_ROLES = [1, 2, 14];

    public function viewAny(User $user): bool
    {
        if ((int) $user->role_id === 7) {
            return true;
        }

        return $this->hasAllocatedTeachingAccess($user)
            || ($this->isStaff($user) && $this->hasMenuPermission($user, 'admin.live_classes'));
    }

    public function view(User $user, LiveClass $liveClass): bool
    {
        if ((int) $user->school_id !== (int) $liveClass->school_id) {
            return false;
        }

        if ((int) $user->role_id === 7) {
            return (bool) $liveClass->is_published;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        if ($this->hasAllocatedTeachingAccess($user)) {
            return true;
        }
        if ((int) $user->role_id === 7) {
            return false;
        }

        if (!$this->isStaff($user) || !$this->hasMenuPermission($user, 'admin.live_classes')) {
            return false;
        }

        return in_array((int) $user->role_id, self::CREATE_ROLES, true) || $this->granted($user, 'live_classes.create');
    }

    public function update(User $user, LiveClass $liveClass): bool
    {
        if ((int) $user->school_id !== (int) $liveClass->school_id) {
            return false;
        }

        if (!$this->isStaff($user) || !$this->hasMenuPermission($user, 'admin.live_classes')) {
            return false;
        }

        if ($this->canManageAll($user)) {
            return true;
        }

        return (int) $user->id === (int) ($liveClass->teacher_id ?: $liveClass->created_by);
    }

    public function delete(User $user, LiveClass $liveClass): bool
    {
        return $this->update($user, $liveClass);
    }

    public function cancel(User $user, LiveClass $liveClass): bool
    {
        return $this->update($user, $liveClass);
    }

    public function publish(User $user, LiveClass $liveClass): bool
    {
        return $this->update($user, $liveClass);
    }

    public function join(User $user, LiveClass $liveClass): bool
    {
        if ((int) $user->school_id !== (int) $liveClass->school_id) {
            return false;
        }

        if ((int) $user->role_id === 7) {
            return (bool) $liveClass->is_published;
        }

        return $this->viewAny($user);
    }

    public function managePlatforms(User $user): bool
    {
        if (!$this->isStaff($user) || !$this->hasMenuPermission($user, 'admin.setting')) {
            return false;
        }

        return in_array((int) $user->role_id, self::PLATFORM_ROLES, true) || $this->granted($user, 'live_classes.platforms');
    }

    private function isStaff(User $user): bool
    {
        return in_array((int) $user->role_id, self::STAFF_ROLES, true)
            && $user->account_status !== 'disable';
    }

    /** A valid teaching appointment supplies access without a separate menu grant. */
    private function hasAllocatedTeachingAccess(User $user): bool
    {
        if ((int) $user->role_id !== 3 || ! $this->isStaff($user)
            || ! \Illuminate\Support\Facades\Schema::hasTable('course_offering_lecturer_allocations')) {
            return false;
        }
        $offerings = \App\Models\CourseOffering::query()->where('school_id', $user->school_id)
            ->whereIn('status', [\App\Models\CourseOffering::STATUS_OPEN, \App\Models\CourseOffering::STATUS_IN_PROGRESS])
            ->whereHas('lecturerAllocations', fn ($query) => $query
                ->where('school_id', $user->school_id)->where('user_id', $user->id)->where('status', 'active'))
            ->get();
        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        return $offerings->contains(fn ($offering) => $access->canLecturerCreateForOffering($user, $offering));
    }

    private function canManageAll(User $user): bool
    {
        return in_array((int) $user->role_id, self::MANAGE_ALL_ROLES, true) || $this->granted($user, 'live_classes.manage_all');
    }

    /** RBAC Phase 3A: a delegated grant (own school only) is one more way in; school checks above still apply. */
    private function granted(User $user, string $key): bool
    {
        return app(PermissionService::class)->hasGrant($user, $key);
    }

    private function hasMenuPermission(User $user, string $routeKey): bool
    {
        if (empty($user->menu_permission) || $user->menu_permission === 'null') {
            return true;
        }

        $permissions = json_decode($user->menu_permission, true);
        if (!is_array($permissions)) {
            return false;
        }

        return in_array($routeKey, $permissions, true);
    }
}
