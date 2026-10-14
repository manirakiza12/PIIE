<?php

namespace Tests\Feature;

use App\Models\StudentFeeManager;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The offline-payment approval/decline decision.
 *
 * Two things are locked down here:
 *
 *  1. A declined payment must record ZERO paid. The admin portal's decline
 *     branch previously kept the full invoice total in paid_amount while
 *     setting status='unpaid' — an unpaid invoice that reported the whole
 *     amount received. The accountant portal already zeroed it, so the two
 *     finance roles disagreed about what a decline means.
 *
 *  2. The decision may only be applied to an invoice actually awaiting review
 *     of an offline claim. `admin.update_offline_payment` is a GET route, so it
 *     is trivially reachable and could previously be pointed at an invoice
 *     that was `processing` on a live gateway, or one already `paid`.
 *
 * Runs on in-memory SQLite; no mail is sent (array mailer).
 */
class OfflinePaymentDecisionTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->schoolId = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->schoolId);

        // The proof column the two submit routes write. Added here for the same
        // reason the real migration now adds it: no migration in this repository
        // created it, and the shared test schema predates it.
        if (! Schema::hasColumn('student_fee_managers', 'document_image')) {
            Schema::table('student_fee_managers', function (Blueprint $table) {
                $table->string('document_image')->nullable();
            });
        }
    }

    private function fee(array $overrides = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_merge([
            'title' => 'Tuition', 'total_amount' => 5000, 'amount' => 5000, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'offline', 'paid_amount' => 0,
            'status' => 'pending', 'school_id' => $this->schoolId,
        ], $overrides));
    }

    private function decide(StudentFeeManager $fee, string $status): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.update_offline_payment', [$fee->id, $status]));
    }

    // ── The decline defect ────────────────────────────────────────────────

    public function test_declining_an_offline_payment_records_nothing_as_paid(): void
    {
        $fee = $this->fee(['paid_amount' => 5000, 'status' => 'pending']);

        $this->decide($fee, 'decline');

        $fee->refresh();
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame(0, (int) $fee->paid_amount, 'A declined payment must not report an amount received.');
        $this->assertGreaterThan(0, (int) $fee->total_amount, 'The invoice total itself is untouched.');
    }

    /** The accountant portal's decline must behave identically to the admin one. */
    public function test_the_accountant_portal_declines_identically(): void
    {
        $fee = $this->fee(['paid_amount' => 5000]);

        $accountant = User::factory()->create([
            'role_id' => 4, 'school_id' => $this->schoolId, 'account_status' => 'active',
        ]);

        $this->actingAs($accountant)
            ->get(route('accountant.update_offline_payment', [$fee->id, 'decline']));

        $fee->refresh();
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    // ── Approval ──────────────────────────────────────────────────────────

    public function test_approving_an_offline_payment_credits_the_invoice_total(): void
    {
        $fee = $this->fee();

        $this->decide($fee, 'approve');

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(5000, (int) $fee->paid_amount);
        $this->assertSame('offline', $fee->payment_method);
    }

    // ── The decision may only touch an invoice awaiting review ─────────────

    public function test_an_invoice_processing_on_a_gateway_is_not_approved_offline(): void
    {
        $fee = $this->fee([
            'status' => 'processing',
            'payment_method' => 'marzpay',
            'gateway_reference' => 'txn-1',
        ]);

        $this->decide($fee, 'approve');

        $fee->refresh();
        $this->assertSame('processing', $fee->status, 'A live gateway payment must not be settled by an offline decision.');
        $this->assertSame('marzpay', $fee->payment_method);
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    public function test_a_gateway_processing_invoice_is_not_declined_offline_either(): void
    {
        $fee = $this->fee([
            'status' => 'processing',
            'payment_method' => 'marzpay',
            'gateway_reference' => 'txn-1',
        ]);

        $this->decide($fee, 'decline');

        $fee->refresh();
        $this->assertSame('processing', $fee->status, 'A decline must not disturb a live gateway payment either.');
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    /**
     * A legacy invoice with an empty payment_method must still be decidable.
     *
     * Requiring payment_method === 'offline' would strand every such row with no
     * way to be approved, so the guard keys on status alone. This test pins that
     * decision, because it is a deliberate loosening rather than an oversight.
     */
    public function test_a_legacy_invoice_with_no_payment_method_is_still_decidable(): void
    {
        $fee = $this->fee(['payment_method' => '', 'status' => 'pending']);

        $this->decide($fee, 'approve');

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(5000, (int) $fee->paid_amount);
    }

    public function test_an_already_paid_invoice_is_not_declined_back_to_unpaid(): void
    {
        $fee = $this->fee(['status' => 'paid', 'paid_amount' => 5000]);

        $this->decide($fee, 'decline');

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(5000, (int) $fee->paid_amount);
    }

    public function test_an_unknown_decision_value_changes_nothing(): void
    {
        $fee = $this->fee();

        $this->decide($fee, 'refund-it');

        $fee->refresh();
        $this->assertSame('pending', $fee->status);
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_a_school_admin_cannot_decide_another_schools_payment(): void
    {
        $otherSchool = $this->makeSchool();
        $foreign = StudentFeeManager::create([
            'title' => 'Tuition', 'total_amount' => 5000, 'amount' => 5000, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'offline', 'paid_amount' => 0,
            'status' => 'pending', 'school_id' => $otherSchool,
        ]);

        $this->decide($foreign, 'approve')->assertNotFound();

        $foreign->refresh();
        $this->assertSame('pending', $foreign->status);
        $this->assertSame(0, (int) $foreign->paid_amount);
    }
}