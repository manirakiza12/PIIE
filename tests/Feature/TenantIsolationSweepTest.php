<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Security Phase 2G — final multi-tenant isolation sweep.
 *
 * Every record reached by an id in these modules must belong to the caller's
 * school: a School A user who swaps in a School B id gets 404 (or, for a
 * scoped bulk update, nothing changes) and never sees School B's data. Each
 * module test also checks the legitimate same-school operation still works
 * wherever the sqlite fixture can exercise it.
 *
 * Which staff roles may use these modules is deliberately unchanged (RBAC).
 * Clubs and the website CMS had no ownership column in Phase 2G; they gained
 * one in Phase 2H and are covered by ClubTenantOwnershipTest and
 * WebsiteCmsTenantOwnershipTest.
 */
class TenantIsolationSweepTest extends TestCase
{
    use StaffModuleTestHelper;

    /** Columns of the live tables (n = numeric, d = date/time, s = string), so fixtures match production. */
    private const LIVE_COLUMNS = [
        'noticeboard' => ['id' => 'n', 'notice_title' => 's', 'notice' => 's', 'start_date' => 's', 'start_time' => 's', 'end_date' => 's', 'end_time' => 's', 'status' => 'n', 'show_on_website' => 'n', 'image' => 's', 'school_id' => 'n', 'session_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'books' => ['id' => 'n', 'name' => 's', 'author' => 's', 'copies' => 'n', 'school_id' => 'n', 'session_id' => 'n', 'timestamp' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'book_issues' => ['id' => 'n', 'book_id' => 'n', 'class_id' => 'n', 'student_id' => 'n', 'issue_date' => 's', 'status' => 'n', 'school_id' => 'n', 'session_id' => 'n', 'timestamp' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'hostels' => ['id' => 'n', 'school_id' => 'n', 'name' => 's', 'type' => 's', 'address' => 's', 'warden_id' => 'n', 'fee' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'hostel_rooms' => ['id' => 'n', 'school_id' => 'n', 'hostel_id' => 'n', 'room_no' => 's', 'capacity' => 'n', 'occupied' => 'n', 'seat_fee' => 'n', 'description' => 's', 'status' => 's', 'created_at' => 'd', 'updated_at' => 'd'],
        'hostel_room_allocations' => ['id' => 'n', 'school_id' => 'n', 'student_id' => 'n', 'room_id' => 'n', 'allocated_on' => 's', 'vacated_on' => 's', 'status' => 's', 'created_at' => 'd', 'updated_at' => 'd'],
        'hostel_applications' => ['id' => 'n', 'school_id' => 'n', 'student_id' => 'n', 'hostel_id' => 'n', 'room_id' => 'n', 'status' => 's', 'note' => 's', 'accepted_at' => 'd', 'created_at' => 'd', 'updated_at' => 'd'],
        'expenses' => ['id' => 'n', 'expense_category_id' => 'n', 'date' => 'n', 'amount' => 's', 'school_id' => 'n', 'session_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'expense_categories' => ['id' => 'n', 'name' => 's', 'school_id' => 'n', 'session_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'admit_cards' => ['id' => 'n', 'template' => 's', 'heading' => 's', 'title' => 's', 'school_id' => 'n', 'exam_center' => 's', 'footer_text' => 's', 'left_logo' => 's', 'right_logo' => 's', 'sign' => 's', 'background_image' => 's', 'created_at' => 'd', 'updated_at' => 'd'],
        'syllabuses' => ['id' => 'n', 'title' => 's', 'class_id' => 'n', 'section_id' => 'n', 'subject_id' => 'n', 'file' => 's', 'school_id' => 'n', 'session_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'departments' => ['id' => 'n', 'name' => 's', 'school_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'class_rooms' => ['id' => 'n', 'name' => 's', 'school_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'sessions' => ['id' => 'n', 'session_title' => 's', 'status' => 'n', 'created_at' => 'd', 'updated_at' => 'd', 'school_id' => 'n'],
        'feedback' => ['id' => 'n', 'title' => 's', 'feedback_text' => 's', 'student_id' => 'n', 'admin_id' => 'n', 'parent_id' => 'n', 'class_id' => 'n', 'section_id' => 'n', 'school_id' => 'n', 'session_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'appraisals' => ['id' => 'n', 'school_id' => 'n', 'class_id' => 'n', 'teacher_id' => 's', 'ans_type' => 's', 'title' => 's', 'question' => 's', 'status' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'routines' => ['id' => 'n', 'class_id' => 'n', 'section_id' => 'n', 'subject_id' => 'n', 'programme_id' => 'n', 'starting_hour' => 'n', 'ending_hour' => 'n', 'starting_minute' => 'n', 'ending_minute' => 'n', 'day' => 's', 'teacher_id' => 'n', 'room_id' => 'n', 'session_id' => 'n', 'school_id' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        'frontend_events' => ['id' => 'n', 'title' => 's', 'timestamp' => 'n', 'status' => 'n', 'school_id' => 'n', 'session_id' => 'n', 'created_by' => 'n', 'created_at' => 'd', 'updated_at' => 'd'],
        // `course_offering_id` added to match production, where the Course
        // Offering Assignments migration put it. Every row this sweep creates is
        // NULL for it - these are the legacy K12 rows - which is what makes them
        // visible to `->k12()` and keeps the sweep testing the legacy action.
        'assignments' => ['id' => 'n', 'school_id' => 'n', 'title' => 's', 'subject_id' => 'n', 'class_id' => 'n', 'teacher_id' => 'n', 'instructions' => 's', 'due_date' => 'd', 'max_marks' => 'n', 'submission_type' => 's', 'is_published' => 'n', 'course_offering_id' => 'z', 'created_at' => 'd', 'updated_at' => 'd'],
        'assignment_submissions' => ['id' => 'n', 'assignment_id' => 'n', 'student_id' => 'n', 'submission' => 's', 'file_path' => 's', 'link' => 's', 'submitted_at' => 'd', 'marks_awarded' => 'n', 'feedback' => 's', 'status' => 's', 'course_offering_id' => 'z', 'created_at' => 'd', 'updated_at' => 'd'],
    ];

    private const ROLE_BY_PREFIX = ['admin' => 2, 'accountant' => 4, 'librarian' => 5, 'teacher' => 3, 'warden' => 10, 'parent' => 6, 'student' => 7];

    private array $A;
    private array $B;
    private int $seq = 0;
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Mail::fake();
        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2g-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        $this->app->instance('path.public', $this->publicDir);
        foreach (array_keys(self::LIVE_COLUMNS) as $table) {
            $this->ensureTable($table);
        }
        $this->A = $this->world('A');
        $this->B = $this->world('B');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function ensureTable(string $table): void
    {
        $columns = self::LIVE_COLUMNS[$table];
        if (!Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                foreach (array_keys($columns) as $name) {
                    $name === 'id' ? $t->id() : $t->text($name)->nullable();
                }
            });
            return;
        }
        $existing = Schema::getColumnListing($table);
        foreach (array_keys($columns) as $name) {
            if (!in_array($name, $existing, true)) {
                Schema::table($table, fn (Blueprint $t) => $t->text($name)->nullable());
            }
        }
    }

    private function world(string $tag): array
    {
        $w = ['tag' => $tag, 'school' => $this->makeSchool(['title' => "School {$tag}", 'status' => 1])];
        $w['class'] = $this->makeClass($w['school'], ['name' => "Class{$tag}"]);
        $w['section'] = DB::table('sections')->insertGetId(['name' => "Sec{$tag}", 'class_id' => $w['class'], 'created_at' => now(), 'updated_at' => now()]);
        $w['student'] = User::factory()->create(['role_id' => 7, 'school_id' => $w['school']])->id;
        $w['teacher'] = User::factory()->create(['role_id' => 3, 'school_id' => $w['school']])->id;
        $w['hostel'] = $this->row('hostels', $w);
        $w['book'] = $this->row('books', $w);
        $w['expense_category'] = $this->row('expense_categories', $w);
        $w['room'] = $this->row('hostel_rooms', $w);
        $w['assignment'] = $this->row('assignments', $w);

        return $w;
    }

    /** Inserts a fully-populated row owned by $w's school; every string column carries a unique marker. */
    private function row(string $table, array $w): int
    {
        $fk = ['class_id' => 'class', 'section_id' => 'section', 'student_id' => 'student', 'teacher_id' => 'teacher', 'hostel_id' => 'hostel',
               'room_id' => 'room', 'book_id' => 'book', 'expense_category_id' => 'expense_category', 'assignment_id' => 'assignment',
               'user_id' => 'student', 'warden_id' => 'teacher', 'admin_id' => 'teacher', 'parent_id' => 'student'];
        $marker = 'MARK' . $w['tag'] . (++$this->seq) . 'X';
        $row = [];
        foreach (self::LIVE_COLUMNS[$table] as $name => $type) {
            if ($name === 'id') continue;
            if ($name === 'school_id') { $row[$name] = $w['school']; continue; }
            if (isset($fk[$name], $w[$fk[$name]])) { $row[$name] = $w[$fk[$name]]; continue; }
            $row[$name] = match ($type) {
                'n' => $name === 'status' ? 0 : 1,
                'd' => now()->toDateTimeString(),
                // Always NULL. For columns meaningful only in the Course Offering
                // world, so the rows this sweep creates stay LEGACY rows and keep
                // exercising the legacy code paths. 'n' would fill them with 1 and
                // silently reclassify them, which the "same-school operation still
                // works" assertion is there to notice.
                'z' => null,
                default => $name === 'status' ? 'pending' : "{$marker}{$name}",
            };
        }

        return (int) DB::table($table)->insertGetId($row);
    }

    private function markerOf(string $table, int $id): string
    {
        foreach ((array) DB::table($table)->where('id', $id)->first() as $value) {
            if (is_string($value) && preg_match('/^(MARK[AB]\d+X)/', $value, $m)) return $m[1];
        }
        return '';
    }

    /** Calls $routeName with a record id as a user of the route's role from $actorWorld; returns [status, output]. */
    private function hit(string $routeName, int $id, array $actorWorld, array $payload = []): array
    {
        $role = self::ROLE_BY_PREFIX[explode('.', $routeName)[0]];
        $actor = User::factory()->create(['role_id' => $role, 'school_id' => $actorWorld['school'], 'account_status' => 'active']);
        $route = Route::getRoutes()->getByName($routeName);
        $url = route($routeName, [($route->parameterNames()[0] ?? 'id') => $id]);
        $body = array_map(fn ($v) => is_string($v) && str_starts_with($v, '@') ? $actorWorld[substr($v, 1)] : $v, $payload);

        ob_start();
        $response = in_array('POST', $route->methods(), true) ? $this->actingAs($actor)->post($url, $body) : $this->actingAs($actor)->get($url);
        $output = ob_get_clean() . $response->getContent();
        auth()->logout();

        return [$response->getStatusCode(), $output];
    }

    /** Cross-school read: School B's record is not returned (404). Same-school control: 200, optionally showing the data. */
    private function assertReadIsolated(string $routeName, string $table, bool $sameSchoolShowsData = true): void
    {
        $foreign = $this->row($table, $this->B);
        [$status, $output] = $this->hit($routeName, $foreign, $this->A);
        $this->assertStringNotContainsString($this->markerOf($table, $foreign), $output, "{$routeName}: School B data returned");
        $this->assertSame(404, $status, "{$routeName}: cross-school status");

        $own = $this->row($table, $this->A);
        [$status, $output] = $this->hit($routeName, $own, $this->A);
        $this->assertSame(200, $status, "{$routeName}: same-school status");
        if ($sameSchoolShowsData) {
            $this->assertStringContainsString($this->markerOf($table, $own), $output, "{$routeName}: same-school data");
        }
    }

    /** Cross-school mutation: School B's row is untouched. Same-school control: optional (fixture permitting). */
    private function assertMutationIsolated(string $routeName, string $table, array $payload = [], bool $sameSchoolMutates = true): void
    {
        $foreign = $this->row($table, $this->B);
        $before = (array) DB::table($table)->where('id', $foreign)->first();
        $this->hit($routeName, $foreign, $this->A, $payload);
        $after = DB::table($table)->where('id', $foreign)->first();
        $this->assertNotNull($after, "{$routeName}: School B row deleted");
        $this->assertEquals(array_diff_key($before, ['updated_at' => 1]), array_diff_key((array) $after, ['updated_at' => 1]), "{$routeName}: School B row changed");

        if ($sameSchoolMutates) {
            $own = $this->row($table, $this->A);
            $ownBefore = (array) DB::table($table)->where('id', $own)->first();
            $this->hit($routeName, $own, $this->A, $payload);
            $ownAfter = DB::table($table)->where('id', $own)->first();
            $this->assertTrue($ownAfter === null || array_diff_key(array_diff_assoc((array) $ownAfter, $ownBefore), ['updated_at' => 1]) !== [], "{$routeName}: same-school operation still works");
        }
    }

    // ── Noticeboard ──────────────────────────────────────────────────────────

    public function test_noticeboard_is_tenant_isolated(): void
    {
        foreach (['admin', 'accountant', 'librarian', 'teacher', 'warden', 'parent', 'student'] as $portal) {
            $this->assertReadIsolated("{$portal}.edit.noticeboard", 'noticeboard');
        }
        $this->assertMutationIsolated('admin.noticeboard.update', 'noticeboard', ['notice_title' => 'CHANGED', 'notice' => 'x', 'start_date' => '09/01/2026',
            'start_time' => '10:00', 'end_date' => '09/30/2026', 'end_time' => '10:00', 'status' => 1, 'show_on_website' => 0]);
        $this->assertMutationIsolated('admin.noticeboard.delete', 'noticeboard');
    }

    // ── Library ──────────────────────────────────────────────────────────────

    public function test_library_books_and_issues_are_tenant_isolated(): void
    {
        foreach (['admin', 'librarian'] as $portal) {
            $this->assertReadIsolated("{$portal}.edit.book", 'books');
            // librarian.book.update does not complete in the sqlite fixture even for its own school (same as before Phase 2G).
            $this->assertMutationIsolated("{$portal}.book.update", 'books', ['name' => 'CHANGED', 'author' => 'x', 'copies' => 3], $portal === 'admin');
            $this->assertMutationIsolated("{$portal}.book.delete", 'books');
            $this->assertMutationIsolated("{$portal}.book_issue.update", 'book_issues', ['book_id' => '@book', 'class_id' => '@class', 'student_id' => '@student', 'issue_date' => '09/01/2026']);
            $this->assertMutationIsolated("{$portal}.book_issue.return", 'book_issues');
            $this->assertMutationIsolated("{$portal}.book_issue.delete", 'book_issues');
        }
    }

    // ── Hostel ───────────────────────────────────────────────────────────────

    public function test_hostels_rooms_allocations_and_applications_are_tenant_isolated(): void
    {
        $this->assertReadIsolated('admin.hostel.edit_hostel', 'hostels');
        $this->assertMutationIsolated('admin.hostel.update_hostel', 'hostels', ['name' => 'CHANGED', 'type' => 'boys', 'address' => 'x', 'fee' => 10]);
        $this->assertMutationIsolated('admin.hostel.delete_hostel', 'hostels');
        $this->assertReadIsolated('admin.hostel.edit_room', 'hostel_rooms');
        $this->assertMutationIsolated('admin.hostel.update_room', 'hostel_rooms', ['hostel_id' => '@hostel', 'room_no' => 'CHANGED', 'capacity' => 4, 'seat_fee' => 10, 'status' => 1]);
        $this->assertMutationIsolated('admin.hostel.delete_room', 'hostel_rooms');
        foreach (['admin', 'warden'] as $portal) {
            $this->assertReadIsolated("{$portal}.hostel.edit_allocation", 'hostel_room_allocations', false);
            $this->assertMutationIsolated("{$portal}.hostel.update_allocation", 'hostel_room_allocations', ['student_id' => '@student', 'room_id' => '@room', 'allocated_on' => '2026-09-01', 'status' => 'vacated']);
            $this->assertMutationIsolated("{$portal}.hostel.delete_allocation", 'hostel_room_allocations');
            // Approval does not complete in the sqlite fixture even for its own school (same as before Phase 2G).
            $this->assertMutationIsolated("{$portal}.hostel.applications.approve", 'hostel_applications', [], false);
            $this->assertMutationIsolated("{$portal}.hostel.applications.reject", 'hostel_applications');
        }
    }

    // ── Expenses ─────────────────────────────────────────────────────────────

    public function test_expenses_and_categories_are_tenant_isolated(): void
    {
        foreach (['admin', 'accountant'] as $portal) {
            $this->assertReadIsolated("{$portal}.edit.expenses", 'expenses');
            $this->assertMutationIsolated("{$portal}.expenses.update", 'expenses', ['expense_category_id' => '@expense_category', 'date' => '09/01/2026', 'amount' => 999]);
            $this->assertMutationIsolated("{$portal}.expense.delete", 'expenses');
            $this->assertReadIsolated("{$portal}.edit.expense_category", 'expense_categories');
            // accountant.expense_category.update does not complete in the sqlite fixture even for its own school.
            $this->assertMutationIsolated("{$portal}.expense_category.update", 'expense_categories', ['name' => 'CHANGED'], $portal === 'admin');
            $this->assertMutationIsolated("{$portal}.expense.category_delete", 'expense_categories');
        }
    }

    // ── Admit cards / syllabus ───────────────────────────────────────────────

    public function test_admit_cards_and_syllabus_are_tenant_isolated(): void
    {
        $this->assertReadIsolated('admin.examination.admit_card_edit', 'admit_cards');
        $this->assertMutationIsolated('admin.examination.admit_card_update', 'admit_cards', ['template' => '1', 'heading' => 'CHANGED', 'title' => 'x', 'exam_center' => 'x', 'footer_text' => 'x']);
        $this->assertMutationIsolated('admin.examination.admit_card_delete', 'admit_cards');
        $this->assertMutationIsolated('admin.syllabus.update', 'syllabuses', ['title' => 'CHANGED', 'class_id' => '@class', 'section_id' => '@section', 'subject_id' => 1]);
        $this->assertMutationIsolated('admin.syllabus.delete', 'syllabuses');
        $this->assertMutationIsolated('teacher.syllabus.delete', 'syllabuses');
    }

    public function test_admit_card_filter_never_renders_another_schools_class(): void
    {
        DB::table('users')->whereIn('id', [$this->A['student'], $this->B['student']])->update(['user_information' => json_encode(['birthday' => strtotime('2010-01-01'), 'gender' => 'Male', 'blood_group' => 'O+', 'phone' => '1', 'address' => 'x', 'photo' => ''])]);
        DB::table('users')->where('id', $this->B['student'])->update(['name' => 'Foreign Pupil Zq']);
        DB::table('enrollment')->insert(['user_id' => $this->B['student'], 'class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'school_id' => $this->B['school'], 'session_id' => 1]);
        DB::table('users')->where('id', $this->A['student'])->update(['name' => 'Own Pupil Zq']);
        DB::table('enrollment')->insert(['user_id' => $this->A['student'], 'class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'school_id' => $this->A['school'], 'session_id' => 1]);
        $session = $this->row('sessions', $this->A);
        $card = $this->row('admit_cards', $this->A);
        $admin = User::factory()->create(['role_id' => 2, 'school_id' => $this->A['school'], 'account_status' => 'active']);

        ob_start();
        $response = $this->actingAs($admin)->get(route('admin.examination.admitCardFilter', ['class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'session_id' => $session, 'admit_card_id' => $card]));
        $output = ob_get_clean() . $response->getContent();
        $this->assertStringNotContainsString('Foreign Pupil Zq', $output, 'School B roster rendered');
        $this->assertStringNotContainsString('ClassB', $output, 'School B class name rendered');
        $this->assertSame(404, $response->getStatusCode());

        $response = $this->actingAs($admin)->get(route('admin.examination.admitCardFilter', ['class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'session_id' => $session, 'admit_card_id' => $card]));
        $response->assertOk();
        $response->assertSee('ClassA');

        $this->actingAs($admin)->get(route('admin.examination.admitCardFilter', ['class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'session_id' => 999999, 'admit_card_id' => $card]))
            ->assertNotFound();
    }

    public function test_attendance_filter_never_renders_another_schools_class(): void
    {
        DB::table('users')->whereIn('id', [$this->A['student'], $this->B['student']])->update(['user_information' => json_encode(['birthday' => strtotime('2010-01-01'), 'gender' => 'Male', 'photo' => ''])]);
        DB::table('users')->where('id', $this->B['student'])->update(['name' => 'Foreign Pupil Zq']);
        DB::table('enrollment')->insert(['user_id' => $this->B['student'], 'class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'school_id' => $this->B['school'], 'session_id' => 1]);
        $admin = User::factory()->create(['role_id' => 2, 'school_id' => $this->A['school'], 'account_status' => 'active']);

        ob_start();
        $response = $this->actingAs($admin)->get(route('admin.daily_attendance.filter', ['class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'month' => 'Sep', 'year' => '2026']));
        $output = ob_get_clean() . $response->getContent();
        $this->assertStringNotContainsString('Foreign Pupil Zq', $output, 'School B roster rendered');
        $this->assertStringNotContainsString('ClassB', $output, 'School B class name rendered');
        // Measured in Phase 2G: 200 with no School B data (the roster loop never matches) — guarded, not changed.

        $response = $this->actingAs($admin)->get(route('admin.daily_attendance.filter', ['class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'month' => 'Sep', 'year' => '2026']));
        $response->assertOk();
        $response->assertSee('ClassA');
    }

    // ── Departments / class rooms / sessions ─────────────────────────────────

    public function test_departments_class_rooms_and_sessions_are_tenant_isolated(): void
    {
        $this->assertReadIsolated('admin.edit.department', 'departments');
        $this->assertMutationIsolated('admin.department.update', 'departments', ['name' => 'CHANGED']);
        $this->assertMutationIsolated('admin.department.delete', 'departments');
        $this->assertReadIsolated('admin.edit.class_room', 'class_rooms');
        $this->assertMutationIsolated('admin.class_room.update', 'class_rooms', ['name' => 'CHANGED']);
        $this->assertMutationIsolated('admin.class_room.delete', 'class_rooms');
        $this->assertReadIsolated('admin.edit.session', 'sessions');
        $this->assertMutationIsolated('admin.session.update', 'sessions', ['session_title' => 'CHANGED']);
        $this->assertMutationIsolated('admin.session.delete', 'sessions');
        $this->assertMutationIsolated('admin.session_manager.active_session', 'sessions');
    }

    public function test_a_foreign_session_never_becomes_the_running_session(): void
    {
        $foreignSession = $this->row('sessions', $this->B);
        $before = DB::table('schools')->where('id', $this->A['school'])->value('running_session');

        $this->hit('admin.session_manager.active_session', $foreignSession, $this->A);

        $this->assertEquals($before, DB::table('schools')->where('id', $this->A['school'])->value('running_session'));
    }

    // ── Feedback / appraisal / routines / events / assignment grading ───────

    public function test_feedback_appraisal_routines_events_and_grading_are_tenant_isolated(): void
    {
        foreach (['admin', 'teacher'] as $portal) {
            $this->assertMutationIsolated("{$portal}.feedback.update_feedback", 'feedback', ['title' => 'CHANGED', 'feedback_text' => 'x', 'student_id' => '@student', 'class_id' => '@class', 'section_id' => '@section']);
            $this->assertMutationIsolated("{$portal}.feedback.delete_feedback", 'feedback');
        }
        $this->assertMutationIsolated('admin.appraisal.appraisalQuestionDelete', 'appraisals');
        // The routine edit view reads "the" active session (unscoped, pre-existing) — give the fixture one.
        DB::table('sessions')->where('id', $this->row('sessions', $this->A))->update(['status' => 1]);
        $this->assertReadIsolated('admin.routine_edit_modal', 'routines', false);
        $this->assertMutationIsolated('admin.routine.update', 'routines', ['class_id' => '@class', 'section_id' => '@section', 'subject_id' => 1, 'day' => 'monday',
            'starting_hour' => 9, 'ending_hour' => 10, 'starting_minute' => 0, 'ending_minute' => 0, 'teacher_id' => '@teacher', 'class_room_id' => 1]);
        $this->assertMutationIsolated('admin.routine.delete', 'routines');
        $this->assertReadIsolated('admin.edit.event', 'frontend_events');
        $this->assertMutationIsolated('admin.event.update', 'frontend_events', ['title' => 'CHANGED', 'timestamp' => '09/01/2026', 'status' => 1]);
        $this->assertMutationIsolated('admin.events.delete', 'frontend_events');
        $this->assertMutationIsolated('admin.assignments.grade', 'assignment_submissions', ['marks_awarded' => 77, 'feedback' => 'CHANGED']);
    }

    public function test_parent_syllabus_list_is_limited_to_own_children(): void
    {
        if (!Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $t) {
                $t->id();
                foreach (['name', 'class_id', 'school_id', 'session_id'] as $c) $t->text($c)->nullable();
                $t->timestamps();
            });
        }
        $foreignParent = User::factory()->create(['role_id' => 6, 'school_id' => $this->B['school']]);
        DB::table('users')->where('id', $this->B['student'])->update(['parent_id' => $foreignParent->id]);
        DB::table('enrollment')->insert(['user_id' => $this->B['student'], 'class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'school_id' => $this->B['school'], 'session_id' => 1]);
        $foreignSyllabus = $this->row('syllabuses', $this->B);
        DB::table('syllabuses')->where('id', $foreignSyllabus)->update(['subject_id' => DB::table('subjects')->insertGetId(['name' => 'SubjB', 'class_id' => $this->B['class'], 'school_id' => $this->B['school']])]);

        $parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->A['school'], 'account_status' => 'active']);
        ob_start();
        $response = $this->actingAs($parent)->get(route('parent.syllabusList_by_student_name', ['user_id' => $this->B['student']]));
        $output = ob_get_clean() . $response->getContent();

        $this->assertStringNotContainsString($this->markerOf('syllabuses', $foreignSyllabus), $output, 'School B syllabus returned');
        $this->assertSame(404, $response->getStatusCode());

        DB::table('users')->where('id', $this->A['student'])->update(['parent_id' => $parent->id]);
        DB::table('enrollment')->insert(['user_id' => $this->A['student'], 'class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'school_id' => $this->A['school'], 'session_id' => 1]);
        $ownSyllabus = $this->row('syllabuses', $this->A);
        DB::table('syllabuses')->where('id', $ownSyllabus)->update(['subject_id' => DB::table('subjects')->insertGetId(['name' => 'SubjA', 'class_id' => $this->A['class'], 'school_id' => $this->A['school']])]);
        $response = $this->actingAs($parent)->get(route('parent.syllabusList_by_student_name', ['user_id' => $this->A['student']]));
        $response->assertOk();
        $response->assertSee($this->markerOf('syllabuses', $ownSyllabus));
    }

    public function test_parent_child_views_reject_a_student_who_is_not_their_child(): void
    {
        if (!Schema::hasTable('subjects')) {
            Schema::create('subjects', function (Blueprint $t) {
                $t->id();
                foreach (['name', 'class_id', 'school_id', 'session_id'] as $c) $t->text($c)->nullable();
                $t->timestamps();
            });
        }
        DB::table('users')->where('id', $this->B['student'])->update(['name' => 'Foreign Pupil Zq']);
        DB::table('enrollment')->insert(['user_id' => $this->B['student'], 'class_id' => $this->B['class'], 'section_id' => $this->B['section'], 'school_id' => $this->B['school'], 'session_id' => 1]);
        DB::table('subjects')->insert(['name' => 'Foreign Subject Zq', 'class_id' => $this->B['class'], 'school_id' => $this->B['school']]);
        $parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->A['school'], 'account_status' => 'active']);

        $routes = [
            'parent.subjectList_by_student_name' => ['user_id' => $this->B['student']],
            'parent.syllabusList_by_student_name' => ['user_id' => $this->B['student']],
            'parent.routine.routine_list' => ['student_id' => $this->B['student']],
            'parent.daily_attendance.filter' => ['student_id' => $this->B['student'], 'month' => 'Sep', 'year' => '2026'],
            'parent.marks_list' => ['student_id' => $this->B['student']],
        ];
        foreach ($routes as $name => $query) {
            ob_start();
            $response = $this->actingAs($parent)->get(route($name, $query));
            $output = ob_get_clean() . $response->getContent();
            $this->assertStringNotContainsString('Foreign Pupil Zq', $output, "{$name}: School B student returned");
            $this->assertStringNotContainsString('Foreign Subject Zq', $output, "{$name}: School B subjects returned");
            $this->assertSame(404, $response->getStatusCode(), "{$name}: cross-school status");
        }

        // Same-school control: the parent's own child still resolves.
        DB::table('users')->where('id', $this->A['student'])->update(['parent_id' => $parent->id]);
        DB::table('enrollment')->insert(['user_id' => $this->A['student'], 'class_id' => $this->A['class'], 'section_id' => $this->A['section'], 'school_id' => $this->A['school'], 'session_id' => 1]);
        DB::table('subjects')->insert(['name' => 'Own Subject Zq', 'class_id' => $this->A['class'], 'school_id' => $this->A['school']]);
        $this->actingAs($parent)->get(route('parent.subjectList_by_student_name', ['user_id' => $this->A['student']]))->assertOk()->assertSee('Own Subject Zq');
    }

    public function test_a_student_cannot_apply_for_another_schools_hostel_room(): void
    {
        DB::table('hostel_rooms')->whereIn('id', [$this->A['room'], $this->B['room']])->update(['occupied' => 0, 'capacity' => 5]);
        $student = User::find($this->A['student']);
        $student->forceFill(['account_status' => 'active'])->save();

        $this->actingAs($student)->post(route('student.hostel.applications.store'), ['hostel_id' => $this->B['hostel'], 'room_id' => $this->B['room']]);
        $this->assertSame(0, DB::table('hostel_applications')->where('room_id', $this->B['room'])->count(), 'application filed against School B room');

        $this->actingAs($student)->post(route('student.hostel.applications.store'), ['hostel_id' => $this->A['hostel'], 'room_id' => $this->A['room']]);
        $this->assertSame(1, DB::table('hostel_applications')->where('room_id', $this->A['room'])->where('student_id', $student->id)->count(), 'same-school application still works');
    }

    // ── Payment settings (currency + gateway keys) ───────────────────────────

    private function paymentMethodRow(array $w, string $name): int
    {
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $t) {
                $t->id();
                foreach (['name', 'image', 'status', 'mode', 'payment_keys', 'school_id'] as $c) $t->text($c)->nullable();
                $t->timestamps();
            });
        }

        return (int) DB::table('payment_methods')->insertGetId(['name' => $name, 'status' => 1, 'mode' => 'live',
            'payment_keys' => json_encode(['secret_live_key' => 'KEY-OF-' . $w['tag']]), 'school_id' => $w['school']]);
    }

    public function test_a_school_cannot_overwrite_or_take_over_another_schools_payment_gateway(): void
    {
        $keys = ['status' => 1, 'mode' => 'live', 'test_key' => 'x', 'test_secret_key' => 'x', 'public_live_key' => 'ATTACKER', 'secret_live_key' => 'ATTACKER'];
        $foreign = $this->paymentMethodRow($this->B, 'stripe');
        $before = (array) DB::table('payment_methods')->where('id', $foreign)->first();

        $this->hit('admin.settings.payment_post', 0, $this->A, ['method' => 'stripe', 'update_id' => $foreign] + $keys);

        $this->assertEquals($before, (array) DB::table('payment_methods')->where('id', $foreign)->first(), 'School B gateway row changed / re-owned');

        $own = $this->paymentMethodRow($this->A, 'stripe');
        $this->hit('admin.settings.payment_post', 0, $this->A, ['method' => 'stripe', 'update_id' => $own] + $keys);
        $this->assertStringContainsString('ATTACKER', DB::table('payment_methods')->where('id', $own)->value('payment_keys'), 'same-school gateway update still works');
    }

    public function test_a_school_cannot_change_another_schools_currency(): void
    {
        foreach (['school_currency', 'currency_position'] as $c) {
            if (!Schema::hasColumn('schools', $c)) Schema::table('schools', fn (Blueprint $t) => $t->text($c)->nullable());
        }
        DB::table('schools')->whereIn('id', [$this->A['school'], $this->B['school']])->update(['school_currency' => 'UGX', 'currency_position' => 'left']);
        $payload = ['method' => 'currency', 'school_currency' => 'EUR', 'currency_position' => 'right'];

        $this->hit('admin.settings.payment_post', 0, $this->A, ['update_id' => $this->B['school']] + $payload);
        $this->assertSame('UGX', DB::table('schools')->where('id', $this->B['school'])->value('school_currency'), 'School B currency changed');

        $this->hit('admin.settings.payment_post', 0, $this->A, ['update_id' => $this->A['school']] + $payload);
        $this->assertSame('EUR', DB::table('schools')->where('id', $this->A['school'])->value('school_currency'), 'same-school currency update still works');
    }
}
