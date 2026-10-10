<?php

namespace Tests\Feature;

use App\Models\ApplicationPayment;
use App\Support\Payments\ApplicationPaymentProof;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicationPaymentProofTest extends TestCase
{
    use AdmissionsTestHelper;

    private array $fixtureFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        config(['app.bypass_subscription' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function payment(int $school, bool $legacy = false): ApplicationPayment
    {
        $payment = ApplicationPayment::create([
            'school_id' => $school,
            'admission_id' => $this->makeAdmission($school),
            'amount' => 50000,
            'currency' => 'UGX',
            'method' => 'offline',
            'status' => 'pending',
            'proof_file' => 'fixture_' . bin2hex(random_bytes(16)) . '.pdf',
        ]);

        $path = $legacy
            ? public_path(ApplicationPayment::PROOF_DIR . '/' . $payment->proof_file)
            : ApplicationPaymentProof::path($payment);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, '%PDF-1.4 synthetic proof');
        $this->fixtureFiles[] = $path;

        return $payment;
    }

    private function primary(int $school): void
    {
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $school,
        ]);
    }

    public function test_private_and_legacy_proofs_require_authenticated_authorized_same_school_admin(): void
    {
        $school = $this->makeSchool();
        $this->primary($school);
        $private = $this->payment($school);
        $legacy = $this->payment($school, true);
        $foreign = $this->payment($this->makeSchool());

        $this->get($private->proof_url)->assertRedirect(route('login'));
        $this->actingAs($this->makeAdminUser($school));

        foreach ([$private, $legacy] as $payment) {
            $response = $this->get($payment->proof_url)->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');

            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('pending', $payment->fresh()->status);
        }

        $this->get($foreign->proof_url)->assertNotFound();
        $this->assertStringNotContainsString('/assets/uploads/', $legacy->proof_url);
    }

    public function test_expired_school_cannot_download_proof_by_direct_url(): void
    {
        // Explicitly test the future subscription-enforcement mode.
        config(['app.enforce_school_subscriptions' => true]);

        $school = $this->makeSchool();
        $this->primary($school);

        $payment = $this->payment($school);

        DB::table('subscriptions')
            ->where('school_id', $school)
            ->update(['expire_date' => strtotime('-2 days')]);

        $this->actingAs($this->makeAdminUser($school))
            ->get($payment->proof_url)
            ->assertRedirect(route('admin.subscription'));

        $this->assertFalse(config('app.bypass_subscription'));
    }



    public function test_unsafe_stored_filename_is_never_resolved(): void
    {
        $school = $this->makeSchool();
        $this->primary($school);
        $payment = $this->payment($school);
        $payment->update(['proof_file' => '../outside.pdf']);

        $this->actingAs($this->makeAdminUser($school))
            ->get($payment->proof_url)
            ->assertNotFound();
    }

    public function test_staff_without_payment_permission_cannot_download_proof(): void
    {
        $school = $this->makeSchool();
        $this->primary($school);
        $payment = $this->payment($school);
        $staff = $this->makeAdminUser($school);
        $staff->update(['role_id' => 3]);

        $this->assertSame('admissions.payments', app(\App\Support\Permissions\PermissionService::class)
            ->routePermission('admin.hei_admissions.payment.proof'));
        $this->actingAs($staff)->get($payment->proof_url)->assertForbidden();
    }

    public function test_missing_proof_is_not_an_application_error_or_ledger_change(): void
    {
        $school = $this->makeSchool();
        $this->primary($school);
        $payment = $this->payment($school);

        unlink(ApplicationPaymentProof::path($payment));
        $before = $payment->fresh()->getRawOriginal();

        $this->actingAs($this->makeAdminUser($school))
            ->get($payment->proof_url)
            ->assertNotFound();

        $this->assertSame($before, $payment->fresh()->getRawOriginal());
    }
}
