<?php

namespace App\Support\Staff;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\IdCard;
use App\Support\PhotoProcessing;
use App\Support\RegistrationNumber;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

final class StaffIdCard
{
    // Existing employee portals, including legacy Registrar and generic staff.
    private const STAFF_ROLES = [1, 2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    // Signature asset paths (relative to public/)
    private const AUTHORIZED_SIGNATURE_PATH = 'assets/signatures/academic registrar.jpg';

    public static function authorizedSignaturePath(): string
    {
        return self::AUTHORIZED_SIGNATURE_PATH;
    }

    public static function authorizedSignatureAsset(bool $pdf = false): ?string
    {
        $url = asset(self::AUTHORIZED_SIGNATURE_PATH);
        return $pdf ? self::localImage($url) : $url;
    }

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

    public static function generateStaffId(int $schoolId, int $roleId = 3): string
    {
        return RegistrationNumber::staff($roleId);
    }

    public static function data(User $staff, bool $pdf = false): array
    {
        $school = School::findOrFail($staff->school_id);
        $department = $staff->department_id
            ? Department::where('school_id', $staff->school_id)->whereKey($staff->department_id)->value('name') : null;
        $position = $staff->designation_id
            ? Designation::where('school_id', $staff->school_id)->whereKey($staff->designation_id)->value('name') : null;
        $position = $position ?: ($staff->designation ?: Role::where('role_id', $staff->role_id)->value('name')) ?: get_phrase('Staff');
        $cardNumber = self::formatStaffCardNumber($staff);
        $photo = $pdf
            ? PhotoProcessing::getProcessedPhotoDataUri($staff->id)
            : PhotoProcessing::getProcessedPhotoUrl($staff->id);
        $logo = !empty($school->school_logo)
            ? asset('assets/uploads/school_logo/'.$school->school_logo) : asset('assets/images/id_logo.png');
        $qr = QrCode::format('svg')->size(240)->margin(1)->generate(self::verifyUrl($staff));

        return [
            'school' => $school,
            'studentDetails' => ['name' => $staff->name, 'code' => $cardNumber, 'photo' => $photo],
            'cardNumber' => $cardNumber,
            'cardLogoSrc' => $pdf ? self::localImage($logo) : $logo,
            'validFor' => IdCard::validFor((int) $staff->school_id),
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr),
            'staffCard' => true,
            'staffPosition' => $position,
            'staffDepartment' => $department,
            'programme' => null,
            'holderSignatureSrc' => null,
            'holderSignatureLabel' => get_phrase('Staff Signature'),
            'authorizedSignatureSrc' => self::authorizedSignatureAsset($pdf),
            'authorizedSignatureLabel' => get_phrase('Authorized Signature'),
        ];
    }

    /**
     * Format the staff card number for display.
     * Uses the PIIE/STF/YYYY/NNN format if available, otherwise falls back to stored code.
     */
    public static function formatStaffCardNumber(User $staff): string
    {
        // If the user already has a code in the new format, use it
        if ($staff->code && preg_match('/^PIIE\/STF\/\d{4}\/\d{3}$/', $staff->code)) {
            return $staff->code;
        }

        // If the user has a legacy code, use it but don't overwrite
        if ($staff->code) {
            return $staff->code;
        }

        // Fallback for users without any code
        return 'PIIE/STF/'.date('Y').'/'.str_pad((string) $staff->id, 3, '0', STR_PAD_LEFT);
    }

    private static function localImage(string $url): string
    {
        if ($url === '' || str_starts_with($url, 'data:')) return $url;

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return '';

        $relative = ltrim($path, '/');
        $candidates = [];
        // Deployments may serve the application from a subdirectory, so anchor
        // the path on the public assets directory rather than the URL root.
        if (($assetsAt = strpos($relative, 'assets/')) !== false) {
            $candidates[] = public_path(substr($relative, $assetsAt));
        }
        $candidates[] = public_path($relative);

        // Production uploads are a symlink to persistent shared storage.
        $roots = array_filter([realpath(public_path()), realpath(public_path('assets/uploads'))]);
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if (!$resolved || !is_file($resolved)) continue;

            $allowed = collect($roots)->contains(fn ($root) => str_starts_with($resolved, $root.DIRECTORY_SEPARATOR));
            if (!$allowed) continue;

            $mime = mime_content_type($resolved);
            if (str_starts_with((string) $mime, 'image/')) {
                return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($resolved));
            }
        }

        return '';
    }
}
