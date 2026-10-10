<?php

namespace Tests\Feature;

use App\Models\PaymentMethods;
use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PesaPalCredentialSecurityTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;
    private array $keys = ['environment' => 'sandbox', 'consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret'];

    protected function setUp(): void
    {
        parent::setUp(); $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        $this->be($this->makeAdminUser($this->school));
        Http::preventStrayRequests();
    }

    private function row(): PaymentMethods
    {
        return PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode($this->keys)]);
    }

    public function test_encrypted_storage_decrypts_only_in_configuration_and_does_not_serialize_secrets(): void
    {
        $row = $this->row();
        $stored = DB::table('payment_methods')->where('id', $row->id)->value('payment_keys');
        $this->assertTrue(! str_contains($stored, 'fixture-key') && ! str_contains($stored, 'fixture-secret'));
        $this->assertTrue(isset(json_decode($stored, true)['encrypted_credentials']));
        $this->assertTrue(PesaPalConfiguration::forSchool($this->school)->credentials() === array_intersect_key($this->keys, array_flip(['consumer_key', 'consumer_secret'])));
        $this->assertArrayNotHasKey('payment_keys', $row->toArray());
        $this->assertTrue(! str_contains(print_r(PesaPalConfiguration::forSchool($this->school), true), 'fixture-secret'));
    }

    public function test_blank_updates_preserve_credentials_and_registered_ipn(): void
    {
        $this->keys['notification_id'] = '7e6b62d9-883e-440f-a63e-e1105bbfadc3'; $row = $this->row();
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox', 'consumer_key' => '', 'consumer_secret' => ''])->assertRedirect()->assertSessionHas('success');
        $config = PesaPalConfiguration::forSchool($this->school);
        $this->assertTrue($config->credentials()['consumer_secret'] === 'fixture-secret');
        $this->assertSame($row->id, $config->configurationId);
        $this->assertSame($this->keys['notification_id'], $config->notificationId);
    }

    public function test_partial_update_preserves_other_secret_and_invalidates_ipn(): void
    {
        $this->keys['notification_id'] = '7e6b62d9-883e-440f-a63e-e1105bbfadc3'; $this->row();
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox', 'consumer_key' => 'replacement-fixture'])->assertRedirect()->assertSessionHas('success');
        $config = PesaPalConfiguration::forSchool($this->school);
        $this->assertTrue($config->credentials() === ['consumer_key' => 'replacement-fixture', 'consumer_secret' => 'fixture-secret']);
        $this->assertNull($config->notificationId);
    }

    public function test_initial_setup_and_environment_switch_require_credentials(): void
    {
        $url = route('admin.hei_admissions.payment.pesapal.settings.save');
        $this->post($url, ['environment' => 'sandbox'])->assertSessionHasErrors('consumer_key');
        $this->assertSame(0, PaymentMethods::count()); $this->row();
        $this->post($url, ['environment' => 'live'])->assertSessionHasErrors('environment');
        $this->assertSame('sandbox', PesaPalConfiguration::forSchool($this->school)->environment);
    }

    public function test_legacy_plaintext_reads_without_mutation_and_next_save_encrypts(): void
    {
        $id = DB::table('payment_methods')->insertGetId(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1, 'payment_keys' => json_encode($this->keys)]);
        $before = DB::table('payment_methods')->where('id', $id)->value('payment_keys');
        $this->assertTrue(PesaPalConfiguration::forSchool($this->school)->credentials()['consumer_key'] === 'fixture-key');
        $this->assertTrue($before === DB::table('payment_methods')->where('id', $id)->value('payment_keys'));
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox'])->assertSessionHas('success');
        $this->assertTrue(! str_contains(DB::table('payment_methods')->where('id', $id)->value('payment_keys'), 'fixture-key'));
    }

    public function test_other_gateways_are_unchanged(): void
    {
        $json = json_encode(['consumer_key' => 'other-fixture', 'consumer_secret' => 'other-secret']);
        $row = PaymentMethods::create(['school_id' => $this->school, 'name' => 'marzpay', 'payment_keys' => $json]);
        $this->assertTrue($row->fresh()->payment_keys === $json);
        $this->assertArrayHasKey('payment_keys', $row->toArray());
    }

    public function test_guests_cannot_open_or_update_settings(): void
    {
        auth()->logout();
        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertRedirect(route('login'));
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), $this->keys)->assertRedirect(route('login'));
        $this->assertSame(0, PaymentMethods::count());
    }

    public function test_incomplete_credentials_are_never_written_as_plaintext(): void
    {
        $row = PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'incomplete-fixture'])]);
        $this->assertTrue(! str_contains($row->fresh()->payment_keys, 'incomplete-fixture'));
        $this->expectException(PesaPalException::class); PesaPalConfiguration::forSchool($this->school);
    }

    public function test_settings_are_school_scoped_and_staff_cannot_write_credentials(): void
    {
        $row = $this->row(); $before = $row->payment_keys;
        $other = $this->makeSchool(); $this->be($this->makeAdminUser($other));
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), $this->keys + ['school_id' => $this->school])->assertSessionHas('success');
        $this->assertTrue($before === $row->fresh()->payment_keys);
        $this->assertSame(1, PaymentMethods::where('school_id', $other)->count());
        $staff = $this->makeAdminUser($this->school); $staff->forceFill(['role_id' => 3])->save(); $this->be($staff);
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), $this->keys)->assertRedirect();
        $this->assertTrue($before === $row->fresh()->payment_keys);
    }

    public function test_ciphertext_cannot_be_copied_to_another_school(): void
    {
        $row = $this->row(); $other = $this->makeSchool();
        DB::table('payment_methods')->insert(['school_id' => $other, 'name' => 'pesapal', 'status' => 1, 'payment_keys' => $row->payment_keys]);
        $this->expectException(PesaPalException::class); PesaPalConfiguration::forSchool($other);
    }

    public function test_tampered_ciphertext_fails_with_a_generic_exception(): void
    {
        $row = $this->row(); $data = json_decode($row->payment_keys, true); $data['encrypted_credentials'] = 'invalid-fixture';
        DB::table('payment_methods')->where('id', $row->id)->update(['payment_keys' => json_encode($data)]);
        try { PesaPalConfiguration::forSchool($this->school); $this->fail('Tampered credentials accepted'); }
        catch (PesaPalException $e) { $this->assertNull($e->getPrevious()); $this->assertTrue(! str_contains($e->getMessage(), 'fixture')); }
    }
}
