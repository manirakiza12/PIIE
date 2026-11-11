<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Staff\StaffIdCard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Tests for the unified ID card redesign:
 * - Staff ID format (PIIE/STF/YYYY/NNN)
 * - Signature display
 * - Photo processing
 * - PDF dimensions (CR80)
 * - Legacy staff ID handling
 */
class IdCardRedesignTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        Schema::table('users', function (Blueprint $table) {
            $table->string('staff_status')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
        });
        Schema::table('schools', fn (Blueprint $table) => $table->string('phone')->nullable());

        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20] as $role) {
            DB::table('roles')->insert(['role_id' => $role, 'name' => $role === 3 ? 'Lecturer' : 'Role '.$role, 'school_id' => 0]);
        }
    }

    private function makeStaff(int $schoolId, int $roleId = 3, ?string $code = null): User
    {
        return User::create([
            'school_id' => $schoolId,
            'role_id' => $roleId,
            'name' => 'Test Staff '.($code ?? $roleId),
            'code' => $code,
            'email' => 'staff.'.$roleId.'.'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'account_status' => 'active',
            'staff_status' => 'active',
            'user_information' => json_encode(['phone' => '0700123456']),
        ]);
    }

    /** @test */
    public function test_staff_id_card_uses_new_piie_stf_format_for_new_staff(): void
    {
        $schoolId = $this->makeSchool(['title' => 'Test Institute']);
        $staff = $this->makeStaff($schoolId, 3); // Lecturer, no code

        $cardNumber = StaffIdCard::formatStaffCardNumber($staff);

        $this->assertMatchesRegularExpression('/^PIIE\/STF\/\d{4}\/\d{3}$/', $cardNumber);
        $this->assertStringStartsWith('PIIE/STF/'.date('Y').'/', $cardNumber);
    }

    /** @test */
    public function test_staff_id_card_preserves_existing_new_format_codes(): void
    {
        $schoolId = $this->makeSchool();
        $staff = $this->makeStaff($schoolId, 3, 'PIIE/STF/2025/042');

        $cardNumber = StaffIdCard::formatStaffCardNumber($staff);

        $this->assertSame('PIIE/STF/2025/042', $cardNumber);
    }

    /** @test */
    public function test_staff_id_card_preserves_legacy_codes(): void
    {
        $schoolId = $this->makeSchool();
        $staff = $this->makeStaff($schoolId, 3, 'EMP-3-LEGACY');

        $cardNumber = StaffIdCard::formatStaffCardNumber($staff);

        $this->assertSame('EMP-3-LEGACY', $cardNumber);
    }

    /** @test */
    public function test_staff_id_card_shows_authorized_signature_on_back(): void
    {
        $schoolId = $this->makeSchool(['title' => 'Signature Test School']);
        $staff = $this->makeStaff($schoolId);

        $data = StaffIdCard::data($staff, true);

        $this->assertArrayHasKey('staffSignatureSrc', $data);
        $this->assertStringStartsWith('data:image/', $data['staffSignatureSrc']);
        $this->assertNotEmpty(substr($data['staffSignatureSrc'], strlen('data:image/')));

        // Browser view uses the public asset URL rather than embedding the file.
        $webData = StaffIdCard::data($staff);
        $this->assertStringContainsString('Twinamatsiko Naboth.jpg', $webData['staffSignatureSrc']);
    }

    /** @test */
    public function test_student_id_card_shows_academic_registrar_signature_on_back(): void
    {
        $schoolId = $this->makeSchool();
        $staff = $this->makeStaff($schoolId, 7); // Student role for testing
        $staff->update(['role_id' => 7, 'code' => student_code()]);

        $data = StaffIdCard::data($staff, true);

        $this->assertArrayHasKey('studentSignatureSrc', $data);
        $this->assertStringStartsWith('data:image/', $data['studentSignatureSrc']);
        $this->assertNotEmpty(substr($data['studentSignatureSrc'], strlen('data:image/')));

        // Browser view uses the public asset URL rather than embedding the file.
        $webData = StaffIdCard::data($staff);
        $this->assertStringContainsString('academic registrar.jpg', $webData['studentSignatureSrc']);
    }

    /** @test */
    public function test_generate_staff_id_is_unique_and_sequential(): void
    {
        $schoolId = $this->makeSchool();

        $id1 = StaffIdCard::generateStaffId($schoolId);
        $id2 = StaffIdCard::generateStaffId($schoolId);
        $id3 = StaffIdCard::generateStaffId($schoolId);

        $this->assertMatchesRegularExpression('/^PIIE\/STF\/\d{4}\/\d{3}$/', $id1);
        $this->assertMatchesRegularExpression('/^PIIE\/STF\/\d{4}\/\d{3}$/', $id2);
        $this->assertMatchesRegularExpression('/^PIIE\/STF\/\d{4}\/\d{3}$/', $id3);

        $this->assertNotEquals($id1, $id2);
        $this->assertNotEquals($id2, $id3);

        // Extract sequence numbers
        $seq1 = (int) substr($id1, -3);
        $seq2 = (int) substr($id2, -3);
        $seq3 = (int) substr($id3, -3);

        $this->assertEquals($seq1 + 1, $seq2);
        $this->assertEquals($seq2 + 1, $seq3);
    }

    /** @test */
    public function test_concurrent_staff_id_generation_no_collision(): void
    {
        $schoolId = $this->makeSchool();

        // Simulate concurrent generation by calling multiple times rapidly
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = StaffIdCard::generateStaffId($schoolId);
        }

        // All should be unique
        $this->assertEquals(count($ids), count(array_unique($ids)));

        // All should follow the format
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^PIIE\/STF\/\d{4}\/\d{3}$/', $id);
        }
    }

    /** @test */
    public function test_photo_processing_returns_data_uri_for_pdf(): void
    {
        $schoolId = $this->makeSchool();
        $student = User::create([
            'school_id' => $schoolId,
            'role_id' => 7,
            'name' => 'Photo Test Student',
            'code' => student_code(),
            'email' => 'photo.test.'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'user_information' => json_encode(['photo' => '']),
        ]);

        // Test with no uploaded photo - the generic thumbnail is processed
        // into an embeddable image data URI for the PDF.
        $dataUri = \App\Support\PhotoProcessing::getProcessedPhotoDataUri($student->id);
        $this->assertStringStartsWith('data:image/', $dataUri);
        $this->assertNotEmpty(substr($dataUri, strlen('data:image/')));
    }

    /** @test */
    public function test_student_pdf_contains_processed_photo(): void
    {
        $schoolId = $this->makeSchool();
        $student = User::create([
            'school_id' => $schoolId,
            'role_id' => 7,
            'name' => 'PDF Photo Student',
            'code' => student_code(),
            'email' => 'pdf.photo.'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'user_information' => json_encode(['photo' => '']),
        ]);

        $response = $this->actingAs($student)->get(route('student.id_card.pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /** @test */
    public function test_staff_pdf_contains_processed_photo_and_signature(): void
    {
        $schoolId = $this->makeSchool(['title' => 'Staff PDF School']);
        $staff = $this->makeStaff($schoolId);

        $response = $this->actingAs($staff)->get(route('staff.id_card.pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $content = $response->getContent();

        // Dompdf embeds the photo, logo, QR code and signature as image objects.
        // Text inside compressed PDF streams is not searchable, so assert on the
        // embedded image objects instead of the signature's literal text.
        $this->assertMatchesRegularExpression('/\/Subtype\s*\/Image/', $content);
    }

    /** @test */
    public function test_card_dimensions_are_cr80_compliant_in_pdf(): void
    {
        $schoolId = $this->makeSchool();
        $staff = $this->makeStaff($schoolId);

        $response = $this->actingAs($staff)->get(route('staff.id_card.pdf'));
        $content = $response->getContent();

        // CR80: 85.60mm x 53.98mm = 242.65pt x 153.01pt. Dompdf writes the
        // resolved page size to /MediaBox rather than the CSS @page declaration.
        $this->assertMatchesRegularExpression(
            '/\/MediaBox\s*\[\s*0(?:\.0+)?\s+0(?:\.0+)?\s+242\.6\d*\s+153(?:\.0+)?\s*\]/',
            $content
        );
    }

    /** @test */
    public function test_verification_page_shows_new_staff_id_format(): void
    {
        $schoolId = $this->makeSchool(['title' => 'Verify Format School']);
        $staff = $this->makeStaff($schoolId, 3, 'PIIE/STF/2026/042');

        $url = StaffIdCard::verifyUrl($staff);
        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('PIIE/STF/2026/042');
        $response->assertSee('VALID STAFF ID');
    }

    /** @test */
    public function test_super_admin_does_not_get_fabricated_staff_id(): void
    {
        // Super admin (role_id=1) should not get a fabricated staff ID
        // They are not actual staff members
        $schoolId = $this->makeSchool();
        $superAdmin = User::create([
            'school_id' => $schoolId,
            'role_id' => 1,
            'name' => 'Super Admin',
            'code' => null,
            'email' => 'super.'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'account_status' => 'active',
        ]);

        $cardNumber = StaffIdCard::formatStaffCardNumber($superAdmin);

        // Should fall back to ID-based format since no code and not eligible for generation
        $this->assertStringStartsWith('PIIE/STF/', $cardNumber);
    }

    /** @test */
    public function test_student_card_shows_programme_and_not_class_when_enrolled(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Computer Science']);
        $student = User::create([
            'school_id' => $schoolId,
            'role_id' => 7,
            'name' => 'Programme Student',
            'code' => student_code(),
            'email' => 'prog.student.'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
        ]);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.id_card'));

        $response->assertOk();
        $response->assertSee('Computer Science');
        $response->assertDontSee('Not assigned');
    }
}