<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE SCHEMA THE PUBLIC SITE ACTUALLY READS.
 *
 * ── WHY THIS IS A TRAIT AND NOT `RefreshDatabase` ─────────────────────────────
 * The suite runs against SQLite, and `RefreshDatabase` executes every file in
 * `database/migrations`. Several of those use MySQL-only syntax — for example
 * `ALTER TABLE ... MODIFY COLUMN`, which SQLite rejects outright — so the whole
 * migration run aborts and every test errors before reaching an assertion.
 *
 * That is a pre-existing property of this codebase's migrations. Working around it
 * is not this feature's job, so these tests state the schema they need instead.
 *
 * ── WHY THAT IS BETTER HERE ─────────────────────────────────────────────────
 * A public-site test should declare exactly which columns the page under test
 * reads. With a shared trait, adding a CMS column cannot silently change what these
 * tests exercise, and a test failure points at a named column rather than at a
 * 200-table migration diff.
 *
 * ── THE TABLES ──────────────────────────────────────────────────────────────
 *   global_settings   read by `get_settings('frontend_view')` in HomeController
 *   schools           read by the public tenant resolver
 *   users             needed to act as a user in authorisation tests
 *   audit_logs        written by the `superAdmin` middleware on every admin request
 *   website_*         the CMS itself
 *   website_enquiries this feature's table
 *
 * `audit_logs` is the one that is easy to miss: without it, every authorisation
 * test 500s inside the middleware before the assertion runs, which looks like an
 * authorisation failure rather than a missing fixture table.
 */
trait CreatesPublicSiteSchema
{
    protected function setUpPublicSiteDatabase(): void
    {
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('sqlite');

        Schema::create('global_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->nullable();
            $t->text('value')->nullable();
        });

        // `frontend_view` switches the public site on. The rest are read by the
        // Super Admin layout chrome via `get_settings(...)`; the layout dereferences
        // them without a null guard, so a missing row is an
        // "Undefined property: stdClass::$x" 500 rather than a blank space.
        //
        // These are fixture values for an institution that has configured its
        // branding. They assert nothing about the product.
        DB::table('global_settings')->insert([
            ['key' => 'frontend_view', 'value' => '1'],
            ['key' => 'system_title', 'value' => 'PIIE'],
            ['key' => 'navbar_title', 'value' => 'PIIE'],
            ['key' => 'footer_text', 'value' => 'All Rights Reserved'],
            ['key' => 'footer_link', 'value' => 'https://example.co.ug'],
            ['key' => 'favicon', 'value' => ''],
            ['key' => 'white_logo', 'value' => ''],
            ['key' => 'language', 'value' => 'en'],
            ['key' => 'running_session', 'value' => ''],
        ]);

        Schema::create('schools', function (Blueprint $t) {
            $t->id();
            $t->string('title')->nullable();
            $t->string('school_type')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->boolean('status')->default(1);
            $t->timestamps();
        });

        // One school on record.
        //
        // `PublicTenantResolver::resolveSchoolId()` falls back to "the first school on
        // record" when `primary_school_id` is unset. With an EMPTY schools table it
        // returned null, and `/apply` correctly aborted 503 — which made the Apply
        // page untestable and hid the fact that its header was rendering empty.
        DB::table('schools')->insert([
            'id' => 1,
            'title' => 'Prime International Institute of Excellence (PIIE)',
            'school_type' => 'university',
            'status' => 1,
        ]);

