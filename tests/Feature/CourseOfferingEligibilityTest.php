<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\CourseRegistration\CourseOfferingEligibility;
use App\Support\CourseRegistration\CourseOfferingRoster;
use App\Support\CourseRegistration\CourseRegistrationService;
use App\Support\CourseRegistration\StudentCourseOfferingDiscovery;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\Feature\Support\CourseOfferingEligibilityFixture;
use Tests\TestCase;

/**
 * Study-year (Study Plan Stage) and Programme Cohort aware Course Offering
 * eligibility, shared by the admin roster/registration, student discovery,
 * self-registration and confirmation.
 */
class CourseOfferingEligibilityTest extends TestCase
{
    use AdmissionsTestHelper;
    use CourseOfferingEligibilityFixture;

    // Offerings in 2026/2027 Semester 1 (and one in 2027/2028).
    private const O_YEAR1 = 11;
    private const O_YEAR2 = 21;
    private const O_YEAR3 = 31;
    private const O_YEAR1_NEXT_YEAR = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        $this->createTables();
        $this->seedAcademicStructure();
    }

    public function test_year1_student_is_eligible_for_a_year1_semester1_unit(): void
    {
        $student = $this->placedStudent(stage: 1);
        $result = $this->evaluate(self::O_YEAR1, $student);

        $this->assertTrue($result->eligible);
        $this->assertSame(111, (int) $result->membership->id);
    }

    public function test_year1_student_is_not_eligible_for_a_year3_unit_sharing_semester1(): void
    {
        $student = $this->placedStudent(stage: 1);
        $result = $this->evaluate(self::O_YEAR3, $student);

        $this->assertFalse($result->eligible);
        $this->assertSame('stage_mismatch', $result->code);
        $this->assertSame("Not eligible — this Course Unit belongs to Year 3 of the student's Study Plan; the student is placed in Year 1.", $result->message);
    }

    public function test_year2_student_is_eligible_for_a_year2_unit(): void
    {
        $this->assertTrue($this->evaluate(self::O_YEAR2, $this->placedStudent(stage: 2))->eligible);
    }

    public function test_same_semester_number_but_wrong_study_plan_stage_is_blocked(): void
    {
        $this->assertSame('stage_mismatch', $this->evaluate(self::O_YEAR1, $this->placedStudent(stage: 2))->code);
        $this->assertSame('stage_mismatch', $this->evaluate(self::O_YEAR2, $this->placedStudent(stage: 1))->code);
    }

    public function test_wrong_programme_is_blocked(): void
    {
        $otherProgrammeStudent = $this->placedStudent(stage: 5, curriculum: 3, programme: $this->otherProgramme, cohort: 3);
        $this->assertSame('not_in_study_plan', $this->evaluate(self::O_YEAR1, $otherProgrammeStudent)->code);

        $profileMismatch = $this->placedStudent(stage: 1);
        DB::table('student_profiles')->where('user_id', $profileMismatch->id)->update(['programme_id' => $this->otherProgramme]);
        $result = $this->evaluate(self::O_YEAR1, $profileMismatch);
        $this->assertSame('programme_mismatch', $result->code);
        $this->assertStringNotContainsString('curriculum', strtolower($result->message));
    }

    public function test_wrong_study_plan_is_blocked(): void
    {
        $otherPlan = $this->placedStudent(stage: 4, curriculum: 2, cohort: 2);
        $result = $this->evaluate(self::O_YEAR1, $otherPlan);

        $this->assertSame('not_in_study_plan', $result->code);
        $this->assertSame("This Course Unit is not part of the student's current Study Plan for this Course Offering.", $result->message);
    }

    public function test_offering_in_an_academic_year_without_recorded_progression_is_blocked(): void
    {
        $result = $this->evaluate(self::O_YEAR1_NEXT_YEAR, $this->placedStudent(stage: 1));

        $this->assertSame('progression_not_recorded', $result->code);
        $this->assertStringContainsString('2027/2028', $result->message);
    }

    public function test_wrong_tenant_is_blocked_everywhere(): void
    {
        $foreign = User::create(['name' => 'Foreign', 'email' => 'foreign@example.com', 'password' => bcrypt('x'), 'role_id' => 7, 'school_id' => $this->foreignSchool]);
        $this->assertSame('student_inactive', $this->evaluate(self::O_YEAR1, $foreign)->code);
        $this->assertFalse($this->roster(self::O_YEAR1)->contains('id', $foreign->id));

        $this->actingAs($this->makeAdminUser($this->school));
        $this->expectException(DomainException::class);
        app(CourseRegistrationService::class)->registerStudentForOffering($this->school, $foreign->id, self::O_YEAR1);
    }

    public function test_current_valid_cohort_and_placement_is_eligible_and_mismatched_cohort_is_blocked(): void
    {
        $this->assertSame('eligible', $this->evaluate(self::O_YEAR1, $this->placedStudent(stage: 1))->code);

        $mismatch = $this->placedStudent(stage: 1);
        $membershipId = (int) DB::table('student_curriculum_assignments')->where('student_id', $mismatch->id)->value('programme_cohort_membership_id');
        DB::table('programme_cohort_memberships')->where('id', $membershipId)->update(['programme_cohort_id' => 2]);
        $result = $this->evaluate(self::O_YEAR1, $mismatch);
        $this->assertSame('cohort_mismatch', $result->code);
        $this->assertSame("The student's Programme Cohort does not match the current academic placement.", $result->message);

        $unlinked = $this->placedStudent(stage: 1);
        DB::table('student_curriculum_assignments')->where('student_id', $unlinked->id)->update(['programme_cohort_membership_id' => null]);
        $this->assertSame('placement_incomplete', $this->evaluate(self::O_YEAR1, $unlinked)->code);

        $noStage = $this->placedStudent(stage: 1);
        DB::table('student_curriculum_assignments')->where('student_id', $noStage->id)->update(['entry_curriculum_stage_id' => null]);
        $this->assertSame('Academic placement is incomplete: no Study Plan stage has been recorded for this student.', $this->evaluate(self::O_YEAR1, $noStage)->message);
    }

    public function test_historical_or_non_current_cohort_membership_does_not_grant_eligibility(): void
    {
        foreach (['transferred' => now(), 'withdrawn' => now(), 'completed' => now(), 'deferred' => null] as $status => $endedAt) {
            $student = $this->placedStudent(stage: 1);
            $membershipId = (int) DB::table('student_curriculum_assignments')->where('student_id', $student->id)->value('programme_cohort_membership_id');
            DB::table('programme_cohort_memberships')->where('id', $membershipId)->update(['status' => $status, 'ended_at' => $endedAt]);

            $this->assertSame('cohort_not_current', $this->evaluate(self::O_YEAR1, $student)->code, $status);
            $this->assertFalse($this->roster(self::O_YEAR1)->contains('id', $student->id), $status);
        }

        $endedPlacement = $this->placedStudent(stage: 1);
        DB::table('student_curriculum_assignments')->where('student_id', $endedPlacement->id)->update(['ended_at' => now()]);
        $this->assertSame('placement_ended', $this->evaluate(self::O_YEAR1, $endedPlacement)->code);
    }

    public function test_transferred_historical_cohort_does_not_override_the_current_placement(): void
    {
        // Historical Year-3-plan cohort membership (transferred out) plus a current Year 1 placement.
        $student = $this->placedStudent(stage: 1);
        DB::table('programme_cohort_memberships')->insert(['school_id' => $this->school, 'student_id' => $student->id, 'programme_cohort_id' => 2, 'status' => 'transferred', 'started_at' => now()->subYear(), 'ended_at' => now()->subMonth()]);

        $this->assertTrue($this->evaluate(self::O_YEAR1, $student)->eligible);
        $this->assertSame('stage_mismatch', $this->evaluate(self::O_YEAR3, $student)->code);

        // A placement still linked to a transferred membership grants nothing even if a newer membership exists.
        $stale = $this->placedStudent(stage: 1);
        $old = (int) DB::table('student_curriculum_assignments')->where('student_id', $stale->id)->value('programme_cohort_membership_id');
        DB::table('programme_cohort_memberships')->where('id', $old)->update(['status' => 'transferred', 'ended_at' => now()]);
        DB::table('programme_cohort_memberships')->insert(['school_id' => $this->school, 'student_id' => $stale->id, 'programme_cohort_id' => 1, 'status' => 'active', 'started_at' => now()]);
        $this->assertSame('cohort_not_current', $this->evaluate(self::O_YEAR1, $stale)->code);
    }

    public function test_eligible_student_roster_uses_the_same_rule(): void
    {
        $year1 = $this->placedStudent(stage: 1);
        $year2 = $this->placedStudent(stage: 2);
        $year3 = $this->placedStudent(stage: 3);

        $this->assertSame([$year1->id], $this->roster(self::O_YEAR1)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$year2->id], $this->roster(self::O_YEAR2)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$year3->id], $this->roster(self::O_YEAR3)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(111, (int) $this->roster(self::O_YEAR1)->first()->roster_membership_id);
    }

    public function test_admin_registration_uses_the_same_rule_with_human_messages(): void
    {
        $admin = $this->makeAdminUser($this->school);
        $student = $this->placedStudent(stage: 1);

        $this->actingAs($admin)->from(route('admin.course_offerings.eligible_students', self::O_YEAR3))
            ->post(route('admin.course_offerings.registrations.store', self::O_YEAR3), ['student_id' => $student->id])
            ->assertRedirect()
            ->assertSessionHasErrors(['registration' => "Not eligible — this Course Unit belongs to Year 3 of the student's Study Plan; the student is placed in Year 1."]);
        $this->assertSame(0, CourseRegistration::query()->count());

        $this->actingAs($admin)->post(route('admin.course_offerings.registrations.store', self::O_YEAR1), ['student_id' => $student->id])
            ->assertRedirect(route('admin.course_offerings.registrations', self::O_YEAR1));
        $this->assertSame(111, (int) CourseRegistration::query()->sole()->curriculum_membership_id);

        $this->actingAs($admin)->post(route('admin.course_offerings.registrations.store', self::O_YEAR1), ['student_id' => $student->id])
            ->assertSessionHasErrors(['registration' => 'This student already has a registration record for this Course Offering.']);
        $this->assertSame(1, CourseRegistration::query()->count());
    }

    public function test_student_discovery_and_self_registration_use_the_same_rule(): void
    {
        $student = $this->placedStudent(stage: 1);
        $view = app(StudentCourseOfferingDiscovery::class)->discover($student);
        $this->assertSame('ready', $view['state']);
        $this->assertSame([self::O_YEAR1], $view['offerings']->pluck('offering_id')->map(fn ($id) => (int) $id)->all());

        $this->actingAs($student)->post(route('student.my_courses.register'), ['course_offering_id' => self::O_YEAR3])
            ->assertRedirect(route('student.my_courses'))->assertSessionHas('error');
        $this->assertSame(0, CourseRegistration::query()->count());
        $this->expectDomainFailure(fn () => app(CourseRegistrationService::class)->registerStudentForOffering($this->school, $student->id, self::O_YEAR3, 131, $student->id), 'Year 3');

        $this->actingAs($student)->post(route('student.my_courses.register'), ['course_offering_id' => self::O_YEAR1])
            ->assertRedirect(route('student.my_courses'))->assertSessionHas('message');
        $this->assertSame(self::O_YEAR1, (int) CourseRegistration::query()->sole()->course_offering_id);

        $deferred = $this->placedStudent(stage: 1);
        $membershipId = (int) DB::table('student_curriculum_assignments')->where('student_id', $deferred->id)->value('programme_cohort_membership_id');
        DB::table('programme_cohort_memberships')->where('id', $membershipId)->update(['status' => 'deferred']);
        $blocked = app(StudentCourseOfferingDiscovery::class)->discover($deferred);
        $this->assertSame('placement_incomplete', $blocked['state']);
        $this->assertTrue($blocked['offerings']->isEmpty());
        $this->actingAs($deferred)->get(route('student.my_courses'))->assertOk()
            ->assertSee('Your Programme Cohort record needs review')->assertDontSee('curriculum_membership_id');
    }

    public function test_duplicate_registration_protection_remains_intact(): void
    {
        $student = $this->placedStudent(stage: 1);
        $this->actingAs($student);
        $service = app(CourseRegistrationService::class);
        $first = $service->registerStudentForOffering($this->school, $student->id, self::O_YEAR1);
        $again = $service->registerStudentForOffering($this->school, $student->id, self::O_YEAR1, 111);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, CourseRegistration::query()->count());
        $this->assertFalse($this->roster(self::O_YEAR1)->contains('id', $student->id));

        $service->dropRegistration($this->school, $first->id, $student->id);
        $this->expectDomainFailure(fn () => $service->registerStudentForOffering($this->school, $student->id, self::O_YEAR1), 'dropped registration');
    }

    /**
     * Mirrors the piie_main BBIT3102 finding: a Year-1 student already holding a
     * registration for a Year-3 unit. The rule now refuses it and blocks
     * confirmation (and therefore confirmed-only Live Class access) without
     * modifying the existing record.
     */
    public function test_pre_existing_year1_to_year3_registration_is_detected_and_cannot_be_confirmed(): void
    {
        $student = $this->placedStudent(stage: 1);
        $id = DB::table('course_registrations')->insertGetId([
            'school_id' => $this->school, 'student_id' => $student->id, 'subject_id' => $this->units['Y3'],
            'course_offering_id' => self::O_YEAR3, 'curriculum_membership_id' => 131, 'registered_credits' => '3.00',
            'registered_classification' => 'compulsory', 'status' => 'registered', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = (array) DB::table('course_registrations')->find($id);

        $this->assertSame('stage_mismatch', $this->evaluate(self::O_YEAR3, $student)->code);
        $this->expectDomainFailure(fn () => app(CourseRegistrationService::class)->confirmRegistration($this->school, $id, $student->id), 'Year 3');
        $this->assertSame($before, (array) DB::table('course_registrations')->find($id));
        $this->assertSame(0, CourseRegistration::query()->where('status', CourseRegistration::STATUS_CONFIRMED)->count());
    }

    public function test_eligible_registration_still_confirms_for_confirmed_only_live_class_access(): void
    {
        $student = $this->placedStudent(stage: 1);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering($this->school, $student->id, self::O_YEAR1, null, $student->id);
        $confirmed = $service->confirmRegistration($this->school, $registration->id, $student->id);

        $this->assertSame(CourseRegistration::STATUS_CONFIRMED, $confirmed->status);
    }

    private function expectDomainFailure(callable $attempt, string $messageFragment): void
    {
        try {
            $attempt();
            $this->fail('Expected the eligibility rule to refuse this action.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }

    private function evaluate(int $offeringId, User $student)
    {
        $offering = CourseOffering::query()->whereKey($offeringId)->firstOrFail();

        return app(CourseOfferingEligibility::class)->evaluate($offering, (int) $student->id);
    }

    private function roster(int $offeringId)
    {
        return app(CourseOfferingRoster::class)->eligible(CourseOffering::query()->whereKey($offeringId)->firstOrFail());
    }
}
