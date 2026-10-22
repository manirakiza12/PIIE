<?php

namespace Tests\Feature;

use App\Models\StudentRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the new Student Affairs request-and-review workflow (transfer
 * applications, complaints, fee-discount appeals) — previously a student
 * had no formal in-app channel to request anything and get a tracked
 * response at all.
 */
class StudentRequestsTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        Schema::create('student_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('type', 40);
            $table->string('transfer_type', 60)->nullable();
            $table->unsignedBigInteger('transfer_to_programme_id')->nullable();
            $table->string('transfer_reason', 60)->nullable();
            $table->string('phone_number', 30)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->string('subject', 191);
            $table->text('details');
            $table->string('status', 20)->default('pending');
            $table->text('admin_response')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    private function makeStudent(int $schoolId, string $email): User
    {
        return User::create([
            'name' => 'Requesting Student', 'email' => $email,
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
        ]);
    }

    public function test_generic_request_form_cannot_bypass_structured_transfer_application(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'req.transfer@example.com');

        $response = $this->actingAs($student)->post(route('student.requests.store'), [
            'type' => StudentRequest::TYPE_TRANSFER,
            'subject' => 'Transfer to Nairobi campus',
            'details' => 'I am relocating for work.',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('student_requests', ['student_id' => $student->id]);
    }

    public function test_an_invalid_request_type_is_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'req.invalid@example.com');

        $this->actingAs($student)->post(route('student.requests.store'), [
            'type' => 'not-a-real-type',
            'subject' => 'X',
            'details' => 'Y',
        ])->assertSessionHasErrors('type');

        $this->assertSame(0, StudentRequest::count());
    }

    public function test_a_student_only_sees_their_own_requests(): void
    {
        $schoolId = $this->makeSchool();
        $studentA = $this->makeStudent($schoolId, 'req.a@example.com');
        $studentB = $this->makeStudent($schoolId, 'req.b@example.com');
        StudentRequest::create([
            'student_id' => $studentA->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_COMPLAINT,
            'subject' => 'From A', 'details' => 'details', 'status' => StudentRequest::STATUS_PENDING,
        ]);
        StudentRequest::create([
            'student_id' => $studentB->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_COMPLAINT,
            'subject' => 'From B', 'details' => 'details', 'status' => StudentRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($studentA)->get(route('student.requests.index'));

        $response->assertOk();
        $response->assertSee('From A');
        $response->assertDontSee('From B');
    }

    public function test_admin_can_approve_a_request_with_a_response(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $student = $this->makeStudent($schoolId, 'req.approve@example.com');
        $req = StudentRequest::create([
            'student_id' => $student->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_FEE_DISCOUNT_APPEAL,
            'subject' => 'Appeal', 'details' => 'Please help', 'status' => StudentRequest::STATUS_PENDING,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.student_requests.update', $req->id), [
            'status' => StudentRequest::STATUS_APPROVED,
            'admin_response' => 'Granted a 10% discount.',
        ]);

        $response->assertRedirect();
        $req->refresh();
        $this->assertSame(StudentRequest::STATUS_APPROVED, $req->status);
        $this->assertSame('Granted a 10% discount.', $req->admin_response);
        $this->assertNotNull($req->reviewed_at);
        // assertEquals, not assertSame — a pre-existing, unrelated sqlite
        // int/string casting quirk affecting several other test files this
        // session already worked around the same way.
        $this->assertEquals($admin->id, $req->reviewed_by);
    }

    public function test_the_student_sees_the_admin_response_once_reviewed(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $student = $this->makeStudent($schoolId, 'req.seeresponse@example.com');
        $req = StudentRequest::create([
            'student_id' => $student->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_COMPLAINT,
            'subject' => 'Noise complaint', 'details' => 'Too loud', 'status' => StudentRequest::STATUS_PENDING,
        ]);
        $this->actingAs($admin)->post(route('admin.student_requests.update', $req->id), [
            'status' => StudentRequest::STATUS_REJECTED,
            'admin_response' => 'Already resolved.',
        ]);

        $response = $this->actingAs($student)->get(route('student.requests.index'));

        $response->assertOk();
        $response->assertSee('Already resolved.');
        $response->assertSee('Rejected');
    }

    public function test_an_admin_cannot_review_another_schools_request(): void
    {
        $schoolId = $this->makeSchool();
        $otherSchoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $otherStudent = $this->makeStudent($otherSchoolId, 'req.other@example.com');
        $req = StudentRequest::create([
            'student_id' => $otherStudent->id, 'school_id' => $otherSchoolId, 'type' => StudentRequest::TYPE_COMPLAINT,
            'subject' => 'Other school', 'details' => 'x', 'status' => StudentRequest::STATUS_PENDING,
        ]);

        $this->actingAs($admin)->post(route('admin.student_requests.update', $req->id), [
            'status' => StudentRequest::STATUS_APPROVED,
        ])->assertStatus(404);
    }

    // ── My Transfers (structured transfer application) ─────────────

    public function test_transfers_page_renders_with_no_applications_yet(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.empty@example.com');

        $response = $this->actingAs($student)->get(route('student.transfers.index'));

        $response->assertOk();
        $response->assertSee('No transfer applications submitted yet');
    }

    public function test_a_student_can_submit_a_structured_transfer_application(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.submit@example.com');
        $targetProgrammeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Science']);

        $response = $this->actingAs($student)->post(route('student.transfers.store'), [
            'transfer_type' => 'change_of_programme',
            'transfer_to_programme_id' => $targetProgrammeId,
            'transfer_reason' => 'career_change',
            'phone_number' => '0700123456',
        ]);

        $response->assertRedirect(route('student.transfers.index'));
        $this->assertDatabaseHas('student_requests', [
            'student_id' => $student->id,
            'school_id' => $schoolId,
            'type' => StudentRequest::TYPE_TRANSFER,
            'transfer_type' => 'change_of_programme',
            'transfer_to_programme_id' => $targetProgrammeId,
            'transfer_reason' => 'career_change',
            'phone_number' => '0700123456',
            'status' => StudentRequest::STATUS_PENDING,
        ]);
    }

    public function test_a_transfer_application_requires_type_target_and_reason(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.invalid@example.com');

        $this->actingAs($student)->post(route('student.transfers.store'), [])
            ->assertSessionHasErrors(['transfer_type', 'transfer_to_programme_id', 'transfer_reason']);

        $this->assertSame(0, StudentRequest::count());
    }

    public function test_a_transfer_application_must_target_a_real_programme(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.badprogramme@example.com');

        $this->actingAs($student)->post(route('student.transfers.store'), [
            'transfer_type' => 'change_of_programme',
            'transfer_to_programme_id' => 999999,
            'transfer_reason' => 'career_change',
        ])->assertSessionHasErrors('transfer_to_programme_id');
    }

    public function test_a_transfer_application_cannot_target_another_schools_programme(): void
    {
        $schoolId = $this->makeSchool();
        $otherSchoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.foreignprogramme@example.com');
        $foreignProgrammeId = $this->makeProgramme($otherSchoolId, ['name' => 'Foreign Programme']);

        $this->actingAs($student)->post(route('student.transfers.store'), [
            'transfer_type' => 'change_of_programme',
            'transfer_to_programme_id' => $foreignProgrammeId,
            'transfer_reason' => 'career_change',
        ])->assertSessionHasErrors('transfer_to_programme_id');

        $this->assertDatabaseMissing('student_requests', ['student_id' => $student->id]);
    }

    public function test_submitting_or_reviewing_a_transfer_does_not_change_student_enrollment(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.noenrollmentchange@example.com');
        $targetProgrammeId = $this->makeProgramme($schoolId);
        $classId = $this->makeClass($schoolId, ['id' => 17]);
        $sectionId = $this->makeSection($classId, ['id' => 23]);
        \Illuminate\Support\Facades\DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post(route('student.transfers.store'), [
            'transfer_type' => 'change_of_programme',
            'transfer_to_programme_id' => $targetProgrammeId,
            'transfer_reason' => 'career_change',
        ])->assertRedirect(route('student.transfers.index'));

        $transfer = StudentRequest::where('student_id', $student->id)->firstOrFail();
        $this->assertDatabaseHas('enrollment', ['user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId]);

        $reviewer = $this->makeAdminUser($schoolId);
        $this->actingAs($reviewer)->post(route('admin.student_requests.update', $transfer->id), [
            'status' => StudentRequest::STATUS_APPROVED,
            'admin_response' => 'Approved for review by the academic office.',
        ])->assertRedirect();

        $this->assertDatabaseHas('enrollment', ['user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId]);
        $this->assertEquals($targetProgrammeId, $transfer->fresh()->transfer_to_programme_id);
    }

    public function test_the_submitted_transfer_appears_in_my_transfers_with_readable_labels(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'transfers.listshow@example.com');
        $targetProgrammeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Laws']);
        StudentRequest::create([
            'student_id' => $student->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_TRANSFER,
            'subject' => 'Inter/Intra Programme Transfer Application', 'details' => '',
            'status' => StudentRequest::STATUS_PENDING,
            'transfer_type' => 'inter_institution',
            'transfer_to_programme_id' => $targetProgrammeId,
            'transfer_reason' => 'relocation',
        ]);

        $response = $this->actingAs($student)->get(route('student.transfers.index'));

        $response->assertOk();
        $response->assertSee('Inter-Institution Transfer');
        $response->assertSee('Bachelor of Laws');
        $response->assertSee('Relocation');
    }

    public function test_a_student_only_sees_their_own_transfers(): void
    {
        $schoolId = $this->makeSchool();
        $studentA = $this->makeStudent($schoolId, 'transfers.a@example.com');
        $studentB = $this->makeStudent($schoolId, 'transfers.b@example.com');
        $targetProgrammeId = $this->makeProgramme($schoolId);
        StudentRequest::create([
            'student_id' => $studentB->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_TRANSFER,
            'subject' => 'x', 'details' => '', 'status' => StudentRequest::STATUS_PENDING,
            'transfer_type' => 'relocation', 'transfer_to_programme_id' => $targetProgrammeId, 'transfer_reason' => 'other',
        ]);

        $response = $this->actingAs($studentA)->get(route('student.transfers.index'));

        $response->assertOk();
        $response->assertSee('No transfer applications submitted yet');
    }

    public function test_admin_sees_structured_transfer_details_in_the_review_queue(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $student = $this->makeStudent($schoolId, 'transfers.adminview@example.com');
        $targetProgrammeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Education']);
        StudentRequest::create([
            'student_id' => $student->id, 'school_id' => $schoolId, 'type' => StudentRequest::TYPE_TRANSFER,
            'subject' => 'Inter/Intra Programme Transfer Application', 'details' => '',
            'status' => StudentRequest::STATUS_PENDING,
            'transfer_type' => 'change_of_campus',
            'transfer_to_programme_id' => $targetProgrammeId,
            'transfer_reason' => 'financial',
            'phone_number' => '0711222333',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.student_requests.index'));

        $response->assertOk();
        $response->assertSee('Bachelor of Education');
        $response->assertSee('Change of Campus / Study Centre');
        $response->assertSee('Financial Reasons');
        $response->assertSee('0711222333');
    }
}
