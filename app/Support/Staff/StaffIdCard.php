<?php

namespace App\Support\Staff;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\IdCard;
use App\Support\PhotoProcessing;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

final class StaffIdCard
{
    // Existing employee portals, including legacy Registrar and generic staff.
    private const STAFF_ROLES = [1, 2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    // Signature asset paths (relative to public/)
    private const STAFF_SIGNATURE_PATH = 'assets/signatures/Twinamatsiko Naboth.jpg';
    private const STUDENT_SIGNATURE_PATH = 'assets/signatures/academic registrar.jpg';

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
            'staffSignatureSrc' => $pdf ? self::localImage(asset(self::STAFF_SIGNATURE_PATH)) : asset(self::STAFF_SIGNATURE_PATH),
            'studentSignatureSrc' => $pdf ? self::localImage(asset(self::STUDENT_SIGNATURE_PATH)) : asset(self::STUDENT_SIGNATURE_PATH),
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

    /**
     * Generate a new PIIE/STF/YYYY/NNN staff ID for a newly registered staff member.
     * This should be called during staff provisioning.
     */
    public static function generateStaffId(int $schoolId): string
    {
        $year = date('Y');
        $prefix = "PIIE/STF/{$year}/";

        // Use a database transaction with row locking to prevent collisions
        return \Illuminate\Support\Facades\DB::transaction(function () use ($prefix) {
            // Create or get the sequence counter for this prefix
            \Illuminate\Support\Facades\DB::table('identity_sequences')
                ->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);

            $counter = \Illuminate\Support\Facades\DB::table('identity_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->first();

            $number = (int) $counter->last_number;

            // Scan existing users for any codes with this prefix to avoid collisions
            foreach (\Illuminate\Support\Facades\DB::table('users')
                ->where('code', 'like', $prefix.'%')
                ->pluck('code') as $code) {
                if (preg_match('#^'.preg_quote($prefix, '#').'(\d+)$#', $code, $match)) {
                    $number = max($number, (int) $match[1]);
                }
            }

            ++$number;

            \Illuminate\Support\Facades\DB::table('identity_sequences')
                ->where('prefix', $prefix)
                ->update(['last_number' => $number]);

            return $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        }, 3);
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