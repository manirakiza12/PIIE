<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the new self-service digital ID card
 * (StudentController::idCardGenerate()/idCardPdf()) — students previously
 * had no way to view or print their own ID card at all; only admin/parent
 * could generate one on their behalf.
 */
class StudentIdCardTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        // get_student_details_by_id() (CommonController) looks up a Role
        // row by role_id — the roles table itself is part of the shared
        // schema (AdmissionsTestHelper), seeded here per-test as needed.
        DB::table('roles')->insert(['role_id' => 7, 'name' => 'Student', 'school_id' => 0]);
    }

    private function makeStudent(int $schoolId, string $email): User
    {
        return User::create([
            'name' => 'Card Student', 'email' => $email,
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
            'user_information' => json_encode(['phone' => '0700000000', 'blood_group' => 'o+']),
        ]);
    }

    public function test_class_track_student_id_card_shows_class_and_section(): void
    {
        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId, ['name' => 'Grade 9']);
        $sectionId = (int) DB::table('sections')->insertGetId([
            'name' => 'B', 'class_id' => $classId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->makeStudent($schoolId, 'card.class@example.com');
        DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.id_card'));

        $response->assertOk();
        $response->assertSee('Grade 9');
        $response->assertSee('Card Student');
    }

    public function test_programme_track_student_id_card_shows_programme_instead_of_class(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Science']);
        $student = $this->makeStudent($schoolId, 'card.programme@example.com');
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.id_card'));

        $response->assertOk();
        $response->assertSee('Bachelor of Science');
        $response->assertDontSee('Class :', false);
    }

    public function test_id_card_pdf_download_produces_a_genuine_pdf(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.pdf@example.com');

        $response = $this->actingAs($student)->get(route('student.id_card.pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_student_with_no_enrollment_or_profile_still_renders_without_error(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.bare@example.com');

        $response = $this->actingAs($student)->get(route('student.id_card'));

        $response->assertOk();
        $response->assertSee('Not assigned');
    }

    public function test_the_card_shows_a_card_number_and_embedded_qr_code(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.qr@example.com');

        $response = $this->actingAs($student)->get(route('student.id_card'));

        $response->assertOk();
        $response->assertSee($student->code);
        $response->assertSee('data:image/svg+xml;base64,', false);
    }

    public function test_the_qr_code_encodes_a_signed_verification_url_that_resolves(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.verifyflow@example.com');

        $verifyUrl = \App\Support\IdCard::verifyUrl($student);

        $response = $this->get($verifyUrl);

        $response->assertOk();
        $response->assertSee('Card Student');
        $response->assertSee('VALID STUDENT ID');
    }

    public function test_a_tampered_verification_url_is_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.tampered@example.com');

        $verifyUrl = \App\Support\IdCard::verifyUrl($student);
        $tampered = $verifyUrl . '&tampered=1';

        $this->get($tampered)->assertStatus(403);
    }

    public function test_verification_page_flags_a_disabled_students_id_as_not_active(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.disabled@example.com');
        $student->update(['account_status' => 'disable']);

        $verifyUrl = \App\Support\IdCard::verifyUrl($student);

        $response = $this->get($verifyUrl);

        $response->assertOk();
        $response->assertSee('THIS ACCOUNT IS NOT ACTIVE');
    }

    public function test_verification_never_leaks_phone_or_blood_group(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'card.privacy@example.com');

        $verifyUrl = \App\Support\IdCard::verifyUrl($student);

        $response = $this->get($verifyUrl);

        $response->assertOk();
        $response->assertDontSee('0700000000');
        $response->assertDontSee('O+');
    }
}
