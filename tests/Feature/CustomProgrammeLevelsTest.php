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

    public function test_programme_categories_are_fixed_and_existing_legacy_categories_remain_editable(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $name = "Bachelor's";
        $this->postJson(route('admin.programmes.levels.store'), ['name' => 'Unlisted Category'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson(route('admin.programmes.store'), ['name' => 'HEC Science', 'code' => 'HECS', 'level' => 'HEC', 'mode' => 'ODEL'])->assertOk();
        $programme = Programme::where('school_id', $school)->where('code', 'HECS')->firstOrFail();
        $this->get(route('admin.programmes.open_modal', ['id' => $programme->id]))->assertOk()->assertSee($name)->assertDontSee('piie-add-level');
        $programme->update(['level' => 'Degree']);
        $this->get(route('admin.programmes.open_modal', ['id' => $programme->id]))->assertOk()->assertSee('Degree (');
        $this->postJson(route('admin.programmes.update', $programme->id), ['name' => 'HEC Science Revised', 'code' => 'HECS', 'level' => 'Degree', 'mode' => 'ODEL'])->assertOk();
        $this->assertDatabaseHas('programmes', ['id' => $programme->id, 'level' => 'Degree', 'name' => 'HEC Science Revised']);
    }

    public function test_category_endpoint_accepts_only_exact_builtin_labels_without_creating_custom_rows(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $url = route('admin.programmes.levels.store');
        $this->postJson($url, ['name' => 'HEC'])->assertOk()->assertJsonPath('name', 'HEC');
        $this->postJson($url, ['name' => '  hec  '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($url, ['name' => 'Certificate'])->assertOk()->assertJsonPath('name', 'Certificate');
        $this->assertSame(0, DB::table('programme_levels')->count());
        $this->postJson($url, ['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($url, ['name' => str_repeat('a', 101)])->assertUnprocessable();
    }

    public function test_custom_category_values_are_rejected_for_every_school(): void
    {
        $one = $this->makeSchool();
        $two = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($one))->postJson(route('admin.programmes.levels.store'), ['name' => 'Private HEC'])->assertUnprocessable();
        $this->actingAs($this->makeAdminUser($two));
        $this->get(route('admin.programmes.open_modal'))->assertOk()->assertDontSee('Private HEC');
        $this->postJson(route('admin.programmes.store'), ['code' => 'HEC', 'name' => 'Test', 'level' => 'Private HEC', 'mode' => 'ODEL'])->assertUnprocessable()->assertJsonValidationErrors('level');
        $this->postJson(route('admin.programmes.levels.store'), ['name' => 'Private HEC'])->assertUnprocessable();
        $this->assertSame(0, DB::table('programme_levels')->count());
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

    public function test_only_requested_categories_are_available_for_admission_document_requirements(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $name = 'HEC';
        $this->get(route('admin.admissions_documents.index'))->assertOk()->assertSee($name)->assertDontSee('Private HEC');
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
