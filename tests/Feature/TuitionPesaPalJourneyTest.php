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
    private const SECOND_GUID = '8e6b62d9-883e-440f-a63e-e1105bbfadc4';
    private int $school;
    private User $student;
    private string $reference = '';
    private int $orders = 0;
    private int $code = 0;
    private string $description = 'INVALID';
    private string $amount = '5000.00';
    private string $channel = 'Card';
    private string $trackingId = self::GUID;
    private bool $timeout = false;
    private bool $statusUnavailable = false;
    private string $checkoutHost = 'cybqa.pesapal.com';

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
                return Http::response(['order_tracking_id' => $this->trackingId, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://' . $this->checkoutHost . '/pay?OrderTrackingId=' . $this->trackingId, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) { if ($this->statusUnavailable) { return Http::response([], 503); } return Http::response(['merchant_reference' => $this->reference,
                'amount' => $this->amount, 'currency' => 'UGX', 'status_code' => $this->code, 'payment_status_description' => $this->description,
                'payment_method' => $this->channel, 'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null]); }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function fee(array $extra = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_merge(['school_id' => $this->school, 'student_id' => $this->student->id,
            'title' => 'School fees', 'total_amount' => 5000, 'amount' => 5000, 'paid_amount' => 0, 'class_id' => 0,
            'payment_method' => '', 'status' => 'unpaid'], $extra));
    }

    public function test_fee_manager_pay_now_and_invoice_form_reach_hosted_checkout(): void
    {
        $fee = $this->fee(['session_id' => 1, 'timestamp' => time()]);
        $page = route('student.FeePayment', $fee->id);
        $this->actingAs($this->student)->get(route('student.fee_manager.list'))->assertOk()->assertSee($page, false)->assertSee('Pay Now');
        $this->get($page)->assertOk()->assertSee(route('student.payment.pesapal.start', $fee->id), false)->assertSee('name="payer_phone"', false)->assertDontSee('target="_blank"', false);
        Http::assertNothingSent();
        $this->from($page)->post(route('student.payment.pesapal.start', $fee->id), ['payment_option' => 'custom', 'payment_amount' => '500.00', 'payer_phone' => '+256 (771) 234-567'])
            ->assertRedirect('https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'SubmitOrderRequest')
            && $request['amount'] === '500.00' && $request['billing_address']['phone_number'] === '+256771234567'
            && ! isset($request['payment_method']) && ! isset($request['payment_option']));
        $orders = Http::recorded(fn ($request) => str_contains($request->url(), 'SubmitOrderRequest'));
        $request = $orders->sole()[0];
        $this->assertSame(\App\Support\Payments\PesaPalService::SANDBOX_URL . '/api/Transactions/SubmitOrderRequest', $request->url());
        $this->assertSame('UGX', $request['currency']);
        $this->assertSame(route('tuition.pesapal.callback'), $request['callback_url']);
        $this->assertSame(self::GUID, $request['notification_id']);
        $this->assertEqualsCanonicalizing(['id', 'amount', 'currency', 'description', 'callback_url', 'notification_id', 'billing_address'], array_keys($request->data()));
        $this->assertSame(['email_address' => 'student@example.test', 'phone_number' => '+256771234567', 'first_name' => 'Student', 'last_name' => 'Student'], $request['billing_address']);
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_student_checkout_uses_school_currency_and_email_without_forcing_phone_country_or_method(): void
    {
        DB::table('schools')->where('id', $this->school)->update(['school_currency' => 'usd']);
        $fee = $this->fee();
        $this->actingAs($this->student)->post(route('student.payment.pesapal.start', $fee->id), ['payment_option' => 'balance'])
            ->assertRedirect('https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID);
        $orders = Http::recorded(fn ($request) => str_contains($request->url(), 'SubmitOrderRequest'));
        $request = $orders->sole()[0];
        $this->assertSame('USD', $request['currency']);
        $this->assertSame('5000.00', $request['amount']);
        $this->assertSame(['email_address' => 'student@example.test', 'phone_number' => '', 'first_name' => 'Student', 'last_name' => 'Student'], $request['billing_address']);
        $this->assertArrayNotHasKey('payment_method', $request->data());
        $this->assertArrayNotHasKey('country_code', $request['billing_address']);
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_resume_reuses_valid_checkout_when_status_endpoint_is_unavailable(): void
    {
        $fee = $this->fee(); TuitionPesaPalPayment::start($fee);
        $this->statusUnavailable = true;
        $this->actingAs($this->student)->from(route('student.FeePayment', $fee->id))
            ->post(route('student.payment.pesapal.start', $fee->id))
            ->assertRedirect('https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID);
        $this->assertSame(1, $this->orders);
        $this->assertSame('pending', TuitionPayment::sole()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_large_invoice_id_still_has_a_provider_valid_reference(): void
    {
        $fee = $this->fee(); $fee->id = 123456; $fee->save();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->assertLessThanOrEqual(50, strlen($payment->reference));
        $this->assertSame(1, $this->orders);
    }

    public function test_invalid_provider_checkout_url_does_not_redirect_or_credit(): void
    {
        $this->checkoutHost = 'untrusted.example.test'; $fee = $this->fee();
        $page = route('student.FeePayment', $fee->id);
        $this->actingAs($this->student)->from($page)->post(route('student.payment.pesapal.start', $fee->id))
            ->assertRedirect($page)->assertSessionHasErrors('payment');
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertArrayNotHasKey('checkout_url', TuitionPayment::sole()->gateway_payload);
    }

    public static function environments(): array
    {
        return [['sandbox', 'cybqa.pesapal.com'], ['live', 'pay.pesapal.com']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('environments')]
    public function test_saved_settings_control_student_parent_and_applicant_requests(string $environment, string $host): void
    {
        // Prime the old token cache, then save distinct synthetic credentials.
        \App\Support\Payments\PesaPalService::forSchool($this->school)->authenticate();
        $this->actingAs($this->makeAdminUser($this->school))->post(route('admin.hei_admissions.payment.pesapal.settings.save'),
            ['environment' => $environment, 'consumer_key' => 'saved-' . $environment, 'consumer_secret' => 'synthetic-secret'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $configuration = \App\Support\Payments\PesaPalConfiguration::forSchool($this->school);
        $this->assertSame($environment, $configuration->environment);
        $this->assertSame('saved-' . $environment, $configuration->credentials()['consumer_key']);
        $this->assertNull($configuration->notificationId);
        (new \App\Support\Payments\PesaPalIpnRegistration)->persist($configuration,
            ['ipn_id' => self::GUID, 'url' => route('applicant.pesapal.ipn'), 'method' => 'GET', 'active' => true, 'status' => 200],
            route('applicant.pesapal.ipn'), true);
        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertOk()->assertSee('IPN registered')->assertDontSee('IPN not registered')
            ->assertDontSee('synthetic-secret')->assertDontSee('saved-' . $environment);
        $this->checkoutHost = $host;
        $fee = $this->fee();
        $this->actingAs($this->student)->post(route('student.payment.pesapal.start', $fee->id))
            ->assertRedirect('https://' . $host . '/pay?OrderTrackingId=' . self::GUID);
        $parent = User::create(['school_id' => $this->school, 'role_id' => 6, 'name' => 'Parent', 'email' => 'saved-parent@example.test']);
        $this->trackingId = self::SECOND_GUID;
        $parentFee = $this->fee(['parent_id' => $parent->id]);
        $this->actingAs($parent)->post(route('parent.payment.pesapal.start', $parentFee->id))
            ->assertRedirect('https://' . $host . '/pay?OrderTrackingId=' . self::SECOND_GUID);
        $admission = \App\Models\Admission::findOrFail($this->makeAdmission($this->school,
            ['status' => \App\Models\Admission::STATUS_SUBMITTED, 'submitted_at' => now(),
                'intake_session_id' => $this->makeIntakeSession($this->school, ['application_fee' => 5000, 'is_open' => 1])]));
        $this->trackingId = '9e6b62d9-883e-440f-a63e-e1105bbfadc5';
        \App\Support\Payments\ApplicantPesaPalPayment::start($admission);
        $base = $environment === 'live' ? \App\Support\Payments\PesaPalService::LIVE_URL : \App\Support\Payments\PesaPalService::SANDBOX_URL;
        Http::assertSent(fn ($request) => $request->url() === $base . '/api/Auth/RequestToken'
            && $request['consumer_key'] === 'saved-' . $environment && $request['consumer_secret'] === 'synthetic-secret');
        $orders = Http::recorded(fn ($request) => str_contains($request->url(), 'SubmitOrderRequest'));
        $this->assertCount(3, $orders);
        foreach ($orders as [$request]) {
            $this->assertSame($base . '/api/Transactions/SubmitOrderRequest', $request->url());
            $this->assertSame(self::GUID, $request['notification_id']);
            $this->assertFalse(isset($request['consumer_secret']));
        }
    }

    public function test_persisted_checkout_url_is_revalidated_before_resume(): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $payment->update(['gateway_payload' => array_merge($payment->gateway_payload, ['checkout_url' => 'https://untrusted.example.test/pay'])]);
        $page = route('student.FeePayment', $fee->id);
        $this->actingAs($this->student)->from($page)->post(route('student.payment.pesapal.start', $fee->id))
            ->assertRedirect($page)->assertSessionHas('error');
        $this->assertSame(1, $this->orders);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_ambiguous_submission_returns_to_invoice_without_redirecting_or_retrying(): void
    {
        $fee = $this->fee(); $this->timeout = true;
        $page = route('student.FeePayment', $fee->id);
        $this->actingAs($this->student)->from($page)->post(route('student.payment.pesapal.start', $fee->id))
            ->assertRedirect($page)->assertSessionHasErrors('payment');
        $this->post(route('student.payment.pesapal.start', $fee->id))->assertRedirect($page)->assertSessionHas('error');
        $this->assertSame(1, $this->orders);
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public static function providerMethods(): array
    {
        return [['MTN Mobile Money'], ['Airtel Money'], ['Visa']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerMethods')]
    public function test_provider_selected_method_is_recorded_only_after_verified_ipn(string $method): void
    {
        $fee = $this->fee(); $payment = TuitionPesaPalPayment::start($fee);
        $url = route('applicant.pesapal.ipn', ['OrderTrackingId' => self::GUID,
            'OrderMerchantReference' => $payment->reference, 'status' => 'paid']);
        $this->get($url)->assertOk();
        $this->assertSame('unpaid', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->channel = $method; $this->code = 1; $this->description = 'COMPLETED';
        $this->get($url)->assertOk(); $this->get($url)->assertOk();
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame($method, $payment->fresh()->gateway_payload['payment_channel']);
    }

    public function test_prior_payment_leaves_only_remaining_balance_for_online_payment(): void
    {
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_22_000000_add_method_to_student_opening_payments.php'))->up();
        \App\Models\StudentProfile::create(['school_id' => $this->school, 'user_id' => $this->student->id, 'was_existing_at_portal_creation' => true]);
        $fee = $this->fee();
        app(\App\Support\Payments\StudentOpeningPayments::class)->change($this->makeAdminUser($this->school), $fee->id, [
            'action' => 'record', 'amount' => '3000.00', 'paid_on' => now()->subMonth()->toDateString(), 'method' => 'bank_transfer',
            'reference' => 'OLD-RECEIPT-42', 'reason' => 'Original bank deposit verified.']);
        $payment = TuitionPesaPalPayment::start($fee);
        $this->assertEquals(2000, $payment->amount);
        $this->amount = '2000.00'; $this->code = 1; $this->description = 'COMPLETED';
        TuitionPesaPalPayment::reconcile($payment);
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertEquals(5000, $fee->fresh()->paid_amount);
        $this->assertEquals(3000, $fee->fresh()->opening_paid_amount);
        $this->assertEquals(2000, $fee->fresh()->collected_amount);
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame('Card', $payment->fresh()->gateway_payload['payment_channel']);
        $this->code = 3; $this->description = 'REVERSED';
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertEquals(3000, $fee->fresh()->paid_amount);
        $this->assertEquals(3000, $fee->fresh()->opening_paid_amount);
        $this->assertEquals(0, $fee->fresh()->collected_amount);
    }

    public function test_multiple_online_channels_each_keep_their_verified_amount_and_channel(): void
    {
        $fee = $this->fee(['paid_amount' => 200]);
        $this->amount = '2400.00'; $this->channel = 'Mobile Money'; $this->code = 1; $this->description = 'COMPLETED';
        $mobileMoney = TuitionPesaPalPayment::start($fee, null, 'custom', '2400.00');
        TuitionPesaPalPayment::reconcile($mobileMoney);
        $this->assertSame('Mobile Money', $mobileMoney->fresh()->gateway_payload['payment_channel']);
        $this->assertEquals(2400, $fee->fresh()->total_amount - $fee->fresh()->paid_amount);

        $this->amount = '2400.00'; $this->channel = 'Card';
        $this->trackingId = self::SECOND_GUID;
        $card = TuitionPesaPalPayment::start($fee, null, 'custom', '2400.00');
        TuitionPesaPalPayment::reconcile($card);
        $this->assertSame('Card', $card->fresh()->gateway_payload['payment_channel']);
        $this->assertEquals(5000, $fee->fresh()->paid_amount);
        $this->assertEquals(0, $fee->fresh()->total_amount - $fee->fresh()->paid_amount);
        $this->assertCount(2, TuitionPayment::where('fee_id', $fee->id)->where('status', 'paid')->get());
    }

    public function test_scholarship_only_charges_net_fee_and_verified_cash_remains_separate(): void
    {
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        $fee = $this->fee();
        app(\App\Support\Billing\StudentFeeAwards::class)->change($this->makeAdminUser($this->school), $fee->id,
            ['action' => 'grant', 'type' => 'scholarship', 'amount' => '2000.00', 'reason' => 'Approved merit scholarship.']);
        $payment = TuitionPesaPalPayment::start($fee);
        $this->assertEquals(3000, $payment->amount);
        $this->amount = '3000.00'; $this->code = 1; $this->description = 'COMPLETED';
        TuitionPesaPalPayment::reconcile($payment);
        $this->assertEquals(3000, $fee->fresh()->paid_amount);
        $this->assertSame('paid', $fee->fresh()->status);
        $this->assertSame(1, TuitionPayment::count());
    }

    public function test_checkout_resumes_same_order_and_only_verified_success_credits_once(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee, '+256771234567');
        $this->assertSame('unpaid', $fee->fresh()->status);
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
