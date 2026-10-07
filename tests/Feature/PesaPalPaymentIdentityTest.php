<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\PaymentIdentityPreflight;
use App\Support\Payments\VerifiedApplicationPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PesaPalPaymentIdentityTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        DB::table('global_settings')->insert([
            ['key' => 'system_currency', 'value' => 'UGX'],
            ['key' => 'primary_school_id', 'value' => (string) $this->school],
        ]);
        Http::preventStrayRequests();
        Mail::fake();
        (require database_path('migrations/2026_10_07_160000_add_settled_transaction_uniqueness_to_application_payments.php'))->up();
    }

    private function migration()
    {
        return require database_path('migrations/2026_10_07_180000_extend_settled_transaction_identity_for_pesapal.php');
    }

    private function payment(array $attributes = [], ?int $school = null): ApplicationPayment
    {
        $school = $school ?? $this->school;
        $admission = $this->makeAdmission($school, [
            'intake_session_id' => $this->makeIntakeSession($school, ['application_fee' => '50000.00']),
            'status' => Admission::STATUS_SUBMITTED,
        ]);
        return ApplicationPayment::create(array_merge([
            'school_id' => $school, 'admission_id' => $admission, 'method' => 'pesapal',
            'status' => 'pending', 'amount' => '50000.00', 'currency' => 'UGX',
            'reference' => 'REF-' . $admission, 'gateway_txn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3',
        ], $attributes));
    }

    private function evidence(ApplicationPayment $p, array $override = []): VerifiedApplicationPayment
    {
        return new VerifiedApplicationPayment(...array_merge([
            'paymentId' => (int) $p->id, 'schoolId' => (int) $p->school_id,
            'provider' => $p->method, 'reference' => $p->reference,
            'transactionId' => $p->gateway_txn_id, 'amount' => (string) $p->amount,
            'currency' => $p->currency, 'status' => 'paid', 'payload' => [],
        ], $override));
    }

    public function test_existing_provider_semantics_are_preserved(): void
    {
        $this->migration()->up();
        foreach (['marzpay', 'stripe', 'flutterwave'] as $provider) {
            foreach (['opaque', 'Opaque', 'opaque '] as $identity) {
                $p = $this->payment(['method' => $provider, 'status' => 'paid', 'gateway_txn_id' => $identity]);
                $this->assertSame($provider, $p->fresh()->settled_provider);
                $this->assertSame($identity, $p->fresh()->settled_provider_txn_id);
            }
            try { $this->payment(['method' => strtoupper($provider), 'status' => 'paid', 'gateway_txn_id' => 'opaque']); $this->fail('Duplicate accepted'); }
            catch (QueryException $e) { $this->assertSame('23000', (string) $e->getCode()); }
        }
    }

    public function test_pesapal_identity_and_duplicate_rejection_within_and_across_schools(): void
    {
        $this->migration()->up();
        $first = $this->payment(['status' => 'paid']);
        $this->assertSame('pesapal', $first->fresh()->settled_provider);
        $this->assertSame($first->gateway_txn_id, $first->fresh()->settled_provider_txn_id);
        foreach ([$this->school, $this->makeSchool()] as $school) {
            $p = $this->payment([], $school);
            try { DB::table('application_payments')->where('id', $p->id)->update(['status' => 'paid']); $this->fail('Duplicate accepted'); }
            catch (QueryException $e) { $this->assertSame('23000', (string) $e->getCode()); }
            $this->assertSame('pending', $p->fresh()->status);
        }
        $this->assertSame(1, ApplicationPayment::where('status', 'paid')->count());
    }

    public function test_nonsettled_manual_and_insufficient_historical_identities_remain_excluded(): void
    {
        $this->migration()->up();
        foreach (['pending', 'failed'] as $status) {
            $p = $this->payment(['status' => $status]);
            $this->assertNull($p->fresh()->settled_provider);
        }
        foreach (['offline', 'cash', 'bank', 'waived'] as $method) {
            for ($i = 0; $i < 2; $i++) {
                $p = $this->payment(['method' => $method, 'status' => 'paid']);
                $this->assertNull($p->fresh()->settled_provider);
            }
        }
        foreach ([null, '', " \t\r\n "] as $identity) {
            $p = $this->payment(['status' => 'paid', 'gateway_txn_id' => $identity]);
            $this->assertSame($identity, $p->fresh()->gateway_txn_id);
            $this->assertNull($p->fresh()->settled_provider_txn_id);
        }
        $this->payment(['status' => 'paid']);
        $this->assertSame(14, ApplicationPayment::count());
    }

    public function test_down_restores_exact_v1_and_reup_restores_protection_without_rewriting_history(): void
    {
        $p = $this->payment(['status' => 'paid']);
        $before = DB::selectOne("SELECT sql FROM sqlite_master WHERE name='application_payments'")->sql;
        $rows = DB::table('application_payments')->select('id', 'method', 'status', 'gateway_txn_id')->get()->toJson();
        $this->migration()->up();
        $this->assertSame('pesapal', $p->fresh()->settled_provider);
        $this->migration()->down();
        $this->assertSame($before, DB::selectOne("SELECT sql FROM sqlite_master WHERE name='application_payments'")->sql);
        $this->assertNull($p->fresh()->settled_provider);
        $this->migration()->up();
        $this->assertSame('pesapal', $p->fresh()->settled_provider);
        $this->assertSame($rows, DB::table('application_payments')->select('id', 'method', 'status', 'gateway_txn_id')->get()->toJson());
    }

    public function test_preflight_reports_new_duplicates_variants_missing_aliases_and_cross_provider_reuse_read_only(): void
    {
        $a = $this->payment(['status' => 'paid']);
        $b = $this->payment(['status' => 'paid'], $this->makeSchool());
        $v = $this->payment(['status' => 'paid', 'method' => ' PESAPAL ', 'gateway_txn_id' => strtoupper($a->gateway_txn_id) . ' ']);
        $missing = $this->payment(['status' => 'paid', 'gateway_txn_id' => null]);
        $alias = $this->payment(['status' => 'paid', 'method' => 'pesa_pal']);
        $this->payment(['method' => 'marzpay', 'status' => 'paid']);
        $before = DB::table('application_payments')->get()->toJson();
        $report = PaymentIdentityPreflight::inspect(DB::connection());
        $this->assertSame([[$a->id, $b->id]], $report['duplicate_eligible_row_ids']);
        $this->assertSame([$a->id, $b->id, $v->id], $report['newly_eligible_pesapal_row_ids']);
        $this->assertSame([$missing->id], $report['paid_online_missing_identity_row_ids']);
        $this->assertSame([$alias->id], $report['ambiguous_pesapal_provider_row_ids']);
        $this->assertCount(1, $report['case_whitespace_variant_row_groups']);
        $this->assertCount(1, $report['cross_provider_identity_row_groups']);
        $this->artisan('payments:identity-preflight')->assertExitCode(1);
        try { $this->migration()->up(); $this->fail('Duplicate history ignored'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Duplicate settled payment identities', $e->getMessage()); }
        $this->assertSame($before, DB::table('application_payments')->get()->toJson());
        $this->assertNull($a->fresh()->settled_provider);
        $this->expectException(QueryException::class);
        $this->payment(['method' => 'marzpay', 'status' => 'paid']);
    }

    public function test_settlement_binding_replay_unique_conflict_and_rollback_are_preserved(): void
    {
        $this->migration()->up();
        $p = $this->payment();
        foreach (['schoolId' => $this->makeSchool(), 'amount' => '1.00', 'currency' => 'KES', 'reference' => 'wrong', 'transactionId' => 'wrong', 'provider' => 'pesa_pal'] as $field => $value) {
            $this->assertSame('rejected', ApplicationPaymentSettlement::apply($this->evidence($p, [$field => $value])));
        }
        $fail = true;
        Admission::saving(function () use (&$fail) { if ($fail) { throw new \RuntimeException('injected'); } });
        try { ApplicationPaymentSettlement::apply($this->evidence($p)); $this->fail('Injection missing'); }
        catch (\RuntimeException $e) { $this->assertSame('injected', $e->getMessage()); }
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertNull($p->fresh()->paid_at);
        $this->assertSame('unpaid', Admission::find($p->admission_id)->fee_status);
        $this->assertSame(0, DB::table('audit_logs')->count());
        $fail = false;
        $this->assertSame('settled', ApplicationPaymentSettlement::apply($this->evidence($p)));
        $this->assertSame('already_settled', ApplicationPaymentSettlement::apply($this->evidence($p)));
        $other = $this->payment([], $this->makeSchool());
        $this->assertSame('rejected', ApplicationPaymentSettlement::apply($this->evidence($other)));
        $legacy = $this->payment(['method' => ' pesapal ', 'status' => 'paid', 'gateway_txn_id' => 'legacy-unique']);
        $loser = $this->payment(['gateway_txn_id' => $legacy->gateway_txn_id], $this->makeSchool());
        $this->assertSame('rejected', ApplicationPaymentSettlement::apply($this->evidence($loser)));
        $this->assertSame('pending', $loser->fresh()->status);
        $this->assertNull($loser->fresh()->paid_at);
    }
}
