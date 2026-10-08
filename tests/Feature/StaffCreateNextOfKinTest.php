<?php

namespace Tests\Feature;

use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Staff\StaffNextOfKin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Admin -> Staff -> Add Staff -> Create <role>: the professional full-page form,
 * the common Next of Kin block, and the date-of-birth default.
 *
 * The real migration is executed here against in-memory SQLite, so the schema
 * under test is the schema that will be applied.
 */
class StaffCreateNextOfKinTest extends TestCase
{
    use StaffModuleTestHelper;

    private User $admin;
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
        $this->runNextOfKinMigration();
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->timestamps(); $table->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->timestamps(); });
        Schema::create('staff_role_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('staff_role_id'); $table->string('permission', 100); $table->timestamps(); });
        Schema::create('user_staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('staff_role_id'); $table->timestamps(); });
        $this->makeDesignation($this->school);

        // The existing account-access screen is exercised below, so the table it
        // writes to must exist in the in-memory schema.
        (require base_path('database/migrations/2014_10_12_100000_create_password_resets_table.php'))->up();

        // A school admin holds the staff permissions by role; nothing to grant.
        $this->admin = User::factory()->create(['name' => 'HR Admin', 'role_id' => 2, 'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active']);
    }

    // ============================================ Next of Kin for every role

    #[\PHPUnit\Framework\Attributes\DataProvider('everyStaffType')]
    public function test_every_staff_type_is_created_with_a_next_of_kin(string $type, int $roleId): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', $type), $type === 'lecturer'
                ? $this->lecturerPayload(['first_name' => "Nok{$roleId}"])
                : $this->payload(['first_name' => "Nok{$roleId}"]))
            ->assertRedirect();

        $user = User::where('email', 'new.staff@example.com')->firstOrFail();
        $this->assertSame($roleId, (int) $user->role_id, 'The requested staff role was created.');

        $profile = StaffProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Grace Nok', $profile->emergency_contact_name);
        $this->assertSame(StaffNextOfKin::SIBLING, $profile->emergency_contact_relationship);
        $this->assertSame('nok@example.com', $profile->emergency_contact_email);
        $this->assertSame('+256 712 345 678', $profile->emergency_contact_phone);
        $this->assertSame('+256 700 111 222', $profile->emergency_contact_alternative_phone);
        $this->assertSame('12 Kampala Road', $profile->emergency_contact_address);
    }

    public static function everyStaffType(): array
    {
        return [
            'admin' => ['admin', 2],
            'lecturer' => ['lecturer', 3],
            'accountant' => ['accountant', 4],
            'librarian' => ['librarian', 5],
            'warden' => ['warden', 10],
            'other staff' => ['staff', 20],
        ];
    }

    public function test_next_of_kin_is_common_to_all_staff_and_not_role_specific(): void
    {
        // One shared staff_profiles table carries it; no per-role duplication.
        $columns = Schema::getColumnListing('staff_profiles');
        foreach (['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_email', 'emergency_contact_phone', 'emergency_contact_alternative_phone', 'emergency_contact_address'] as $column) {
            $this->assertContains($column, $columns);
        }
        $users = Schema::getColumnListing('users');
        foreach (['emergency_contact_email', 'emergency_contact_name'] as $column) {
            $this->assertNotContains($column, $users, 'Next of Kin belongs to the common staff profile, not to users.');
        }
    }

    public function test_next_of_kin_does_not_create_a_user_or_any_login_access(): void
    {
        Mail::fake();
        $before = User::count();

        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())
            ->assertRedirect();

        $this->assertSame($before + 1, User::count(), 'Only the staff member is created.');
        $nok = User::where('email', 'nok@example.com')->first();
        $this->assertNull($nok, 'The Next of Kin is never a system user.');

        $created = User::where('email', 'new.staff@example.com')->firstOrFail();
        $this->assertNotSame('nok@example.com', $created->email);
    }

    // =================================================== validation & rules

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredNextOfKinField')]
    public function test_next_of_kin_required_fields_are_enforced(string $field): void
    {
        $payload = $this->lecturerPayload();
        unset($payload[$field]);

        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $payload)
            ->assertSessionHasErrors($field);
    }

    public static function requiredNextOfKinField(): array
    {
        return [
            'full name' => ['emergency_contact_name'],
            'relationship' => ['emergency_contact_relationship'],
            'email' => ['emergency_contact_email'],
            'phone' => ['emergency_contact_phone'],
        ];
    }

    public function test_invalid_next_of_kin_email_is_rejected_with_a_human_message(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['emergency_contact_email' => 'not-an-email']))
            ->assertSessionHasErrors('emergency_contact_email');

        $this->assertStringContainsString('valid Next of Kin email address', session('errors')->first('emergency_contact_email'));
        $this->assertStringNotContainsString('emergency_contact_email', session('errors')->first('emergency_contact_email'));
        $this->assertStringNotContainsString('SQLSTATE', session('errors')->first('emergency_contact_email'));
    }

    public function test_international_next_of_kin_phone_numbers_are_accepted(): void
    {
        foreach (['+256 712 345 678', '+256712345678', '0772 123456', '(256) 712-345678', '+1-202-555-0143'] as $phone) {
            Mail::fake();
            $email = 'phone.'.md5($phone).'@example.com';
            $this->actingAs($this->admin)
                ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['phone' => $phone, 'emergency_contact_phone' => $phone, 'email' => $email]))
                ->assertRedirect();
            $this->assertSame($phone, StaffProfile::where('user_id', User::where('email', $email)->value('id'))->value('emergency_contact_phone'), $phone);
        }
    }

    public function test_a_letters_only_contact_number_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['emergency_contact_phone' => 'call me maybe']))
            ->assertSessionHasErrors('emergency_contact_phone');
    }

    public function test_relationship_is_a_controlled_selection_and_other_requires_a_description(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
                'emergency_contact_relationship' => 'Other',
                'emergency_contact_relationship_other' => 'Church elder',
                'email' => 'other.ok@example.com',
            ]))
            ->assertRedirect();
        $this->assertSame('Other: Church elder', StaffProfile::where('user_id', User::where('email', 'other.ok@example.com')->value('id'))->value('emergency_contact_relationship'));
        $this->assertSame('Other', StaffNextOfKin::base('Other: Church elder'));
        $this->assertSame('Church elder', StaffNextOfKin::otherDetail('Other: Church elder'));

        $this->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
            'emergency_contact_relationship' => 'Other',
            'emergency_contact_relationship_other' => '',
            'email' => 'other.bad@example.com',
        ]))->assertSessionHasErrors('emergency_contact_relationship_other');
    }

    public function test_an_unknown_relationship_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload(['emergency_contact_relationship' => 'Neighbour']))
            ->assertSessionHasErrors('emergency_contact_relationship');
    }

    public function test_form_values_are_preserved_after_a_validation_failure(): void
    {
        $this->from(route('admin.staff.create', 'lecturer'))
            ->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload([
                'first_name' => 'Kept',
                'last_name' => 'Values',
                'emergency_contact_name' => 'Kept Nok',
                'emergency_contact_email' => 'broken',
            ]))
            ->assertRedirect(route('admin.staff.create', 'lecturer'))
            ->assertSessionHasInput('first_name', 'Kept')
            ->assertSessionHasInput('last_name', 'Values')
            ->assertSessionHasInput('emergency_contact_name', 'Kept Nok');

        // The redisplayed form still carries what was typed, so nothing has to
        // be retyped after a mistake.
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk()->getContent();
        $this->assertStringContainsString('value="Kept"', $html);
        $this->assertStringContainsString('value="Kept Nok"', $html);
    }

    public function test_the_other_relationship_description_is_hidden_unless_other_is_selected(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk()->getContent();

        // Rendered hidden on a fresh form, and only the "Other" option reveals it.
        $this->assertMatchesRegularExpression('/class="[^"]*d-none[^"]*"[^>]*id="nok-other-wrap"/', $html);
        $this->assertStringContainsString("pairToggles('emergency_contact_relationship', 'nok-other-wrap', 'Other', null)", $html);
        $this->assertStringContainsString("wrap.classList.toggle('d-none', !on)", $html);
    }

    // ================================================ date of birth default

    public function test_date_of_birth_defaults_to_blank_and_never_todays_date(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'));
        $page->assertOk()->assertSee('name="birthday"', false);

        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/name="birthday"[^>]*value=""/', $html, 'A new staff record must have a blank date of birth.');
        $this->assertStringNotContainsString('name="birthday" value="'.date('m/d/Y').'"', $html);
        $this->assertStringNotContainsString('name="birthday" value="'.date('Y-m-d').'"', $html);
    }

    public function test_date_of_birth_persists_when_supplied_and_is_rejected_in_the_future(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'accountant'), $this->payload(['birthday' => '1990-04-17', 'email' => 'dob@example.com']))
            ->assertRedirect();
        $user = User::where('email', 'dob@example.com')->firstOrFail();
        $this->assertSame('1990-04-17', date('Y-m-d', (int) json_decode($user->user_information, true)['birthday']));

        $this->post(route('admin.staff.create.store', 'accountant'), $this->payload([
            'birthday' => now()->addDay()->toDateString(),
            'email' => 'future@example.com',
        ]))->assertSessionHasErrors('birthday');
    }

    public function test_omitting_the_date_of_birth_stores_no_birthday(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['email' => 'nodob@example.com']))
            ->assertRedirect();
        $info = json_decode(User::where('email', 'nodob@example.com')->value('user_information'), true);
        $this->assertTrue(empty($info['birthday']), 'An omitted date of birth must not become today.');
    }

    /**
     * The narrow per-role create forms are still reachable directly, so the
     * pre-filled-today defect is fixed there too rather than only on the new
     * full-page form.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('legacyStaffCreateForm')]
    public function test_no_staff_form_prefills_todays_date_as_a_date_of_birth(string $form): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route($form), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();

        $this->assertStringContainsString('name="birthday"', $html);
        $this->assertStringNotContainsString('date(\'m/d/Y\')', $html, "{$form} must not default a date of birth to today.");
        $this->assertDoesNotMatchRegularExpression('/name="birthday"[^>]*value="'.preg_quote(date('m/d/Y'), '/').'"/', $html);
    }

    public static function legacyStaffCreateForm(): array
    {
        return [
            'admin' => ['admin.open_modal'],
            'teacher' => ['admin.teacher.open_modal'],
            'accountant' => ['admin.accountant.open_modal'],
            'librarian' => ['admin.librarian.open_modal'],
            'warden' => ['admin.warden.create_form'],
        ];
    }

    public function test_the_warden_edit_form_does_not_substitute_today_for_a_missing_date_of_birth(): void
    {
        $warden = User::factory()->create([
            'name' => 'Wanda Warden', 'role_id' => 10, 'school_id' => $this->school,
            'account_status' => 'active', 'staff_status' => 'active',
            'user_information' => json_encode(['birthday' => 0, 'phone' => '+256 712 000 111', 'address' => 'x', 'gender' => 'Female', 'blood_group' => '']),
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.warden_edit_modal', $warden->id))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="birthday"[^>]*value="'.preg_quote(date('m/d/Y'), '/').'"/', $html);
    }

    // ============================================== security & regressions

    public function test_tenant_isolation_is_preserved(): void
    {
        Mail::fake();
        $other = $this->makeSchool();
        $otherDesignation = $this->makeDesignation($other);
        $otherAdmin = User::factory()->create(['name' => 'Other HR', 'role_id' => 2, 'school_id' => $other, 'account_status' => 'active', 'staff_status' => 'active']);
        // Granted directly: the delegation service deliberately refuses to
        // delegate across institutions, which is itself the boundary under test.
        DB::table('user_permissions')->insert([
            ['school_id' => $other, 'user_id' => $otherAdmin->id, 'permission' => 'staff.create', 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $other, 'user_id' => $otherAdmin->id, 'permission' => 'staff.edit', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'librarian'), $this->payload(['email' => 'tenant.a@example.com']))->assertRedirect();
        $this->actingAs($otherAdmin)->post(route('admin.staff.create.store', 'librarian'), $this->payload(['email' => 'tenant.b@example.com', 'designation_id' => $otherDesignation]))->assertRedirect();

        $a = StaffProfile::where('user_id', User::where('email', 'tenant.a@example.com')->value('id'))->firstOrFail();
        $b = StaffProfile::where('user_id', User::where('email', 'tenant.b@example.com')->value('id'))->firstOrFail();
        $this->assertSame($this->school, (int) $a->school_id);
        $this->assertSame($other, (int) $b->school_id, 'Each Next of Kin is written inside its own institution.');
        $this->assertSame((int) $a->user->school_id, (int) $a->school_id);

        // A designation belonging to one institution is never accepted by the other.
        $this->actingAs($otherAdmin)
            ->post(route('admin.staff.create.store', 'librarian'), $this->payload(['email' => 'tenant.c@example.com', 'designation_id' => DB::table('designations')->where('school_id', $this->school)->value('id')]))
            ->assertSessionHasErrors('designation_id');
        $this->assertNull(User::where('email', 'tenant.c@example.com')->first());
    }

    public function test_staff_number_generation_is_unchanged(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'warden'), $this->payload())->assertRedirect();
        $code = User::where('email', 'new.staff@example.com')->value('code');
        $this->assertNotEmpty($code, 'A staff number is still generated on save.');
        $this->assertMatchesRegularExpression('/\d+/', $code);
    }

    public function test_secure_account_access_workflow_is_untouched(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();
        $user = User::where('email', 'new.staff@example.com')->firstOrFail();

        // No password is chosen by the administrator on this form, and the
        // account stays at "setup required" for the existing secure-link flow.
        $this->actingAs($user)->get(route('login'))->assertRedirect();
        $response = $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'));
        $response->assertOk();
    }

    public function test_staff_types_the_actor_may_not_create_are_refused(): void
    {
        // A Lecturer may not create an Admin. Whichever boundary answers first
        // (the admin middleware or this controller), no account is created.
        $limited = User::factory()->create(['name' => 'Limited', 'role_id' => 3, 'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active']);
        $before = User::count();

        $this->actingAs($limited)->post(route('admin.staff.create.store', 'admin'), $this->payload())->assertRedirect();
        $this->assertSame($before, User::count(), 'No account is created by an actor without authority.');

        // An unknown staff type is not routable at all.
        $this->actingAs($this->admin)->get(route('admin.staff.create', 'superuser'))->assertNotFound();
    }

    public function test_the_existing_staff_directory_and_profiles_still_load(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->lecturerPayload())->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'staff'), $this->payload(['email' => 'generic@example.com']))->assertRedirect();

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk();
        $lecturer = User::where('email', 'new.staff@example.com')->firstOrFail();
        $generic = User::where('email', 'generic@example.com')->firstOrFail();

        // Both profiles load, and the existing secure account-access screen still
        // works for the staff type it governs.
        $this->assertNotNull(StaffProfile::where('user_id', $lecturer->id)->firstOrFail()->emergency_contact_email);
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $generic->id))->assertOk();
    }

    public function test_the_launcher_now_offers_the_full_page_form(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.staff.add'));
        $page->assertOk()
            ->assertSee(route('admin.staff.create', 'lecturer'), false)
            ->assertSee(route('admin.staff.create', 'accountant'), false)
            ->assertSee(route('admin.staff.create', 'warden'), false);
        // The drawer hook that opened the narrow modal is gone from the launcher.
        $this->assertStringNotContainsString('data-create-route', $page->getContent(), 'The narrow drawer is no longer used for staff creation.');
    }

    public function test_migration_only_adds_the_email_column(): void
    {
        $source = file_get_contents(base_path('database/migrations/2026_09_28_000002_add_next_of_kin_email_to_staff_profiles.php'));
        $this->assertStringContainsString('emergency_contact_email', $source);
        $up = explode('public function down', $source)[0];
        foreach (['users', 'daily_attendances', 'Schema::create', 'dropColumn'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $up, "The migration must not touch {$forbidden}.");
        }
    }

    // ======================================================= helpers

    private function runNextOfKinMigration(): void
    {
        // The real professional-record schema, built by the same migrations that
        // produced piie_main and in the same order, so the Next-of-Kin columns
        // are exercised against the actual table the migration extends. It also
        // supplies the qualification and document tables a Lecturer needs.
        (require base_path('database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php'))->up();

        $this->assertFalse(
            Schema::hasColumn('staff_profiles', 'emergency_contact_email'),
            'The email column only exists once the pending migration adds it.'
        );

        $migration = require base_path('database/migrations/2026_09_28_000002_add_next_of_kin_email_to_staff_profiles.php');
        $migration->up();
        $this->assertTrue(Schema::hasColumn('staff_profiles', 'emergency_contact_email'));
    }

    /**
     * A Lecturer additionally requires the academic block, so the Next-of-Kin
     * assertions supply it for that one staff type.
     */
    private function lecturerPayload(array $overrides = []): array
    {
        return $this->payload($overrides + [
            'qualification_level' => "Master's Degree",
            'field_of_study' => 'Software Engineering',
            'institution' => 'Makerere University',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'New',
            'last_name' => 'Staff',
            // The personal title is a required controlled selection.
            'title' => 'Mr',
            'gender' => 'Female',
            'birthday' => '',
            'email' => 'new.staff@example.com',
            'phone' => '+256 712 000 111',
            'designation_id' => DB::table('designations')->where('school_id', $this->school)->value('id'),
            'employment_type' => 'Full Time',
            'staff_status' => 'active',
            'emergency_contact_name' => 'Grace Nok',
            'emergency_contact_relationship' => StaffNextOfKin::SIBLING,
            'emergency_contact_email' => 'nok@example.com',
            'emergency_contact_phone' => '+256 712 345 678',
            'emergency_contact_alternative_phone' => '+256 700 111 222',
            'emergency_contact_address' => '12 Kampala Road',
        ], $overrides);
    }
}
