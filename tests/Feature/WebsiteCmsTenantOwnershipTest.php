<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * Security Phase 2H — website CMS content is school-owned (school_id on
 * website_pages / sections / items / settings / seo_settings).
 *
 * Before 2H (measured in the Phase 2G probe) any school's staff had full
 * CRUD over the one set of CMS rows, including deleting the public site's
 * pages. Now school staff manage only their own school's rows, Super Admin
 * manages the public site's school (global_settings.primary_school_id via
 * App\Support\PublicTenantResolver), and the public pages render that
 * school's content exactly as before.
 *
 * The CMS schema is built by running the real migrations, so the new
 * 2026_09_23_000002 migration (up, backfill, down) is exercised as well.
 */
class WebsiteCmsTenantOwnershipTest extends TestCase
{
    use StaffModuleTestHelper;

    private const MIGRATIONS = [
        'database/migrations/2026_06_24_000001_create_website_management_tables.php',
        'database/migrations/2026_06_27_000002_ensure_website_management_schema_integrity.php',
        'database/migrations/2026_06_27_000003_add_page_header_and_navigation_fields_to_website_pages.php',
    ];
    private const ADD_SCHOOL_ID = 'database/migrations/2026_09_23_000002_add_school_id_to_website_tables.php';
    private const TABLES = ['website_pages', 'website_sections', 'website_items', 'website_settings', 'website_seo_settings'];

    private array $A;
    private array $B;
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2h-cms-' . uniqid();
        File::ensureDirectoryExists($this->publicDir . '/assets/uploads/website');
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

        foreach (self::MIGRATIONS as $path) {
            $this->migration($path)->up();
        }
        $this->migration(self::ADD_SCHOOL_ID)->up();

        $this->A = $this->world('A');
        $this->B = $this->world('B');
        $this->setting('primary_school_id', (string) $this->A['school']);
        $this->setting('frontend_view', '1');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function migration(string $path)
    {
        return require base_path($path);
    }

    private function setting(string $key, string $value): void
    {
        DB::table('global_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }

    /** A school with an admin and one of each CMS row; School A is the public-site school. */
    private function world(string $tag): array
    {
        $school = $this->makeSchool(['title' => "School {$tag}", 'status' => 1]);
        $w = [
            'tag' => $tag,
            'school' => $school,
            'admin' => User::factory()->create(['role_id' => 2, 'school_id' => $school, 'account_status' => 'active']),
        ];
        $w['page'] = DB::table('website_pages')->insertGetId(['school_id' => $school, 'page_key' => 'about', 'slug' => 'about', 'title' => "About{$tag} Zq", 'status' => 1, 'sort_order' => 0]);
        $w['section'] = DB::table('website_sections')->insertGetId(['school_id' => $school, 'page_key' => 'about', 'section_key' => 'hero', 'title' => "Hero{$tag} Zq", 'image' => "sec{$tag}.png", 'status' => 1, 'sort_order' => 0]);
        $w['item'] = DB::table('website_items')->insertGetId(['school_id' => $school, 'section_key' => 'hero', 'item_type' => 'general', 'title' => "Item{$tag} Zq", 'image' => "item{$tag}.png", 'status' => 1, 'sort_order' => 0]);
        $w['setting'] = DB::table('website_settings')->insertGetId(['school_id' => $school, 'key' => 'motto', 'value' => "Motto{$tag} Zq", 'is_json' => 0, 'status' => 1]);
        $w['seo'] = DB::table('website_seo_settings')->insertGetId(['school_id' => $school, 'page_key' => 'about', 'meta_title' => "Seo{$tag} Zq", 'status' => 1]);
        foreach (["sec{$tag}.png", "item{$tag}.png"] as $file) {
            file_put_contents($this->publicDir . "/assets/uploads/website/{$file}", 'x');
        }

        return $w;
    }

    private function row(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    private function unchanged(string $table, int $id, array $before, string $message): void
    {
        $after = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($after, "{$message}: School B row deleted");
        $this->assertEquals($before, (array) $after, "{$message}: School B row changed");
    }

    private function sectionPayload(array $extra = []): array
    {
        return $extra + ['page_key' => 'about', 'section_key' => 'hero', 'title' => 'HIJACKED', 'status' => 1];
    }

    // ── Migration ────────────────────────────────────────────────────────────

    public function test_migration_adds_indexed_school_id_and_per_school_unique_keys_and_rolls_back(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'school_id'), $table);
            $this->assertContains("{$table}_school_id_index", collect(DB::select("PRAGMA index_list('{$table}')"))->pluck('name')->all());
        }
        $pageIndexes = collect(DB::select("PRAGMA index_list('website_pages')"))->pluck('name')->all();
        $this->assertContains('website_pages_school_id_page_key_unique', $pageIndexes);
        $this->assertNotContains('website_pages_page_key_unique', $pageIndexes);

        // Two schools may now both have an "about" page (setUp created one each) — but not two in one school.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('website_pages')->insert(['school_id' => $this->A['school'], 'page_key' => 'about', 'title' => 'dup']);
    }

