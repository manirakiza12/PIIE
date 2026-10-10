<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Models\PaymentMethods;
use App\Support\Admissions\ApplicantNotificationDelivery;
use App\Support\Admissions\ApplicantNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ModuleOneOperationsTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;
    protected function setUp(): void
    {
        parent::setUp(); $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => $this->school]);
        $this->be($this->makeAdminUser($this->school));
        Http::preventStrayRequests(); Mail::fake();
        config(['cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test'); URL::forceScheme('https');
    }

    public function test_settings_never_render_stored_credentials(): void
    {
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox',
            'consumer_key' => 'unique-fixture-key', 'consumer_secret' => 'unique-fixture-secret'])->assertRedirect();
        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertOk()
            ->assertDontSee('unique-fixture-key')->assertDontSee('unique-fixture-secret');
    }

    public function test_ipn_registration_is_explicit_and_persists_verified_notification_id(): void
    {
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox',
            'consumer_key' => 'fixture', 'consumer_secret' => 'fixture'])->assertRedirect();
        Http::assertNothingSent();
        Http::fake(['*/api/Auth/RequestToken' => Http::response(['token' => 'fixture-token', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]),
            '*/api/URLSetup/RegisterIPN' => Http::response(['ipn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3',
                'url' => route('applicant.pesapal.ipn'), 'created_date' => now()->format('Y-m-d\TH:i:s'), 'notification_type' => 0,
                'ipn_notification_type_description' => 'GET', 'ipn_status' => 1, 'ipn_status_description' => 'Active', 'status' => '200', 'error' => null])]);
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.register'))->assertRedirect()->assertSessionHas('success');
        $keys = json_decode(PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->first()->payment_keys, true);
        $this->assertSame('7e6b62d9-883e-440f-a63e-e1105bbfadc3', $keys['notification_id']);
    }

    public function test_notification_migration_retry_and_encryption_use_only_in_memory_database(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $id = ApplicantNotificationDelivery::record('isolated-recipient@example.test', ['subject' => 'Fixture', 'school_id' => $this->school]);
        $this->assertFalse(ApplicantNotificationDelivery::deliver($id));
        $row = DB::table('applicant_notification_deliveries')->where('id', $id)->first();
        $this->assertStringNotContainsString('isolated-recipient', $row->encrypted_message);
        $this->assertNull($row->sent_at);
        foreach (['smtp_user', 'smtp_pass', 'smtp_host', 'smtp_port'] as $key) {
            DB::table('global_settings')->insert(['key' => $key, 'value' => 'fixture']);
        }
        DB::table('applicant_notification_deliveries')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->artisan('applications:retry-notifications')->assertSuccessful();
        $this->artisan('applications:retry-notifications')->assertSuccessful();
        $this->assertNotNull(DB::table('applicant_notification_deliveries')->where('id', $id)->value('sent_at'));
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_business_rollback_discards_notification_record_and_never_sends_mail(): void
    {
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $applicant = $this->makeApplicant($this->school);
        DB::beginTransaction();
        ApplicantNotifier::welcome($applicant);
        $this->assertSame(1, DB::table('applicant_notification_deliveries')->count());
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertSame(0, DB::table('applicant_notification_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_delayed_payment_invitation_gets_a_fresh_signed_link_and_rejects_changed_recipient(): void
    {
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        foreach (['smtp_user', 'smtp_pass', 'smtp_host', 'smtp_port'] as $key) {
            DB::table('global_settings')->insert(['key' => $key, 'value' => 'fixture']);
        }
        $admission = \App\Models\Admission::findOrFail($this->makeAdmission($this->school, [
            'status' => 'submitted', 'submitted_at' => now(),
            'intake_session_id' => $this->makeIntakeSession($this->school, ['application_fee' => 50000]),
        ]));
        $data = ['school_id' => $this->school, 'payment_invitation_admission_id' => $admission->id,
            'cta_url' => 'https://piie.example.test/expired'];
        $id = ApplicantNotificationDelivery::record($admission->email, $data);
        $this->assertTrue(ApplicantNotificationDelivery::deliver($id));
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) {
            return str_contains($mail->render(), 'signature=') && ! str_contains($mail->render(), '/expired');
        });
        $other = ApplicantNotificationDelivery::record('different@example.test', $data);
        $this->assertFalse(ApplicantNotificationDelivery::deliver($other));
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_sensitive_configuration_inputs_are_not_flashed_on_validation_failure(): void
    {
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'invalid',
            'consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret'])->assertRedirect();
        $this->assertArrayNotHasKey('consumer_key', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('consumer_secret', session()->get('_old_input', []));
    }
}
