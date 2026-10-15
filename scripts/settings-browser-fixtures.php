<?php
// Real settings views, isolated synthetic test schema. No external requests.
putenv('APP_ENV=testing');putenv('DB_CONNECTION=sqlite');putenv('DB_DATABASE=:memory:');putenv('APP_URL=http://127.0.0.1:8774');
require __DIR__.'/../vendor/autoload.php';
class SettingsBrowserFixtures extends \Tests\Feature\SettingsOrganisationTest
{
    public function export(): void
    {
        parent::setUp();\Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8774');
        $dir=base_path('output/settings-browser');if(!is_dir($dir))mkdir($dir,0777,true);
        foreach(['notifications'=>'admin.settings.notifications','platforms'=>'admin.settings.teaching_platforms','grading'=>'admin.settings.academic','general'=>'admin.settings.school'] as $name=>$route){
            $response=$this->get(route($route));if($response->getStatusCode()!==200)throw new \RuntimeException('Settings fixture failed: '.$name);file_put_contents($dir.'/'.$name.'.html',$response->getContent());
        }
        echo json_encode(['directory'=>$dir]).PHP_EOL;
    }
}
(new SettingsBrowserFixtures('test_grading_and_platform_screens_have_one_separate_editor'))->export();
