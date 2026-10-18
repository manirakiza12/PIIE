<?php
namespace Tests\Feature;

use App\Support\Academic\AcademicWorkflowGuide;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class AcademicWorkflowGuideTest extends TestCase
{
    use StaffModuleTestHelper;

    public function test_steps_explain_dependencies_and_links_follow_menu_permissions(): void
    {
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_type')->nullable());
        $user = $this->makeAdminUser($this->makeSchool(['school_type'=>'higher_ed']));
        $guide = app(AcademicWorkflowGuide::class);
        $data = $guide->forRequest($user,Request::create('/admin/course-unit/setup'));
        $this->assertSame('units',$data['current']['key']);
        $this->assertStringContainsString('Programme Years',$data['current']['before']);
        $keys = array_column($data['steps'],'key');
        $this->assertNotContains('teaching-groups', $keys);
        $this->assertNotContains('admin.class_list', array_column($data['steps'], 'route'));
        $this->assertNotContains('admin.programme_cohorts.index', array_column($data['steps'], 'route'));
        $this->assertContains('admin.academic.placements.index', array_column($data['steps'], 'route'));
        $this->assertLessThan(array_search('units',$keys),array_search('curricula',$keys));
        $this->assertLessThan(array_search('lecturers',$keys),array_search('offerings',$keys));
        foreach($data['steps'] as $step) $this->assertTrue(app('router')->getRoutes()->hasNamedRoute($step['route']));
        $user->menu_permission=json_encode(['admin.subject_list']);
        $restricted = $guide->forRequest($user,Request::create('/admin/course-unit/setup'));
        $this->assertSame(['units'],array_column($restricted['steps'],'key'));
        $this->assertNull($guide->forRequest($user,Request::create('/admin/settings/notifications')));
        $semester=$guide->forRequest($user,Request::create('/admin/academic-structure?section=semesters'));
        $this->assertSame('semesters',$semester['current']['key']);
    }

    public function test_existing_secondary_school_pages_do_not_receive_tertiary_guidance(): void
    {
        $this->bootStaffModuleTestSchema();
        $user=$this->makeAdminUser($this->makeSchool());
        $this->assertNull(app(AcademicWorkflowGuide::class)->forRequest($user,Request::create('/admin/subject')));
    }
}
