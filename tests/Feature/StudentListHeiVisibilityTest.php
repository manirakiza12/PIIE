<?php

namespace Tests\Feature;

use App\Http\Controllers\CommonController;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers two bugs found while investigating "an enrolled student doesn't
 * show up in Student Management": AdminController::studentList() (and its
 * CSV/Excel export siblings) inner-joined on `enrollment`, which only a
 * class-based (K-12) student ever has a row in — every programme-based
 * (HEI) student, including everyone who comes through the Admissions
 * "enrolled" flow, was silently excluded from the list entirely.
 * CommonController::get_student_academic_info() (called once per row by
 * that list, and separately by the admin/teacher/parent feedback screens)
 * went further and fatal-errored on a null Enrollment lookup for the same
 * students.
 */
class StudentListHeiVisibilityTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function makeHeiStudent(int $schoolId, array $overrides = []): \App\Models\User
    {
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'BSc Computer Science']);

        $student = \App\Models\User::factory()->create(array_merge([
            'role_id' => 7,
            'school_id' => $schoolId,
            'account_status' => 'active',
            'name' => 'HEI Student',
        ], $overrides));

        DB::table('student_profiles')->insert([
            'user_id' => $student->id,
            'school_id' => $schoolId,
            'first_name' => 'HEI',
            'last_name' => 'Student',
            'programme_id' => $programmeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $student;
    }

    private function makeK12Student(int $schoolId, int $classId, string $name = 'K12 Student'): \App\Models\User
    {
        $student = \App\Models\User::factory()->create([
            'role_id' => 7,
            'school_id' => $schoolId,
            'account_status' => 'active',
            'name' => $name,
        ]);

        DB::table('enrollment')->insert([
            'user_id' => $student->id,
            'class_id' => $classId,
            'school_id' => $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $student;
    }

    public function test_an_hei_student_with_no_enrollment_row_appears_in_the_student_list(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $student = $this->makeHeiStudent($schoolId, ['name' => 'Robert Ssenono']);

        $response = $this->actingAs($admin)->get(route('admin.student', ['search' => 'Robert Ssenono']));

        $response->assertStatus(200);
        $response->assertSee('Robert Ssenono');
    }

    public function test_student_actions_link_to_the_selected_course_registration_workspace(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $student = $this->makeHeiStudent($schoolId, ['name' => 'Registration Shortcut Student']);

        $this->actingAs($admin)->get(route('admin.student', ['search' => $student->name]))
            ->assertOk()->assertSee('Check eligibility / Register course units')
            ->assertSee(route('admin.course_registrations.index', ['student_id' => $student->id]), false);
    }

    public function test_the_hei_student_shows_their_programme_instead_of_removed(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->makeHeiStudent($schoolId, ['name' => 'Robert Ssenono']);

        $response = $this->actingAs($admin)->get(route('admin.student', ['search' => 'Robert Ssenono']));

        $response->assertStatus(200);
        $response->assertSee('BSc Computer Science');
        $response->assertDontSee('Removed');
    }

    public function test_a_k12_student_still_appears_and_a_class_filter_still_narrows_correctly(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $classId = DB::table('classes')->insertGetId(['name' => 'Class A', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);
        $otherClassId = DB::table('classes')->insertGetId(['name' => 'Class B', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);

        $inClass = $this->makeK12Student($schoolId, $classId, 'In Class Student');
        $inOtherClass = $this->makeK12Student($schoolId, $otherClassId, 'In Other Class Student');
        $heiStudent = $this->makeHeiStudent($schoolId);

        // Unfiltered: everyone shows, K-12 and HEI alike.
        $unfiltered = $this->actingAs($admin)->get(route('admin.student'));
        $unfiltered->assertSee($inClass->name);
        $unfiltered->assertSee($inOtherClass->name);
        $unfiltered->assertSee($heiStudent->name);

        // Filtered to one class: only that class's student, never the HEI
        // student (who has no class at all) or the other class's student.
        $filtered = $this->actingAs($admin)->get(route('admin.student', ['class_id' => $classId]));
        $filtered->assertSee($inClass->name);
        $filtered->assertDontSee($inOtherClass->name);
        $filtered->assertDontSee($heiStudent->name);
    }

    public function test_students_from_another_school_never_leak_into_the_list(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminA = $this->makeAdminUser($schoolA);

        $this->makeHeiStudent($schoolA, ['name' => 'School A Student']);
        $this->makeHeiStudent($schoolB, ['name' => 'School B Student']);

        $response = $this->actingAs($adminA)->get(route('admin.student'));

        $response->assertSee('School A Student');
        $response->assertDontSee('School B Student');
    }

    public function test_csv_export_includes_the_hei_student(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->makeHeiStudent($schoolId, ['name' => 'Robert Ssenono', 'email' => 'robert@example.test']);

        $response = $this->actingAs($admin)->get(route('admin.student.export'));

        $response->assertStatus(200);
        $this->assertStringContainsString('robert@example.test', $response->streamedContent());
    }

    // ── get_student_academic_info() ──────────────────────────────────────

    public function test_get_student_academic_info_does_not_crash_for_a_student_with_no_enrollment(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeHeiStudent($schoolId, ['name' => 'Robert Ssenono']);

        $info = (new CommonController())->get_student_academic_info($student->id);

        $this->assertSame('Robert Ssenono', $info->name);
        $this->assertSame('', $info->class_name);
        $this->assertSame('BSc Computer Science', $info->programme_name);
    }

    public function test_get_student_academic_info_still_returns_class_details_for_a_k12_student(): void
    {
        $schoolId = $this->makeSchool();
        $classId = DB::table('classes')->insertGetId(['name' => 'Class A', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);
        $student = $this->makeK12Student($schoolId, $classId);

        $info = (new CommonController())->get_student_academic_info($student->id);

        $this->assertSame('Class A', $info->class_name);
    }
}
