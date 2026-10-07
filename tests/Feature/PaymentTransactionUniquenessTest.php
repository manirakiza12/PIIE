<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\PaymentIdentityPreflight;
use App\Support\Payments\SettledPaymentIdentity;
use App\Support\Payments\VerifiedApplicationPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PaymentTransactionUniquenessTest extends TestCase
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
    }

    private function migration()
    {
        return require database_path('migrations/2026_10_07_160000_add_settled_transaction_uniqueness_to_application_payments.php');
    }

    private function payment(array $attributes = [], ?int $school = null): ApplicationPayment
    {
        $school = $school ?? $this->school;
        $admission = $this->makeAdmission($school, [
            'intake_session_id' => $this->makeIntakeSession($school, ['application_fee' => '50000.00']),
            'status' => Admission::STATUS_SUBMITTED,
        ]);
        return ApplicationPayment::create(array_merge([
            'school_id' => $school, 'admission_id' => $admission, 'method' => 'marzpay',
            'status' => 'pending', 'amount' => '50000.00', 'currency' => 'UGX',
            'reference' => 'REF-' . $admission, 'gateway_txn_id' => 'TX-' . $admission,
        ], $attributes));
    }

    private function evidence(ApplicationPayment $p): VerifiedApplicationPayment
    {
        return new VerifiedApplicationPayment((int) $p->id, (int) $p->school_id,
            $p->method, $p->reference, $p->gateway_txn_id, (string) $p->amount, $p->currency, 'paid', []);
    }

    public function test_migration_accepts_existing_rows_and_preserves_history_on_down_and_reup(): void
    {
        $paid = $this->payment(['status' => 'paid']);
        $historical = $this->payment(['status' => 'paid', 'gateway_txn_id' => null, 'currency' => null]);
        $before = DB::table('application_payments')->select('id', 'gateway_txn_id', 'status')->get()->toJson();
        $this->migration()->up();
        $this->assertSame($paid->gateway_txn_id, DB::table('application_payments')->where('id', $paid->id)->value('settled_provider_txn_id'));
        $this->assertNull(DB::table('application_payments')->where('id', $historical->id)->value('settled_provider_txn_id'));
        $this->migration()->down();
        $this->assertSame($before, DB::table('application_payments')->select('id', 'gateway_txn_id', 'status')->get()->toJson());
        $this->migration()->up();
        $this->assertSame(2, ApplicationPayment::count());
    }

    public function test_database_rejects_duplicate_settled_identity_across_schools(): void
    {
        $this->migration()->up();
        $first = $this->payment(['status' => 'paid']);
        $other = $this->payment(['gateway_txn_id' => $first->gateway_txn_id], $this->makeSchool());
        try {
            DB::table('application_payments')->where('id', $other->id)->update(['status' => 'paid']);
            $this->fail('Database accepted a duplicate credit.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertSame('pending', $other->fresh()->status);
        $this->assertSame(1, ApplicationPayment::where('status', 'paid')->count());
    }

    public function test_pending_and_failed_rows_do_not_consume_identity(): void
    {
        $this->migration()->up();
        $pending = $this->payment(['gateway_txn_id' => 'shared']);
        $failed = $this->payment(['gateway_txn_id' => 'shared', 'status' => 'failed']);
        $this->assertNull(DB::table('application_payments')->where('id', $pending->id)->value('settled_provider'));
        $this->assertNull(DB::table('application_payments')->where('id', $failed->id)->value('settled_provider'));
        $this->assertSame('settled', ApplicationPaymentSettlement::apply($this->evidence($pending)));
        $this->assertSame('rejected', ApplicationPaymentSettlement::apply($this->evidence($failed)));
    }

    public function test_offline_manual_and_null_historical_rows_remain_supported(): void
    {
        $this->migration()->up();
        foreach (['offline', 'cash', 'bank', 'waived'] as $method) {
            $this->payment(['method' => $method, 'status' => 'paid', 'gateway_txn_id' => 'bank-proof']);
            $this->payment(['method' => $method, 'status' => 'paid', 'gateway_txn_id' => 'bank-proof']);
        }
        foreach ([null, '', " \t\r\n "] as $identity) {
            $p = $this->payment(['status' => 'paid', 'gateway_txn_id' => $identity]);
            $this->assertSame($identity, $p->fresh()->gateway_txn_id);
            $this->assertNull(DB::table('application_payments')->where('id', $p->id)->value('settled_provider'));
        }
        $this->assertSame(11, ApplicationPayment::count());
    }

    public function test_transaction_identity_is_provider_scoped_and_byte_exact(): void
    {
        $this->migration()->up();
        foreach ([['marzpay', 'opaque'], ['stripe', 'opaque'], ['flutterwave', 'opaque'],
            ['marzpay', 'Opaque'], ['marzpay', 'opaque ']] as [$provider, $id]) {
            $this->payment(['status' => 'paid', 'method' => $provider, 'gateway_txn_id' => $id]);
        }
        $this->assertSame(5, ApplicationPayment::count());
        $this->assertCount(1, PaymentIdentityPreflight::inspect(DB::connection())['case_whitespace_variant_row_groups']);
    }

    public function test_provider_label_variants_cannot_bypass_the_constraint(): void
    {
        $this->migration()->up();
        $this->payment(['method' => ' MARZPAY ', 'status' => 'paid', 'gateway_txn_id' => 'same']);
        $this->expectException(QueryException::class);
        $this->payment(['method' => 'marzpay', 'status' => 'paid', 'gateway_txn_id' => 'same']);
    }

    public function test_duplicate_history_aborts_before_schema_changes_without_rewriting_rows(): void
    {
        $this->payment(['status' => 'paid', 'gateway_txn_id' => 'duplicate']);
        $this->payment(['status' => 'paid', 'gateway_txn_id' => 'duplicate'], $this->makeSchool());
        $before = DB::table('application_payments')->get()->toJson();
        try { $this->migration()->up(); $this->fail('Duplicate history was ignored.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Duplicate settled payment identities', $e->getMessage()); }
        $this->assertSame($before, DB::table('application_payments')->get()->toJson());
        $columns = array_column(array_map(fn ($row) => (array) $row, DB::select('PRAGMA table_xinfo(application_payments)')), 'name');
        $this->assertNotContains('settled_provider', $columns);
    }

    public function test_preflight_is_read_only_and_reports_duplicates_missing_variants_and_namespaces(): void
    {
        $first = $this->payment(['status' => 'paid', 'gateway_txn_id' => 'shared']);
        $second = $this->payment(['status' => 'paid', 'gateway_txn_id' => 'shared'], $this->makeSchool());
        $variant = $this->payment(['status' => 'paid', 'gateway_txn_id' => 'SHARED ']);
        $missing = $this->payment(['status' => 'paid', 'gateway_txn_id' => null]);
        $this->payment(['method' => 'offline', 'status' => 'paid']);
        $before = DB::table('application_payments')->get()->toJson();
        $report = PaymentIdentityPreflight::inspect(DB::connection());
        $this->assertSame([[$first->id, $second->id]], $report['duplicate_eligible_row_ids']);
        $this->assertSame([$missing->id], $report['paid_online_missing_identity_row_ids']);
        $this->assertSame([[$first->id, $second->id, $variant->id]], $report['case_whitespace_variant_row_groups']);
        $this->assertCount(2, $report['provider_namespace_review'][0]['school_ids']);
        $this->artisan('payments:identity-preflight')->assertExitCode(1);
        $this->assertSame($before, DB::table('application_payments')->get()->toJson());
    }

    public function test_replay_is_idempotent_with_database_protection(): void
    {
        $this->migration()->up();
        $p = $this->payment();
        $this->assertSame('settled', ApplicationPaymentSettlement::apply($this->evidence($p)));
        $at = $p->fresh()->paid_at;
        $audits = DB::table('audit_logs')->count();
        $this->assertSame('already_settled', ApplicationPaymentSettlement::apply($this->evidence($p)));
        $this->assertEquals($at, $p->fresh()->paid_at);
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_named_mysql_identity_conflict_rolls_back_and_webhook_returns_200(): void
    {
        $this->migration()->up();
        $p = $this->payment();
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'marzpay', 'status' => 1,
            'mode' => 'test', 'payment_keys' => json_encode(['sandbox_api_key' => 'fixture', 'sandbox_api_secret' => 'fixture', 'country' => 'UG'])]);
        Http::fake(['wallet.wearemarz.com/api/v1/collect-money/*' => Http::response(['data' => [
            'transaction' => ['uuid' => $p->gateway_txn_id, 'reference' => $p->reference, 'status' => 'successful'],
            'collection' => ['amount' => ['raw' => '50000.00', 'currency' => 'UGX']],
        ]])]);
        $inject = true;
        Admission::saving(function () use (&$inject) {
            if (! $inject) { return; }
            $pdo = new \PDOException('Duplicate entry for key ' . SettledPaymentIdentity::INDEX, 23000);
            $pdo->errorInfo = ['23000', 1062, 'Duplicate entry for key ' . SettledPaymentIdentity::INDEX];
            throw new QueryException('UPDATE admissions', [], $pdo);
        });
        $this->postJson(route('webhooks.marzpay'), ['event_type' => 'collection.completed',
            'transaction' => ['uuid' => $p->gateway_txn_id],
            'metadata' => [['context' => 'application'], ['context_id' => $p->id]],
        ])->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame('pending', $p->fresh()->status);
        $this->assertNull($p->fresh()->paid_at);
        $this->assertSame('unpaid', Admission::find($p->admission_id)->fee_status);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Mail::assertNothingSent();
        $inject = false;
        $this->assertSame('settled', ApplicationPaymentSettlement::apply($this->evidence($p)));
    }

    public function test_real_constraint_conflict_from_a_canonical_provider_variant_is_rejected(): void
    {
        $this->migration()->up();
        $this->payment(['method' => ' marzpay ', 'status' => 'paid', 'gateway_txn_id' => 'canonical-conflict']);
        $loser = $this->payment(['gateway_txn_id' => 'canonical-conflict'], $this->makeSchool());
        $this->assertSame('rejected', ApplicationPaymentSettlement::apply($this->evidence($loser)));
        $this->assertSame('pending', $loser->fresh()->status);
        $this->assertNull($loser->fresh()->paid_at);
        $this->assertSame('unpaid', Admission::find($loser->admission_id)->fee_status);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Mail::assertNothingSent();
    }

    public function test_unrelated_duplicate_key_errors_are_not_swallowed(): void
    {
        $p = $this->payment();
        ApplicationPayment::updating(function () {
            $pdo = new \PDOException('Duplicate entry for key unrelated_unique', 23000);
            $pdo->errorInfo = ['23000', 1062, 'Duplicate entry for key unrelated_unique'];
            throw new QueryException('UPDATE application_payments', [], $pdo);
        });
        $this->expectException(QueryException::class);
        ApplicationPaymentSettlement::apply($this->evidence($p));
    }
}
