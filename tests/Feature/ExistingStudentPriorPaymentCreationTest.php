<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\Programme;
use App\Models\Session;
use App\Models\StudentFeeManager;
use App\Models\StudentOpeningPayment;
use App\Models\StudentProfile;
use App\Models\TuitionPayment;
use App\Models\User;
use App\Support\StudentFeeInvoiceGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ExistingStudentPriorPaymentCreationTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;
    private User $admin;
    private Session $period;
    private FeeStructure $structure;
    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_22_000000_add_method_to_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_currency')->default('UGX'));

        $this->schoolId = $this->makeSchool(['running_session' => 1]);
        $this->admin = $this->makeAdminUser($this->schoolId);
        $this->period = Session::create(['school_id' => $this->schoolId, 'session_title' => '2026 Semester 2', 'status' => 1]);
        $this->programme = Programme::create(['school_id' => $this->schoolId, 'name' => 'Diploma in Finance', 'code' => 'DF', 'is_active' => 1]);
        $this->structure = FeeStructure::create(['school_id' => $this->schoolId, 'name' => 'Tuition', 'fee_type' => 'tuition',
            'amount' => 1500000, 'is_mandatory' => 1, 'class_id' => null, 'programme_id' => $this->programme->id, 'session_id' => $this->period->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Continuing Student', 'email' => 'continuing@example.test', 'registration_number' => 'PIIE/CONT/026',
            'password_option' => 'auto', 'gender' => 'Female', 'blood_group' => '', 'birthday' => '01/01/2000',
            'phone' => '0700000000', 'address' => 'Kampala', 'record_fees' => '1',
            'programme_id' => $this->programme->id,
            'fee_session_id' => $this->period->id, 'fee_structure_id' => $this->structure->id,
            'actual_fee_amount' => '1200000.00', 'prior_paid_amount' => '200000.00',
            'prior_paid_on' => now()->subMonth()->toDateString(), 'prior_payment_method' => 'mobile_money',
            'prior_payment_reference' => 'MM-PRIOR-026', 'prior_payment_reason' => 'Verified against the original mobile money receipt.',
        ], $overrides);
    }

    public function test_add_existing_student_creates_student_specific_invoice_and_audited_prior_payment_atomically(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $prior = StudentOpeningPayment::where('fee_id', $fee->id)->sole();

        $this->assertTrue((bool) StudentProfile::where('user_id', $student->id)->value('was_existing_at_portal_creation'));
        $this->assertSame((int) $this->period->id, (int) $fee->session_id);
        $this->assertSame((int) $this->structure->id, (int) $fee->fee_structure_id);
        $this->assertEquals(1200000, $fee->amount);
        $this->assertEquals(1200000, $fee->total_amount);
        $this->assertEquals(200000, $fee->paid_amount);
        $this->assertEquals(200000, $fee->opening_paid_amount);
        $this->assertEquals(1000000, $fee->total_amount - $fee->paid_amount);
        $this->assertSame('mobile_money', $prior->method);
        $this->assertSame('MM-PRIOR-026', $prior->reference);
        $this->assertSame('Verified against the original mobile money receipt.', $prior->reason);
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame(0, TuitionPayment::count(), 'A verified old receipt is not a new gateway collection.');
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
        $this->assertSame([], StudentFeeInvoiceGenerator::generateForStudent($student, (int) $this->programme->id, $this->schoolId), 'The linked fee structure must not be invoiced again by a later sync.');
        $this->assertSame(1, StudentFeeManager::where('student_id', $student->id)->where('fee_structure_id', $this->structure->id)->where('session_id', $this->period->id)->count());
    }

    public function test_invalid_prior_payment_rolls_back_student_invoice_and_ledger_together(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '1300000.00',
        ]))->assertSessionHasErrors('prior_paid_amount');

        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_creation_with_zero_prior_payment_does_not_create_a_fake_cash_record(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0', 'prior_paid_on' => '', 'prior_payment_method' => '',
            'prior_payment_reference' => '', 'prior_payment_reason' => '',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $this->assertEquals(1200000, $fee->total_amount);
        $this->assertEquals(0, $fee->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }
}
