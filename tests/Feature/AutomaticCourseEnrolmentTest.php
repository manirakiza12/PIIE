<?php

namespace Tests\Feature;

use App\Models\{CourseOffering, CourseRegistration, LiveClass, OnlineExam, StudentFeeManager};
use App\Support\CourseRegistration\AutomaticCourseEnrolment;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\LiveClasses\LiveClassEligibility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\{AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock};
use Tests\TestCase;

class AutomaticCourseEnrolmentTest extends TestCase
{
    use AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock('2026-10-27 12:00:00');
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
    }

    private function reconcile(int $id, bool $apply = true): array
    {
        return app(AutomaticCourseEnrolment::class)->reconcileStudent($this->school, $id, $apply);
    }

    public static function payments(): array
    {
        return [
            'below boundary' => [19, 'unpaid', false], 'exactly 20 percent' => [20, 'unpaid', true],
            'above boundary' => [21, 'unpaid', true], 'verified fully paid' => [100, 'paid', true],
            'pending asserted full payment' => [100, 'pending', false], 'no payment' => [0, 'unpaid', false],
        ];
    }

    #[DataProvider('payments')]
    public function test_payment_boundary_and_pending_claims(int $paid, string $status, bool $eligible): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => $paid, 'status' => $status]);
        DB::table('course_offerings')->where('id', 11)->update(['status' => 'in_progress']);
        $before = DB::table('student_fee_managers')->get()->toJson();
        $this->reconcile($student->id);
        $this->assertSame($eligible ? 1 : 0, CourseRegistration::where('student_id', $student->id)->where('status', 'confirmed')->count());
        $this->assertSame($before, DB::table('student_fee_managers')->get()->toJson());
    }

    public function test_fractional_threshold_rounds_up_to_the_next_minor_unit(): void
    {
        $student = $this->placedStudent(1);
        // SQLite fixture uses integer affinity but accepts decimal values, matching the finance parser.
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['total_amount' => '100.01', 'paid_amount' => '20.00']);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => '20.01']);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 1);
    }

    public function test_approved_study_waiver_qualifies_but_revoked_or_other_session_waiver_does_not(): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => 0]);
        $admin = $this->makeAdminUser($this->school);
        $id = DB::table('student_fee_registration_waivers')->insertGetId(['school_id' => $this->school,
            'student_id' => $student->id, 'session_id' => 2, 'granted_by' => $admin->id,
            'reason' => 'Approved study waiver', 'granted_at' => now()]);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
        DB::table('student_fee_registration_waivers')->where('id', $id)->update(['session_id' => 1, 'revoked_at' => now()]);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
        DB::table('student_fee_registration_waivers')->where('id', $id)->update(['revoked_at' => null]);
        $this->reconcile($student->id);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'status' => 'confirmed']);
    }

    public function test_pending_financial_setup_requests_never_enrol_students(): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_fee_managers')->where('student_id', $student->id)->update(['paid_amount' => 0]);
        (require database_path('migrations/2026_10_23_100000_create_existing_student_financial_setup_reviews_table.php'))->up();
        DB::table('student_financial_setup_reviews')->insert(['school_id' => $this->school, 'student_id' => $student->id,
            'fee_id' => StudentFeeManager::where('student_id', $student->id)->value('id'), 'session_id' => 1,
            'prior_payment_status' => 'pending', 'prior_payment' => json_encode(['amount' => 100]),
            'award_status' => 'pending', 'award' => json_encode(['type' => 'scholarship', 'amount' => 100]),
            'study_waiver_status' => 'pending', 'study_waiver' => json_encode(['reason' => 'Requested waiver']), 'captured_by' => $student->id]);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public function test_approved_scholarship_uses_the_existing_reduced_invoice_balance(): void
    {
        $student = $this->placedStudent(1);
        $fee = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $admin = $this->makeAdminUser($this->school);
        // The finance approval service writes this award and its reduced invoice atomically.
        DB::table('student_fee_awards')->insert(['school_id' => $this->school, 'fee_id' => $fee->id,
            'type' => 'scholarship', 'amount' => 100, 'reason' => 'Approved full scholarship',
            'approved_by' => $admin->id, 'approved_by_name' => $admin->name]);
        $fee->update(['total_amount' => 0, 'paid_amount' => 0, 'status' => 'paid']);
        $this->reconcile($student->id);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'status' => 'confirmed']);
    }

    public static function invalidPlacements(): array
    {
        return ['wrong year of study' => ['stage'], 'missing placement' => ['missing'], 'ended placement' => ['ended'],
            'wrong curriculum' => ['curriculum'], 'unapproved curriculum' => ['unapproved'],
            'wrong programme' => ['programme'], 'wrong academic year' => ['year'], 'disabled account' => ['disabled']];
    }

    #[DataProvider('invalidPlacements')]
    public function test_placement_and_curriculum_must_match(string $case): void
    {
        $student = $this->placedStudent($case === 'stage' ? 2 : 1);
        DB::table('course_offerings')->whereIn('id', [21, 31])->update(['status' => 'draft']);
        match ($case) {
            'missing' => DB::table('student_curriculum_assignments')->where('student_id', $student->id)->delete(),
            'ended' => DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['ended_at' => now()]),
            'curriculum' => DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['curriculum_id' => 2, 'entry_curriculum_stage_id' => 4]),
            'unapproved' => DB::table('curricula')->where('id', 1)->update(['status' => 'draft']),
            'programme' => DB::table('student_profiles')->where('user_id', $student->id)->update(['programme_id' => $this->otherProgramme]),
            'year' => DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['entry_academic_year_id' => 2, 'effective_from_academic_year_id' => 2]),
            'disabled' => $student->update(['account_status' => 'disable']),
            default => null,
        };
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public static function closedContexts(): array
    {
        return [['draft'], ['cancelled'], ['completed'], ['period_completed'], ['year_completed'], ['period_future'], ['period_past'], ['no_period'], ['foreign_period']];
    }

    #[DataProvider('closedContexts')]
    public function test_only_current_active_period_and_teaching_allocations_are_used(string $case): void
    {
        $student = $this->placedStudent(1);
        if (in_array($case, ['draft', 'cancelled', 'completed'])) {
            DB::table('course_offerings')->where('id', 11)->update(['status' => $case]);
        } elseif ($case === 'period_completed') {
            DB::table('academic_periods')->where('id', 1)->update(['status' => 'completed']);
        } elseif ($case === 'year_completed') {
            DB::table('academic_years')->where('id', 1)->update(['status' => 'completed']);
        } elseif ($case === 'period_future' || $case === 'period_past') {
            DB::table('academic_periods')->where('id', 1)->update($case === 'period_future' ? ['start_date' => '2026-10-28'] : ['end_date' => '2026-10-26']);
        } elseif ($case === 'foreign_period') {
            DB::table('academic_periods')->where('id', 1)->update(['school_id' => $this->foreignSchool]);
        } else {
            DB::table('schools')->where('id', $this->school)->update(['current_academic_period_id' => null]);
        }
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public function test_preview_retries_and_existing_history_are_safe(): void
    {
        $student = $this->placedStudent(1);
        $preview = $this->reconcile($student->id, false);
        $this->assertSame('would_enrol', $preview[0]['result']);
        $this->assertDatabaseCount('course_registrations', 0);
        $this->reconcile($student->id);
        $before = CourseRegistration::firstOrFail()->toArray();
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 1);
        $this->assertSame($before, CourseRegistration::firstOrFail()->toArray());
        CourseRegistration::firstOrFail()->update(['status' => 'dropped']);
        $before = CourseRegistration::firstOrFail()->toArray();
        $this->reconcile($student->id);
        $this->assertSame($before, CourseRegistration::firstOrFail()->toArray());
    }

    public function test_pending_registration_is_confirmed_but_historical_registration_is_untouched(): void
    {
        $student = $this->placedStudent(1);
        $attributes = ['school_id' => $this->school, 'student_id' => $student->id,
            'subject_id' => $this->units['Y1'], 'curriculum_membership_id' => 111,
            'registered_credits' => 3, 'registered_classification' => 'compulsory', 'status' => 'registered'];
        $current = CourseRegistration::create($attributes + ['course_offering_id' => 11]);
        $historical = CourseRegistration::create($attributes + ['course_offering_id' => 12]);
        $before = $historical->fresh()->toArray();
        $this->reconcile($student->id);
        $this->assertSame('confirmed', $current->fresh()->status);
        $this->assertSame($before, $historical->fresh()->toArray());
    }

    public function test_electives_require_existing_choice_and_parallel_allocations_are_reported(): void
    {
        $student = $this->placedStudent(1);
        DB::table('curriculum_memberships')->where('id', 111)->update(['classification' => 'elective']);
        $this->assertSame('elective_requires_choice', $this->reconcile($student->id)[0]['reason']);
        CourseRegistration::create(['school_id' => $this->school, 'student_id' => $student->id,
            'subject_id' => $this->units['Y1'], 'course_offering_id' => 11, 'curriculum_membership_id' => 111,
            'registered_credits' => 3, 'registered_classification' => 'elective', 'status' => 'registered']);
        $this->reconcile($student->id);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'status' => 'confirmed']);
        DB::table('course_offerings')->insert(['id' => 13, 'school_id' => $this->school, 'subject_id' => $this->units['Y1'],
            'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => 'in_progress']);
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => $this->school, 'course_offering_id' => 13,
            'curriculum_id' => 1, 'curriculum_membership_id' => 111, 'subject_id' => $this->units['Y1']]);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 1);
        $second = $this->placedStudent(1);
        $this->assertSame('parallel_allocations', $this->reconcile($second->id)[0]['reason']);
    }

    public function test_confirmed_students_are_visible_to_lecturers_lectures_and_assessments(): void
    {
        $student = $this->placedStudent(1);
        $offering = CourseOffering::findOrFail(11);
        $roster = app(LecturerCourseOfferingAccess::class);
        $this->assertCount(0, $roster->teachingRoster($offering));
        $this->reconcile($student->id);
        $this->assertSame([$student->id], $roster->teachingRoster($offering)->pluck('student_id')->all());
        $live = new LiveClass(['school_id' => $this->school, 'course_offering_id' => 11]);
        $this->assertSame([$student->id], LiveClassEligibility::confirmedOfferingStudentUserIds($live)->all());
        $exam = new OnlineExam(['school_id' => $this->school, 'course_offering_id' => 11]);
        $this->assertContains($student->id, app(CourseOfferingExamAccess::class)->eligibleStudentIds($exam));
        $this->assertCount(0, $roster->teachingRoster(CourseOffering::findOrFail(21)));
    }

    public function test_command_defaults_to_preview_and_can_apply_with_explicit_scope(): void
    {
        $student = $this->placedStudent(1);
        $this->artisan('courses:reconcile-enrolment', ['--school' => $this->school, '--student' => $student->id])->assertSuccessful();
        $this->assertDatabaseCount('course_registrations', 0);
        $this->artisan('courses:reconcile-enrolment', ['--school' => $this->school, '--student' => $student->id, '--apply' => true, '--json' => true])->assertSuccessful();
        $this->assertDatabaseCount('course_registrations', 1);
        $this->artisan('courses:reconcile-enrolment', ['--school' => 'invalid'])->assertFailed();
    }

    public function test_every_required_unit_is_enrolled_without_group_membership(): void
    {
        $student = $this->placedStudent(1);
        DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['programme_cohort_membership_id' => null]);
        DB::table('programme_cohort_memberships')->where('student_id', $student->id)->delete();
        DB::table('curriculum_memberships')->where('id', 121)->update(['curriculum_stage_id' => 1]);
        DB::table('course_offerings')->where('id', 21)->update(['status' => 'in_progress']);
        $this->reconcile($student->id);
        $this->assertSame([11, 21], CourseRegistration::where('student_id', $student->id)->orderBy('course_offering_id')->pluck('course_offering_id')->all());
    }

    public function test_wrong_programme_and_previous_session_fees_do_not_clear_enrolment(): void
    {
        $student = $this->placedStudent(1);
        $fee = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $fee->update(['programme_id' => $this->otherProgramme, 'paid_amount' => 100, 'status' => 'paid']);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
        $fee->update(['programme_id' => $this->programme, 'session_id' => 2]);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public function test_verified_provider_settlement_enables_automatic_enrolment(): void
    {
        $student = $this->placedStudent(1);
        $fee = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $fee->update(['paid_amount' => 0, 'status' => 'processing', 'payment_method' => 'marzpay', 'gateway_reference' => 'verified-txn']);
        $this->reconcile($student->id);
        $this->assertDatabaseCount('course_registrations', 0);
        $evidence = new \App\Support\Payments\VerifiedTuitionPayment($fee->id, $this->school, 'marzpay', 'reference', 'verified-txn', '100.00', 'UGX', 'paid', []);
        $this->assertSame('settled', \App\Support\Payments\TuitionFeeSettlement::apply($evidence));
        $this->reconcile($student->id);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'status' => 'confirmed']);
    }

    public function test_finance_approval_service_qualifies_a_scholarship_without_posting_cash(): void
    {
        $student = $this->placedStudent(1);
        $fee = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $fee->update(['paid_amount' => 0]);
        $admin = $this->makeAdminUser($this->school);
        app(\App\Support\Billing\StudentFeeAwards::class)->change($admin, $fee->id, [
            'action' => 'grant', 'type' => 'scholarship', 'amount' => '100.00', 'reason' => 'Authorised full scholarship award',
        ]);
        $this->reconcile($student->id);
        $this->assertEquals(0, $fee->fresh()->paid_amount);
        $this->assertDatabaseHas('course_registrations', ['student_id' => $student->id, 'status' => 'confirmed']);
    }

    public function test_attendance_and_content_access_use_the_automatically_confirmed_registration(): void
    {
        $student = $this->placedStudent(1);
        Schema::create('course_offering_attendance_records', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('attendance_session_id');
            $table->unsignedBigInteger('course_registration_id'); $table->unsignedBigInteger('student_id');
            $table->unsignedTinyInteger('status'); $table->timestamp('marked_at')->nullable();
        });
        $session = new \App\Models\CourseOfferingAttendanceSession();
        $session->forceFill(['id' => 1, 'school_id' => $this->school, 'course_offering_id' => 11]);
        $this->reconcile($student->id);
        $roster = app(\App\Support\CourseOfferingAttendance\CourseOfferingAttendanceService::class)->rosterFor($session);
        $this->assertSame([$student->id], $roster->pluck('student_id')->values()->all());
        $this->assertTrue(app(\App\Support\CourseContent\CourseContentAccess::class)->canStudentAccess($student, CourseOffering::findOrFail(11)));
    }

    public function test_mapped_finance_session_must_belong_to_the_current_year(): void
    {
        $student = $this->placedStudent(1);
        Schema::table('sessions', fn (Blueprint $table) => $table->unsignedBigInteger('academic_year_id')->nullable());
        DB::table('sessions')->insert(['id' => 1, 'school_id' => $this->school, 'academic_year_id' => 2]);
        $this->assertSame('finance_session_year_mismatch', $this->reconcile($student->id)[0]['reason']);
        $this->assertDatabaseCount('course_registrations', 0);
    }
}
