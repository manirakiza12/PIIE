<?php

namespace Tests\Feature;

use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Models\TuitionPayment;
use App\Support\Payments\PesaPalConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Integrity guarantees around a school's PesaPal configuration.
 *
 * These tests prove fail-closed behaviour, not merely an HTTP status: no
 * credential is displayed, no configuration is written when a guard fires,
 * and the database itself refuses a second active row per school.
 */
class PesaPalSettingsIntegrityTest extends TestCase
{
    use AdmissionsTestHelper;

    private const MIGRATION = 'migrations/2026_11_01_120000_enforce_single_active_pesapal_configuration_per_school.php';

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $this->school = $this->makeSchool();
        $this->be($this->makeAdminUser($this->school));
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $this->school]);
        Http::preventStrayRequests();
    }

    private function activeRow(int $school, string $key = 'initial-key', string $secret = 'initial-secret',
        string $environment = 'sandbox'): PaymentMethods
    {
        return PaymentMethods::create(['school_id' => $school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => $environment, 'consumer_key' => $key, 'consumer_secret' => $secret])]);
    }

    private function save(array $overrides = [])
    {
        return $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), array_merge([
            'environment' => 'sandbox', 'consumer_key' => 'replacement-key', 'consumer_secret' => 'replacement-secret',
        ], $overrides));
    }

    public function test_duplicate_active_configurations_render_a_fail_closed_page_without_secrets_or_a_save_form(): void
    {
        $this->activeRow($this->school, 'first-key', 'first-secret');
        $this->activeRow($this->school, 'second-key', 'second-secret', 'live');

        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertOk()
            ->assertSee('Multiple active PesaPal configurations exist for this school.')
            ->assertSee('Configuration changes are blocked until the duplicate PesaPal configurations are resolved.')
            ->assertDontSee('Save configuration')
            ->assertDontSee('first-key')->assertDontSee('first-secret')
            ->assertDontSee('second-key')->assertDontSee('second-secret');
        Http::assertNothingSent();
    }

    public function test_unreadable_stored_credentials_are_recoverable_without_exposing_the_broken_value(): void
    {
        $row = PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox'])]);
        DB::table('payment_methods')->where('id', $row->id)->update(['payment_keys' => json_encode([
            'environment' => 'sandbox', 'credential_format' => 1, 'encrypted_credentials' => 'not-a-valid-ciphertext',
        ])]);

        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertOk()
            ->assertSee('Stored PesaPal credentials cannot be read.')
            ->assertSee('Save configuration')
            ->assertDontSee('not-a-valid-ciphertext');

        $this->save(['consumer_key' => 'recovered-key', 'consumer_secret' => 'recovered-secret'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $configuration = PesaPalConfiguration::forSchool($this->school);
        $this->assertSame('recovered-key', $configuration->credentials()['consumer_key']);
        $this->assertSame('recovered-secret', $configuration->credentials()['consumer_secret']);
        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')
            ->where('status', 1)->count());
        Http::assertNothingSent();
    }

    public function test_replacing_credentials_is_rejected_while_an_application_order_is_pending(): void
    {
        $configuration = $this->activeRow($this->school);
        $stored = DB::table('payment_methods')->where('id', $configuration->id)->value('payment_keys');
        $admission = $this->makeAdmission($this->school);
        ApplicationPayment::create(['school_id' => $this->school, 'admission_id' => $admission, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending', 'reference' => 'APPFEE-PENDING-1']);

        $this->save()->assertRedirect()->assertSessionHasErrors('configuration');

        $this->assertSame($stored, DB::table('payment_methods')->where('id', $configuration->id)->value('payment_keys'));
        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->count());
        $this->assertSame('initial-key', PesaPalConfiguration::forSchool($this->school)->credentials()['consumer_key']);
        $this->assertDatabaseHas('application_payments', ['reference' => 'APPFEE-PENDING-1', 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_replacing_credentials_is_rejected_while_a_tuition_order_is_pending(): void
    {
        $configuration = $this->activeRow($this->school);
        $stored = DB::table('payment_methods')->where('id', $configuration->id)->value('payment_keys');
        TuitionPayment::create(['school_id' => $this->school, 'fee_id' => 1, 'amount' => 5000, 'currency' => 'UGX',
            'method' => 'pesapal', 'status' => 'pending', 'reference' => 'TUITION-PENDING-1']);

        $this->save()->assertRedirect()->assertSessionHasErrors('configuration');

        $this->assertSame($stored, DB::table('payment_methods')->where('id', $configuration->id)->value('payment_keys'));
        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->count());
        $this->assertSame('initial-key', PesaPalConfiguration::forSchool($this->school)->credentials()['consumer_key']);
        $this->assertDatabaseHas('tuition_payments', ['reference' => 'TUITION-PENDING-1', 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_save_refuses_duplicate_active_rows_without_changing_any_row(): void
    {
        $first = $this->activeRow($this->school, 'first-key', 'first-secret');
        $second = $this->activeRow($this->school, 'second-key', 'second-secret', 'live');
        $before = DB::table('payment_methods')->orderBy('id')->get(['id', 'status', 'payment_keys'])->toArray();

        $this->save()->assertRedirect()->assertSessionHasErrors('configuration');

        $this->assertEquals($before, DB::table('payment_methods')->orderBy('id')->get(['id', 'status', 'payment_keys'])->toArray());
        $this->assertSame(2, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        Http::assertNothingSent();
    }

    public function test_database_rejects_a_second_active_pesapal_row_but_allows_inactive_history_and_other_gateways(): void
    {
        (require database_path(self::MIGRATION))->up();
        $this->activeRow($this->school);

        try {
            $this->activeRow($this->school, 'second-key');
            $this->fail('A second active PesaPal row was accepted for the same school.');
        } catch (QueryException $exception) {
            $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        }

        // Inactive rows are historical configurations and must remain allowed.
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 0,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'old-key', 'consumer_secret' => 'old-secret'])]);
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 0,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'older-key', 'consumer_secret' => 'older-secret'])]);
        // Another school and another gateway are independent.
        $this->activeRow($this->makeSchool());
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'paystack', 'status' => 1,
            'payment_keys' => json_encode(['public' => 'fixture'])]);

        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        $this->assertSame(2, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 0)->count());
        $this->assertSame(1, PaymentMethods::where('name', 'paystack')->where('status', 1)->count());
    }

    public function test_migration_refuses_to_run_when_duplicate_active_rows_already_exist(): void
    {
        $first = $this->activeRow($this->school, 'first-key', 'first-secret');
        $second = $this->activeRow($this->school, 'second-key', 'second-secret', 'live');
        $firstKeys = DB::table('payment_methods')->where('id', $first->id)->value('payment_keys');
        $secondKeys = DB::table('payment_methods')->where('id', $second->id)->value('payment_keys');

        try {
            (require database_path(self::MIGRATION))->up();
            $this->fail('Migration accepted duplicate active configurations.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Duplicate active PesaPal configurations exist', $exception->getMessage());
        }

        $this->assertSame(2, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        $this->assertSame($firstKeys, DB::table('payment_methods')->where('id', $first->id)->value('payment_keys'));
        $this->assertSame($secondKeys, DB::table('payment_methods')->where('id', $second->id)->value('payment_keys'));
        // No constraint was left behind by the refused migration.
        $this->activeRow($this->school, 'third-key');
        $this->assertSame(3, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        $this->assertFalse(Schema::hasColumn('payment_methods', 'active_pesapal_school_id'));
    }

    public function test_credential_rotation_with_history_keeps_exactly_one_active_row(): void
    {
        (require database_path(self::MIGRATION))->up();
        $configuration = $this->activeRow($this->school);
        $admission = $this->makeAdmission($this->school);
        ApplicationPayment::create(['school_id' => $this->school, 'admission_id' => $admission, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'paid', 'reference' => 'APPFEE-HISTORY-1',
            'gateway_txn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3']);

        $this->save()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->where('status', 1)->count());
        $this->assertSame(2, PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->count());
        $this->assertSame(0, (int) PaymentMethods::whereKey($configuration->id)->value('status'));
        $this->assertSame('replacement-key', PesaPalConfiguration::forSchool($this->school)->credentials()['consumer_key']);
        $this->assertDatabaseHas('application_payments', ['reference' => 'APPFEE-HISTORY-1', 'status' => 'paid']);
    }
}
