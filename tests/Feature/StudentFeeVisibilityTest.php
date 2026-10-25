<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, Session, StudentFeeManager, StudentFinancialSetupReview, StudentProfile, TuitionPayment, User};
use App\Support\Billing\{ExistingStudentFinancialSetup, StudentFeeAwards};
use App\Support\Payments\{StudentOpeningPayments, TuitionPesaPalPayment};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Mail, Schema};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentFeeVisibilityTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;
    private User $admin;
    private int $session;
    private int $otherSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        foreach (['2026_10_19_160000_preserve_school_fee_payment_precision.php',
            '2026_10_21_060000_create_student_opening_payments.php', '2026_10_22_000000_add_method_to_student_opening_payments.php',
            '2026_10_21_050000_create_student_fee_awards_table.php', '2026_10_23_100000_create_existing_student_financial_setup_reviews_table.php'] as $migration) {
            (require database_path('migrations/' . $migration))->up();
        }
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_currency')->default('UGX'));
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
        $this->session = Session::create(['school_id' => $this->school, 'session_title' => '2026/2027', 'status' => 1])->id;
        $this->otherSession = Session::create(['school_id' => $this->school, 'session_title' => '2025/2026', 'status' => 0])->id;
        DB::table('schools')->where('id', $this->school)->update(['running_session' => $this->session]);
        Mail::fake();
        Http::preventStrayRequests();
        $this->actingAs($this->admin);
    }

    private function student(bool $existing = false, string $suffix = 'one'): User
    {
        if ($existing) return \App\Support\Students\StudentProvisioner::provision(['name' => 'Existing ' . $suffix,
            'email' => $suffix . '@existing.test', 'registration_number' => 'LEGACY/' . $suffix, 'password_option' => 'auto'], $this->school, false)['user'];
        $student = User::create(['name' => 'New ' . $suffix, 'email' => $suffix . '@new.test', 'code' => 'NEW/' . $suffix,
            'school_id' => $this->school, 'role_id' => 7, 'account_status' => 'active']);
        StudentProfile::create(['school_id' => $this->school, 'user_id' => $student->id, 'was_existing_at_portal_creation' => false]);
        return $student;
    }

    private function invoice(User $student, array $extra = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_replace(['school_id' => $this->school, 'student_id' => $student->id,
            'session_id' => $this->session, 'title' => 'Session tuition', 'amount' => 1000, 'total_amount' => 1000,
            'paid_amount' => 0, 'class_id' => 0, 'payment_method' => '', 'status' => 'unpaid', 'timestamp' => time()], $extra));
    }

    private function summary(User $student, array $query = []): User
    {
        return $this->get(route('admin.fee_manager.list', $query))->assertOk()->viewData('feeStudents')->firstWhere('id', $student->id);
    }

    public function test_nine_active_students_remain_findable_when_only_three_invoices_match_october_report(): void
    {
        $students = collect();
        for ($i = 0; $i < 9; $i++) $students->push($this->student($i % 2 === 0, 'student-' . $i));
        foreach ($students->take(3) as $student) $this->invoice($student, ['timestamp' => strtotime('2026-10-12')]);
        $this->invoice($students[3], ['timestamp' => strtotime('2026-09-30')]);
        $this->invoice($students[4], ['session_id' => $this->otherSession, 'timestamp' => strtotime('2026-10-12')]);
        $query = ['eDateRange' => '10/01/2026 - 10/31/2026', 'status' => 'all', 'class' => 'all'];
        $response = $this->get(route('admin.fee_manager.list', $query))->assertOk()
            ->assertViewHas('invoices', fn ($rows) => $rows->count() === 3)
            ->assertViewHas('feeStudents', fn ($rows) => $rows->total() === 9)
            ->assertSee('Needs invoice for this session')->assertSee('Verified prior payments');
        foreach ($students as $student) $response->assertSee($student->email);
        $this->assertSame(0, DB::table('enrollment')->count(), 'Placement must not be required for financial discovery.');
        $before = [User::count(), StudentFeeManager::count(), DB::table('audit_logs')->count()];
        $this->get(route('admin.fee_manager.list', $query))->assertOk();
        $this->assertSame($before, [User::count(), StudentFeeManager::count(), DB::table('audit_logs')->count()]);
    }

    public function test_partial_prior_cash_award_and_later_verified_payment_remain_separate_when_fully_settled(): void
    {
        $student = $this->student(true);
        $fee = $this->invoice($student);
        app(StudentOpeningPayments::class)->change($this->admin, $fee->id, ['action' => 'record', 'amount' => '300.00',
            'paid_on' => now()->subMonth()->toDateString(), 'method' => 'cash', 'reference' => 'CASH-BEFORE-PIIE',
            'reason' => 'Original pre-PIIE cash receipt verified.']);
        app(StudentFeeAwards::class)->change($this->admin, $fee->id, ['action' => 'grant', 'type' => 'bursary',
            'amount' => '200.00', 'reference' => 'AWARD-BOARD', 'reason' => 'Approved bursary from board records.']);
        $this->assertSame(['base' => 100000, 'discount' => 0, 'fee' => 80000, 'prior' => 30000, 'new' => 0, 'awards' => 20000, 'balance' => 50000],
            $this->summary($student)->fee_summary);
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture',
                'notification_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3'])]);
        config(['app.url' => 'https://piie.example.test']);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://piie.example.test');
        \Illuminate\Support\Facades\URL::forceScheme('https');
        $reference = '';
        Http::fake(function ($request) use (&$reference) {
            if (str_contains($request->url(), 'RequestToken')) return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]);
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $reference = $request['id'];
                return Http::response(['order_tracking_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3', 'merchant_reference' => $reference,
                    'redirect_url' => 'https://cybqa.pesapal.com/pay', 'status' => '200', 'error' => null]);
            }
            return Http::response(['merchant_reference' => $reference, 'amount' => '500.00', 'currency' => 'UGX', 'status_code' => 1,
                'payment_status_description' => 'COMPLETED', 'payment_method' => 'Mobile Money', 'confirmation_code' => 'VERIFIED-500', 'status' => '200', 'error' => null]);
        });
        $payment = TuitionPesaPalPayment::start($fee->fresh());
        $pending = $this->summary($student);
        $this->assertSame(50000, $pending->fee_summary['balance']);
        $this->assertNotEmpty($pending->sessionInvoices->first()->overview['pending']);
        TuitionPesaPalPayment::reconcile($payment);
        TuitionPesaPalPayment::reconcile($payment);
        $settled = $this->summary($student);
        $this->assertSame(30000, $settled->fee_summary['prior']);
        $this->assertSame(50000, $settled->fee_summary['new']);
        $this->assertSame(20000, $settled->fee_summary['awards']);
        $this->assertSame(0, $settled->fee_summary['balance']);
        $this->get(route('admin.fee_manager.list'))->assertOk()->assertSee('Fully settled');
        $this->assertSame(1, TuitionPayment::count());
        $this->assertSame(1, StudentFeeManager::count());
    }

    public function test_pending_setup_is_visible_but_does_not_reduce_the_balance_until_confirmed(): void
    {
        $student = $this->student(true);
        $fee = $this->invoice($student);
        StudentFinancialSetupReview::create(['school_id' => $this->school, 'session_id' => $this->session, 'student_id' => $student->id,
            'fee_id' => $fee->id, 'captured_by' => $this->admin->id, 'prior_payment_status' => 'pending',
            'prior_payment' => ['amount' => '250.00', 'paid_on' => now()->subMonth()->toDateString(), 'method' => 'cash',
                'reference' => 'PENDING-CASH', 'reason' => 'Original pre-PIIE cash receipt captured for verification.'],
            'award_status' => 'pending', 'award' => ['type' => 'scholarship', 'amount' => '100.00', 'reference' => 'PENDING-AWARD', 'reason' => 'Approved award captured for financial review.']]);
        $before = $this->summary($student);
        $this->assertSame(100000, $before->fee_summary['balance']);
        $this->assertSame(0, $before->fee_summary['prior']);
        $this->assertCount(2, $before->sessionInvoices->first()->overview['pending']);
        app(ExistingStudentFinancialSetup::class)->confirm($this->admin, $fee->id, 'prior_payment');
        $after = $this->summary($student);
        $this->assertSame(25000, $after->fee_summary['prior']);
        $this->assertSame(75000, $after->fee_summary['balance']);
        $this->assertCount(1, $after->sessionInvoices->first()->overview['pending']);
    }

    public function test_session_filters_status_and_dates_never_hide_students_or_mix_their_invoice_links(): void
    {
        $student = $this->student();
        $current = $this->invoice($student, ['title' => 'Current tuition', 'paid_amount' => 1000, 'status' => 'paid', 'timestamp' => strtotime('2026-10-10')]);
        $past = $this->invoice($student, ['title' => 'Past tuition', 'amount' => 200, 'total_amount' => 200, 'session_id' => $this->otherSession, 'timestamp' => strtotime('2026-10-10')]);
        $foreignSchool = $this->makeSchool();
        $foreign = User::factory()->create(['school_id' => $foreignSchool, 'role_id' => 7]);
        $foreignSession = Session::create(['school_id' => $foreignSchool, 'session_title' => 'Private session'])->id;
        $this->invoice($student, ['school_id' => $foreignSchool, 'title' => 'Foreign invoice']);
        $this->invoice($foreign, ['title' => 'Invalid cross-school link']);
        $this->get(route('admin.fee_manager.list', ['fee_session_id' => $this->session, 'eDateRange' => '10/01/2026 - 10/31/2026', 'status' => 'unpaid']))
            ->assertOk()->assertViewHas('invoices', fn ($rows) => $rows->isEmpty())->assertSee($student->email)
            ->assertSee(route('admin.tuition_payments.show', $current->id))->assertDontSee(route('admin.tuition_payments.show', $past->id))
            ->assertDontSee('Invalid cross-school link')->assertDontSee('Foreign invoice')->assertDontSee($foreign->email);
        $this->assertSame(20000, $this->summary($student, ['fee_session_id' => $this->otherSession])->fee_summary['balance']);
        $this->get(route('admin.fee_manager.export', ['date_from' => strtotime('2026-10-01'), 'date_to' => strtotime('2026-10-31 23:59:59'),
            'selected_class' => 'all', 'selected_status' => 'all', 'fee_session_id' => $this->otherSession]))
            ->assertOk()->assertSee('Past tuition')->assertDontSee('Current tuition');
        $this->getJson(route('admin.fee_manager.list', ['fee_session_id' => $foreignSession]))->assertUnprocessable();
        $this->getJson(route('admin.fee_manager.list', ['eDateRange' => 'bad date']))->assertUnprocessable();
    }

    public function test_no_invoice_and_zero_fee_students_are_distinct_and_paginated_without_duplicates(): void
    {
        $new = $this->student(false, 'zero');
        $this->invoice($new, ['amount' => 0, 'total_amount' => 0, 'status' => 'paid']);
        $existing = $this->student(true, 'uninvoiced');
        for ($i = 0; $i < 27; $i++) $this->student(false, sprintf('page-%02d', $i));
        $disabled = $this->student(false, 'disabled'); $disabled->update(['account_status' => 'disable']);
        $page1 = $this->get(route('admin.fee_manager.list', ['fee_session_id' => $this->session]))->assertOk()->viewData('feeStudents');
        $page2 = $this->get(route('admin.fee_manager.list', ['fee_session_id' => $this->session, 'students_page' => 2]))->assertOk()->viewData('feeStudents');
        $this->assertSame(29, $page1->total());
        $this->assertCount(25, $page1); $this->assertCount(4, $page2);
        $this->assertCount(29, $page1->pluck('id')->merge($page2->pluck('id'))->unique());
        $this->assertStringContainsString('fee_session_id=' . $this->session, $page1->nextPageUrl());
        $this->assertSame(0, $this->summary($new, ['fee_search' => $new->code])->fee_summary['balance']);
        $this->get(route('admin.fee_manager.list', ['fee_search' => $existing->code]))->assertOk()
            ->assertViewHas('feeStudents', fn ($rows) => $rows->count() === 1 && $rows->first()->sessionInvoices->isEmpty())
            ->assertSee('Needs invoice for this session')->assertDontSee('Fully settled')
            ->assertSee(route('admin.student.invoice_setup', ['id' => $existing->id]));
        $this->assertSame(0, StudentFeeManager::where('student_id', $existing->id)->count(), 'Viewing Fee Manager never creates an invoice.');
        $accountant = User::create(['school_id' => $this->school, 'role_id' => 4, 'name' => 'Finance', 'email' => 'finance@test.test']);
        $this->actingAs($accountant)->get(route('accountant.fee_manager.list', ['fee_search' => $new->code, 'fee_session_id' => $this->session]))
            ->assertOk()->assertSee('Fully settled')->assertSee(route('accountant.tuition_payments.show', StudentFeeManager::first()->id));
        $this->get(route('accountant.fee_manager.list', ['fee_search' => $existing->code, 'fee_session_id' => $this->session]))
            ->assertOk()->assertSee(route('accountant.student.invoice_setup', ['id' => $existing->id]));
    }

    public function test_multiple_invoices_are_not_multiplied_by_payment_or_award_history(): void
    {
        $student = $this->student(true);
        $first = $this->invoice($student);
        $second = $this->invoice($student, ['title' => 'Other fee', 'amount' => 200, 'total_amount' => 200]);
        foreach (['100.00', '50.00'] as $index => $amount) {
            app(StudentOpeningPayments::class)->change($this->admin, $first->id, ['action' => 'record', 'amount' => $amount,
                'paid_on' => now()->subMonth()->toDateString(), 'method' => 'cash', 'reference' => 'CASH-' . $index,
                'reason' => 'Original pre-PIIE cash receipt independently verified.']);
        }
        $overview = $this->summary($student);
        $this->assertCount(2, $overview->sessionInvoices);
        $this->assertSame(120000, $overview->fee_summary['fee']);
        $this->assertSame(15000, $overview->fee_summary['prior']);
        $this->assertSame(105000, $overview->fee_summary['balance']);
        $this->assertSame([$first->id, $second->id], $overview->sessionInvoices->pluck('id')->all());
    }
}
