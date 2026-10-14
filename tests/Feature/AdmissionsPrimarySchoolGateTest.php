<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Internal Admissions belongs to each authenticated school. The public site's
 * primary_school_id must not disable another school's own staff workflows.
 */
class AdmissionsPrimarySchoolGateTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require base_path('database/migrations/2026_07_02_130002_create_admissions_agents_table.php'))->up();
    }

    public function test_primary_schools_admin_sees_the_admissions_nav_and_can_access_it(): void
    {
        $primarySchoolId = $this->makeSchool();
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $primarySchoolId, 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->makeAdminUser($primarySchoolId);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.index'));

        $response->assertStatus(200);
        $response->assertSee('Admissions', false);
    }

    public function test_non_primary_schools_admin_can_access_its_own_admissions(): void
    {
        $primarySchoolId = $this->makeSchool();
        $otherSchoolId = $this->makeSchool();
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $primarySchoolId, 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->makeAdminUser($otherSchoolId);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.index'));

        $response->assertOk()->assertSee('Applications');
        $foreignId = $this->makeAdmission($primarySchoolId);
        $this->get(route('admin.hei_admissions.review', $foreignId))->assertNotFound();
        $this->get(route('admin.hei_admissions.wizard.create'))->assertRedirect();
        $this->assertDatabaseHas('admissions', ['school_id' => $otherSchoolId, 'source' => 'staff_entry']);
        $own = \App\Models\Admission::where('school_id', $otherSchoolId)->firstOrFail();
        $this->post(route('admin.hei_admissions.wizard.programme', $own->id), [
            'programme_id' => $this->makeProgramme($primarySchoolId),
            'intake_session_id' => $this->makeIntakeSession($primarySchoolId),
        ])->assertSessionHasErrors(['programme_id', 'intake_session_id']);
        $this->assertNull($own->fresh()->programme_id);
        $ownProgramme = $this->makeProgramme($otherSchoolId);
        $ownIntake = $this->makeIntakeSession($otherSchoolId);
        $this->post(route('admin.hei_admissions.wizard.programme', $own->id), [
            'programme_id' => $ownProgramme, 'intake_session_id' => $ownIntake,
        ])->assertSessionHasNoErrors();
        $this->assertSame($ownProgramme, (int) $own->fresh()->programme_id);
    }

    public function test_non_primary_schools_admin_can_access_its_own_intakes_and_agents(): void
    {
        $primarySchoolId = $this->makeSchool();
        $otherSchoolId = $this->makeSchool();
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $primarySchoolId, 'created_at' => now(), 'updated_at' => now()]);
        $admin = $this->makeAdminUser($otherSchoolId);

        $this->actingAs($admin)->get(route('admin.intake_sessions.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.admissions_agents.index'))->assertOk();
        $foreignIntake = $this->makeIntakeSession($primarySchoolId);
        $this->get(route('admin.intake_sessions.open_modal', ['id' => $foreignIntake]))->assertNotFound();
    }

    public function test_missing_primary_school_setting_fails_open_rather_than_locking_everyone_out(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        // No primary_school_id row inserted at all.

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.index'));

        $response->assertStatus(200);
    }
}
