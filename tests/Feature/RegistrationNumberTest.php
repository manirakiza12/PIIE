<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RegistrationNumber;
use App\Support\StudentProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class RegistrationNumberTest extends TestCase
{
    use AdmissionsTestHelper;
    private array $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_13_121000_add_admission_letter_dates_to_intake_sessions.php'))->up();
        $school = $this->makeSchool();
        $this->assignment = ['school_id' => $school,
            'programme_id' => $this->makeProgramme($school, ['code' => 'MPAM']),
            'intake_session_id' => $this->makeIntakeSession($school, ['start_date' => '2026-05-04', 'open_date' => '2025-12-01'])];
    }

    public function test_enrolment_uses_intake_start_and_preserves_number_on_retry(): void
    {
        $data = $this->assignment + ['name' => 'Example Student', 'email' => 'one@example.test'];
        $student = StudentProvisioningService::provision($data)['student'];
        $this->assertSame('2026/05/MPAM/001', $student->code);
        $this->assertSame($student->code, StudentProvisioningService::provision($data)['student']->code);
        $this->assertSame('2026/05/MPAM/002', StudentProvisioningService::provision($this->assignment + ['email' => 'two@example.test'])['student']->code);
        $this->assertSame($student->code, \App\Support\IdCard::cardNumber($student));
    }

    public function test_deleted_accounts_do_not_free_numbers_and_imported_numbers_are_skipped(): void
    {
        $student = StudentProvisioningService::provision($this->assignment + ['email' => 'one@example.test'])['student'];
        $student->delete();
        $this->assertSame('2026/05/MPAM/002', RegistrationNumber::student($this->assignment));
        User::create(['name' => 'Imported', 'email' => 'import@example.test', 'code' => '2026/05/MPAM/009', 'school_id' => $this->assignment['school_id'], 'role_id' => 7]);
        $this->assertSame('2026/05/MPAM/010', RegistrationNumber::student($this->assignment));
    }

    public function test_rollback_does_not_consume_a_number(): void
    {
        DB::beginTransaction();
        $this->assertSame('2026/05/MPAM/001', RegistrationNumber::student($this->assignment));
        DB::rollBack();
        $this->assertSame('2026/05/MPAM/001', RegistrationNumber::student($this->assignment));
    }

    public function test_imported_numbers_with_a_legacy_trailing_slash_are_also_reserved(): void
    {
        User::create(['name' => 'Legacy imported', 'email' => 'legacy@example.test',
            'code' => '2026/05/MPAM/009/', 'school_id' => $this->assignment['school_id'], 'role_id' => 7]);
        $this->assertSame('2026/05/MPAM/010', RegistrationNumber::student($this->assignment));
    }

    public function test_intakes_and_staff_roles_have_independent_sequences(): void
    {
        RegistrationNumber::student($this->assignment);
        DB::table('intake_sessions')->where('id', $this->assignment['intake_session_id'])->update(['start_date' => '2027-01-04']);
        $this->assertSame('2027/01/MPAM/001', RegistrationNumber::student($this->assignment));
        $this->assertSame(now()->format('Y/m').'/TRS/001', RegistrationNumber::staff(3));
        $this->assertSame(now()->format('Y/m').'/TRS/002', RegistrationNumber::staff(3));
        $this->assertSame(now()->format('Y/m').'/ADM/001', RegistrationNumber::staff(2));
    }

    public function test_foreign_intake_is_rejected(): void
    {
        $other = $this->makeSchool();
        $this->expectException(ValidationException::class);
        RegistrationNumber::student(array_replace($this->assignment, ['intake_session_id' => $this->makeIntakeSession($other)]));
    }

    public function test_older_intake_names_with_year_before_month_are_understood(): void
    {
        DB::table('intake_sessions')->where('id', $this->assignment['intake_session_id'])->update(['start_date' => null, 'name' => '2026 September Intake']);
        $this->assertSame('2026/09/MPAM/001', RegistrationNumber::student($this->assignment));
    }

    public function test_unspecified_intake_date_is_rejected_instead_of_using_application_opening(): void
    {
        DB::table('intake_sessions')->where('id', $this->assignment['intake_session_id'])->update(['start_date' => null, 'name' => 'Intake A']);
        $this->expectException(ValidationException::class);
        RegistrationNumber::student($this->assignment);
    }
}
