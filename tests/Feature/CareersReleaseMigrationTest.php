<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CareersReleaseMigrationTest extends TestCase
{
    use CreatesPublicSiteSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();
        DB::table('website_pages')->where('page_key', 'careers')->delete();
    }

    public function test_release_publishes_careers_once_and_makes_the_public_page_available(): void
    {
        $migration = require database_path('migrations/2026_10_14_120000_publish_primary_school_careers_page.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, DB::table('website_pages')->where('page_key', 'careers')->count());
        $this->assertSame(3, DB::table('website_sections')->where('page_key', 'careers')->count());
        $this->get('/website/careers')->assertOk()->assertSee('Careers');
    }

    public function test_release_preserves_existing_copy_and_other_schools(): void
    {
        DB::table('website_pages')->insert([
            ['school_id' => 1, 'page_key' => 'careers', 'title' => 'Our opportunities', 'status' => 0],
            ['school_id' => 2, 'page_key' => 'careers', 'title' => 'Other school', 'status' => 0],
        ]);
        DB::table('website_sections')->insert(['school_id' => 1, 'page_key' => 'careers', 'section_key' => 'careers', 'content' => 'Approved institutional wording']);
        $migration = require database_path('migrations/2026_10_14_120000_publish_primary_school_careers_page.php');
        $migration->up();
        $this->assertSame('Our opportunities', DB::table('website_pages')->where('school_id', 1)->where('page_key', 'careers')->value('title'));
        $this->assertSame(1, (int) DB::table('website_pages')->where('school_id', 1)->where('page_key', 'careers')->value('status'));
        $this->assertSame(0, (int) DB::table('website_pages')->where('school_id', 2)->where('page_key', 'careers')->value('status'));
        $this->assertSame('Approved institutional wording', DB::table('website_sections')->where('section_key', 'careers')->value('content'));
        $migration->down();
        $this->assertSame(2, DB::table('website_pages')->where('page_key', 'careers')->count());
    }
}
