<?php

namespace Tests\Feature;

use App\Models\Programme;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class CustomProgrammeLevelsTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_14_180000_add_custom_programme_levels.php'))->up();
    }

    public function test_admin_adds_level_then_saves_and_edits_a_programme_using_it(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $name = 'Higher Education Certificate (HEC)';
        $this->postJson(route('admin.programmes.levels.store'), ['name' => $name, 'school_id' => 999])
            ->assertOk()->assertJsonPath('name', $name);
        $this->assertDatabaseHas('programme_levels', ['school_id' => $school, 'name' => $name]);
        $this->postJson(route('admin.programmes.store'), ['name' => 'HEC Science', 'code' => 'HECS', 'level' => $name, 'mode' => 'ODEL'])->assertOk();
        $programme = Programme::where('school_id', $school)->where('code', 'HECS')->firstOrFail();
        $this->get(route('admin.programmes.open_modal', ['id' => $programme->id]))->assertOk()->assertSee($name)->assertSee('piie-add-level');
        $this->postJson(route('admin.programmes.update', $programme->id), ['name' => 'HEC Science Revised', 'code' => 'HECS', 'level' => $name, 'mode' => 'ODEL'])->assertOk();
        $this->assertDatabaseHas('programmes', ['id' => $programme->id, 'level' => $name, 'name' => 'HEC Science Revised']);
    }

    public function test_duplicates_reuse_the_existing_level_and_builtins(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $url = route('admin.programmes.levels.store');
        $this->postJson($url, ['name' => 'HEC'])->assertOk()->assertJsonPath('name', 'HEC');
        $this->postJson($url, ['name' => '  hec  '])->assertOk()->assertJsonPath('name', 'HEC');
        $this->postJson($url, ['name' => ' certificate '])->assertOk()->assertJsonPath('name', 'Certificate');
        $this->assertSame(1, DB::table('programme_levels')->count());
        $this->postJson($url, ['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($url, ['name' => str_repeat('a', 101)])->assertUnprocessable();
    }

    public function test_custom_levels_are_isolated_to_their_school(): void
    {
        $one = $this->makeSchool();
        $two = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($one))->postJson(route('admin.programmes.levels.store'), ['name' => 'Private HEC'])->assertOk();
        $this->actingAs($this->makeAdminUser($two));
        $this->get(route('admin.programmes.open_modal'))->assertOk()->assertDontSee('Private HEC');
        $this->postJson(route('admin.programmes.store'), ['code' => 'HEC', 'name' => 'Test', 'level' => 'Private HEC', 'mode' => 'ODEL'])->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->postJson(route('admin.programmes.levels.store'), ['name' => 'Private HEC'])->assertOk();
        $this->assertSame(2, DB::table('programme_levels')->count());
    }

    public function test_student_cannot_create_a_level(): void
    {
        $school = $this->makeSchool();
        $student = $this->makeAdminUser($school);
        $student->update(['role_id' => 7]);
        $response = $this->actingAs($student)->postJson(route('admin.programmes.levels.store'), ['name' => 'HEC']);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, DB::table('programme_levels')->count());
    }

    public function test_staff_without_programme_permission_cannot_create_a_level(): void
    {
        $school = $this->makeSchool();
        $staff = $this->makeAdminUser($school);
        $staff->update(['role_id' => 20]);
        $response = $this->actingAs($staff)->postJson(route('admin.programmes.levels.store'), ['name' => 'HEC']);
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertSame(0, DB::table('programme_levels')->count());
    }

    public function test_custom_level_is_available_for_admission_document_requirements(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $name = 'Higher Education Certificate (HEC)';
        $this->postJson(route('admin.programmes.levels.store'), ['name' => $name])->assertOk();
        $this->get(route('admin.admissions_documents.index'))->assertOk()->assertSee($name);
        $this->post(route('admin.admissions_documents.store'), [
            'key' => 'hec_results', 'label' => 'HEC Results', 'applies_to_levels' => [$name],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('admission_document_requirements', ['school_id' => $school, 'key' => 'hec_results']);
    }

    public function test_mysql_migration_preview_emits_the_column_widening_without_query_results(): void
    {
        $database = DB::getFacadeRoot();
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $connection = new \Illuminate\Database\MySqlConnection(DB::connection()->getPdo(), 'preview', '', ['driver' => 'mysql']);
        DB::swap($connection);
        \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(function () {
                (require database_path('migrations/2026_10_14_180000_add_custom_programme_levels.php'))->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('MODIFY `level` VARCHAR(100)', $sql);
            $this->assertStringContainsString('create table `programme_levels`', $sql);
        } finally {
            DB::swap($database);
            \Illuminate\Support\Facades\Schema::swap($schema);
        }
    }
}
