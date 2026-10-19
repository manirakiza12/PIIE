<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, StudentFeeManager, TuitionPayment, User};
use App\Support\Payments\{PesaPalException, TuitionPesaPalPayment};
use Illuminate\Support\Facades\{DB, Http, Schema, URL};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class TuitionPesaPalJourneyTest extends TestCase
{
    use AdmissionsTestHelper;
    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private int $school;
    private User $student;
    private string $reference = '';
    private int $orders = 0;
    private int $code = 0;
    private string $description = 'INVALID';
    private string $amount = '5000.00';
    private bool $timeout = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $t) => $t->string('school_currency')->nullable());
        $this->school = $this->makeSchool(['school_currency' => 'UGX']);
        $this->student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Student', 'email' => 'student@example.test']);
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test'); URL::forceScheme('https');
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture', 'notification_id' => self::GUID])]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) { return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]); }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $this->orders++; $this->reference = $request['id'];
                if ($this->timeout) { throw new \Illuminate\Http\Client\ConnectionException('timeout'); }
                return Http::response(['order_tracking_id' => self::GUID, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) { return Http::response(['merchant_reference' => $this->reference,
                'amount' => $this->amount, 'currency' => 'UGX', 'status_code' => $this->code, 'payment_status_description' => $this->description,
                'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null]); }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function fee(array $extra = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_merge(['school_id' => $this->school, 'student_id' => $this->student->id,
            'title' => 'School fees', 'total_amount' => 5000, 'amount' => 5000, 'paid_amount' => 0, 'class_id' => 0,
            'payment_method' => '', 'status' => 'unpaid'], $extra));
    }

    public function test_checkout_resumes_same_order_and_only_verified_success_credits_once(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee, '+256771234567');
        $this->assertSame('processing', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame($payment->id, TuitionPesaPalPayment::start($fee)->id);
        $this->assertSame(1, $this->orders);
        $this->code = 1; $this->description = 'COMPLETED';
        $this->assertSame('paid', TuitionPesaPalPayment::reconcile($payment));
        $this->assertSame('paid', TuitionPesaPalPayment::reconcile($payment));
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame('paid', $fee->fresh()->status);
    }

    public function test_provider_amount_mismatch_cannot_settle(): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1; $this->description = 'COMPLETED'; $this->amount = '4999.00';
        try { TuitionPesaPalPayment::reconcile($payment); $this->fail('Mismatch accepted'); }
        catch (PesaPalException $e) { $this->assertSame(0, (int) $fee->fresh()->paid_amount); }
    }

    public function test_ambiguous_timeout_never_dispatches_a_second_order(): void
    {
        $fee = $this->fee(); $this->timeout = true;
        try { TuitionPesaPalPayment::start($fee); $this->fail('Expected timeout'); } catch (ValidationException $e) {}
        $payment = TuitionPesaPalPayment::start($fee);
        $this->assertSame(1, $this->orders);
        $this->assertNull($payment->gateway_txn_id);
        $this->assertSame('pending', $payment->status);
    }

    public function test_shared_ipn_settles_school_fees_without_login_and_is_idempotent(): void
    {
        $fee = $this->fee(); TuitionPesaPalPayment::start($fee);
        $this->code = 1; $this->description = 'COMPLETED';
        $url = route('applicant.pesapal.ipn', ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $this->reference]);
        $this->get($url)->assertOk(); $this->get($url)->assertOk();
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
    }

    public function test_reversal_removes_credit_once(): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1; $this->description = 'COMPLETED'; TuitionPesaPalPayment::reconcile($payment);
        $this->code = 3; $this->description = 'REVERSED';
        $this->assertSame('reversed', TuitionPesaPalPayment::reconcile($payment));
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $fee->fresh()->status);
    }

    public function test_reversal_before_success_observation_releases_invoice_without_credit(): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 3; $this->description = 'REVERSED';
        $this->assertSame('reversed', TuitionPesaPalPayment::reconcile($payment));
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_unsubmitted_order_can_be_retried_without_stranding_the_invoice(): void
    {
        $fee = $this->fee(); URL::forceScheme('http'); URL::forceRootUrl('http://piie.example.test');
        try { TuitionPesaPalPayment::start($fee); $this->fail('Unsafe callback accepted'); } catch (ValidationException $e) {}
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, $this->orders);
        URL::forceRootUrl('https://piie.example.test'); URL::forceScheme('https');
        $this->assertNotNull(TuitionPesaPalPayment::start($fee)->gateway_txn_id);
        $this->assertSame(1, $this->orders);
    }

    public function test_existing_partial_payment_only_charges_outstanding_balance(): void
    {
        $fee = $this->fee(['paid_amount' => 1000]); $this->amount = '4000.00';
        $payment = TuitionPesaPalPayment::start($fee);
        $this->assertSame('4000.00', $payment->amount);
        $this->code = 1; $this->description = 'COMPLETED'; TuitionPesaPalPayment::reconcile($payment);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
    }

    public function test_student_cannot_access_another_students_payment_or_cross_school_invoice(): void
    {
        $fee = $this->fee(['student_id' => $this->student->id + 100]);
        $this->actingAs($this->student)->get(route('student.FeePayment', $fee->id))->assertNotFound();
        $fee->update(['student_id' => $this->student->id, 'school_id' => $this->makeSchool()]);
        $this->get(route('student.FeePayment', $fee->id))->assertNotFound();
    }

    public function test_checkout_has_no_offline_payment_and_retired_routes_are_absent(): void
    {
        $fee = $this->fee();
        $this->actingAs($this->student)->get(route('student.FeePayment', $fee->id))->assertOk()->assertSee('Pay with PesaPal')->assertDontSee('document_image')->assertDontSee('Pay offline');
        foreach (['student.offline_payment','parent.offline_payment','admin.update_offline_payment','accountant.update_offline_payment','bursar.update_offline_payment','student.payment.marzpay.start','parent.payment.marzpay.start'] as $name) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($name), $name);
        }
    }

    public function test_admin_finance_tracks_payments_and_can_verify_them(): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $admin = $this->makeAdminUser($this->school);
        $this->actingAs($admin)->get(route('admin.tuition_payments.index'))->assertOk()->assertSee($payment->reference);
        $this->get(route('admin.tuition_payments.show', $fee->id))->assertOk()->assertSee(self::GUID);
        $this->code = 1; $this->description = 'COMPLETED';
        $this->post(route('admin.payment.pesapal.check', ['id' => $fee->id, 'payment' => $payment->id]))->assertRedirect();
        $this->assertSame('paid', $fee->fresh()->status);
    }

    public function test_parent_can_only_pay_invoices_linked_to_them(): void
    {
        $parent = User::create(['school_id' => $this->school, 'role_id' => 6, 'name' => 'Parent', 'email' => 'parent@example.test']);
        $fee = $this->fee(['parent_id' => $parent->id]);
        $this->actingAs($parent)->get(route('parent.FeePayment', $fee->id))->assertOk();
        $fee->update(['parent_id' => $parent->id + 100]);
        $this->get(route('parent.FeePayment', $fee->id))->assertNotFound();
    }

    public function test_invoice_edits_cannot_forge_payment_or_modify_an_active_order(): void
    {
        $fee = $this->fee(); TuitionPesaPalPayment::start($fee);
        $this->actingAs($this->makeAdminUser($this->school));
        $this->post(route('admin.fee_manager.update', $fee->id), ['paid_amount' => 5000, 'status' => 'paid'])->assertStatus(422);
        $this->get(route('admin.fee_manager.delete', $fee->id))->assertStatus(422);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_twenty_percent_payment_grants_study_eligibility_only_after_verification(): void
    {
        $fee = $this->fee(['session_id' => 1]);
        $eligibility = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class);
        $this->assertFalse($eligibility->allows($this->student));
        $payment = TuitionPesaPalPayment::start($fee, null, 'minimum');
        $this->assertSame('1000.00', $payment->amount);
        $this->assertFalse($eligibility->allows($this->student));
        $this->amount = '1000.00'; $this->code = 1; $this->description = 'COMPLETED';
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertTrue($eligibility->allows($this->student));
        $this->assertSame(1000, (int) $fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->code = 3; $this->description = 'REVERSED'; TuitionPesaPalPayment::reconcile($payment);
        $this->assertFalse($eligibility->allows($this->student));
    }

    public function test_legacy_integer_balances_are_widened_before_crediting_fractional_minimum_payments(): void
    {
        $fee = $this->fee(['session_id' => 1, 'total_amount' => 5001]);
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        $this->assertSame('numeric', Schema::getColumnType('student_fee_managers', 'paid_amount'));
        $payment = TuitionPesaPalPayment::start($fee, null, 'minimum');
        $this->assertSame('1000.20', $payment->amount);
        $this->amount = '1000.20'; $this->code = 1; $this->description = 'COMPLETED';
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertEquals('1000.20', $fee->fresh()->paid_amount);
        $this->assertTrue(app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->allows($this->student));
        $this->assertSame('unpaid', $fee->fresh()->status);
    }

    public function test_minimum_payment_uses_all_current_session_invoices_and_excludes_old_sessions(): void
    {
        $fee = $this->fee(['session_id' => 1, 'paid_amount' => 500]);
        $this->fee(['session_id' => 1, 'total_amount' => 5000]);
        $this->fee(['session_id' => 2, 'total_amount' => 50000, 'paid_amount' => 50000, 'status' => 'paid']);
        $payment = TuitionPesaPalPayment::start($fee, null, 'minimum');
        $this->assertSame('1500.00', $payment->amount);
        $summary = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->summary($this->student);
        $this->assertSame(1000000, $summary['total_minor']);
        $this->assertSame(50000, $summary['paid_minor']);
    }

    public function test_twenty_percent_rounds_up_without_truncating_decimal_amounts(): void
    {
        $fee = $this->fee(['session_id' => 1, 'total_amount' => '100.01', 'paid_amount' => '20.00']);
        $service = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class);
        $this->assertFalse($service->allows($this->student));
        $this->assertSame(2001, $service->summary($this->student)['required_minor']);
        $fee->update(['paid_amount' => '20.01']);
        $this->assertTrue($service->allows($this->student));
    }

    public function test_unverified_pending_claim_cannot_grant_study_eligibility(): void
    {
        $this->fee(['session_id' => 1, 'status' => 'pending', 'payment_method' => 'offline', 'paid_amount' => 5000]);
        $service = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class);
        $this->assertFalse($service->allows($this->student));
        $this->assertSame(0, $service->summary($this->student)['paid_minor']);
    }

    public function test_custom_partial_payment_is_verified_without_settling_the_whole_invoice(): void
    {
        $fee = $this->fee(['session_id' => 1]);
        $payment = TuitionPesaPalPayment::start($fee, null, 'custom', '500.00');
        $this->amount = '500.00'; $this->code = 1; $this->description = 'COMPLETED';
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertSame(500, (int) $fee->fresh()->paid_amount);
        $this->assertFalse(app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_custom_payment_cannot_exceed_balance_or_be_negative_or_have_extra_precision(): void
    {
        foreach (['5000.01', '-1', '0', '1.001'] as $amount) {
            try { TuitionPesaPalPayment::start($this->fee(), null, 'custom', $amount); $this->fail('Invalid payment accepted'); }
            catch (ValidationException $exception) { $this->assertSame(0, $this->orders); }
        }
    }

    private function waiverSchema(): void
    {
        if (! Schema::hasTable('student_fee_registration_waivers')) {
            (require database_path('migrations/2026_10_17_100000_create_student_fee_registration_waivers_table.php'))->up();
        }
    }

    public function test_finance_can_grant_and_revoke_new_student_study_waiver_without_crediting_money(): void
    {
        $this->waiverSchema(); $fee = $this->fee(['session_id' => 1]);
        $url = route('admin.tuition_payments.waiver', $fee->id);
        $this->actingAs($this->makeAdminUser($this->school))->post($url, ['action' => 'grant', 'reason' => 'Approved temporary financial hardship.'])->assertRedirect()->assertSessionHasNoErrors();
        $eligibility = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class);
        $this->assertTrue($eligibility->allows($this->student));
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame(5000, (int) $fee->fresh()->total_amount);
        $this->assertDatabaseHas('student_fee_registration_waivers', ['student_id' => $this->student->id, 'session_id' => 1, 'reason' => 'Approved temporary financial hardship.']);
        $this->post($url, ['action' => 'revoke', 'reason' => 'Temporary approval period has ended.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($eligibility->allows($this->student));
        $this->assertSame(2, DB::table('audit_logs')->whereIn('action', ['COURSE_REGISTRATION_FEE_WAIVER_GRANTED', 'COURSE_REGISTRATION_FEE_WAIVER_REVOKED'])->count());
    }

    public function test_waivers_require_permission_reason_and_same_school_and_expire_with_session(): void
    {
        $this->waiverSchema(); $fee = $this->fee(['session_id' => 1]);
        $url = route('admin.tuition_payments.waiver', $fee->id);
        $this->actingAs($this->student)->post($url, ['action' => 'grant', 'reason' => 'Cannot grant my own exemption.'])->assertRedirect();
        $this->assertDatabaseCount('student_fee_registration_waivers', 0);
        $this->actingAs($this->makeAdminUser($this->makeSchool()))->post($url, ['action' => 'grant', 'reason' => 'Cannot grant another school waiver.'])->assertNotFound();
        $this->actingAs($this->makeAdminUser($this->school))->post($url, ['action' => 'grant', 'reason' => 'short'])->assertSessionHasErrors('reason');
        $this->post($url, ['action' => 'grant', 'reason' => 'Approved for the current fee session.'])->assertSessionHasNoErrors();
        DB::table('schools')->where('id', $this->school)->update(['running_session' => 2]);
        $this->assertFalse(app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_learning_gate_blocks_direct_links_but_waiver_opens_access_and_revocation_closes_it(): void
    {
        $this->waiverSchema(); $this->fee(['session_id' => 1]);
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'student'])->get('/student/study-fee-test', fn () => response('Learning access'))->name('student.courses.fee_test');
        $this->actingAs($this->student)->get('/student/study-fee-test')->assertRedirect(route('student.fee_manager.list'));
        $this->getJson('/student/study-fee-test')->assertForbidden();
        $admin = $this->makeAdminUser($this->school);
        $service = app(\App\Support\CourseRegistration\CourseRegistrationFeeWaiverService::class);
        $service->grantForStudent($admin, $this->student->id, $this->school, 'Approved temporary financial hardship.');
        $this->get('/student/study-fee-test')->assertOk()->assertSee('Learning access');
        $service->revoke($admin, $this->student->id, $this->school, 'Temporary approval period has ended.');
        $this->get('/student/study-fee-test')->assertRedirect(route('student.fee_manager.list'));
        $this->get(route('student.FeePayment', StudentFeeManager::first()->id))->assertOk();
    }
}
