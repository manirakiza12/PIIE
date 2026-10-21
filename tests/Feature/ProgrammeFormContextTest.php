<?php

namespace Tests\Feature;

use App\Models\AcademicUnit;
use App\Models\Department;
use App\Models\Programme;
use App\Models\User;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ProgrammeFormContextTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        foreach ([
            '2026_10_17_110000_create_academic_units_and_link_departments.php',
            '2026_10_17_120000_add_codes_and_leadership_to_academic_divisions.php',
            '2026_10_21_030000_add_details_and_staff_head_to_departments.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->schoolId = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($this->schoolId));
    }

    private function payload(array $values = []): array
    {
        return $values + ['code' => 'FORM1', 'name' => 'Programme Form Test', 'level' => 'Diploma', 'mode' => 'ODEL'];
    }

    public function test_selected_department_context_is_shown_by_name_on_initial_load_and_after_refresh(): void
    {
        $faculty = AcademicUnit::create(['school_id' => $this->schoolId, 'name' => 'Computing Faculty', 'type' => 'faculty', 'status' => 'active']);
        $head = User::factory()->create(['school_id' => $this->schoolId, 'role_id' => 3, 'name' => 'Current Linked Head']);
        $department = Department::create([
            'school_id' => $this->schoolId, 'name' => 'Computing Department',
            'academic_unit_id' => $faculty->id, 'head_staff_id' => $head->id, 'hod_name' => 'Old Head Name',
        ]);
        $this->post(route('admin.programmes.store'), $this->payload(['department_id' => $department->id, 'is_active' => 0]))->assertSessionHasNoErrors();
        $programme = Programme::where('code', 'FORM1')->firstOrFail();
        for ($refresh = 0; $refresh < 2; $refresh++) {
            $this->get(route('admin.programmes.open_modal', ['id' => $programme->id]))->assertOk()
                ->assertSee('data-faculty="Computing Faculty"', false)->assertSee('data-hod="Current Linked Head"', false)
                ->assertSee('value="Computing Faculty" readonly', false)->assertSee('value="Current Linked Head" readonly', false)
                ->assertSee('name="is_active"', false)->assertDontSee('Old Head Name');
        }
        $head->update(['name' => 'Renamed Linked Head']);
        $this->get(route('admin.programmes.open_modal', ['id' => $programme->id]))->assertOk()->assertSee('Renamed Linked Head');
    }

    public function test_department_context_does_not_expose_foreign_names_and_keeps_legacy_hod_names(): void
    {
        $foreignSchool = $this->makeSchool();
        $foreignUnit = AcademicUnit::create(['school_id' => $foreignSchool, 'name' => 'Foreign Faculty Hidden', 'type' => 'faculty', 'status' => 'active']);
        $foreignHead = User::factory()->create(['school_id' => $foreignSchool, 'role_id' => 3, 'name' => 'Foreign Head Hidden']);
        Department::create(['school_id' => $foreignSchool, 'name' => 'Foreign Department Hidden']);
        Department::create([
            'school_id' => $this->schoolId, 'name' => 'Own Department', 'hod_name' => 'Legacy Department Head',
            'academic_unit_id' => $foreignUnit->id, 'head_staff_id' => $foreignHead->id,
        ]);
        $this->get(route('admin.programmes.open_modal'))->assertOk()->assertSee('Legacy Department Head')
            ->assertDontSee('Foreign Faculty Hidden')->assertDontSee('Foreign Head Hidden')->assertDontSee('Foreign Department Hidden');
    }

    public function test_programme_active_status_can_be_created_and_changed_and_omission_keeps_existing_status(): void
    {
        $this->post(route('admin.programmes.store'), $this->payload(['is_active' => '0']))->assertSessionHasNoErrors();
        $programme = Programme::where('code', 'FORM1')->firstOrFail();
        $this->assertFalse($programme->is_active);
        $this->post(route('admin.programmes.update', $programme->id), $this->payload(['name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertFalse($programme->fresh()->is_active);
        $this->post(route('admin.programmes.update', $programme->id), $this->payload(['is_active' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue($programme->fresh()->is_active);
        $this->post(route('admin.programmes.store'), $this->payload(['code' => 'DEFAULT']))->assertSessionHasNoErrors();
        $this->assertTrue(Programme::where('code', 'DEFAULT')->firstOrFail()->is_active);
    }

    public function test_invalid_active_values_cannot_create_a_programme(): void
    {
        $this->post(route('admin.programmes.store'), $this->payload(['is_active' => 'sometimes']))->assertSessionHasErrors('is_active');
        $this->assertDatabaseMissing('programmes', ['code' => 'FORM1']);
    }
}
