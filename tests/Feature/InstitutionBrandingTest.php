<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstitutionBrandingTest extends TestCase
{
    use CreatesPublicSiteSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPublicSiteDatabase();
    }

    public function test_branding_uses_own_school_and_reflects_setting_changes(): void
    {
        DB::table('schools')->where('id', 1)->update(['title' => 'Real Institution']);
        $this->assertSame('Real Institution', institution_name());
        $this->actingAs(new User(['role_id' => 1]));
        $this->assertSame('Real Institution', institution_name());
        DB::table('schools')->insert(['id' => 2, 'title' => 'Other Institution']);
        $this->actingAs(new User(['school_id' => 2]));
        $this->assertSame('Other Institution', institution_name());
        DB::table('schools')->where('id', 2)->update(['title' => 'Renamed Institution']);
        $this->assertSame('Renamed Institution', institution_name());
        $this->actingAs(new User(['school_id' => 999]));
        $this->assertSame('Institution Portal', institution_name());
    }

    public function test_placeholder_correction_preserves_other_schools_and_custom_names(): void
    {
        DB::table('schools')->where('id', 1)->update(['title' => 'PIIE Test School']);
        DB::table('schools')->insert(['id' => 2, 'title' => 'PIIE Test School']);
        $migration = require database_path('migrations/2026_10_15_080000_correct_primary_institution_test_name.php');
        $migration->up();
        $this->assertSame('Prime International Institute of Excellence', institution_name());
        $this->assertSame('PIIE Test School', DB::table('schools')->where('id', 2)->value('title'));
        DB::table('schools')->where('id', 1)->update(['title' => 'Approved New Name']);
        $migration->up();
        $migration->down();
        $this->assertSame('Approved New Name', institution_name());
    }
}
