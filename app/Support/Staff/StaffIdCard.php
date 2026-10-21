<?php

namespace App\Support\Staff;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\IdCard;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

final class StaffIdCard
{
    // Existing employee portals, including legacy Registrar and generic staff.
    private const STAFF_ROLES = [1, 2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    public static function isStaff(?User $user): bool
    {
        return $user && $user->school_id
            && in_array((int) $user->role_id, self::STAFF_ROLES, true);
    }

    public static function isActive(User $user): bool
    {
        return self::isStaff($user) && $user->account_status !== 'disable'
            && !$user->isStaffPortalBlocked()
            && !((int) $user->role_id === 20 && $user->force_password_change);
    }

    public static function verifyUrl(User $staff): string
    {
        return URL::temporarySignedRoute('staff.id_card.verify', now()->addYears(2), ['staff' => $staff->id]);
    }

    public static function data(User $staff, bool $pdf = false): array
    {
        $school = School::findOrFail($staff->school_id);
        $department = $staff->department_id
            ? Department::where('school_id', $staff->school_id)->whereKey($staff->department_id)->value('name') : null;
        $position = $staff->designation_id
            ? Designation::where('school_id', $staff->school_id)->whereKey($staff->designation_id)->value('name') : null;
        $position = $position ?: ($staff->designation ?: Role::where('role_id', $staff->role_id)->value('name')) ?: get_phrase('Staff');
        $cardNumber = $staff->code ?: 'STAFF-'.$staff->id;
        $photo = get_user_image($staff->id);
        $logo = !empty($school->school_logo)
            ? asset('assets/uploads/school_logo/'.$school->school_logo) : asset('assets/images/id_logo.png');
        $qr = QrCode::format('svg')->size(240)->margin(1)->generate(self::verifyUrl($staff));

        return [
            'school' => $school,
            'studentDetails' => ['name' => $staff->name, 'code' => $cardNumber, 'photo' => $pdf ? self::localImage($photo) : $photo],
            'cardNumber' => $cardNumber,
            'cardLogoSrc' => $pdf ? self::localImage($logo) : $logo,
            'validFor' => IdCard::validFor((int) $staff->school_id),
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr),
            'staffCard' => true,
            'staffPosition' => $position,
            'staffDepartment' => $department,
            'programme' => null,
        ];
    }

    private static function localImage(string $url): string
    {
        $path = realpath(str_replace(asset(''), public_path(''), $url));
        // Production uploads are a symlink to persistent shared storage.
        $roots = array_filter([realpath(public_path()), realpath(public_path('assets/uploads'))]);
        $allowed = $path && collect($roots)->contains(fn ($root) => str_starts_with($path, $root.DIRECTORY_SEPARATOR));
        if (!$allowed || !is_file($path)) return '';
        $mime = mime_content_type($path);
        return str_starts_with((string) $mime, 'image/') ? 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)) : '';
    }
}
