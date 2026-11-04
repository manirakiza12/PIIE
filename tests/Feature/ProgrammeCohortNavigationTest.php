<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProgrammeCohortNavigationTest extends TestCase
{
    public function test_programme_cohorts_route_is_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.programme_cohorts.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.programme_cohorts.create'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('admin.programme_cohorts.store'));
    }

    public function test_navigation_partial_includes_cohorts_in_academic_setup(): void
    {
        $path = resource_path('views/admin/partials/academic_navigation.blade.php');
        $contents = file_get_contents($path);
        $this->assertStringContainsString("'admin.programme_cohorts.index'", $contents);
        $this->assertStringContainsString("Programme Cohorts", $contents);
        $this->assertStringContainsString("admin.programme_cohorts.index", $contents);
    }
}
