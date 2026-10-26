<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\Subject;
use App\Models\User;
use App\Support\Curriculum\CurriculumFoundationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class CourseUnitSetupTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;
    private int $programme;
    private User $admin;
    private Curriculum $plan;
    private int $stage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        foreach (['school_type', 'education_level'] as $column) {
            if (!Schema::hasColumn('schools', $column)) Schema::table('schools', fn (Blueprint $t) => $t->string($column)->nullable());
        }
        if (!Schema::hasColumn('schools', 'academic_calendar_pattern')) {
            Schema::table('schools', fn (Blueprint $t) => $t->string('academic_calendar_pattern')->nullable());
        }
        Schema::create('curricula', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id');
            $t->string('version'); $t->unsignedBigInteger('effective_academic_year_id')->nullable(); $t->string('status'); $t->timestamps();
        });
        Schema::create('curriculum_stages', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->string('label'); $t->integer('sequence'); $t->timestamps();
        });
        Schema::create('curriculum_memberships', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('curriculum_stage_id');
            $t->string('period_type')->nullable(); $t->integer('period_sequence')->nullable(); $t->string('classification'); $t->decimal('credits', 6, 2); $t->integer('sequence'); $t->timestamps();
            $t->unique(['curriculum_id', 'subject_id']);
        });
        Schema::create('curriculum_prerequisites', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('membership_id'); $t->unsignedBigInteger('prerequisite_membership_id'); $t->primary(['membership_id','prerequisite_membership_id']);
        });
        Schema::create('academic_years', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label'); $t->date('start_date')->nullable();
        });
        $this->school = $this->makeSchool(['school_type' => 'higher_ed', 'education_level' => 'tertiary', 'academic_calendar_pattern' => 'semester']);
        $this->programme = $this->makeProgramme($this->school, ['name' => 'Business Administration', 'code' => 'BBA']);
        $this->admin = $this->makeAdminUser($this->school);
        $this->actingAs($this->admin);
        $this->plan = app(CurriculumFoundationService::class)->createDraft($this->school, $this->programme, '2026 plan');
        $this->stage = (int) DB::table('curriculum_stages')->insertGetId(['school_id' => $this->school, 'curriculum_id' => $this->plan->id, 'label' => 'Foundation year', 'sequence' => 1]);
    }

    public function test_create_and_edit_save_one_catalogue_unit_and_one_existing_plan_relationship(): void
    {
        $this->post(route('admin.create.subject'), $this->payload())->assertSessionHasNoErrors()->assertRedirect(route('admin.subject_list'));
        $unit = Subject::where('code', 'BBA101')->firstOrFail();
        $membership = CurriculumMembership::where('subject_id', $unit->id)->firstOrFail();
        $this->assertSame($this->programme, (int)$unit->programme_id);
        $this->assertSame('3.50', $membership->credits);
        $this->assertNull($unit->credits); // No second source of Study Plan credit values.
        $this->post(route('admin.subject.update', $unit->id), $this->payload(['name' => 'Revised Business']))->assertSessionHasNoErrors();
        $this->put(route('admin.curricula.memberships.update', [$this->plan->id, $membership->id]), [
            'curriculum_stage_id' => $this->stage, 'classification' => 'compulsory', 'credits' => '4.00',
            'period_type' => 'semester', 'period_sequence' => 1, 'sequence' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertSame('4.00', $membership->fresh()->credits);
        $this->assertSame('Revised Business', $unit->fresh()->name);
        $this->assertSame(1, Subject::count());
        $this->assertSame(1, CurriculumMembership::count());
    }

    public function test_prerequisites_save_existing_membership_ids_and_are_not_duplicated(): void
    {
        $required = $this->existingPlacement('BBA100');
        $payload = $this->payload(['placement_prerequisites' => [$required->id]]);
        $this->post(route('admin.create.subject'), $payload)->assertSessionHasNoErrors();
        $unit = Subject::where('code', 'BBA101')->firstOrFail();
        $this->assertSame(1, DB::table('curriculum_prerequisites')->count());
        $this->assertSame((int)$required->id, (int) DB::table('curriculum_prerequisites')->value('prerequisite_membership_id'));
        $this->delete(route('admin.curricula.prerequisites.destroy', $this->plan->id), ['membership_id' => CurriculumMembership::where('subject_id', $unit->id)->value('id'), 'prerequisite_membership_id' => $required->id])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('curriculum_prerequisites')->count());
        $this->assertSame(2, Subject::count());
    }

    public function test_invalid_stage_or_prerequisite_rolls_back_the_new_unit(): void
    {
        foreach (['placement_stage_id', 'placement_prerequisites'] as $field) {
            $data = $this->payload([$field => $field === 'placement_prerequisites' ? [9999] : 9999]);
            $this->post(route('admin.create.subject'), $data)->assertSessionHasErrors();
            $this->assertSame(0, Subject::count());
            $this->assertSame(0, CurriculumMembership::count());
        }
    }

    public function test_catalogue_edit_does_not_mutate_approved_curriculum_placement(): void
    {
        $membership = $this->existingPlacement('BBA101');
        $unit = $membership->subject;
        DB::table('curricula')->where('id', $this->plan->id)->update(['status' => 'approved']);
        $this->post(route('admin.subject.update', $unit->id), ['name' => 'Shared catalogue change', 'code' => 'BBA101', 'programme_id' => $this->programme])->assertSessionHasNoErrors();
        $this->assertSame('Shared catalogue change', $unit->fresh()->name);
        $this->post(route('admin.subject.update', $unit->id), [
            'name' => 'Shared catalogue change 2', 'code' => 'BBA101', 'programme_id' => $this->programme,
            'placement_curriculum_id' => $this->plan->id, 'placement_stage_id' => $this->stage, 'placement_credits' => '8.00',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Shared catalogue change 2', $unit->fresh()->name);
        $this->assertSame('2.00', $membership->fresh()->credits);
    }

    public function test_cycle_is_rejected_without_overwriting_existing_edges_or_name(): void
    {
        $first = $this->existingPlacement('BBA101');
        $second = $this->existingPlacement('BBA102');
        app(CurriculumFoundationService::class)->addPrerequisite($this->plan, (int)$second->id, (int)$first->id);
        try {
            app(CurriculumFoundationService::class)->addPrerequisite($this->plan, (int) $first->id, (int) $second->id);
            $this->fail('A prerequisite cycle was accepted.');
        } catch (\Illuminate\Validation\ValidationException) {
            // The placement service rejects the cycle before changing graph state.
        }
        $this->assertSame('Existing BBA101', $first->subject->fresh()->name);
        $this->assertSame(1, DB::table('curriculum_prerequisites')->count());
    }

    public function test_other_placements_remain_and_catalogue_only_save_does_not_clear_them(): void
    {
        $first = $this->existingPlacement('BBA101');
        $otherPlan = $this->plan->replicate(); $otherPlan->version = 'Next draft'; $otherPlan->save();
        $otherStage = DB::table('curriculum_stages')->insertGetId(['school_id' => $this->school, 'curriculum_id' => $otherPlan->id, 'label' => 'Other stage', 'sequence' => 1]);
        app(CurriculumFoundationService::class)->addMembership($otherPlan, (int) $first->subject_id, (int) $otherStage, ['classification' => 'compulsory', 'credits' => '7.00', 'sequence' => 0]);
        $this->assertSame(2, CurriculumMembership::count());
        $this->assertSame('2.00', $first->fresh()->credits);
        $this->post(route('admin.subject.update', $first->subject_id), ['name' => 'Catalogue edit', 'code' => 'BBA101', 'programme_id' => $this->programme])->assertSessionHasNoErrors();
        $this->assertSame(2, CurriculumMembership::count());
    }

    public function test_foreign_plan_and_unauthorized_curriculum_changes_are_rejected_atomically(): void
    {
        $foreignSchool = $this->makeSchool();
        $foreignPlan = $this->plan->replicate(); $foreignPlan->school_id = $foreignSchool; $foreignPlan->save();
        $this->post(route('admin.create.subject'), $this->payload(['placement_curriculum_id' => $foreignPlan->id]))->assertSessionHasErrors('placement_curriculum_id');
        $this->assertSame(0, Subject::count());
        $restricted = User::factory()->create(['school_id' => $this->school, 'role_id' => 3, 'account_status' => 'active']);
        // Exercise the extra service permission independently of route admission.
        $request = \Illuminate\Http\Request::create('/admin/subject', 'POST', $this->payload());
        $request->setUserResolver(fn () => $restricted);
        $unit = Subject::create(['school_id' => $this->school, 'name' => 'Existing', 'code' => 'TEST', 'programme_id' => $this->programme]);
        try {
            DB::transaction(fn () => app(\App\Support\Curriculum\CourseUnitSetup::class)->savePlacement($request, $unit));
            $this->fail('Curriculum permission was bypassed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, CurriculumMembership::count());
    }

    public function test_form_shows_names_retains_selected_inactive_programme_and_moves_editors(): void
    {
        $member = $this->existingPlacement('BBA101');
        DB::table('programmes')->where('id', $this->programme)->update(['is_active' => 0]);
        $response = $this->get(route('admin.edit.subject', ['id' => $member->subject_id, 'curriculum_id' => $this->plan->id, 'curriculum_stage_id' => $this->stage]));
        $response->assertOk()->assertSee('class="sidebar"', false)->assertSee('curriculum-workspace.css')->assertSee('Edit Course Unit')->assertSee('Shared catalogue details')->assertSee('Changes here may affect other curricula')->assertSee('Return to Curriculum Placement')->assertDontSee('placement_credits')->assertDontSee('placement_prerequisites[]', false);
        $response->assertSee('value="'.$this->programme.'" selected', false);
        if (getenv('PIIE_CAPTURE_UNIT_FORM') === '1') {
            file_put_contents(base_path('output/course-unit-setup-preview.html'), $response->getContent());
        }
        $this->get(route('admin.subject_list', ['curriculum_id' => $this->plan->id]))->assertOk()->assertSee('subject-picker-form', false);
        $direct = $this->get(route('admin.edit.subject', $member->subject_id));
        $direct->assertOk()->assertSee('class="sidebar"', false)->assertSee('Edit Course Unit')->assertSee('Shared catalogue details')->assertDontSee('placement_credits');
        $modal = $this->get(route('admin.edit.subject', ['id' => $member->subject_id, 'modal' => 1]));
        $modal->assertOk()->assertSee('ajaxForm')->assertDontSee('class="sidebar"', false);
        $this->get(route('admin.curricula.show', $this->plan->id))->assertOk()
            ->assertDontSee('Edit Course Unit setup')->assertDontSee('name="credits"', false)
            ->assertDontSee('id="subject-picker-form"', false)->assertDontSee('name="prerequisite_membership_id"', false);
    }

    public function test_catalogue_edit_preserves_curriculum_context_on_validation_save_and_cancel(): void
    {
        $placement = $this->existingPlacement('BBA101');
        $other = $this->existingPlacement('BBA102');
        $otherPlan = $this->plan->replicate(); $otherPlan->version = 'Second curriculum'; $otherPlan->save();
        $otherStage = DB::table('curriculum_stages')->insertGetId(['school_id' => $this->school, 'curriculum_id' => $otherPlan->id, 'label' => 'Year 1', 'sequence' => 1]);
        $secondPlacement = app(CurriculumFoundationService::class)->addMembership($otherPlan, (int) $placement->subject_id, $otherStage, ['classification' => 'compulsory', 'credits' => '5.00', 'sequence' => 0]);

        $editUrl = route('admin.edit.subject', ['id' => $placement->subject_id, 'curriculum_id' => $this->plan->id, 'curriculum_stage_id' => $this->stage]);
        $returnUrl = route('admin.curricula.show', $this->plan->id).'#curriculum-year-'.$this->stage;
        $this->get($editUrl)->assertOk()->assertSee($returnUrl, false);
        $this->from($editUrl)->post(route('admin.subject.update', ['id' => $placement->subject_id, 'curriculum_id' => $this->plan->id, 'curriculum_stage_id' => $this->stage]), [
            'name' => '', 'code' => 'BBA101', 'programme_id' => $this->programme,
            'curriculum_id' => (string) $this->plan->id, 'curriculum_stage_id' => (string) $this->stage,
        ])->assertRedirect($editUrl)->assertSessionHasErrors('name')
            ->assertSessionHas('_old_input.curriculum_id', (string) $this->plan->id)
            ->assertSessionHas('_old_input.curriculum_stage_id', (string) $this->stage);

        $this->post(route('admin.subject.update', ['id' => $placement->subject_id, 'curriculum_id' => $this->plan->id, 'curriculum_stage_id' => $this->stage]), [
            'name' => 'Reused Across Curricula', 'code' => 'BBA101', 'programme_id' => $this->programme,
            'curriculum_id' => (string) $this->plan->id, 'curriculum_stage_id' => (string) $this->stage,
        ])->assertSessionHasNoErrors()->assertRedirect($returnUrl);
        $this->assertSame('Reused Across Curricula', $placement->subject->fresh()->name);
        $this->assertSame($placement->subject_id, $secondPlacement->fresh()->subject_id);
        $this->assertSame('2.00', $placement->fresh()->credits);
        $this->assertSame('5.00', $secondPlacement->fresh()->credits);
        $this->assertSame(2, CurriculumMembership::where('subject_id', $placement->subject_id)->count());
    }

    public function test_form_explains_year_selection_and_preserves_catalogue_and_placement_data(): void
    {
        $year = DB::table('academic_years')->insertGetId(['school_id'=>$this->school,'label'=>'2026/2027','start_date'=>'2026-10-01']);
        DB::table('curricula')->where('id',$this->plan->id)->update(['effective_academic_year_id'=>$year]);
        $member = $this->existingPlacement('BBA102');
        $before = $member->getAttributes();
        $response = $this->get(route('admin.subject.setup', $member->subject_id))->assertOk()
            ->assertSee('Shared catalogue details')->assertSee('Changes here may affect other curricula')
            ->assertDontSee('Curriculum applies from Academic Year')->assertDontSee('placement_credits');
        $create = $this->get(route('admin.subject.setup'))->assertOk()
            ->assertSee('Curriculum applies from Academic Year')->assertSee('data-curriculum-year', false)
            ->assertSee('placement_credits');
        $this->assertEquals($before,$member->fresh()->getAttributes());
        $view = $this->get(route('admin.subject.setup', ['id' => $member->subject_id, 'view' => '1']))->assertOk()->assertSee('View Course Unit')->assertSee('class="sidebar"', false);
        $viewDoc = new \DOMDocument(); @$viewDoc->loadHTML($view->getContent());
        $this->assertGreaterThanOrEqual(1, (new \DOMXPath($viewDoc))->query('//fieldset[@disabled]')->length);
        $view->assertDontSee('Remove placement');
        $this->get(route('admin.programmes.index'))->assertOk()->assertSee('Student Course Registration');
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'Business Foundations', 'code' => 'BBA101', 'programme_id' => $this->programme,
            'placement_curriculum_id' => $this->plan->id, 'placement_stage_id' => $this->stage,
            'placement_classification' => 'compulsory', 'placement_credits' => '3.50', 'placement_sequence' => 1,
            'placement_period_type' => 'semester', 'placement_period_sequence' => 1,
            'placement_prerequisites_present' => 1, 'placement_prerequisites' => []], $overrides);
    }

    private function existingPlacement(string $code): CurriculumMembership
    {
        $unit = Subject::create(['school_id' => $this->school, 'name' => 'Existing '.$code, 'code' => $code, 'programme_id' => $this->programme]);
        return app(CurriculumFoundationService::class)->addMembership($this->plan, (int)$unit->id, $this->stage, ['classification' => 'compulsory', 'credits' => '2.00', 'sequence' => 0]);
    }
}