        // ── The Apply page's own two tables ──────────────────────────────────
        //
        // `/apply` reads `programmes` (the internal/admissions list, NOT the 67
        // public CMS catalogue items) and `intake_sessions`. Without these the
        // controller throws before the view renders. Declared with only the columns
        // `PublicApplicationController::showForm()` actually reads.
        Schema::create('programmes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('code')->nullable();
            $t->string('name');
            $t->string('level')->nullable();
            $t->string('duration')->nullable();
            $t->string('mode')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });

        Schema::create('intake_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('name')->nullable();
            $t->string('status')->nullable();
            $t->boolean('is_open')->default(0);
            $t->date('open_date')->nullable();
            $t->date('close_date')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('email_verified_at')->nullable();
            $t->unsignedInteger('role_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->string('designation')->nullable();
            $t->unsignedBigInteger('designation_id')->nullable();
            $t->string('employment_type')->nullable();
            $t->string('staff_status')->nullable();
            $t->string('password')->nullable();
            $t->string('force_password_change')->nullable();
            $t->string('code')->nullable();
            $t->text('user_information')->nullable();
            $t->string('remember_token')->nullable();
            $t->string('account_status')->default('active');
            $t->string('documents')->nullable();
            $t->text('student_info')->nullable();
            $t->string('status')->nullable();
            $t->string('school_role')->nullable();
            // Read directly by the Super Admin layout as `$usersinfo->language`, with
            // no null guard, so the column must exist for any admin page to render.
            $t->string('language')->nullable();
            $t->string('timezone')->nullable();
            $t->text('menu_permission')->nullable();
            $t->timestamps();
        });

        // Written by the `superAdmin` middleware on every guarded request.
        //
        // Column set copied from the live `audit_logs` table. Only the columns the
        // middleware actually writes are declared non-nullable, because a fixture that
        // is stricter than production turns an authorisation test into a 500 that looks
        // like an authorisation failure. `user_name` in particular is written by the
        // middleware and its absence produced "table audit_logs has no column named
        // user_name" — which reads as a permissions problem and is not one.
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('user_id')->default(0);
            $t->string('user_name', 150)->nullable();
            $t->unsignedTinyInteger('role_id')->nullable();
            $t->string('role_name', 60)->nullable();
            $t->string('action', 100)->nullable();
            $t->string('event_type', 100)->default('access');
            $t->string('module', 80)->nullable();
            $t->string('route_name', 150)->nullable();
            $t->string('url', 500)->nullable();
            $t->string('method', 10)->nullable();
            $t->text('description')->nullable();
            $t->string('record_type', 100)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('device_type', 20)->nullable();
            $t->string('browser', 60)->nullable();
            $t->string('platform', 60)->nullable();
            $t->string('status', 20)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('website_pages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable();
            $t->string('title');
            $t->string('nav_title')->nullable();
            $t->string('subtitle')->nullable();
            $t->string('slug')->nullable();
            $t->boolean('status')->default(1);
            $t->integer('display_order')->default(0);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('website_sections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable();
            $t->string('section_key')->nullable();
            $t->string('title')->nullable();
            $t->text('subtitle')->nullable();
            $t->text('content')->nullable();
            $t->text('extra_json')->nullable();
            $t->string('image')->nullable();
            $t->boolean('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('website_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('section_key')->nullable();
            $t->string('item_type')->nullable();
            $t->string('title')->nullable();
            $t->string('subtitle')->nullable();
            $t->text('description')->nullable();
            $t->text('content')->nullable();
            $t->string('image')->nullable();
            $t->string('link')->nullable();
            $t->string('button_text')->nullable();
            $t->boolean('status')->default(1);
            $t->integer('sort_order')->default(0);
            $t->text('meta_json')->nullable();
            $t->timestamps();
        });

        Schema::create('website_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('key')->nullable();
            $t->text('value')->nullable();
            $t->boolean('is_json')->default(false);
            $t->boolean('status')->default(1);
            $t->timestamps();
        });

        Schema::create('website_seo_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('page_key')->nullable();
            $t->string('meta_title')->nullable();
            $t->text('meta_description')->nullable();
            $t->text('meta_keywords')->nullable();
            $t->string('canonical_url')->nullable();
            $t->boolean('status')->default(1);
            $t->timestamps();
        });

        // Read by `get_phrase()` in the Super Admin layout chrome. Without it any
        // authorisation test that renders an admin page 500s inside the layout.
        Schema::create('language', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('phrase')->nullable();
            $t->string('translated')->nullable();
        });

        // ── The Super Admin layout's own queries ──────────────────────────────
        //
        // `resources/views/superadmin/navigation.blade.php` runs two raw queries in
        // its chrome, on EVERY admin page:
        //
        //   DB::table('payment_history')->where('status','pending')->count();
        //   DB::table('sessions')->where('id', get_settings('running_session'))->value('session_title');
        //
        // Neither is guarded by `Schema::hasTable()`, so both tables must exist for
        // any admin view to render at all. They are created empty here, which makes
        // the badge count zero and the footer session blank — exactly what an
        // institution with no payments and no running session would see.
        Schema::create('payment_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('session_title')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        Schema::create('website_enquiries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->string('name', 191);
            $t->string('email', 191);
            $t->string('phone', 64)->nullable();
            $t->string('subject', 191);
            $t->text('message');
            $t->enum('status', ['new', 'read', 'answered', 'spam'])->default('new');
            $t->string('honeypot', 191)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
        });

        $this->seedPublicPages();
    }

    /**
     * The published CMS pages every public test can navigate to.
     *
     * `careers` is included because the Careers page is created by
     * `scripts/create-careers-page.php` against the real database, and a test that
     * walks "every public page" must be able to reach it. Omitting it produced a 404
     * that read like a broken page rather than a missing fixture row.
     */
    protected function seedPublicPages(): void
    {
        foreach ([
            ['home', 'Home', 'home'],
            ['about', 'About Us', 'about-us'],
            ['programs', 'Academic Programmes', 'academic-programmes'],
            ['admissions', 'Admissions', 'admissions'],
            ['research', 'Research & Innovation', 'research-and-innovation'],
            ['contact', 'Contact Us', 'contact-us'],
            ['careers', 'Careers', 'careers'],
        ] as [$key, $title, $slug]) {
            DB::table('website_pages')->insert([
                'page_key' => $key,
                'title' => $title,
                'slug' => $slug,
                'status' => 1,
                'display_order' => 0,
                'sort_order' => 0,
            ]);
        }
    }

    /** A signed-in user with the given role id, as an Eloquent model. */
    protected function userWithRole(int $roleId, string $email = 'user@example.co.ug'): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => $email,
            'role_id' => $roleId,
            'account_status' => 'active',
            'password' => bcrypt('secret123'),
        ]);

        return \App\Models\User::find($id);
    }
}
