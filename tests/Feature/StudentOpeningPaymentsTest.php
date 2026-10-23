<?php

namespace Tests\Feature;

use App\Models\{StudentFeeAward, StudentFeeManager, StudentOpeningPayment, StudentProfile, TuitionPayment, User};
use App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility;
use App\Support\Payments\StudentOpeningPayments;
use App\Support\Billing\StudentFeeAwards;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentOpeningPaymentsTest extends TestCase
{
    use AdmissionsTestHelper;
    private User $admin;
    private User $student;
    private StudentFeeManager $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_22_000000_add_method_to_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_currency')->default('UGX'));
        $school = $this->makeSchool(['running_session' => 1]);
        $this->admin = $this->makeAdminUser($school);
        $this->student = User::create(['school_id' => $school, 'role_id' => 7, 'name' => 'Existing Student', 'email' => 'existing@example.test']);
        StudentProfile::create(['school_id' => $school, 'user_id' => $this->student->id, 'was_existing_at_portal_creation' => true]);
        $this->fee = StudentFeeManager::create(['school_id' => $school, 'student_id' => $this->student->id,
            'title' => 'Current session fees', 'total_amount' => 1000, 'amount' => 1000, 'paid_amount' => 0,
            'class_id' => 0, 'session_id' => 1, 'payment_method' => '', 'status' => 'unpaid', 'timestamp' => time()]);
    }

    private function record(array $extra = []): void
    {
        app(StudentOpeningPayments::class)->change($this->admin, $this->fee->id, array_merge([
            'action' => 'record', 'amount' => '600.00', 'paid_on' => now()->subMonth()->toDateString(), 'method' => 'bank_transfer',
            'reference' => 'OLD-RECEIPT-001', 'reason' => 'Verified against the original bank receipt.'], $extra));
    }

    private function reverse(): void
    {
        app(StudentOpeningPayments::class)->change($this->admin, $this->fee->id, [
            'action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Incorrect historical allocation; restore balance.']);
    }

    public function test_prior_payment_reduces_balance_and_is_separate_from_new_collections(): void
    {
        $this->record(['amount' => '600.25']);
        $fee = $this->fee->fresh();
        $this->assertEquals(600.25, $fee->paid_amount);
        $this->assertSame('600.25', $fee->opening_paid_amount);
        $this->assertEquals(399.75, $fee->total_amount - $fee->paid_amount);
        $this->assertEquals(0, $fee->collected_amount);
        $this->assertSame(0, TuitionPayment::count());
        $this->assertSame($this->admin->id, StudentOpeningPayment::first()->approved_by);
        $this->assertTrue(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_new_and_prior_payments_are_added_without_overwriting_existing_money(): void
    {
        $this->fee->update(['paid_amount' => 100]);
        $this->record();
        $fee = $this->fee->fresh();
        $this->assertEquals(700, $fee->paid_amount);
        $this->assertEquals(100, $fee->collected_amount);
        $this->reverse();
        $this->assertEquals(100, $this->fee->fresh()->paid_amount);
        $this->assertEquals(100, $this->fee->fresh()->collected_amount);
    }

    public function test_full_prior_payment_removes_checkout_without_a_fake_gateway_receipt(): void
    {
        $this->record(['amount' => '1000.00']);
        $this->assertSame('paid', $this->fee->fresh()->status);
        $this->actingAs($this->student)->get(route('student.FeePayment', $this->fee->id))
            ->assertOk()->assertSee('OLD-RECEIPT-001')->assertSee('Verified prior payment')->assertDontSee('Pay with PesaPal');
        $this->assertSame(0, TuitionPayment::count());
    }

    public function test_reversal_restores_balance_and_keeps_approval_history(): void
    {
        $this->record(['amount' => '1000.00']);
        $this->reverse();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
        $this->assertSame('0.00', $this->fee->fresh()->opening_paid_amount);
        $this->assertSame('unpaid', $this->fee->fresh()->status);
        $this->assertNotNull(StudentOpeningPayment::first()->reversed_at);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
        $this->expectException(ValidationException::class);
        $this->reverse();
    }

    public function test_duplicate_receipt_does_not_credit_twice_even_on_another_invoice(): void
    {
        $this->record();
        $other = $this->fee->replicate(); $other->forceFill(['paid_amount' => 0, 'opening_paid_amount' => 0])->save();
        try {
            app(StudentOpeningPayments::class)->change($this->admin, $other->id, [
                'action' => 'record', 'amount' => '100.00', 'paid_on' => now()->toDateString(), 'method' => 'cash',
                'reference' => ' old-receipt-001 ', 'reason' => 'Accidental duplicate receipt entry.']);
            $this->fail('Duplicate accepted');
        } catch (ValidationException $e) {}
        $this->assertEquals(0, $other->fresh()->paid_amount);
        $this->assertSame(1, StudentOpeningPayment::count());
    }

    public function test_overpayment_is_rejected_without_changing_invoice_or_history(): void
    {
        $this->fee->update(['paid_amount' => 500]);
        try { $this->record(); $this->fail('Overpayment accepted'); } catch (ValidationException $e) {}
        $this->assertEquals(500, $this->fee->fresh()->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_current_session_eligibility_does_not_use_past_session_payments(): void
    {
        $this->record();
        DB::table('schools')->where('id', $this->student->school_id)->update(['running_session' => 2]);
        $this->assertFalse(app(StudentRegistrationConfirmationEligibility::class)->allows($this->student));
    }

    public function test_pending_offline_claims_and_gateway_attempts_block_entry(): void
    {
        $this->fee->update(['status' => 'pending']);
        try { $this->record(); $this->fail('Unreviewed claim accepted'); } catch (ValidationException $e) {}
        $this->fee->update(['status' => 'unpaid']);
        foreach (['pending', 'failed'] as $status) {
            TuitionPayment::query()->delete();
            TuitionPayment::create(['school_id' => $this->fee->school_id, 'fee_id' => $this->fee->id, 'amount' => 1000,
                'currency' => 'UGX', 'method' => 'pesapal', 'status' => $status, 'reference' => $status,
                'gateway_txn_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3']);
            try { $this->record(); $this->fail('Unresolved attempt accepted'); } catch (ValidationException $e) {}
        }
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_finance_can_record_and_reverse_through_the_form(): void
    {
        $url = route('admin.tuition_payments.opening_payment', $this->fee->id);
        $this->actingAs($this->admin)->post($url, ['action' => 'record', 'amount' => '600.00',
            'paid_on' => now()->subDay()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'BANK-OLD-1', 'reason' => 'Original receipt verified by finance.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.tuition_payments.show', $this->fee->id))->assertOk()->assertSee('BANK-OLD-1')->assertSee('Reverse incorrect prior payment');
        $this->post($url, ['action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Correcting original receipt allocation.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
    }

    public function test_student_overview_and_invoice_report_link_to_their_own_payment_screen(): void
    {
        $this->actingAs($this->admin)->get(route('admin.fee_manager.list'))
            ->assertOk()->assertSee('Review invoice and financial setup')
            ->assertSee(route('admin.tuition_payments.show', $this->fee->id))
            ->assertDontSee('Payments / prior payments / awards');
        $adminHtml = $this->get(route('admin.fee_manager.list'))->getContent();
        $this->assertSame(2, substr_count($adminHtml, route('admin.tuition_payments.show', $this->fee->id)), 'One student overview link and one invoice report link.');

        $accountant = User::create(['school_id' => $this->fee->school_id, 'role_id' => 4, 'name' => 'Finance',
            'email' => 'finance-list@example.test', 'account_status' => 'active']);
        $accountantHtml = $this->actingAs($accountant)->get(route('accountant.fee_manager.list'))
            ->assertOk()->assertSee('Review invoice and financial setup')
            ->assertSee(route('accountant.tuition_payments.show', $this->fee->id))
            ->getContent();
        $this->assertSame(2, substr_count($accountantHtml, route('accountant.tuition_payments.show', $this->fee->id)), 'One student overview link and one invoice report link.');
    }

    public function test_accountant_can_reach_and_record_eligible_existing_student_prior_payment(): void
    {
        $accountant = User::create(['school_id' => $this->fee->school_id, 'role_id' => 4, 'name' => 'Finance',
            'email' => 'finance-entry@example.test', 'account_status' => 'active']);
        $this->assertTrue(app(\App\Support\Permissions\PermissionService::class)->allows($accountant, 'finance.payments'));

        $this->actingAs($accountant)->get(route('accountant.tuition_payments.show', $this->fee->id))
            ->assertOk()->assertSee('Record prior payment already received')
            ->assertSee(route('accountant.tuition_payments.opening_payment', $this->fee->id))
            ->assertDontSee('Pay with PesaPal / resume checkout');
        $this->actingAs($accountant)->post(route('accountant.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '250.00', 'paid_on' => now()->subDay()->toDateString(),
            'method' => 'bank_transfer', 'reference' => 'ACCOUNTANT-PRIOR-1', 'reason' => 'Original bank receipt verified.'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(250, $this->fee->fresh()->opening_paid_amount);
        $this->assertEquals(0, $this->fee->fresh()->collected_amount);
    }

    public function test_invoice_viewer_without_payment_permission_sees_reason_but_no_prior_entry_action(): void
    {
        $viewer = User::create(['school_id' => $this->fee->school_id, 'role_id' => 3, 'name' => 'Finance Viewer',
            'email' => 'finance-viewer@example.test', 'account_status' => 'active']);
        foreach (['finance.view', 'finance.invoices'] as $permission) {
            DB::table('user_permissions')->insert(['school_id' => $this->fee->school_id, 'user_id' => $viewer->id,
                'permission' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($viewer)->get(route('admin.tuition_payments.show', $this->fee->id))
            ->assertOk()->assertSee('does not have the finance payment-approval permission')
            ->assertSee('Record fee award')->assertDontSee('Verify and apply prior payment');
        $this->actingAs($viewer)->post(route('admin.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '100.00', 'paid_on' => now()->toDateString(), 'method' => 'cash',
            'reference' => 'VIEWER-UNAUTHORIZED', 'reason' => 'Viewer cannot approve prior payment.'])->assertForbidden();
        $this->assertSame(0, StudentOpeningPayment::count());
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
    }

    public function test_award_and_prior_payment_have_separate_invoice_actions_and_accounting(): void
    {
        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $this->fee->id))
            ->assertOk()->assertSee('Record prior payment already received')->assertSee('Record fee award');
        $this->record(['amount' => '100.00']);
        app(StudentFeeAwards::class)->change($this->admin, $this->fee->id, [
            'action' => 'grant', 'type' => 'scholarship', 'amount' => '200.00', 'reference' => 'SCH-200',
            'reason' => 'Approved scholarship coverage for this session.']);

        $html = $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $this->fee->id))
            ->assertOk()->assertSee('Prior payments already received')->assertSee('Record prior payment already received')
            ->assertSee('Scholarship or bursary award')->assertSee('Revoke award and restore fee')->getContent();
        $this->assertSame(2, substr_count($html, route('admin.tuition_payments.opening_payment', $this->fee->id)));
        $this->assertSame(1, substr_count($html, route('admin.tuition_payments.award', $this->fee->id)));
        $this->assertSame(1, StudentOpeningPayment::count());
        $this->assertSame(1, StudentFeeAward::count());
        $this->assertSame(0, TuitionPayment::count());
        $this->assertEquals(100, $this->fee->fresh()->opening_paid_amount);
        $this->assertEquals(0, $this->fee->fresh()->collected_amount);
        $this->assertEquals(100, $this->fee->fresh()->paid_amount);
    }

    public function test_invalid_precision_future_dates_and_missing_receipts_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '1.001', 'paid_on' => now()->addDay()->toDateString(), 'reason' => 'short'])
            ->assertSessionHasErrors(['amount', 'paid_on', 'method', 'reference', 'reason']);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_student_and_other_institution_cannot_record_payments(): void
    {
        $data = ['action' => 'record', 'amount' => '600.00', 'paid_on' => now()->toDateString(),
            'reference' => 'TENANT-1', 'method' => 'cash', 'reason' => 'Original payment verified by finance.'];
        $url = route('admin.tuition_payments.opening_payment', $this->fee->id);
        $this->actingAs($this->student)->post($url, $data)->assertRedirect();
        $this->actingAs($this->makeAdminUser($this->makeSchool()))->post($url, $data)->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_reversal_cannot_target_another_invoice(): void
    {
        $this->record();
        $other = $this->fee->replicate(); $other->save();
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $other->id), [
            'action' => 'reverse', 'opening_payment_id' => StudentOpeningPayment::first()->id,
            'reason' => 'Cannot reverse another invoice payment.'])->assertNotFound();
        $this->assertNull(StudentOpeningPayment::first()->reversed_at);
    }

    public function test_generic_invoice_edit_and_delete_preserve_history_after_reversal(): void
    {
        $this->record(); $this->reverse();
        $this->actingAs($this->admin)->post(route('admin.fee_manager.update', $this->fee->id), [])->assertStatus(422);
        $this->get(route('admin.fee_manager.delete', $this->fee->id))->assertStatus(422);
        $this->assertTrue($this->fee->fresh()->hasOpeningPaymentHistory());
    }

    public function test_dashboard_excludes_prior_payments_from_new_collections(): void
    {
        DB::table('roles')->insert(['role_id' => 7, 'name' => 'Student', 'school_id' => 0]);
        $accountant = User::create(['school_id' => $this->fee->school_id, 'role_id' => 4,
            'name' => 'Finance', 'email' => 'finance@example.test', 'account_status' => 'active']);
        $this->record();
        $this->actingAs($accountant)->get(route('accountant.dashboard'))->assertOk()
            ->assertViewHas('totalCollected', 0)->assertViewHas('openingPaymentsTotal', 600)
            ->assertViewHas('totalOutstanding', 400)->assertViewHas('recentPayments', fn ($payments) => $payments->isEmpty());
        $this->fee->refresh()->update(['paid_amount' => 700]);
        $this->get(route('accountant.dashboard'))->assertOk()->assertViewHas('totalCollected', 100)
            ->assertViewHas('totalOutstanding', 300);
    }

    public function test_finance_report_uses_combined_payments_for_debt_but_excludes_opening_money_from_collections(): void
    {
        $this->record();
        $this->actingAs($this->admin)->get(route('admin.reports.finance'))->assertOk()
            ->assertViewHas('totals', fn ($totals) => (float) $totals->invoiced === 1000.0 && (float) $totals->paid === 600.0 && (float) $totals->collected === 0.0);
    }

    public function test_printed_receipt_history_is_scoped_to_the_invoice_institution(): void
    {
        $this->record();
        $this->assertStringContainsString('OLD-RECEIPT-001', view('shared.invoice_opening_payments', ['invoice_details' => $this->fee->fresh()->toArray()])->render());
        $details = $this->fee->fresh()->toArray(); $details['school_id'] = $this->makeSchool();
        $this->assertStringNotContainsString('OLD-RECEIPT-001', view('shared.invoice_opening_payments', ['invoice_details' => $details])->render());
    }

    public function test_invoice_sync_and_refresh_preserve_prior_payments_without_duplicate_billing(): void
    {
        $structure = \App\Models\FeeStructure::create(['school_id' => $this->fee->school_id, 'name' => 'Tuition',
            'fee_type' => 'tuition', 'amount' => 1000, 'is_mandatory' => 1, 'session_id' => 1]);
        $this->fee->update(['fee_structure_id' => $structure->id]);
        $this->record();
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame([], \App\Support\StudentFeeInvoiceGenerator::generateForStudent($this->student, null, (int) $this->fee->school_id));
        }
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertEquals(600, $this->fee->fresh()->paid_amount);
        $this->assertEquals(400, $this->fee->fresh()->total_amount - $this->fee->fresh()->paid_amount);
    }

    public function test_staff_without_payment_approval_permission_cannot_credit_prior_money(): void
    {
        $lecturer = User::create(['school_id' => $this->fee->school_id, 'role_id' => 3, 'name' => 'Lecturer',
            'email' => 'lecturer@example.test', 'account_status' => 'active']);
        $this->actingAs($lecturer)->post(route('admin.tuition_payments.opening_payment', $this->fee->id), [
            'action' => 'record', 'amount' => '600.00', 'paid_on' => now()->toDateString(), 'method' => 'cash',
            'reference' => 'UNAUTHORIZED', 'reason' => 'Staff cannot approve payment without permission.'])->assertForbidden();
        $this->assertEquals(0, $this->fee->fresh()->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_online_applicant_and_regular_student_invoice_cannot_use_opening_payment_feature(): void
    {
        $regularStudent = User::create(['school_id' => $this->fee->school_id, 'role_id' => 7, 'name' => 'Regular Student', 'email' => 'regular@example.test']);
        StudentProfile::create(['school_id' => $this->fee->school_id, 'user_id' => $regularStudent->id]);
        $regularFee = $this->fee->replicate();
        $regularFee->forceFill(['student_id' => $regularStudent->id, 'paid_amount' => 0, 'opening_paid_amount' => 0])->save();
        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $regularFee->id))
            ->assertOk()->assertSee('Prior payment entry unavailable')->assertDontSee('Record prior payment already received');
        try {
            app(StudentOpeningPayments::class)->change($this->admin, $regularFee->id, [
                'action' => 'record', 'amount' => '100.00', 'paid_on' => now()->toDateString(), 'method' => 'cash',
                'reference' => 'REGULAR-ACCOUNT', 'reason' => 'Regular admissions students excluded.']);
            $this->fail('Regular student account accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_legacy_student_without_the_new_marker_can_record_prior_payment_after_finance_verification(): void
    {
        $legacy = User::create(['school_id' => $this->fee->school_id, 'role_id' => 7, 'name' => 'Legacy Student', 'email' => 'legacy-prior@example.test']);
        $profile = StudentProfile::create(['school_id' => $this->fee->school_id, 'user_id' => $legacy->id, 'was_existing_at_portal_creation' => false]);
        DB::table('student_profiles')->where('id', $profile->id)->update(['created_at' => '2026-10-20 12:00:00']);
        $legacyFee = $this->fee->replicate();
        $legacyFee->forceFill(['student_id' => $legacy->id, 'paid_amount' => 0, 'opening_paid_amount' => 0])->save();

        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $legacyFee->id))
            ->assertOk()->assertSee('Record prior payment already received');
        app(StudentOpeningPayments::class)->change($this->admin, $legacyFee->id, [
            'action' => 'record', 'amount' => '125.00', 'paid_on' => now()->subDay()->toDateString(),
            'method' => 'cash', 'reference' => 'LEGACY-VERIFIED-125', 'reason' => 'Legacy receipt inspected and verified by finance.',
        ]);

        $this->assertEquals(125, $legacyFee->fresh()->paid_amount);
        $this->assertSame('LEGACY-VERIFIED-125', StudentOpeningPayment::first()->reference);
    }

    public function test_legacy_profiles_linked_to_converted_applicants_are_not_prior_payment_candidates(): void
    {
        $converted = User::create(['school_id' => $this->fee->school_id, 'role_id' => 7, 'name' => 'Converted Student', 'email' => 'converted-prior@example.test']);
        $profile = StudentProfile::create(['school_id' => $this->fee->school_id, 'user_id' => $converted->id, 'was_existing_at_portal_creation' => false]);
        DB::table('student_profiles')->where('id', $profile->id)->update(['created_at' => '2026-10-20 12:00:00']);
        DB::table('applicants')->insert(['school_id' => $this->fee->school_id, 'first_name' => 'Converted', 'last_name' => 'Student',
            'email' => 'converted-applicant@example.test', 'password' => 'x', 'converted_user_id' => $converted->id,
            'created_at' => now(), 'updated_at' => now()]);
        $convertedFee = $this->fee->replicate();
        $convertedFee->forceFill(['student_id' => $converted->id, 'paid_amount' => 0, 'opening_paid_amount' => 0])->save();

        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $convertedFee->id))
            ->assertOk()->assertSee('Prior payment entry unavailable')->assertDontSee('Record prior payment already received');
        $this->assertFalse(app(\App\Support\Payments\ExistingStudentPriorPaymentEligibility::class)->allows($converted, (int) $converted->school_id));
    }

    public function test_fee_manager_has_one_visible_invoice_table_and_reports_award_and_balance_meanings(): void
    {
        $this->fee->update(['amount' => 1200000, 'total_amount' => 900000, 'discounted_price' => 0]);
        $html = $this->actingAs($this->admin)->get(route('admin.fee_manager.list'))
            ->assertOk()->assertViewHas('invoices', fn ($rows) => $rows->count() === 1)
            ->assertSee('Base amount')->assertSee('Invoice total')->assertSee('Balance due')
            ->assertSee('Amount fields need reconciliation; balance uses invoice total.')
            ->getContent();

        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $visibleTables = $xpath->query('//*[@id="student_fee_manager"]//table[not(ancestor-or-self::*[@hidden])]');
        $this->assertSame(1, $visibleTables->length, 'Only the live invoice table should be visible on screen.');
        $this->assertSame(0, $xpath->query('//*[@id="student_fee_report_export"]')->length, 'Admin has no print/export action for a duplicate report table.');

        $accountant = User::create(['school_id' => $this->fee->school_id, 'role_id' => 4, 'name' => 'Finance',
            'email' => 'duplicate-table-finance@example.test', 'account_status' => 'active']);
        $accountantHtml = $this->actingAs($accountant)->get(route('accountant.fee_manager.list'))
            ->assertOk()->assertViewHas('invoices', fn ($rows) => $rows->count() === 1)->getContent();
        $accountantDom = new \DOMDocument();
        @$accountantDom->loadHTML($accountantHtml);
        $accountantXpath = new \DOMXPath($accountantDom);
        $this->assertSame(1, $accountantXpath->query('//*[@id="student_fee_manager"]//table[not(ancestor-or-self::*[@hidden])]')->length);
        $this->assertSame(1, $accountantXpath->query('//*[@id="student_fee_report_export"][@hidden]')->length);
        $this->assertSame(1, $accountantXpath->query('//*[@id="student_fee_report_table"]')->length, 'The accountant print/export table must have a unique ID.');
    }

    public function test_printable_invoice_keeps_the_existing_summary_columns_and_layout_contract(): void
    {
        $this->record(['amount' => '100.00']);
        $html = $this->actingAs($this->admin)->get(route('admin.studentFeeinvoice', $this->fee->id))
            ->assertOk()->assertSee('INVOICE')->assertSee('Invoice no')->assertSee('printableDiv')
            ->assertSee('OLD-RECEIPT-001')->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $headers = $xpath->query('//*[@id="printableDiv"]//table[1]/thead/tr/th');
        $labels = array_map(fn ($node) => trim($node->textContent), iterator_to_array($headers));
        $this->assertSame(['ID', 'Invoice Title', 'Total Amount', 'Paid Amount', 'Due Amount'], $labels);
        $this->assertSame(1, $xpath->query('//*[@id="printableDiv"]')->length);
    }

    public function test_fee_manager_explains_an_award_that_reduced_invoice_total(): void
    {
        $this->fee->update(['amount' => 1200000, 'total_amount' => 1200000, 'discounted_price' => 0]);
        app(StudentFeeAwards::class)->change($this->admin, $this->fee->id, [
            'action' => 'grant', 'type' => 'scholarship', 'amount' => '300000',
            'reference' => 'SCH-300', 'reason' => 'Approved scholarship for the current study period.',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.fee_manager.list'))->assertOk()
            ->assertSee('Base amount')->assertSee('Invoice total')->assertSee('Balance due')
            ->assertSee('Scholarship:')->assertDontSee('Amount fields need reconciliation; balance uses invoice total.');
        $this->assertEquals(0, $this->fee->fresh()->total_amount - $this->fee->fresh()->paid_amount - 900000);
    }
}
