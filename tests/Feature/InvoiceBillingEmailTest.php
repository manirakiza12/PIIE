<?php

namespace Tests\Feature;

use App\Mail\InvoiceEmail as InvoiceMessage;
use App\Models\StudentFeeManager;
use App\Models\User;
use App\Support\Billing\InvoiceBilling;
use App\Support\Billing\InvoiceEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class InvoiceBillingEmailTest extends TestCase
{
    use AdmissionsTestHelper;
    private int $school;
    private User $student;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_14_110000_create_invoice_email_tables.php'))->up();
        $this->school = $this->makeSchool(['title' => 'Prime International Institute of Excellence']);
        $this->admin = $this->makeAdminUser($this->school);
        $this->student = User::create(['name' => 'Example Student', 'email' => 'billing@example.test', 'role_id' => 7,
            'school_id' => $this->school, 'code' => '2026/05/MPAM/001/', 'account_status' => 'active',
            'user_information' => json_encode(['address' => 'Kampala Example Road', 'phone' => '+256700000001'])]);
        $this->enableSmtpSettings();
        Mail::fake();
    }

    private function invoice(array $extra = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_replace(['title' => 'Semester tuition', 'total_amount' => 500000,
            'amount' => 500000, 'paid_amount' => 100000, 'discounted_price' => 0, 'class_id' => 0,
            'student_id' => $this->student->id, 'school_id' => $this->school, 'status' => 'unpaid', 'payment_method' => 'unpaid', 'timestamp' => time()], $extra));
    }

    public function test_printed_invoice_pulls_full_billing_details_for_every_portal(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->admin);
        foreach (['admin.student_fee_manager.invoice', 'accountant.student_fee_manager.invoice', 'student.fee_manager.invoice', 'parent.fee_manager.invoice'] as $view) {
            $html = view($view, ['invoice_details' => $invoice->toArray(), 'student_details' => ['name' => $this->student->name],])->renderSections()['content'];
            foreach (['Example Student', 'billing@example.test', 'Kampala Example Road', '+256700000001', '2026/05/MPAM/001/'] as $value) $this->assertStringContainsString($value, $html);
        }
    }

    public function test_billing_falls_back_to_enrolled_application_but_ignores_foreign_school(): void
    {
        $this->student->update(['user_information' => json_encode(['address' => '', 'phone' => ''])]);
        $this->makeAdmission($this->school, ['email' => $this->student->email, 'status' => 'enrolled', 'physical_address' => 'Application Road', 'phone' => '+256700000002']);
        $this->makeAdmission($this->makeSchool(), ['email' => $this->student->email, 'status' => 'enrolled', 'physical_address' => 'Foreign Road', 'phone' => 'WRONG']);
        $billing = InvoiceBilling::details($this->invoice()->toArray());
        $this->assertStringContainsString('Application Road', $billing['address']);
        $this->assertSame('+256700000002', $billing['phone']);
    }

    public function test_automatic_email_is_opt_in_and_sends_only_after_commit(): void
    {
        $this->invoice();
        Mail::assertNothingSent();
        DB::table('invoice_email_preferences')->insert(['school_id' => $this->school, 'automatic' => true]);
        DB::beginTransaction();
        $invoice = $this->invoice();
        Mail::assertNothingSent();
        DB::commit();
        Mail::assertSent(InvoiceMessage::class, function ($mail) use ($invoice) {
            $html = $mail->render();
            $this->assertStringContainsString('billing@example.test', $html);
            $this->assertStringContainsString('400,000.00', $html);
            $this->assertStringContainsString(route('student.studentFeeinvoice', $invoice->id), $html);
            return $mail->hasTo($this->student->email);
        });
        $this->assertSame('sent', DB::table('invoice_email_deliveries')->where('invoice_id', $invoice->id)->value('status'));
        InvoiceEmail::request($invoice);
        Mail::assertSentCount(1);
    }

    public function test_rollback_sends_nothing_and_leaves_no_delivery(): void
    {
        DB::table('invoice_email_preferences')->insert(['school_id' => $this->school, 'automatic' => true]);
        DB::beginTransaction();
        $this->invoice();
        DB::rollBack();
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('invoice_email_deliveries')->count());
    }

    public function test_admin_can_email_existing_invoice_and_another_school_cannot(): void
    {
        $invoice = $this->invoice();
        $this->actingAs($this->admin)->post(route('admin.invoice_email.send', $invoice->id))->assertRedirect()->assertSessionHas('message');
        Mail::assertSentCount(1);
        $foreign = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($foreign)->post(route('admin.invoice_email.send', $invoice->id))->assertNotFound();
        Mail::assertSentCount(1);
    }

    public function test_preferences_are_scoped_and_apply_only_to_future_invoices(): void
    {
        $this->invoice();
        $this->actingAs($this->admin)->post(route('admin.invoice_email.preferences'), ['automatic' => 1])->assertRedirect();
        $this->assertTrue(InvoiceEmail::automatic($this->school));
        $this->assertFalse(InvoiceEmail::automatic($this->makeSchool()));
        Mail::assertNothingSent();
        $this->invoice();
        Mail::assertSentCount(1);
    }

    public function test_missing_smtp_is_visible_and_failed_email_is_retried(): void
    {
        DB::table('global_settings')->where('key', 'smtp_host')->update(['value' => '']);
        $invoice = $this->invoice();
        $this->actingAs($this->admin)->post(route('admin.invoice_email.send', $invoice->id))->assertRedirect()->assertSessionHas('error');
        Mail::assertNothingSent();
        $this->assertSame('failed', DB::table('invoice_email_deliveries')->value('status'));
        $this->enableSmtpSettings();
        DB::table('invoice_email_deliveries')->update(['available_at' => now()->subMinute()]);
        $this->artisan('invoices:retry-emails')->assertSuccessful();
        Mail::assertSentCount(1);
        $this->artisan('invoices:retry-emails')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_cross_school_student_invoice_is_never_emailed(): void
    {
        $invoice = $this->invoice(['school_id' => $this->makeSchool()]);
        $this->assertFalse(InvoiceEmail::deliver(InvoiceEmail::record($invoice)));
        Mail::assertNothingSent();
    }

    public function test_creation_checkbox_emails_invoice_without_enabling_school_automation(): void
    {
        $class = $this->makeClass($this->school);
        $this->actingAs($this->admin)->post(route('admin.create.fee_manager', 'single'), [
            'title' => 'Created invoice', 'amount' => 500000, 'paid_amount' => 100000, 'discounted_price' => 0,
            'class_id' => $class, 'student_id' => $this->student->id, 'status' => 'unpaid', 'payment_method' => 'unpaid', 'email_invoice' => 1,
        ])->assertRedirect()->assertSessionHas('message');
        Mail::assertSentCount(1);
        $this->assertFalse(InvoiceEmail::automatic($this->school));
    }

    public function test_accountant_and_bursar_can_send_but_student_cannot(): void
    {
        $invoice = $this->invoice();
        $accountant = User::create(['name' => 'Accountant', 'email' => 'accountant@example.test', 'role_id' => 4, 'school_id' => $this->school, 'account_status' => 'active']);
        foreach (['accountant', 'bursar'] as $portal) {
            $this->actingAs($accountant)->post(route($portal.'.invoice_email.send', $invoice->id))->assertRedirect()->assertSessionHas('message');
        }
        Mail::assertSentCount(2);
        $this->actingAs($this->student)->post(route('admin.invoice_email.send', $invoice->id))->assertRedirect();
        Mail::assertSentCount(2);
    }

    public function test_transport_failure_keeps_invoice_and_retry_is_bounded(): void
    {
        $invoice = $this->invoice();
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('Test transport failure'));
        $id = InvoiceEmail::record($invoice);
        $this->assertFalse(InvoiceEmail::deliver($id));
        $this->assertNotNull($invoice->fresh());
        $this->assertSame('failed', DB::table('invoice_email_deliveries')->where('id', $id)->value('status'));
        DB::table('invoice_email_deliveries')->where('id', $id)->update(['attempts' => 5, 'available_at' => now()->subMinute()]);
        $this->assertSame(0, InvoiceEmail::retry());
        $this->assertSame(5, DB::table('invoice_email_deliveries')->where('id', $id)->value('attempts'));
    }
}
