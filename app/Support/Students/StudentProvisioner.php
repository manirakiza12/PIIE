<?php

namespace App\Support\Students;

use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates existing-student portal accounts and profiles without academic placement or billing. */
final class StudentProvisioner
{
    /**
     * @param  array  $data  Already-validated studentCreate-shaped payload.
     * @param  int    $schoolId
     * @return array{user:User, password:string}
     *
     * @throws ValidationException when the email is already taken
     */
    public static function provision(array $data, int $schoolId, bool $sendWelcomeEmail = true, ?callable $afterProvision = null): array
    {
        $email = trim((string) $data['email']);

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Email was already taken.']);
        }

        return DB::transaction(function () use ($data, $schoolId, $email, $afterProvision) {
            // Serialize registration checks for concurrent onboarding in this school.
            \App\Models\School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            self::assertAcademicScope($data, $schoolId);
            $registrationNumber = trim((string) ($data['registration_number'] ?? ''));
            if ($registrationNumber === '' || strlen($registrationNumber) > 255) {
                throw ValidationException::withMessages(['registration_number' => 'Provide the existing registration number (maximum 255 characters).']);
            }
            if (User::where('school_id', $schoolId)->whereRaw('LOWER(code) = ?', [strtolower($registrationNumber)])->exists()) {
                throw ValidationException::withMessages(['registration_number' => 'This registration number is already registered in this school. Use the existing account activation action.']);
            }
            // Portal password: administrator either chooses one, or it is
            // generated. Never logged or exported.
            $passwordOption = $data['password_option'] ?? 'manual';
            $plainPassword  = ($passwordOption === 'auto' || empty($data['password']))
                ? Str::random(10)
                : $data['password'];

            $student = User::create([
                'name'             => $data['name'],
                'first_name'       => $data['first_name'] ?? null,
                'last_name'        => $data['last_name'] ?? null,
                'email'            => $email,
                'password'         => Hash::make($plainPassword),
                'code'             => $registrationNumber,
                'role_id'          => '7',
                'school_id'        => $schoolId,
                'user_information' => $data['user_information'] ?? json_encode([]),
                'status'           => 1,
                'account_status'   => 'active',
                'force_password_change' => true,
            ]);

            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'school_id'               => $schoolId,
                    // This provisioner is used only by the admin's existing-student add/import flows.
                    'was_existing_at_portal_creation' => true,
                    'first_name'              => $data['first_name'] ?? null,
                    'last_name'               => $data['last_name'] ?? null,
                    'programme_id'            => $data['programme_id'] ?? null,
                    'intake_session_id'       => $data['intake_session_id'] ?? null,
                    'year_of_study'           => $data['year_of_study'] ?? null,
                    'nationality'             => $data['nationality'] ?? null,
                    'national_id_or_passport' => $data['national_id_or_passport'] ?? null,
                    'next_of_kin_name' => $data['next_of_kin_name'] ?? null,
                    'next_of_kin_address'     => $data['next_of_kin_address'] ?? null,
                    'next_of_kin_contact'     => $data['next_of_kin_contact'] ?? null,
                    'additional_image'        => $data['additional_image'] ?? null,
                    'status'                  => $data['status'] ?? 'active',
                    ...StudentProfile::affiliationAttributes($data),
                ]
            );

            if ($afterProvision) {
                $afterProvision($student);
            }

            return ['user' => $student, 'password' => $plainPassword];
        });
    }

    /**
     * Emailed only after the transaction commits, and only when SMTP is actually
     * configured — a mail transport failure must never roll back a created
     * student or abort a bulk import half way.
     */
    public static function sendWelcomeEmail(User $student, string $plainPassword): bool
    {
        $profile = StudentProfile::where('user_id', $student->id)->first();
        return \App\Support\StudentPortalActivation::sendActivationEmail(
            $student, $plainPassword, $profile?->programme_id, $profile?->intake_session_id, true, passwordSetupOnly: true
        );
    }

    /**
     * A section may only be used with the class it belongs to. Applied by the
     * single-add form and the import alike.
     */
    public static function assertSectionBelongsToClass(int $sectionId, int $classId): void
    {
        if ($sectionId > 0 && ! Section::where('id', $sectionId)->where('class_id', $classId)->exists()) {
            throw ValidationException::withMessages([
                'section_id' => 'The selected section does not belong to the selected class.',
            ]);
        }
    }

    /**
     * Programme, department and class must all belong to the importing school.
     * Guards a spreadsheet that was copied between tenants.
     */
    public static function assertAcademicScope(array $data, int $schoolId): void
    {
        foreach (['programme_id', 'class_id', 'department_id', 'intake_session_id'] as $field) {
            $value = $data[$field] ?? null;
            if (blank($value)) {
                continue;
            }

            $table = match ($field) {
                'programme_id'  => 'programmes',
                'class_id'      => 'classes',
                'department_id' => 'departments',
                default         => 'intake_sessions',
            };

            $exists = \Illuminate\Support\Facades\DB::table($table)
                ->where('id', (int) $value)->where('school_id', $schoolId)->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    $field => "The selected {$field} does not belong to this school.",
                ]);
            }
        }

        // `sections` carries no school_id; it belongs to a class, so it is scoped
        // through the class instead of being filtered on a column that does not exist.
        $sectionId = $data['section_id'] ?? null;
        if (filled($sectionId)) {
            $sectionOk = \Illuminate\Support\Facades\DB::table('sections')
                ->join('classes', 'sections.class_id', '=', 'classes.id')
                ->where('sections.id', (int) $sectionId)
                ->where('classes.school_id', $schoolId)
                ->exists();

            if (! $sectionOk) {
                throw ValidationException::withMessages([
                    'section_id' => 'The selected section does not belong to this school.',
                ]);
            }
        }
    }
}
