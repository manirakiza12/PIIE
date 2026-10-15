<?php

namespace Tests\Feature;

use App\Support\SuperAdminWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SuperAdminWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'app.enforce_school_subscriptions' => false]);
        DB::purge('sqlite');
        foreach (['packages', 'subscriptions', 'payment_history', 'addons'] as $table) {
            Schema::create($table, fn (Blueprint $table) => $table->id());
        }
    }

    public function test_unused_platform_tools_are_omitted(): void
    {
        $this->assertFalse(SuperAdminWorkspace::showsSubscriptions());
        $this->assertFalse(SuperAdminWorkspace::showsAddons());
    }

    public function test_enforced_billing_is_always_accessible(): void
    {
        config(['app.enforce_school_subscriptions' => true]);
        $this->assertTrue(SuperAdminWorkspace::showsSubscriptions());
    }

    public function test_existing_billing_records_remain_accessible_without_enforcement(): void
    {
        foreach (['packages', 'subscriptions', 'payment_history'] as $table) {
            DB::table($table)->insert(['id' => 1]);
            $this->assertTrue(SuperAdminWorkspace::showsSubscriptions(), $table);
            $this->assertSame(1, DB::table($table)->count());
            DB::table($table)->delete();
        }
    }

    public function test_addon_controls_remain_accessible_when_any_addon_is_installed(): void
    {
        DB::table('addons')->insert(['id' => 1]);
        $this->assertTrue(SuperAdminWorkspace::showsAddons());
        $this->assertFalse(SuperAdminWorkspace::showsSubscriptions());
    }

    public function test_partial_installation_does_not_require_optional_tables(): void
    {
        foreach (['packages', 'subscriptions', 'payment_history', 'addons'] as $table) {
            Schema::drop($table);
        }
        $this->assertFalse(SuperAdminWorkspace::showsSubscriptions());
        $this->assertFalse(SuperAdminWorkspace::showsAddons());
    }
}
