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
 * Security Phase 2H — clubs are school-owned (clubs.school_id); members and
 * notices belong to a school through their club.
 *
 * Before 2H (measured in the Phase 2G probe) any school's staff could list,
 * rename and delete every school's clubs, members and notices. Each test
 * below checks the cross-school attempt is refused with the School B record
 * unchanged, and that the same operation inside one school still works.
 *
 * The club schema is built by running the real migrations, so the new
 * 2026_09_23_000001 migration (up, backfill, down) is exercised as well.
 */
class ClubTenantOwnershipTest extends TestCase
{
    use StaffModuleTestHelper;

    private const CREATE_CLUBS = 'database/migrations/2026_07_26_150001_create_club_tables.php';
    private const ADD_SCHOOL_ID = 'database/migrations/2026_09_23_000001_add_school_id_to_clubs_table.php';

    private array $A;
    private array $B;
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2h-' . uniqid();
        File::ensureDirectoryExists($this->publicDir . '/assets/uploads/club');
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

        $this->migration(self::CREATE_CLUBS)->up();
        $this->migration(self::ADD_SCHOOL_ID)->up();

        $this->A = $this->world('A');
        $this->B = $this->world('B');
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

    private function world(string $tag): array
    {
        $school = $this->makeSchool(['title' => "School {$tag}", 'status' => 1]);
        $user = fn (int $role, string $name) => User::factory()->create(['role_id' => $role, 'school_id' => $school, 'name' => $name, 'status' => 1, 'account_status' => 'active']);
        $w = [
            'tag' => $tag,
            'school' => $school,
            'admin' => $user(2, "Admin {$tag}"),
            'teacher' => $user(3, "Teacher{$tag} Zq"),
            'student' => $user(7, "Pupil{$tag} Zq"),
        ];
        $w['club'] = $this->club($w, "Club{$tag} Zq");
        $w['member'] = DB::table('club_members')->insertGetId(['club_id' => $w['club'], 'student_id' => $w['student']->id, 'status' => 0]);
        $w['notice'] = DB::table('club_notices')->insertGetId(['club_id' => $w['club'], 'title' => "Notice{$tag} Zq", 'description' => 'd', 'notice_date' => '2026-09-01', 'image' => "img{$tag}.png", 'status' => 1]);
        file_put_contents($this->publicDir . "/assets/uploads/club/img{$tag}.png", 'x');

        return $w;
    }

    private function club(array $w, string $name): int
    {
        return DB::table('clubs')->insertGetId(['club_name' => $name, 'advisor_id' => $w['teacher']->id, 'school_id' => $w['school'], 'status' => 1]);
    }

