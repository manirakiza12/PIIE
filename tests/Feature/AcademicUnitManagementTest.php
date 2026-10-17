<?php

namespace Tests\Feature;

use App\Models\AcademicUnit;
use App\Models\Department;
use App\Models\Programme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AcademicUnitManagementTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function migrateAcademicUnits(): void
    {
        (require database_path('migrations/2026_10_17_110000_create_academic_units_and_link_departments.php'))->up();
        (require database_path('migrations/2026_10_17_120000_add_codes_and_leadership_to_academic_divisions.php'))->up();
        (require database_path('migrations/2026_10_17_130000_promote_legacy_academic_units.php'))->up();
        (require database_path('migrations/2026_10_17_140000_detach_legacy_unit_programmes.php'))->up();
    }

    private function createUnit(int $schoolId, string $type, string $name, ?int $parentId = null): int
    {
        return (int) AcademicUnit::query()->create([
            'school_id' => $schoolId, 'type' => $type, 'name' => $name,
            'parent_id' => $parentId, 'status' => 'active',
        ])->id;
    }

    public function test_migration_preserves_existing_department_programme_ids_and_marks_no_parent_for_review(): void
    {
        $schoolId = $this->makeSchool();
        $departmentId = (int) DB::table('departments')->insertGetId(['school_id' => $schoolId, 'name' => 'Legacy Department']);
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Legacy Programme', 'code' => 'LEG1', 'department_id' => $departmentId]);

        $this->migrateAcademicUnits();

        $this->assertTrue(Schema::hasTable('academic_units'));
        $this->assertTrue(Schema::hasColumn('departments', 'academic_unit_id'));
        $this->assertSame('Legacy Department', Department::query()->findOrFail($departmentId)->name);
        $this->assertNull(Department::query()->findOrFail($departmentId)->academic_unit_id);
        $this->assertSame($departmentId, (int) Programme::query()->findOrFail($programmeId)->department_id);

        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->get(route('admin.department.departments'))
            ->assertOk()->assertSee('Legacy Department')->assertSee('Parent not assigned')->assertDontSee('Parent assignment')->assertDontSee('Needs review');
    }

    public function test_schools_faculties_and_departments_have_separate_views_and_parent_options_are_namespaced(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $school = $this->createUnit($schoolId, 'school', 'School of Computing');

        $this->actingAs($admin)->get(route('admin.department_list'))->assertRedirect(route('admin.department.schools_faculties'));
        $this->get(route('admin.department.schools_faculties'))->assertOk()->assertSee('Schools & Faculties')->assertSee('School of Computing')->assertSee('Add School / Faculty')
            ->assertDontSee('Academic units sections');
        $this->get(route('admin.department.departments'))->assertOk()->assertSee('Departments')->assertSee('Add Department')
            ->assertDontSee('Academic units sections')->assertDontSee('Parent assignment');
        $this->get(route('admin.department.units.create'))
            ->assertOk()->assertSee('Choose School or Faculty')->assertSee('Belongs To - Select Parent School or Faculty')->assertSee('School of Computing - School')
            ->assertSee('Dean / Director / Head')->assertDontSee('Dean / Director / Head Title')->assertDontSee('Academic units sections');
        $this->get(route('admin.department.units.create', ['type' => 'faculty']))
            ->assertOk()->assertSee('Faculty')->assertSee('Belongs To - Select Parent School or Faculty');
        $this->get(route('admin.department.units.show', $school))->assertOk()->assertSee('School of Computing');
        $this->get(route('admin.department.units.edit', $school))->assertOk()->assertSee('Save changes')->assertSee('School of Computing');
        $this->get(route('admin.department.departments.create'))
            ->assertOk()->assertSee('Belongs To - Select Parent School or Faculty')->assertSee('School of Computing - School')->assertDontSee('Academic units sections');
        $this->assertGreaterThan(0, $school);
    }

    public function test_school_and_faculty_creation_enforce_parent_rules_duplicate_names_and_tenant_scope(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $otherParent = $this->createUnit($otherSchool, 'school', 'Other Tenant School');
        $admin->forceFill(['menu_permission' => json_encode(['admin.department_list'])])->save();
        $this->actingAs($admin);

        $this->post(route('admin.department.units.store'), ['type' => 'school', 'name' => 'School of Business', 'code' => 'BUS', 'head_title' => 'Director', 'head_name' => 'Amina Example', 'status' => 'active'])
            ->assertRedirect(route('admin.department.schools_faculties'))
            ->assertSessionHasNoErrors();
        $school = AcademicUnit::query()->where('school_id', $schoolId)->where('type', 'school')->where('name', 'School of Business')->firstOrFail();
        $this->assertNull($school->parent_id);
        $this->assertSame('BUS', $school->code);
        $this->assertSame('Director', $school->head_title);
        $this->assertSame('Amina Example', $school->head_name);
        $this->put(route('admin.department.units.update', $school->id), [
            'name' => 'School of Business', 'code' => 'BUS', 'head_name' => 'Amina Example', 'status' => 'active',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Director', $school->fresh()->head_title);

        $this->from(route('admin.department.units.create', ['type' => 'faculty']))
            ->post(route('admin.department.units.store'), ['type' => 'faculty', 'name' => 'Faculty of Finance', 'status' => 'active'])
            ->assertSessionHasErrors('parent_id');
        $this->from(route('admin.department.units.create', ['type' => 'faculty']))
            ->post(route('admin.department.units.store'), ['type' => 'faculty', 'name' => 'Faculty of Finance', 'parent_id' => $otherParent, 'status' => 'active'])
            ->assertSessionHasErrors('parent_id');
        $this->from(route('admin.department.units.create', ['type' => 'faculty']))
            ->post(route('admin.department.units.store'), ['type' => 'faculty', 'name' => 'Faculty of Finance', 'code' => 'FIN', 'head_title' => 'Dean', 'head_name' => 'Ben Example', 'parent_id' => $school->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $faculty = AcademicUnit::query()->where('school_id', $schoolId)->where('type', 'faculty')->where('name', 'Faculty of Finance')->firstOrFail();
        $this->assertSame((int) $school->id, (int) $faculty->parent_id);
        $this->assertSame('FIN', $faculty->code);
        $this->assertSame('Dean', $faculty->head_title);
        $this->from(route('admin.department.units.create', ['type' => 'school']))
            ->post(route('admin.department.units.store'), ['type' => 'school', 'name' => 'School of Business', 'status' => 'active'])
            ->assertSessionHasErrors('name');
        $this->from(route('admin.department.units.create', ['type' => 'school']))
            ->post(route('admin.department.units.store'), ['type' => 'school', 'name' => 'School of Commerce', 'code' => 'BUS', 'status' => 'active'])
            ->assertSessionHasErrors('code');
        $this->assertSame(1, AcademicUnit::query()->where('school_id', $schoolId)->where('type', 'school')->where('name', 'School of Business')->count());
    }

    public function test_faculty_self_parenting_and_circular_hierarchy_are_rejected_without_changing_records(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $school = $this->createUnit($schoolId, 'school', 'School of Science');
        $facultyA = $this->createUnit($schoolId, 'faculty', 'Faculty A', $school);
        $facultyB = $this->createUnit($schoolId, 'faculty', 'Faculty B', $facultyA);
        $this->actingAs($admin);

        $this->from(route('admin.department.units.edit', $facultyA))
            ->put(route('admin.department.units.update', $facultyA), ['name' => 'Faculty A', 'parent_id' => $facultyA, 'status' => 'active'])
            ->assertSessionHasErrors('parent_id');
        $this->from(route('admin.department.units.edit', $facultyA))
            ->put(route('admin.department.units.update', $facultyA), ['name' => 'Faculty A', 'parent_id' => $facultyB, 'status' => 'active'])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame((int) $school, (int) AcademicUnit::query()->findOrFail($facultyA)->parent_id);
        $this->assertSame((int) $facultyA, (int) AcademicUnit::query()->findOrFail($facultyB)->parent_id);
    }

    public function test_department_creation_requires_a_same_tenant_parent_and_keeps_programme_relationships(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $parent = $this->createUnit($schoolId, 'school', 'School of Technology');
        $invalidParent = (int) AcademicUnit::query()->create([
            'school_id' => $schoolId, 'type' => 'campus', 'name' => 'Invalid parent type', 'status' => 'active',
        ])->id;
        $foreignParent = $this->createUnit($otherSchool, 'school', 'Foreign School');
        $this->actingAs($admin);

        $this->from(route('admin.department.departments.create'))
            ->post(route('admin.create.department'), ['name' => 'Computer Science'])
            ->assertSessionHasErrors('academic_unit_id');
        $this->from(route('admin.department.departments.create'))
            ->post(route('admin.create.department'), ['name' => 'Computer Science', 'academic_unit_id' => $foreignParent])
            ->assertSessionHasErrors('academic_unit_id');
        $this->from(route('admin.department.departments.create'))
            ->post(route('admin.create.department'), ['name' => 'Computer Science', 'academic_unit_id' => $invalidParent])
            ->assertSessionHasErrors('academic_unit_id');
        $this->post(route('admin.create.department'), ['name' => 'Computer Science', 'code' => 'CSC', 'hod_name' => 'Carol Example', 'academic_unit_id' => $parent])->assertSessionHasNoErrors();

        $department = Department::query()->where('school_id', $schoolId)->where('name', 'Computer Science')->firstOrFail();
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'BSc Computing', 'code' => 'CSC1', 'department_id' => $department->id]);
        $this->assertSame((int) $parent, (int) $department->academic_unit_id);
        $this->assertSame('CSC', $department->code);
        $this->assertSame('Carol Example', $department->hod_name);
        $this->assertSame((int) $department->id, (int) Programme::query()->findOrFail($programmeId)->department_id);
        $this->get(route('admin.department.show', $department->id))->assertOk()->assertSee('Computer Science');
        $this->get(route('admin.department.departments.edit', $department->id))->assertOk()->assertSee('Edit Department');
        $this->post(route('admin.department.update', $department->id), [
            'name' => 'Computing', 'code' => 'CSC', 'hod_name' => 'Carol Example', 'academic_unit_id' => $parent,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.department.departments'));
        $department->refresh();
        $this->assertSame('Computing', $department->name);
        $this->assertSame((int) $parent, (int) $department->academic_unit_id);
        $this->assertSame((int) $department->id, (int) Programme::query()->findOrFail($programmeId)->department_id);

        $this->from(route('admin.department.departments.create'))
            ->post(route('admin.create.department'), ['name' => 'Computing', 'academic_unit_id' => $parent])
            ->assertSessionHasErrors('name');
        $this->from(route('admin.department.departments.create'))
            ->post(route('admin.create.department'), ['name' => 'Information Systems', 'code' => 'CSC', 'academic_unit_id' => $parent])
            ->assertSessionHasErrors('code');
        $this->get(route('admin.department.departments', ['search' => 'Computing']))
            ->assertOk()->assertSee('Computing')->assertSee('School of Technology');
    }

    public function test_department_delete_is_blocked_when_programmes_use_it_and_module_permissions_are_retained(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $parent = $this->createUnit($schoolId, 'school', 'School of Law');
        $departmentId = (int) Department::query()->create(['school_id' => $schoolId, 'name' => 'Law', 'academic_unit_id' => $parent])->id;
        $this->makeProgramme($schoolId, ['name' => 'LLB', 'code' => 'LLB1', 'department_id' => $departmentId]);
        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->get(route('admin.department.delete', $departmentId))->assertRedirect(route('admin.department.departments'));
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'school_id' => $schoolId]);

        $admin->forceFill(['menu_permission' => json_encode(['admin.department_list'])])->save();
        $this->actingAs($admin->fresh())->get(route('admin.department.departments'))->assertOk();
        $admin->forceFill(['menu_permission' => json_encode([])])->save();
        $this->from(route('admin.dashboard'))->actingAs($admin->fresh())->get(route('admin.department.departments'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_delete_actions_are_available_and_block_academic_records_until_reassigned(): void
    {
        $this->migrateAcademicUnits();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $school = $this->createUnit($schoolId, 'school', 'School of Science');
        $faculty = $this->createUnit($schoolId, 'faculty', 'Faculty of Computing', $school);
        $department = Department::query()->create([
            'school_id' => $schoolId, 'name' => 'Computer Science', 'academic_unit_id' => $faculty,
        ]);
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'BSc Computing', 'code' => 'BSC1', 'department_id' => $department->id]);
        $this->actingAs($admin);

        $this->get(route('admin.department.schools_faculties'))->assertOk()
            ->assertSee('Delete')->assertSee(route('admin.department.units.destroy', $school), false);
        $this->get(route('admin.department.departments'))->assertOk()
            ->assertSee('Delete')->assertSee(route('admin.department.departments.destroy', $department->id), false);

        $this->delete(route('admin.department.units.destroy', $school))
            ->assertRedirect(route('admin.department.schools_faculties'))->assertSessionHas('error');
        $this->delete(route('admin.department.units.destroy', $faculty))
            ->assertRedirect(route('admin.department.schools_faculties'))->assertSessionHas('error');
        $this->delete(route('admin.department.departments.destroy', $department->id))
            ->assertRedirect(route('admin.department.departments'))->assertSessionHas('error');
        $this->assertDatabaseHas('academic_units', ['id' => $school]);
        $this->assertDatabaseHas('departments', ['id' => $department->id]);

        Programme::query()->whereKey($programmeId)->delete();
        if (Schema::hasColumn('users', 'department_id')) {
            DB::table('users')->where('id', $admin->id)->update(['department_id' => $department->id]);
            $this->delete(route('admin.department.departments.destroy', $department->id))
                ->assertRedirect(route('admin.department.departments'))->assertSessionHas('error');
            DB::table('users')->where('id', $admin->id)->update(['department_id' => null]);
        }
        $this->delete(route('admin.department.departments.destroy', $department->id))
            ->assertRedirect(route('admin.department.departments'))->assertSessionHas('success', 'Department deleted.');
        $this->delete(route('admin.department.units.destroy', $faculty))
            ->assertRedirect(route('admin.department.schools_faculties'))->assertSessionHas('success', 'School / Faculty deleted.');
        $this->delete(route('admin.department.units.destroy', $school))
            ->assertRedirect(route('admin.department.schools_faculties'))->assertSessionHas('success', 'School / Faculty deleted.');
        $this->assertDatabaseMissing('academic_units', ['id' => $school]);
        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_migration_can_be_reverted_on_an_empty_new_structure_without_affecting_departments(): void
    {
        $this->migrateAcademicUnits();
        (require database_path('migrations/2026_10_17_140000_detach_legacy_unit_programmes.php'))->down();
        (require database_path('migrations/2026_10_17_130000_promote_legacy_academic_units.php'))->down();
        (require database_path('migrations/2026_10_17_120000_add_codes_and_leadership_to_academic_divisions.php'))->down();
        (require database_path('migrations/2026_10_17_110000_create_academic_units_and_link_departments.php'))->down();
        $this->assertFalse(Schema::hasTable('academic_units'));
        $this->assertFalse(Schema::hasColumn('departments', 'academic_unit_id'));
        $this->assertTrue(Schema::hasTable('departments'));
    }

    public function test_named_legacy_units_are_promoted_without_breaking_department_or_programme_ids(): void
    {
        $schoolId = $this->makeSchool();
        $legacyNames = [
            'FACULTY OF APPLIED SICNCE AND TECHNOLOGY' => 'faculty',
            'FACULTY OF EDUCATION' => 'faculty',
            'FACULTY OF MANAGEMENT AND HUMANITIES' => 'faculty',
            'SCHOOL OF GRADUATE STUDIES AND RESEARCH' => 'school',
            'SCHOOL OF HEALTH SCIENCES' => 'school',
        ];
        $legacyIds = [];
        foreach ($legacyNames as $name => $type) {
            $departmentId = (int) DB::table('departments')->insertGetId(['school_id' => $schoolId, 'name' => $name]);
            $legacyIds[$name] = $departmentId;
            if ($name === 'FACULTY OF EDUCATION') {
                $this->makeProgramme($schoolId, ['name' => 'Education Programme', 'code' => 'EDU1', 'department_id' => $departmentId]);
            }
        }
        $normalDepartmentId = (int) DB::table('departments')->insertGetId(['school_id' => $schoolId, 'name' => 'Department of Education']);

        $this->migrateAcademicUnits();

        foreach ($legacyNames as $name => $type) {
            $unit = AcademicUnit::query()->where('school_id', $schoolId)->where('name', $name)->firstOrFail();
            $this->assertSame($type, $unit->type);
            $this->assertNull($unit->parent_id, 'Unknown parent assignments must remain unguessed.');
            $this->assertSame($legacyIds[$name], (int) $unit->legacy_department_id);
            $this->assertDatabaseHas('departments', ['id' => $legacyIds[$name], 'name' => $name]);
        }

        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->get(route('admin.department.schools_faculties'))
            ->assertOk()->assertSee('FACULTY OF EDUCATION')->assertSee('1');
        $this->get(route('admin.department.departments'))
            ->assertOk()->assertDontSee('<td class="fw-semibold">FACULTY OF EDUCATION</td>', false)->assertSee('Department of Education');
        $this->get(route('admin.department.show', $legacyIds['FACULTY OF EDUCATION']))->assertNotFound();
        $this->get(route('admin.department.departments.edit', $legacyIds['FACULTY OF EDUCATION']))->assertNotFound();
        $this->assertDatabaseHas('programmes', [
            'name' => 'Education Programme', 'department_id' => null,
            'legacy_department_id' => $legacyIds['FACULTY OF EDUCATION'],
        ]);
        $this->assertDatabaseHas('departments', ['id' => $normalDepartmentId, 'name' => 'Department of Education']);
        $this->actingAs($admin)->get(route('admin.programmes.open_modal'))
            ->assertOk()->assertSee('Department of Education')->assertDontSee('FACULTY OF EDUCATION');
        $this->get(route('admin.programmes.index'))->assertOk()
            ->assertSee('Previously under FACULTY OF EDUCATION');
        $this->post(route('admin.programmes.store'), [
            'name' => 'Another Education Programme', 'code' => 'EDU2', 'level' => 'Diploma',
            'mode' => 'Full Time', 'department_id' => $legacyIds['FACULTY OF EDUCATION'],
        ])->assertSessionHasErrors('department_id');
        $educationProgramme = Programme::query()->where('school_id', $schoolId)->where('code', 'EDU1')->firstOrFail();
        $this->postJson(route('admin.programmes.update', $educationProgramme->id), [
            'name' => $educationProgramme->name, 'code' => $educationProgramme->code,
            'level' => $educationProgramme->level, 'mode' => 'Full Time',
            'department_id' => $normalDepartmentId,
        ])->assertOk();
        $this->assertDatabaseHas('programmes', [
            'id' => $educationProgramme->id, 'department_id' => $normalDepartmentId,
            'legacy_department_id' => null,
        ]);
        $faculty = AcademicUnit::query()->where('school_id', $schoolId)->where('name', 'FACULTY OF EDUCATION')->firstOrFail();
        $this->get(route('admin.department.units.show', $faculty->id))->assertOk()->assertSee('Programmes')->assertSee('0');
        $this->delete(route('admin.department.units.destroy', $faculty->id))
            ->assertRedirect(route('admin.department.schools_faculties'))->assertSessionHas('error');
        $this->assertDatabaseHas('academic_units', ['id' => $faculty->id, 'legacy_department_id' => $legacyIds['FACULTY OF EDUCATION']]);
    }

    public function test_legacy_programme_link_can_be_restored_if_the_new_migration_is_rolled_back(): void
    {
        $schoolId = $this->makeSchool();
        $legacyDepartmentId = (int) DB::table('departments')->insertGetId([
            'school_id' => $schoolId, 'name' => 'FACULTY OF EDUCATION',
        ]);
        $this->makeProgramme($schoolId, [
            'name' => 'Education Programme', 'code' => 'EDU1', 'department_id' => $legacyDepartmentId,
        ]);

        $this->migrateAcademicUnits();
        $this->assertDatabaseHas('programmes', ['code' => 'EDU1', 'department_id' => null, 'legacy_department_id' => $legacyDepartmentId]);

        (require database_path('migrations/2026_10_17_140000_detach_legacy_unit_programmes.php'))->down();

        $this->assertDatabaseHas('programmes', ['code' => 'EDU1', 'department_id' => $legacyDepartmentId]);
        $this->assertFalse(Schema::hasColumn('programmes', 'legacy_department_id'));
    }
}
