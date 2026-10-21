<?php

namespace Tests\Feature;

use App\Models\AcademicUnit;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class DepartmentDetailsTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;
    private AcademicUnit $faculty;
    private int $existingDepartmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        foreach ([
            '2026_10_17_110000_create_academic_units_and_link_departments.php',
            '2026_10_17_120000_add_codes_and_leadership_to_academic_divisions.php',
            '2026_10_17_130000_promote_legacy_academic_units.php',
            '2026_10_17_140000_detach_legacy_unit_programmes.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->schoolId = $this->makeSchool();
        $this->existingDepartmentId = DB::table('departments')->insertGetId([
            'school_id' => $this->schoolId, 'name' => 'Existing Department',
            'hod_name' => 'Legacy Head', 'created_at' => '2020-01-02 03:04:05',
        ]);
        (require database_path('migrations/2026_10_21_030000_add_details_and_staff_head_to_departments.php'))->up();
        $this->faculty = AcademicUnit::create([
            'school_id' => $this->schoolId, 'type' => 'faculty',
            'name' => 'Faculty of Computing', 'status' => 'active',
        ]);
        $this->actingAs($this->makeAdminUser($this->schoolId));
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'school_id' => $this->schoolId, 'role_id' => 3,
            'name' => 'Linked Lecturer Head', 'code' => 'EMP-0042', 'account_status' => 'active',
        ]);
    }

    private function payload(array $attributes = []): array
    {
        return $attributes + ['name' => 'Software Engineering', 'academic_unit_id' => $this->faculty->id];
    }

    public function test_migration_keeps_existing_head_name_and_creation_time_and_defaults_to_active(): void
    {
        $department = Department::findOrFail($this->existingDepartmentId);
        $this->assertTrue($department->is_active);
        $this->assertNull($department->head_staff_id);
        $this->assertNull($department->description);
        $this->assertSame('Legacy Head', $department->hod_name);
        $this->assertSame('2020-01-02 03:04:05', $department->created_at->format('Y-m-d H:i:s'));
    }

    public function test_department_forms_show_staff_and_parent_ids_and_read_only_system_fields(): void
    {
        $this->staff();
        $this->staff(['name' => 'Foreign Staff Must Not Appear', 'school_id' => $this->makeSchool()]);
        $this->staff(['name' => 'Student Must Not Appear', 'role_id' => 7]);
        $this->staff(['name' => 'Disabled Staff Must Not Appear', 'account_status' => 'disable']);
        foreach (['admin.department.departments.create', 'admin.department.open_modal'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('Head Staff ID / HOD ID')
                ->assertSee('EMP-0042')->assertSee('Faculty of Computing')->assertSee('Description')
                ->assertSee('Institution ID')->assertSee('Created At')->assertSee('Recorded when saved')
                ->assertSee('id="department-institution"', false)->assertSee('readonly', false)
                ->assertDontSee('Foreign Staff Must Not Appear')->assertDontSee('Student Must Not Appear')
                ->assertDontSee('Disabled Staff Must Not Appear');
        }
    }

    public function test_creation_persists_details_uses_linked_head_and_ignores_spoofed_system_fields(): void
    {
        $head = $this->staff();
        $otherSchool = $this->makeSchool();
        $this->post(route('admin.create.department'), $this->payload([
            'head_staff_id' => $head->id, 'hod_name' => 'Incorrect free-text head',
            'description' => '  Responsible for software programmes.  ', 'is_active' => '0',
            'school_id' => $otherSchool, 'institution_id' => $otherSchool, 'created_at' => '1900-01-01',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.department.departments'));
        $department = Department::where('name', 'Software Engineering')->firstOrFail();
        $this->assertSame($this->schoolId, (int) $department->school_id);
        $this->assertSame((int) $head->id, (int) $department->head_staff_id);
        $this->assertSame($head->name, $department->hod_name);
        $this->assertSame('Responsible for software programmes.', $department->description);
        $this->assertFalse($department->is_active);
        $this->assertNotSame('1900-01-01', $department->created_at->format('Y-m-d'));
        for ($refresh = 0; $refresh < 2; $refresh++) {
            $this->get(route('admin.department.show', $department->id))->assertOk()
                ->assertSee('Responsible for software programmes.')->assertSee($head->name)->assertSee($head->code);
        }
    }

    public function test_non_staff_foreign_and_disabled_head_assignments_and_invalid_details_are_rejected(): void
    {
        foreach ([
            $this->staff(['school_id' => $this->makeSchool()]),
            $this->staff(['role_id' => 7]),
            $this->staff(['account_status' => 'disable']),
        ] as $head) {
            $this->post(route('admin.create.department'), $this->payload(['head_staff_id' => $head->id]))
                ->assertSessionHasErrors('head_staff_id');
        }
        $this->post(route('admin.create.department'), $this->payload(['is_active' => 'maybe']))->assertSessionHasErrors('is_active');
        $this->post(route('admin.create.department'), $this->payload(['description' => str_repeat('x', 10001)]))->assertSessionHasErrors('description');
        $this->assertDatabaseMissing('departments', ['name' => 'Software Engineering']);
    }

    public function test_legacy_updates_preserve_details_and_inactive_existing_head_can_be_retained_or_cleared(): void
    {
        $head = $this->staff();
        $department = Department::create($this->payload([
            'school_id' => $this->schoolId, 'head_staff_id' => $head->id,
            'hod_name' => $head->name, 'description' => 'Keep this description', 'is_active' => false,
        ]));
        $createdAt = $department->created_at;
        $head->update(['account_status' => 'disable']);
        $this->get(route('admin.department.departments.edit', $department->id))->assertOk()->assertSee($head->code);
        $this->post(route('admin.department.update', $department->id), $this->payload(['name' => 'Renamed Department']))->assertSessionHasNoErrors();
        $department->refresh();
        $this->assertSame((int) $head->id, (int) $department->head_staff_id);
        $this->assertSame('Keep this description', $department->description);
        $this->assertFalse($department->is_active);
        $this->assertTrue($createdAt->equalTo($department->created_at));
        $this->post(route('admin.department.update', $department->id), $this->payload([
            'name' => 'Renamed Department', 'head_staff_id' => null, 'hod_name' => 'Legacy Head', 'description' => '', 'is_active' => '1',
        ]))->assertSessionHasNoErrors();
        $department->refresh();
        $this->assertNull($department->head_staff_id);
        $this->assertNull($department->description);
        $this->assertSame('Legacy Head', $department->hod_name);
        $this->assertTrue($department->is_active);
    }
}
