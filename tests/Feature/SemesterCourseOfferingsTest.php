<?php

namespace Tests\Feature;

use App\Models\{CourseOffering, CourseRegistration, StudentFeeManager};
use App\Support\AcademicContextManager;
use App\Support\CourseOffering\{CourseOfferingService, LecturerCourseOfferingAccess, SemesterCourseOfferings};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\{AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock};
use Tests\TestCase;

/** Synthetic BAASP Year 1 Semester 1 reproduction; no production financial data. */
class SemesterCourseOfferingsTest extends TestCase
{
    use AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock('2026-10-28 12:00:00');
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        $this->createTables();
        $this->seedAcademicStructure();
        (require database_path('migrations/2026_10_17_100000_create_student_fee_registration_waivers_table.php'))->up();
        (require database_path('migrations/2026_10_21_050000_create_student_fee_awards_table.php'))->up();
        DB::table('programmes')->where('id', $this->programme)->update(['name' => 'BAASP test programme', 'code' => 'BAASP']);
        DB::table('curricula')->whereIn('id', [2, 3])->update(['status' => 'draft']);
        DB::table('curriculum_memberships')->where('id', 121)->update(['curriculum_stage_id' => 1]);
        DB::table('curriculum_memberships')->where('id', 131)->update(['period_sequence' => 2]);
        DB::table('course_offering_curriculum_memberships')->whereIn('course_offering_id', [11, 21, 31])->delete();
        DB::table('course_offerings')->whereIn('id', [11, 21, 31])->delete();
    }

    private function provision(bool $apply = true): array
    {
        return app(SemesterCourseOfferings::class)->reconcile($this->school, 1, $apply);
    }

    private function reconcile(): void
    {
        $this->artisan('courses:reconcile-enrolment', ['--school' => $this->school, '--apply' => true])->assertSuccessful();
    }

    private function current()
    {
        return CourseOffering::where('school_id', $this->school)->where('academic_period_id', 1)->orderBy('id');
    }

    public function test_opening_semester_creates_all_required_offerings_without_lecturers_or_students(): void
    {
        DB::table('academic_periods')->where('id', 1)->update(['status' => 'planned']);
        app(AcademicContextManager::class)->transitionPeriod($this->school, 1, 'active');
        $this->assertSame(2, $this->current()->count());
        $this->assertSame(['open'], $this->current()->pluck('status')->unique()->values()->all());
        $this->assertDatabaseCount('course_offering_lecturer_allocations', 0);
        $this->assertDatabaseCount('course_registrations', 0);
        $this->assertSame(2, collect($this->provision())->filter(fn ($row) => in_array('lecturer_missing', $row['warnings']))->count());
    }

    public function test_selecting_an_already_open_semester_backfills_offerings(): void
    {
        app(AcademicContextManager::class)->setCurrent($this->school, 1, 1);
        $this->assertSame(2, $this->current()->count());
        app(AcademicContextManager::class)->setCurrent($this->school, 1, 1);
        $this->assertSame(2, $this->current()->count());
    }

    public function test_baasp_student_is_enrolled_into_every_required_unit_without_manual_setup(): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['programme_cohort_membership_id' => null]);
        DB::table('programme_cohort_memberships')->where('student_id', $student->id)->delete();
        $financeBefore = DB::table('student_fee_managers')->get()->toJson();
        $historyBefore = CourseOffering::findOrFail(12)->toArray();
        $this->reconcile();
        $this->assertSame(2, $this->current()->count());
        $this->assertSame(2, CourseRegistration::where('student_id', $student->id)->where('status', 'confirmed')->count());
        $this->assertSame($financeBefore, DB::table('student_fee_managers')->get()->toJson());
        $this->assertSame($historyBefore, CourseOffering::findOrFail(12)->toArray());
        $this->assertDatabaseCount('course_offering_lecturer_allocations', 0);
    }

    public function test_preview_creates_neither_offerings_nor_registrations(): void
    {
        $student = $this->placedStudent(1);
        $this->assertSame(['would_create', 'would_create'], array_column($this->provision(false), 'result'));
        $this->artisan('courses:reconcile-enrolment', ['--school' => $this->school, '--json' => true])->assertSuccessful();
        $this->assertSame(0, $this->current()->count());
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public function test_existing_active_offering_is_reused_and_missing_applicability_is_attached(): void
    {
        $service = app(CourseOfferingService::class);
        $existing = $service->createDraft($this->school, $this->units['Y1'], 1, 1);
        DB::table('course_offerings')->where('id', $existing->id)->update(['status' => 'in_progress']);
        $student = $this->placedStudent(1);
        $this->reconcile();
        $this->assertSame(2, $this->current()->count());
        $this->assertSame('in_progress', $existing->fresh()->status);
        $this->assertDatabaseHas('course_offering_curriculum_memberships', ['course_offering_id' => $existing->id, 'curriculum_membership_id' => 111]);
        $this->assertDatabaseHas('course_registrations', ['course_offering_id' => $existing->id, 'student_id' => $student->id, 'status' => 'confirmed']);
    }

    public function test_existing_draft_is_opened_through_governed_lifecycle_without_a_duplicate(): void
    {
        $existing = app(CourseOfferingService::class)->createDraft($this->school, $this->units['Y1'], 1, 1);
        $this->provision();
        $this->assertSame(2, $this->current()->count());
        $this->assertSame('open', $existing->fresh()->status);
    }

    public static function payments(): array
    {
        return [[19, 'unpaid', 0], [20, 'unpaid', 2], [100, 'pending', 0], [100, 'paid', 2]];
    }

    #[DataProvider('payments')]
    public function test_finance_review_rules_apply_after_offerings_are_created(int $paid, string $status, int $expected): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => $paid, 'status' => $status]);
        $this->reconcile();
        $this->assertSame(2, $this->current()->count());
        $this->assertSame($expected, CourseRegistration::where('student_id', $student->id)->count());
    }

    public function test_approved_waiver_qualifies_without_posting_cash(): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => 0]);
        $this->reconcile();
        $this->assertDatabaseCount('course_registrations', 0);
        $admin = $this->makeAdminUser($this->school);
        app(\App\Support\CourseRegistration\CourseRegistrationFeeWaiverService::class)->grantForStudent($admin, $student->id, $this->school, 'Approved study waiver for this student');
        $this->reconcile();
        $this->assertSame(2, CourseRegistration::where('student_id', $student->id)->where('status', 'confirmed')->count());
    }

    public function test_approved_scholarship_enrols_into_all_required_units(): void
    {
        $student = $this->placedStudent(1);
        $fee = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $fee->update(['paid_amount' => 0]);
        app(\App\Support\Billing\StudentFeeAwards::class)->change($this->makeAdminUser($this->school), $fee->id,
            ['action' => 'grant', 'type' => 'scholarship', 'amount' => '100.00', 'reason' => 'Authorised full scholarship']);
        $this->reconcile();
        $this->assertSame(2, CourseRegistration::where('student_id', $student->id)->count());
        $this->assertEquals(0, $fee->fresh()->paid_amount);
    }

    public function test_repeat_runs_preserve_confirmed_withdrawn_and_historical_registrations(): void
    {
        $student = $this->placedStudent(1);
        $this->reconcile();
        $registration = CourseRegistration::where('student_id', $student->id)->firstOrFail();
        $registration->update(['status' => 'dropped']);
        $before = CourseRegistration::orderBy('id')->get()->toJson();
        $offerings = $this->current()->get()->toJson();
        $this->reconcile();
        $this->reconcile();
        $this->assertSame($before, CourseRegistration::orderBy('id')->get()->toJson());
        $this->assertSame($offerings, $this->current()->get()->toJson());
        $this->assertSame(2, $this->current()->count());
    }

    public function test_electives_are_never_automatically_selected_or_provisioned(): void
    {
        $student = $this->placedStudent(1);
        DB::table('curriculum_memberships')->where('id', 121)->update(['classification' => 'elective']);
        $this->reconcile();
        $this->assertSame(1, $this->current()->count());
        $this->assertSame(1, CourseRegistration::where('student_id', $student->id)->count());
    }

    public function test_late_lecturer_assignment_reveals_the_automatically_enrolled_roster(): void
    {
        $eligible = $this->placedStudent(1);
        $pending = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $pending->id)->update(['status' => 'pending', 'paid_amount' => 100]);
        $this->reconcile();
        $lecturer = \App\Models\User::create(['school_id' => $this->school, 'role_id' => 3, 'name' => 'BAASP Lecturer',
            'email' => 'baasp-lecturer@example.test', 'password' => bcrypt('x')]);
        $access = app(LecturerCourseOfferingAccess::class);
        foreach ($this->current()->get() as $offering) {
            DB::table('course_offering_lecturer_allocations')->insert(['school_id' => $this->school, 'course_offering_id' => $offering->id,
                'user_id' => $lecturer->id, 'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => '2026-10-01']);
            $this->assertSame([$eligible->id], $access->teachingRoster($offering)->pluck('student_id')->all());
        }
        $this->assertSame(2, $access->reachableOfferings($lecturer)->count());
        $this->assertSame([1, 1], $access->offerings($lecturer)->pluck('my_confirmed_students')->values()->all());
        $this->assertSame([[], []], array_column($this->provision(), 'warnings'));
    }

    public function test_unapproved_other_period_and_foreign_tenant_units_are_excluded(): void
    {
        DB::table('curricula')->where('id', 1)->update(['school_id' => $this->foreignSchool]);
        $this->provision();
        $this->assertSame(0, $this->current()->count());
        $this->assertSame('period_not_open', app(SemesterCourseOfferings::class)->reconcile($this->foreignSchool, 1, true)[0]['reason']);
        $this->assertSame(1, CourseOffering::count());
    }

    public static function terminalStates(): array { return [['cancelled'], ['completed']]; }

    public function test_shared_required_unit_reuses_one_offering_across_approved_curricula(): void
    {
        DB::table('curricula')->where('id', 2)->update(['status' => 'approved']);
        $this->provision();
        $this->assertSame(2, $this->current()->count());
        $first = DB::table('course_offering_curriculum_memberships')->where('school_id', $this->school)->whereIn('course_offering_id', $this->current()->pluck('id'))->where('curriculum_membership_id', 111)->value('course_offering_id');
        $second = DB::table('course_offering_curriculum_memberships')->where('school_id', $this->school)->where('curriculum_membership_id', 211)->value('course_offering_id');
        $this->assertSame($first, $second);
    }

    public function test_missing_lecturer_is_flagged_on_the_staff_page_without_blocking_enrolment(): void
    {
        $student = $this->placedStudent(1);
        $this->reconcile();
        $this->actingAs($this->makeAdminUser($this->school))->get(route('admin.course_offerings.index', ['period_id' => 1]))
            ->assertOk()->assertSee('Lecturer required')->assertSee('students can enrol');
        $this->assertSame(2, CourseRegistration::where('student_id', $student->id)->count());
    }

    public function test_existing_terminal_delivery_does_not_hide_a_unique_active_delivery(): void
    {
        $service = app(CourseOfferingService::class);
        $closed = $service->createDraft($this->school, $this->units['Y1'], 1, 1);
        DB::table('course_offerings')->where('id', $closed->id)->update(['status' => 'completed']);
        $active = $service->createDraft($this->school, $this->units['Y1'], 1, 1);
        DB::table('course_offerings')->where('id', $active->id)->update(['status' => 'in_progress']);
        $student = $this->placedStudent(1);
        $this->reconcile();
        $this->assertSame('completed', $closed->fresh()->status);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'course_offering_id' => $active->id, 'status' => 'confirmed']);
        $this->assertDatabaseMissing('course_registrations', ['course_offering_id' => $closed->id]);
        $this->assertSame(3, $this->current()->count());
    }

    public function test_future_curriculum_and_archived_units_are_not_provisioned(): void
    {
        DB::table('curricula')->where('id', 1)->update(['effective_academic_year_id' => 2]);
        $this->provision();
        $this->assertSame(0, $this->current()->count());
        DB::table('curricula')->where('id', 1)->update(['effective_academic_year_id' => null]);
        Schema::table('curriculum_memberships', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
        DB::table('curriculum_memberships')->where('id', 121)->update(['archived_at' => now()]);
        $this->provision();
        $this->assertSame(1, $this->current()->count());
    }

    public function test_past_or_unopened_semesters_do_not_receive_new_offerings(): void
    {
        DB::table('academic_periods')->where('id', 1)->update(['status' => 'planned']);
        $this->assertSame('period_not_open', $this->provision()[0]['reason']);
        DB::table('academic_periods')->where('id', 1)->update(['status' => 'active', 'end_date' => '2026-10-27']);
        $this->assertSame('period_not_open', $this->provision()[0]['reason']);
        $this->assertSame(0, $this->current()->count());
    }

    public function test_inconsistent_current_year_and_period_do_not_provision_or_enrol(): void
    {
        $this->placedStudent(1);
        DB::table('schools')->where('id', $this->school)->update(['current_academic_year_id' => 2]);
        $this->reconcile();
        $this->assertSame(0, $this->current()->count());
        $this->assertDatabaseCount('course_registrations', 0);
    }

    #[DataProvider('terminalStates')]
    public function test_terminal_offerings_are_never_reopened_or_replaced(string $status): void
    {
        $offering = app(CourseOfferingService::class)->createDraft($this->school, $this->units['Y1'], 1, 1);
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => $status]);
        $this->reconcile();
        $this->assertSame($status, $offering->fresh()->status);
        $this->assertSame(2, $this->current()->count());
        $this->assertSame(1, $this->current()->where('subject_id', $this->units['Y1'])->count());
    }
}
