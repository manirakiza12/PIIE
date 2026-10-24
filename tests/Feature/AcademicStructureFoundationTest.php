<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Session;
use App\Http\Controllers\AcademicStructureController;
use App\Support\AcademicContext;
use App\Support\AcademicContextManager;
use App\Support\Academic\AcademicRecordDeletionService;
use App\Support\CourseOffering\CourseOfferingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AcademicStructureFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('status')->nullable();
            $table->unsignedBigInteger('running_session')->nullable();
            $table->string('academic_calendar_pattern')->nullable();
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
            $table->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('label');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestamps();
            $table->unique(['school_id', 'id']);
            $table->foreign('school_id')->references('id')->on('schools');
        });
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('type');
            $table->string('label');
            $table->unsignedSmallInteger('sequence');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
            $table->timestamps();
            $table->unique(['school_id', 'academic_year_id', 'id']);
            $table->foreign(['school_id', 'academic_year_id'])->references(['school_id', 'id'])->on('academic_years');
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->foreign(['id', 'current_academic_year_id'])->references(['school_id', 'id'])->on('academic_years');
            $table->foreign(['id', 'current_academic_year_id', 'current_academic_period_id'])->references(['school_id', 'academic_year_id', 'id'])->on('academic_periods');
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_title');
            $table->integer('status');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->timestamps();
            $table->foreign('academic_year_id')->references('id')->on('academic_years');
        });
        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value');
        });
        DB::table('global_settings')->insert(['key' => 'running_session', 'value' => '777']);
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id'); $table->unsignedBigInteger('academic_period_id');
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->nullable(); $table->unsignedBigInteger('user_id');
            $table->string('user_name')->nullable(); $table->unsignedBigInteger('role_id')->nullable(); $table->string('role_name')->nullable();
            $table->string('action')->nullable(); $table->string('event_type')->nullable(); $table->string('module')->nullable();
            $table->text('description')->nullable(); $table->string('record_type')->nullable(); $table->unsignedBigInteger('record_id')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function school(string $pattern): School
    {
        return School::query()->create([
            'title' => 'Academic Test '.uniqid(),
            'status' => 1,
            'academic_calendar_pattern' => $pattern,
        ]);
    }

    private function year(School $school, string $label = '2026/2027'): AcademicYear
    {
        return AcademicYear::query()->create([
            'school_id' => $school->id, 'label' => $label,
            'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'status' => 'planned',
        ]);
    }

    private function period(AcademicYear $year, string $type, string $label = 'Period 1'): AcademicPeriod
    {
        return AcademicPeriod::query()->create([
            'school_id' => $year->school_id, 'academic_year_id' => $year->id,
            'type' => $type, 'label' => $label, 'sequence' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2027-01-15', 'status' => 'planned',
        ]);
    }

    private function controllerFor(School $school): AcademicStructureController
    {
        $controller = new AcademicStructureController();
        $property = new \ReflectionProperty($controller, 'schoolId');
        $property->setValue($controller, (int) $school->id);

        return $controller;
    }

    public function test_day_first_dates_are_saved_as_iso_without_swapping_month_and_day(): void
    {
        $school = $this->school('semester');
        $controller = $this->controllerFor($school);
        $controller->storeYear(Request::create('/', 'POST', ['label' => 'October intake', 'start_date' => '01/10/26', 'end_date' => '30/09/27']));
        $year = AcademicYear::where('school_id', $school->id)->firstOrFail();
        $this->assertSame('2026-10-01', $year->start_date->toDateString());
        $this->assertSame('2027-09-30', $year->end_date->toDateString());
        $controller->storePeriod(Request::create('/', 'POST', ['academic_year_id' => $year->id, 'type' => 'semester', 'sequence' => 1,
            'label' => 'Semester 1', 'start_date' => '01/10/26', 'end_date' => '31/03/27']));
        $this->assertSame('2027-03-31', AcademicPeriod::firstOrFail()->end_date->toDateString());
        $controller->update(Request::create('/', 'PUT', ['label' => 'October intake revised', 'start_date' => '01/10/26', 'end_date' => '30/09/27']), 'years', $year->id);
        $this->assertSame('October intake revised', $year->fresh()->label);
    }

    public function test_years_and_periods_in_all_lifecycle_states_can_be_deleted_with_owned_rows(): void
    {
        $deletion = new AcademicRecordDeletionService(new CourseOfferingService());
        Schema::create('academic_year_keyless_workflows', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_year_id');
            $table->string('workflow_key');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->onDelete('restrict');
            $table->primary(['academic_year_id', 'workflow_key']);
        });
        Schema::create('academic_period_keyless_workflows', function (Blueprint $table): void {
            $table->unsignedBigInteger('academic_period_id');
            $table->string('workflow_key');
            $table->foreign('academic_period_id')->references('id')->on('academic_periods')->onDelete('restrict');
            $table->primary(['academic_period_id', 'workflow_key']);
        });
        foreach (['planned', 'active', 'completed', 'cancelled'] as $status) {
            $school = $this->school('semester');
            $year = $this->year($school, 'Year '.$status);
            $year->status = $status; $year->save();
            $period = $this->period($year, 'semester', 'Semester '.$status);
            $period->status = $status; $period->save();
            DB::table('academic_year_keyless_workflows')->insert(['academic_year_id' => $year->id, 'workflow_key' => 'workflow-'.$status]);
            DB::table('academic_period_keyless_workflows')->insert(['academic_period_id' => $period->id, 'workflow_key' => 'workflow-'.$status]);
            DB::table('sessions')->insert(['session_title' => 'Legacy '.$status, 'status' => 1, 'school_id' => $school->id, 'academic_year_id' => $year->id]);
            DB::table('schools')->where('id', $school->id)->update(['current_academic_year_id' => $year->id, 'current_academic_period_id' => $period->id]);

            try { $deletion->deletePeriod((int) $school->id, (int) $period->id, 1, 'wrong'); $this->fail('Invalid confirmation deleted a period.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('confirm_deletion', $exception->errors()); }
            $deletion->deletePeriod((int) $school->id, (int) $period->id, 1, 'DELETE');
            $this->assertDatabaseMissing('academic_periods', ['id' => $period->id]);
            $this->assertDatabaseMissing('academic_period_keyless_workflows', ['academic_period_id' => $period->id]);
            $deletion->deleteYear((int) $school->id, (int) $year->id, 1, 'DELETE');
            $this->assertDatabaseMissing('academic_years', ['id' => $year->id]);
            $this->assertDatabaseMissing('academic_year_keyless_workflows', ['academic_year_id' => $year->id]);
            $this->assertDatabaseMissing('sessions', ['academic_year_id' => $year->id]);
            $this->assertNull(DB::table('schools')->where('id', $school->id)->value('current_academic_year_id'));
            $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_YEAR_DELETED', 'user_id' => 1, 'record_id' => $year->id]);
        }
    }

    public function test_academic_deletion_cannot_cross_tenant_boundaries(): void
    {
        $schoolA = $this->school('semester'); $schoolB = $this->school('semester');
        $yearA = $this->year($schoolA, 'Tenant A year');
        $deletion = new AcademicRecordDeletionService(new CourseOfferingService());
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $deletion->deleteYear((int) $schoolB->id, (int) $yearA->id, 1, 'DELETE');
    }

    public function test_year_and_period_edits_save_in_each_lifecycle_state(): void
    {
        foreach (['planned', 'active', 'completed', 'cancelled'] as $status) {
            $school = $this->school('semester'); $controller = $this->controllerFor($school);
            $year = $this->year($school, 'Academic '.$status);
            DB::table('academic_years')->where('id', $year->id)->update(['status' => $status]);
            $period = $this->period($year, 'semester', 'Period '.$status);
            DB::table('academic_periods')->where('id', $period->id)->update(['status' => $status]);
            $controller->update(Request::create('/', 'PUT', [
                'label' => 'Edited '.$status, 'start_date' => '01/09/26', 'end_date' => '31/08/27',
            ]), 'years', (int) $year->id);
            $controller->update(Request::create('/', 'PUT', [
                'label' => 'Edited period '.$status, 'academic_year_id' => $year->id,
                'type' => 'semester', 'sequence' => 1, 'start_date' => '01/09/26', 'end_date' => '15/01/27',
            ]), 'periods', (int) $period->id);
            $this->assertSame('Edited '.$status, $year->fresh()->label);
            $this->assertSame('Edited period '.$status, $period->fresh()->label);
            $this->assertSame($status, $year->fresh()->status);
            $this->assertSame($status, $period->fresh()->status);
        }
    }

    public function test_discovered_restrictive_dependency_is_deleted_before_its_year(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        Schema::create('opaque_year_reference', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_year_id');
            $table->foreign('owner_year_id')->references('id')->on('academic_years')->onDelete('restrict');
        });
        $school = $this->school('semester'); $year = $this->year($school, 'Protected year');
        DB::table('schools')->where('id', $school->id)->update(['current_academic_year_id' => $year->id]);
        DB::table('opaque_year_reference')->insert(['owner_year_id' => $year->id]);
        $deletion = new AcademicRecordDeletionService(new CourseOfferingService());
        $deletion->deleteYear((int) $school->id, (int) $year->id, 1, 'DELETE');
        $this->assertDatabaseMissing('academic_years', ['id' => $year->id]);
        $this->assertDatabaseMissing('opaque_year_reference', ['owner_year_id' => $year->id]);
    }

    public function test_year_cleanup_rolls_back_when_the_final_audit_write_fails(): void
    {
        $school = $this->school('semester');
        $year = $this->year($school, 'Rollback year');
        DB::table('sessions')->insert([
            'session_title' => 'Rollback session', 'status' => 1,
            'school_id' => $school->id, 'academic_year_id' => $year->id,
        ]);
        $deletion = new AcademicRecordDeletionService(new CourseOfferingService());
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reject_year_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
        } else {
            DB::unprepared("CREATE TRIGGER reject_year_audit BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure'");
        }
        try {
            try {
                $deletion->deleteYear((int) $school->id, (int) $year->id, 1, 'DELETE');
                $this->fail('A rejected audit write should roll back the deletion.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
                $this->assertDatabaseHas('sessions', ['academic_year_id' => $year->id]);
                $this->assertDatabaseMissing('audit_logs', ['action' => 'ACADEMIC_YEAR_DELETED', 'record_id' => $year->id]);
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_year_audit');
        }
    }

    public function test_day_first_invalid_calendar_dates_are_rejected(): void
    {
        foreach (['29/02/27', '31/04/26', '10/13/26', '10/01/2026', "01\0/10/26"] as $date) {
            try {
                \App\Support\Academic\AcademicDate::normalize(Request::create('/', 'POST', ['start_date' => $date]));
                $this->fail('Invalid date must not be normalised: '.$date);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('start_date', $exception->errors());
                $this->assertStringContainsString('DD/MM/YY', $exception->errors()['start_date'][0]);
            }
        }
        $request = Request::create('/', 'POST', ['start_date' => '29/02/28']);
        \App\Support\Academic\AcademicDate::normalize($request);
        $this->assertSame('2028-02-29', $request->input('start_date'));
    }

    public function test_day_first_reversed_dates_and_outside_periods_still_fail_validation(): void
    {
        $school = $this->school('semester');
        $controller = $this->controllerFor($school);
        try {
            $controller->storeYear(Request::create('/', 'POST', ['label' => 'Invalid', 'start_date' => '30/09/27', 'end_date' => '01/10/26']));
            $this->fail('Reversed dates must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
        }
        $year = $this->year($school);
        try {
            $controller->storePeriod(Request::create('/', 'POST', ['academic_year_id' => $year->id, 'type' => 'semester', 'sequence' => 1,
                'label' => 'Invalid', 'start_date' => '01/09/26', 'end_date' => '01/09/27']));
            $this->fail('A semester outside the year must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('end_date', $exception->errors());
        }
    }

    public function test_calendar_inputs_format_iso_old_input_and_model_dates_consistently(): void
    {
        $year = $this->year($this->school('semester'));
        $this->assertSame('01/09/26', \App\Support\Academic\AcademicDate::input($year->start_date));
        $this->assertSame('01/10/26', \App\Support\Academic\AcademicDate::input('2026-10-01'));
        $this->assertSame('31/02/26', \App\Support\Academic\AcademicDate::input('31/02/26'));
    }

    public function test_years_are_school_owned_and_duplicate_labels_are_tenant_local(): void
    {
        $a = $this->school('semester');
        $b = $this->school('term');
        $yearA = $this->year($a);
        $yearB = $this->year($b);

        $this->assertSame($yearA->label, $yearB->label);
        $this->assertSame((int) $a->id, (int) $yearA->school_id);
        $this->assertSame((int) $b->id, (int) $yearB->school_id);
    }

    public function test_period_is_owned_by_its_year_and_database_rejects_cross_tenant_parent(): void
    {
        $a = $this->school('semester');
        $b = $this->school('term');
        $yearA = $this->year($a);
        $this->period($yearA, 'semester', 'Semester 1');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('academic_periods')->insert([
            'school_id' => $b->id, 'academic_year_id' => $yearA->id, 'type' => 'term',
            'label' => 'Term 1', 'sequence' => 1, 'start_date' => '2026-09-01',
            'end_date' => '2027-01-15', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_period_types_follow_tenant_calendar_configuration(): void
    {
        $semesterSchool = $this->school('semester');
        $termSchool = $this->school('term');
        $semesterYear = $this->year($semesterSchool);
        $termYear = $this->year($termSchool);

        $this->assertTrue(AcademicPeriod::matchesCalendarPattern('semester', 'semester'));
        $this->assertFalse(AcademicPeriod::matchesCalendarPattern('term', 'semester'));
        $this->assertTrue(AcademicPeriod::matchesCalendarPattern('term', 'term'));
        $this->assertFalse(AcademicPeriod::matchesCalendarPattern('quarter', 'quarter'));
        $this->assertSame('semester', $this->period($semesterYear, 'semester', 'Semester 1')->type);
        $this->assertSame('term', $this->period($termYear, 'term', 'Term 1')->type);
    }

    public function test_date_validation_rejects_reversed_year_and_out_of_year_period(): void
    {
        $yearValidator = Validator::make([
            'start_date' => '2027-09-01', 'end_date' => '2027-08-31',
        ], ['start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date']]);
        $this->assertFalse($yearValidator->passes());

        $periodValidator = Validator::make([
            'start_date' => '2026-08-31', 'end_date' => '2027-09-01',
        ], ['start_date' => ['required', 'date', 'after_or_equal:2026-09-01', 'before_or_equal:2027-08-31'], 'end_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:2027-08-31']]);
        $this->assertFalse($periodValidator->passes());
    }

    public function test_current_context_is_tenant_scoped_and_period_can_be_null(): void
    {
        $a = $this->school('semester');
        $b = $this->school('term');
        $yearA = $this->year($a);
        $yearA->update(['status' => 'active']);
        $yearB = $this->year($b);
        $yearB->update(['status' => 'active']);
        $periodA = $this->period($yearA, 'semester');
        $periodA->update(['status' => 'active']);
        $manager = app(AcademicContextManager::class);

        try {
            $manager->setCurrent((int) $a->id, (int) $yearB->id, null);
            $this->fail('A year from another tenant must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_academic_year_id', $exception->errors());
        }
        try {
            $otherPeriod = $this->period($yearB, 'term');
            $otherPeriod->update(['status' => 'active']);
            $manager->setCurrent((int) $a->id, (int) $yearA->id, (int) $otherPeriod->id);
            $this->fail('A period from another tenant must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_academic_period_id', $exception->errors());
        }

        $manager->setCurrent((int) $a->id, (int) $yearA->id, (int) $periodA->id);
        $manager->setCurrent((int) $a->id, (int) $yearA->id, null);
        $a->refresh();
        $this->assertSame((int) $yearA->id, (int) $a->current_academic_year_id);
        $this->assertNull($a->current_academic_period_id);
        $this->assertNull(app(AcademicContext::class)->currentPeriod($a));
    }

    public function test_current_period_must_belong_to_the_selected_year(): void
    {
        $school = $this->school('semester');
        $yearA = $this->year($school, '2026');
        $yearA->update(['status' => 'active']);
        $yearB = $this->year($school, '2027');
        $yearB->update(['status' => 'active']);
        $period = $this->period($yearB, 'semester');
        $period->update(['status' => 'active']);

        try {
            app(AcademicContextManager::class)->setCurrent((int) $school->id, (int) $yearA->id, (int) $period->id);
            $this->fail('A period from a different year must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('current_academic_period_id', $exception->errors());
        }
    }

    public function test_creation_always_starts_planned_and_rejects_submitted_lifecycle_fields(): void
    {
        $school = $this->school('semester');
        $controller = $this->controllerFor($school);
        $yearPayload = ['label' => 'Created Year', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31'];

        $controller->storeYear(Request::create('/', 'POST', $yearPayload));
        $this->assertSame('planned', AcademicYear::query()->where('label', 'Created Year')->value('status'));

        foreach (['planned', 'active', 'completed', 'cancelled'] as $status) {
            try {
                $controller->storeYear(Request::create('/', 'POST', $yearPayload + ['label' => 'Rejected '.$status, 'status' => $status]));
                $this->fail("Year creation must reject submitted status {$status}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        $year = $this->year($school, 'Period Parent');
        $periodPayload = [
            'academic_year_id' => $year->id, 'type' => 'semester', 'label' => 'Created Period', 'sequence' => 1,
            'start_date' => '2026-09-01', 'end_date' => '2027-01-15',
        ];
        $controller->storePeriod(Request::create('/', 'POST', $periodPayload));
        $this->assertSame('planned', AcademicPeriod::query()->where('label', 'Created Period')->value('status'));

        foreach (['active', 'completed', 'cancelled'] as $status) {
            try {
                $controller->storePeriod(Request::create('/', 'POST', $periodPayload + ['label' => 'Rejected '.$status, 'status' => $status]));
                $this->fail("Period creation must reject submitted status {$status}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }

        try {
            $controller->storePeriod(Request::create('/', 'POST', $periodPayload + ['make_current' => '1']));
            $this->fail('A newly planned period cannot be made current during creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('make_current', $exception->errors());
        }
    }

    public function test_cancellation_transition_requires_explicit_confirmation(): void
    {
        $school = $this->school('semester');
        $year = $this->year($school);
        $controller = $this->controllerFor($school);

        try {
            $controller->transitionYear(Request::create('/', 'POST', ['status' => 'cancelled']), (int) $year->id, app(AcademicContextManager::class));
            $this->fail('Cancellation must require explicit confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_cancellation', $exception->errors());
        }

        $controller->transitionYear(Request::create('/', 'POST', ['status' => 'cancelled', 'confirm_cancellation' => '1']), (int) $year->id, app(AcademicContextManager::class));
        $this->assertSame('cancelled', $year->fresh()->status);
    }

    public function test_lifecycle_transitions_allow_only_the_approved_edges_and_multiple_active_records(): void
    {
        $school = $this->school('semester');
        $manager = app(AcademicContextManager::class);
        $yearA = $this->year($school, 'Year A');
        $yearB = $this->year($school, 'Year B');
        $periodA = $this->period($yearA, 'semester', 'Period A');
        $periodB = $this->period($yearA, 'semester', 'Period B');

        $manager->transitionYear((int) $school->id, (int) $yearA->id, 'active');
        $manager->transitionYear((int) $school->id, (int) $yearB->id, 'active');
        $manager->transitionPeriod((int) $school->id, (int) $periodA->id, 'active');
        $manager->transitionPeriod((int) $school->id, (int) $periodB->id, 'active');
        $this->assertSame(2, AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->count());
        $this->assertSame(2, AcademicPeriod::query()->where('school_id', $school->id)->where('status', 'active')->count());

        $manager->transitionPeriod((int) $school->id, (int) $periodA->id, 'completed');
        $manager->transitionPeriod((int) $school->id, (int) $periodB->id, 'cancelled');
        $manager->transitionYear((int) $school->id, (int) $yearA->id, 'completed');
        $cancelledYear = $this->year($school, 'Cancelled year');
        $manager->transitionYear((int) $school->id, (int) $cancelledYear->id, 'cancelled');

        foreach ([[$yearA, 'active'], [$cancelledYear, 'active'], [$periodA, 'active'], [$periodB, 'active']] as [$record, $status]) {
            try {
                $method = $record instanceof AcademicYear ? 'transitionYear' : 'transitionPeriod';
                $manager->{$method}((int) $school->id, (int) $record->id, $status);
                $this->fail('Completed and cancelled records cannot be reopened.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        }
    }

    public function test_current_context_requires_active_records_and_current_lifecycle_transitions_require_explicit_clear(): void
    {
        $school = $this->school('semester');
        $manager = app(AcademicContextManager::class);
        $plannedYear = $this->year($school, 'Planned');
        $this->assertValidationError(fn () => $manager->setCurrent((int) $school->id, (int) $plannedYear->id, null), 'current_academic_year_id');

        $manager->transitionYear((int) $school->id, (int) $plannedYear->id, 'cancelled');
        $this->assertValidationError(fn () => $manager->setCurrent((int) $school->id, (int) $plannedYear->id, null), 'current_academic_year_id');

        $completedYear = $this->year($school, 'Completed');
        $manager->transitionYear((int) $school->id, (int) $completedYear->id, 'active');
        $manager->transitionYear((int) $school->id, (int) $completedYear->id, 'completed');
        $this->assertValidationError(fn () => $manager->setCurrent((int) $school->id, (int) $completedYear->id, null), 'current_academic_year_id');

        $year = $this->year($school, 'Current');
        $manager->transitionYear((int) $school->id, (int) $year->id, 'active');
        $plannedPeriod = $this->period($year, 'semester', 'Planned period');
        $this->assertValidationError(fn () => $manager->setCurrent((int) $school->id, (int) $year->id, (int) $plannedPeriod->id), 'current_academic_period_id');
        $manager->transitionPeriod((int) $school->id, (int) $plannedPeriod->id, 'active');
        $manager->setCurrent((int) $school->id, (int) $year->id, (int) $plannedPeriod->id);

        $this->assertValidationError(fn () => $manager->transitionPeriod((int) $school->id, (int) $plannedPeriod->id, 'completed'), 'status');
        $this->assertValidationError(fn () => $manager->transitionPeriod((int) $school->id, (int) $plannedPeriod->id, 'cancelled'), 'status');
        $this->assertValidationError(fn () => $manager->transitionYear((int) $school->id, (int) $year->id, 'completed'), 'status');
        $this->assertValidationError(fn () => $manager->transitionYear((int) $school->id, (int) $year->id, 'cancelled'), 'status');

        $manager->setCurrent((int) $school->id, (int) $year->id, null);
        $school->refresh();
        $this->assertSame((int) $year->id, (int) $school->current_academic_year_id);
        $this->assertNull($school->current_academic_period_id, 'An active year may remain current during a break.');
        $manager->transitionPeriod((int) $school->id, (int) $plannedPeriod->id, 'completed');
        $manager->setCurrent((int) $school->id, null, null);
        $manager->transitionYear((int) $school->id, (int) $year->id, 'completed');
    }

    public function test_year_cannot_complete_with_planned_or_active_child_periods_and_cross_tenant_transition_is_rejected(): void
    {
        $schoolA = $this->school('semester');
        $schoolB = $this->school('semester');
        $year = $this->year($schoolA);
        $period = $this->period($year, 'semester');
        $manager = app(AcademicContextManager::class);
        $manager->transitionYear((int) $schoolA->id, (int) $year->id, 'active');

        $this->assertValidationError(fn () => $manager->transitionYear((int) $schoolA->id, (int) $year->id, 'completed'), 'status');
        $manager->transitionPeriod((int) $schoolA->id, (int) $period->id, 'active');
        $this->assertValidationError(fn () => $manager->transitionYear((int) $schoolA->id, (int) $year->id, 'completed'), 'status');

        try {
            $manager->transitionYear((int) $schoolB->id, (int) $year->id, 'cancelled');
            $this->fail('A tenant cannot transition another tenant’s year.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertTrue(true);
        }
        try {
            $manager->transitionPeriod((int) $schoolB->id, (int) $period->id, 'cancelled');
            $this->fail('A tenant cannot transition another tenant’s period.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertTrue(true);
        }
    }

    private function assertValidationError(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail("Expected validation error for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    public function test_legacy_session_lookup_and_mapping_are_tenant_scoped_without_touching_running_values(): void
    {
        $a = $this->school('semester');
        $b = $this->school('term');
        $yearA = $this->year($a);
        $sessionA = Session::query()->create(['school_id' => $a->id, 'session_title' => '2026', 'status' => 1]);
        $sessionB = Session::query()->create(['school_id' => $b->id, 'session_title' => '2026', 'status' => 1]);
        $a->running_session = $sessionA->id;
        $a->save();
        $beforeGlobal = DB::table('global_settings')->where('key', 'running_session')->value('value');
        $beforeRunning = DB::table('schools')->whereKey($a->id)->value('running_session');
        $context = app(AcademicContext::class);

        $this->assertSame((int) $sessionA->id, (int) $context->legacyRunningSession($a)?->id);
        try {
            app(AcademicContextManager::class)->mapLegacySession((int) $a->id, (int) $sessionA->id, (int) $this->year($b)->id);
            $this->fail('A legacy session cannot map to another school’s year.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('academic_year_id', $exception->errors());
        }
        app(AcademicContextManager::class)->mapLegacySession((int) $a->id, (int) $sessionA->id, (int) $yearA->id);

        $this->assertSame((int) $yearA->id, (int) $sessionA->fresh()->academic_year_id);
        $this->assertNull($sessionB->fresh()->academic_year_id);
        $this->assertSame($beforeGlobal, DB::table('global_settings')->where('key', 'running_session')->value('value'));
        $this->assertSame($beforeRunning, DB::table('schools')->whereKey($a->id)->value('running_session'));
    }

    public function test_tenant_current_pointers_and_legacy_mapping_are_not_mass_assignable(): void
    {
        $school = new School(['title' => 'Safe', 'current_academic_year_id' => 44, 'current_academic_period_id' => 55]);
        $session = new Session(['session_title' => 'Legacy', 'academic_year_id' => 44]);

        $this->assertNull($school->current_academic_year_id);
        $this->assertNull($school->current_academic_period_id);
        $this->assertNull($session->academic_year_id);
    }
}
