<?php

namespace Tests\Feature;

use App\Mail\GenericStaffPasswordSetupMail;
use App\Models\AuditLog;
use App\Models\StaffDocument;
use App\Models\StaffProfile;
use App\Models\StaffQualification;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffAccountAccess;
use App\Support\Staff\StaffDocumentStorage;
use App\Support\Staff\StaffNin;
use App\Support\Staff\StaffRecordService;
use App\Support\TenantConfiguration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Staff Directory record management: the per-row Actions menu, the complete
 * View Profile screen, the full-page Edit Staff correction workflow, the
 * governed Suspend / Reinstate action, and the reachability plus delete safety
 * of the existing Designation master data.
 *
 * The invariants under test are the ones that make the feature safe to expose:
 * the Staff Number and the base role cannot move, RBAC and account access are
 * untouched by an HR correction, a lecturer's professional records are corrected
 * in place rather than replaced, another school's staff id is a plain 404, and
 * every change is audited without a NIN.
 */
class StaffProfileManagementTest extends TestCase
{
    use StaffModuleTestHelper;

    /** A recognisable stored value, so "the password never moved" is provable. */
    private const PRESET_PASSWORD = 'preset-password-hash-for-account-access';

    private User $admin;

    private int $school;

    private int $department;

    private int $designation;

    private int $otherDesignation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
        (require base_path('database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php'))->up();
        (require base_path('database/migrations/2026_09_28_000002_add_next_of_kin_email_to_staff_profiles.php'))->up();
        (require base_path('database/migrations/2014_10_12_100000_create_password_resets_table.php'))->up();
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();

        config(['piie.staff_documents.root' => storage_path('app/staff-documents')]);
        File::ensureDirectoryExists(StaffDocumentStorage::root());

        $this->department = $this->makeDepartment($this->school, 'Business Management');
        $this->designation = $this->makeDesignation($this->school, 'Registrar');
        $this->otherDesignation = $this->makeDesignation($this->school, 'Lecturer');

