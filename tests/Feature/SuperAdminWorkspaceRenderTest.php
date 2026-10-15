<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class SuperAdminWorkspaceRenderTest extends TestCase
{
    use StaffModuleTestHelper;

    public function test_institution_dashboard_omits_unused_tools_and_keeps_operational_links(): void
    {
        $this->bootStaffModuleTestSchema();
        config(['app.enforce_school_subscriptions' => false]);
        $school = $this->makeSchool();
        // This fixture normally grants a subscription; production has neither.
        DB::table('subscriptions')->delete();
        DB::table('packages')->delete();
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $school]);
        $user = User::factory()->create(['role_id' => 1, 'school_id' => null, 'account_status' => 'active']);
        $response = $this->actingAs($user)->get(route('superadmin.dashboard'));
        $response->assertOk();
        foreach (['superadmin.website.index', 'superadmin.enquiries.index', 'superadmin.system_settings', 'superadmin.payment_settings', 'superadmin.smtp_settings', 'superadmin.google_meet.settings', 'superadmin.library.institutions', 'superadmin.audit_log.index', 'superadmin.school.list'] as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
        foreach (['superadmin.package', 'superadmin.subscription.report', 'superadmin.subscription.pending', 'superadmin.subscription.expired_subcription', 'superadmin.addon.list', 'superadmin.website_settings', 'superadmin.faq_views', 'superadmin.about', 'superadmin.school.add'] as $name) {
            $response->assertDontSee('href="'.route($name).'"', false);
        }
        $response->assertDontSee('assets/amchart/index.js', false);
    }
}
