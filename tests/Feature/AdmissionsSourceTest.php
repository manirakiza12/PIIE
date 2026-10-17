<?php

namespace Tests\Feature;

use App\Models\Admission;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdmissionsSourceTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_application_started_in_the_applicant_portal_is_always_tagged_source_public(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $this->makeProgramme($schoolId);

        // Registering and opening the portal is what creates the application
        // now; there is no anonymous submit endpoint to post to.
        $this->post(route('applicant.register.submit'), [
            'first_name' => 'Alice',
            'last_name' => 'Applicant',
            'email' => 'alice@example.com',
            'phone_code' => '+256',
            'phone_number' => '0700111222',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'terms' => '1',
        ]);

        $this->get(route('applicant.dashboard'));

        $admission = Admission::first();
        $this->assertNotNull($admission);
        $this->assertSame('public', $admission->source);
    }

    public function test_staff_created_application_is_always_tagged_source_staff_entry_even_if_source_is_posted(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.store'), [
            'first_name' => 'Staff',
            'last_name' => 'Entered',
            'email' => 'staffentered@example.com',
            'phone' => '0711222333',
            'programme_id' => $programmeId,
            // An applicant/staff member must never be able to inject their
            // own source value — the controller must ignore this and force
            // staff_entry regardless of what's posted.
            'source' => 'public',
        ]);

        $admission = Admission::where('email', 'staffentered@example.com')->first();
        $this->assertNotNull($admission);
        $response->assertRedirect(route('admin.hei_admissions.wizard.step', [$admission->id, 'personal']))
            ->assertSessionHas('success');

        $this->assertSame('staff_entry', $admission->source);
    }

    public function test_pre_existing_admissions_default_to_staff_entry(): void
    {
        $schoolId = $this->makeSchool();
        $admissionId = $this->makeAdmission($schoolId);

        $admission = Admission::find($admissionId);
        $this->assertSame('staff_entry', $admission->source);
    }

    public function test_admissions_index_can_be_filtered_by_source(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->makeAdmission($schoolId, ['source' => 'public', 'email' => 'pub@example.com']);
        $this->makeAdmission($schoolId, ['source' => 'staff_entry', 'email' => 'staff@example.com']);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.index', ['source' => 'public']));

        $response->assertStatus(200);
        $response->assertSee('pub@example.com');
        $response->assertDontSee('staff@example.com');
    }

    public function test_public_application_from_one_school_never_appears_in_another_schools_admissions_index(): void
    {
        $schoolA = $this->makeSchool(['title' => 'School A']);
        $schoolB = $this->makeSchool(['title' => 'School B']);
        $adminB = $this->makeAdminUser($schoolB);

        $this->makeAdmission($schoolA, ['source' => 'public', 'email' => 'schoolA-applicant@example.com']);

        $response = $this->actingAs($adminB)->get(route('admin.hei_admissions.index', ['source' => 'public']));

        $response->assertStatus(200);
        $response->assertDontSee('schoolA-applicant@example.com');
    }
}