    private function row(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    private function unchanged(string $table, int $id, array $before, string $message): void
    {
        $after = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($after, "{$message}: School B row deleted");
        $this->assertEquals(array_diff_key($before, ['updated_at' => 1]), array_diff_key((array) $after, ['updated_at' => 1]), "{$message}: School B row changed");
    }

    // ── Migration ────────────────────────────────────────────────────────────

    public function test_migration_adds_an_indexed_school_id_and_rolls_back(): void
    {
        $this->assertTrue(Schema::hasColumn('clubs', 'school_id'));
        $indexes = collect(DB::select("PRAGMA index_list('clubs')"))->pluck('name');
        $this->assertContains('clubs_school_id_index', $indexes->all());

        $this->migration(self::ADD_SCHOOL_ID)->down();
        $this->assertFalse(Schema::hasColumn('clubs', 'school_id'));
        $this->assertSame(2, DB::table('clubs')->count(), 'rollback keeps the clubs');

        $this->migration(self::ADD_SCHOOL_ID)->up();
        $this->assertTrue(Schema::hasColumn('clubs', 'school_id'));
    }

    public function test_migration_backfills_only_deterministic_clubs(): void
    {
        $this->migration(self::ADD_SCHOOL_ID)->down();

        $byAdvisor = DB::table('clubs')->insertGetId(['club_name' => 'advisor', 'advisor_id' => $this->B['teacher']->id, 'status' => 1]);
        $byMembers = DB::table('clubs')->insertGetId(['club_name' => 'members', 'advisor_id' => null, 'status' => 1]);
        DB::table('club_members')->insert([['club_id' => $byMembers, 'student_id' => $this->A['student']->id, 'status' => 1]]);
        $mixed = DB::table('clubs')->insertGetId(['club_name' => 'mixed', 'advisor_id' => null, 'status' => 1]);
        DB::table('club_members')->insert([
            ['club_id' => $mixed, 'student_id' => $this->A['student']->id, 'status' => 1],
            ['club_id' => $mixed, 'student_id' => $this->B['student']->id, 'status' => 1],
        ]);
        $orphan = DB::table('clubs')->insertGetId(['club_name' => 'orphan', 'advisor_id' => 999999, 'status' => 1]);

        $this->migration(self::ADD_SCHOOL_ID)->up();

        $this->assertEquals($this->B['school'], DB::table('clubs')->where('id', $byAdvisor)->value('school_id'));
        $this->assertEquals($this->A['school'], DB::table('clubs')->where('id', $byMembers)->value('school_id'));
        $this->assertNull(DB::table('clubs')->where('id', $mixed)->value('school_id'), 'ambiguous club must not be guessed');
        $this->assertNull(DB::table('clubs')->where('id', $orphan)->value('school_id'), 'orphaned club must not be guessed');
    }

    // ── Club records ─────────────────────────────────────────────────────────

    public function test_club_lists_show_only_the_callers_school(): void
    {
        foreach (['admin' => 'admin.club.index', 'teacher' => 'teacher.club.list', 'student' => 'student.club.list'] as $who => $route) {
            $response = $this->actingAs($this->A[$who])->get(route($route));
            $response->assertOk();
            $response->assertSee('ClubA Zq');
            $response->assertDontSee('ClubB Zq');
            $response->assertDontSee('TeacherB Zq');
        }
    }

    public function test_a_school_cannot_edit_rename_toggle_or_delete_another_schools_club(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $actor = $this->A[$portal];
            $club = $this->B['club'];
            $before = $this->row('clubs', $club);

            $this->actingAs($actor)->get(route("{$portal}.club.edit", $club))->assertNotFound();
            $this->actingAs($actor)->post(route("{$portal}.club.update", $club), ['club_name' => 'HIJACKED', 'school_id' => $this->A['school']])->assertNotFound();
            $this->actingAs($actor)->post(route("{$portal}.club.toggle_status", $club))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.delete", $club))->assertNotFound();
            $this->unchanged('clubs', $club, $before, "{$portal} club");

            // Same school still works.
            $own = $this->club($this->A, "Own{$portal}");
            $this->actingAs($actor)->get(route("{$portal}.club.edit", $own))->assertOk();
            $this->actingAs($actor)->post(route("{$portal}.club.update", $own), ['club_name' => "Renamed{$portal}"]);
            $this->assertSame("Renamed{$portal}", DB::table('clubs')->where('id', $own)->value('club_name'));
            $this->actingAs($actor)->get(route("{$portal}.club.delete", $own));
            $this->assertNull(DB::table('clubs')->where('id', $own)->first());
        }
    }

    public function test_club_update_cannot_move_a_club_to_another_school_or_foreign_advisor(): void
    {
        $this->actingAs($this->A['admin'])->post(route('admin.club.update', $this->A['club']), ['club_name' => 'Kept', 'school_id' => $this->B['school']]);
        $this->assertEquals($this->A['school'], DB::table('clubs')->where('id', $this->A['club'])->value('school_id'));

        $this->actingAs($this->A['admin'])->post(route('admin.club.update', $this->A['club']), ['club_name' => 'Kept', 'advisor_id' => $this->B['teacher']->id]);
        $this->assertEquals($this->A['teacher']->id, DB::table('clubs')->where('id', $this->A['club'])->value('advisor_id'));
    }

