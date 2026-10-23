<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\Programme;
use App\Models\Session;
use App\Models\StudentFeeManager;
use App\Models\StudentFeeAward;
use App\Models\StudentFeeRegistrationWaiver;
use App\Models\StudentFinancialSetupReview;
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
        (require database_path('migrations/2026_10_17_100000_create_student_fee_registration_waivers_table.php'))->up();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require database_path('migrations/2026_10_23_100000_create_existing_student_financial_setup_reviews_table.php'))->up();
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

    public function test_add_existing_student_stages_financial_setup_without_applying_it_before_confirmation(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $review = StudentFinancialSetupReview::where('fee_id', $fee->id)->sole();

        $this->assertTrue((bool) StudentProfile::where('user_id', $student->id)->value('was_existing_at_portal_creation'));
        $this->assertSame((int) $this->period->id, (int) $fee->session_id);
        $this->assertSame((int) $this->structure->id, (int) $fee->fee_structure_id);
        $this->assertEquals(1200000, $fee->amount);
        $this->assertEquals(1200000, $fee->total_amount);
        $this->assertEquals(0, $fee->paid_amount);
        $this->assertEquals(0, $fee->opening_paid_amount);
        $this->assertEquals(1200000, $fee->total_amount - $fee->paid_amount);
        $this->assertSame('pending', $review->prior_payment_status);
        $this->assertSame('200000.00', $review->prior_payment['amount']);
        $this->assertSame('mobile_money', $review->prior_payment['method']);
        $this->assertSame('MM-PRIOR-026', $review->prior_payment['reference']);
        $this->assertSame('Verified against the original mobile money receipt.', $review->prior_payment['reason']);
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame(0, StudentOpeningPayment::count(), 'Captured historical cash remains pending until authorized confirmation.');
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

    public function test_financial_setup_is_reviewed_once_then_existing_ledgers_apply_each_item_separately(): void
    {
        $payload = $this->payload([
            'prior_paid_amount' => '200000.00',
            'record_award' => '1', 'award_type' => 'scholarship', 'award_amount' => '100000.00',
            'award_reference' => 'SCH-026', 'award_reason' => 'Approved scholarship for the current study period.',
            'record_study_waiver' => '1', 'study_waiver_reason' => 'Approved exceptional study payment requirement support.',
        ]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $review = StudentFinancialSetupReview::where('fee_id', $fee->id)->sole();
        $this->assertEquals(1200000, $fee->total_amount, 'The agreed invoice remains intact before review.');
        $this->assertEquals(900000, app(\App\Support\Billing\ExistingStudentFinancialSetup::class)->projectedBalance($fee, $review) / 100);
        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $fee->id))->assertOk()
            ->assertSee('Financial setup review')->assertSee('Projected balance')
            ->assertSee('SCH-026')->assertSee('MM-PRIOR-026');
        $this->assertSame(0, StudentOpeningPayment::count());
        $this->assertSame(0, StudentFeeAward::count());
        $this->assertSame(0, StudentFeeRegistrationWaiver::count());

        $confirm = fn (string $item) => $this->actingAs($this->admin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, $item]));
        $confirm('prior_payment')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(200000, $fee->fresh()->paid_amount);
        $this->assertEquals(1200000, $fee->fresh()->total_amount);
        $this->assertEquals(0, TuitionPayment::count());
        $confirm('prior_payment')->assertSessionHasErrors('financial_setup');
        $this->assertSame(1, StudentOpeningPayment::count());

        $confirm('award')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, StudentFeeAward::count());
        $this->assertEquals(1100000, $fee->fresh()->total_amount);
        $this->assertEquals(200000, $fee->fresh()->paid_amount);
        $this->assertSame(0, TuitionPayment::count());

        $confirm('study_waiver')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, StudentFeeRegistrationWaiver::count());
        $this->assertEquals(1100000, $fee->fresh()->total_amount);
        $this->assertEquals(200000, $fee->fresh()->paid_amount, 'The study waiver never changes fee accounting.');
    }

    public function test_financial_setup_confirmation_checks_permission_school_and_session(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload())->assertRedirect();
        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();

        $viewer = User::create(['school_id' => $this->schoolId, 'role_id' => 3, 'name' => 'Viewer', 'email' => 'viewer@example.test', 'account_status' => 'active']);
        DB::table('user_permissions')->insert(['school_id' => $this->schoolId, 'user_id' => $viewer->id,
            'permission' => 'finance.view', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertForbidden();
        $this->assertSame(0, StudentOpeningPayment::count());

        $otherSchoolAdmin = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($otherSchoolAdmin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());

        DB::table('student_financial_setup_reviews')->where('fee_id', $fee->id)->update(['session_id' => $fee->session_id + 1]);
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_existing_legacy_invoice_without_setup_packet_keeps_existing_payment_and_award_workflows(): void
    {
        $student = User::create(['school_id' => $this->schoolId, 'role_id' => 7, 'name' => 'Legacy Student', 'email' => 'legacy@example.test']);
        $profile = StudentProfile::create(['school_id' => $this->schoolId, 'user_id' => $student->id]);
        DB::table('student_profiles')->where('id', $profile->id)->update([
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]);
        $fee = StudentFeeManager::create(['school_id' => $this->schoolId, 'student_id' => $student->id, 'title' => 'Legacy tuition',
            'amount' => 1200000, 'total_amount' => 1200000, 'paid_amount' => 0, 'class_id' => 0, 'session_id' => $this->period->id,
            'payment_method' => '', 'status' => 'unpaid', 'timestamp' => time()]);

        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $fee->id))->assertOk()
            ->assertSee('Record prior payment already received')->assertSee('Record fee award');
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $fee->id), [
            'action' => 'record', 'amount' => '100000.00', 'paid_on' => now()->subDay()->toDateString(),
            'method' => 'cash', 'reference' => 'LEGACY-CASH-1', 'reason' => 'Verified historic cash receipt for legacy student.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(100000, $fee->fresh()->paid_amount);
        $this->assertSame(0, StudentFinancialSetupReview::where('fee_id', $fee->id)->count());
    }
}
