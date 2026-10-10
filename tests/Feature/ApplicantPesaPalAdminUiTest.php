<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ApplicantPesaPalSettingsController;
use App\Models\PaymentMethods;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicantPesaPalAdminUiTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        // Only the isolated SQLite fixture; never migrate the recovered database.
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $this->school = $this->makeSchool();
        $this->be($this->makeAdminUser($this->school));
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $this->school]);
        Http::preventStrayRequests();
    }

    private function content(): string
    {
        $request = Request::create('/admin/applicant-pesapal-settings');
        $request->setUserResolver(fn () => auth()->user());
        $data = (new ApplicantPesaPalSettingsController)->index($request)->getData();
        return view('admin.admissions.partials.pesapal_settings_content', $data + ['errors' => new ViewErrorBag])->render();
    }

    public function test_settings_route_renders_dashboard_styles_and_navigation_without_provider_operations(): void
    {
        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertOk()
            ->assertSee('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css', false)
            ->assertSee('assets/css/style.css', false)
            ->assertSee('Create application')->assertSee('Application payment status')
            ->assertSee('Failed admission notifications')->assertSee('Shared application payment configuration');
        Http::assertNothingSent();
    }

    public function test_configuration_status_and_password_inputs_never_render_credentials_or_ipn_identifiers(): void
    {
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'synthetic-ui-key',
                'consumer_secret' => 'synthetic-ui-secret', 'notification_id' => 'synthetic-ipn-id'])]);
        session()->flashInput(['consumer_key' => 'synthetic-flashed-key', 'consumer_secret' => 'synthetic-flashed-secret']);
        $html = $this->content();
        $this->assertStringContainsString('Credentials configured', $html);
        $this->assertStringContainsString('IPN registered', $html);
        $this->assertStringContainsString('col-12 col-xl-8', $html);
        $this->assertStringContainsString('class="form-control eForm-control" type="password"', $html);
        $this->assertTrue(!preg_match('/synthetic-(ui|flashed|ipn)/', $html));
        Http::assertNothingSent();
    }

    public function test_missing_notification_storage_is_distinct_from_no_failed_notifications(): void
    {
        Schema::drop('applicant_notification_deliveries');
        $html = $this->content();
        $this->assertStringContainsString('Credentials not configured', $html);
        $this->assertStringContainsString('IPN not registered', $html);
        $this->assertStringContainsString('Notification tracking is unavailable', $html);
        $this->assertStringNotContainsString('No outstanding notification records.', $html);
        Http::assertNothingSent();
    }

    public function test_configuration_and_failed_notifications_are_scoped_to_the_signed_in_school(): void
    {
        $other = $this->makeSchool();
        PaymentMethods::create(['school_id' => $other, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'live', 'consumer_key' => 'synthetic-other-key', 'consumer_secret' => 'synthetic-other-secret'])]);
        DB::table('applicant_notification_deliveries')->insert(['school_id' => $other, 'encrypted_message' => 'synthetic-encrypted-message', 'attempts' => 5, 'available_at' => now()]);
        $html = $this->content();
        $this->assertStringContainsString('Credentials not configured', $html);
        $this->assertStringContainsString('No outstanding notification records.', $html);
        $this->assertStringNotContainsString('Retry notification', $html);
    }

    public function test_navigation_reuses_existing_workflows_and_primary_school_gate(): void
    {
        $html = view('admin.admissions.partials.navigation')->render();
        foreach (['Create application', 'Application payment status', 'Applicant PesaPal settings', 'Failed admission notifications'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString(route('admin.hei_admissions.wizard.create'), $html);
        $this->assertStringContainsString('#failed-notifications', $html);
        $this->be($this->makeAdminUser($this->makeSchool()));
        $html = view('admin.admissions.partials.navigation')->render();
        $this->assertStringNotContainsString('Create application', $html);
        $this->assertStringNotContainsString('Application payment status', $html);
        $this->assertStringContainsString('Applicant PesaPal settings', $html);
        $this->get(route('admin.hei_admissions.index'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_notification_retry_is_visible_only_when_exhausted_and_due_without_message_content(): void
    {
        $id = DB::table('applicant_notification_deliveries')->insertGetId(['school_id' => $this->school,
            'encrypted_message' => 'synthetic-private-message', 'attempts' => 5, 'available_at' => now()->addHour()]);
        $html = $this->content();
        $this->assertStringContainsString('Administrator attention required', $html);
        $this->assertStringNotContainsString('Retry notification', $html);
        DB::table('applicant_notification_deliveries')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $html = $this->content();
        $this->assertStringContainsString('Retry notification', $html);
        $this->assertStringNotContainsString('synthetic-private-message', $html);
        Http::assertNothingSent();
    }

    public function test_legacy_menu_restrictions_and_unauthorized_roles_are_preserved(): void
    {
        auth()->user()->forceFill(['menu_permission' => json_encode(['admin.dashboard'])])->save();
        $this->assertSame('', trim(view('admin.admissions.partials.navigation')->render()));
        $user = $this->makeAdminUser($this->school);
        $user->forceFill(['role_id' => 7])->save();
        $this->be($user);
        $this->assertSame('', trim(view('admin.admissions.partials.navigation')->render()));
        $this->get(route('admin.hei_admissions.payment.pesapal.settings'))->assertRedirect();
        $this->post(route('admin.hei_admissions.payment.pesapal.settings.save'), ['environment' => 'sandbox'])->assertRedirect();
        $this->assertSame(0, PaymentMethods::count());
    }
}
