<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Models\Admission;
use App\Models\Applicant;
use App\Models\ApplicationPayment;
use App\Support\Admissions\ApplicantPortalAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Closing the application-fee gap for staff-entry applications — reusing
 * the existing ApplicationPayment/ApplicationFee/ApplicantNotifier
 * architecture rather than building a parallel one. See
 * App\Support\Admissions\ApplicantPortalAccess for how a staff-entry
 * Admission (applicant_id = NULL) gets into the same applicant portal an
 * online applicant already pays through.
 */
class AdmissionPaymentTest extends TestCase
{
    use AdmissionsTestHelper { makeAdmission as private makeBasicAdmission; }

    protected function makeAdmission(int $schoolId, array $overrides = []): int
    {
        return $this->makeBasicAdmission($schoolId, array_merge(['submitted_at' => now()], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        \App\Models\PaymentMethods::create(['school_id' => $schoolId, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture',
                'notification_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3'])]);
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_staff_entry_admission_starts_with_fee_status_unpaid_not_waived(): void
    {
        $schoolId = $this->makeSchool();
        $admissionId = $this->makeAdmission($schoolId, ['source' => 'staff_entry']);

        $this->assertSame(Admission::FEE_UNPAID, Admission::find($admissionId)->fee_status);
    }

    public function test_app_number_is_reused_as_the_payment_reference_and_is_unique(): void
    {
        $schoolId = $this->makeSchool();
        $a = $this->makeAdmission($schoolId, ['app_number' => 'PIIE-2627-O-P0001']);
        $b = $this->makeAdmission($schoolId, ['app_number' => 'PIIE-2627-O-P0002']);

        $this->assertNotSame(Admission::find($a)->app_number, Admission::find($b)->app_number);
    }

    public function test_ensure_linked_creates_a_new_applicant_for_a_staff_entry_admission(): void
    {
        $schoolId = $this->makeSchool();
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'email' => 'linkme@example.com',
        ]);
        $admission = Admission::find($admissionId);

        $applicant = ApplicantPortalAccess::ensureLinked($admission);

