<?php

namespace Tests\Feature;

use App\Models\{StudentFeeAward, StudentFeeManager, TuitionPayment, User};
use App\Support\Billing\StudentFeeAwards;
use App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentFeeAwardsTest extends TestCase
{
    use AdmissionsTestHelper;

    private User $admin;
    private User $student;
    private StudentFeeManager $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        \Illuminate\Support\Facades\Schema::table('schools', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->string('school_currency')->default('UGX'));
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        $school = $this->makeSchool(['running_session' => 1]);
        $this->admin = $this->makeAdminUser($school);
        $this->student = User::create(['school_id' => $school, 'role_id' => 7, 'name' => 'Award Student', 'email' => 'award@example.test']);
        $this->fee = StudentFeeManager::create(['school_id' => $school, 'student_id' => $this->student->id,
            'title' => 'Tuition', 'total_amount' => 900, 'amount' => 1000, 'discounted_price' => 100,
            'paid_amount' => 0, 'class_id' => 0, 'session_id' => 1, 'payment_method' => '', 'status' => 'unpaid']);
    }

    private function grant(string $amount = '400.00', array $extra = []): void
    {
        app(StudentFeeAwards::class)->change($this->admin, $this->fee->id, array_merge([
            'action' => 'grant', 'type' => 'bursary', 'amount' => $amount,
            'reference' => 'BOARD-2026', 'reason' => 'Approved financial assistance.'], $extra));
    }

    public function test_award_preserves_cash_and_existing_discount_and_other_students_fees(): void
    {
        $other = $this->fee->replicate(); $other->save();
        $this->grant('400.25');
        $fee = $this->fee->fresh();
        $this->assertEquals(499.75, $fee->total_amount);
        $this->assertEquals(0, $fee->paid_amount);
        $this->assertEquals(100, $fee->discounted_price);
        $this->assertEquals(900, $other->fresh()->total_amount);
        $this->assertSame(0, TuitionPayment::count());
        $this->assertSame($this->admin->id, StudentFeeAward::first()->approved_by);
    }

    public function test_reduced_fee_drives_twenty_percent_requirement(): void
    {
        $this->fee->update(['paid_amount' => 100]);
        $this->grant();
        $summary = app(StudentRegistrationConfirmationEligibility::class)->summary($this->student);
        $this->assertSame(50000, $summary['total_minor']);
        $this->assertSame(10000, $summary['required_minor']);
        $this->assertTrue($summary['eligible']);
        $this->assertEquals(100, $this->fee->fresh()->paid_amount);
    }

    public function test_full_scholarship_allows_study_without_fabricating_payment(): void
    {
        $this->grant('900.00', ['type' => 'scholarship']);
        $summary = app(StudentRegistrationConfirmationEligibility::class)->summary($this->student);
        $this->assertTrue($summary['fully_covered']);
        $this->assertTrue($summary['eligible']);
        $this->assertSame(0, $summary['paid_minor']);
        $this->assertSame('paid', $this->fee->fresh()->status);
        $this->actingAs($this->student)->get(route('student.FeePayment', $this->fee->id))
            ->assertOk()->assertSee('fully covered')->assertSee('Scholarship reduction')->assertDontSee('Pay with PesaPal')->assertDontSee('Approve fee reduction');
    }

    public function test_revocation_restores_exact_balance_and_keeps_audit_history(): void
    {
        $this->grant('900.00');
        app(StudentFeeAwards::class)->change($this->admin, $this->fee->id, ['action' => 'revoke', 'reason' => 'Award withdrawn after review.']);
        $this->assertEquals(900, $this->fee->fresh()->total_amount);
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $this->fee->fresh()->status);
        $this->assertNotNull(StudentFeeAward::first()->revoked_at);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->summary($this->student)['eligible']);
    }

    public function test_award_cannot_exceed_remaining_balance(): void
    {
        $this->fee->update(['paid_amount' => 600]);
        $this->expectException(ValidationException::class);
        $this->grant('300.01');
    }

    public function test_repeated_grant_does_not_deduct_twice(): void
    {
        $this->grant();
        try { $this->grant(); $this->fail('Duplicate accepted'); } catch (ValidationException $e) {}
        $this->assertEquals(500, $this->fee->fresh()->total_amount);
        $this->assertSame(1, StudentFeeAward::count());
    }

    public function test_pending_and_late_failed_orders_block_adjustments(): void
    {
        foreach (['pending', 'failed'] as $status) {
            TuitionPayment::query()->delete();
            TuitionPayment::create(['school_id' => $this->fee->school_id, 'fee_id' => $this->fee->id,
                'amount' => 900, 'currency' => 'UGX', 'method' => 'pesapal', 'status' => $status,
                'reference' => 'ORDER-' . $status, 'gateway_txn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3']);
            try { $this->grant(); $this->fail('Unreconciled order accepted'); } catch (ValidationException $e) {}
        }
        $this->assertSame(0, StudentFeeAward::count());
        $this->assertEquals(900, $this->fee->fresh()->total_amount);
    }

    public function test_http_grant_revoke_and_display(): void
    {
        $url = route('admin.tuition_payments.award', $this->fee->id);
        $this->actingAs($this->admin)->post($url, ['action' => 'grant', 'type' => 'scholarship', 'amount' => '400.00', 'reason' => 'Board approved scholarship.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.tuition_payments.show', $this->fee->id))->assertOk()->assertSee('Scholarship reduction')->assertSee('Revoke award and restore fee');
        $this->post($url, ['action' => 'revoke', 'reason' => 'Board revoked scholarship.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(900, $this->fee->fresh()->total_amount);
    }

    public function test_students_and_other_schools_cannot_approve_awards(): void
    {
        $data = ['action' => 'grant', 'type' => 'bursary', 'amount' => '400.00', 'reason' => 'Approved assistance request.'];
        $url = route('admin.tuition_payments.award', $this->fee->id);
        $this->actingAs($this->student)->post($url, $data)->assertRedirect();
        $this->actingAs($this->makeAdminUser($this->makeSchool()))->post($url, $data)->assertNotFound();
        $this->assertSame(0, StudentFeeAward::count());
    }

    public function test_invalid_precision_and_missing_reason_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.award', $this->fee->id), [
            'action' => 'grant', 'type' => 'bursary', 'amount' => '1.001', 'reason' => 'short'])
            ->assertSessionHasErrors(['amount', 'reason']);
        $this->assertSame(0, StudentFeeAward::count());
    }

    public function test_existing_invoice_edit_and_delete_cannot_erase_award_history(): void
    {
        $this->grant();
        $this->actingAs($this->admin)->post(route('admin.fee_manager.update', $this->fee->id), [])->assertStatus(422);
        $this->get(route('admin.fee_manager.delete', $this->fee->id))->assertStatus(422);
        $this->assertTrue($this->fee->fresh()->hasAwardHistory());
    }

    public function test_full_award_does_not_cover_other_invoices_or_future_sessions(): void
    {
        $other = $this->fee->replicate(); $other->save();
        $this->grant('900.00');
        $summary = app(StudentRegistrationConfirmationEligibility::class)->summary($this->student);
        $this->assertFalse($summary['eligible']);
        $this->assertSame(90000, $summary['total_minor']);
        $other->update(['session_id' => 2]);
        \Illuminate\Support\Facades\DB::table('schools')->where('id', $this->student->school_id)->update(['running_session' => 2]);
        $summary = app(StudentRegistrationConfirmationEligibility::class)->summary($this->student);
        $this->assertFalse($summary['eligible']);
        $this->assertFalse($summary['fully_covered']);
    }

    public function test_printable_award_breakdown_is_scoped_to_the_invoice_school(): void
    {
        $this->grant();
        $this->assertStringContainsString('BOARD-2026', view('shared.invoice_award', ['invoice_details' => $this->fee->fresh()->toArray()])->render());
        $details = $this->fee->fresh()->toArray(); $details['school_id'] = $this->makeSchool();
        $this->assertStringNotContainsString('BOARD-2026', view('shared.invoice_award', ['invoice_details' => $details])->render());
    }

    public function test_zero_fees_without_awards_do_not_bypass_finance_setup(): void
    {
        $this->fee->update(['total_amount' => 0]);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->summary($this->student)['eligible']);
    }
}
