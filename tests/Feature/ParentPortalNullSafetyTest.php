<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Stability repair H1 — the Parent portal must work for a child who has no
 * enrollment / academic record yet (new or historical students).
 *
 * CommonController::get_student_academic_info() returns an Enrollment model for
 * an enrolled student but a plain object otherwise; the Parent pages used to
 * call ->toArray() / ['key'] on it and crashed with HTTP 500.
 */
class ParentPortalNullSafetyTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;
    private User $parent;
    private User $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
        $this->parent = User::factory()->create(['role_id' => 6, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->child = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'parent_id' => $this->parent->id, 'account_status' => 'active', 'name' => 'Unenrolled Child']);
    }

    /** The Parent sidebar pages that list the parent's children. */
    public static function childPages(): array
    {
        return [
            'routine' => ['parent.routine'],
            'attendance' => ['parent.list_of_attendence'],
            'marks' => ['parent.marks'],
            'feedback filter' => ['parent.feedback.filter'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('childPages')]
    public function test_pages_render_for_a_child_without_enrollment(string $route): void
    {
        $this->assertFalse(DB::table('enrollment')->where('user_id', $this->child->id)->exists());

        $this->actingAs($this->parent)->get(route($route))->assertOk()->assertSee('Unenrolled Child');
    }

    public function test_attendance_filter_for_a_child_without_enrollment_shows_an_empty_month(): void
    {
        $this->actingAs($this->parent)
            ->get(route('parent.list_of_attendence', ['month' => 'Jan', 'year' => '2026', 'student_id' => $this->child->id]))
            ->assertOk();
    }

    public function test_attendance_filter_uses_the_childs_actual_section(): void
    {
        $class = (int) DB::table('classes')->insertGetId(['name' => 'Attendance Class', 'school_id' => $this->school]);
        $section = (int) DB::table('sections')->insertGetId(['name' => 'Section B', 'class_id' => $class]);
        DB::table('enrollment')->insert(['user_id' => $this->child->id, 'class_id' => $class, 'section_id' => $section, 'school_id' => $this->school, 'session_id' => 1]);
        DB::table('daily_attendances')->insert(['class_id' => $class, 'section_id' => $section, 'student_id' => $this->child->id,
            'status' => 1, 'session_id' => 1, 'school_id' => $this->school, 'timestamp' => strtotime('2026-01-05'), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->parent)
            ->get(route('parent.list_of_attendence', ['month' => 'Jan', 'year' => '2026', 'student_id' => $this->child->id]))
            ->assertOk()->assertSee('class="present"', false);
    }

    public function test_pages_still_work_for_an_enrolled_child(): void
    {
        $class = (int) DB::table('classes')->insertGetId(['name' => 'Class One', 'school_id' => $this->school]);
        DB::table('enrollment')->insert(['user_id' => $this->child->id, 'class_id' => $class, 'section_id' => 0, 'school_id' => $this->school, 'session_id' => 1]);

        foreach (self::childPages() as [$route]) {
            $this->actingAs($this->parent)->get(route($route))->assertOk()->assertSee('Unenrolled Child');
        }
    }

    public function test_teacher_list_renders_teachers_without_a_department(): void
    {
        $withDepartment = $this->makeDepartment($this->school, 'Sciences');
        User::factory()->create(['role_id' => 3, 'school_id' => $this->school, 'name' => 'No Department Teacher', 'department_id' => null]);
        User::factory()->create(['role_id' => 3, 'school_id' => $this->school, 'name' => 'Deleted Department Teacher', 'department_id' => 99999]);
        User::factory()->create(['role_id' => 3, 'school_id' => $this->school, 'name' => 'Science Teacher', 'department_id' => $withDepartment]);

        $this->actingAs($this->parent)->get(route('parent.teacherlist'))->assertOk()
            ->assertSee('No Department Teacher')->assertSee('Deleted Department Teacher')->assertSee('Science Teacher')->assertSee('Sciences');
    }

    public function test_a_parent_without_children_sees_the_pages(): void
    {
        $lonely = User::factory()->create(['role_id' => 6, 'school_id' => $this->school, 'account_status' => 'active']);

        foreach (self::childPages() as [$route]) {
            $this->actingAs($lonely)->get(route($route))->assertOk();
        }
    }
}
