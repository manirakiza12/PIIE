<?php

namespace Tests\Feature;

use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingService;
use App\Support\CourseRegistration\CourseRegistrationService;
use App\Support\CourseRegistration\CourseOfferingRoster;
use App\Support\CourseRegistration\StudentCourseOfferingDiscovery;
use App\Support\CourseRegistration\StudentCourseCatalogue;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class CourseRegistrationOfferingFoundationTest extends TestCase
{
    use AdmissionsTestHelper;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->unsignedBigInteger('curriculum_membership_id')->nullable();
            $table->decimal('registered_credits', 6, 2)->unsigned()->nullable();
            $table->string('registered_classification')->nullable();
            $table->string('status', 20)->default('registered');
            $table->timestamps();
            $table->unique(['school_id', 'student_id', 'course_offering_id']);
        });
        $this->createAcademicFixtureTables();
        $this->fixture = $this->makeAcademicFixture();
    }

    public function test_registration_is_tenant_scoped_idempotent_and_snapshots_membership_without_legacy_session(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        $again = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);

        $this->assertSame($registration->id, $again->id);
        $this->assertSame('registered', $registration->status);
        $this->assertSame(null, $registration->session_id);
        $this->assertSame('12.50', $registration->registered_credits);
        $this->assertSame('compulsory', $registration->registered_classification);
        $this->assertTrue($registration->isOfferingBacked());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'COURSE_REGISTRATION_CREATED')->count());
    }

    public function test_administrator_roster_uses_assignment_applicability_and_existing_registration_service(): void
    {
        $offering = \App\Models\CourseOffering::query()->where('school_id', 1)->whereKey($this->fixture['offering'])->firstOrFail();
        $roster = app(CourseOfferingRoster::class);

        $eligible = $roster->eligible($offering);
        $this->assertSame([(int) $this->fixture['student']->id], $eligible->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame((int) $this->fixture['membership'], (int) $eligible->first()->roster_membership_id);

        $registration = app(CourseRegistrationService::class)->registerStudentForOffering(
            1,
            (int) $this->fixture['student']->id,
            (int) $this->fixture['offering'],
            (int) $eligible->first()->roster_membership_id,
            (int) $this->fixture['student']->id,
        );

        $this->assertSame('registered', $registration->status);
        $this->assertTrue($roster->eligible($offering)->isEmpty());
        $registered = $roster->registered($offering)->sole();
        $this->assertSame('Offering Student', $registered->student_name);
        $this->assertSame('1', (string) $registered->curriculum_version);
        $this->assertSame('registered', $registered->status);
    }

    public function test_cross_tenant_student_offering_and_arbitrary_membership_are_rejected(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $this->expectException(DomainException::class);
        $service->registerStudentForOffering(1, $this->fixture['foreign_student']->id, $this->fixture['offering'], $this->fixture['membership']);
    }

    public function test_foreign_offering_subject_membership_and_membership_subject_mismatch_are_rejected(): void
    {
        $this->actingAs($this->fixture['student']);
        $foreignSchool = (int) DB::table('schools')->where('id', '<>', 1)->value('id');
        $foreignSubject = (int) DB::table('subjects')->insertGetId(['name' => 'Foreign subject', 'credits' => 3, 'course_type' => 'compulsory', 'pass_mark' => 50, 'school_id' => $foreignSchool]);
        DB::table('course_offerings')->insert(['id' => 20, 'school_id' => $foreignSchool, 'subject_id' => $foreignSubject, 'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => 'open']);
        DB::table('course_offerings')->insert(['id' => 21, 'school_id' => 1, 'subject_id' => $foreignSubject, 'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => 'open']);
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => 1, 'course_offering_id' => 21, 'curriculum_id' => 1, 'curriculum_membership_id' => $this->fixture['membership'], 'subject_id' => $foreignSubject]);

        $service = app(CourseRegistrationService::class);
        foreach ([
            fn () => $service->registerStudentForOffering(1, $this->fixture['student']->id, 20, $this->fixture['membership']),
            fn () => $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], 999),
            fn () => $service->registerStudentForOffering(1, $this->fixture['student']->id, 21, $this->fixture['membership']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Mismatched tenant/Offering/Membership context must be rejected.');
            } catch (DomainException) {
                $this->assertSame(0, CourseRegistration::count());
            }
        }
    }

    public function test_offering_status_and_membership_applicability_are_authoritative(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $this->expectException(DomainException::class);
        $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['draft_offering'], $this->fixture['membership']);
    }

    public function test_registration_lifecycle_finance_gate_and_immutable_confirmed_provenance(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        DB::table('student_fee_managers')->insert([
            'title' => 'Tuition', 'total_amount' => 100, 'amount' => 100, 'class_id' => 0,
            'student_id' => $this->fixture['student']->id, 'payment_method' => 'offline', 'paid_amount' => 20,
            'status' => 'unpaid', 'school_id' => 1, 'session_id' => 1,
        ]);
        try {
            $service->confirmRegistration(1, $registration->id);
            $this->fail('Outstanding balance must prevent confirmation.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('registered', $registration->fresh()->status);
        }
        DB::table('student_fee_managers')->where('student_id', $this->fixture['student']->id)->update(['paid_amount' => 100]);
        $service->confirmRegistration(1, $registration->id);
        $confirmed = $registration->fresh();
        $this->assertSame('confirmed', $confirmed->status);
        try {
            $confirmed->registered_credits = 99;
            $confirmed->save();
            $this->fail('Confirmed provenance must be immutable.');
        } catch (DomainException) {
            $this->assertSame('12.50', $confirmed->fresh()->registered_credits);
        }
        $service->dropRegistration(1, $registration->id, null, 'Student withdrawal');
        $this->assertSame('dropped', $registration->fresh()->status);
        $this->assertSame(2, DB::table('audit_logs')->whereIn('action', ['COURSE_REGISTRATION_CONFIRMED', 'COURSE_REGISTRATION_DROPPED'])->count());
    }

    public function test_in_progress_allows_drop_but_not_registration_or_confirmation_and_cancel_preserves_history(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        DB::table('course_offerings')->where('id', $this->fixture['offering'])->update(['status' => 'in_progress']);
        try {
            $service->confirmRegistration(1, $registration->id);
            $this->fail('In-progress offering cannot confirm.');
        } catch (DomainException) {
            $this->assertSame('registered', $registration->fresh()->status);
        }
        $service->dropRegistration(1, $registration->id);
        $this->assertSame('dropped', $registration->fresh()->status);
        DB::table('course_offerings')->where('id', $this->fixture['offering'])->update(['status' => 'cancelled']);
        $this->assertSame('dropped', $registration->fresh()->status);
    }

    public function test_completed_and_cancelled_offerings_are_read_only_for_registration_history(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        DB::table('course_offerings')->where('id', $this->fixture['offering'])->update(['status' => 'completed']);
        foreach ([
            fn () => $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']),
            fn () => $service->confirmRegistration(1, $registration->id),
            fn () => $service->dropRegistration(1, $registration->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Completed Offering registration history must be read-only.');
            } catch (DomainException) {
                $this->assertSame('registered', $registration->fresh()->status);
            }
        }
        DB::table('course_offerings')->where('id', $this->fixture['offering'])->update(['status' => 'cancelled']);
        $this->expectException(DomainException::class);
        $service->dropRegistration(1, $registration->id);
    }

    public function test_profile_programme_mismatch_rejects_and_other_curriculum_applicability_is_ignored(): void
    {
        $this->actingAs($this->fixture['student']);
        DB::table('student_profiles')->where('user_id', $this->fixture['student']->id)->update(['programme_id' => $this->fixture['other_programme']]);
        try {
            app(CourseRegistrationService::class)->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
            $this->fail('Programme mismatch must reject registration.');
        } catch (DomainException) {
            $this->assertSame(0, CourseRegistration::count());
        }

        DB::table('student_profiles')->where('user_id', $this->fixture['student']->id)->update(['programme_id' => null]);
        DB::table('curricula')->insert(['id' => 3, 'school_id' => 1, 'programme_id' => 1, 'version' => '2', 'status' => 'approved']);
        DB::table('curriculum_memberships')->insert(['id' => 3, 'school_id' => 1, 'curriculum_id' => 3, 'subject_id' => $this->fixture['subject'], 'curriculum_stage_id' => 3, 'classification' => 'elective', 'credits' => 6, 'sequence' => 1]);
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => 1, 'course_offering_id' => $this->fixture['offering'], 'curriculum_id' => 3, 'curriculum_membership_id' => 3, 'subject_id' => $this->fixture['subject']]);
        $registration = app(CourseRegistrationService::class)->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        $this->assertSame(1, (int) $registration->curriculum_membership_id);
    }

    public function test_shared_offering_uses_membership_for_the_placement_governing_its_academic_year(): void
    {
        $student = $this->fixture['student'];
        $this->actingAs($student);
        $service = app(CourseRegistrationService::class);
        $oldYear = $service->registerStudentForOffering(1, $student->id, $this->fixture['offering'], 1);
        $this->assertSame(1, (int) $oldYear->curriculum_membership_id);

        // A later governed placement (new cohort membership, explicit stage) takes effect in year 2.
        $programme = (int) DB::table('curricula')->where('id', 1)->value('programme_id');
        DB::table('academic_years')->insert(['id' => 2, 'school_id' => 1, 'label' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        DB::table('curricula')->insert(['id' => 2, 'school_id' => 1, 'programme_id' => $programme, 'version' => '2', 'status' => 'approved']);
        DB::table('curriculum_stages')->insert(['id' => 2, 'school_id' => 1, 'curriculum_id' => 2, 'label' => 'Year 2', 'sequence' => 2]);
        DB::table('curriculum_memberships')->insert(['id' => 2, 'school_id' => 1, 'curriculum_id' => 2, 'subject_id' => $this->fixture['subject'], 'curriculum_stage_id' => 2, 'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'elective', 'credits' => 6, 'sequence' => 1]);
        DB::table('academic_periods')->insert(['id' => 5, 'school_id' => 1, 'academic_year_id' => 2, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'status' => 'active']);
        DB::table('course_offerings')->where('id', $this->fixture['second_offering'])->update(['academic_year_id' => 2, 'academic_period_id' => 5]);
        DB::table('course_offering_curriculum_memberships')->where('course_offering_id', $this->fixture['second_offering'])->delete();
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => 1, 'course_offering_id' => $this->fixture['second_offering'], 'curriculum_id' => 2, 'curriculum_membership_id' => 2, 'subject_id' => $this->fixture['subject']]);
        DB::table('programme_cohort_memberships')->where('id', 1)->update(['status' => 'transferred', 'ended_at' => now()]);
        DB::table('student_curriculum_assignments')->where('student_id', $student->id)->update(['ended_at' => now()]);
        DB::table('programme_cohorts')->insert(['id' => 2, 'school_id' => 1, 'programme_id' => $programme, 'curriculum_id' => 2, 'entry_academic_year_id' => 2, 'name' => 'Cohort 2026', 'code' => 'C-2026', 'status' => 'active']);
        DB::table('programme_cohort_memberships')->insert(['id' => 2, 'school_id' => 1, 'student_id' => $student->id, 'programme_cohort_id' => 2, 'status' => 'active', 'started_at' => now()]);
        DB::table('student_curriculum_assignments')->insert(['school_id' => 1, 'student_id' => $student->id, 'programme_id' => $programme, 'curriculum_id' => 2, 'entry_academic_year_id' => 2, 'effective_from_academic_year_id' => 2, 'programme_cohort_membership_id' => 2, 'entry_curriculum_stage_id' => 2, 'assigned_by' => $student->id]);

        $newYear = $service->registerStudentForOffering(1, $student->id, $this->fixture['second_offering'], 2);
        $this->assertSame(2, (int) $newYear->curriculum_membership_id);
        $this->assertSame('6.00', $newYear->registered_credits);
        $this->assertSame('elective', $newYear->registered_classification);

        // The ended year-1 placement grants nothing further, including confirmation.
        try {
            $service->confirmRegistration(1, $oldYear->id, (int) $student->id);
            $this->fail('An ended placement must not confirm registration.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('no longer current', $exception->getMessage());
            $this->assertSame('registered', $oldYear->fresh()->status);
        }
    }

    public function test_discovery_uses_current_assignment_context_and_keeps_parallel_offerings_separate(): void
    {
        $student = $this->fixture['student'];
        $programme = (int) DB::table('curricula')->where('id', 1)->value('programme_id');
        DB::table('curricula')->insert(['id' => 3, 'school_id' => 1, 'programme_id' => $programme, 'version' => 'unrelated', 'status' => 'approved']);
        DB::table('curriculum_memberships')->insert(['id' => 3, 'school_id' => 1, 'curriculum_id' => 3, 'subject_id' => $this->fixture['subject'], 'curriculum_stage_id' => 3, 'classification' => 'elective', 'credits' => 9, 'sequence' => 1]);
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => 1, 'course_offering_id' => $this->fixture['offering'], 'curriculum_id' => 3, 'curriculum_membership_id' => 3, 'subject_id' => $this->fixture['subject']]);
        $view = app(StudentCourseOfferingDiscovery::class)->discover($student);
        $this->assertSame('ready', $view['state']);
        $this->assertSame([1, 2], $view['offerings']->pluck('offering_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([1], $view['offerings']->pluck('curriculum_membership_id')->unique()->map(fn ($id) => (int) $id)->all());

        $this->actingAs($student);
        // The view now builds its cards from `StudentCourseCatalogue`, so a direct
        // render must supply it the way `StudentController::myCourses()` does. What
        // this test is about is unchanged: parallel Offerings stay separate, and the
        // register form still submits only the Offering id.
        $catalogue = app(StudentCourseCatalogue::class, ['schoolId' => 1])->build($view);
        $html = view('student.my_courses', $view + [
            'catalogue' => $catalogue,
            'workflow' => 'hei',
            'courseUnitLabel' => 'Course Unit',
        ])->render();
        $this->assertStringContainsString('name="course_offering_id"', $html);
        $this->assertStringNotContainsString('name="curriculum_membership_id"', $html);

        $registration = app(CourseRegistrationService::class)->registerStudentForOffering(1, $student->id, 1, 1, $student->id);
        $afterRegistration = app(StudentCourseOfferingDiscovery::class)->discover($student);
        $this->assertSame([2], $afterRegistration['offerings']->pluck('offering_id')->map(fn ($id) => (int) $id)->all());
        $this->assertCount(1, $afterRegistration['pending']);
        $this->assertSame($registration->id, $afterRegistration['pending']->first()->id);
        DB::table('student_curriculum_assignments')->where('student_id', $student->id)->delete();
        $withoutAssignment = app(StudentCourseOfferingDiscovery::class)->discover($student);
        $this->assertSame('assignment_missing', $withoutAssignment['state']);
        $this->assertSame($registration->id, $withoutAssignment['pending']->first()->id);
    }

    public function test_student_route_submits_only_offering_and_blocks_self_withdrawal_after_start(): void
    {
        $student = $this->fixture['student'];
        $this->actingAs($student);
        $this->get(route('student.my_courses'))->assertOk()->assertSee('Available to Register');
        $response = $this->post(route('student.my_courses.register'), ['course_offering_id' => $this->fixture['offering']]);
        $response->assertRedirect(route('student.my_courses'));
        $registration = CourseRegistration::query()->where('school_id', 1)->where('student_id', $student->id)->firstOrFail();
        $this->assertSame(1, (int) $registration->curriculum_membership_id);
        $this->assertSame('registered', $registration->status);

        DB::table('course_offerings')->where('id', $this->fixture['offering'])->update(['status' => 'in_progress']);
        $this->post(route('student.my_courses.drop', $registration->id))->assertRedirect()->assertSessionHas('error');
        $this->assertSame('registered', $registration->fresh()->status);
    }

    public function test_discovery_surfaces_assignment_profile_period_and_offering_states_without_fallback(): void
    {
        $student = $this->fixture['student'];
        DB::table('student_profiles')->where('user_id', $student->id)->update(['programme_id' => $this->fixture['other_programme']]);
        $this->assertSame('programme_mismatch', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);

        $programme = (int) DB::table('curricula')->where('id', 1)->value('programme_id');
        DB::table('student_profiles')->where('user_id', $student->id)->update(['programme_id' => $programme]);
        DB::table('academic_periods')->insert(['id' => 2, 'school_id' => 1, 'academic_year_id' => 1, 'type' => 'semester', 'label' => 'Semester 2', 'sequence' => 2, 'start_date' => '2025-07-01', 'end_date' => '2025-12-31', 'status' => 'active']);
        DB::table('schools')->where('id', 1)->update(['current_academic_period_id' => 2]);
        $this->assertSame('no_offerings', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);
        DB::table('schools')->where('id', 1)->update(['current_academic_period_id' => null]);
        $this->assertSame('no_period', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);
        DB::table('schools')->where('id', 1)->update(['current_academic_year_id' => null, 'current_academic_period_id' => null]);
        $this->assertSame('no_year', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);
        DB::table('schools')->where('id', 1)->update(['current_academic_year_id' => 1, 'current_academic_period_id' => 1]);
        DB::table('student_curriculum_assignments')->where('student_id', $student->id)->delete();
        $this->assertSame('assignment_missing', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);

        DB::table('student_curriculum_assignments')->insert(['school_id' => 1, 'student_id' => $student->id, 'programme_id' => $programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'programme_cohort_membership_id' => 1, 'entry_curriculum_stage_id' => 1, 'assigned_by' => $student->id]);
        DB::table('course_offerings')->whereIn('id', [$this->fixture['offering'], $this->fixture['second_offering']])->update(['status' => 'completed']);
        $this->assertSame('no_offerings', app(StudentCourseOfferingDiscovery::class)->discover($student)['state']);
    }

    public function test_student_may_retake_in_another_offering_but_dropped_registration_cannot_be_reopened(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $first = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        $service->dropRegistration(1, $first->id);
        $second = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['second_offering'], $this->fixture['membership']);
        $this->assertNotSame($first->id, $second->id);
        $this->expectException(DomainException::class);
        $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
    }

    public function test_cross_tenant_registration_lookup_and_student_self_ownership_are_enforced(): void
    {
        $this->actingAs($this->fixture['student']);
        $service = app(CourseRegistrationService::class);
        $registration = $service->registerStudentForOffering(1, $this->fixture['student']->id, $this->fixture['offering'], $this->fixture['membership']);
        try {
            $service->dropRegistration(2, $registration->id);
            $this->fail('Cross-tenant registration lookup must fail.');
        } catch (DomainException) {
            $this->assertSame('registered', $registration->fresh()->status);
        }
        $this->expectException(DomainException::class);
        $service->dropRegistration(1, $registration->id, $this->fixture['foreign_student']->id);
    }

    private function createAcademicFixtureTables(): void
    {
        Schema::create('academic_years', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->string('label'); $table->date('start_date'); $table->date('end_date'); $table->string('status'); $table->timestamps(); });
        Schema::create('academic_periods', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id'); $table->string('type'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->string('status')->default('active'); $table->timestamps(); });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('user_id'); $table->string('role'); $table->date('starts_on'); $table->date('ends_on')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('student_curriculum_assignments', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->unsignedBigInteger('effective_from_academic_year_id'); $table->unsignedBigInteger('assigned_by'); $table->timestamp('ended_at')->nullable(); $table->string('reason')->nullable(); $table->unsignedBigInteger('programme_cohort_membership_id')->nullable(); $table->unsignedBigInteger('entry_curriculum_stage_id')->nullable(); $table->timestamps(); });
        Schema::create('programme_cohorts', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->string('name'); $table->string('code'); $table->string('status'); $table->timestamps(); });
        Schema::create('programme_cohort_memberships', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_cohort_id'); $table->string('status'); $table->dateTime('started_at'); $table->dateTime('ended_at')->nullable(); $table->timestamps(); });
        Schema::create('course_offerings', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('academic_year_id'); $table->unsignedBigInteger('academic_period_id'); $table->string('reference')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('curriculum_membership_id'); $table->unsignedBigInteger('subject_id'); $table->timestamps(); $table->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']); });
        Schema::create('curricula', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->string('version'); $table->unsignedBigInteger('effective_academic_year_id')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('curriculum_stage_id'); $table->string('period_type')->nullable(); $table->unsignedSmallInteger('period_sequence')->nullable(); $table->string('classification'); $table->decimal('credits', 6, 2); $table->unsignedSmallInteger('sequence')->default(0); $table->timestamps(); });
        Schema::create('curriculum_stages', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->timestamps(); });
    }

    private function makeAcademicFixture(): array
    {
        $school = $this->makeSchool();
        $foreignSchool = $this->makeSchool();
        $programme = $this->makeProgramme($school);
        DB::table('academic_years')->insert(['id' => 1, 'school_id' => $school, 'label' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('academic_periods')->insert(['id' => 1, 'school_id' => $school, 'academic_year_id' => 1, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2025-01-01', 'end_date' => '2025-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('schools')->where('id', $school)->update(['current_academic_year_id' => 1, 'current_academic_period_id' => 1]);
        $student = User::create(['name' => 'Offering Student', 'email' => 'offering-student@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $school]);
        $foreignStudent = User::create(['name' => 'Foreign Student', 'email' => 'foreign-student@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $foreignSchool]);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $school, 'programme_id' => $programme, 'created_at' => now(), 'updated_at' => now()]);
        $subject = (int) DB::table('subjects')->insertGetId(['name' => 'Research', 'code' => 'R101', 'credits' => 3, 'course_type' => 'compulsory', 'pass_mark' => 50, 'programme_id' => $programme, 'school_id' => $school, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curricula')->insert(['id' => 1, 'school_id' => $school, 'programme_id' => $programme, 'version' => '1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curriculum_stages')->insert(['id' => 1, 'school_id' => $school, 'curriculum_id' => 1, 'label' => 'Year 1', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curriculum_memberships')->insert(['id' => 1, 'school_id' => $school, 'curriculum_id' => 1, 'subject_id' => $subject, 'curriculum_stage_id' => 1, 'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'compulsory', 'credits' => '12.50', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('programme_cohorts')->insert(['id' => 1, 'school_id' => $school, 'programme_id' => $programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'name' => 'Cohort 2025', 'code' => 'C-2025', 'status' => 'active']);
        DB::table('programme_cohort_memberships')->insert(['id' => 1, 'school_id' => $school, 'student_id' => $student->id, 'programme_cohort_id' => 1, 'status' => 'active', 'started_at' => now()]);
        DB::table('student_curriculum_assignments')->insert(['school_id' => $school, 'student_id' => $student->id, 'programme_id' => $programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'programme_cohort_membership_id' => 1, 'entry_curriculum_stage_id' => 1, 'assigned_by' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'open', 2 => 'open', 3 => 'draft'] as $id => $status) {
            DB::table('course_offerings')->insert(['id' => $id, 'school_id' => $school, 'subject_id' => $subject, 'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1, 2] as $offeringId) {
            DB::table('course_offering_curriculum_memberships')->insert(['school_id' => $school, 'course_offering_id' => $offeringId, 'curriculum_id' => 1, 'curriculum_membership_id' => 1, 'subject_id' => $subject, 'created_at' => now(), 'updated_at' => now()]);
        }
        return ['student' => $student, 'foreign_student' => $foreignStudent, 'subject' => $subject, 'membership' => 1, 'offering' => 1, 'second_offering' => 2, 'draft_offering' => 3, 'other_programme' => $this->makeProgramme($school, ['name' => 'Other Programme'])];
    }
}