    public function test_migration_rolls_back_to_the_original_schema(): void
    {
        DB::table('website_pages')->where('id', $this->B['page'])->delete();   // down() restores the platform-wide unique
        DB::table('website_settings')->where('id', $this->B['setting'])->delete();
        DB::table('website_seo_settings')->where('id', $this->B['seo'])->delete();

        $this->migration(self::ADD_SCHOOL_ID)->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'school_id'), $table);
        }
        $this->assertContains('website_pages_page_key_unique', collect(DB::select("PRAGMA index_list('website_pages')"))->pluck('name')->all());
        $this->assertSame(1, DB::table('website_pages')->count(), 'rollback keeps content');
    }

    public function test_migration_backfills_existing_content_to_the_configured_public_school_only(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
        $this->migration(self::ADD_SCHOOL_ID)->down();
        DB::table('website_pages')->insert(['page_key' => 'home', 'slug' => 'home', 'title' => 'Legacy', 'status' => 1]);
        DB::table('website_settings')->insert(['key' => 'motto', 'value' => 'Legacy', 'is_json' => 0, 'status' => 1]);

        // primary_school_id configured → deterministic backfill.
        $this->migration(self::ADD_SCHOOL_ID)->up();
        $this->assertEquals($this->A['school'], DB::table('website_pages')->value('school_id'));
        $this->assertEquals($this->A['school'], DB::table('website_settings')->value('school_id'));

        // Not configured → rows are left unattributed rather than guessed.
        $this->migration(self::ADD_SCHOOL_ID)->down();
        DB::table('global_settings')->where('key', 'primary_school_id')->delete();
        $this->migration(self::ADD_SCHOOL_ID)->up();
        $this->assertNull(DB::table('website_pages')->value('school_id'));
        $this->assertNull(DB::table('website_settings')->value('school_id'));
    }

    // ── School administration ────────────────────────────────────────────────

    public function test_school_cms_list_shows_only_its_own_content(): void
    {
        $response = $this->actingAs($this->B['admin'])->get(route('admin.website.index'));

        $response->assertOk();
        foreach (['AboutB Zq', 'HeroB Zq', 'ItemB Zq', 'MottoB Zq', 'SeoB Zq'] as $own) {
            $response->assertSee($own);
        }
        foreach (['AboutA Zq', 'HeroA Zq', 'ItemA Zq', 'MottoA Zq', 'SeoA Zq'] as $foreign) {
            $response->assertDontSee($foreign);
        }
    }

    public function test_a_school_cannot_edit_or_delete_another_schools_pages_sections_or_items(): void
    {
        $admin = $this->B['admin'];
        $before = [];
        foreach (['page' => 'website_pages', 'section' => 'website_sections', 'item' => 'website_items'] as $k => $t) {
            $before[$k] = $this->row($t, $this->A[$k]);
        }

        $this->actingAs($admin)->post(route('admin.website.page.update', $this->A['page']), ['page_key' => 'about', 'title' => 'HIJACKED'])->assertNotFound();
        $this->actingAs($admin)->get(route('admin.website.page.delete', $this->A['page']))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.website.section.update', $this->A['section']), $this->sectionPayload())->assertNotFound();
        $this->actingAs($admin)->get(route('admin.website.section.delete', $this->A['section']))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.website.item.update', $this->A['item']), ['section_key' => 'hero', 'title' => 'HIJACKED'])->assertNotFound();
        $this->actingAs($admin)->get(route('admin.website.item.delete', $this->A['item']))->assertNotFound();

        $this->unchanged('website_pages', $this->A['page'], $before['page'], 'page');
        $this->unchanged('website_sections', $this->A['section'], $before['section'], 'section');
        $this->unchanged('website_items', $this->A['item'], $before['item'], 'item');
        $this->assertFileExists($this->publicDir . '/assets/uploads/website/secA.png');
        $this->assertFileExists($this->publicDir . '/assets/uploads/website/itemA.png');
    }

    public function test_a_school_upload_cannot_replace_another_schools_website_image(): void
    {
        $before = $this->row('website_sections', $this->A['section']);

        $this->actingAs($this->B['admin'])->post(route('admin.website.section.update', $this->A['section']), $this->sectionPayload([
            'image' => UploadedFile::fake()->image('replacement.png'),
        ]))->assertNotFound();
        $this->actingAs($this->B['admin'])->post(route('admin.website.item.update', $this->A['item']), [
            'section_key' => 'hero', 'title' => 'x', 'image' => UploadedFile::fake()->image('replacement.png'),
        ])->assertNotFound();

        $this->unchanged('website_sections', $this->A['section'], $before, 'section image');
        $this->assertFileExists($this->publicDir . '/assets/uploads/website/secA.png');
        $this->assertSame('itemA.png', DB::table('website_items')->where('id', $this->A['item'])->value('image'));
        $this->assertFileExists($this->publicDir . '/assets/uploads/website/itemA.png');
    }

    public function test_a_school_cannot_modify_another_schools_settings_or_seo(): void
    {
        $setting = $this->row('website_settings', $this->A['setting']);
        $seo = $this->row('website_seo_settings', $this->A['seo']);

        $this->actingAs($this->B['admin'])->post(route('admin.website.settings.upsert'), ['settings' => [['key' => 'motto', 'value' => 'Changed by B', 'school_id' => $this->A['school']]]]);
        $this->actingAs($this->B['admin'])->post(route('admin.website.seo.upsert'), ['seo' => [['page_key' => 'about', 'meta_title' => 'Changed by B']]]);

        $this->unchanged('website_settings', $this->A['setting'], $setting, 'setting');
        $this->unchanged('website_seo_settings', $this->A['seo'], $seo, 'seo');
        // …and B's own rows were the ones updated.
        $this->assertSame('Changed by B', DB::table('website_settings')->where('id', $this->B['setting'])->value('value'));
        $this->assertSame('Changed by B', DB::table('website_seo_settings')->where('id', $this->B['seo'])->value('meta_title'));
    }

    public function test_same_school_cms_editing_still_works(): void
    {
        $admin = $this->B['admin'];

        // Page keys are unique per school, not across the platform.
        $this->actingAs($admin)->post(route('admin.website.page.store'), ['page_key' => 'home', 'title' => 'Home B', 'slug' => 'home']);
        $this->assertEquals($this->B['school'], DB::table('website_pages')->where('title', 'Home B')->value('school_id'));
        $this->actingAs($admin)->post(route('admin.website.page.store'), ['page_key' => 'about', 'title' => 'Second about']);
        $this->assertSame(0, DB::table('website_pages')->where('title', 'Second about')->count(), 'duplicate page_key within one school');

        $this->actingAs($admin)->post(route('admin.website.page.update', $this->B['page']), ['page_key' => 'about', 'title' => 'About B edited']);
        $this->assertSame('About B edited', DB::table('website_pages')->where('id', $this->B['page'])->value('title'));

        $this->actingAs($admin)->post(route('admin.website.section.store'), $this->sectionPayload(['title' => 'New section B']));
        $this->assertEquals($this->B['school'], DB::table('website_sections')->where('title', 'New section B')->value('school_id'));
        $this->actingAs($admin)->post(route('admin.website.item.store'), ['section_key' => 'hero', 'title' => 'New item B', 'school_id' => $this->A['school']]);
        $this->assertEquals($this->B['school'], DB::table('website_items')->where('title', 'New item B')->value('school_id'), 'submitted school_id ignored');

        $this->actingAs($admin)->post(route('admin.website.settings.upsert'), ['settings' => [['key' => 'tagline', 'value' => 'New B']]]);
        $this->assertEquals($this->B['school'], DB::table('website_settings')->where('key', 'tagline')->value('school_id'));

        $this->actingAs($admin)->get(route('admin.website.item.delete', $this->B['item']));
        $this->assertNull(DB::table('website_items')->where('id', $this->B['item'])->first());
    }

    // ── Super Admin ──────────────────────────────────────────────────────────

    public function test_super_admin_manages_the_public_site_school(): void
    {
        // The Super Admin layout counts pending subscription payments.
        Schema::create('payment_history', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->timestamps();
        });
        $superAdmin = User::factory()->create(['role_id' => 1, 'school_id' => null, 'account_status' => 'active']);

        $index = $this->actingAs($superAdmin)->get(route('superadmin.website.index'));
        $index->assertOk();
        $index->assertSee('AboutA Zq');
        $index->assertDontSee('AboutB Zq');

        $this->actingAs($superAdmin)->post(route('superadmin.website.page.update', $this->A['page']), ['page_key' => 'about', 'title' => 'About A by super admin']);
        $this->assertSame('About A by super admin', DB::table('website_pages')->where('id', $this->A['page'])->value('title'));

        $this->actingAs($superAdmin)->post(route('superadmin.website.settings.upsert'), ['settings' => [['key' => 'motto', 'value' => 'Platform motto']]]);
        $this->assertSame('Platform motto', DB::table('website_settings')->where('id', $this->A['setting'])->value('value'));
        $this->assertSame('MottoB Zq', DB::table('website_settings')->where('id', $this->B['setting'])->value('value'));
    }

    // ── Public website ───────────────────────────────────────────────────────

    public function test_public_pages_render_the_public_site_schools_content_only(): void
    {
        $response = $this->get(route('website.page', 'about'));

        $response->assertOk();
        $response->assertSee('AboutA Zq');
        $response->assertSee('HeroA Zq');
        $response->assertDontSee('AboutB Zq');
        $response->assertDontSee('HeroB Zq');
        $response->assertDontSee('ItemB Zq');
        $response->assertDontSee('MottoB Zq');
    }

    public function test_public_pages_still_render_unattributed_legacy_content(): void
    {
        // An install without primary_school_id keeps its existing (school_id NULL) site visible.
        DB::table('website_pages')->insert(['page_key' => 'legacy', 'slug' => 'legacy', 'title' => 'Legacy Page Zq', 'status' => 1, 'sort_order' => 0]);

        $this->get(route('website.page', 'legacy'))->assertOk()->assertSee('Legacy Page Zq');
    }
}
