<?php

namespace App\Support\Payments;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Restricts opening-balance cash entries to portal-onboarded or provable legacy students. */
final class ExistingStudentPriorPaymentEligibility
{
    /** The marker was introduced by 2026_10_21_060000_create_student_opening_payments. */
    private const MARKER_INTRODUCED_AT = '2026-10-21 00:00:00';

    public function allows(User $student, int $schoolId): bool
    {
        if ((int) $student->school_id !== $schoolId || (int) $student->role_id !== 7) {
            return false;
        }

        $profile = StudentProfile::where('user_id', $student->id)
            ->where('school_id', $schoolId)
            ->first();

        if (! $profile) {
            return false;
        }

        if (Schema::hasColumn('student_profiles', 'was_existing_at_portal_creation')
            && (bool) $profile->was_existing_at_portal_creation) {
            return true;
        }

        // Legacy profiles predate the marker. Exclude known applicant conversions and
        // issued admission letters; finance permission and receipt verification still apply.
        if (! $profile->created_at || $profile->created_at->gte(self::MARKER_INTRODUCED_AT)) {
            return false;
        }

        if (Schema::hasTable('applicants') && DB::table('applicants')
            ->where('school_id', $schoolId)->where('converted_user_id', $student->id)->exists()) {
            return false;
        }

        if (Schema::hasTable('admission_letters') && DB::table('admission_letters')
            ->where('student_id', $student->id)->exists()) {
            return false;
        }

        return true;
    }
}