    public function test_created_clubs_belong_to_the_creators_school_whatever_is_submitted(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $this->actingAs($this->A[$portal])->post(route("{$portal}.club.store"), [
                'club_name' => "Made by {$portal}", 'advisor_id' => $this->A['teacher']->id, 'school_id' => $this->B['school'], 'status' => 1,
            ]);
            $this->assertEquals($this->A['school'], DB::table('clubs')->where('club_name', "Made by {$portal}")->value('school_id'));

            $this->actingAs($this->A[$portal])->post(route("{$portal}.club.store"), ['club_name' => "Foreign advisor {$portal}", 'advisor_id' => $this->B['teacher']->id]);
            $this->assertNull(DB::table('clubs')->where('club_name', "Foreign advisor {$portal}")->first(), 'club with a School B advisor');
        }
    }

    // ── Members ──────────────────────────────────────────────────────────────

    public function test_a_school_cannot_view_or_change_another_schools_club_members(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $actor = $this->A[$portal];
            $member = $this->B['member'];
            $before = $this->row('club_members', $member);

            $this->actingAs($actor)->get(route("{$portal}.club.members", $this->B['club']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.add_member", $this->B['club']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.members.search", $this->B['club']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.member.approve", $member))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.member.disable", $member))->assertNotFound();
            $this->actingAs($actor)->post(route("{$portal}.club.member.reject", $member))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.member.delete", $member))->assertNotFound();
            $this->unchanged('club_members', $member, $before, "{$portal} member");

            // Same school still works.
            $this->actingAs($actor)->get(route("{$portal}.club.members", $this->A['club']))->assertOk()->assertSee('PupilA Zq');
            $this->actingAs($actor)->get(route("{$portal}.club.member.approve", $this->A['member']));
            $this->assertEquals(1, DB::table('club_members')->where('id', $this->A['member'])->value('status'));
        }
    }

    public function test_a_club_only_accepts_members_from_its_own_school(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $actor = $this->A[$portal];

            // School B student into a School A club: refused even though the user id exists.
            $this->actingAs($actor)->post(route("{$portal}.club.member.store"), ['club_id' => $this->A['club'], 'student_id' => $this->B['student']->id]);
            $this->assertSame(0, DB::table('club_members')->where('club_id', $this->A['club'])->where('student_id', $this->B['student']->id)->count(), "{$portal}: School B student added");

            // School A student into a School B club: refused.
            $this->actingAs($actor)->post(route("{$portal}.club.member.store"), ['club_id' => $this->B['club'], 'student_id' => $this->A['student']->id]);
            $this->assertSame(0, DB::table('club_members')->where('club_id', $this->B['club'])->where('student_id', $this->A['student']->id)->count(), "{$portal}: added to School B club");

            // The student picker never offers another school's students.
            $search = $this->actingAs($actor)->get(route("{$portal}.club.students.search", ['q' => 'Zq', 'club_id' => $this->A['club']]));
            $search->assertOk();
            $this->assertStringNotContainsString('PupilB Zq', $search->getContent());
        }

        // Same school still works.
        $newcomer = User::factory()->create(['role_id' => 7, 'school_id' => $this->A['school'], 'name' => 'Newcomer Zq']);
        $this->actingAs($this->A['admin'])->post(route('admin.club.member.store'), ['club_id' => $this->A['club'], 'student_id' => $newcomer->id]);
        $this->assertSame(1, DB::table('club_members')->where('club_id', $this->A['club'])->where('student_id', $newcomer->id)->count());
    }

    public function test_admin_member_search_returns_only_own_school_students(): void
    {
        // Pre-RBAC cleanup G: this endpoint filtered on a users.role column that does not exist
        // (every call was a SQL error); students are role_id 7 in the current role architecture.
        $response = $this->actingAs($this->A['admin'])->get(route('admin.club.members.search', ['club' => $this->A['club'], 'q' => 'Zq']));

        $response->assertOk();
        $texts = collect($response->json())->pluck('text')->implode(' | ');
        $this->assertStringContainsString('PupilA Zq', $texts, 'search still finds own-school students');
        $this->assertStringNotContainsString('PupilB Zq', $texts, 'School B student exposed');
        $this->assertStringNotContainsString('TeacherA Zq', $texts, 'non-student account returned');
        $this->assertStringNotContainsString('@', $response->getContent(), 'emails exposed');

        $this->actingAs($this->A['admin'])->get(route('admin.club.members.search', ['club' => $this->B['club'], 'q' => 'Zq']))->assertNotFound();
    }

    // ── Notices ──────────────────────────────────────────────────────────────

    public function test_a_school_cannot_view_or_change_another_schools_club_notices(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $actor = $this->A[$portal];
            $notice = $this->B['notice'];
            $before = $this->row('club_notices', $notice);

            $this->actingAs($actor)->get(route("{$portal}.club.notice", $this->B['club']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.notice.create", $this->B['club']))->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.notice.edit", $notice))->assertNotFound();
            $this->actingAs($actor)->post(route("{$portal}.club.notice.update", $notice), [
                'title' => 'HIJACKED', 'description' => 'x', 'notice_date' => '2026-09-02', 'status' => 1,
                'image' => UploadedFile::fake()->image('new.png'),
            ])->assertNotFound();
            $this->actingAs($actor)->get(route("{$portal}.club.notice.delete", $notice))->assertNotFound();
            $this->unchanged('club_notices', $notice, $before, "{$portal} notice");
            $this->assertFileExists($this->publicDir . '/assets/uploads/club/imgB.png', 'School B notice image removed');

            $this->actingAs($actor)->post(route("{$portal}.club.notice.store"), ['club_id' => $this->B['club'], 'title' => "Planted {$portal}", 'description' => 'x', 'notice_date' => '2026-09-02', 'status' => 1]);
            $this->assertSame(0, DB::table('club_notices')->where('title', "Planted {$portal}")->count(), "{$portal}: notice planted in School B club");

            // Same school still works.
            $this->actingAs($actor)->get(route("{$portal}.club.notice", $this->A['club']))->assertOk()->assertSee(DB::table('club_notices')->where('id', $this->A['notice'])->value('title'));
            $this->actingAs($actor)->post(route("{$portal}.club.notice.store"), ['club_id' => $this->A['club'], 'title' => "Own {$portal}", 'description' => 'x', 'notice_date' => '2026-09-02', 'status' => 1]);
            $this->assertSame(1, DB::table('club_notices')->where('title', "Own {$portal}")->where('club_id', $this->A['club'])->count());
            $this->actingAs($actor)->post(route("{$portal}.club.notice.update", $this->A['notice']), ['title' => "Updated {$portal}", 'description' => 'x', 'notice_date' => '2026-09-02', 'status' => 1]);
            $this->assertSame("Updated {$portal}", DB::table('club_notices')->where('id', $this->A['notice'])->value('title'));
        }
    }

    // ── Students ─────────────────────────────────────────────────────────────

    public function test_a_student_cannot_join_leave_or_read_another_schools_club(): void
    {
        $student = $this->A['student'];

        $this->actingAs($student)->get(route('club.join', $this->B['club']))->assertNotFound();
        $this->actingAs($student)->get(route('club.leave', $this->B['club']))->assertNotFound();
        $this->actingAs($student)->get(route('club.removeRequest', $this->B['club']))->assertNotFound();
        $this->actingAs($student)->get(route('student.club.notice', $this->B['club']))->assertNotFound();
        $this->assertSame(0, DB::table('club_members')->where('club_id', $this->B['club'])->where('student_id', $student->id)->count());

        // Same school still works.
        $own = $this->club($this->A, 'Open club');
        $this->actingAs($student)->get(route('club.join', $own));
        $this->assertSame(1, DB::table('club_members')->where('club_id', $own)->where('student_id', $student->id)->count());
        $this->actingAs($student)->get(route('student.club.notice', $this->A['club']))->assertOk()->assertSee('NoticeA Zq');
    }

    // ── Super Admin ──────────────────────────────────────────────────────────

    public function test_super_admin_club_behaviour_is_unchanged(): void
    {
        // There is no Super Admin club workflow (no superadmin club routes); Super Admin is
        // still turned away from the school-portal club routes exactly as before Phase 2H.
        $this->assertEmpty(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($n) => str_starts_with($n, 'superadmin.') && str_contains($n, 'club'))->all());

        $superAdmin = User::factory()->create(['role_id' => 1, 'school_id' => null]);
        $response = $this->actingAs($superAdmin)->get(route('admin.club.index'));
        $this->assertTrue($response->isRedirect(), 'Super Admin redirected away from school club admin');
        $this->assertStringNotContainsString('ClubA Zq', $response->getContent());
    }
}
