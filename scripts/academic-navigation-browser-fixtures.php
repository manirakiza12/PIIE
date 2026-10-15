<?php
// Real Laravel pages, isolated in-memory test database. No production access.
putenv('APP_ENV=testing');putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE=:memory:');
putenv('APP_URL=http://127.0.0.1:8774');
require __DIR__.'/../vendor/autoload.php';
class AcademicNavigationBrowserFixtures extends \Tests\Feature\CourseOfferingAdministrationTest
{
    public function export(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8774');
        $admin=\App\Models\User::factory()->create(['school_id'=>1,'role_id'=>2,'account_status'=>'active']);
        $this->actingAs($admin);
        \Illuminate\Support\Facades\DB::table('schools')->where('id',1)->update(['current_academic_year_id'=>1,'current_academic_period_id'=>1]);
        $directory=base_path('output/academic-navigation-browser');if(!is_dir($directory))mkdir($directory,0777,true);
        foreach(['years'=>route('admin.academic_structure.index'),'semesters'=>route('admin.academic_structure.index',['section'=>'semesters']),'curricula'=>route('admin.curricula.index'),'curriculum'=>route('admin.curricula.show',1),'create-curriculum'=>route('admin.curricula.create'),'registrations'=>route('admin.course_offerings.index')] as $name=>$url){
            $response=$this->get($url);if($response->getStatusCode()!==200)throw new \RuntimeException('Fixture failed: '.$name.' HTTP '.$response->getStatusCode().' '.substr(strip_tags($response->getContent()),0,800));file_put_contents($directory.'/'.$name.'.html',$response->getContent());
        }
        echo json_encode(['directory'=>$directory]).PHP_EOL;
    }
}
(new AcademicNavigationBrowserFixtures('test_view_routes_are_authorized_tenant_scoped_and_menu_permission_does_not_grant_access'))->export();
