<?php

namespace Tests\Feature;

use App\Models\{StudentFeeManager, StudentOpeningPayment, TuitionPayment, User};
use App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility;
use App\Support\Payments\StudentOpeningPayments;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentOpeningPaymentsTest extends TestCase
{
    use AdmissionsTestHelper;
    private User $admin;
    private User $student;
    private StudentFeeManager $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_currency')->default('UGX'));
        $school = $this->makeSchool(['running_session' => 1]);
        $this->admin = $this->makeAdminUser($school);
        $this->student = User::create(['school_id' => $school, 'role_id' => 7, 'name' => 'Existing Student', 'email' => 'existing@example.test']);
        $this->fee = StudentFeeManager::create(['school_id' => $school, 'student_id' => $this->student->id,
            'title' => 'Current session fees', 'total_amount' => 1000, 'amount' => 1000, 'paid_amount' => 0,
            'class_id' => 0, 'session_id' => 1, 'payment_method' => '', 'status' => 'unpaid', 'timestamp' => time()]);
    }

    private function record(array $extra = []): void
    {
        app(StudentOpeningPayments::class)->change($this->admin, $this->fee->id, array_merge([
            'action' => 'record', 'amount' => '600.00', 'paid_on' => now()->subMonth()->toDateString(),
            'reference' => 'OLD-RECEIPT-001', 'reason' => 'Verified against the original bank receipt.'], $extra));
    }

    private function reverse(): void
    {
        app(StudentOpeningPayments::class)->change($this->admin, $this->fee->id, [
            'action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Incorrect historical allocation; restore balance.']);
    }

    public function test_prior_payment_reduces_balance_and_is_separate_from_new_collections(): void
    {
        $this->record(['amount' => '600.25']);
        $fee = $this->fee->fresh();
        $this->assertEquals(600.25, $fee->paid_amount);
        $this->assertSame('600.25', $fee->opening_paid_amount);
        $this->assertEquals(399.75, $fee->total_amount - $fee->paid_amount);
        $this->assertEquals(0, $fee->collected_amount);
        $this->assertSame(0, TuitionPayment::count());
        $this->assertSame($this->admin->id, StudentOpeningPayment::first()->approved_by);
        $this->assertTrue(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_new_and_prior_payments_are_added_without_overwriting_existing_money(): void
    {
        $this->fee->update(['paid_amount' => 100]);
        $this->record();
        $fee = $this->fee->fresh();
        $this->assertEquals(700, $fee->paid_amount);
        $this->assertEquals(100, $fee->collected_amount);
        $this->reverse();
        $this->assertEquals(100, $this->fee->fresh()->paid_amount);
        $this->assertEquals(100, $this->fee->fresh()->collected_amount);
    }

    public function test_full_prior_payment_removes_checkout_without_a_fake_gateway_receipt(): void
    {
        $this->record(['amount' => '1000.00']);
        $this->assertSame('paid', $this->fee->fresh()->status);
        $this->actingAs($this->student)->get(route('student.FeePayment', $this->fee->id))
            ->assertOk()->assertSee('OLD-RECEIPT-001')->assertSee('Verified prior payment')->assertDontSee('Pay with PesaPal');
        $this->assertSame(0, TuitionPayment::count());
    }

    public function test_reversal_restores_balance_and_keeps_approval_history(): void
    {
        $this->record(['amount' => '1000.00']);
        $this->reverse();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
        $this->assertSame('0.00', $this->fee->fresh()->opening_paid_amount);
        $this->assertSame('unpaid', $this->fee->fresh()->status);
        $this->assertNotNull(StudentOpeningPayment::first()->reversed_at);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
        $this->expectException(ValidationException::class);
        $this->reverse();
    }

    public function test_duplicate_receipt_does_not_credit_twice_even_on_another_invoice(): void
    {
        $this->record();
        $other = $this->fee->replicate(); $other->forceFill(['paid_amount' => 0, 'opening_paid_amount' => 0])->save();
        try {
            app(StudentOpeningPayments::class)->change($this->admin, $other->id, [
                'action' => 'record', 'amount' => '100.00', 'paid_on' => now()->toDateString(),
                'reference' => ' old-receipt-001 ', 'reason' => 'Accidental duplicate receipt entry.']);
            $this->fail('Duplicate accepted');
        } catch (ValidationException $e) {}
        $this->assertEquals(0, $other->fresh()->paid_amount);
        $this->assertSame(1, StudentOpeningPayment::count());
    }

    public function test_overpayment_is_rejected_without_changing_invoice_or_history(): void
    {
        $this->fee->update(['paid_amount' => 500]);
        try { $this->record(); $this->fail('Overpayment accepted'); } catch (ValidationException $e) {}
        $this->assertEquals(500, $this->fee->fresh()->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_current_session_eligibility_does_not_use_past_session_payments(): void
    {
        $this->record();
        DB::table('schools')->where('id', $this->student->school_id)->update(['running_session' => 2]);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_pending_offline_claims_and_gateway_attempts_block_entry(): void
    {
        $this->fee->update(['status' => 'pending']);
        try { $this->record(); $this->fail('Unreviewed claim accepted'); } catch (ValidationException $e) {}
        $this->fee->update(['status' => 'unpaid']);
        foreach (['pending', 'failed'] as $status) {
            TuitionPayment::query()->delete();
            TuitionPayment::create(['school_id' => $this->fee->school_id, 'fee_id' => $this->fee->id, 'amount' => 1000,
                'currency' => 'UGX', 'method' => 'pesapal', 'status' => $status, 'reference' => $status,
                'gateway_txn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3']);
            try { $this->record(); $this->fail('Unresolved attempt accepted'); } catch (ValidationException $e) {}
        }
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_finance_can_record_and_reverse_through_the_form(): void
    {
        $url = route('admin.tuition_payments.opening_payment', $this->fee->id);
        $this->actingAs($this->admin)->post($url, ['action' => 'record', 'amount' => '600.00',
            'paid_on' => now()->subDay()->toDateString(), 'reference' => 'BANK-OLD-1', 'reason' => 'Original receipt verified by finance.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.tuition_payments.show', $this->fee->id))->assertOk()->assertSee('BANK-OLD-1')->assertSee('Reverse incorrect prior payment');
        $this->post($url, ['action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Correcting original receipt allocation.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
    }

    public function test_invalid_precision_future_dates_and_missing_receipts_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '1.001', 'paid_on' => now()->addDay()->toDateString(), 'reason' => 'short'])
            ->assertSessionHasErrors(['amount', 'paid_on', 'reference', 'reason']);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_student_and_other_institution_cannot_record_payments(): void
    {
        $data = ['action' => 'record', 'amount' => '600.00', 'paid_on' => now()->toDateString(),
            'reference' => 'TENANT-1', 'reason' => 'Original payment verified by finance.'];
        $url = route('admin.tuition_payments.opening_payment', $this->fee->id);
        $this->actingAs($this->student)->post($url, $data)->assertRedirect();
        $this->actingAs($this->makeAdminUser($this->makeSchool()))->post($url, $data)->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_reversal_cannot_target_another_invoice(): void
    {
        $this->record();
        $other = $this->fee->replicate(); $other->save();
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $other->id), [
            'action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Cannot reverse another invoice payment.'])->assertNotFound();
        $this->assertNull(StudentOpeningPayment::first()->reversed_at);
    }

    public function test_generic_invoice_edit_and_delete_preserve_history_after_reversal(): void
    {
        $this->record(); $this->reverse();
        $this->actingAs($this->admin)->post(route('admin.fee_manager.update', $this->fee->id), [])->assertStatus(422);
        $this->get(route('admin.fee_manager.delete', $this->fee->id))->assertStatus(422);
        $this->assertTrue($this->fee->fresh()->hasOpeningPaymentHistory());
    }

    public function test_dashboard_excludes_prior_payments_from_new_collections(): void
    {
        DB::table('roles')->insert(['role_id' => 7, 'name' => 'Student', 'school_id' => 0]);
        $accountant = User::create(['school_id' => $this->fee->school_id, 'role_id' => 4,
            'name' => 'Finance', 'email' => 'finance@example.test', 'account_status' => 'active']);
        $this->record();
        $this->actingAs($accountant)->get(route('accountant.dashboard'))->assertOk()
            ->assertViewHas('totalCollected', 0)->assertViewHas('openingPaymentsTotal', 600)
            ->assertViewHas('totalOutstanding', 400)->assertViewHas('recentPayments', fn ($payments) => $payments->isEmpty());
        $this->fee->refresh()->update(['paid_amount' => 700]);
        $this->get(route('accountant.dashboard'))->assertOk()->assertViewHas('totalCollected', 100)
            ->assertViewHas('totalOutstanding', 300);
    }

    public function test_finance_report_uses_combined_payments_for_debt_but_excludes_opening_money_from_collections(): void
    {
        $this->record();
        $this->actingAs($this->admin)->get(route('admin.reports.finance'))->assertOk()
            ->assertViewHas('totals', fn ($totals) => (float) $totals->invoiced === 1000.0 && (float) $totals->paid === 600.0 && (float) $totals->collected === 0.0);
    }

    public function test_printed_receipt_history_is_scoped_to_the_invoice_institution(): void
    {
        $this->record();
        $this->assertStringContainsString('OLD-RECEIPT-001', view('shared.invoice_opening_payments', ['invoice_details' => $this->fee->fresh()->toArray()])->render());
        $details = $this->fee->fresh()->toArray(); $details['school_id'] = $this->makeSchool();
        $this->assertStringNotContainsString('OLD-RECEIPT-001', view('shared.invoice_opening_payments', ['invoice_details' => $details])->render());
    }

    public function test_invoice_sync_and_refresh_preserve_prior_payments_without_duplicate_billing(): void
    {
        $structure = \App\Models\FeeStructure::create(['school_id' => $this->fee->school_id, 'name' => 'Tuition',
            'fee_type' => 'tuition', 'amount' => 1000, 'is_mandatory' => 1, 'session_id' => 1]);
        $this->fee->update(['fee_structure_id' => $structure->id]);
        $this->record();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame([], \App\Support\StudentFeeInvoiceGenerator::generateForStudent($this->student, null, (int) $this->fee->school_id));
        }
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertEquals(600, $this->fee->fresh()->paid_amount);
        $this->assertEquals(400, $this->fee->fresh()->total_amount - $this->fee->fresh()->paid_amount);
    }

    public function test_staff_without_payment_approval_permission_cannot_credit_prior_money(): void
    {
        $lecturer = User::create(['school_id' => $this->fee->school_id, 'role_id' => 3, 'name' => 'Lecturer',
            'email' => 'lecturer@example.test', 'account_status' => 'active']);
        $this->actingAs($lecturer)->post(route('admin.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '600.00', 'paid_on' => now()->toDateString(),
            'reference' => 'UNAUTHORIZED', 'reason' => 'Staff cannot approve payment without permission.'])->assertForbidden();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }
}
