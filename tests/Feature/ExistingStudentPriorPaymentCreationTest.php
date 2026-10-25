<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\PaymentMethods;
use App\Models\Programme;
use App\Models\Session;
use App\Models\StudentFeeManager;
use App\Models\StudentFeeAward;
use App\Models\StudentFeeRegistrationWaiver;
use App\Models\StudentFinancialSetupReview;
use App\Models\StudentOpeningPayment;
use App\Models\StudentProfile;
use App\Models\TuitionPayment;
use App\Models\User;
use App\Support\StudentFeeInvoiceGenerator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema, URL};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ExistingStudentPriorPaymentCreationTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;
    private int $academicYearId;
    private User $admin;
    private Session $period;
    private FeeStructure $structure;
    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_19_160000_preserve_school_fee_payment_precision.php'))->up();
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_22_000000_add_method_to_student_opening_payments.php'))->up();
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        (require database_path('migrations/2026_10_17_100000_create_student_fee_registration_waivers_table.php'))->up();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require database_path('migrations/2026_10_23_100000_create_existing_student_financial_setup_reviews_table.php'))->up();
        Schema::table('schools', function (Blueprint $table) {
            $table->string('school_currency')->default('UGX');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('label', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::table('sessions', fn (Blueprint $table) => $table->unsignedBigInteger('academic_year_id')->nullable());

        $this->schoolId = $this->makeSchool(['running_session' => 1]);
        $this->admin = $this->makeAdminUser($this->schoolId);
        $this->academicYearId = DB::table('academic_years')->insertGetId([
            'school_id' => $this->schoolId, 'label' => '2026/2027', 'start_date' => '2026-08-01', 'end_date' => '2027-07-31',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('schools')->where('id', $this->schoolId)->update(['current_academic_year_id' => $this->academicYearId]);
        $this->period = Session::create(['school_id' => $this->schoolId, 'session_title' => '2026 Semester 2', 'status' => 1]);
        DB::table('sessions')->where('id', $this->period->id)->update(['academic_year_id' => $this->academicYearId]);
        $this->programme = Programme::create(['school_id' => $this->schoolId, 'name' => 'Diploma in Finance', 'code' => 'DF', 'is_active' => 1]);
        $this->structure = FeeStructure::create(['school_id' => $this->schoolId, 'name' => 'Tuition', 'fee_type' => 'tuition',
            'amount' => 1500000, 'is_mandatory' => 1, 'per_semester' => 1, 'class_id' => null, 'programme_id' => $this->programme->id, 'session_id' => $this->period->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Continuing Student', 'email' => 'continuing@example.test', 'registration_number' => 'PIIE/CONT/026',
            'password_option' => 'auto', 'gender' => 'Female', 'blood_group' => '', 'birthday' => '01/01/2000',
            'phone' => '0700000000', 'address' => 'Kampala', 'record_fees' => '1',
            'programme_id' => $this->programme->id,
            'fee_academic_year_id' => $this->academicYearId,
            'fee_session_id' => $this->period->id, 'fee_structure_id' => $this->structure->id,
            'prior_paid_amount' => '200000.00',
            'prior_paid_on' => now()->subMonth()->toDateString(), 'prior_payment_method' => 'mobile_money',
            'prior_payment_reference' => 'MM-PRIOR-026', 'prior_payment_reason' => 'Verified against the original mobile money receipt.',
        ], $overrides);
    }

    public function test_add_existing_student_stages_financial_setup_without_applying_it_before_confirmation(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'actual_fee_amount' => '1.00',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $review = StudentFinancialSetupReview::where('fee_id', $fee->id)->sole();

        $this->assertTrue((bool) StudentProfile::where('user_id', $student->id)->value('was_existing_at_portal_creation'));
        $this->assertSame((int) $this->period->id, (int) $fee->session_id);
        $this->assertSame((int) $this->structure->id, (int) $fee->fee_structure_id);
        $this->assertSame('Tuition — '.$this->period->session_title, $fee->title, 'The invoice title identifies its Finance billing period.');
        $this->assertSame(1, (int) $this->structure->per_semester);
        $this->assertEquals(1500000, $fee->amount);
        $this->assertEquals(1500000, $fee->total_amount);
        $this->assertEquals(0, $fee->paid_amount);
        $this->assertEquals(0, $fee->opening_paid_amount);
        $this->assertEquals(1500000, $fee->total_amount - $fee->paid_amount);
        $this->assertSame('pending', $review->prior_payment_status);
        $this->assertSame('200000.00', $review->prior_payment['amount']);
        $this->assertSame('mobile_money', $review->prior_payment['method']);
        $this->assertSame('MM-PRIOR-026', $review->prior_payment['reference']);
        $this->assertSame('Verified against the original mobile money receipt.', $review->prior_payment['reason']);
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame(0, StudentOpeningPayment::count(), 'Captured historical cash remains pending until authorized confirmation.');
        $this->assertSame(0, TuitionPayment::count(), 'A verified old receipt is not a new gateway collection.');
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('application_payments')->count());
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
        $this->assertSame([], StudentFeeInvoiceGenerator::generateForStudent($student, (int) $this->programme->id, $this->schoolId), 'The linked fee structure must not be invoiced again by a later sync.');
        $this->assertSame(1, StudentFeeManager::where('student_id', $student->id)->where('fee_structure_id', $this->structure->id)->where('session_id', $this->period->id)->count());
    }

    public function test_confirmed_partial_payment_uses_the_programme_fee_and_online_checkout_uses_the_remaining_balance(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0',
            'received_payment_amount' => '400000.00',
            'received_payment_on' => now()->toDateString(),
            'received_payment_method' => 'bank_transfer',
            'received_payment_reference' => 'BANK-RECEIPT-026',
            'received_payment_reason' => 'Verified receipt for the amount received today.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $manualPayment = TuitionPayment::where('fee_id', $fee->id)->sole();
        $this->assertEquals(1500000, $fee->total_amount, 'Invoice amount comes from the selected Fee Structure, not a posted override.');
        $this->assertEquals(400000, $fee->paid_amount);
        $this->assertEquals(1100000, $fee->total_amount - $fee->paid_amount);
        $this->assertSame('unpaid', $fee->status);
        $this->assertSame('paid', $manualPayment->status);
        $this->assertSame('bank_transfer', $manualPayment->method);
        $this->assertEquals(400000, $manualPayment->amount);
        $this->assertSame('BANK-RECEIPT-026', $manualPayment->gateway_payload['receipt_reference']);
        $this->assertSame(0, StudentOpeningPayment::count(), 'A payment received now is not stored as historical opening money.');
        $this->assertDatabaseHas('audit_logs', ['record_type' => TuitionPayment::class, 'record_id' => $manualPayment->id]);

        PaymentMethods::create(['school_id' => $this->schoolId, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture',
                'notification_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3'])]);
        config(['app.url' => 'https://piie.example.test']);
        URL::forceRootUrl('https://piie.example.test');
        URL::forceScheme('https');
        $checkoutAmount = null;
        Http::fake(function ($request) use (&$checkoutAmount) {
            if (str_contains($request->url(), 'RequestToken')) {
                return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\\TH:i:s'), 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $checkoutAmount = (string) $request['amount'];
                return Http::response(['order_tracking_id' => '7e6b62d9-883e-440f-a63e-e1105bbfadc3', 'merchant_reference' => $request['id'],
                    'redirect_url' => 'https://cybqa.pesapal.com/pay', 'status' => '200', 'error' => null]);
            }
            return Http::response([], 500);
        });

        $onlinePayment = \App\Support\Payments\TuitionPesaPalPayment::start($fee->fresh());
        $this->assertSame('1100000.00', $checkoutAmount, 'Online balance checkout requests only the remaining invoice balance.');
        $this->assertEquals(1100000, $onlinePayment->amount);
        $this->assertSame('pending', $onlinePayment->status);
        $this->assertEquals(400000, $fee->fresh()->paid_amount, 'A pending provider order does not add a second credit.');
    }

    public function test_confirmed_full_payment_is_ledgered_and_leaves_no_online_balance(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0',
            'received_payment_amount' => '1500000.00',
            'received_payment_on' => now()->toDateString(),
            'received_payment_method' => 'cash',
            'received_payment_reference' => 'CASH-RECEIPT-FULL-026',
            'received_payment_reason' => 'Verified the full cash receipt at the Finance counter.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $this->assertEquals(1500000, $fee->total_amount);
        $this->assertEquals(1500000, $fee->paid_amount);
        $this->assertSame('paid', $fee->status);
        $this->assertSame('paid', TuitionPayment::where('fee_id', $fee->id)->sole()->status);
        $this->assertEquals(0, $fee->total_amount - $fee->paid_amount, 'A fully confirmed invoice has no balance to pay online.');
        $this->assertSame(1, TuitionPayment::where('fee_id', $fee->id)->where('status', 'paid')->count());
    }

    public function test_confirmed_payment_is_permission_gated_and_foreign_fee_structures_are_rejected(): void
    {
        $foreignSchool = $this->makeSchool();
        $foreignProgramme = Programme::create(['school_id' => $foreignSchool, 'name' => 'Foreign Programme', 'code' => 'FP', 'is_active' => 1]);
        $foreignStructure = FeeStructure::create(['school_id' => $foreignSchool, 'name' => 'Foreign Tuition', 'fee_type' => 'tuition',
            'amount' => 250000, 'is_mandatory' => 1, 'class_id' => null, 'programme_id' => $foreignProgramme->id, 'session_id' => null]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'fee_structure_id' => $foreignStructure->id,
        ]))->assertSessionHasErrors('fee_structure_id');
        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());

        $permissionService = \Mockery::mock(\App\Support\Permissions\PermissionService::class);
        $permissionService->shouldReceive('routePermission')->andReturn(null);
        $permissionService->shouldReceive('allows')->with($this->admin, 'finance.invoices')->andReturn(true);
        $permissionService->shouldReceive('allows')->with($this->admin, 'finance.payments')->andReturn(false);
        $permissionService->shouldReceive('allowsAny')->andReturn(true);
        $this->app->instance(\App\Support\Permissions\PermissionService::class, $permissionService);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0',
            'received_payment_amount' => '100000.00',
            'received_payment_on' => now()->toDateString(),
            'received_payment_method' => 'cash',
            'received_payment_reference' => 'NO-PAYMENT-PERMISSION',
            'received_payment_reason' => 'Payment permission should be required for this receipt.',
        ]))->assertForbidden();
        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertSame(0, TuitionPayment::count());
    }

    public function test_duplicate_payment_reference_and_duplicate_form_submission_do_not_create_duplicate_credits(): void
    {
        $receipt = [
            'prior_paid_amount' => '0', 'received_payment_amount' => '100000.00',
            'received_payment_on' => now()->toDateString(), 'received_payment_method' => 'cash',
            'received_payment_reference' => 'UNIQUE-CASH-RECEIPT',
            'received_payment_reason' => 'Verified cash receipt for the first invoice.',
        ];
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload($receipt))->assertSessionHasNoErrors();
        $firstStudent = User::where('email', 'continuing@example.test')->firstOrFail();
        $firstInvoice = StudentFeeManager::where('student_id', $firstStudent->id)->sole();

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload(array_merge($receipt, [
            'email' => 'continuing-second-attempt@example.test',
        ])))
            ->assertSessionHasErrors('registration_number');
        $this->assertSame(1, TuitionPayment::count());
        $this->assertSame(1, StudentFeeManager::count());

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload(array_merge($receipt, [
            'email' => 'second-student@example.test', 'registration_number' => 'PIIE/CONT/027',
        ])))->assertSessionHasErrors('received_payment_reference');
        $this->assertSame(1, User::where('role_id', 7)->where('school_id', $this->schoolId)->count());
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertSame(1, TuitionPayment::count());
        $this->assertEquals(100000, $firstInvoice->fresh()->paid_amount);
    }

    public function test_add_existing_student_form_is_one_page_and_finance_sessions_follow_the_selected_academic_year(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.student.open_modal'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Existing registration')
            ->assertSee('Student account and portal access')
            ->assertSee('Programme, intake, and academic details')
            ->assertSee('Optional finance setup')
            ->assertSee('Next of kin and other student details')
            ->assertSee('Create Student')
            ->assertSee('2026/2027')
            ->assertSee('2026 Semester 2')
            ->assertDontSee('Step 1')
            ->assertDontSee('Previous')
            ->assertDontSee('Next Step')
            ->assertDontSee('For a new student, complete an application')
            ->assertSee('fee_academic_year_id')
            ->assertSee('Programme fee')
            ->assertSee('Per semester')
            ->assertSee('Finance Session')
            ->assertSee('How billing works')
            ->assertSee('Academic Year supplies study context only.')
            ->assertSee('Recorded payments reduce the balance. Prior-payment claims, awards, and waivers need Finance approval.')
            ->assertSee('Confirmed amount received')
            ->assertDontSee('name="actual_fee_amount"', false)
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('name="additional_photo"', false)
            ->assertSee('data-academic-year-id="'.$this->academicYearId.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), '<form'));
        $this->assertStringContainsString('data-workspace-continuous', $response->getContent());
    }

    public function test_finance_year_default_uses_the_configured_active_year_not_the_running_session_year(): void
    {
        $otherYearId = DB::table('academic_years')->insertGetId([
            'school_id' => $this->schoolId, 'label' => '2031/2032', 'start_date' => '2031-08-01', 'end_date' => '2032-07-31',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $runningSession = Session::create(['school_id' => $this->schoolId, 'session_title' => '2031 Semester 1', 'status' => 1]);
        DB::table('sessions')->where('id', $runningSession->id)->update(['academic_year_id' => $otherYearId]);
        DB::table('sessions')->where('id', $this->period->id)->update(['academic_year_id' => $otherYearId]);
        DB::table('schools')->where('id', $this->schoolId)->update(['running_session' => $runningSession->id]);

        $response = $this->actingAs($this->admin)->get(route('admin.student.open_modal'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('2026/2027')
            ->assertSee('2031/2032')
            ->assertSee('data-academic-year-id="'.$otherYearId.'"', false);
        $this->assertSame(1, preg_match('/<option value="'.$this->academicYearId.'"\s+selected>2026\/2027<\/option>/', $response->getContent()));
    }

    public function test_missing_active_year_shows_selection_guidance_and_keeps_years_available(): void
    {
        DB::table('schools')->where('id', $this->schoolId)->update(['current_academic_year_id' => null]);

        $response = $this->actingAs($this->admin)->get(route('admin.student.open_modal'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('No active Academic Year is configured. Select a year before setting up Finance.')
            ->assertSee('Select an academic year')
            ->assertSee('2026/2027');
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$this->academicYearId.'"[^>]*selected>/', $response->getContent());
    }

    public function test_missing_finance_session_reports_accounting_setup_without_misidentifying_the_programme_fee(): void
    {
        $yearWithoutSessions = DB::table('academic_years')->insertGetId([
            'school_id' => $this->schoolId, 'label' => '2028/2029', 'start_date' => '2028-08-01', 'end_date' => '2029-07-31',
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $programmeFee = FeeStructure::create(['school_id' => $this->schoolId, 'name' => 'Programme Tuition', 'fee_type' => 'tuition',
            'amount' => 1500000, 'is_mandatory' => 1, 'per_semester' => 1, 'class_id' => null,
            'programme_id' => $this->programme->id, 'session_id' => null]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'fee_academic_year_id' => $yearWithoutSessions,
            'fee_session_id' => null,
            'fee_structure_id' => $programmeFee->id,
        ]))->assertSessionHasErrors('fee_session_id')->assertSessionDoesntHaveErrors('fee_structure_id');

        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());
    }

    public function test_admin_can_link_a_finance_session_to_the_academic_year_needed_for_invoice_creation(): void
    {
        $this->actingAs($this->admin)->get(route('admin.create.session'), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('academic_year_id')->assertSee('Academic Year for invoice context');

        $this->actingAs($this->admin)->post(route('admin.session_manager.create'), [
            'session_title' => '2028/29 Semester 1', 'academic_year_id' => $this->academicYearId,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $session = Session::where('school_id', $this->schoolId)->where('session_title', '2028/29 Semester 1')->sole();
        $this->assertSame($this->academicYearId, (int) $session->academic_year_id);
        $this->assertSame(1, Session::where('school_id', $this->schoolId)->where('academic_year_id', $this->academicYearId)->whereKey($session->id)->count());
    }

    public function test_semester_fee_uses_the_configured_amount_once_for_its_selected_finance_session(): void
    {
        $this->structure->update(['per_semester' => 1, 'amount' => 1500000]);
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0', 'received_payment_amount' => '0',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $invoice = StudentFeeManager::where('student_id', $student->id)->sole();
        $this->assertEquals(1500000, $invoice->amount, 'A per-semester Fee Structure uses its configured amount for the selected billing session, with no year-based calculation or multiplier.');
        $this->assertEquals(1500000, $invoice->total_amount);
        $this->assertSame('Tuition — '.$this->period->session_title, $invoice->title);
        $this->assertSame((int) $this->period->id, (int) $invoice->session_id);
    }

    public function test_finance_rejects_a_fee_session_outside_the_selected_academic_year(): void
    {
        $otherYearId = DB::table('academic_years')->insertGetId([
            'school_id' => $this->schoolId, 'label' => '2027/2028', 'start_date' => '2027-08-01', 'end_date' => '2028-07-31',
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherSession = Session::create(['school_id' => $this->schoolId, 'session_title' => '2027 Semester 1', 'status' => 1]);
        DB::table('sessions')->where('id', $otherSession->id)->update(['academic_year_id' => $otherYearId]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'fee_session_id' => $otherSession->id,
        ]))->assertSessionHasErrors('fee_session_id');

        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());
    }

    public function test_finance_remains_optional_and_context_selection_alone_creates_no_invoice(): void
    {
        $payload = $this->payload([
            'record_fees' => null, 'fee_session_id' => null, 'fee_structure_id' => null,
            'actual_fee_amount' => null, 'received_payment_amount' => null, 'prior_paid_amount' => null, 'prior_paid_on' => null,
            'prior_payment_method' => null, 'prior_payment_reference' => null, 'prior_payment_reason' => null,
            'fee_academic_year_id' => $this->academicYearId,
            'name' => null, 'first_name' => 'Amina', 'last_name' => 'Mukasa',
        ]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $this->assertSame('Amina Mukasa', $student->name);
        $this->assertSame('Amina', $student->first_name);
        $this->assertSame('Mukasa', $student->last_name);
        $this->assertSame('Amina', StudentProfile::where('user_id', $student->id)->value('first_name'));
        $this->assertSame('Mukasa', StudentProfile::where('user_id', $student->id)->value('last_name'));
        $this->assertSame(0, StudentFeeManager::where('student_id', $student->id)->count());
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('application_payments')->count());
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
        $this->assertSame(0, DB::table('applicants')->count());
    }

    public function test_finance_permissions_still_gate_optional_setup_and_validation_is_shown_inline(): void
    {
        $permissionService = \Mockery::mock(\App\Support\Permissions\PermissionService::class);
        $permissionService->shouldReceive('routePermission')->andReturn(null);
        $permissionService->shouldReceive('allows')->andReturn(false);
        $permissionService->shouldReceive('allowsAny')->andReturn(true);
        $this->app->instance(\App\Support\Permissions\PermissionService::class, $permissionService);

        $this->actingAs($this->admin)->get(route('admin.student.open_modal'))
            ->assertOk()->assertDontSee('Optional financial setup');
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload())->assertForbidden();

        $this->app->instance(\App\Support\Permissions\PermissionService::class, new \App\Support\Permissions\PermissionService());

        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'email' => 'not-an-email',
        ]))->assertSessionHasErrors('email');

        $this->get(route('admin.student.open_modal'))
            ->assertOk()->assertSee('valid email address')
            ->assertSee('name="email"', false)->assertSee('is-invalid', false);
        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
    }

    public function test_invalid_prior_payment_rolls_back_student_invoice_and_ledger_together(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '1600000.00',
        ]))->assertSessionHasErrors('prior_paid_amount');

        $this->assertSame(0, User::where('email', 'continuing@example.test')->count());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_creation_with_zero_prior_payment_does_not_create_a_fake_cash_record(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload([
            'prior_paid_amount' => '0', 'prior_paid_on' => '', 'prior_payment_method' => '',
            'prior_payment_reference' => '', 'prior_payment_reason' => '',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $this->assertEquals(1500000, $fee->total_amount);
        $this->assertEquals(0, $fee->paid_amount);
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_financial_setup_is_reviewed_once_then_existing_ledgers_apply_each_item_separately(): void
    {
        $payload = $this->payload([
            'prior_paid_amount' => '200000.00',
            'record_award' => '1', 'award_type' => 'scholarship', 'award_amount' => '100000.00',
            'award_reference' => 'SCH-026', 'award_reason' => 'Approved scholarship for the current study period.',
            'record_study_waiver' => '1', 'study_waiver_reason' => 'Approved exceptional study payment requirement support.',
        ]);

        $this->actingAs($this->admin)->post(route('admin.student.create'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();
        $review = StudentFinancialSetupReview::where('fee_id', $fee->id)->sole();
        $this->assertEquals(1500000, $fee->total_amount, 'The configured programme invoice remains intact before review.');
        $this->assertEquals(1200000, app(\App\Support\Billing\ExistingStudentFinancialSetup::class)->projectedBalance($fee, $review) / 100);
        $this->assertEquals(1500000, $fee->total_amount - $fee->paid_amount, 'Unverified claims do not reduce the payable invoice balance.');
        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $fee->id))->assertOk()
            ->assertSee('Financial setup review')->assertSee('Projected balance')
            ->assertSee('SCH-026')->assertSee('MM-PRIOR-026');
        $this->assertSame(0, StudentOpeningPayment::count());
        $this->assertSame(0, StudentFeeAward::count());
        $this->assertSame(0, StudentFeeRegistrationWaiver::count());

        $confirm = fn (string $item) => $this->actingAs($this->admin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, $item]));
        $confirm('prior_payment')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(200000, $fee->fresh()->paid_amount);
        $this->assertEquals(1500000, $fee->fresh()->total_amount);
        $this->assertEquals(0, TuitionPayment::count());
        $confirm('prior_payment')->assertSessionHasErrors('financial_setup');
        $this->assertSame(1, StudentOpeningPayment::count());

        $confirm('award')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, StudentFeeAward::count());
        $this->assertEquals(1400000, $fee->fresh()->total_amount);
        $this->assertEquals(200000, $fee->fresh()->paid_amount);
        $this->assertSame(0, TuitionPayment::count());

        $confirm('study_waiver')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, StudentFeeRegistrationWaiver::count());
        $this->assertEquals(1400000, $fee->fresh()->total_amount);
        $this->assertEquals(200000, $fee->fresh()->paid_amount, 'The study waiver never changes fee accounting.');
    }

    public function test_financial_setup_confirmation_checks_permission_school_and_session(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student.create'), $this->payload())->assertRedirect();
        $student = User::where('email', 'continuing@example.test')->firstOrFail();
        $fee = StudentFeeManager::where('student_id', $student->id)->sole();

        $viewer = User::create(['school_id' => $this->schoolId, 'role_id' => 3, 'name' => 'Viewer', 'email' => 'viewer@example.test', 'account_status' => 'active']);
        DB::table('user_permissions')->insert(['school_id' => $this->schoolId, 'user_id' => $viewer->id,
            'permission' => 'finance.view', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertForbidden();
        $this->assertSame(0, StudentOpeningPayment::count());

        $otherSchoolAdmin = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($otherSchoolAdmin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());

        DB::table('student_financial_setup_reviews')->where('fee_id', $fee->id)->update(['session_id' => $fee->session_id + 1]);
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.setup.confirm', [$fee->id, 'prior_payment']))->assertNotFound();
        $this->assertSame(0, StudentOpeningPayment::count());
    }

    public function test_existing_legacy_invoice_without_setup_packet_keeps_existing_payment_and_award_workflows(): void
    {
        $student = User::create(['school_id' => $this->schoolId, 'role_id' => 7, 'name' => 'Legacy Student', 'email' => 'legacy@example.test']);
        $profile = StudentProfile::create(['school_id' => $this->schoolId, 'user_id' => $student->id]);
        DB::table('student_profiles')->where('id', $profile->id)->update([
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]);
        $fee = StudentFeeManager::create(['school_id' => $this->schoolId, 'student_id' => $student->id, 'title' => 'Legacy tuition',
            'amount' => 1200000, 'total_amount' => 1200000, 'paid_amount' => 0, 'class_id' => 0, 'session_id' => $this->period->id,
            'payment_method' => '', 'status' => 'unpaid', 'timestamp' => time()]);

        $this->actingAs($this->admin)->get(route('admin.tuition_payments.show', $fee->id))->assertOk()
            ->assertSee('Record prior payment already received')->assertSee('Record fee award');
        $this->actingAs($this->admin)->post(route('admin.tuition_payments.opening_payment', $fee->id), [
            'action' => 'record', 'amount' => '100000.00', 'paid_on' => now()->subDay()->toDateString(),
            'method' => 'cash', 'reference' => 'LEGACY-CASH-1', 'reason' => 'Verified historic cash receipt for legacy student.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(100000, $fee->fresh()->paid_amount);
        $this->assertSame(0, StudentFinancialSetupReview::where('fee_id', $fee->id)->count());
    }
}