        // A school administrator holds the staff permissions outright.
        $this->admin = User::factory()->create([
            'name' => 'HR Admin', 'role_id' => 2, 'school_id' => $this->school,
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob(StaffDocumentStorage::root().'/*/*/*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    // ================================================= Staff Directory actions

    public function test_every_staff_row_offers_view_profile_edit_access_and_roles(): void
    {
        $lecturer = $this->makeLecturer();

        $html = $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.staff.profile.show', $lecturer->id), $html, 'View Profile is offered');
        $this->assertStringContainsString(route('admin.staff.profile.edit', $lecturer->id), $html, 'Edit Staff is offered');
        $this->assertStringContainsString(route('admin.rbac.staff.show', $lecturer->id), $html, 'Roles & Permissions is offered');
        $this->assertStringContainsString(route('admin.staff.account-access.show', $lecturer->id), $html, 'Account access is offered');
        $this->assertStringContainsString(route('admin.staff.profile.status', $lecturer->id), $html, 'the governed status action is offered');

        // Asserted with the casing the application's language table actually
        // stores: get_phrase() matches case-insensitively on MySQL, so
        // "Edit Staff" would render the pre-existing "Edit staff" row.
        foreach (['View Profile', 'Edit staff', 'Account Access', 'Roles &amp; Permissions', 'Suspend'] as $label) {
            $this->assertStringContainsString($label, $html, "the Actions menu is labelled {$label}");
        }
    }

    public function test_the_account_access_action_is_offered_for_every_staff_base_role(): void
    {
        $generic = User::factory()->create([
            'name' => 'Test Registrar', 'email' => 'registrar@example.test', 'role_id' => 20,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $lecturer = $this->makeLecturer();

        $html = $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->getContent();

        // The governed setup workflow is no longer restricted to Other Staff, so
        // the action is offered for every staff row and never links to a 404.
        $this->assertStringContainsString(route('admin.staff.account-access.show', $generic->id), $html);
        $this->assertStringContainsString(route('admin.staff.account-access.show', $lecturer->id), $html);
    }

    public function test_there_is_no_destructive_route_for_a_staff_record(): void
    {
        $destroy = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'admin/staff/{id}')
                && in_array('DELETE', $route->methods(), true));

        $this->assertCount(0, $destroy, 'a staff record is corrected or suspended, never deleted');
        $this->assertNull(app('router')->getRoutes()->getByName('admin.staff.destroy'));
    }

    public function test_the_directory_keeps_its_existing_other_staff_edit_link(): void
    {
        $generic = User::factory()->create([
            'name' => 'Test Registrar', 'email' => 'registrar@example.test', 'role_id' => 20,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
        ]);

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()
            ->assertSee('Edit profile')
            ->assertSee(route('admin.staff.other.edit', $generic->id), false);
    }

    // ======================================================== View Profile

    public function test_the_profile_shows_the_complete_lecturer_record(): void
    {
        $lecturer = $this->createLecturerThroughTheForm();

        $page = $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))->assertOk();

        // Personal, contact, employment, Next of Kin.
        $page->assertSee('Grace Lecturer', false)
            ->assertSee('lecturer@example.test', false)
            ->assertSee('+256 712 000 111')
            ->assertSee('Registrar')
            ->assertSee('Business Management')
            ->assertSee('Sister Nok')
            ->assertSee('nok@example.test');

        // Lecturer professional information and the account access status.
        $page->assertSee('Academic &amp; Professional Information', false)
            ->assertSee('Makerere University')
            ->assertSee('Software Engineering')
            ->assertSee('Qualifications')
            ->assertSee('Account Access')
            ->assertSee('Setup required');

        // The actions the profile offers.
        $page->assertSee(route('admin.staff.profile.edit', $lecturer->id), false)
            ->assertSee(route('admin.rbac.staff.show', $lecturer->id), false)
            ->assertSee(route('admin.rbac.staff.index'), false);
    }

    public function test_the_profile_shows_the_next_of_kin_relationship_description(): void
    {
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'emergency_contact_relationship' => 'Other',
                'emergency_contact_relationship_other' => 'In-law',
            ]))
            ->assertRedirect();

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertOk()
            ->assertSee('In-law');
    }

    public function test_the_profile_never_exposes_the_stored_nin(): void
    {
        $lecturer = $this->makeLecturer();
        $profile = StaffProfile::where('user_id', $lecturer->id)->firstOrFail();
        StaffNin::assign($profile, 'CM900123456ABCD');
        $profile->save();

        $html = $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('CM900123456ABCD', $html, 'the decrypted NIN is never rendered');
        $this->assertStringNotContainsString('CM900123456', $html);
        $this->assertStringContainsString('ABCD', $html, 'only the masked tail is shown');
    }

    // =========================================================== Edit Staff

    public function test_an_authorised_administrator_can_open_the_edit_form(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)->get(route('admin.staff.profile.edit', $lecturer->id))
            ->assertOk()
            ->assertSee('Edit staff')
            ->assertSee('Designation')
            ->assertSee('Registrar')
            ->assertSee('Business Management')
            // Identity is displayed, and Designation master data is reachable.
            ->assertSee('Staff Number')
            ->assertSee(route('admin.designation_list'), false);
    }

    public function test_an_edit_corrects_the_designation_and_department(): void
    {
        $lecturer = $this->makeLecturer();
        $newDepartment = $this->makeDepartment($this->school, 'Public and Private Law');
        $newDesignation = $this->makeDesignation($this->school, 'Lecturer');

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'first_name' => 'Daniel',
                'last_name' => 'Okello',
                'department_id' => $newDepartment,
                'designation_id' => $newDesignation,
                'employment_type' => 'Part Time',
            ]))
            ->assertRedirect(route('admin.staff.profile.show', $lecturer->id))
            ->assertSessionHas('message');

        $fresh = $lecturer->fresh();
        $this->assertSame('Daniel Okello', $fresh->name);
        $this->assertSame('Daniel', $fresh->first_name);
        $this->assertSame('Okello', $fresh->last_name);
        $this->assertSame($newDepartment, (int) $fresh->department_id);
        $this->assertSame($newDesignation, (int) $fresh->designation_id);
        $this->assertSame('Part Time', $fresh->employment_type);
        $this->assertSame(1, User::where('email', $lecturer->email)->count(), 'the record is corrected, never replaced');
    }

    public function test_an_edit_updates_the_contact_details(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'email' => 'daniel.okello@example.test',
                'phone' => '+256 712 999 888',
                'alternative_phone' => '+256 712 999 887',
                'address' => '12 Nakivubo Street',
            ]))
            ->assertRedirect();

        $fresh = $lecturer->fresh();
        $this->assertSame('daniel.okello@example.test', $fresh->email);
        $information = json_decode((string) $fresh->user_information, true);
        $this->assertSame('+256 712 999 888', $information['phone']);
        $this->assertSame('12 Nakivubo Street', $information['address']);
        $this->assertSame('+256 712 999 887', StaffProfile::where('user_id', $lecturer->id)->value('alternative_phone'));
    }

    public function test_an_edit_updates_the_next_of_kin(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'emergency_contact_name' => 'Mary Okello',
                'emergency_contact_relationship' => 'Spouse',
                'emergency_contact_email' => 'mary@example.test',
                'emergency_contact_phone' => '+256 700 111 222',
            ]))
            ->assertRedirect();

        $profile = StaffProfile::where('user_id', $lecturer->id)->firstOrFail();
        $this->assertSame('Mary Okello', $profile->emergency_contact_name);
        $this->assertSame('Spouse', $profile->emergency_contact_relationship);
        $this->assertSame('mary@example.test', $profile->emergency_contact_email);
        $this->assertSame('+256 700 111 222', $profile->emergency_contact_phone);
    }

    public function test_an_edit_can_replace_the_recorded_nin_and_a_blank_keeps_it(): void
    {
        $lecturer = $this->makeLecturer();
        $profile = StaffProfile::where('user_id', $lecturer->id)->firstOrFail();
        StaffNin::assign($profile, 'CM900123456ABCD');
        $profile->save();

        // Blank: whatever is recorded stays.
        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['nin' => '']))
            ->assertRedirect();
        $this->assertSame('CM900123456ABCD', StaffNin::decrypt(StaffProfile::where('user_id', $lecturer->id)->firstOrFail()));

        // A new value replaces it, through the existing encrypted architecture.
        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['nin' => 'CM900999999ZZ']))
            ->assertRedirect();
        $this->assertSame('CM900999999ZZ', StaffNin::decrypt(StaffProfile::where('user_id', $lecturer->id)->firstOrFail()));
    }

    // ============================================ immutable identity & access

    public function test_the_staff_number_and_base_role_are_immutable_even_when_posted(): void
    {
        $lecturer = $this->makeLecturer();
        $code = $lecturer->code;
        $roleId = (int) $lecturer->role_id;

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                // All posted by hand: none may be honoured.
                'code' => 'HACKED-001',
                'role_id' => 2,
                'role_key' => 'admin',
                'account_status' => 'disable',
                'force_password_change' => 0,
                'password' => 'hijacked',
            ]))
            ->assertRedirect();

        $fresh = $lecturer->fresh();
        $this->assertSame($code, $fresh->code, 'the Staff Number never changes');
        $this->assertSame($roleId, (int) $fresh->role_id, 'the base role never changes');
        $this->assertSame('active', $fresh->account_status, 'account_status is not an HR field');
        $this->assertSame(1, (int) $fresh->force_password_change, 'account setup state is not an HR field');
        $this->assertNotSame('hijacked', $fresh->password);
    }

    public function test_an_edit_leaves_rbac_and_account_access_untouched(): void
    {
        $lecturer = $this->makeLecturer();

        $roleId = DB::table('staff_roles')->insertGetId([
            'school_id' => $this->school, 'name' => 'Examinations Officer',
            'description' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_staff_roles')->insert([
            'user_id' => $lecturer->id, 'staff_role_id' => $roleId, 'school_id' => $this->school, 'created_at' => now(),
        ]);
        DB::table('user_permissions')->insert([
            'user_id' => $lecturer->id, 'permission' => 'library.view', 'school_id' => $this->school, 'created_at' => now(),
        ]);
        DB::table('password_resets')->insert([
            'email' => $lecturer->email, 'token' => Hash::make('an-old-reset-token'), 'created_at' => now(),
        ]);

        $permissions = app(PermissionService::class);
        $before = [
            'roles' => DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all(),
            'grants' => DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all(),
            'effective' => $permissions->effectivePermissions($lecturer),
            'password' => $lecturer->password,
            'resets' => DB::table('password_resets')->where('email', $lecturer->email)->count(),
        ];

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['designation_id' => $this->otherDesignation]))
            ->assertRedirect();

        $fresh = $lecturer->fresh();
        $this->assertSame($before['roles'], DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all(), 'custom roles unchanged');
        $this->assertSame($before['grants'], DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all(), 'direct permissions unchanged');
        $this->assertEqualsCanonicalizing($before['effective'], $permissions->effectivePermissions($fresh), 'effective permissions unchanged');
        $this->assertSame($before['password'], $fresh->password, 'the password is never rewritten');
        $this->assertSame($before['resets'], DB::table('password_resets')->where('email', $fresh->email)->count(), 'account access state unchanged');
    }

    public function test_editing_hr_data_grants_no_permission(): void
    {
        $lecturer = $this->makeLecturer();
        $permissions = app(PermissionService::class);
        $before = $permissions->effectivePermissions($lecturer);

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['designation_id' => $this->otherDesignation]))
            ->assertRedirect();

        $fresh = $lecturer->fresh();
        $this->assertEqualsCanonicalizing($before, $permissions->effectivePermissions($fresh));
        $this->assertFalse($permissions->allows($fresh, 'users.assign_roles'), 'an HR correction grants no access governance');
        $this->assertFalse($permissions->allows($fresh, 'staff.edit'), 'an HR correction does not grant edit rights either');
    }

    // ==================================================== Lecturer records

    public function test_a_lecturer_qualification_is_corrected_in_place(): void
    {
        $lecturer = $this->createLecturerThroughTheForm();
        $qualification = StaffQualification::where('user_id', $lecturer->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'qualification_level' => 'Doctorate / PhD',
                'field_of_study' => 'Information Systems',
                'institution' => 'University of Cape Town',
                'completion_year' => 2019,
            ]))
            ->assertRedirect();

        $this->assertSame(1, StaffQualification::where('user_id', $lecturer->id)->count(), 'the row is corrected, not duplicated');
        $corrected = StaffQualification::where('user_id', $lecturer->id)->firstOrFail();
        $this->assertSame($qualification->id, $corrected->id, 'the existing qualification row is updated');
        $this->assertSame('Doctorate / PhD', $corrected->qualification_level);
        $this->assertSame('Information Systems', $corrected->specialisation);
        $this->assertSame('University of Cape Town', $corrected->institution);
        $this->assertSame(2019, (int) $corrected->completion_year);
    }

    public function test_leaving_the_academic_block_blank_creates_no_qualification(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['designation_id' => $this->otherDesignation]))
            ->assertRedirect();

        $this->assertSame(0, StaffQualification::where('user_id', $lecturer->id)->count());
        $this->assertSame($this->otherDesignation, (int) $lecturer->fresh()->designation_id);
    }

    public function test_a_qualification_is_created_when_the_staff_member_has_none(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'qualification_level' => "Master's Degree",
                'field_of_study' => 'Software Engineering',
                'institution' => 'Makerere University',
            ]))
            ->assertRedirect();

        $this->assertSame(1, StaffQualification::where('user_id', $lecturer->id)->count());
        $this->assertSame('Software Engineering', StaffQualification::where('user_id', $lecturer->id)->value('specialisation'));
    }

    public function test_an_uploaded_document_is_added_and_the_existing_one_survives(): void
    {
        $lecturer = $this->makeLecturer();
        $existing = app(StaffRecordService::class)->uploadDocument($this->admin, $lecturer, $this->pdf('cv.pdf'), 'cv');
        $this->assertSame(1, StaffDocument::where('user_id', $lecturer->id)->count());

        // The file travels inside the payload, the pattern the Staff module's
        // own document tests use.
        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'documents' => [0 => ['category' => 'academic_certificate', 'file' => $this->pdf('certificate.pdf')]],
            ]))
            ->assertRedirect(route('admin.staff.profile.show', $lecturer->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, StaffDocument::where('user_id', $lecturer->id)->count());
        $this->assertNotNull(StaffDocument::find($existing->id), 'the earlier document is never replaced');
        $this->assertSame('cv', StaffDocument::find($existing->id)->category);
        $this->assertSame('academic_certificate', StaffDocument::where('user_id', $lecturer->id)->where('id', '!=', $existing->id)->value('category'));
    }

    public function test_an_untouched_document_row_uploads_nothing(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'documents' => [0 => ['category' => '']],
            ]))
            ->assertRedirect();

        $this->assertSame(0, StaffDocument::where('user_id', $lecturer->id)->count());
    }

    // ================================================== authorization & IDOR

    public function test_an_unauthorised_actor_cannot_open_or_submit_the_edit_form(): void
    {
        $lecturer = $this->makeLecturer();
        $teacher = User::factory()->create([
            'name' => 'Plain Teacher', 'role_id' => 3, 'school_id' => $this->school,
            'account_status' => 'active', 'staff_status' => 'active',
        ]);

        $this->assertFalse(app(PermissionService::class)->allows($teacher, 'staff.edit'), 'the fixture must really lack staff.edit');

        $this->actingAs($teacher)->get(route('admin.staff.profile.edit', $lecturer->id))->assertForbidden();
        $this->actingAs($teacher)->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload())->assertForbidden();
        $this->actingAs($teacher)->post(route('admin.staff.profile.status', $lecturer->id), ['staff_status' => 'suspended'])->assertForbidden();

        $this->assertSame('Registrar', DB::table('designations')->where('id', $lecturer->fresh()->designation_id)->value('name'));
        $this->assertSame('active', $lecturer->fresh()->staff_status);
    }

    public function test_another_schools_staff_id_is_a_plain_404_on_every_action(): void
    {
        $outsider = $this->makeLecturerInAnotherSchool();

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.staff.profile.edit', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->put(route('admin.staff.profile.update', $outsider->id), $this->editPayload())->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.staff.profile.status', $outsider->id), ['staff_status' => 'suspended'])->assertNotFound();

        $this->assertSame('active', $outsider->fresh()->staff_status, 'nothing was changed');
        $this->assertSame('STF-FOREIGN-0001', $outsider->fresh()->code);
    }

    public function test_a_student_is_not_a_staff_record(): void
    {
        $student = User::factory()->create([
            'name' => 'A Student', 'role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active',
        ]);

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $student->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.staff.profile.edit', $student->id))->assertNotFound();
    }

    public function test_another_schools_department_or_designation_is_refused(): void
    {
        $lecturer = $this->makeLecturer();
        $otherSchool = $this->makeSchool();
        $foreignDepartment = $this->makeDepartment($otherSchool, 'Foreign Dept');
        $foreignDesignation = $this->makeDesignation($otherSchool, 'Foreign Role');

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['department_id' => $foreignDepartment]))
            ->assertSessionHasErrors('department_id');

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload(['designation_id' => $foreignDesignation]))
            ->assertSessionHasErrors('designation_id');

        $this->assertSame($this->designation, (int) $lecturer->fresh()->designation_id);
    }

    // ======================================== account access (setup workflow)

    public function test_a_lecturer_profile_whose_setup_is_required_shows_the_setup_action(): void
    {
        $lecturer = $this->makeLecturer();   // force_password_change = 1

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'required')
            // The state the administrator has to act on, and the account it applies to.
            ->assertSee('Setup required')
            ->assertSee('Enabled')
            ->assertSee('Base system role')
            // Rendered with the tenant's own terminology, as everywhere else.
            ->assertSee(app(TenantConfiguration::class)->terminology()['teacher'])
            // The primary action, leading to the EXISTING governed workflow.
            ->assertSee('Set Up Portal Access')
            ->assertSee(route('admin.staff.account-access.show', $lecturer->id), false);
    }

    public function test_the_profile_setup_action_opens_the_existing_account_access_workflow(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'required')
            ->assertSee('Account Access')
            ->assertSee('Send Password Setup Link')
            ->assertDontSee('Resend Password Setup Link')
            // A Lecturer's setup is now reachable; it used to 404 here.
            ->assertSee($lecturer->email);
    }

    public function test_a_pending_setup_renders_the_pending_state_and_the_resend_action(): void
    {
        $lecturer = $this->makeLecturer();
        Password::broker('users')->createToken($lecturer);

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'pending')
            ->assertSee('Pending setup')
            ->assertSee('Resend Password Setup Link')
            ->assertDontSee('Send Password Setup Link');
    }

    public function test_a_completed_setup_offers_no_send_action(): void
    {
        $lecturer = $this->makeLecturer([
            'force_password_change' => false,
            'password' => Hash::make('Chosen-By-Themselves-991!'),
        ]);

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'completed')
            ->assertSee('Completed')
            ->assertDontSee('Send Password Setup Link')
            ->assertDontSee('Resend Password Setup Link');

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'completed')
            ->assertSee('Portal access active')
            ->assertDontSee('Set Up Portal Access');
    }

    public function test_the_directory_separates_account_access_from_roles_and_permissions(): void
    {
        $lecturer = $this->makeLecturer();

        $html = $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->getContent();

        // Two distinct concepts, two distinct destinations.
        $this->assertStringContainsString(route('admin.staff.account-access.show', $lecturer->id), $html, 'portal/account access is offered');
        $this->assertStringContainsString(route('admin.rbac.staff.show', $lecturer->id), $html, 'roles & permissions is offered');
        $this->assertStringContainsString('Account Access', $html);
        $this->assertStringContainsString('Roles &amp; Permissions', $html);
        // The pending-setup hint travels with the account access entry.
        $this->assertStringContainsString('Setup required', $html);
    }

    public function test_the_existing_test_registrar_account_workflow_still_works(): void
    {
        Mail::fake();
        $registrar = User::factory()->create([
            'name' => 'Test Registrar', 'email' => 'registrar@example.test', 'role_id' => 20,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
            'force_password_change' => 1, 'password' => self::PRESET_PASSWORD,
        ]);

        // The pre-existing, already-tested route still works unchanged.
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $registrar->id))
            ->assertOk()
            ->assertViewHas('setupState', 'required')
            ->assertSee('Send Password Setup Link');

        // ... and the new HR-scoped route reaches the same screen.
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $registrar->id))
            ->assertOk()
            ->assertViewHas('setupState', 'required');

        $this->post(route('admin.rbac.staff.account-access.send', $registrar->id))->assertRedirect();
        Mail::assertSent(GenericStaffPasswordSetupMail::class);

        // A second issue is refused while a link is already outstanding.
        $this->post(route('admin.staff.account-access.send', $registrar->id))->assertSessionHas('error');
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $registrar->id))
            ->assertViewHas('setupState', 'pending');
    }

    public function test_no_email_is_sent_by_opening_the_account_access_screens(): void
    {
        Mail::fake();
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_resets')->count(), 'viewing never creates a token');
    }

    public function test_the_setup_link_carries_no_password_and_leaves_identity_and_access_untouched(): void
    {
        Mail::fake();
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);

        $roleId = DB::table('staff_roles')->insertGetId([
            'school_id' => $this->school, 'name' => 'Examinations Officer',
            'description' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_staff_roles')->insert([
            'user_id' => $lecturer->id, 'staff_role_id' => $roleId, 'school_id' => $this->school, 'created_at' => now(),
        ]);
        DB::table('user_permissions')->insert([
            'user_id' => $lecturer->id, 'permission' => 'library.view', 'school_id' => $this->school, 'created_at' => now(),
        ]);

        $before = [
            'code' => $lecturer->code,
            'role' => (int) $lecturer->role_id,
            'roles' => DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all(),
            'grants' => DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all(),
            'effective' => app(PermissionService::class)->effectivePermissions($lecturer),
        ];

        $this->post(route('admin.staff.account-access.send', $lecturer->id))->assertRedirect();

        Mail::assertSent(GenericStaffPasswordSetupMail::class, function ($mail) use ($lecturer) {
            // The link carries a token, never a password.
            $this->assertStringContainsString('password/reset', $mail->setupUrl);
            $this->assertStringNotContainsString(self::PRESET_PASSWORD, $mail->setupUrl);
            $this->assertStringNotContainsString($lecturer->password, $mail->setupUrl);
            $this->assertSame($lecturer->name, $mail->staffName);

            return true;
        });

        // A token now exists for the broker to manage, and the password is intact.
        $this->assertSame(1, DB::table('password_resets')->where('email', $lecturer->email)->count());
        $this->assertSame(self::PRESET_PASSWORD, $lecturer->fresh()->password);
        $this->assertSame($before['code'], $lecturer->fresh()->code);
        $this->assertSame($before['role'], (int) $lecturer->fresh()->role_id);
        $this->assertSame($before['roles'], DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all());
        $this->assertSame($before['grants'], DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all());
        $this->assertEqualsCanonicalizing($before['effective'], app(PermissionService::class)->effectivePermissions($lecturer->fresh()));

        // The issue is audited.
        $this->assertNotNull(AuditLog::where('action', 'STAFF_PASSWORD_SETUP_LINK_ISSUED')
            ->where('record_id', $lecturer->id)->first());
    }

    public function test_account_access_is_blocked_across_tenants_and_for_unauthorised_actors(): void
    {
        $lecturer = $this->makeLecturer();
        $outsider = $this->makeLecturerInAnotherSchool();
        $teacher = User::factory()->create([
            'name' => 'Plain Teacher', 'role_id' => 3, 'school_id' => $this->school,
            'account_status' => 'active', 'staff_status' => 'active',
        ]);

        // Another school's staff id: a plain 404 on both entry points and both verbs.
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.staff.account-access.send', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.rbac.staff.account-access.send', $outsider->id))->assertNotFound();

        // A non-administrator is refused, and nothing was issued.
        $this->actingAs($teacher)->get(route('admin.staff.account-access.show', $lecturer->id))->assertForbidden();
        $this->actingAs($teacher)->post(route('admin.staff.account-access.send', $lecturer->id))->assertForbidden();
        $this->assertSame(0, DB::table('password_resets')->count());

        // A student is not a staff record.
        $student = User::factory()->create([
            'name' => 'A Student', 'role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active',
        ]);
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $student->id))->assertNotFound();
    }

    public function test_account_access_and_roles_and_permissions_are_gated_by_different_permissions(): void
    {
        $routes = app(PermissionService::class);

        // The two concepts are declared to the RBAC layer as different
        // permissions, so neither screen can be reached by borrowing the
        // other's authority.
        $this->assertSame('staff.accounts', $routes->routePermission('admin.staff.account-access.show'));
        $this->assertSame('staff.accounts', $routes->routePermission('admin.staff.account-access.send'));
        $this->assertSame('users.assign_roles', $routes->routePermission('admin.rbac.staff.show'));
        $this->assertSame('users.assign_roles', $routes->routePermission('admin.rbac.staff.index'));

        // Neither is an HR record edit: a password setup link is not a staff
        // correction, so it neither requires nor grants staff.edit.
        $this->assertNotSame('staff.edit', $routes->routePermission('admin.staff.account-access.send'));
        $this->assertNotSame('staff.view', $routes->routePermission('admin.staff.account-access.show'));

        // The existing account-access entry point is untouched.
        $this->assertSame('users.assign_roles', $routes->routePermission('admin.rbac.staff.account-access'));
    }

    // ================================ setup-state completion (durable flag)

    public function test_a_new_staff_account_who_has_never_set_a_password_is_setup_required(): void
    {
        // 1. New staff without completed setup -> Setup required.
        $lecturer = $this->makeLecturer();
        $this->assertSame(1, (int) $lecturer->force_password_change);

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'required')
            ->assertSee('Setup required')
            ->assertSee('Send Password Setup Link')
            ->assertDontSee('Resend Password Setup Link');
    }

    public function test_issuing_a_setup_link_moves_the_account_to_pending_setup(): void
    {
        // 2. Sending setup link -> Pending setup.
        Mail::fake();
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);

        $this->post(route('admin.staff.account-access.send', $lecturer->id))->assertRedirect();

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'pending')
            ->assertSee('Pending setup')
            ->assertSee('Resend Password Setup Link');
    }

    public function test_successful_initial_setup_completes_setup_and_survives_a_refresh(): void
    {
        // 3. + 4. Successful setup -> Completed, and still Completed on reload.
        Mail::fake();
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);
        $this->post(route('admin.staff.account-access.send', $lecturer->id))->assertRedirect();

        $this->completeSetupAs($lecturer, $this->setupTokenFor($lecturer), 'Daniel-Chosen-Pass-4242!');

        // The DURABLE flag, not the token, is what moved. The token is gone.
        $this->assertSame(0, (int) $lecturer->fresh()->force_password_change,
            'a successful password establishment clears the durable setup flag');
        $this->assertSame(0, DB::table('password_resets')->where('email', $lecturer->email)->count());

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'completed')
            ->assertSee('Completed');

        // A refresh changes nothing.
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertViewHas('setupState', 'completed');
        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertViewHas('setupState', 'completed');
    }

    public function test_a_completed_account_offers_no_setup_or_resend_action_and_can_authenticate(): void
    {
        // 5. + 6. No Send/Resend button, and the chosen credentials work.
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);
        $this->post(route('admin.staff.account-access.send', $lecturer->id));

        $password = 'Daniel-Chosen-Pass-4242!';
        $this->completeSetupAs($lecturer, $this->setupTokenFor($lecturer), $password);

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertDontSee('Send Password Setup Link')
            ->assertDontSee('Resend Password Setup Link');

        // The POST endpoint refuses too, not just the rendered button.
        $this->post(route('admin.staff.account-access.send', $lecturer->id))
            ->assertSessionHas('message');
        $this->assertSame(0, DB::table('password_resets')->where('email', $lecturer->email)->count(),
            'no further link can be issued once setup is complete');

        // The staff member's own chosen password is the one that works.
        $fresh = $lecturer->fresh();
        $this->assertTrue(Hash::check($password, $fresh->password));
        $this->assertNotSame($password, $fresh->password, 'never stored in plaintext');
        $this->assertFalse(Hash::check(self::PRESET_PASSWORD, $fresh->password));
        $this->assertTrue(Auth::guard('web')->attempt(['email' => $lecturer->email, 'password' => $password]));
    }

    public function test_an_ordinary_forgotten_password_reset_does_not_regress_a_completed_account(): void
    {
        // 7. A later forgot-password token must NOT move Completed -> Pending.
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);
        $this->post(route('admin.staff.account-access.send', $lecturer->id));
        $this->completeSetupAs($lecturer, $this->setupTokenFor($lecturer), 'Daniel-Chosen-Pass-4242!');

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertViewHas('setupState', 'completed');

        // The staff member forgets their password and a fresh token is issued.
        $laterToken = $this->setupTokenFor($lecturer);
        $this->assertSame(1, DB::table('password_resets')->where('email', $lecturer->email)->count());

        // Even with a live token outstanding, the account is still Completed:
        // the state is read from the durable flag, never from token presence.
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertOk()
            ->assertViewHas('setupState', 'completed')
            ->assertSee('Completed')
            ->assertDontSee('Resend Password Setup Link');

        // And after using it, still Completed.
        $this->completeSetupAs($lecturer, $laterToken, 'Daniel-Recovered-Pass-7788!');
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertViewHas('setupState', 'completed');
    }

    public function test_a_failed_or_expired_setup_does_not_complete_setup(): void
    {
        // 8. A failed / expired attempt must leave the account untouched.
        Mail::fake();
        $lecturer = $this->makeLecturer();
        $this->actingAs($this->admin);
        $this->post(route('admin.staff.account-access.send', $lecturer->id));
        $token = $this->setupTokenFor($lecturer);
        $passwordBefore = $lecturer->fresh()->password;

        // (a) A wrong token.
        $this->post(route('password.update'), [
            'token' => 'not-a-real-token', 'email' => $lecturer->email,
            'password' => 'Should-Never-Be-Applied-1!', 'password_confirmation' => 'Should-Never-Be-Applied-1!',
        ])->assertSessionHasErrors('email');

        // (b) A mismatched confirmation.
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $lecturer->email,
            'password' => 'Mismatch-Pass-1!', 'password_confirmation' => 'Mismatch-Pass-2!',
        ])->assertSessionHasErrors('password');

        // (c) An expired token.
        DB::table('password_resets')->where('email', $lecturer->email)
            ->update(['created_at' => now()->subMinutes((int) config('auth.passwords.users.expire') + 5)]);

        $this->post(route('password.update'), [
            'token' => $token, 'email' => $lecturer->email,
            'password' => 'Expired-Pass-1!', 'password_confirmation' => 'Expired-Pass-1!',
        ])->assertSessionHasErrors('email');

        // Nothing was applied and setup is NOT complete.
        $fresh = $lecturer->fresh();
        $this->assertSame(1, (int) $fresh->force_password_change, 'a failed attempt never completes setup');
        $this->assertSame($passwordBefore, $fresh->password, 'the existing password is untouched');
        $this->assertFalse(Hash::check('Should-Never-Be-Applied-1!', $fresh->password));

        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $lecturer->id))
            ->assertViewHas('setupState', 'required');
    }

    public function test_every_supported_staff_base_role_uses_the_same_setup_state_logic(): void
    {
        // 9. One state machine for all staff base roles, not just Other Staff.
        $roles = [
            SystemRole::TEACHER, SystemRole::ACCOUNTANT, SystemRole::LIBRARIAN,
            SystemRole::WARDEN, SystemRole::ADMISSIONS_OFFICER, SystemRole::HR_MANAGER,
            SystemRole::PROCUREMENT_OFFICER, SystemRole::DIRECTOR, SystemRole::GENERIC_STAFF,
        ];

        foreach ($roles as $roleId) {
            $member = $this->makeLecturer([
                'email' => "role-{$roleId}@example.test",
                'role_id' => $roleId,
                'code' => "STF-ROLE-{$roleId}",
            ]);

            // required -> pending -> completed, with no role-specific branch.
            $this->assertSame(StaffAccountAccess::REQUIRED, StaffAccountAccess::state($member),
                "role {$roleId} starts Setup required");

            $this->setupTokenFor($member);
            $this->assertSame(StaffAccountAccess::PENDING, StaffAccountAccess::state($member->fresh()),
                "role {$roleId} becomes Pending setup once a link is issued");

            $this->completeSetupAs($member, $this->setupTokenFor($member->fresh()), 'Role-Wide-Pass-5150!');
            $member = $member->fresh();

            $this->assertSame(0, (int) $member->force_password_change, "role {$roleId} durable flag cleared");
            $this->assertSame(StaffAccountAccess::COMPLETED, StaffAccountAccess::state($member),
                "role {$roleId} reaches Completed");

            // And the admin screen agrees, for this role.
            $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $member->id))
                ->assertOk()
                ->assertViewHas('setupState', 'completed')
                ->assertDontSee('Send Password Setup Link');
        }
    }

    public function test_completing_setup_preserves_identity_tenant_rbac_and_issues_no_mail(): void
    {
        // 10. + 11. Cross-tenant and RBAC are unaffected by a completion.
        Mail::fake();
        $lecturer = $this->makeLecturer();
        $outsider = $this->makeLecturerInAnotherSchool();

        $roleId = DB::table('staff_roles')->insertGetId([
            'school_id' => $this->school, 'name' => 'Examinations Officer',
            'description' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_staff_roles')->insert([
            'user_id' => $lecturer->id, 'staff_role_id' => $roleId, 'school_id' => $this->school, 'created_at' => now(),
        ]);
        DB::table('user_permissions')->insert([
            'user_id' => $lecturer->id, 'permission' => 'library.view', 'school_id' => $this->school, 'created_at' => now(),
        ]);

        $before = [
            'code' => $lecturer->code,
            'role' => (int) $lecturer->role_id,
            'designation' => (int) $lecturer->designation_id,
            'department' => (int) $lecturer->department_id,
            'school' => (int) $lecturer->school_id,
            'roles' => DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all(),
            'grants' => DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all(),
            'effective' => app(PermissionService::class)->effectivePermissions($lecturer),
        ];

        $this->actingAs($this->admin);
        $this->post(route('admin.staff.account-access.send', $lecturer->id));
        // The only mail is the one the send produced; completing setup adds none.
        Mail::assertSent(GenericStaffPasswordSetupMail::class);
        $this->completeSetupAs($lecturer, $this->setupTokenFor($lecturer), 'Daniel-Chosen-Pass-4242!');
        Mail::assertSent(GenericStaffPasswordSetupMail::class, 1);

        $fresh = $lecturer->fresh();
        $this->assertSame($before['code'], $fresh->code, 'the staff number cannot move');
        $this->assertSame($before['role'], (int) $fresh->role_id, 'the base role is unchanged');
        $this->assertSame($before['designation'], (int) $fresh->designation_id);
        $this->assertSame($before['department'], (int) $fresh->department_id);
        $this->assertSame($before['school'], (int) $fresh->school_id);
        $this->assertSame($before['roles'], DB::table('user_staff_roles')->where('user_id', $lecturer->id)->pluck('staff_role_id')->all());
        $this->assertSame($before['grants'], DB::table('user_permissions')->where('user_id', $lecturer->id)->pluck('permission')->all());
        $this->assertEqualsCanonicalizing($before['effective'], app(PermissionService::class)->effectivePermissions($fresh));

        // Cross-tenant is still a plain 404 on both entry points and both verbs.
        $this->actingAs($this->admin)->get(route('admin.staff.account-access.show', $outsider->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $outsider->id))->assertNotFound();
        $this->post(route('admin.staff.account-access.send', $outsider->id))->assertNotFound();
    }

    // =========================================== governed suspend / reinstate

    public function test_suspend_and_reinstate_use_the_existing_lifecycle_and_are_audited(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->post(route('admin.staff.profile.status', $lecturer->id), ['staff_status' => 'suspended'])
            ->assertSessionHas('message');
        $this->assertSame('suspended', $lecturer->fresh()->staff_status);
        $this->assertTrue($lecturer->fresh()->isStaffPortalBlocked(), 'suspended blocks the portal, exactly as creation records it');

        $this->actingAs($this->admin)
            ->post(route('admin.staff.profile.status', $lecturer->id), ['staff_status' => 'active'])
            ->assertSessionHas('message');
        $this->assertSame('active', $lecturer->fresh()->staff_status);

        $entries = AuditLog::where('action', 'STAFF_EMPLOYMENT_STATUS_CHANGED')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        // AuditLog casts old_values / new_values to arrays.
        $this->assertSame(['staff_status' => 'active'], $entries[0]->old_values);
        $this->assertSame(['staff_status' => 'suspended'], $entries[0]->new_values);
        $this->assertSame((int) $lecturer->id, (int) $entries[0]->record_id);
    }

    public function test_an_unsupported_status_transition_is_refused_and_nothing_is_deleted(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)
            ->post(route('admin.staff.profile.status', $lecturer->id), ['staff_status' => 'terminated'])
            ->assertSessionHas('error');
        $this->assertSame('active', $lecturer->fresh()->staff_status);
        $this->assertNotNull(User::find($lecturer->id), 'the record still exists');
    }

    // ============================================================== audit

    public function test_a_correction_is_audited_with_the_old_values_and_never_a_nin(): void
    {
        $lecturer = $this->makeLecturer();
        $profile = StaffProfile::where('user_id', $lecturer->id)->firstOrFail();
        StaffNin::assign($profile, 'CM900123456ABCD');
        $profile->save();

        $this->actingAs($this->admin)
            ->put(route('admin.staff.profile.update', $lecturer->id), $this->editPayload([
                'designation_id' => $this->otherDesignation,
                'nin' => 'CM900123456ABCD',
            ]))
            ->assertRedirect();

        $entry = AuditLog::where('action', 'STAFF_RECORD_UPDATED')->latest('id')->first();
        $this->assertNotNull($entry, 'a staff correction is audited');
        $this->assertSame('Staff', $entry->module);
        $this->assertSame((int) $lecturer->id, (int) $entry->record_id, 'which staff member is identifiable');
        $this->assertSame($this->school, (int) $entry->school_id);
        $this->assertSame((int) $this->admin->id, (int) $entry->user_id, 'who changed the record is identifiable');

        $old = $entry->old_values;
        $this->assertSame($this->designation, (int) $old['designation_id'], 'the old designation is identifiable');
        $this->assertSame('Daniel Okello', $old['name']);

        $new = $entry->new_values;
        $this->assertSame($this->otherDesignation, (int) $new['employment']['designation_id'], 'the new designation is identifiable');

        $serialised = json_encode([$entry->old_values, $entry->new_values, $entry->description]);
        $this->assertStringNotContainsString('CM900123456ABCD', $serialised, 'no NIN value in the audit entry');
        $this->assertStringNotContainsString('nin_encrypted', $serialised);
        $this->assertStringNotContainsString('nin_hash', $serialised);
    }

    // =================================================== Designation master data

    public function test_designations_are_reachable_from_the_staff_screens(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()
            ->assertSee(route('admin.designation_list'), false);

        $this->actingAs($this->admin)->get(route('admin.staff.profile.edit', $lecturer->id))
            ->assertSee(route('admin.designation_list'), false);

        $this->actingAs($this->admin)->get(route('admin.staff.profile.show', $lecturer->id))
            ->assertSee(route('admin.designation_list'), false);
    }

    public function test_designation_create_edit_and_delete_of_an_unused_row(): void
    {
        $this->actingAs($this->admin)->get(route('admin.designation_list'))->assertOk()->assertSee('Registrar', false);

        $this->actingAs($this->admin)
            ->post(route('admin.create.designation'), ['name' => 'Lecturer II'])
            ->assertRedirect();
        $id = (int) DB::table('designations')->where('name', 'Lecturer II')->value('id');
        $this->assertGreaterThan(0, $id, 'a designation can be created');

        $this->actingAs($this->admin)
            ->post(route('admin.designation.update', $id), ['name' => 'Lecturer III'])
            ->assertRedirect();
        $this->assertSame('Lecturer III', DB::table('designations')->where('id', $id)->value('name'), 'a designation can be edited');

        $this->actingAs($this->admin)->get(route('admin.designation.delete', $id))->assertRedirect();
        $this->assertNull(DB::table('designations')->where('id', $id)->first(), 'an unused designation can still be deleted');
    }

    public function test_a_designation_referenced_by_staff_is_protected_from_deletion(): void
    {
        $lecturer = $this->makeLecturer();

        $this->actingAs($this->admin)->get(route('admin.designation.delete', $this->designation))->assertRedirect();

        $this->assertNotNull(DB::table('designations')->where('id', $this->designation)->first(), 'a designation in use is never hard-deleted');
        $this->assertSame($this->designation, (int) $lecturer->fresh()->designation_id, 'the staff record keeps its designation');
    }

    public function test_the_designation_list_explains_why_a_referenced_row_cannot_be_deleted(): void
    {
        $this->makeLecturer();

        $html = $this->actingAs($this->admin)->get(route('admin.designation_list'))->assertOk()->getContent();

        $this->assertStringContainsString('In use', $html);
        $this->assertStringNotContainsString(route('admin.designation.delete', $this->designation), $html, 'no delete action for a referenced designation');
        $this->assertStringContainsString(route('admin.designation.delete', $this->otherDesignation), $html, 'an unused designation still offers delete');
    }

    public function test_designation_management_is_tenant_scoped(): void
    {
        $otherSchool = $this->makeSchool();
        $foreignDesignation = $this->makeDesignation($otherSchool, 'Foreign Designation');

        $html = $this->actingAs($this->admin)->get(route('admin.designation_list'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Foreign Designation', $html, "another school's designations are not listed");

        $this->actingAs($this->admin)->get(route('admin.edit.designation', $foreignDesignation))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.designation.delete', $foreignDesignation))->assertNotFound();
        $this->assertNotNull(DB::table('designations')->where('id', $foreignDesignation)->first(), "another school's designation survives");

        $this->actingAs($this->admin)
            ->post(route('admin.create.designation'), ['name' => 'Registrar'])
            ->assertSessionHas('error');
        $this->assertSame(1, DB::table('designations')->where('name', 'Registrar')->count(), 'a duplicate name inside the tenant is refused');
    }

    // ============================================== existing staff regression

    public function test_the_existing_staff_screens_still_work(): void
    {
        $lecturer = $this->makeLecturer();
        $generic = User::factory()->create([
            'name' => 'Test Registrar', 'email' => 'registrar@example.test', 'role_id' => 20,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
        ]);

        $this->actingAs($this->admin)->get(route('admin.staff.add'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.staff.create', 'lecturer'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->assertSee($lecturer->name);
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.show', $lecturer->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $generic->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.staff.other.edit', $generic->id))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.designation_list'))->assertOk();
    }

    // ============================================================== fixtures

    private function makeLecturer(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'name' => 'Daniel Okello', 'email' => 'daniel.okello@example.test', 'role_id' => 3,
            'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
            'code' => 'STF-2026-4762-3958', 'first_name' => 'Daniel', 'last_name' => 'Okello',
            'department_id' => $this->department, 'designation_id' => $this->designation,
            'employment_type' => 'Full Time', 'password' => self::PRESET_PASSWORD,
            'force_password_change' => 1,
            'user_information' => json_encode([
                'gender' => 'Male', 'blood_group' => 'O+', 'birthday' => strtotime('1988-04-17'),
                'phone' => '+256 712 000 111', 'address' => 'Plot 1, Kampala', 'photo' => '',
            ]),
        ], $overrides));

        // Every real staff record has a profile row; the fixture matches that.
        StaffProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['school_id' => $this->school, 'emergency_contact_name' => 'Sister Nok', 'emergency_contact_relationship' => 'Sibling',
                'emergency_contact_email' => 'nok@example.test', 'emergency_contact_phone' => '+256 712 345 678',
                'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]
        );

        return $user;
    }

    private function makeLecturerInAnotherSchool(): User
    {
        $otherSchool = $this->makeSchool();

        return User::factory()->create([
            'name' => 'Foreign Lecturer', 'email' => 'foreign@example.test', 'role_id' => 3,
            'school_id' => $otherSchool, 'account_status' => 'active', 'staff_status' => 'active',
            'code' => 'STF-FOREIGN-0001', 'first_name' => 'Foreign', 'last_name' => 'Lecturer',
            'department_id' => $this->makeDepartment($otherSchool, 'Foreign Dept'),
            'designation_id' => $this->makeDesignation($otherSchool, 'Foreign Role'),
        ]);
    }

    /** A lecturer created through the real creation form, professional records included. */
    private function createLecturerThroughTheForm(): User
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route('admin.staff.create.store', 'lecturer'), $this->creationPayload())
            ->assertRedirect();

        return User::where('email', 'lecturer@example.test')->firstOrFail();
    }

    private function creationPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Grace', 'last_name' => 'Lecturer', 'title' => 'Dr', 'gender' => 'Female',
            'email' => 'lecturer@example.test', 'phone' => '+256 712 000 111',
            'designation_id' => $this->designation, 'department_id' => $this->department,
            'employment_type' => 'Full Time', 'staff_status' => 'active',
            'emergency_contact_name' => 'Sister Nok', 'emergency_contact_relationship' => 'Sibling',
            'emergency_contact_email' => 'nok@example.test', 'emergency_contact_phone' => '+256 712 345 678',
            'qualification_level' => "Master's Degree", 'field_of_study' => 'Software Engineering',
            'institution' => 'Makerere University',
        ], $overrides);
    }

    /** A valid Edit Staff submission, matching what the role-aware form posts. */
    private function editPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Daniel', 'middle_name' => '', 'last_name' => 'Okello',
            'title' => 'Mr', 'gender' => 'Male', 'birthday' => '1988-04-17', 'nationality' => 'Ugandan',
            'blood_group' => 'O+', 'address' => 'Plot 1, Kampala', 'nin' => '',
            'email' => 'daniel.okello@example.test', 'phone' => '+256 712 000 111', 'alternative_phone' => '',
            'department_id' => $this->department, 'designation_id' => $this->designation,
            'employment_type' => 'Full Time', 'staff_status' => 'active', 'date_joined' => '',
            'emergency_contact_name' => 'Sister Nok', 'emergency_contact_relationship' => 'Sibling',
            'emergency_contact_email' => 'nok@example.test', 'emergency_contact_phone' => '+256 712 345 678',
        ], $overrides);
    }

    /**
     * The broker's own token, issued without any mail. The broker is the only
     * thing that ever holds it, exactly as it is in production.
     */
    private function setupTokenFor(User $member): string
    {
        return Password::broker('users')->createToken($member);
    }

    /** The staff member completes setup at the real public reset endpoint. */
    private function completeSetupAs(User $member, string $token, string $password): void
    {
        Auth::logout();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $member->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check($password, $member->fresh()->password),
            'the staff member\'s own chosen password is the one established');
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('A', 1024));
    }
}
