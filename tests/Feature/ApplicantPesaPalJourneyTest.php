<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationWorkflow;
use App\Support\Payments\ApplicantPesaPalPayment;
use App\Support\Payments\PesaPalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicantPesaPalJourneyTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;
    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private string $reference = '';
    private int $code = 0;
    private string $description = 'INVALID';
    private array $statusChanges = [];
    private bool $timeout = false;
    private int $orderRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test');
        URL::forceScheme('https');
        DB::table('global_settings')->insert([
            ['key' => 'primary_school_id', 'value' => (string) $this->school],
            ['key' => 'system_currency', 'value' => 'UGX'],
        ]);
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture', 'notification_id' => self::GUID])]);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) {
                return Http::response(['token' => 'fixture-token', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $this->orderRequests++;
                $this->reference = $request['id'];
                if ($this->timeout) { throw new \Illuminate\Http\Client\ConnectionException('fixture timeout'); }
                return Http::response(['order_tracking_id' => self::GUID, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                return Http::response(array_merge(['merchant_reference' => $this->reference, 'amount' => '50000.00', 'currency' => 'UGX',
                    'status_code' => $this->code, 'payment_status_description' => $this->description, 'status' => '200', 'error' => null], $this->statusChanges));
            }
            throw new \RuntimeException('Unexpected fixture endpoint');
        });
    }

    private function application(string $source = 'public', array $overrides = []): Admission
    {
        $applicant = $source === 'public' ? $this->makeApplicant($this->school) : null;
        return Admission::findOrFail($this->makeAdmission($this->school, array_merge([
            'source' => $source, 'status' => 'submitted', 'submitted_at' => now(), 'applicant_id' => $applicant?->id,
            'intake_session_id' => $this->makeIntakeSession($this->school, ['application_fee' => '50000.00']),
        ], $overrides)));
    }

    public static function channels(): array { return [['public'], ['staff_entry']]; }

    public function test_applicant_checkout_and_settlement_leave_subscription_and_admin_records_unchanged(): void
    {
        config(['app.bypass_subscription'=>false]);
        $admission=$this->application();$admin=$this->makeAdminUser($this->school);
        DB::table('packages')->where('id',1)->update(['name'=>'Existing plan','price'=>100]);
        if(!\Illuminate\Support\Facades\Schema::hasTable('roles')){
            \Illuminate\Support\Facades\Schema::create('roles',function(\Illuminate\Database\Schema\Blueprint $table){$table->id();$table->integer('role_id');$table->string('name');});
        }
        DB::table('roles')->insert(['role_id'=>2,'name'=>'School admin']);
        $rbacMigration=require database_path('migrations/2026_09_23_000003_create_rbac_tables.php');$rbacMigration->up();
        $staffRole=DB::table('staff_roles')->insertGetId(['school_id'=>$this->school,'name'=>'Existing finance role']);
        DB::table('staff_role_permissions')->insert(['staff_role_id'=>$staffRole,'permission'=>'subscription.manage']);
        DB::table('user_staff_roles')->insert(['school_id'=>$this->school,'user_id'=>$admin->id,'staff_role_id'=>$staffRole]);
        DB::table('user_permissions')->insert(['school_id'=>$this->school,'user_id'=>$admin->id,'permission'=>'admins.manage']);
        DB::table('subscriptions')->where('school_id',$this->school)->update(['active'=>0]);
        DB::table('subscriptions')->insert(['school_id'=>$this->school,'package_id'=>1,'paid_amount'=>100,
            'active'=>0,'expire_date'=>strtotime('-30 days')]);
        $tables=array_filter(['subscriptions','packages','payment_history','schools','users','roles','permissions','role_permissions','user_roles',
            'staff_roles','staff_role_permissions','user_staff_roles','user_permissions','global_settings'],
            fn($table)=>\Illuminate\Support\Facades\Schema::hasTable($table));
        $snapshot=fn()=>array_map(fn($table)=>DB::table($table)->orderBy('id')->get()->toJson(),$tables);
        $before=$snapshot();$payment=ApplicantPesaPalPayment::start($admission);
        $this->code=1;$this->description='COMPLETED';ApplicantPesaPalPayment::reconcile($payment);
        ApplicantPesaPalPayment::reconcile($payment);
        $this->assertSame($before,$snapshot());
        $this->assertTrue($admission->fresh()->isFeeSettled());
        $this->assertFalse(config('app.bypass_subscription'));
    }

    public function test_verified_expiry_is_recorded_without_settling_the_fee(): void
    {
        $admission=$this->application();$payment=ApplicantPesaPalPayment::start($admission);
        $this->code=2;$this->description='FAILED';$this->statusChanges=['description'=>'Expired'];
        ApplicantPesaPalPayment::reconcile($payment);
        $payment=$payment->fresh();
        $this->assertSame('failed',$payment->status);
        $this->assertSame('EXPIRED',$payment->gateway_payload['verified_failure_reason']);
        $this->assertSame('expired',\App\Support\Payments\PesaPalPaymentExperience::forPayment($payment)['state']);
        $this->assertSame(1,$admission->payments()->count());
        $this->assertFalse($admission->fresh()->isFeeSettled());
    }

    public function test_expiry_reason_from_mismatched_order_does_not_change_the_attempt(): void
    {
        $admission=$this->application();$payment=ApplicantPesaPalPayment::start($admission);
        $this->code=2;$this->description='FAILED';$this->statusChanges=['description'=>'Expired','amount'=>'49999'];
        $before=$payment->fresh()->getAttributes();
        try { ApplicantPesaPalPayment::reconcile($payment);$this->fail('Mismatched evidence accepted'); }
        catch(PesaPalException) { $this->assertSame($before,$payment->fresh()->getAttributes()); }
        $this->assertFalse($admission->fresh()->isFeeSettled());
        $this->assertSame(1,$admission->payments()->count());
    }

    public function test_sandbox_start_authority_runs_before_any_reservation_or_provider_call(): void
    {
        $a=$this->application();config(['sandbox.checkout.start_guard'=>fn()=>throw new PesaPalException()]);
        try{ApplicantPesaPalPayment::start($a);$this->fail();}catch(PesaPalException){$this->assertSame(0,$a->payments()->count());Http::assertNothingSent();}
    }
    public function test_sandbox_reconciliation_authority_blocks_provider_calls_and_settlement(): void
    {
        $p=ApplicantPesaPalPayment::start($this->application());$this->code=1;$this->description='COMPLETED';
        config(['sandbox.checkout.reconcile_guard'=>fn()=>throw new PesaPalException()]);$before=count(Http::recorded());
        try{ApplicantPesaPalPayment::reconcile($p);$this->fail();}catch(PesaPalException){$this->assertSame($before,count(Http::recorded()));$this->assertSame('pending',$p->fresh()->status);}
    }

    public function test_configuration_rotation_preserves_verification_of_historical_payment(): void
    {
        $admission = $this->application();
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->code = 1; $this->description = 'COMPLETED';
        ApplicantPesaPalPayment::reconcile($payment);
        $oldId = $payment->gateway_payload['configuration_id'];
        $this->be($this->makeAdminUser($this->school));
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'sandbox', 'consumer_key' => 'rotated-fixture', 'consumer_secret' => 'rotated-fixture',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, (int) PaymentMethods::findOrFail($oldId)->status);
        $this->assertNotSame($oldId, \App\Support\Payments\PesaPalConfiguration::forSchool($this->school)->configurationId);
        $this->code = 3; $this->description = 'REVERSED';
        ApplicantPesaPalPayment::reconcile($payment->fresh());
        $this->assertSame('REVERSED', $payment->fresh()->gateway_payload['classification']);
        $this->assertFalse($admission->fresh()->isFeeSettled());
    }

    #[DataProvider('channels')]
    public function test_both_channels_share_checkout_and_completed_ipn_settlement(string $source): void
    {
        $admission = $this->application($source);
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->assertSame('50000.00', $payment->amount);
        $this->assertSame('pesapal', $payment->method);
        $this->code = 1; $this->description = 'COMPLETED';
        $url = route('applicant.pesapal.ipn', ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $payment->reference, 'OrderNotificationType' => 'IPNCHANGE']);
        $this->get($url)->assertOk()->assertJson(['status' => 200]);
        $this->get($url)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $admission->fresh()->fee_status);
        $this->assertSame(1, $admission->payments()->count());
    }

    #[DataProvider('channels')]
    public function test_pending_duplicate_start_resumes_one_order(string $source): void
    {
        $admission = $this->application($source);
        $first = ApplicantPesaPalPayment::start($admission);
        $second = ApplicantPesaPalPayment::start($admission);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('pending', $second->status);
        Http::assertSentCount(3); // token, order, server-side resume verification
    }

    #[DataProvider('channels')]
    public function test_provider_bank_payment_is_verified_as_pesapal_not_manually_approved(string $source): void
    {
        $admission = $this->application($source);
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->statusChanges = ['payment_method' => 'Bank'];
        $this->code = 1;
        $this->description = 'COMPLETED';
        ApplicantPesaPalPayment::reconcile($payment);
        $this->assertSame('pesapal', $payment->fresh()->method);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->confirmed_by);
        $this->assertSame('paid', $admission->fresh()->fee_status);
    }

    #[DataProvider('channels')]
    public function test_verified_failure_does_not_settle_the_fee(string $source): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application($source));
        $this->code = 2; $this->description = 'FAILED';
        $this->assertSame('failed', ApplicantPesaPalPayment::reconcile($payment));
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('unpaid', $payment->admission->fresh()->fee_status);
    }

    #[DataProvider('channels')]
    public function test_browser_cancel_claim_cannot_settle_or_release_pending_order(string $source): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application($source));
        $this->get(route('applicant.pesapal.callback', ['OrderTrackingId' => self::GUID,
            'OrderMerchantReference' => $payment->reference, 'status' => 'cancelled']))->assertRedirect();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame($payment->id, ApplicantPesaPalPayment::start($payment->admission)->id);
    }

    #[DataProvider('channels')]
    public function test_ambiguous_timeout_keeps_reservation_and_blocks_duplicate_submission(string $source): void
    {
        $admission = $this->application($source);
        $this->timeout = true;
        try { ApplicantPesaPalPayment::start($admission); $this->fail('Expected ambiguous timeout'); }
        catch (ValidationException $exception) {}
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->assertNull($payment->gateway_txn_id);
        $this->assertSame(1, $admission->payments()->count());
        $this->assertSame(1, $this->orderRequests);
    }

    public function test_missing_order_identity_can_be_recovered_only_with_matching_provider_evidence(): void
    {
        $admission = $this->application('staff_entry'); $this->timeout = true;
        try { ApplicantPesaPalPayment::start($admission); } catch (ValidationException $exception) {}
        $payment = $admission->payments()->first();
        $this->code = 1; $this->description = 'COMPLETED';
        $this->assertSame('settled', ApplicantPesaPalPayment::reconcile($payment, self::GUID));
        $this->assertSame(self::GUID, $payment->fresh()->gateway_txn_id);
    }

    public static function mismatches(): array
    {
        return [[['merchant_reference' => 'someone-else']], [['amount' => '1.00']], [['currency' => 'KES']]];
    }

    #[DataProvider('mismatches')]
    public function test_mismatched_provider_evidence_never_credits_payment(array $changes): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application());
        $this->code = 1; $this->description = 'COMPLETED'; $this->statusChanges = $changes;
        $this->expectException(PesaPalException::class);
        try { ApplicantPesaPalPayment::reconcile($payment); }
        finally { $this->assertSame('pending', $payment->fresh()->status); }
    }

    public function test_reversal_removes_fee_credit_and_cannot_be_recredited_by_late_success(): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application());
        $this->code = 1; $this->description = 'COMPLETED'; ApplicantPesaPalPayment::reconcile($payment);
        $this->code = 3; $this->description = 'REVERSED';
        $this->assertSame('reversed', ApplicantPesaPalPayment::reconcile($payment->fresh()));
        $this->assertSame('unpaid', $payment->admission->fresh()->fee_status);
        $this->code = 1; $this->description = 'COMPLETED';
        $this->assertSame('reversed', ApplicantPesaPalPayment::reconcile($payment->fresh()));
    }

    public function test_signed_invitation_is_application_specific_expiring_and_get_does_not_charge(): void
    {
        $admission = $this->application('staff_entry');
        $url = URL::temporarySignedRoute('applicant.pesapal.invitation', now()->addHour(), ['admission' => $admission->id]);
        $this->get($url)->assertOk()->assertSee($admission->app_number);
        Http::assertNothingSent();
        $other = $this->application('staff_entry');
        $this->get(str_replace('/' . $admission->id . '?', '/' . $other->id . '?', $url))->assertForbidden();
        $expired = URL::temporarySignedRoute('applicant.pesapal.invitation', now()->subMinute(), ['admission' => $admission->id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_admin_cannot_manually_confirm_or_record_a_pesapal_payment(): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application('staff_entry'));
        $this->be($this->makeAdminUser($this->school));
        $this->post(route('admin.hei_admissions.payment.review', $payment->id), ['status' => 'paid'])->assertStatus(422);
        $this->postJson(route('admin.hei_admissions.payment.record', $payment->admission_id),
            ['method' => 'pesapal', 'amount' => 50000, 'paid_at' => now()->toDateString()])->assertUnprocessable();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    #[DataProvider('channels')]
    public function test_unpaid_application_cannot_be_accepted_or_enrolled(string $source): void
    {
        $admission = $this->application($source);
        $this->assertFalse(ApplicationWorkflow::canTransition($admission, 'accepted'));
        $admission->update(['status' => 'accepted']);
        $this->assertFalse(ApplicationWorkflow::canTransition($admission, 'enrolled'));
    }

    public function test_other_applicant_cannot_poll_this_payment(): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application());
        $other = $this->application(); $this->be($other->applicant, 'applicant');
        $this->post(route('applicant.payment.pesapal.status', $payment->id))->assertNotFound();
    }

    public function test_partial_offline_credit_reduces_order_amount(): void
    {
        $admission = $this->application();
        ApplicationPayment::create(['school_id' => $this->school, 'admission_id' => $admission->id, 'method' => 'cash', 'status' => 'paid', 'amount' => '10000.00', 'currency' => 'UGX']);
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->assertSame('40000.00', $payment->amount);
    }

    #[DataProvider('channels')]
    public function test_verified_payment_allows_acceptance_then_atomic_enrolment(string $source): void
    {
        $admission = $this->completeAdmissionForDecision($this->application($source));
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->code = 1; $this->description = 'COMPLETED'; ApplicantPesaPalPayment::reconcile($payment);
        $this->be($this->makeAdminUser($this->school));
        $this->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $admission->fresh()->status);
        $this->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'enrolled'])->assertRedirect();
        $this->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'enrolled'])->assertRedirect();
        $this->assertSame('enrolled', $admission->fresh()->status);
        $this->assertSame(1, \App\Models\User::where('email', $admission->email)->where('role_id', 7)->count());
    }

    public function test_conversion_conflict_rolls_back_decision_timeline_and_notification(): void
    {
        $admission = $this->completeAdmissionForDecision($this->application('staff_entry', ['status' => 'accepted']));
        $payment = ApplicantPesaPalPayment::start($admission);
        $this->code = 1; $this->description = 'COMPLETED'; ApplicantPesaPalPayment::reconcile($payment);
        $staff = $this->makeAdminUser($this->school);
        $admission->update(['email' => $staff->email]);
        $this->be($staff);
        $events = $admission->statusEvents()->count();
        $this->postJson(route('admin.hei_admissions.status', $admission->id), ['status' => 'enrolled'])->assertUnprocessable();
        $this->assertSame('accepted', $admission->fresh()->status);
        $this->assertSame($events, $admission->statusEvents()->count());
        $admission->update(['email' => 'recovered.fixture@example.test']);
        $this->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'enrolled'])->assertRedirect();
        $this->assertSame('enrolled', $admission->fresh()->status);
    }

    public function test_obligation_amount_and_currency_are_immutable_after_first_checkout(): void
    {
        $admission = $this->application();
        $payment = ApplicantPesaPalPayment::start($admission);
        $admission->intakeSession->update(['application_fee' => '99000.00']);
        DB::table('global_settings')->where('key', 'system_currency')->update(['value' => 'KES']);
        $this->assertSame(50000.0, ApplicationFee::amountFor($admission->fresh()));
        $this->assertSame('UGX', ApplicationFee::currencyFor($admission->fresh()));
        $this->code = 1; $this->description = 'COMPLETED'; ApplicantPesaPalPayment::reconcile($payment);
        $this->assertSame('paid', $admission->fresh()->fee_status);
    }

    public function test_no_fee_and_explicit_waiver_do_not_create_online_charges(): void
    {
        $admission = $this->application();
        $this->be($this->makeAdminUser($this->school));
        $this->post(route('admin.hei_admissions.payment.waive', $admission->id), ['reason' => 'Approved isolated fixture waiver'])->assertRedirect();
        $this->assertSame('waived', $admission->fresh()->fee_status);
        $this->expectException(ValidationException::class);
        ApplicantPesaPalPayment::start($admission);
    }

    #[DataProvider('channels')]
    public function test_offline_review_and_waiver_cannot_mask_pending_online_charge(string $source): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application($source));
        $this->be($this->makeAdminUser($this->school));
        $this->post(route('admin.hei_admissions.payment.waive', $payment->admission_id), ['reason' => 'Fixture'])->assertUnprocessable();
        $this->post(route('admin.hei_admissions.payment.record', $payment->admission_id), ['method' => 'cash', 'amount' => 50000, 'paid_at' => now()->toDateString()])->assertUnprocessable();
        $this->assertSame(1, $payment->admission->payments()->count());
        $offline = ApplicationPayment::create(['school_id' => $this->school, 'admission_id' => $payment->admission_id,
            'applicant_id' => $payment->applicant_id, 'method' => 'offline', 'status' => 'pending', 'amount' => 50000, 'currency' => 'UGX']);
        $this->post(route('admin.hei_admissions.payment.review', $offline->id), ['status' => 'paid'])->assertUnprocessable();
        $this->assertSame('pending', $offline->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $payment->admission->fresh()->fee_status);
    }

    public function test_closed_browser_payment_is_recovered_by_background_reconciliation(): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application('staff_entry'));
        $this->code = 1; $this->description = 'COMPLETED';
        $this->artisan('applications:reconcile-pesapal')->assertSuccessful();
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_foreign_admin_cannot_verify_or_send_payment_requests(): void
    {
        $payment = ApplicantPesaPalPayment::start($this->application());
        $this->be($this->makeAdminUser($this->makeSchool()));
        $this->post(route('admin.hei_admissions.payment.pesapal.check', $payment->id))->assertRedirect();
        // Existing primary-school guard refuses access before controller lookup.
        $this->assertSame('pending', $payment->fresh()->status);
    }
}
