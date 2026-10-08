<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;
use RuntimeException;

class SanctumExpiryMigrationTest extends TestCase
{
    use StaffModuleTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
    }

    private function original(): void
    {
        (require base_path('database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php'))->up();
    }

    private function migration()
    {
        return require base_path('database/migrations/2026_10_08_000001_add_expires_at_to_personal_access_tokens.php');
    }

    private function historical(): array
    {
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 42,
            'name' => 'historical', 'token' => hash('sha256', 'isolated-fixture'),
            'abilities' => '["profile:read"]', 'last_used_at' => '2020-01-02 03:04:05',
            'created_at' => '2019-01-01 00:00:00', 'updated_at' => '2020-01-02 03:04:05',
        ]);
        return (array) DB::table('personal_access_tokens')->find($id);
    }

    private function assertIndex(): void
    {
        $indexes = DB::select("PRAGMA index_list('personal_access_tokens')");
        $matches = array_values(array_filter($indexes, fn ($row) => $row->name === 'personal_access_tokens_expires_at_index'));
        $this->assertCount(1, $matches);
        $this->assertSame(0, (int) $matches[0]->unique);
        $columns = DB::select("PRAGMA index_info('personal_access_tokens_expires_at_index')");
        $this->assertCount(1, $columns);
        $this->assertSame('expires_at', $columns[0]->name);
    }

    public function test_old_schema_upgrade_preserves_every_historical_value(): void
    {
        $this->original();
        $before = $this->historical();
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'expires_at'));
        $this->migration()->up();
        $after = (array) DB::table('personal_access_tokens')->find($before['id']);
        $this->assertNull($after['expires_at']);
        unset($after['expires_at']);
        $this->assertSame($before, $after);
        $this->assertIndex();
    }

    public function test_fresh_original_plus_additive_path_has_nullable_timestamp_and_index(): void
    {
        $this->original();
        $this->migration()->up();
        $columns = array_values(array_filter(DB::select("PRAGMA table_info('personal_access_tokens')"), fn ($row) => $row->name === 'expires_at'));
        $this->assertCount(1, $columns);
        $this->assertSame('datetime', strtolower($columns[0]->type));
        $this->assertSame(0, (int) $columns[0]->notnull);
        $this->assertTrue($columns[0]->dflt_value === null || strtolower($columns[0]->dflt_value) === 'null');
        $row = $this->historical();
        $this->assertNull($row['expires_at']);
        $this->assertIndex();
    }

    public function test_repeated_up_is_safe_and_repairs_a_missing_index(): void
    {
        $this->original();
        $before = $this->historical();
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        DB::statement('DROP INDEX personal_access_tokens_expires_at_index');
        $migration->up();
        $this->assertIndex();
        $after = (array) DB::table('personal_access_tokens')->find($before['id']);
        unset($after['expires_at']);
        $this->assertSame($before, $after);
    }

    public function test_explicit_null_only_down_and_re_up_preserve_token_data(): void
    {
        $this->original();
        $before = $this->historical();
        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'expires_at'));
        $this->assertSame($before, (array) DB::table('personal_access_tokens')->find($before['id']));
        $migration->down();
        $migration->up();
        $this->assertNull(DB::table('personal_access_tokens')->find($before['id'])->expires_at);
        $this->assertIndex();
    }

    public function test_down_refuses_to_discard_populated_expiry_metadata(): void
    {
        $this->original();
        $row = $this->historical();
        $migration = $this->migration();
        $migration->up();
        DB::table('personal_access_tokens')->where('id', $row['id'])->update(['expires_at' => '2030-01-01 00:00:00']);
        try { $migration->down(); $this->fail('Populated expiry must prevent DDL rollback.'); }
        catch (RuntimeException $exception) { $this->assertStringContainsString('expiry metadata', $exception->getMessage()); }
        $this->assertSame('2030-01-01 00:00:00', DB::table('personal_access_tokens')->find($row['id'])->expires_at);
        $this->assertIndex();
    }

    public function test_missing_original_table_fails_before_creating_anything(): void
    {
        try { $this->migration()->up(); $this->fail('Missing prerequisite must fail.'); }
        catch (RuntimeException $exception) { $this->assertStringContainsString('must run first', $exception->getMessage()); }
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->migration()->down();
    }

    public function test_conflicting_index_fails_before_schema_mutation(): void
    {
        $this->original();
        DB::statement('CREATE INDEX personal_access_tokens_expires_at_index ON personal_access_tokens(name)');
        try { $this->migration()->up(); $this->fail('Conflicting index must fail.'); }
        catch (RuntimeException $exception) { $this->assertStringContainsString('Unexpected', $exception->getMessage()); }
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'expires_at'));
    }
}
