<?php

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('APP_URL=http://localhost');
require __DIR__.'/../vendor/autoload.php';

class ProgrammeLevelBrowserFixtures extends \Tests\Feature\CustomProgrammeLevelsTest
{
    public function export(): void
    {
        parent::setUp();
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $directory = base_path('output/programme-levels');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        $modal = $this->get(route('admin.programmes.open_modal'))->assertOk()->getContent();
        file_put_contents($directory.'/modal.html', '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css"><link rel="stylesheet" href="/assets/css/workspace-ui.css"></head><body><main style="max-width:1000px;margin:auto;padding:16px">'.$modal.'</main></body></html>');
        echo "Programme level modal exported.\n";
    }
}

(new ProgrammeLevelBrowserFixtures('test_admin_adds_level_then_saves_and_edits_a_programme_using_it'))->export();
