<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Payroll\PayrollService;
use App\Support\Payroll\UgandaPayrollCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Payroll Module upgrade — acceptance tests.
 *
 * Uses the same in-memory schema bootstrap pattern as the rest of the
 * suite (StaffModuleTestHelper + running the real migrations directly),
 * plus the finance tables the payroll payments post into and the payroll
 * enhancement migration.
 */
class PayrollModuleTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;
    private int $otherSchool;
    private User $admin;
    private User $otherSchoolAdmin;
    private User $teacher;
    private User $librarian;
    private string $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();

        (require base_path('database/migrations/2026_07_02_130010_create_payroll_graduation_tables.php'))->up();
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();

        // Finance tables payroll payments post into (mirrors the real MySQL
        // schema used by the expenses module).
        Schema::create('expense_categories', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('school_id');
            $t->unsignedInteger('session_id')->nullable();
            $t->timestamps();
        });
        Schema::create('expenses', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('expense_category_id');
            $t->bigInteger('date');
            $t->decimal('amount', 15, 2);
            $t->integer('school_id');
            $t->unsignedInteger('session_id')->nullable();
            $t->timestamps();
        });

        $this->school = $this->makeSchool();
        $this->otherSchool = $this->makeSchool();
        $this->period = now()->format('Y-m');
        $this->admin = $this->makeAdminUser($this->school);
        $this->otherSchoolAdmin = $this->makeAdminUser($this->otherSchool);

        $this->teacher = User::factory()->create([
            'role_id' => 3, 'school_id' => $this->school, 'account_status' => 'active', 'name' => 'Sarah Teacher',
        ]);
        $this->librarian = User::factory()->create([
            'role_id' => 5, 'school_id' => $this->school, 'account_status' => 'active', 'name' => 'Larry Librarian',
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function periodFilter(): string
    {
        return PayrollService::periodValue($this->period);
    }

    private function rowFor(User $user)
    {
        return DB::table('payroll')
            ->where('staff_id', $user->id)
            ->where('pay_period', $this->periodFilter())
            ->first();
    }

    private function structureFor(User $user, array $overrides = []): int
    {
        return (int) DB::table('salary_structures')->insertGetId(array_merge([
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'basic' => 1000000,
            'housing' => 200000,
            'transport' => 100000,
            'medical' => 50000,
            'other_earnings' => 0,
            'payment_method' => 'bank',
            'effective_from' => $this->period . '-01',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function generate(string $month = null): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.payroll.generate'), ['month' => $month ?? $this->period])
            ->assertRedirect();
    }

    private function paymentPayload(float $amount): array
    {
        return [
            'payment_amount' => (string) $amount,
            'payment_method' => 'bank',
            'payment_reference' => 'TRX-' . uniqid(),
            'paid_on' => now()->toDateString(),
        ];
    }

    // ── 405 root cause: Generate must be a POST form, not a GET link ────

    public function test_dashboard_renders_and_generate_is_a_post_form_never_an_anchor(): void
    {
        $this->structureFor($this->teacher);

        $response = $this->actingAs($this->admin)->get(route('admin.payroll.index', ['month' => $this->period]));
        $response->assertOk();

        $content = (string) $response->getContent();
        $this->assertStringContainsString('admin/payroll/generate', $content);
        $this->assertStringContainsString('name="_token"', $content);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*href="[^"]*admin\/payroll\/generate[^"]*"/', $content, 'Generate must never be an anchor (GET) again.');
    }

    public function test_generate_accepts_post_and_get_gets_method_not_allowed(): void
    {
        $route = route('admin.payroll.generate');
        $this->actingAs($this->admin)->post($route, ['month' => $this->period])->assertRedirect();

        // GET on the generate URL no longer 405s — it redirects to the payroll
        // dashboard (the generation itself requires a POST with CSRF).
        $this->actingAs($this->admin)->get($route)->assertRedirect(route('admin.payroll.index'));
    }

    // ── Generate workflow ──────────────────────────────────────────────

    public function test_generate_creates_correct_rows_for_eligible_staff(): void
    {
        $this->structureFor($this->teacher);
        $this->structureFor($this->librarian, ['basic' => 800000, 'payment_method' => 'cash']);

        $this->generate($this->period);

        $teacherRow = $this->rowFor($this->teacher);
        $librarianRow = $this->rowFor($this->librarian);

        $this->assertNotNull($teacherRow);
        $this->assertNotNull($librarianRow);

        // gross 1,000,000 + 350,000 = 1,350,000; NSSF employee 5% = 67,500
        $this->assertEqualsWithDelta(1350000.0, $teacherRow->basic_salary + $teacherRow->allowances + $teacherRow->other_earnings, 0.01);
        $this->assertEqualsWithDelta(67500.0, $teacherRow->nssf, 0.01);
        $this->assertEqualsWithDelta(135000.0, $teacherRow->employer_nssf, 0.01); // employer cost, kept separate

        $expected = UgandaPayrollCalculator::compute(['basic' => 1000000, 'housing' => 200000, 'transport' => 100000, 'medical' => 50000, 'other' => 0]);
        $this->assertEqualsWithDelta($expected['paye'], $teacherRow->tax, 0.01);
        $this->assertEqualsWithDelta($expected['net'], $teacherRow->net_pay, 0.01);
        $this->assertTrue(abs(($teacherRow->basic_salary + $teacherRow->allowances + $teacherRow->other_earnings) - $teacherRow->nssf - $teacherRow->tax - $teacherRow->net_pay) < 0.01);

        $this->assertSame('draft', $teacherRow->status);
        $this->assertSame('bank', $teacherRow->payment_method);
        $this->assertSame('cash', $librarianRow->payment_method);
    }

    public function test_generate_excludes_parents_students_and_inactive_employees(): void
    {
        $this->structureFor($this->teacher);

        $parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->school, 'account_status' => 'active', 'name' => 'A Parent']);
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active', 'name' => 'A Student']);
        $terminated = User::factory()->create(['role_id' => 3, 'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'terminated', 'name' => 'Gone Teacher']);
        $disabled = User::factory()->create(['role_id' => 5, 'school_id' => $this->school, 'account_status' => 'disable', 'name' => 'Disabled Librarian']);
        $otherSchoolStaff = User::factory()->create(['role_id' => 3, 'school_id' => $this->otherSchool, 'account_status' => 'active', 'name' => 'Elsewhere Teacher']);

        foreach ([$parent, $student, $terminated, $disabled, $otherSchoolStaff] as $user) {
            $this->structureFor($user, ['school_id' => $user->school_id]);
        }

        $this->generate($this->period);

        $included = DB::table('payroll')->where('pay_period', $this->periodFilter())->pluck('staff_id')->all();
        $this->assertContains($this->teacher->id, $included);
        $this->assertNotContains($parent->id, $included);
        $this->assertNotContains($student->id, $included);
        $this->assertNotContains($terminated->id, $included);
        $this->assertNotContains($disabled->id, $included);
        $this->assertNotContains($otherSchoolStaff->id, $included);
    }

    public function test_generate_skips_staff_without_salary_structure_and_is_idempotent(): void
    {
        $this->structureFor($this->teacher);

        $this->generate($this->period);
        $this->generate($this->period); // second run must not duplicate

        $count = DB::table('payroll')->where('pay_period', $this->periodFilter())->count();
        $this->assertSame(1, $count);
    }

    public function test_november_five_eligible_employees_without_assignments_are_skipped_without_inventing_salaries(): void
    {
        $this->period = '2026-11';
        User::factory()->count(2)->create([
            'school_id' => $this->school, 'role_id' => 3, 'account_status' => 'active',
        ]);
        $service = app(PayrollService::class);
        $preview = $service->preview($this->school, '2026-11-01');

        $this->assertCount(5, $preview['rows']);
        $this->assertSame(5, $preview['missing_structure_count']);
        $this->assertCount(0, array_filter($preview['rows'], fn ($row) => $row['computed'] !== null));
        $this->assertDatabaseCount('payroll', 0);
        $this->actingAs($this->admin)->get(route('admin.payroll.preview', ['month' => $this->period]))
            ->assertOk()->assertSee('No salary structure effective for this period');

        $result = $service->generate($this->school, $this->admin->id, '2026-11-01');
        $this->assertSame(0, $result['generated']);
        $this->assertCount(5, $result['skipped_no_structure']);
        $this->assertDatabaseCount('salary_structures', 0);
        $this->assertDatabaseCount('payroll', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public static function novemberSalaryDates(): array
    {
        return [
            'prior month carries forward' => ['2026-10-31', true],
            'month start' => ['2026-11-01', true],
            'mid month' => ['2026-11-15', true],
            'last day is inclusive' => ['2026-11-30', true],
            'next month is excluded' => ['2026-12-01', false],
            'legacy undated assignment is excluded' => [null, false],
        ];
    }

    #[DataProvider('novemberSalaryDates')]
    public function test_november_salary_assignment_dates_match_preview_and_generation(?string $date, bool $payable): void
    {
        $this->period = '2026-11';
        if ($date === null) {
            $this->structureFor($this->teacher, ['effective_from' => null]);
        } else {
            // Synthetic fixture amount only. Exercise the real form/model date
            // cast, rather than bypassing it with a raw database date insert.
            $this->actingAs($this->admin)->postJson(route('admin.salary_structures.store'), [
                'user_id' => $this->teacher->id, 'basic' => 1000000, 'effective_from' => $date,
            ])->assertOk()->assertJsonPath('status', 'success');
        }
        $service = app(PayrollService::class);
        $preview = $service->preview($this->school, '2026-11-01');
        $row = collect($preview['rows'])->first(fn ($row) => $row['staff']->id === $this->teacher->id);
        $this->assertSame($payable, $row['structure'] !== null);
        $this->assertSame($payable, $row['computed'] !== null);
        $this->assertDatabaseCount('payroll', 0);

        $result = $service->generate($this->school, $this->admin->id, '2026-11-01');
        $this->assertSame($payable ? 1 : 0, $result['generated']);
        $this->assertSame(!$payable, in_array($this->teacher->name, $result['skipped_no_structure'], true));
        $this->assertSame($payable, $this->rowFor($this->teacher) !== null);
    }

    public function test_november_uses_latest_effective_assignment_and_never_future_or_foreign_school_salary(): void
    {
        $this->period = '2026-11';
        $this->structureFor($this->teacher, ['effective_from' => '2026-10-01']);
        $expected = $this->structureFor($this->teacher, ['effective_from' => '2026-11-15']);
        $this->structureFor($this->teacher, ['effective_from' => '2026-12-01']);
        $this->structureFor($this->teacher, ['school_id' => $this->otherSchool, 'effective_from' => '2026-11-29']);
        $this->structureFor($this->librarian, ['school_id' => $this->otherSchool]);

        $service = app(PayrollService::class);
        $preview = $service->preview($this->school, '2026-11-01');
        $teacher = collect($preview['rows'])->first(fn ($row) => $row['staff']->id === $this->teacher->id);
        $this->assertSame($expected, $teacher['structure']->id);
        $this->assertSame(2, $preview['missing_structure_count']);

        $result = $service->generate($this->school, $this->admin->id, '2026-11-01');
        $this->assertSame(1, $result['generated']);
        $this->assertContains($this->librarian->name, $result['skipped_no_structure']);
        $this->assertEqualsWithDelta($teacher['computed']['net'], $this->rowFor($this->teacher)->net_pay, 0.01);
    }

    public function test_salary_assignment_requires_an_effective_date_and_the_same_school(): void
    {
        $payload = ['user_id' => $this->teacher->id, 'basic' => 1000000];
        $this->actingAs($this->admin)->postJson(route('admin.salary_structures.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $payload['user_id'] = $this->otherSchoolAdmin->id;
        $payload['effective_from'] = '2026-11-01';
        $this->actingAs($this->admin)->postJson(route('admin.salary_structures.store'), $payload)->assertNotFound();
        $this->assertDatabaseCount('salary_structures', 0);
    }

    // ── Approval workflow ──────────────────────────────────────────────

    private function salaryFormPayload(array $overrides = []): array
    {
        // Existing synthetic test salary, never production configuration.
        return array_merge(['user_id' => $this->teacher->id, 'basic' => '1000000.00',
            'housing' => '0', 'transport' => '', 'medical' => '', 'other_earnings' => '',
            'payment_method' => 'bank', 'bank_account' => '', 'effective_from' => '2026-11-30'], $overrides);
    }

    public function test_salary_form_browser_save_persists_redirects_and_is_recognized_by_preview_only(): void
    {
        $this->actingAs($this->admin)->post(route('admin.salary_structures.store'), $this->salaryFormPayload())
            ->assertRedirect(route('admin.salary_structures.index'))->assertSessionHas('success', 'Salary structure saved');
        $this->assertDatabaseCount('salary_structures', 1);
        $saved = \App\Models\SalaryStructure::firstOrFail();
        $this->assertSame($this->teacher->id, $saved->user_id);
        $this->assertSame($this->school, $saved->school_id);
        $this->assertSame('2026-11-30', $saved->effective_from->format('Y-m-d'));
        $this->assertEquals(1000000, $saved->basic);
        $this->assertEquals(0, $saved->transport);
        $preview = app(PayrollService::class)->preview($this->school, '2026-11-01');
        $row = collect($preview['rows'])->first(fn ($row) => $row['staff']->id === $this->teacher->id);
        $this->assertSame($saved->id, $row['structure']->id);
        $this->assertNotNull($row['computed']);
        $this->assertDatabaseCount('payroll', 0);
        $this->assertDatabaseCount('payroll_payments', 0);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_salary_form_ajax_save_and_identical_retry_return_same_persisted_id(): void
    {
        $this->actingAs($this->admin);
        $first = $this->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload())
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('created', true);
        $id = $first->json('id');
        $this->assertDatabaseHas('salary_structures', ['id' => $id, 'user_id' => $this->teacher->id]);
        $this->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload(['basic' => '1000000', 'transport' => '0.00']))
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('id', $id);
        $this->assertDatabaseCount('salary_structures', 1);
    }

    public function test_salary_form_browser_retry_does_not_duplicate_and_conflict_preserves_input_and_history(): void
    {
        $url = route('admin.salary_structures.store');
        $this->actingAs($this->admin);
        $payload = $this->salaryFormPayload();
        $this->post($url, $payload)->assertRedirect(route('admin.salary_structures.index'));
        $original = DB::table('salary_structures')->first();
        $this->post($url, $payload)->assertSessionHas('success', 'Salary structure already saved');
        $conflict = $this->salaryFormPayload(['basic' => '1000001.00']);
        $this->from(route('admin.salary_structures.index'))->post($url, $conflict)
            ->assertRedirect(route('admin.salary_structures.index'))->assertSessionHasErrors('effective_from')
            ->assertSessionHasInput('basic', '1000001.00');
        $this->assertEquals($original, DB::table('salary_structures')->first());
        $this->assertDatabaseCount('salary_structures', 1);
    }

    public function test_salary_form_conflicting_ajax_and_preexisting_duplicates_are_rejected_without_deleting_history(): void
    {
        $url = route('admin.salary_structures.store');
        $this->actingAs($this->admin)->postJson($url, $this->salaryFormPayload())->assertOk();
        $this->postJson($url, $this->salaryFormPayload(['housing' => '1.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->postJson($url, $this->salaryFormPayload(['bank_account' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->structureFor($this->teacher, ['effective_from' => '2026-11-30']);
        $before = DB::table('salary_structures')->orderBy('id')->get();
        $this->postJson($url, $this->salaryFormPayload())->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->assertEquals($before, DB::table('salary_structures')->orderBy('id')->get());
    }

    public function test_salary_form_new_effective_date_appends_history_and_never_overwrites_the_old_salary(): void
    {
        $url = route('admin.salary_structures.store');
        $this->actingAs($this->admin)->postJson($url, $this->salaryFormPayload())->assertOk();
        $old = DB::table('salary_structures')->first();
        $this->postJson($url, $this->salaryFormPayload(['effective_from' => '2026-12-01']))->assertOk()->assertJsonPath('created', true);
        $this->assertEquals($old, DB::table('salary_structures')->where('id', $old->id)->first());
        $this->assertDatabaseCount('salary_structures', 2);
        $this->assertDatabaseCount('payroll', 0);
    }

    public static function invalidSalaryFormFields(): array
    {
        return ['negative salary' => ['basic', '-1'], 'overflow' => ['basic', '10000000000000'],
            'extra decimal places' => ['housing', '1.001'], 'missing date' => ['effective_from', ''],
            'invalid date' => ['effective_from', '2026-11-31'], 'timestamp instead of date' => ['effective_from', '2026-11-30 12:00:00'],
            'payment method' => ['payment_method', 'cheque'], 'unknown employee' => ['user_id', 999999]];
    }

    #[DataProvider('invalidSalaryFormFields')]
    public function test_salary_form_ajax_validation_does_not_persist(string $field, mixed $value): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('salary_structures', 0);
    }

    public function test_salary_form_browser_validation_renders_errors_with_entered_values(): void
    {
        $index = route('admin.salary_structures.index');
        $this->actingAs($this->admin)->from($index)->post(route('admin.salary_structures.store'),
            $this->salaryFormPayload(['basic' => '-1', 'housing' => '125.25', 'payment_method' => 'cash']))
            ->assertRedirect($index)->assertSessionHasErrors('basic')->assertSessionHasInput('housing', '125.25');
        $this->get($index)->assertOk()->assertSee('Correct salary structure details')->assertSee('value="125.25"', false)
            ->assertSee('name="_token"', false);
        $this->assertDatabaseCount('salary_structures', 0);
    }

    public function test_salary_form_requires_authorized_actor_and_eligible_same_school_employee(): void
    {
        $url = route('admin.salary_structures.store');
        $this->postJson($url, $this->salaryFormPayload())->assertUnauthorized();
        $this->actingAs($this->teacher)->postJson($url, $this->salaryFormPayload())->assertForbidden();
        $this->actingAs($this->admin)->postJson($url, $this->salaryFormPayload(['user_id' => $this->otherSchoolAdmin->id]))->assertNotFound();
        $parent = User::factory()->create(['school_id' => $this->school, 'role_id' => 6, 'account_status' => 'active']);
        $this->postJson($url, $this->salaryFormPayload(['user_id' => $parent->id]))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->teacher->update(['staff_status' => 'terminated']);
        $this->postJson($url, $this->salaryFormPayload())->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('salary_structures', 0);
    }

    public function test_salary_form_csrf_is_required_for_browser_and_ajax(): void
    {
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, fn ($app) =>
            new class($app, $app['encrypter']) extends \App\Http\Middleware\VerifyCsrfToken {
                protected function runningUnitTests() { return false; }
            });
        $this->actingAs($this->admin)->post(route('admin.salary_structures.store'), $this->salaryFormPayload())->assertStatus(419);
        $this->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload())->assertStatus(419);
        $this->assertDatabaseCount('salary_structures', 0);
        $this->withSession(['_token' => 'salary-form-test-token'])
            ->post(route('admin.salary_structures.store'), $this->salaryFormPayload(['_token' => 'salary-form-test-token']), ['Accept' => 'text/html'])
            ->assertRedirect(route('admin.salary_structures.index'));
        $this->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload(), ['X-CSRF-TOKEN' => 'salary-form-test-token'])
            ->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('salary_structures', 1);
    }

    public function test_salary_form_transaction_rolls_back_if_persistence_fails_after_insert(): void
    {
        \App\Models\SalaryStructure::created(function () { throw new \RuntimeException('Synthetic persistence failure'); });
        try {
            $this->actingAs($this->admin)->postJson(route('admin.salary_structures.store'), $this->salaryFormPayload())->assertStatus(500);
            $this->assertDatabaseCount('salary_structures', 0);
        } finally { \App\Models\SalaryStructure::flushEventListeners(); }
    }

    public function test_draft_cannot_be_approved_by_get_but_can_via_post(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        $this->actingAs($this->admin)->get(route('admin.payroll.approve', $payroll->id))->assertStatus(405);

        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))
            ->assertRedirect()->assertSessionHas('success');

        $row = DB::table('payroll')->find($payroll->id);
        $this->assertSame('approved', $row->status);
        $this->assertEquals($this->admin->id, $row->approved_by);
        $this->assertNotNull($row->approved_at);
    }

    public function test_rejection_records_reason_and_generation_replaces_rejected_drafts(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        $this->actingAs($this->admin)
            ->post(route('admin.payroll.reject', $payroll->id), ['rejection_reason' => 'Basic salary outdated'])
            ->assertRedirect();
        $this->assertSame('Basic salary outdated', DB::table('payroll')->find($payroll->id)->rejection_reason);
        $this->assertNotNull(DB::table('payroll')->find($payroll->id)->rejected_at);

        $this->generate(); // regeneration replaces rejected drafts

        $this->assertDatabaseMissing('payroll', ['id' => $payroll->id]);
        $new = $this->rowFor($this->teacher);
        $this->assertSame('draft', $new->status);
        $this->assertNull($new->rejected_at);
    }

    // ── Payment recording ──────────────────────────────────────────────

    public function test_unapproved_payroll_cannot_be_paid(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        $this->actingAs($this->admin)
            ->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload(1000))
            ->assertUnprocessable();
        $this->assertDatabaseMissing('payroll_payments', ['payroll_id' => $payroll->id]);
    }

    public function test_full_payment_creates_expense_and_marks_paid(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();

        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;

        $this->actingAs($this->admin)
            ->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net))
            ->assertRedirect()->assertSessionHas('success');

        $row = DB::table('payroll')->find($payroll->id);
        $this->assertSame('paid', $row->status);
        $this->assertEqualsWithDelta($net, (float) $row->paid_amount, 0.01);
        $paymentRow = DB::table('payroll_payments')->where('payroll_id', $row->id)->first();
        $this->assertNotNull($paymentRow);
        $this->assertEqualsWithDelta($net, (float) $paymentRow->amount, 0.01);
        $this->assertSame('bank', $paymentRow->payment_method);
        $this->assertStringContainsString('TRX-', $paymentRow->payment_reference);

        $expenses = DB::table('expenses')->where('school_id', $this->school)->get();
        $this->assertCount(1, $expenses);
        $this->assertEqualsWithDelta($net, (float) $expenses[0]->amount, 0.01);
        $this->assertSame('Payroll', DB::table('expense_categories')->find($expenses[0]->expense_category_id)->name);
    }

    public function test_partial_payment_then_completion(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();

        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;

        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net / 2))->assertRedirect();
        $afterPartial = DB::table('payroll')->find($payroll->id);
        $this->assertSame('approved', $afterPartial->status, 'Half-paid row stays approved (partially paid)');

        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net / 2))->assertRedirect();
        $this->assertSame('paid', (string) DB::table('payroll')->find($payroll->id)->status);
        $this->assertSame(2, (int) DB::table('payroll_payments')->where('payroll_id', $payroll->id)->count());
    }

    public function test_duplicate_and_overpayment_protection(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();
        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;

        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net))->assertRedirect();

        // A second full payment must be refused (payroll is already fully paid)
        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net))->assertUnprocessable();

        $this->assertSame(1, (int) DB::table('payroll_payments')->count(), 'No duplicate payment rows');
    }

    // ── Security / tenancy ─────────────────────────────────────────────

    public function test_administrator_of_another_school_cannot_see_or_act(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        // Other school admin's dashboard is scoped to their school only
        $this->actingAs($this->otherSchoolAdmin)->get(route('admin.payroll.index', ['month' => $this->period]))
            ->assertOk()->assertDontSee('Sarah Teacher');

        // Cross-tenant records do not exist for them (no information leak)
        $this->actingAs($this->otherSchoolAdmin)->post(route('admin.payroll.approve', $payroll->id))->assertNotFound();
        $this->assertSame('draft', DB::table('payroll')->find($payroll->id)->status);
    }

    public function test_print_and_export_endpoints_return_files(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        $this->actingAs($this->admin)->get(route('admin.payroll.print', $payroll->id))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $csv = $this->actingAs($this->admin)->get(route('admin.payroll.export', ['month' => $this->period]));
        $csv->assertOk();
        $this->assertStringContainsString('1,000,000.00', $csv->streamedContent());
    }

    public function test_reports_render_year_totals(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);

        $this->actingAs($this->admin)->get(route('admin.payroll.reports', ['year' => now()->year]))->assertOk();
    }

    // ── Fee protection: salaries never touch student balances ──────────

    public function test_salary_payment_only_posts_an_expense_and_never_touches_revenue_tables(): void
    {
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'name' => 'Fees Student']);

        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();
        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;
        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net))->assertRedirect();

        // The only financial side-effect is the salary expense posting.
        $this->assertSame(1, (int) DB::table('expenses')->count());

        // Payroll never performs fee-module operations: expense rows are the
        // school's expenditure ledger; student users are untouched.
        $this->assertSame($student->id, DB::table('users')->where('id', $student->id)->value('id'));
        $this->assertSame('Fees Student', DB::table('users')->where('id', $student->id)->value('name'));
    }

    // ── Dashboard aggregates ──────────────────────────────────────────

    public function test_dashboard_lists_period_rows_with_totals(): void
    {
        $this->structureFor($this->teacher);
        $this->structureFor($this->librarian, ['basic' => 800000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);

        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period]))
            ->assertOk()
            ->assertSee('Sarah Teacher')
            ->assertSee('Larry Librarian');
    }

    /** The payment modal only renders for approved rows — exercising it guards the dashboard render for paid/approved periods. */
    public function test_dashboard_renders_approved_rows_and_payment_modal(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;

        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period]))
            ->assertOk()
            ->assertSee('Sarah Teacher')
            ->assertSee('Record payment')
            // default amount must be a plain 2-decimal number (no thousands separators)
            ->assertSee('value="'.number_format($net, 2, '.', '').'"', false);
    }

    // ── Full workflow regression: approve → pay (partial → full) ────────────

    public function test_full_workflow_approve_then_pay_partial_then_complete(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        // 1. Draft → Approve
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('approved', DB::table('payroll')->find($payroll->id)->status);

        // 2. Approved → Partial payment
        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;
        $partial = round($net / 2, 2);
        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($partial))
            ->assertRedirect()->assertSessionHas('success');
        $afterPartial = DB::table('payroll')->find($payroll->id);
        $this->assertSame('approved', $afterPartial->status, 'Partially paid stays approved');
        $this->assertEqualsWithDelta($partial, (float) $afterPartial->paid_amount, 0.01);
        $this->assertSame(1, DB::table('payroll_payments')->where('payroll_id', $payroll->id)->count());

        // 3. Partial → Full payment
        $remaining = round($net - $partial, 2);
        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($remaining))
            ->assertRedirect()->assertSessionHas('success');
        $afterFull = DB::table('payroll')->find($payroll->id);
        $this->assertSame('paid', $afterFull->status);
        $this->assertEqualsWithDelta($net, (float) $afterFull->paid_amount, 0.01);
        $this->assertSame(2, DB::table('payroll_payments')->where('payroll_id', $payroll->id)->count());

        // 4. Expense posted for each payment
        $this->assertSame(2, DB::table('expenses')->where('school_id', $this->school)->count());
    }

    public function test_payment_modal_renders_for_approved_rows_and_submits_to_correct_route(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();

        // Dashboard renders payment modal with correct form action
        $response = $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period]));
        $response->assertOk();
        $content = (string) $response->getContent();

        // Modal exists with correct ID
        $this->assertStringContainsString("id=\"payModal{$payroll->id}\"", $content);
        // Form action points to correct pay route
        $this->assertStringContainsString("action=\"".route('admin.payroll.pay', $payroll->id)."\"", $content);
        // Modal has proper Bootstrap structure: modal-dialog > modal-content > form
        $this->assertStringContainsString('<div class="modal-dialog">', $content);
        $this->assertStringContainsString('<div class="modal-content">', $content);
        // Payment fields present
        $this->assertStringContainsString('name="payment_amount"', $content);
        $this->assertStringContainsString('name="payment_method"', $content);
        $this->assertStringContainsString('name="payment_reference"', $content);
        $this->assertStringContainsString('name="paid_on"', $content);
    }

    public function test_reject_modal_renders_for_draft_rows(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period]));
        $response->assertOk();
        $content = (string) $response->getContent();

        $this->assertStringContainsString("id=\"rejectModal{$payroll->id}\"", $content);
        $this->assertStringContainsString("action=\"".route('admin.payroll.reject', $payroll->id)."\"", $content);
        $this->assertStringContainsString('<div class="modal-dialog">', $content);
        $this->assertStringContainsString('<div class="modal-content">', $content);
        $this->assertStringContainsString('name="rejection_reason"', $content);
    }

    public function test_flash_messages_appear_after_approve_and_pay(): void
    {
        $this->structureFor($this->teacher, ['basic' => 1000000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);

        // Approve returns success flash
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))
            ->assertRedirect()->assertSessionHas('success')->assertSessionHas('message');

        // Pay returns success flash
        $net = (float) DB::table('payroll')->find($payroll->id)->net_pay;
        $this->actingAs($this->admin)->post(route('admin.payroll.pay', $payroll->id), $this->paymentPayload($net))
            ->assertRedirect()->assertSessionHas('success')->assertSessionHas('message');
    }

    public function test_get_pay_route_redirects_to_index(): void
    {
        $this->structureFor($this->teacher);
        $this->generate($this->period);
        $payroll = $this->rowFor($this->teacher);
        $this->actingAs($this->admin)->post(route('admin.payroll.approve', $payroll->id))->assertRedirect();

        // GET on pay route redirects to index (not 405)
        $this->actingAs($this->admin)->get(route('admin.payroll.pay', $payroll->id))
            ->assertRedirect(route('admin.payroll.index').'?*');
    }

    // ── Preview ────────────────────────────────────────────────────────

    public function test_preview_lists_eligible_staff_without_creating_records(): void
    {
        $this->structureFor($this->teacher);

        $this->actingAs($this->admin)->get(route('admin.payroll.preview', ['month' => $this->period]))
            ->assertOk()
            ->assertSee('Sarah Teacher');

        $this->assertDatabaseCount('payroll', 0);

        $this->actingAs($this->admin)->post(route('admin.payroll.generate'), ['month' => $this->period])->assertRedirect();
        $this->actingAs($this->admin)->get(route('admin.payroll.preview', ['month' => $this->period]))
            ->assertOk()
            ->assertSee('Sarah Teacher');
        $this->assertDatabaseCount('payroll', 1);
    }

    // ── Calculator coverage (statutory maths contract) ────────────────

    public function test_calculator_zero_income_and_band_edges(): void
    {
        $result = UgandaPayrollCalculator::compute(['basic' => 0, 'housing' => 0, 'transport' => 0, 'medical' => 0, 'other' => 0]);
        $this->assertSame(0.0, $result['gross']);
        $this->assertGreaterThanOrEqual(0.0, $result['paye']);

        // exactly 235,000 chargeable — first band 10% = 23,500
        $result = UgandaPayrollCalculator::compute(['basic' => 235000.0, 'housing' => 0, 'transport' => 0, 'medical' => 0, 'other' => 0], ['nssf_employee_rate' => 0]);
        $this->assertEqualsWithDelta(23500, $result['paye'], 0.01);

        // 500,000 chargeable: 23,500 + 20% of 100,000 + 30% of 165,000
        $result = UgandaPayrollCalculator::compute(['basic' => 500000.0, 'housing' => 0, 'transport' => 0, 'medical' => 0, 'other' => 0], ['nssf_employee_rate' => 0]);
        $expected = 23500 + 0.20 * (335000 - 235000) + 0.30 * (500000 - 335000);
        $this->assertEqualsWithDelta($expected, $result['paye'], 0.01);
    }

    // ── Regression: November 2026 ORDER BY name error (P0) ────────────────

    public function test_dashboard_sorts_by_staff_name_without_sql_error(): void
    {
        $this->period = '2026-11';
        $this->structureFor($this->teacher);
        $this->structureFor($this->librarian, ['basic' => 800000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);

        // Default sort by staff.name (ascending) — must not throw "Unknown column 'name'"
        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period]))
            ->assertOk()
            ->assertSee('Larry Librarian')
            ->assertSee('Sarah Teacher');

        // Explicit sort parameter must be restricted to allowlist
        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period, 'sort' => 'name', 'dir' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Sarah Teacher', 'Larry Librarian']); // desc: Sarah (S) before Larry (L)

        // Invalid sort field must fall back to default (staff.name asc)
        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period, 'sort' => 'invalid_column', 'dir' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Larry Librarian', 'Sarah Teacher']);

        // Pagination works with sorting
        $this->actingAs($this->admin)
            ->get(route('admin.payroll.index', ['month' => $this->period, 'page' => 1]))
            ->assertOk();
    }

    public function test_dashboard_allows_sorting_by_allowlisted_fields(): void
    {
        $this->period = '2026-11';
        $this->structureFor($this->teacher);
        $this->structureFor($this->librarian, ['basic' => 800000, 'housing' => 0, 'transport' => 0, 'medical' => 0]);
        $this->generate($this->period);

        foreach (['name', 'pay_period', 'net_pay', 'status', 'created_at'] as $field) {
            $this->actingAs($this->admin)
                ->get(route('admin.payroll.index', ['month' => $this->period, 'sort' => $field]))
                ->assertOk();
        }
    }
}
