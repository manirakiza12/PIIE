<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\User;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers AdminController::studentUpdate() assigning a real class to a
 * Programme-track (HEI) student — this used to silently no-op:
 * Enrollment::where('user_id', $id)->update([...]) affects zero rows when
 * the student has no Enrollment row yet (the normal state for a
 * Programme-track student before EnrollmentDefaults::ensureRow() existed),
 * so the class picked on the shared admin/student/edit_student form
 * appeared to save but never actually took effect — the student stayed
 * invisible to that class's teacher regardless of what was picked.
 */
class StudentClassAssignmentTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function updatePayload(int $classId, int $sectionId): array
    {
        return [
            'name' => 'Test Student', 'email' => 'test.student@example.com',
            'gender' => 'male', 'blood_group' => 'O+', 'birthday' => '2000-01-01',
            'phone' => '123456', 'address' => 'Somewhere',
            'class_id' => $classId, 'section_id' => $sectionId,
        ];
    }

    public function test_tertiary_student_edit_has_no_teaching_group_and_saves_without_enrollment(): void
    {
        \Illuminate\Support\Facades\Schema::table('schools', fn ($table) => $table->string('education_level')->nullable());
        $school = $this->makeSchool();
        \Illuminate\Support\Facades\DB::table('schools')->where('id', $school)->update(['education_level' => 'tertiary']);
        $admin = $this->makeAdminUser($school);
        $this->makeClass($school, ['name' => 'MPAM GROUP']);
        $student = User::factory()->create(['school_id' => $school, 'role_id' => 7]);
        $this->actingAs($admin)->get(route('admin.student_edit_modal', $student->id))
            ->assertOk()->assertDontSee('MPAM GROUP')->assertDontSee('name="class_id"', false)
            ->assertDontSee('Students enrolled in this class can access matching class-targeted online exams.')
            ->assertDontSee('name="section_id"', false);
        $payload = $this->updatePayload(0, 0);
        unset($payload['class_id'], $payload['section_id']);
        $payload['email'] = $student->email;
        $this->post(route('admin.student.update', $student->id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Test Student', $student->fresh()->name);
        $this->assertSame(0, Enrollment::where('user_id', $student->id)->count());
    }

    public function test_tertiary_profile_save_ignores_group_fields_and_preserves_existing_assignments(): void
    {
        \Illuminate\Support\Facades\Schema::table('schools', fn ($table) => $table->string('education_level')->nullable());
        $school = $this->makeSchool();
        \Illuminate\Support\Facades\DB::table('schools')->where('id', $school)->update(['education_level' => 'tertiary']);
        $student = User::factory()->create(['school_id' => $school, 'role_id' => 7]);
        $old = $this->makeClass($school, ['name' => 'Old Teaching Group']);
        $new = $this->makeClass($school, ['name' => 'Other Teaching Group']);
        Enrollment::create(['user_id' => $student->id, 'school_id' => $school, 'class_id' => $old,
            'section_id' => 0, 'session_id' => 1, 'department_id' => 0]);
        $before = Enrollment::where('user_id', $student->id)->firstOrFail()->getRawOriginal();
        $payload = $this->updatePayload($new, 99999);
        $payload['email'] = $student->email;
        $this->actingAs($this->makeAdminUser($school))->post(route('admin.student.update', $student->id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, Enrollment::where('user_id', $student->id)->firstOrFail()->getRawOriginal());
    }

    public function test_assigning_a_class_to_a_student_with_no_enrollment_row_creates_one(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $classId = $this->makeClass($schoolId);
        $sectionId = (int) \Illuminate\Support\Facades\DB::table('sections')->insertGetId([
            'name' => 'A', 'class_id' => $classId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = User::create([
            'name' => 'Test Student', 'email' => 'test.student@example.com',
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
        ]);

        $this->assertSame(0, Enrollment::where('user_id', $student->id)->count(), 'Precondition: student starts with no Enrollment row.');

        $response = $this->actingAs($admin)
            ->post(route('admin.student.update', ['id' => $student->id]), $this->updatePayload($classId, $sectionId) + ['update_placement' => 1]);

        $response->assertRedirect();
        $this->assertDatabaseHas('enrollment', [
            'user_id' => $student->id,
            'school_id' => $schoolId,
            'class_id' => $classId,
            'section_id' => $sectionId,
        ]);
    }

    public function test_reassigning_a_class_updates_the_existing_row_instead_of_duplicating_it(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $oldClassId = $this->makeClass($schoolId, ['name' => 'Old Class']);
        $newClassId = $this->makeClass($schoolId, ['name' => 'New Class']);
        $sectionId = (int) \Illuminate\Support\Facades\DB::table('sections')->insertGetId([
            'name' => 'A', 'class_id' => $newClassId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = User::create([
            'name' => 'Test Student', 'email' => 'reassign.student@example.com',
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
        ]);
        \Illuminate\Support\Facades\DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => $oldClassId, 'section_id' => 1,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.student.update', ['id' => $student->id]), array_merge(
            $this->updatePayload($newClassId, $sectionId),
            ['email' => 'reassign.student@example.com', 'update_placement' => 1]
        ));

        $this->assertSame(1, Enrollment::where('user_id', $student->id)->count(), 'Must update in place, not create a second row.');
        $this->assertDatabaseHas('enrollment', [
            'user_id' => $student->id,
            'class_id' => $newClassId,
        ]);
    }

    public function test_ordinary_student_profile_edit_preserves_existing_placement_even_if_old_form_values_are_posted(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $oldClass = $this->makeClass($schoolId, ['name' => 'Current Class']);
        $otherClass = $this->makeClass($schoolId, ['name' => 'Unselected Class']);
        $student = User::create(['name' => 'Existing Student', 'email' => 'existing.profile@example.test',
            'password' => bcrypt('preserve-this-password'), 'code' => 'REG-KEEP-1', 'role_id' => 7, 'school_id' => $schoolId]);
        Enrollment::create(['user_id' => $student->id, 'school_id' => $schoolId, 'class_id' => $oldClass,
            'section_id' => 0, 'session_id' => 1, 'department_id' => 0]);
        $before = Enrollment::where('user_id', $student->id)->firstOrFail()->getRawOriginal();
        $password = $student->password;
        $this->actingAs($admin)->post(route('admin.student.update', $student->id), array_merge(
            $this->updatePayload($otherClass, 0), ['email' => $student->email, 'name' => 'Corrected Name']
        ))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, Enrollment::where('user_id', $student->id)->firstOrFail()->getRawOriginal());
        $this->assertSame($password, $student->fresh()->password);
        $this->assertSame('REG-KEEP-1', $student->fresh()->code);
        $this->assertSame('Corrected Name', $student->fresh()->name);
    }
}
