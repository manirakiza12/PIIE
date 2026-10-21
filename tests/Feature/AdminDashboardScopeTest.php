<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\ApplicationPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the fix that separated the School Admin dashboard from the
 * Super Admin (platform) dashboard — AdminController::adminDashboard()
 * used to query platform-wide tables (schools, subscriptions) with no
 * school_id filter at all, leaking every school's aggregate data to
 * every School Admin.
 */
class AdminDashboardScopeTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    public function test_admin_dashboard_renders_and_never_mentions_platform_widgets(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);

        // The old (buggy) view/controller exposed these Super-Admin-only
        // concepts on the School Admin's own dashboard — they must never
        // reappear here.
        $response->assertDontSee('Total Schools', false);
        $response->assertDontSee('Add School', false);
        $response->assertDontSee('Active Subscriptions', false);
        $response->assertDontSee('Pending Requests', false);

        // The school-scoped replacements must be present instead.
        $response->assertSee('Total Students', false);
        $response->assertSee('Teachers', false);
        $response->assertSee('Fee Collected This Month', false);
    }

    public function test_ai_teaching_assistant_shortcut_is_only_shown_when_superadmin_enables_it(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertDontSee('AI Teaching Assistant Pro');

        DB::table('global_settings')->insert(['key' => 'ai_teaching_assistant_enabled', 'value' => '1']);
        $response = $this->get(route('admin.dashboard'))->assertOk()
            ->assertSee('AI Teaching Assistant Pro')
            ->assertSee('https://www.aiteachingassistantpro.ca/', false)
            ->assertSee('rel="noopener noreferrer"', false);

        $superAdmin = User::factory()->create(['role_id' => 1, 'school_id' => null]);
        $this->actingAs($superAdmin)->get(route('superadmin.system_settings'))
            ->assertOk()->assertSee('Enable shortcuts for Admins and Lecturers');
        $this->post(route('superadmin.system.update'), ['ai_teaching_assistant_enabled' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0', get_settings('ai_teaching_assistant_enabled'));
        $this->from(route('superadmin.system_settings'))
            ->post(route('superadmin.system.update'), ['ai_teaching_assistant_enabled' => 'enabled'])
            ->assertRedirect(route('superadmin.system_settings'))
            ->assertSessionHasErrors('ai_teaching_assistant_enabled');
        $this->assertSame('0', get_settings('ai_teaching_assistant_enabled'));
    }

    public function test_admin_dashboard_counts_are_scoped_to_the_admins_own_school(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();

        $adminA = $this->makeAdminUser($schoolA);

        // 3 students in school A, 5 in school B.
        User::factory()->count(3)->create(['role_id' => 7, 'school_id' => $schoolA]);
        User::factory()->count(5)->create(['role_id' => 7, 'school_id' => $schoolB]);

        $response = $this->actingAs($adminA)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        // School A's admin must see 3 (their own), never 8 (the platform total).
        $response->assertSee('>3<', false);
        $response->assertDontSee('>8<', false);
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'primary_school_id'],
            ['value' => (string) $schoolId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function test_needs_your_action_panel_lists_pending_items_with_links(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);

        $submittedId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_SUBMITTED]);

        ApplicationPayment::create([
            'school_id' => $schoolId,
            'admission_id' => $submittedId,
            'amount' => 100,
            'method' => 'offline',
            'status' => ApplicationPayment::STATUS_PENDING,
        ]);

        AdmissionDocument::create([
            'school_id' => $schoolId,
            'admission_id' => $submittedId,
            'original_name' => 'id.pdf',
            'stored_name' => 'stored.pdf',
            'status' => AdmissionDocument::STATUS_PENDING,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Needs Your Action');
        $response->assertSee('New application(s) awaiting first review');
        $response->assertSee('Application fee payment(s) awaiting confirmation');
        $response->assertSee('Uploaded document(s) awaiting verification');
        $response->assertSee(route('admin.hei_admissions.index', ['status' => Admission::STATUS_SUBMITTED]), false);
    }

    public function test_needs_your_action_panel_is_absent_when_nothing_is_pending(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);

        // An already-enrolled application with everything settled should
        // never show up as something needing attention.
        $this->makeAdmission($schoolId, ['status' => Admission::STATUS_ENROLLED, 'fee_status' => Admission::FEE_PAID]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $response->assertDontSee('Needs Your Action');
    }

    public function test_non_primary_school_dashboard_shows_only_its_own_admissions_action_items(): void
    {
        $primarySchoolId = $this->makeSchool();
        $otherSchoolId = $this->makeSchool();
        $this->configurePrimarySchool($primarySchoolId);

        $admin = $this->makeAdminUser($otherSchoolId);
        $this->makeAdmission($otherSchoolId, ['status' => Admission::STATUS_SUBMITTED]);
        $this->makeAdmission($primarySchoolId, ['status' => Admission::STATUS_SUBMITTED, 'first_name' => 'Foreign applicant']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertStatus(200);
        $response->assertSee('Needs Your Action')->assertDontSee('Foreign applicant');
    }
}