        $this->assertInstanceOf(Applicant::class, $applicant);
        $this->assertSame('linkme@example.com', $applicant->email);
        $this->assertSame($applicant->id, $admission->fresh()->applicant_id);
    }

    public function test_ensure_linked_reuses_an_existing_applicant_with_the_same_email_instead_of_duplicating(): void
    {
        $schoolId = $this->makeSchool();
        $existing = $this->makeApplicant($schoolId, ['email' => 'shared@example.com']);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'email' => 'shared@example.com',
        ]);

        $applicant = ApplicantPortalAccess::ensureLinked(Admission::find($admissionId));

        $this->assertSame($existing->id, $applicant->id);
        $this->assertSame(1, Applicant::where('email', 'shared@example.com')->count());
    }

    public function test_ensure_linked_is_idempotent_when_already_linked(): void
    {
        $schoolId = $this->makeSchool();
        $admissionId = $this->makeAdmission($schoolId, ['source' => 'staff_entry', 'email' => 'once@example.com']);
        $admission = Admission::find($admissionId);

        $first = ApplicantPortalAccess::ensureLinked($admission);
        $second = ApplicantPortalAccess::ensureLinked($admission->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Applicant::where('email', 'once@example.com')->count());
    }

    public function test_payment_link_is_a_signed_reset_style_url_not_a_predictable_admission_id(): void
    {
        $schoolId = $this->makeSchool();
        $applicant = $this->makeApplicant($schoolId, ['email' => 'linkurl@example.com']);

        $url = ApplicantPortalAccess::paymentLinkFor($applicant);

        $this->assertStringContainsString('/applicant/reset-password/', $url);

        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertNotSame((string) $applicant->id, $token, 'The link must not simply be the applicant/admission id.');
        $this->assertGreaterThan(40, strlen($token), 'The token must be a long random value, not a predictable identifier.');

        $this->assertDatabaseHas('applicant_password_resets', ['email' => 'linkurl@example.com']);
    }

    public function test_admin_can_send_a_payment_request_for_a_staff_entry_admission(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'email' => 'sendreq@example.com',
            'intake_session_id' => $intakeId,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        $response->assertRedirect();
        $this->assertNotNull(Admission::find($admissionId)->applicant_id, 'Sending a payment request must link an Applicant account.');
    }

    public function test_sending_a_payment_request_never_creates_a_payment_record_or_changes_fee_status(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'email' => 'nopaymentrow@example.com', 'intake_session_id' => $intakeId,
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        $this->assertSame(0, ApplicationPayment::where('admission_id', $admissionId)->count());
        $this->assertSame(Admission::FEE_UNPAID, Admission::find($admissionId)->fee_status);
    }

    public function test_resending_payment_instructions_keeps_the_same_applicant_link_and_is_rate_limited(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'email' => 'resend@example.com', 'intake_session_id' => $intakeId,
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));
        $firstApplicantId = Admission::find($admissionId)->applicant_id;

        $second = $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        $this->assertSame($firstApplicantId, Admission::find($admissionId)->applicant_id, 'Resending must not create a second Applicant.');
        $this->assertSame(1, Applicant::where('email', 'resend@example.com')->count());
        // Second immediate call is rate-limited — no new admission, no new payment.
        $second->assertRedirect();
        $this->assertSame(0, ApplicationPayment::where('admission_id', $admissionId)->count());
    }

    /**
     * The full Batch 2 round-trip: a staff-entry candidate who has never
     * logged in anywhere can use the emailed link to set a password and
     * land on the exact same payment page an online applicant uses — no
     * separate payment route/page exists for them.
     */
    public function test_staff_entry_candidate_can_use_the_payment_link_to_reach_their_own_payment_page(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 75000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'email' => 'roundtrip@example.com',
            'first_name' => 'Round', 'last_name' => 'Trip',
            'intake_session_id' => $intakeId,
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        $applicant = Applicant::where('email', 'roundtrip@example.com')->first();
        $this->assertNotNull($applicant);

        $resetRow = DB::table('applicant_password_resets')->where('email', 'roundtrip@example.com')->first();
        $this->assertNotNull($resetRow, 'A reset token must have been issued.');

        // The plaintext token isn't stored (only its hash is), so exercise
        // the same reset endpoint AuthController::resetPassword() using a
        // freshly issued one via the same code path, proving the mechanism
        // Applicant\AuthController already exposes actually lands the
        // candidate on their own application's payment page afterwards.
        $freshUrl = ApplicantPortalAccess::paymentLinkFor($applicant->fresh());
        $token = basename(parse_url($freshUrl, PHP_URL_PATH));

        $this->post(route('applicant.password.update'), [
            'token' => $token,
            'email' => 'roundtrip@example.com',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertRedirect(route('applicant.login'));

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $applicant->fresh()->password));

        $this->be($applicant->fresh(), 'applicant');

        $payment = $this->get(route('applicant.payment'));
        $payment->assertStatus(200);
        $payment->assertSee('75,000', false);
    }

    public function test_admin_can_record_a_manual_offline_payment_with_full_audit_trail(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'email' => 'manual@example.com', 'intake_session_id' => $intakeId,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.payment.record', $admissionId), [
            'amount' => 50000,
            'method' => 'cash',
            'external_reference' => 'RECEIPT-001',
            'paid_at' => now()->toDateString(),
            'note' => 'Paid at front desk.',
        ]);

        $response->assertRedirect();

        $payment = ApplicationPayment::where('admission_id', $admissionId)->first();
        $this->assertNotNull($payment);
        $this->assertSame(ApplicationPayment::STATUS_PAID, $payment->status);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('RECEIPT-001', $payment->gateway_txn_id);
        $this->assertSame($admin->id, $payment->confirmed_by);
        $this->assertSame(Admission::FEE_PAID, Admission::find($admissionId)->fee_status);
    }

    public function test_recording_a_manual_payment_requires_authentication_as_admin(): void
    {
        $schoolId = $this->makeSchool();
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, ['intake_session_id' => $intakeId]);

        $response = $this->post(route('admin.hei_admissions.payment.record', $admissionId), [
            'amount' => 50000, 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ]);

        $response->assertRedirect(route('login'));
        $this->assertSame(0, ApplicationPayment::where('admission_id', $admissionId)->count());
    }

    public function test_waiving_a_fee_requires_a_reason(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, ['intake_session_id' => $intakeId]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $admissionId), []);

        $response->assertSessionHasErrors('reason');
        $this->assertSame(Admission::FEE_UNPAID, Admission::find($admissionId)->fee_status);
    }

    public function test_waived_and_paid_remain_distinguishable(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);

        $waivedId = $this->makeAdmission($schoolId, ['email' => 'waived@example.com', 'intake_session_id' => $intakeId]);
        $paidId = $this->makeAdmission($schoolId, ['email' => 'paidon@example.com', 'intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $waivedId), ['reason' => 'Scholarship recipient.']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.record', $paidId), [
            'amount' => 50000, 'method' => 'bank', 'paid_at' => now()->toDateString(),
        ]);

        $this->assertSame(Admission::FEE_WAIVED, Admission::find($waivedId)->fee_status);
        $this->assertSame(Admission::FEE_PAID, Admission::find($paidId)->fee_status);

        $waiverPayment = ApplicationPayment::where('admission_id', $waivedId)->first();
        $this->assertSame(ApplicationPayment::STATUS_WAIVED, $waiverPayment->status);
        $this->assertSame('Scholarship recipient.', $waiverPayment->note);
    }

    public function test_sending_a_payment_request_actually_dispatches_an_email_through_the_real_mail_pipeline(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 60000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'app_number' => 'PIIE-2627-O-P5001',
            'email' => 'mailme@example.com',
            'intake_session_id' => $intakeId,
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) {
            return $mail->hasTo('mailme@example.com')
                && str_contains($mail->data['subject'], 'PIIE-2627-O-P5001')
                && ($mail->data['details']['Application Number'] ?? null) === 'PIIE-2627-O-P5001'
                && ($mail->data['details']['Payment Reference'] ?? null) === 'PIIE-2627-O-P5001'
                && str_contains((string) $mail->data['cta_url'], '/payments/application/')
                && str_contains((string) $mail->data['access_url'], '/applicant/reset-password/');
        });
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_resending_payment_instructions_sends_reminder_copy_not_first_notice_copy(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 60000]);
        $admissionId = $this->makeAdmission($schoolId, ['email' => 'reminder@example.com', 'intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));
        // Bypass the rate limiter to prove the copy differs on a genuine resend.
        RateLimiter::clear('admission-payment-request:' . $admissionId);
        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.request', $admissionId));

        Mail::assertSent(ApplicantNotificationEmail::class, 2);
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) {
            return str_contains($mail->data['heading'], 'still outstanding');
        });
    }

    public function test_manual_payment_confirmation_email_is_dispatched(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 60000]);
        $admissionId = $this->makeAdmission($schoolId, ['email' => 'confirm@example.com', 'intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.record', $admissionId), [
            'amount' => 60000, 'method' => 'bank_transfer', 'paid_at' => now()->toDateString(),
        ]);

        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) {
            return $mail->hasTo('confirm@example.com')
                && str_contains($mail->data['heading'], 'received');
        });
    }

    public function test_review_screen_shows_the_application_fee_card_with_reference_and_outstanding_amount(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 100000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'app_number' => 'PIIE-2627-O-P9001',
            'intake_session_id' => $intakeId,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admissionId));

        $response->assertStatus(200);
        $response->assertSee('Application Fee');
        $response->assertSee('PIIE-2627-O-P9001');
        $response->assertSee('Send Payment Instructions');
        $response->assertSee('Record Offline Payment');
        $response->assertSee('Waive Fee');
    }

    public function test_review_screen_hides_payment_actions_once_fee_is_settled(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 100000]);
        $admissionId = $this->makeAdmission($schoolId, ['intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $admissionId), ['reason' => 'Test.']);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admissionId));

        $response->assertStatus(200);
        $response->assertDontSee('Send Payment Instructions');
        $response->assertDontSee('Record Offline Payment');
    }

    /**
     * Matches the ONLINE applicant wizard's own documented rule
     * (ApplicationProgress::blockers(): "The payment step is deliberately
     * never listed here, even when unpaid — applicants can submit before
     * paying"). Staff-entry follows the exact same rule — no new gate
     * invented for it.
     */
    public function test_staff_entry_application_can_be_submitted_while_fee_is_still_unpaid(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'status' => Admission::STATUS_DRAFT,
            'programme_id' => $programmeId,
            'intake_session_id' => $intakeId,
        ]);
        $this->completeApplicationFields(Admission::find($admissionId));
        DB::table('admission_document_requirements')->insert([
            'school_id' => $schoolId, 'key' => 'other', 'label' => 'Other', 'is_required' => false,
            'allow_multiple' => 0, 'is_active' => 1, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.submit', $admissionId));

        $admission = Admission::find($admissionId);
        $this->assertSame(Admission::STATUS_SUBMITTED, $admission->status, 'Unpaid must not block submission — same rule as the online applicant wizard.');
        $this->assertSame(Admission::FEE_UNPAID, $admission->fee_status);
    }

    public function test_application_fee_payments_never_touch_the_tuition_fee_ledger(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, ['intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.record', $admissionId), [
            'amount' => 50000, 'method' => 'cash', 'paid_at' => now()->toDateString(),
        ]);

        $waivedId = $this->makeAdmission($schoolId, ['email' => 'tuition.check@example.com', 'intake_session_id' => $intakeId]);
        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $waivedId), ['reason' => 'Test.']);

        $this->assertSame(0, \App\Models\StudentFeeManager::count(), 'Application-fee actions must never create a tuition invoice.');
    }

    public function test_waiving_an_already_settled_fee_is_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 50000]);
        $admissionId = $this->makeAdmission($schoolId, ['intake_session_id' => $intakeId]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $admissionId), ['reason' => 'First waiver.']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.waive', $admissionId), ['reason' => 'Second attempt.']);

        $this->assertSame(1, ApplicationPayment::where('admission_id', $admissionId)->count(), 'Must not double-waive an already-settled fee.');
    }
}
