<?php

namespace App\Support\Curriculum;

use App\Models\User;
use App\Support\Permissions\PermissionService;

final class CurriculumOwnerAccess
{
    public static function allows(?User $user, int $schoolId): bool
    {
        return $user && (int) $user->school_id === $schoolId
            && in_array((int) $user->role_id, [PermissionService::SUPER_ADMIN, PermissionService::SCHOOL_ADMIN], true)
            && app(PermissionService::class)->allows($user, 'academic.curriculum.approve');
    }
}
