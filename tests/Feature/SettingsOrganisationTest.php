<?php
namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings\SettingsNavigation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class SettingsOrganisationTest extends TestCase
{
    use StaffModuleTestHelper;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $school=$this->makeSchool();
        $this->admin=$this->makeAdminUser($school);
        $this->actingAs($this->admin);
    }

    public function test_grading_and_platform_screens_have_one_separate_editor(): void
    {
        $this->get(route('admin.settings.academic'))->assertOk()->assertSee('id="gradeForm"', false)->assertDontSee('name="live_class_platform_jitsi"', false)->assertDontSee('aria-label="Settings sections"', false);
        $this->get(route('admin.settings.teaching_platforms'))->assertOk()->assertSee('name="live_class_platform_jitsi"', false)->assertDontSee('id="gradeForm"', false);
        $page=$this->get(route('admin.settings.notifications'))->assertOk()->assertSee('data-notification-switch', false)->assertDontSee('id="gradeForm"', false);
        $doc=new \DOMDocument();@$doc->loadHTML($page->getContent());$xp=new \DOMXPath($doc);
        $this->assertSame(9, $xp->query('//input[@data-notification-switch]')->length);
        $this->assertSame(0, $xp->query('//nav[@aria-label="Settings sections"]//a[contains(@href,"/settings/academic")]')->length);
    }

    public function test_grading_save_does_not_reset_teaching_platforms(): void
    {
        DB::table('global_settings')->insert(['key'=>'live_class_platform_zoom','value'=>'1']);
        $this->post(route('admin.settings.academic.save'),['_settings_section'=>'grading','grade'=>[['name'=>'A','mark_from'=>80,'mark_upto'=>100,'gpa_points'=>4.5]]])->assertRedirect();
        $this->assertSame('1',DB::table('global_settings')->where('key','live_class_platform_zoom')->value('value'));
        $this->assertDatabaseHas('grades',['school_id'=>$this->admin->school_id,'name'=>'A','gpa_points'=>4.5]);
    }

    public function test_platform_save_preserves_grades_even_if_a_grade_field_is_supplied(): void
    {
        $grade=$this->makeGrade($this->admin->school_id);
        $before=DB::table('grades')->where('id',$grade)->first();
        $this->post(route('admin.settings.academic.save'),['_settings_section'=>'platforms','live_class_platform_zoom'=>'1','grade'=>[['name'=>'REPLACE']]])->assertRedirect();
        $this->assertEquals($before,DB::table('grades')->where('id',$grade)->first());
        $this->assertSame('1',DB::table('global_settings')->where('key','live_class_platform_zoom')->value('value'));
    }

    public function test_notification_save_preserves_existing_keys_and_does_not_touch_grading_or_platforms(): void
    {
        $grade=$this->makeGrade($this->admin->school_id);
        DB::table('global_settings')->insert(['key'=>'live_class_platform_zoom','value'=>'1']);
        $this->post(route('admin.settings.notifications.save'),['notif_new_student'=>'on','sms_fee_paid'=>'on'])->assertRedirect();
        $this->assertSame('1',DB::table('global_settings')->where('key','notif_new_student')->value('value'));
        $this->assertSame('0',DB::table('global_settings')->where('key','notif_fee_paid')->value('value'));
        $this->assertSame('1',DB::table('global_settings')->where('key','sms_fee_paid')->value('value'));
        $this->assertSame('1',DB::table('global_settings')->where('key','live_class_platform_zoom')->value('value'));
        $this->assertDatabaseHas('grades',['id'=>$grade]);
    }

    public function test_admissions_copy_address_is_validated_and_saved_for_only_this_institution(): void
    {
        $key = \App\Support\Admissions\AdmissionsNotificationCopy::settingKey((int) $this->admin->school_id);
        $this->post(route('admin.settings.notifications.save'), ['admissions_notification_email' => 'not-an-email'])
            ->assertSessionHasErrors('admissions_notification_email');
        $this->assertNull(DB::table('global_settings')->where('key', $key)->value('value'));
        $this->post(route('admin.settings.notifications.save'), ['admissions_notification_email' => 'admissions@example.test'])->assertRedirect();
        $this->assertSame('admissions@example.test', DB::table('global_settings')->where('key', $key)->value('value'));
        $this->get(route('admin.settings.notifications'))->assertOk()->assertSee('admissions@example.test');
        $this->assertNull(\App\Support\Admissions\AdmissionsNotificationCopy::recipient($this->makeSchool()));
    }

    public function test_legacy_combined_save_still_updates_both_sets(): void
    {
        $this->post(route('admin.settings.academic.save'),['grade'=>[['name'=>'B','mark_from'=>60,'mark_upto'=>79]],'live_class_platform_zoom'=>'1'])->assertRedirect();
        $this->assertDatabaseHas('grades',['school_id'=>$this->admin->school_id,'name'=>'B']);
        $this->assertSame('1',DB::table('global_settings')->where('key','live_class_platform_zoom')->value('value'));
    }

    public function test_settings_navigation_respects_role_permissions_and_legacy_menu_restrictions(): void
    {
        $staff=User::factory()->create(['school_id'=>$this->admin->school_id,'role_id'=>3,'account_status'=>'active']);
        $this->actingAs($staff)->get(route('admin.settings.teaching_platforms'))->assertForbidden();
        $this->assertSame([],app(SettingsNavigation::class)->items($staff));
        $this->admin->menu_permission=json_encode(['admin.course_offerings.index']);
        $this->assertSame([],app(SettingsNavigation::class)->items($this->admin));
    }
}
