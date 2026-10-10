<?php

namespace App\Support\Students;

use App\Helpers\CommonController;
use App\Models\Enrollment;
use App\Models\Programme;
use App\Models\Section;
use App\Models\Session;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\EnrollmentDefaults;
use App\Support\StudentFeeInvoiceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a student account, its academic profile and its enrolment.
 *
 * Extracted from AdminController@studentCreate so the single-add form and the
 * CSV bulk import provision students identically. Duplicating this logic across
 * the two entry points is how a student added by hand ends up with different
 * columns from one imported from a spreadsheet.
 *
 * Every row is written in one transaction: a student without its enrolment, or
 * an enrolment without its student, is never left behind.
 */
final class StudentProvisioner
{
    /**
     * @param  array  $data  Already-validated studentCreate-shaped payload.
     * @param  int    $schoolId
     * @return array{user:User, password:string}
     *
     * @throws ValidationException when the email is already taken
     */
    public static function provision(array $data, int $schoolId, bool $sendWelcomeEmail = true): array
    {
        $email = trim((string) $data['email']);

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Email was already taken.']);
        }

        return DB::transaction(function () use ($data, $schoolId, $email, $sendWelcomeEmail) {
            // Portal password: administrator either chooses one, or it is
            // generated. Never logged or exported.
            $passwordOption = $data['password_option'] ?? 'manual';
            $plainPassword  = ($passwordOption === 'auto' || empty($data['password']))
                ? Str::random(10)
                : $data['password'];

            $student = User::create([
                'name'             => $data['name'],
                'email'            => $email,
                'password'         => Hash::make($plainPassword),
                'code'             => student_code(),
                'role_id'          => '7',
                'school_id'        => $schoolId,
                'user_information' => $data['user_information'] ?? json_encode([]),
                'status'           => 1,
            ]);

            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'school_id'               => $schoolId,
                    'programme_id'            => $data['programme_id'] ?? null,
                    'intake_session_id'       => $data['intake_session_id'] ?? null,
                    'year_of_study'           => $data['year_of_study'] ?? null,
                    'nationality'             => $data['nationality'] ?? null,
                    'national_id_or_passport' => $data['national_id_or_passport'] ?? null,
                    'next_of_kin_address'     => $data['next_of_kin_address'] ?? null,
                    'next_of_kin_contact'     => $data['next_of_kin_contact'] ?? null,
                    'status'                  => $data['status'] ?? 'active',
                ]
            );

            if (! empty($data['programme_id'])) {
                StudentFeeInvoiceGenerator::generateForStudent($student, (int) $data['programme_id'], $schoolId);
            }

            $runningSession = $data['session_id'] ?? get_school_settings($schoolId)->value('running_session')
                ?: Session::where('school_id', $schoolId)->where('status', 1)->value('id');

            Enrollment::create([
                'user_id'       => $student->id,
                'class_id'      => (int) $data['class_id'],
                'section_id'    => (int) ($data['section_id'] ?? 0),
                'school_id'     => $schoolId,
                'department_id' => (int) ($data['department_id'] ?? 0),
                'session_id'    => (int) ($runningSession ?? 0),
            ]);

            EnrollmentDefaults::ensureRow($student->id, $schoolId);

            return ['user' => $student, 'password' => $plainPassword];
        });
    }

    /**
     * Emailed only after the transaction commits, and only when SMTP is actually
     * configured — a mail transport failure must never roll back a created
     * student or abort a bulk import half way.
     */
    public static function sendWelcomeEmail(User $student, string $plainPassword): void
    {
        if (! get_settings('smtp_user') || ! get_settings('smtp_pass')
            || ! get_settings('smtp_host') || ! get_settings('smtp_port')) {
            return;
        }

        \App\Support\Mail\SafeMail::send($student->email, new \App\Mail\NewUserEmail([
            'name'     => $student->name,
            'email'    => $student->email,
            'password' => $plainPassword,
        ]));
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