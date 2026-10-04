<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\LiveClass;
use App\Models\OnlineExam;
use App\Models\User;
use App\Support\Permissions\OnlineExamAuthorizer;
use App\Support\Permissions\OnlineExamPermissionService;
use App\Support\Permissions\PermissionAssignmentService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * RBAC Phase 3B — Administration → Roles & Permissions, exercised through the
 * real HTTP routes (Custom Roles + Staff Access screens).
 *
 * Covers: role CRUD (create/edit/duplicate/deactivate/delete), the grouped
 * permission selector (sensitive + non-delegable handling, dependencies,
 * templates), staff delegation (custom roles + direct grants + revocation),
 * the read-only Effective Access explanation, staff filtering, audit logging,
 * tenant isolation through manipulated URL ids, no self-escalation, and the
 * legacy layers (role_perm, menu_permission, roles 10/11/12) staying intact.
 *
 * Store Keeper (role 17) is the "clean" base role where a delegated grant is
 * the only way into a module; Teacher (role 3) is the realistic persona.
 */
class RbacRoleManagementUiTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $schoolA;
    private int $schoolB;
    private User $adminA;
    private User $adminB;
    private User $teacherA;
    private User $teacherB;
    private PermissionService $perms;
    private PermissionAssignmentService $assign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();

        $this->schoolA = $this->makeSchool(['title' => 'School A', 'status' => 1]);
        $this->schoolB = $this->makeSchool(['title' => 'School B', 'status' => 1]);
        $this->adminA = $this->user(2, $this->schoolA, ['name' => 'Admin A']);
        $this->adminB = $this->user(2, $this->schoolB, ['name' => 'Admin B']);
        $this->teacherA = $this->user(3, $this->schoolA, ['name' => 'John Teacher', 'email' => 'john@a.test', 'code' => 'STF-001']);
        $this->teacherB = $this->user(3, $this->schoolB, ['name' => 'Bea Teacher', 'email' => 'bea@b.test']);
        $this->perms = app(PermissionService::class);
        $this->assign = app(PermissionAssignmentService::class);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function user(int $role, int $school, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $school, 'account_status' => 'active']);
    }

    /** Creates a role through the service as that school's admin (setup, not the behaviour under test). */
    private function role(User $admin, string $name, array $permissions): int
    {
        $this->actingAs($admin);

        return $this->assign->createStaffRole($admin, $name, $permissions);
    }

    private function roleRow(int $id): ?object
    {
        return DB::table('staff_roles')->where('id', $id)->first();
    }

    private function bundle(int $id): array
    {
        return DB::table('staff_role_permissions')->where('staff_role_id', $id)->orderBy('permission')->pluck('permission')->all();
    }

    private function direct(User $user): array
    {
        return DB::table('user_permissions')->where('user_id', $user->id)->orderBy('permission')->pluck('permission')->all();
    }

    private function audits(string $action): \Illuminate\Support\Collection
    {
        return DB::table('audit_logs')->where('module', 'RBAC')->where('action', $action)->get();
    }

    /** Is the permission checkbox for $key rendered checked (and/or disabled)? */
    private function checkbox(string $html, string $key): ?string
    {
        return preg_match('/<input type="checkbox"[^>]*name="permissions\[\]" value="' . preg_quote($key, '/') . '"[^>]*>/s', $html, $m) ? $m[0] : null;
    }

    private function allows(User $user, string $key): bool
    {
        return $this->perms->allows($user->fresh(), $key);
    }

    /** All routes of the Roles & Permissions screens with sample parameters, per verb. */
    private function rbacRequests(int $roleId, int $staffId): array
    {
        return [
            ['GET', route('admin.rbac.roles.index')],
            ['GET', route('admin.rbac.roles.create')],
            ['POST', route('admin.rbac.roles.store'), ['name' => 'Sneaky', 'permissions' => ['library.view']]],
            ['GET', route('admin.rbac.roles.show', $roleId)],
            ['GET', route('admin.rbac.roles.edit', $roleId)],
            ['PUT', route('admin.rbac.roles.update', $roleId), ['name' => 'Renamed', 'permissions' => ['library.view']]],
            ['POST', route('admin.rbac.roles.duplicate', $roleId)],
            ['POST', route('admin.rbac.roles.status', $roleId), ['active' => 0]],
            ['DELETE', route('admin.rbac.roles.destroy', $roleId)],
            ['GET', route('admin.rbac.staff.index')],
            ['GET', route('admin.rbac.staff.show', $staffId)],
            ['POST', route('admin.rbac.staff.roles.assign', $staffId), ['staff_role_id' => $roleId]],
            ['DELETE', route('admin.rbac.staff.roles.remove', [$staffId, $roleId])],
            ['POST', route('admin.rbac.staff.permissions.grant', $staffId), ['permissions' => ['library.view']]],
            ['DELETE', route('admin.rbac.staff.permissions.revoke', [$staffId, 'library.view'])],
            ['DELETE', route('admin.rbac.staff.permissions.clear', $staffId)],
            ['GET', route('admin.settings.permissions')],
        ];
    }

    // ── Role list, selector, templates ──────────────────────────────────────

    public function test_role_and_staff_screens_render_with_useful_empty_states(): void
    {
        $this->actingAs($this->adminA)->get(route('admin.rbac.roles.index'))->assertOk()
            ->assertSee('No custom roles have been created for this school.')
            ->assertSee('Examinations Officer');   // template button

        $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $this->teacherA->id))->assertOk()
            ->assertSee('No custom roles are assigned.')
            ->assertSee('No additional permissions have been assigned.')
            ->assertSee('Teacher / Lecturer');

        $id = $this->role($this->adminA, 'Library Helper', ['library.view']);
        $this->actingAs($this->adminA)->get(route('admin.rbac.roles.show', $id))->assertOk()
            ->assertSee('No staff members are assigned to this role.');

        $page = $this->actingAs($this->adminA)->get(route('admin.rbac.roles.index'))->assertOk();
        $page->assertSee('Library Helper')->assertDontSee('No custom roles have been created');
        foreach (['Custom role', 'Description', 'Users', 'Permissions', 'Status', 'Actions', 'View', 'Edit', 'Duplicate', 'Deactivate', 'Delete'] as $column) {
            $page->assertSee($column);
        }
    }

    public function test_permission_selector_is_grouped_by_module_marks_sensitive_and_locks_non_delegable(): void
    {
        $html = $this->actingAs($this->adminA)->get(route('admin.rbac.roles.create'))->assertOk()->getContent();

        // Grouped by module (one fieldset per registry module), never one flat list.
        $this->assertSame(count(PermissionRegistry::modules()), substr_count($html, '<fieldset class="rbac-module">'));
        foreach (['Online Exams', 'Live Classes', 'Admissions', 'Finance'] as $module) {
            $this->assertStringContainsString("<strong>{$module}</strong>", $html);
        }

        // Every registry permission is offered exactly once; nothing outside the registry.
        preg_match_all('/name="permissions\[\]" value="([^"]+)"/', $html, $m);
        $this->assertEqualsCanonicalizing(array_keys(PermissionRegistry::permissions()), $m[1]);

        foreach (PermissionRegistry::permissions() as $key => $definition) {
            $box = $this->checkbox($html, $key);
            $this->assertStringContainsString('data-sensitive="' . ($definition['sensitive'] ? 1 : 0) . '"', $box, $key);
            $this->assertSame(!$definition['delegable'], str_contains($box, 'disabled'), "{$key} locked state");
        }
        $this->assertStringContainsString('School Admin only', $html);
        $this->assertStringContainsString('Sensitive', $html);
        // "Select all" is scripted to skip sensitive and locked boxes.
        $this->assertStringContainsString("cb.getAttribute('data-sensitive') !== '1'", $html);
    }

    public function test_templates_prefill_only_and_never_preselect_sensitive_permissions(): void
    {
        foreach (PermissionRegistry::templates() as $key => $template) {
            foreach ($template['permissions'] as $permission) {
                $this->assertTrue($this->perms->isDelegable($permission), "{$key}: {$permission}");
                $this->assertFalse($this->perms->isSensitive($permission), "{$key}: {$permission}");
            }
        }
        $this->assertNotContains('finance.settings', PermissionRegistry::templates()['finance_officer']['permissions']);

        $html = $this->actingAs($this->adminA)->get(route('admin.rbac.roles.create', ['template' => 'examinations_officer']))->assertOk()->getContent();
        $this->assertStringContainsString('value="Examinations Officer"', $html);
        $this->assertStringContainsString('checked', $this->checkbox($html, 'online_exams.mark'));
        $this->assertStringNotContainsString('checked', $this->checkbox($html, 'online_exams.publish'));
        $this->assertStringNotContainsString('checked', $this->checkbox($html, 'online_exams.results'));

        // A template creates nothing by itself.
        $this->assertSame(0, DB::table('staff_roles')->count());
    }

    // ── Create / edit / duplicate / deactivate / delete ─────────────────────

    public function test_school_admin_creates_a_role_and_actions_bring_their_view(): void
    {
        $response = $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), [
            'name' => 'Examinations Officer',
            'description' => 'Manages examination preparation, publication, marking and results for this school.',
            'permissions' => ['online_exams.edit_all', 'online_exams.mark'],
            'school_id' => $this->schoolB,   // never trusted
        ]);

        $role = DB::table('staff_roles')->where('name', 'Examinations Officer')->first();
        $this->assertNotNull($role);
        $response->assertRedirect(route('admin.rbac.roles.show', $role->id));
        $this->assertSame($this->schoolA, (int) $role->school_id);
        $this->assertTrue((bool) $role->is_active);
        $this->assertSame(['online_exams.edit_all', 'online_exams.mark', 'online_exams.view'], $this->bundle($role->id));

        $audit = $this->audits('ROLE_CREATED')->first();
        $this->assertSame($this->adminA->id, (int) $audit->user_id);
        $this->assertSame($this->schoolA, (int) $audit->school_id);
        $this->assertSame((int) $role->id, (int) $audit->record_id);
        $this->assertContains('online_exams.mark', json_decode($audit->new_values, true)['permissions']);

        // No new system role was created and nobody's base role changed.
        $this->assertSame(0, DB::table('roles')->count());
        $this->assertSame(3, (int) $this->teacherA->fresh()->role_id);
    }

    public function test_role_creation_rejects_unknown_non_delegable_and_unconfirmed_sensitive_permissions(): void
    {
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Ghost', 'permissions' => ['exams.teleport']])->assertForbidden();
        foreach (['roles.manage', 'permissions.assign', 'users.assign_roles', 'settings.api', 'admins.manage'] as $reserved) {
            $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Escalator', 'permissions' => [$reserved]])->assertForbidden();
            // Acknowledging "sensitive" never unlocks a non-delegable permission.
            $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Escalator', 'permissions' => [$reserved], 'acknowledge_sensitive' => 1])->assertForbidden();
        }

        // Sensitive permission without the deliberate acknowledgement: refused, nothing saved.
        $this->actingAs($this->adminA)->from(route('admin.rbac.roles.create'))
            ->post(route('admin.rbac.roles.store'), ['name' => 'Finance Officer', 'permissions' => ['finance.view', 'finance.settings']])
            ->assertRedirect(route('admin.rbac.roles.create'))->assertSessionHas('error');
        $this->actingAs($this->adminA)->postJson(route('admin.rbac.roles.store'), ['name' => 'Finance Officer', 'permissions' => ['finance.settings']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Confirm that you intend to grant these sensitive permissions: finance.settings.']);
        $this->assertSame(0, DB::table('staff_roles')->count());

        // Ordinary finance operations never drag in payment-gateway settings.
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Finance Officer', 'permissions' => ['finance.view', 'finance.invoices', 'finance.reports']]);
        $id = (int) DB::table('staff_roles')->where('name', 'Finance Officer')->value('id');
        $this->assertNotContains('finance.settings', $this->bundle($id));

        // With the acknowledgement it is accepted.
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Gateway Admin', 'permissions' => ['finance.settings'], 'acknowledge_sensitive' => 1]);
        $this->assertSame(['finance.settings'], $this->bundle((int) DB::table('staff_roles')->where('name', 'Gateway Admin')->value('id')));

        // Names of protected base roles and duplicates are refused.
        foreach (['Teacher', 'school admin', 'Finance Officer'] as $name) {
            $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => $name, 'permissions' => ['library.view']])->assertSessionHas('error');
        }
        $this->assertSame(2, DB::table('staff_roles')->count());
    }

    public function test_staff_and_delegates_cannot_create_roles_or_reach_any_rbac_screen(): void
    {
        $roleA = $this->role($this->adminA, 'Library Helper', ['library.view']);
        $financeDelegate = $this->user(4, $this->schoolA, ['name' => 'Finance Delegate']);
        $this->actingAs($this->adminA);
        $this->assign->grantMany($this->adminA, $financeDelegate, ['finance.view', 'finance.invoices', 'finance.reports', 'finance.fee_structures']);
        $loaded = $this->user(17, $this->schoolA, ['name' => 'Everything Delegate']);
        // Every delegable permission there is — still no RBAC administration.
        $this->assign->grantMany($this->adminA, $loaded, array_keys(array_filter(PermissionRegistry::permissions(), fn ($d) => $d['delegable'])));
        $this->assertFalse($this->allows($loaded, 'roles.view'));

        foreach ([$this->teacherA, $financeDelegate->fresh(), $loaded->fresh(), $this->user(10, $this->schoolA), $this->user(5, $this->schoolA)] as $staff) {
            foreach ($this->rbacRequests($roleA, $this->teacherA->id) as $request) {
                [$method, $url] = $request;
                $this->actingAs($staff)->call($method, $url, $request[2] ?? [])->assertForbidden();
                $this->actingAs($staff)->json($method, $url, $request[2] ?? [])->assertForbidden();
            }
        }

        $this->assertSame(1, DB::table('staff_roles')->count());
        $this->assertSame('Library Helper', $this->roleRow($roleA)->name);
        $this->assertSame([], $this->direct($this->teacherA));
    }

    public function test_students_parents_and_super_admin_never_reach_school_delegation(): void
    {
        $roleA = $this->role($this->adminA, 'Library Helper', ['library.view']);
        $student = $this->user(7, $this->schoolA);
        $parent = $this->user(6, $this->schoolA);
        $superAdmin = $this->user(1, $this->schoolA);

        foreach ([$student, $parent, $superAdmin] as $outsider) {
            foreach ($this->rbacRequests($roleA, $this->teacherA->id) as $request) {
                $status = $this->actingAs($outsider)->call($request[0], $request[1], $request[2] ?? [])->getStatusCode();
                $this->assertNotSame(200, $status, "role {$outsider->role_id} {$request[0]} {$request[1]}");
                $this->assertTrue(in_array($status, [302, 403], true), "role {$outsider->role_id} got {$status}");
            }
        }
        $this->assertSame(1, DB::table('staff_roles')->count());
        $this->assertSame([], $this->direct($this->teacherA));
    }

    public function test_edit_updates_the_bundle_and_access_follows(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all', 'online_exams.cancel']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.cancel'));

        $html = $this->actingAs($this->adminA)->get(route('admin.rbac.roles.edit', $id))->assertOk()->getContent();
        $this->assertStringContainsString('checked', $this->checkbox($html, 'online_exams.cancel'));

        $this->actingAs($this->adminA)->put(route('admin.rbac.roles.update', $id), [
            'name' => 'Senior Examinations Officer', 'description' => 'Updated', 'permissions' => ['online_exams.edit_all'],
        ])->assertRedirect(route('admin.rbac.roles.show', $id));

        $this->assertSame('Senior Examinations Officer', $this->roleRow($id)->name);
        $this->assertSame(['online_exams.edit_all', 'online_exams.view'], $this->bundle($id));
        $this->assertFalse($this->allows($this->teacherA, 'online_exams.cancel'));
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.edit_all'));

        $audit = $this->audits('ROLE_UPDATED')->first();
        $this->assertContains('online_exams.cancel', json_decode($audit->old_values, true)['permissions']);
        $this->assertNotContains('online_exams.cancel', json_decode($audit->new_values, true)['permissions']);
        $this->assertSame('Examinations Officer', json_decode($audit->old_values, true)['name']);
    }

    public function test_duplicate_copies_description_and_permissions_but_never_assignments(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.mark', 'online_exams.edit_all']);
        DB::table('staff_roles')->where('id', $id)->update(['description' => 'Runs exams']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);

        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.duplicate', $id), ['name' => 'Senior Examinations Officer'])->assertRedirect();
        $copy = DB::table('staff_roles')->where('name', 'Senior Examinations Officer')->first();

        $this->assertNotNull($copy);
        $this->assertSame('Runs exams', $copy->description);
        $this->assertSame($this->schoolA, (int) $copy->school_id);
        $this->assertSame($this->bundle($id), $this->bundle($copy->id));
        $this->assertSame(0, DB::table('user_staff_roles')->where('staff_role_id', $copy->id)->count());
        $this->assertSame(1, DB::table('user_staff_roles')->where('staff_role_id', $id)->count());

        // Without a name the copy is suffixed.
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.duplicate', $id));
        $this->assertTrue(DB::table('staff_roles')->where('name', 'Examinations Officer (copy)')->exists());
    }

    public function test_deactivated_role_stays_visible_grants_nothing_and_cannot_be_newly_assigned(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.edit_all'));

        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.status', $id), ['active' => 0])->assertRedirect();

        $this->assertFalse((bool) $this->roleRow($id)->is_active);
        $this->assertFalse($this->allows($this->teacherA, 'online_exams.edit_all'));
        $this->assertFalse(app(OnlineExamPermissionService::class)->has($this->teacherA->fresh(), 'edit_all_online_exams'));
        $this->assertTrue(DB::table('user_staff_roles')->where('staff_role_id', $id)->exists(), 'assignment kept for history');
        $this->actingAs($this->adminA)->get(route('admin.rbac.roles.index'))->assertSee('Examinations Officer')->assertSee('Inactive');
        $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $this->teacherA->id))->assertSee('Inactive — grants nothing');

        // Cannot be newly assigned.
        $other = $this->user(3, $this->schoolA);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $other->id), ['staff_role_id' => $id])->assertSessionHas('error');
        $this->assertFalse(DB::table('user_staff_roles')->where('user_id', $other->id)->exists());

        // Reactivating restores the existing assignment.
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.status', $id), ['active' => 1]);
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.edit_all'));

        $this->assertCount(1, $this->audits('ROLE_DEACTIVATED'));
        $this->assertCount(1, $this->audits('ROLE_ACTIVATED'));
    }

    public function test_delete_is_refused_while_assigned_and_never_touches_users(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);

        $this->actingAs($this->adminA)->from(route('admin.rbac.roles.index'))->delete(route('admin.rbac.roles.destroy', $id))
            ->assertRedirect(route('admin.rbac.roles.index'))
            ->assertSessionHas('error', 'This role is currently assigned to 1 staff member. Remove it from them first, or deactivate the role instead.');
        $this->assertNotNull($this->roleRow($id));

        $this->actingAs($this->adminA)->delete(route('admin.rbac.staff.roles.remove', [$this->teacherA->id, $id]))->assertRedirect();
        $this->actingAs($this->adminA)->delete(route('admin.rbac.roles.destroy', $id))->assertRedirect(route('admin.rbac.roles.index'));

        $this->assertNull($this->roleRow($id));
        $this->assertSame([], $this->bundle($id));
        $this->assertNotNull($this->teacherA->fresh());
        $this->assertSame(3, (int) $this->teacherA->fresh()->role_id);
        $this->assertCount(1, $this->audits('ROLE_DELETED'));
        $this->assertCount(1, $this->audits('ROLE_CREATED'), 'audit history is never deleted');
    }

    // ── Staff delegation ────────────────────────────────────────────────────

    public function test_staff_list_shows_only_staff_of_the_school_with_their_access_and_filters(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all']);
        $librarian = $this->user(5, $this->schoolA, ['name' => 'Lara Librarian', 'email' => 'lara@a.test', 'code' => 'LIB-9']);
        $suspended = $this->user(4, $this->schoolA, ['name' => 'Sam Suspended', 'staff_status' => 'suspended']);
        $student = $this->user(7, $this->schoolA, ['name' => 'Stu Student']);
        $parent = $this->user(6, $this->schoolA, ['name' => 'Pat Parent']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);
        $this->assign->grant($this->adminA, $this->teacherA, 'live_classes.create');

        $page = $this->actingAs($this->adminA)->get(route('admin.rbac.staff.index'))->assertOk();
        $page->assertSee('John Teacher')->assertSee('STF-001')->assertSee('Teacher')->assertSee('Examinations Officer')
            ->assertSee('Lara Librarian')->assertSee('Sam Suspended')
            ->assertDontSee('Stu Student')->assertDontSee('Pat Parent')->assertDontSee('Bea Teacher')
            // The per-row Actions menu, which is how a staff record is now reached.
            // Account Access (portal / password setup) and Roles & Permissions are
            // deliberately separate entries with separate authority.
            ->assertSee('Account Access')->assertSee('Roles &amp; Permissions', false)
            ->assertSee('View Profile')->assertSee('Edit staff');
        foreach (['Name', 'Staff ID', 'Staff type', 'Access roles', 'Direct permissions', 'Status'] as $column) {
            $page->assertSee($column);
        }

        $list = fn (array $query) => $this->actingAs($this->adminA)->get(route('admin.rbac.staff.index', $query))->assertOk();
        $list(['q' => 'Lara'])->assertSee('Lara Librarian')->assertDontSee('John Teacher');
        $list(['q' => 'john@a.test'])->assertSee('John Teacher')->assertDontSee('Lara Librarian');
        $list(['q' => 'LIB-9'])->assertSee('Lara Librarian')->assertDontSee('John Teacher');
        $list(['q' => 'Stu'])->assertDontSee('Stu Student');
        $list(['base_role' => 5])->assertSee('Lara Librarian')->assertDontSee('John Teacher');
        $list(['base_role' => 7])->assertDontSee('Stu Student');
        $list(['custom_role' => $id])->assertSee('John Teacher')->assertDontSee('Lara Librarian');
        $list(['status' => 'inactive'])->assertSee('Sam Suspended')->assertDontSee('John Teacher');
        $list(['status' => 'active'])->assertSee('John Teacher')->assertDontSee('Sam Suspended');

        // Students and parents are not staff: 404 on Manage Access.
        foreach ([$student, $parent] as $outsider) {
            $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $outsider->id))->assertNotFound();
        }
    }

    public function test_assigning_a_custom_role_adds_its_capabilities_and_keeps_the_teacher_a_teacher(): void
    {
        $baseBefore = $this->perms->basePermissions($this->teacherA);
        $this->assertFalse($this->allows($this->teacherA, 'online_exams.edit_all'));
        $this->assertFalse($this->allows($this->teacherA, 'online_exams.cancel'));

        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all', 'online_exams.cancel', 'online_exams.mark']);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $this->teacherA->id), ['staff_role_id' => $id])
            ->assertRedirect(route('admin.rbac.staff.show', $this->teacherA->id));

        $teacher = $this->teacherA->fresh();
        $this->assertSame(3, (int) $teacher->role_id, 'base role unchanged');
        $this->assertTrue($this->allows($teacher, 'online_exams.edit_all'));
        $this->assertTrue($this->allows($teacher, 'online_exams.cancel'));
        foreach ($baseBefore as $key) {
            $this->assertTrue($this->allows($teacher, $key), "teacher keeps {$key}");
        }
        foreach (['online_exams.publish', 'online_exams.settings', 'finance.view', 'finance.settings', 'library.manage_books', 'roles.manage', 'permissions.assign'] as $unrelated) {
            $this->assertFalse($this->allows($teacher, $unrelated), "teacher must not gain {$unrelated}");
        }

        // Online Exams: the grant flows into the existing service; school checks still apply.
        $exams = app(OnlineExamPermissionService::class);
        $this->assertTrue($exams->has($teacher, 'edit_all_online_exams'));
        $this->assertFalse($exams->has($teacher, 'publish_online_exams'));
        $authorizer = app(OnlineExamAuthorizer::class);
        $this->assertTrue($authorizer->canManageExam($teacher, new OnlineExam(['school_id' => $this->schoolA, 'creator_id' => 999])));
        $this->assertFalse($authorizer->canManageExam($teacher, new OnlineExam(['school_id' => $this->schoolB, 'creator_id' => $teacher->id])));

        $audit = $this->audits('ROLE_ASSIGNED')->first();
        $this->assertSame($this->adminA->id, (int) $audit->user_id);
        $this->assertSame($this->teacherA->id, (int) $audit->record_id);
        $this->assertSame(['staff_roles' => ['Examinations Officer']], json_decode($audit->new_values, true));

        // Removing the role takes it away again.
        $this->actingAs($this->adminA)->delete(route('admin.rbac.staff.roles.remove', [$this->teacherA->id, $id]))->assertRedirect();
        $this->assertFalse($this->allows($this->teacherA, 'online_exams.edit_all'));
        $this->assertCount(1, $this->audits('ROLE_REMOVED'));
    }

    public function test_custom_roles_and_grants_cannot_target_students_parents_admins_or_oneself(): void
    {
        $id = $this->role($this->adminA, 'Library Helper', ['library.view']);
        $student = $this->user(7, $this->schoolA);
        $parent = $this->user(6, $this->schoolA);
        $otherAdmin = $this->user(2, $this->schoolA);

        foreach ([$student, $parent] as $outsider) {
            $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $outsider->id), ['staff_role_id' => $id])->assertNotFound();
            $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $outsider->id), ['permissions' => ['library.view']])->assertNotFound();
        }
        foreach ([$otherAdmin, $this->adminA] as $admin) {
            $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $admin->id))->assertOk()->assertSee('School Administrators already have full access');
            $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $admin->id), ['staff_role_id' => $id])->assertForbidden();
            $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $admin->id), ['permissions' => ['library.view']])->assertForbidden();
        }

        $this->assertSame(0, DB::table('user_staff_roles')->count());
        $this->assertSame(0, DB::table('user_permissions')->count());
        // School Admin keeps full administrative access regardless.
        $this->assertTrue($this->allows($this->adminA, 'permissions.assign'));
        $this->assertTrue($this->allows($this->adminA, 'roles.manage'));
    }

    public function test_direct_grant_takes_effect_and_revocation_removes_only_that_access(): void
    {
        $keeper = $this->user(17, $this->schoolA, ['name' => 'Kim Keeper']);
        $this->assertSame(403, $this->actingAs($keeper)->get(route('admin.fee_manager.list'))->getStatusCode());

        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $keeper->id), ['permissions' => ['finance.invoices', 'library.view']])
            ->assertRedirect(route('admin.rbac.staff.show', $keeper->id));
        $this->assertSame(['finance.invoices', 'finance.view', 'library.view'], $this->direct($keeper), 'action brings its view');
        $this->assertNotSame(403, $this->actingAs($keeper->fresh())->get(route('admin.fee_manager.list'))->getStatusCode());
        $this->assertSame(17, (int) $keeper->fresh()->role_id);

        // Revoking the view revokes the dependent action too; other grants remain.
        $this->actingAs($this->adminA)->delete(route('admin.rbac.staff.permissions.revoke', [$keeper->id, 'finance.view']))->assertRedirect();
        $this->assertSame(['library.view'], $this->direct($keeper));
        $this->assertSame(403, $this->actingAs($keeper->fresh())->get(route('admin.fee_manager.list'))->getStatusCode());
        $this->assertTrue($this->allows($keeper, 'library.view'));

        // Remove all: direct grants only; custom roles stay.
        $role = $this->role($this->adminA, 'Library Helper', ['library.issue']);
        $this->assign->assignStaffRole($this->adminA, $keeper, $role);
        $this->actingAs($this->adminA)->delete(route('admin.rbac.staff.permissions.clear', $keeper->id))->assertRedirect();
        $this->assertSame([], $this->direct($keeper));
        $this->assertTrue($this->allows($keeper, 'library.issue'));
        $this->assertFalse($this->allows($keeper, 'finance.view'));

        $this->assertCount(1, $this->audits('PERMISSION_GRANTED'));
        $this->assertCount(2, $this->audits('PERMISSION_REVOKED'));
        $revoked = $this->audits('PERMISSION_REVOKED')->first();
        $this->assertSame(['permissions' => ['finance.invoices', 'finance.view', 'library.view']], json_decode($revoked->old_values, true));
        $this->assertSame(['permissions' => ['library.view']], json_decode($revoked->new_values, true));
    }

    public function test_direct_grant_rejects_unknown_non_delegable_and_unconfirmed_sensitive_permissions(): void
    {
        $url = route('admin.rbac.staff.permissions.grant', $this->teacherA->id);
        $this->actingAs($this->adminA)->post($url, ['permissions' => ['exams.teleport']])->assertForbidden();
        foreach (['permissions.assign', 'roles.manage', 'roles.view', 'users.assign_roles', 'settings.backup', 'settings.api', 'subscription.manage', 'admins.manage'] as $reserved) {
            $this->actingAs($this->adminA)->post($url, ['permissions' => [$reserved]])->assertForbidden();
            $this->actingAs($this->adminA)->postJson($url, ['permissions' => ['library.view', $reserved]])->assertForbidden();
            $this->actingAs($this->adminA)->post($url, ['permissions' => [$reserved], 'acknowledge_sensitive' => 1])->assertForbidden();
        }
        $this->actingAs($this->adminA)->post($url, ['permissions' => []])->assertSessionHas('error');

        $this->actingAs($this->adminA)->post($url, ['permissions' => ['finance.settings']])->assertSessionHas('error');
        $this->assertSame([], $this->direct($this->teacherA), 'nothing written');

        $this->actingAs($this->adminA)->post($url, ['permissions' => ['finance.settings'], 'acknowledge_sensitive' => 1])->assertRedirect();
        $this->assertSame(['finance.settings'], $this->direct($this->teacherA), 'gateway settings stay separate from fee operations');
    }

    public function test_staff_cannot_escalate_themselves_or_others(): void
    {
        $delegate = $this->user(17, $this->schoolA);
        $this->actingAs($this->adminA);
        $this->assign->grantMany($this->adminA, $delegate, ['staff.view', 'staff.edit', 'finance.view']);
        $delegate = $delegate->fresh();

        foreach ([$delegate->id, $this->teacherA->id] as $target) {
            $this->actingAs($delegate)->post(route('admin.rbac.staff.permissions.grant', $target), ['permissions' => ['library.view']])->assertForbidden();
            $this->actingAs($delegate)->post(route('admin.rbac.staff.roles.assign', $target), ['staff_role_id' => 1])->assertForbidden();
        }
        $this->actingAs($delegate)->post(route('admin.rbac.roles.store'), ['name' => 'Mine', 'permissions' => ['library.view']])->assertForbidden();

        try {
            $this->assign->grant($delegate, $delegate, 'library.view');
            $this->fail('self grant must be refused');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }
        $this->assertSame(['finance.view', 'staff.edit', 'staff.view'], $this->direct($delegate));
        $this->assertSame([], $this->direct($this->teacherA));
    }

    public function test_effective_access_combines_base_role_custom_role_and_direct_grants_read_only(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all', 'online_exams.cancel', 'online_exams.mark']);
        $this->actingAs($this->adminA);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);
        $this->assign->grant($this->adminA, $this->teacherA, 'live_classes.create');
        $teacher = $this->teacherA->fresh();

        $explained = $this->perms->explain($teacher);
        $labels = fn (string $key) => array_column($explained[$key] ?? [], 'label');

        $this->assertContains('Examinations Officer', $labels('online_exams.edit_all'));
        $this->assertNotContains('Teacher / Lecturer base role', $labels('online_exams.edit_all'));
        $this->assertContains('Direct permission', $labels('live_classes.create'));
        foreach ($this->perms->basePermissions($teacher) as $key) {
            $this->assertContains('Teacher / Lecturer base role', $labels($key), $key);
        }
        foreach (['finance.settings', 'roles.manage', 'roles.view', 'permissions.assign', 'users.assign_roles', 'online_exams.publish'] as $absent) {
            $this->assertArrayNotHasKey($absent, $explained);
        }
        foreach (array_keys(PermissionRegistry::permissions()) as $key) {
            if (str_starts_with($key, 'hr.')) {
                $this->assertSame($this->perms->baseAllows($teacher, $key), array_key_exists($key, $explained), "{$key} only if the base role already has it");
            }
        }
        $this->assertEqualsCanonicalizing($this->perms->effectivePermissions($teacher), array_keys($explained));

        $html = $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $teacher->id))->assertOk()->getContent();
        $this->assertStringContainsString('Effective access', $html);
        $effective = substr($html, strpos($html, 'Effective access'));
        $this->assertStringContainsString('rbac-badge-role">Examinations Officer', $effective);
        $this->assertStringContainsString('rbac-badge-direct">Direct permission', $effective);
        $this->assertStringContainsString('rbac-badge-base">Teacher / Lecturer base role', $effective);
        $this->assertStringNotContainsString('<input', $effective, 'effective access is read-only');
        $this->assertStringNotContainsString('<form', $effective);

        // There is no route that edits effective access directly.
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('effective', (string) $route->getName());
        }
    }

    public function test_assign_role_form_confirms_roles_that_carry_sensitive_permissions(): void
    {
        $plain = $this->role($this->adminA, 'Library Helper', ['library.view']);
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.store'), ['name' => 'Results Officer', 'permissions' => ['online_exams.results'], 'acknowledge_sensitive' => 1]);
        $sensitive = (int) DB::table('staff_roles')->where('name', 'Results Officer')->value('id');

        $html = $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $this->teacherA->id))->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $sensitive . '" data-sensitive="1">/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $plain . '" data-sensitive="0">/', $html);
        $this->assertStringContainsString('data-confirm-sensitive', $html);
    }

    // ── Tenant isolation ────────────────────────────────────────────────────

    public function test_school_a_admin_cannot_see_or_touch_school_b_roles_staff_or_grants(): void
    {
        $roleA = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all']);
        $roleB = $this->role($this->adminB, 'B Secret Role', ['library.view']);
        $this->actingAs($this->adminB);
        $this->assign->assignStaffRole($this->adminB, $this->teacherB, $roleB);
        $this->assign->grant($this->adminB, $this->teacherB, 'hostel.view');
        $snapshot = fn () => [DB::table('staff_roles')->where('id', $roleB)->first(), $this->bundle($roleB), $this->direct($this->teacherB),
            DB::table('user_staff_roles')->where('user_id', $this->teacherB->id)->pluck('staff_role_id')->all()];
        $before = $snapshot();

        $a = $this->actingAs($this->adminA);
        $a->get(route('admin.rbac.roles.index'))->assertOk()->assertDontSee('B Secret Role');
        $a->get(route('admin.rbac.staff.index'))->assertOk()->assertDontSee('Bea Teacher');
        $a->get(route('admin.rbac.staff.index', ['q' => 'Bea']))->assertDontSee('Bea Teacher');
        $a->get(route('admin.rbac.roles.show', $roleB))->assertNotFound();
        $a->get(route('admin.rbac.roles.edit', $roleB))->assertNotFound();
        $a->put(route('admin.rbac.roles.update', $roleB), ['name' => 'Hijacked', 'permissions' => ['finance.view']])->assertNotFound();
        $a->post(route('admin.rbac.roles.duplicate', $roleB))->assertNotFound();
        $a->post(route('admin.rbac.roles.status', $roleB), ['active' => 0])->assertNotFound();
        $a->delete(route('admin.rbac.roles.destroy', $roleB))->assertNotFound();
        $a->deleteJson(route('admin.rbac.roles.destroy', $roleB))->assertNotFound();

        // B's role cannot be assigned to A staff; A's role cannot be assigned to B staff.
        $a->post(route('admin.rbac.staff.roles.assign', $this->teacherA->id), ['staff_role_id' => $roleB])->assertNotFound();
        $a->post(route('admin.rbac.staff.roles.assign', $this->teacherB->id), ['staff_role_id' => $roleA])->assertNotFound();
        $a->get(route('admin.rbac.staff.show', $this->teacherB->id))->assertNotFound();
        $a->post(route('admin.rbac.staff.permissions.grant', $this->teacherB->id), ['permissions' => ['finance.view']])->assertNotFound();
        $a->delete(route('admin.rbac.staff.permissions.revoke', [$this->teacherB->id, 'hostel.view']))->assertNotFound();
        $a->delete(route('admin.rbac.staff.permissions.clear', $this->teacherB->id))->assertNotFound();
        $a->delete(route('admin.rbac.staff.roles.remove', [$this->teacherB->id, $roleB]))->assertNotFound();

        $this->assertEquals($before, $snapshot(), 'School B data unchanged');
        $this->assertFalse(DB::table('user_staff_roles')->where('user_id', $this->teacherA->id)->exists());
        $this->assertSame(0, DB::table('audit_logs')->where('module', 'RBAC')->where('user_id', $this->adminA->id)->where('school_id', $this->schoolB)->count());

        // Service-level: School A's admin never reaches School B even when calling the backend directly.
        $this->actingAs($this->adminA);
        foreach ([
            fn () => $this->assign->grant($this->adminA, $this->teacherB, 'library.view'),
            fn () => $this->assign->assignStaffRole($this->adminA, $this->teacherB, $roleB),
            fn () => $this->assign->updateStaffRole($this->adminA, $roleB, 'x', null, []),
            fn () => $this->assign->deleteStaffRole($this->adminA, $roleB),
            fn () => $this->assign->createStaffRole($this->adminA, 'Cross', ['library.view'], null, $this->schoolB),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('cross-school change must be refused');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
            }
        }
        $this->assertEquals($before, $snapshot());
    }

    public function test_a_delegated_permission_never_reaches_another_schools_records(): void
    {
        $keeper = $this->user(17, $this->schoolA);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $keeper->id), ['permissions' => ['library.manage_books', 'live_classes.manage_all']]);
        $keeper = $keeper->fresh();
        $this->assertTrue($this->allows($keeper, 'library.manage_books'));

        $foreignBook = (int) DB::table('books')->insertGetId(['name' => 'B book', 'author' => 'x', 'copies' => 1, 'school_id' => $this->schoolB, 'session_id' => 1, 'timestamp' => time()]);
        $this->actingAs($keeper)->get(route('admin.edit.book', ['id' => $foreignBook]))->assertNotFound();

        // A grant made in School A does not count if the user record is in School B.
        DB::table('user_permissions')->insert(['user_id' => $this->teacherB->id, 'school_id' => $this->schoolA, 'permission' => 'finance.view', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->allows($this->teacherB, 'finance.view'));

        // Live classes: the grant allows managing live classes of the keeper's own school only.
        $this->assertTrue(Gate::forUser($keeper)->allows('update', new LiveClass(['school_id' => $this->schoolA, 'teacher_id' => 999])));
        $this->assertFalse(Gate::forUser($keeper)->allows('update', new LiveClass(['school_id' => $this->schoolB, 'teacher_id' => 999])));
    }

    // ── Navigation ──────────────────────────────────────────────────────────

    public function test_roles_and_permissions_menu_is_shown_to_administrators_only(): void
    {
        $this->bootFixtureGapTables();
        $menuLink = route('admin.rbac.roles.index');

        $this->actingAs($this->adminA)->get(route('admin.rbac.staff.index'))->assertSee($menuLink, false)->assertSee('Roles &amp; Permissions', false);

        // A fully loaded delegate on an admin page they legitimately reach: no RBAC menu.
        $keeper = $this->user(17, $this->schoolA);
        $this->actingAs($this->adminA);
        $this->assign->grantMany($this->adminA, $keeper, array_keys(array_filter(PermissionRegistry::permissions(), fn ($d) => $d['delegable'] && !$d['sensitive'])));
        $this->actingAs($keeper->fresh())->get(route('admin.assets.index'))->assertOk()->assertDontSee($menuLink, false);
    }

    public function test_navigation_hides_modules_the_staff_member_cannot_access(): void
    {
        $this->bootFixtureGapTables();
        $keeper = $this->user(17, $this->schoolA);
        $page = fn () => $this->actingAs($keeper->fresh())->get(route('admin.assets.index'))->assertOk();

        $page()->assertDontSee(route('admin.fee_manager.list'), false);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $keeper->id), ['permissions' => ['finance.view']]);
        $page()->assertSee(route('admin.fee_manager.list'), false);
        $this->actingAs($this->adminA)->delete(route('admin.rbac.staff.permissions.revoke', [$keeper->id, 'finance.view']));
        $page()->assertDontSee(route('admin.fee_manager.list'), false);
    }

    // ── Fixture-gap pages rendered for real (Phase 3A reported 500s) ────────

    private function bootFixtureGapTables(): void
    {
        foreach ([
            '2026_07_02_130008_create_assignments_tables',
            '2026_07_26_150002_create_appraisal_tables',
            '2026_07_26_150004_create_payments_history_and_currency_tables',
            '2026_09_19_000002_restore_school_setup_columns',
        ] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }

        // Mirrors asset_categories/assets from 2026_07_02_130011 (that migration also creates
        // audit_logs, which the shared test schema already provides).
        \Illuminate\Support\Facades\Schema::create('asset_categories', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('name', 100);
            $table->string('icon', 50)->default('fas fa-box');
            $table->string('color', 10)->default('#1a3a6b');
            $table->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('assets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('asset_tag', 50)->nullable();
            $table->string('name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->nullable();
            $table->string('location', 150)->nullable();
            $table->enum('condition', ['new', 'good', 'fair', 'poor', 'condemned'])->default('good');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();
        });

        DB::table('schools')->whereIn('id', [$this->schoolA, $this->schoolB])->update(['school_currency' => 'USD', 'currency_position' => 'left']);
        $gateways = [
            'paypal' => ['test_client_id', 'test_secret_key', 'live_client_id', 'live_secret_key'],
            'stripe' => ['test_key', 'test_secret_key', 'public_live_key', 'secret_live_key'],
            'razorpay' => ['test_key', 'test_secret_key', 'live_key', 'live_secret_key', 'theme_color'],
            'paytm' => ['test_merchant_id', 'test_merchant_key', 'live_merchant_id', 'live_merchant_key', 'environment', 'merchant_website', 'channel', 'industry_type'],
            'flutterwave' => ['test_key', 'test_secret_key', 'test_encryption_key', 'public_live_key', 'secret_live_key', 'encryption_live_key'],
            'paystack' => ['test_key', 'test_secret_key', 'public_live_key', 'secret_live_key'],
            'marzpay' => ['sandbox_api_key', 'sandbox_api_secret', 'live_api_key', 'live_api_secret', 'country'],
        ];
        foreach ($gateways as $name => $keys) {
            DB::table('payment_methods')->insert(['name' => $name, 'payment_keys' => json_encode(array_fill_keys($keys, 'x')), 'image' => "{$name}.png",
                'status' => 1, 'mode' => 'test', 'school_id' => $this->schoolA, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_appraisal_assignments_assets_and_payment_settings_render_for_those_allowed(): void
    {
        $this->bootFixtureGapTables();
        DB::table('assets')->insert(['school_id' => $this->schoolA, 'name' => 'Projector A1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assets')->insert(['school_id' => $this->schoolB, 'name' => 'Projector B1', 'created_at' => now(), 'updated_at' => now()]);

        // School Admin renders every page (200, real content — not merely "not 403").
        $this->actingAs($this->adminA)->get(route('admin.appraisal.appraisalQuestions'))->assertOk();
        $this->actingAs($this->adminA)->get(route('admin.assignments.index'))->assertOk();
        $this->actingAs($this->adminA)->get(route('admin.assets.index'))->assertOk()->assertSee('Projector A1')->assertDontSee('Projector B1');
        $this->actingAs($this->adminA)->get(route('admin.settings.payment'))->assertOk()->assertSee('School Currency');

        // A delegate reaches exactly the modules granted through Roles & Permissions.
        $keeper = $this->user(17, $this->schoolA);
        foreach (['admin.appraisal.appraisalQuestions', 'admin.assignments.index', 'admin.settings.payment'] as $route) {
            $this->actingAs($keeper->fresh())->get(route($route))->assertForbidden();
        }
        $this->actingAs($keeper->fresh())->get(route('admin.assets.index'))->assertOk()->assertSee('Projector A1')->assertDontSee('Projector B1');

        $role = $this->role($this->adminA, 'HR & Academic Helper', ['hr.appraisal', 'academic.assignments']);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $keeper->id), ['staff_role_id' => $role]);
        $this->actingAs($keeper->fresh())->get(route('admin.appraisal.appraisalQuestions'))->assertOk();
        $this->actingAs($keeper->fresh())->get(route('admin.assignments.index'))->assertOk();
        $this->actingAs($keeper->fresh())->get(route('admin.settings.payment'))->assertForbidden();

        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $keeper->id), ['permissions' => ['finance.settings'], 'acknowledge_sensitive' => 1]);
        $this->actingAs($keeper->fresh())->get(route('admin.settings.payment'))->assertOk()->assertSee('School Currency');
    }

    // ── Migration ───────────────────────────────────────────────────────────

    public function test_role_status_migration_is_additive_and_reversible(): void
    {
        $id = $this->role($this->adminA, 'Examinations Officer', ['online_exams.edit_all']);
        $this->assign->assignStaffRole($this->adminA, $this->teacherA, $id);
        $migration = require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php');

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('staff_roles', 'is_active'));
        $this->assertNotNull($this->roleRow($id), 'rollback keeps roles');
        // Before the column exists every role counts as active; status changes are refused, not faked.
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.edit_all'));
        $this->actingAs($this->adminA)->get(route('admin.rbac.roles.index'))->assertOk()->assertDontSee('Deactivate');
        $this->actingAs($this->adminA)->post(route('admin.rbac.roles.status', $id), ['active' => 0])->assertSessionHas('error');

        $migration->up();
        $this->assertTrue((bool) $this->roleRow($id)->is_active, 'existing roles stay active');
        $this->assertTrue($this->allows($this->teacherA, 'online_exams.edit_all'));
    }

    // ── Legacy layers ───────────────────────────────────────────────────────

    public function test_legacy_role_perm_menu_permission_and_frozen_roles_are_preserved(): void
    {
        $rolePerm = json_encode(['online_exams', 'exams']);
        DB::table('global_settings')->insert(['key' => 'role_perm_5', 'value' => $rolePerm]);
        $librarian = $this->user(5, $this->schoolA, ['name' => 'Legacy Librarian']);
        $restricted = $this->user(5, $this->schoolA, ['name' => 'Restricted Librarian', 'menu_permission' => json_encode(['admin.book'])]);

        // Online Exams: legacy role_perm keeps working for users without any RBAC grant.
        $this->assertTrue(app(OnlineExamPermissionService::class)->has($librarian, 'view_online_exams'));
        $this->assertTrue($this->allows($librarian, 'online_exams.view'));

        // RBAC work never writes the legacy layers.
        $role = $this->role($this->adminA, 'Live Helper', ['live_classes.create']);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $librarian->id), ['staff_role_id' => $role]);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $restricted->id), ['staff_role_id' => $role]);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.permissions.grant', $restricted->id), ['permissions' => ['hostel.view']]);
        $this->assertSame($rolePerm, DB::table('global_settings')->where('key', 'role_perm_5')->value('value'));
        $this->assertSame(json_encode(['admin.book']), $restricted->fresh()->menu_permission);
        $this->assertNull($librarian->fresh()->menu_permission);

        // menu_permission still restricts even a delegated grant (Live Class policy).
        $this->assertTrue(Gate::forUser($librarian->fresh())->allows('create', LiveClass::class));
        $this->assertFalse(Gate::forUser($restricted->fresh())->allows('create', LiveClass::class));

        // The legacy Settings → Permissions page stays usable and points to the new screens.
        $this->actingAs($this->adminA)->get(route('admin.settings.permissions'))->assertOk()
            ->assertSee('Legacy role matrix')->assertSee(route('admin.rbac.roles.index'), false);

        // Roles 10/11/12: shown as legacy base roles, never offered as custom roles, never changed.
        $frozen = [];
        foreach ([10, 11, 12] as $roleId) {
            $frozen[$roleId] = $this->user($roleId, $this->schoolA, ['name' => "Frozen {$roleId}"]);
        }
        $staffPage = $this->actingAs($this->adminA)->get(route('admin.rbac.staff.index'))->assertOk();
        $this->assertGreaterThanOrEqual(3, substr_count($staffPage->getContent(), 'rbac-badge-legacy'));
        $roleForm = $this->actingAs($this->adminA)->get(route('admin.rbac.staff.show', $frozen[11]->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('<option value="11"', $roleForm);
        $this->actingAs($this->adminA)->post(route('admin.rbac.staff.roles.assign', $frozen[10]->id), ['staff_role_id' => $role])->assertRedirect();
        foreach ($frozen as $roleId => $user) {
            $this->assertSame($roleId, (int) $user->fresh()->role_id);
        }
        $this->assertSame(0, DB::table('roles')->count(), 'no system roles created');
    }
}
