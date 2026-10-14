<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers admin- and parent-generated ID cards — previously each had their
 * own hand-styled copy of the card (no Programme support, no logo/photo
 * fallback consistency, no QR/verification) that had drifted from the
 * student's own. Both now render through the same shared
 * partials.id_card the student-facing card and PDF use.
 */
class StaffViewedIdCardTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        DB::table('roles')->insert(['role_id' => 7, 'name' => 'Student', 'school_id' => 0]);
    }

    private function makeAdmin(int $schoolId): User
    {
        return User::create([
            'name' => 'School Admin', 'email' => 'idcard.admin.' . uniqid() . '@example.com',
            'password' => bcrypt('secret'), 'role_id' => 2, 'school_id' => $schoolId,
            'account_status' => 'active',
        ]);
    }

    private function makeParentUser(int $schoolId): User
    {
        return User::create([
            'name' => 'Guardian', 'email' => 'idcard.parent.' . uniqid() . '@example.com',
            'password' => bcrypt('secret'), 'role_id' => 6, 'school_id' => $schoolId,
            'account_status' => 'active',
        ]);
    }

    private function makeStudent(int $schoolId, ?int $parentId = null): User
    {
        return User::create([
            'name' => 'Viewed Student', 'email' => 'idcard.student.' . uniqid() . '@example.com',
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7,
            'school_id' => $schoolId, 'parent_id' => $parentId,
        ]);
    }

    public function test_admin_can_view_a_students_id_card_with_programme_and_qr(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdmin($schoolId);
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Diploma in IT']);
        $student = $this->makeStudent($schoolId);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.student.id_card', $student->id));

        $response->assertOk();
        $response->assertSee('Diploma in IT');
        $response->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_parent_can_view_a_child_id_card(): void
    {
        $schoolId = $this->makeSchool();
        $parent = $this->makeParentUser($schoolId);
        $student = $this->makeStudent($schoolId, $parent->id);

        $response = $this->actingAs($parent)->get(route('parent.student.id_card', $student->id));

        $response->assertOk();
        $response->assertSee('Viewed Student');
        $response->assertSee($student->code);
    }

    /** Security Phase 2G: a parent's ID card view is limited to their own children in their own school. */
    public function test_parent_cannot_view_another_schools_student_id_card(): void
    {
        $parent = $this->makeParentUser($this->makeSchool());
        $foreignSchool = $this->makeSchool();
        $foreignStudent = $this->makeStudent($foreignSchool, $this->makeParentUser($foreignSchool)->id);
        $foreignStudent->forceFill(['name' => 'Foreign Pupil Zq'])->save();

        $response = $this->actingAs($parent)->get(route('parent.student.id_card', $foreignStudent->id));

        $response->assertNotFound();
        $response->assertDontSee('Foreign Pupil Zq');
    }

    public function test_parent_cannot_view_a_same_school_student_who_is_not_their_child(): void
    {
        $schoolId = $this->makeSchool();
        $parent = $this->makeParentUser($schoolId);
        $other = $this->makeStudent($schoolId, $this->makeParentUser($schoolId)->id);
        $other->forceFill(['name' => 'Other Family Zq'])->save();

        $response = $this->actingAs($parent)->get(route('parent.student.id_card', $other->id));

        $response->assertNotFound();
        $response->assertDontSee('Other Family Zq');
    }
}
