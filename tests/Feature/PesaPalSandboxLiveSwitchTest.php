<?php

namespace Tests\Feature;

use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Models\User;
use App\Support\Admissions\ApplicationFee;
use App\Support\Payments\ApplicantPesaPalPayment;
use App\Support\Payments\PesaPalConfiguration;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Switching the school between the PesaPal sandbox and live merchant.
 *
 * This decides whose account receives real money, so the rules matter: changing
 * environment demands fresh credentials, historical orders keep the
 * configuration they were created under, and the new environment stays hidden
 * from applicants until its own IPN is registered.
 */
class PesaPalSandboxLiveSwitchTest extends TestCase
{
    use AdmissionsTestHelper;

    private const SANDBOX_KEY = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private const LIVE_KEY = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
    private const IPN = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';

    private int $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
        $this->makeSchool(['title' => 'Other']);
    }

    private function configure(string $environment, string $key, ?string $ipn = self::IPN): PaymentMethods
    {
        return PaymentMethods::create([
            'school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'mode' => $environment === 'live' ? 'live' : 'test',
            'payment_keys' => json_encode([
                'environment' => $environment, 'consumer_key' => $key,
                'consumer_secret' => 'secret-'.$key, 'notification_id' => $ipn,
            ]),
        ]);
    }

    public function test_configuration_resolves_the_environment_it_was_saved_with(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);
        $this->assertSame('sandbox', PesaPalConfiguration::forSchool($this->school)->environment);

        $this->configure('live', self::LIVE_KEY);
        // forSchool demands exactly one ACTIVE row, so archive the sandbox one.
        PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')
            ->orderBy('id')->first()->update(['status' => 0]);
        $this->assertSame('live', PesaPalConfiguration::forSchool($this->school)->environment);
    }

    public function test_switching_environment_without_new_credentials_is_refused(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);

        // A controller ValidationException becomes a redirect with errors, so the
        // refusal is asserted on the session rather than by catching it.
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'live',
        ])->assertSessionHasErrors();

        $this->assertSame('sandbox', PesaPalConfiguration::forSchool($this->school)->environment,
            'the environment must be unchanged when the switch was refused');
    }

    public function test_switching_to_live_with_new_credentials_updates_the_row_when_no_orders_exist(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);

        $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'live',
            'consumer_key' => self::LIVE_KEY,
            'consumer_secret' => 'live-secret',
        ])->assertSessionHasNoErrors();

        // No payment history means there is nothing to preserve, so the row is
        // updated in place rather than archived.
        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->count());
        $this->assertSame('live', PesaPalConfiguration::forSchool($this->school)->environment);
        $this->assertSame(self::LIVE_KEY, PesaPalConfiguration::forSchool($this->school)->credentials()['consumer_key']);
    }

    public function test_switching_is_refused_while_an_order_is_still_pending(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);
        $admissionId = $this->makeAdmission($this->school, ['intake_session_id' => $this->paidIntake()]);
        ApplicationPayment::create([
            'school_id' => $this->school, 'admission_id' => $admissionId,
            'amount' => 100000, 'currency' => 'UGX', 'method' => 'pesapal',
            'status' => ApplicationPayment::STATUS_PENDING, 'reference' => 'PENDING-1',
            'gateway_payload' => ['environment' => 'sandbox'],
        ]);

        // Replacing the configuration under a live order would strand it.
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'live', 'consumer_key' => self::LIVE_KEY, 'consumer_secret' => 'live-secret',
        ])->assertStatus(422);

        $this->assertSame('sandbox', PesaPalConfiguration::forSchool($this->school)->environment);
    }

    public function test_a_historical_sandbox_order_cannot_be_settled_by_the_live_configuration(): void
    {
        $sandbox = $this->configure('sandbox', self::SANDBOX_KEY);
        $admissionId = $this->makeAdmission($this->school, ['intake_session_id' => $this->paidIntake()]);
        $admission = \App\Models\Admission::find($admissionId);
        ApplicationFee::freezeObligation($admission);

        // A SETTLED order: history worth preserving, but nothing left in flight.
        $payment = ApplicationPayment::create([
            'school_id' => $this->school, 'admission_id' => $admissionId,
            'amount' => 100000, 'currency' => 'UGX', 'method' => 'pesapal',
            'status' => ApplicationPayment::STATUS_PAID, 'reference' => 'SANDBOX-SETTLED',
            'gateway_payload' => ['environment' => 'sandbox', 'configuration_id' => $sandbox->id],
        ]);

        // Switch the school to live, keeping the sandbox row archived.
        $switch = $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'live', 'consumer_key' => self::LIVE_KEY, 'consumer_secret' => 'live-secret',
        ]);
        $switch->assertRedirect();
        $this->assertNotSame(403, $switch->getStatusCode(), 'the save route must not be refused');
        $this->assertCount(0, $switch->getSession()?->get('errors') ?? []);

        // The old order must resolve to the SANDBOX configuration it was created
        // under, never to the live one now active for the school. That is what
        // archiving the old row buys: historical orders stay reconcilable
        // against the merchant that actually holds them.
        $resolved = PesaPalConfiguration::forPayment($payment->fresh());
        $this->assertSame('sandbox', $resolved->environment,
            'a sandbox order must never resolve to the live configuration');
        $this->assertSame(self::SANDBOX_KEY, $resolved->credentials()['consumer_key']);

        // And the school's active configuration really is the live one.
        $this->assertSame('live', PesaPalConfiguration::forSchool($this->school)->environment);
    }

    public function test_a_new_environment_serves_no_payments_until_its_own_ipn_is_registered(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'live', 'consumer_key' => self::LIVE_KEY, 'consumer_secret' => 'live-secret',
        ])->assertSessionHasNoErrors();

        // The freshly created live row has no notification_id of its own, so the
        // gateway stays switched OFF for applicants until finance registers it.
        $this->assertFalse(ApplicationFee::gatewayIsConfigured('pesapal', $this->school),
            'a new environment must not be advertised before its IPN is registered');
        $this->assertNull(PesaPalConfiguration::forSchool($this->school)->notificationId);
    }

    public function test_saving_the_same_environment_preserves_the_registered_ipn(): void
    {
        $this->configure('sandbox', self::SANDBOX_KEY);

        $this->actingAs($this->admin)->post(route('admin.hei_admissions.payment.pesapal.settings.save'), [
            'environment' => 'sandbox', 'consumer_key' => self::SANDBOX_KEY, 'consumer_secret' => 'secret-'.self::SANDBOX_KEY,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::IPN, PesaPalConfiguration::forSchool($this->school)->notificationId,
            're-saving the same environment must not silently drop a registered IPN');
        $this->assertTrue(ApplicationFee::gatewayIsConfigured('pesapal', $this->school));
    }

    private function paidIntake(): int
    {
        return $this->makeIntakeSession($this->school, ['application_fee' => 100000, 'is_open' => 1]);
    }
}