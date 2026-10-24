<?php

namespace App\Support;

use App\Models\Applicant;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one place a canonical student account is created, regardless of
 * whether it originates from an accepted online Admission or an
 * administrator enrolling one directly (staff-entry Admission, or the
 * legacy single-admission form). Both callers must produce the same shape
 * of student: a `users` row, an `enrollment` row, and a `student_profiles`
 * row — a student must not behave differently downstream merely because of
 * how they were admitted.
 *
 * Idempotent and transactional, so a retried/duplicated call (a re-submitted
 * status-update request, a double click on "Enrol") never creates duplicate
 * accounts, profiles or enrolments:
 *  - the User is looked up by email first; only created if missing.
 *  - the Enrollment is created only if the student does not already have
 *    one (one canonical enrolment per student — matches `User::enrollment()`
 *    being a hasOne elsewhere in the app).
 *  - the StudentProfile is upserted (updateOrCreate keyed on user_id).
 *  - the fee invoice generator dedupes internally on its own keys.
 * Credentials are only generated/emailed the first time the User row is
 * actually created, never on a subsequent idempotent call.
 */
class StudentProvisioningService
{
    /**
     * @param array{
     *   school_id: int,
     *   name?: string,
     *   first_name?: string,
     *   last_name?: string,
     *   email: string,
     *   password?: string,
     *   gender?: string,
     *   birthday?: string,
     *   phone?: string,
     *   address?: string,
     *   photo?: string,
     *   class_id?: int,
     *   section_id?: int,
     *   department_id?: int,
     *   session_id?: int,
     *   programme_id?: int,
     *   intake_session_id?: int,
     *   nationality?: string,
     *   applicant_id?: int,
     * } $data
     *
     * @return array{
     *   student: ?User,
     *   created: bool,
     *   enrollment: ?Enrollment,
     *   profile: ?StudentProfile,
     *   plain_password: ?string,
     *   error: ?string,
     * }
     */
    public static function provision(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $existingUser = User::where('email', $data['email'])->first();

            // Email already belongs to a non-student account — do not touch
            // it automatically; the caller decides how to surface this.
            if ($existingUser && (int) $existingUser->role_id !== 7) {
                return [
                    'student'        => null,
                    'created'        => false,
                    'enrollment'     => null,
                    'profile'        => null,
                    'plain_password' => null,
                    'error'          => 'email_taken_by_non_student',
                ];
            }

            $student       = $existingUser;
            $plainPassword = null;
            $created       = false;

            if (! $student) {
                $plainPassword = $data['password'] ?? Str::random(10);
                $created       = true;

                $name = $data['name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

                $student = User::create([
                    'name'                   => $name,
                    'email'                  => $data['email'],
                    'password'               => Hash::make($plainPassword),
                    'code'                   => RegistrationNumber::student($data),
                    'role_id'                => 7, // student
                    'school_id'              => $data['school_id'],
                    'account_status'         => 'active',
                    'status'                 => 1,
                    'force_password_change'  => true,
                    'user_information'       => json_encode([
                        'gender'      => $data['gender'] ?? null,
                        'birthday'    => $data['birthday'] ?? null,
                        'phone'       => $data['phone'] ?? null,
                        'address'     => $data['address'] ?? null,
                        'photo'       => $data['photo'] ?? '',
                    ]),
                ]);
            }

            // One canonical enrolment per student. Only created when the
            // caller supplied a complete academic assignment (class,
            // section, department, session) — a partial assignment is never
            // enough to satisfy `enrollment`'s NOT NULL columns, so it is
            // simply skipped rather than written incomplete.
            $enrollment = null;

            if (! empty($data['class_id']) && ! empty($data['section_id'])
                && ! empty($data['department_id']) && ! empty($data['session_id'])
            ) {
                $enrollment = Enrollment::firstOrCreate(
                    ['user_id' => $student->id],
                    [
                        'class_id'      => $data['class_id'],
                        'section_id'    => $data['section_id'],
                        'school_id'     => $data['school_id'],
                        'department_id' => $data['department_id'],
                        'session_id'    => $data['session_id'],
                    ]
                );
            }

            $profile = StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'school_id'         => $data['school_id'],
                    'first_name'        => $data['first_name'] ?? null,
                    'last_name'         => $data['last_name'] ?? null,
                    'programme_id'      => $data['programme_id'] ?? null,
                    'intake_session_id' => $data['intake_session_id'] ?? null,
                    'nationality'       => $data['nationality'] ?? null,
                ]
            );

            if (! empty($data['programme_id'])) {
                StudentFeeInvoiceGenerator::generateForStudent($student, $data['programme_id'], $data['school_id']);
            }

            // Link the portal account to the student it became, so the
            // applicant portal can point them at the student login rather
            // than leaving them on a finished application with nowhere to
            // go. Never touched for staff-entry admissions (no applicant_id).
            if (! empty($data['applicant_id'])) {
                Applicant::where('id', $data['applicant_id'])->update(['converted_user_id' => $student->id]);
            }

            return [
                'student'        => $student,
                'created'        => $created,
                'enrollment'     => $enrollment,
                'profile'        => $profile,
                'plain_password' => $plainPassword,
                'error'          => null,
            ];
        });
    }
}
