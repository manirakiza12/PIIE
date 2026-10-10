<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Models\Admission;
use App\Models\AdmissionDocumentRequirement;
use App\Models\ApplicationPayment;
use App\Models\AuditLog;
use App\Models\PaymentMethods;
use App\Models\StudentFeeManager;
use App\Models\User;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\DecimalAmount;
use App\Support\Payments\VerifiedApplicationPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicationPaymentFoundationTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->schoolId = $this->makeSchool();
        DB::table('global_settings')->insert([
            ['key' => 'primary_school_id', 'value' => (string) $this->schoolId],
            ['key' => 'system_currency', 'value' => 'UGX'],
        ]);
        Http::preventStrayRequests();
        Mail::fake();
        PaymentMethods::create([
            'school_id' => $this->schoolId, 'name' => 'marzpay', 'status' => 1, 'mode' => 'test',
            'payment_keys' => json_encode(['sandbox_api_key' => 'fixture', 'sandbox_api_secret' => 'fixture', 'country' => 'UG']),
        ]);
    }

    private function admission(string $fee = '50000.00', array $attributes = []): Admission
    {
        return Admission::findOrFail($this->makeAdmission($this->schoolId, array_merge([
            'intake_session_id' => $this->makeIntakeSession($this->schoolId, ['application_fee' => $fee]),
            'status' => Admission::STATUS_SUBMITTED, 'submitted_at' => now(),
        ], $attributes)));
    }

    private function payment(Admission $admission, array $attributes = []): ApplicationPayment
    {
        return ApplicationPayment::create(array_merge([
            'school_id' => $admission->school_id, 'admission_id' => $admission->id,
            'applicant_id' => $admission->applicant_id, 'amount' => '50000.00', 'currency' => 'UGX',
            'method' => 'marzpay', 'status' => ApplicationPayment::STATUS_PENDING,
            'reference' => 'APP-' . $admission->id, 'gateway_txn_id' => 'txn-' . $admission->id,
        ], $attributes));
    }

    private function evidence(ApplicationPayment $payment, array $overrides = []): VerifiedApplicationPayment
    {
        $fields = array_merge([
            'paymentId' => (int) $payment->id, 'schoolId' => (int) $payment->school_id,
            'provider' => $payment->method, 'reference' => $payment->reference,
            'transactionId' => $payment->gateway_txn_id, 'amount' => (string) $payment->amount,
            'currency' => $payment->currency, 'status' => 'paid', 'payload' => ['verified_fixture' => true],
        ], $overrides);
        return new VerifiedApplicationPayment(...$fields);
    }

    private function applicantApplication(string $status): Admission
    {
        $applicant = $this->makeApplicant($this->schoolId);
        $this->be($applicant, 'applicant');
        return $this->admission('50000.00', [
            'applicant_id' => $applicant->id, 'status' => $status,
            'submitted_at' => $status === Admission::STATUS_DRAFT ? null : now(),
        ]);
    }

    public function test_unpaid_application_submission_remains_valid_and_payment_is_not_a_blocker(): void
    {
        $admission = $this->applicantApplication(Admission::STATUS_DRAFT);
        $admission = $this->completeApplicationFields($admission, [
            'programme_id' => $this->makeProgramme($this->schoolId),
        ]);
        AdmissionDocumentRequirement::create([
            'school_id' => $this->schoolId, 'key' => 'optional', 'label' => 'Optional', 'is_required' => false,
        ]);
        $this->assertFalse(ApplicationFee::isSettled($admission));
        $this->assertSame([], ApplicationProgress::blockers($admission));
        $this->post(route('applicant.application.submit'), ['declaration' => '1'])
            ->assertRedirect(route('applicant.dashboard'));
        $this->assertSame(Admission::STATUS_SUBMITTED, $admission->fresh()->status);
        $this->assertNotNull($admission->fresh()->submitted_at);
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
        $this->assertSame(0, ApplicationPayment::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsubmittedStates')]
    public function test_unsubmitted_application_cannot_start_a_new_online_payment(string $status): void
    {
        $this->applicantApplication($status);
        Http::fake();
        $this->post(route('applicant.payment.gateway.start', 'marzpay'), ['phone_number' => '0700111222'])
            ->assertSessionHas('error');
        $this->assertSame(0, ApplicationPayment::count());
        Http::assertNothingSent();
    }

    public static function unsubmittedStates(): array
    {
        return [[Admission::STATUS_DRAFT], [Admission::STATUS_NEEDS_CORRECTION]];
    }

    public function test_submitted_unpaid_application_cannot_start_new_legacy_marzpay_orders(): void
    {
        $admission = $this->applicantApplication(Admission::STATUS_SUBMITTED);
        Http::fake(['wallet.wearemarz.com/api/v1/collect-money' => Http::response([
            'data' => ['transaction' => ['uuid' => 'new-transaction', 'status' => 'processing']],
        ])]);
        $this->post(route('applicant.payment.gateway.start', 'marzpay'), ['phone_number' => '0700111222'])
            ->assertRedirect();
        $this->assertSame(0, $admission->payments()->count());
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('feeTotals')]
    public function test_fee_status_uses_exact_cumulative_paid_amounts(string $required, array $amounts, string $expected): void
    {
        $admission = $this->admission($required);
        foreach ($amounts as $amount) {
            $this->payment($admission, ['amount' => $amount, 'method' => 'cash', 'status' => ApplicationPayment::STATUS_PAID]);
        }
        $this->assertSame($expected, ApplicationFee::refreshStatus($admission));
        $this->assertSame($expected, $admission->fresh()->fee_status);
    }

    public static function feeTotals(): array
    {
        return [
            'partial' => ['50000.00', ['10000.00'], Admission::FEE_UNPAID],
            'cumulative' => ['50000.00', ['25000.00', '25000.00'], Admission::FEE_PAID],
            'exact' => ['50000.00', ['50000.00'], Admission::FEE_PAID],
            'overpayment' => ['50000.00', ['70000.00'], Admission::FEE_PAID],
            'zero' => ['0.00', [], Admission::FEE_WAIVED],
            'negative' => ['-1.00', [], Admission::FEE_WAIVED],
            'decimal sum' => ['0.30', ['0.10', '0.20'], Admission::FEE_PAID],
            'one cent short' => ['50000.01', ['25000.00', '25000.00'], Admission::FEE_UNPAID],
        ];
    }

    public function test_explicit_waiver_remains_waived(): void
    {
        $admission = $this->admission();
        $this->payment($admission, ['method' => 'waived', 'status' => ApplicationPayment::STATUS_WAIVED]);
        $this->assertSame(Admission::FEE_WAIVED, ApplicationFee::refreshStatus($admission));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedStates')]
    public function test_failed_and_rejected_payments_never_contribute_to_the_fee(string $status): void
    {
        $admission = $this->admission();
        $this->payment($admission, ['status' => $status]);
        $this->assertSame(Admission::FEE_UNPAID, ApplicationFee::refreshStatus($admission));
    }

    public static function excludedStates(): array
    {
        return [[ApplicationPayment::STATUS_FAILED], [ApplicationPayment::STATUS_REJECTED]];
    }

    public function test_partial_payment_with_a_valid_pending_payment_is_pending(): void
    {
        $admission = $this->admission();
        $this->payment($admission, ['method' => 'cash', 'amount' => '10000.00', 'status' => ApplicationPayment::STATUS_PAID]);
        $this->payment($admission, ['amount' => '40000.00']);
        $this->assertSame(Admission::FEE_PENDING, ApplicationFee::refreshStatus($admission));
    }

    public function test_wrong_school_and_currency_rows_do_not_contribute_and_legacy_rows_are_preserved(): void
    {
        $admission = $this->admission();
        $this->payment($admission, ['school_id' => $this->makeSchool(), 'status' => ApplicationPayment::STATUS_PAID]);
        $this->payment($admission, ['currency' => 'KES', 'status' => ApplicationPayment::STATUS_PAID]);
        $this->assertSame(Admission::FEE_UNPAID, ApplicationFee::refreshStatus($admission));
        $legacy = $this->payment($admission, ['method' => 'cash', 'currency' => null, 'status' => ApplicationPayment::STATUS_PAID]);
        $this->assertSame(Admission::FEE_PAID, ApplicationFee::refreshStatus($admission));
        $this->assertNull($legacy->fresh()->currency);
        $this->assertSame(3, $admission->payments()->count());
    }

    public function test_duplicate_settlement_does_not_duplicate_value_audit_timestamp_or_email(): void
    {
        foreach (['smtp_user' => 'fixture', 'smtp_pass' => 'fixture', 'smtp_host' => 'smtp.example.test', 'smtp_port' => '587'] as $key => $value) {
            DB::table('global_settings')->insert(['key' => $key, 'value' => $value]);
        }
        $admission = $this->admission();
        $payment = $this->payment($admission);
        $evidence = $this->evidence($payment);
        $this->assertSame(ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::apply($evidence));
        $timestamp = $payment->fresh()->paid_at;
        $audits = AuditLog::count();
        $this->assertSame(ApplicationPaymentSettlement::ALREADY_SETTLED, ApplicationPaymentSettlement::apply($evidence));
        $this->assertSame(1, ApplicationPayment::count());
        $this->assertSame('50000.00', $payment->fresh()->amount);
        $this->assertEquals($timestamp, $payment->fresh()->paid_at);
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(Admission::FEE_PAID, $admission->fresh()->fee_status);
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_one_provider_transaction_cannot_settle_two_internal_payments_even_across_schools(): void
    {
        $first = $this->payment($this->admission());
        $secondSchool = $this->makeSchool();
        $secondAdmission = $this->admission('50000.00', ['school_id' => $secondSchool]);
        $second = $this->payment($secondAdmission, ['gateway_txn_id' => $first->gateway_txn_id]);
        $this->assertSame(ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::apply($this->evidence($first)));
        $this->assertSame(ApplicationPaymentSettlement::REJECTED, ApplicationPaymentSettlement::apply($this->evidence($second)));
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(Admission::FEE_UNPAID, $secondAdmission->fresh()->fee_status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mismatches')]
    public function test_mismatched_verified_evidence_cannot_settle(array $override): void
    {
        $admission = $this->admission();
        $payment = $this->payment($admission);
        $this->assertSame(ApplicationPaymentSettlement::REJECTED,
            ApplicationPaymentSettlement::apply($this->evidence($payment, $override)));
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->paid_at);
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
        Mail::assertNothingSent();
    }

    public static function mismatches(): array
    {
        return [
            'underpaid' => [['amount' => '49999.99']],
            'unexpected higher amount' => [['amount' => '50000.01']],
            'extra precision' => [['amount' => '50000.001']],
            'currency' => [['currency' => 'KES']],
            'missing currency' => [['currency' => '']],
            'merchant reference' => [['reference' => 'OTHER-APPLICATION']],
            'missing reference' => [['reference' => '']],
            'transaction' => [['transactionId' => 'another-transaction']],
            'missing transaction' => [['transactionId' => '']],
            'provider' => [['provider' => 'stripe']],
            'school' => [['schoolId' => 999999]],
            'payment identity' => [['paymentId' => 999999]],
        ];
    }

    public function test_payment_school_must_match_admission_school(): void
    {
        $payment = $this->payment($this->admission(), ['school_id' => $this->makeSchool()]);
        $this->assertSame(ApplicationPaymentSettlement::REJECTED, ApplicationPaymentSettlement::apply($this->evidence($payment)));
    }

    public function test_rejected_and_waived_internal_payments_cannot_be_reopened_by_online_settlement(): void
    {
        foreach ([ApplicationPayment::STATUS_REJECTED, ApplicationPayment::STATUS_WAIVED] as $status) {
            $payment = $this->payment($this->admission(), ['status' => $status]);
            $this->assertSame(ApplicationPaymentSettlement::REJECTED, ApplicationPaymentSettlement::apply($this->evidence($payment)));
            $this->assertSame($status, $payment->fresh()->status);
        }
    }

    public function test_marzpay_webhook_and_poll_share_bound_verification_and_idempotency(): void
    {
        $admission = $this->applicantApplication(Admission::STATUS_SUBMITTED);
        $payment = $this->payment($admission);
        $this->fakeMarzpay($payment);
        $this->webhook($payment)->assertOk();
        $firstPaidAt = $payment->fresh()->paid_at;
        $this->get(route('applicant.payment.marzpay.status', $payment->id))->assertJson(['status' => 'paid']);
        $this->webhook($payment)->assertOk();
        $this->assertEquals($firstPaidAt, $payment->fresh()->paid_at);
        $this->assertSame(Admission::FEE_PAID, $admission->fresh()->fee_status);
        $this->assertSame(1, ApplicationPayment::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('marzpayMismatches')]
    public function test_marzpay_requires_provider_supplied_identity_amount_and_currency(array $override): void
    {
        $admission = $this->applicantApplication(Admission::STATUS_SUBMITTED);
        $payment = $this->payment($admission);
        $this->fakeMarzpay($payment, $override);
        $this->webhook($payment);
        $this->get(route('applicant.payment.marzpay.status', $payment->id));
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
    }

    public static function marzpayMismatches(): array
    {
        return [
            [['transaction' => ['uuid' => 'wrong']]],
            [['transaction' => ['reference' => 'wrong']]],
            [['collection' => ['amount' => ['raw' => 1]]]],
            [['collection' => ['amount' => ['currency' => 'KES']]]],
            [['transaction' => ['reference' => null]]],
            [['collection' => ['amount' => ['raw' => null]]]],
            [['collection' => ['amount' => ['currency' => null]]]],
        ];
    }

    private function fakeMarzpay(ApplicationPayment $payment, array $override = []): void
    {
        // Laravel merges fake callbacks; replace the factory so a later
        // provider-state fixture really supersedes the earlier response.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['wallet.wearemarz.com/api/v1/collect-money/*' => Http::response(['data' => array_replace_recursive([
            'transaction' => ['uuid' => $payment->gateway_txn_id, 'reference' => $payment->reference, 'status' => 'successful'],
            'collection' => ['amount' => ['raw' => '50000.00', 'currency' => 'UGX']],
        ], $override)])]);
    }

    private function webhook(ApplicationPayment $payment, string $event = 'collection.completed', ?string $uuid = null)
    {
        return $this->postJson(route('webhooks.marzpay'), [
            'event_type' => $event, 'transaction' => ['uuid' => $uuid ?? $payment->gateway_txn_id],
            'metadata' => [['context' => 'application'], ['context_id' => $payment->id]],
        ]);
    }

    public function test_webhook_claims_do_not_mark_payment_failed_when_provider_verification_is_unavailable(): void
    {
        $payment = $this->payment($this->admission());
        Http::fake(['wallet.wearemarz.com/*' => Http::response([], 503)]);
        $this->webhook($payment, 'collection.failed')->assertStatus(503);
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_verified_failure_refreshes_fee_status_and_cannot_downgrade_a_paid_transaction(): void
    {
        $admission = $this->admission();
        $payment = $this->payment($admission);
        ApplicationFee::refreshStatus($admission);
        $this->fakeMarzpay($payment, ['transaction' => ['status' => 'failed']]);
        $this->webhook($payment)->assertOk();
        $this->assertSame(ApplicationPayment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
        $this->fakeMarzpay($payment);
        $this->webhook($payment)->assertOk();
        $this->fakeMarzpay($payment, ['transaction' => ['status' => 'failed']]);
        $this->webhook($payment, 'collection.failed')->assertOk();
        $this->assertSame(ApplicationPayment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_webhook_cannot_select_another_payments_transaction(): void
    {
        $payment = $this->payment($this->admission());
        Http::fake();
        $this->webhook($payment, 'collection.completed', 'another-transaction')->assertOk();
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_declining_offline_tuition_does_not_falsely_credit_the_invoice(): void
    {
        $accountant = User::factory()->create(['role_id' => 4, 'school_id' => $this->schoolId, 'account_status' => 'active']);
        $invoice = StudentFeeManager::create([
            'title' => 'Tuition', 'amount' => 50000, 'total_amount' => 50000, 'class_id' => 0,
            'student_id' => 1, 'school_id' => $this->schoolId, 'session_id' => 1,
            'payment_method' => 'offline', 'status' => 'pending', 'paid_amount' => 0, 'timestamp' => time(),
        ]);
        $this->actingAs($accountant)->get(route('accountant.update_offline_payment', [$invoice->id, 'decline']))->assertRedirect();
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertEquals(0, $invoice->fresh()->paid_amount);
        $this->assertEquals(50000, $invoice->fresh()->total_amount - $invoice->fresh()->paid_amount);
    }

    public function test_money_parser_never_rounds_invalid_precision(): void
    {
        $this->assertSame(30, DecimalAmount::minorUnits('0.30'));
        $this->assertNull(DecimalAmount::minorUnits('0.301'));
        $this->assertNull(DecimalAmount::minorUnits('1e3'));
        $this->assertNull(DecimalAmount::minorUnits(INF));
    }

    public function test_missing_stored_transaction_identity_fails_closed_unless_adapter_can_bind_it(): void
    {
        $payment = $this->payment($this->admission(), ['gateway_txn_id' => null]);
        $this->assertSame(ApplicationPaymentSettlement::REJECTED,
            ApplicationPaymentSettlement::apply($this->evidence($payment, ['transactionId' => 'verified-id'])));
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_first_transaction_binding_requires_an_unambiguous_merchant_reference(): void
    {
        $payment = $this->payment($this->admission(), ['method' => 'flutterwave', 'gateway_txn_id' => null]);
        $this->payment($this->admission(), ['method' => 'flutterwave', 'reference' => $payment->reference]);
        $this->assertSame(ApplicationPaymentSettlement::REJECTED,
            ApplicationPaymentSettlement::apply($this->evidence($payment, ['transactionId' => 'verified-id', 'canBindTransaction' => true])));
    }

    public function test_rolled_back_settlement_never_notifies_or_persists_payment_value(): void
    {
        $admission = $this->admission();
        $payment = $this->payment($admission);
        DB::beginTransaction();
        $this->assertSame(ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::apply($this->evidence($payment)));
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
        $this->assertNull($payment->fresh()->paid_at);
        Mail::assertNothingSent();
    }

    public function test_partial_verified_payment_email_does_not_claim_the_application_fee_is_complete(): void
    {
        foreach (['smtp_user' => 'fixture', 'smtp_pass' => 'fixture', 'smtp_host' => 'smtp.example.test', 'smtp_port' => '587'] as $key => $value) {
            DB::table('global_settings')->insert(['key' => $key, 'value' => $value]);
        }
        $admission = $this->admission();
        $payment = $this->payment($admission, ['amount' => '10000.00']);
        $this->assertSame(ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::apply($this->evidence($payment)));
        $this->assertSame(Admission::FEE_UNPAID, $admission->fresh()->fee_status);
        Mail::assertSent(ApplicantNotificationEmail::class, fn ($mail) =>
            str_contains(implode(' ', $mail->data['paragraphs']), 'not yet fully paid'));
    }
}
