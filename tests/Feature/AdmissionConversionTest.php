<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\StudentFeeManager;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdmissionConversionTest extends TestCase
{
    use AdmissionsTestHelper { makeAdmission as private makeBasicAdmission; }

    protected function makeAdmission(int $schoolId, array $overrides = []): int
    {
        $id = $this->makeBasicAdmission($schoolId, $overrides);
        $this->completeAdmissionForDecision(\App\Models\Admission::findOrFail($id));
        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    public function test_enrolling_an_admission_creates_a_student_with_the_correct_role_and_registration_number(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'intake_session_id' => $intakeId,
            'email' => 'convert.me@example.com',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $response->assertStatus(302);

        $student = User::where('email', 'convert.me@example.com')->first();
        $this->assertNotNull($student, 'Admission conversion should create a User account.');
        $this->assertSame(7, (int) $student->role_id, 'Converted student must use the canonical student role_id (7), not 4.');
        $this->assertNotEmpty($student->code, 'A registration number (users.code) must be assigned.');

        $profile = StudentProfile::where('user_id', $student->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame($programmeId, $profile->programme_id);
        $this->assertSame($intakeId, $profile->intake_session_id);

        // Every teacher-facing roster (gradebook, attendance, online exam
        // class lists) reads from Enrollment, not StudentProfile — without
        // this row a Programme-track student was previously invisible
        // everywhere a teacher looks, silently. See EnrollmentDefaults.
        $this->assertDatabaseHas('enrollment', [
            'user_id' => $student->id,
            'school_id' => $schoolId,
        ]);
    }

    public function test_retrying_conversion_does_not_create_duplicate_accounts_profiles_or_invoices(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $this->makeFeeStructure($schoolId, ['programme_id' => $programmeId, 'amount' => 500]);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'retry.me@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        // Simulate a retried/duplicated request by moving the status back to
        // accepted and re-triggering the enrolled transition — this bypasses
        // the controller's oldStatus guard so we test createStudentFromAdmission's
        // OWN idempotency, not just the outer guard.
        DB::table('admissions')->where('id', $admissionId)->update(['status' => 'accepted']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $this->assertSame(1, User::where('email', 'retry.me@example.com')->count(), 'Must not create duplicate student accounts.');

        $student = User::where('email', 'retry.me@example.com')->first();
        $this->assertSame(1, StudentProfile::where('user_id', $student->id)->count(), 'Must not create duplicate student profiles.');
        $this->assertSame(1, StudentFeeManager::where('student_id', $student->id)->count(), 'Must not create duplicate fee invoices.');
        $this->assertSame(1, DB::table('enrollment')->where('user_id', $student->id)->count(), 'Must not create duplicate enrollment rows.');
    }

    public function test_enrolling_with_academic_assignment_creates_an_enrollment_row(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $departmentId = $this->makeDepartment($schoolId);
        $classId = $this->makeClass($schoolId);
        $sectionId = $this->makeSection($classId);
        $sessionId = $this->makeAcademicSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'enrol.assign@example.com',
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), [
            'status' => 'enrolled',
            'class_id' => $classId,
            'section_id' => $sectionId,
            'department_id' => $departmentId,
            'session_id' => $sessionId,
        ]);

        $response->assertStatus(302);

        $student = User::where('email', 'enrol.assign@example.com')->first();
        $this->assertNotNull($student);

        $enrollment = Enrollment::where('user_id', $student->id)->first();
        $this->assertNotNull($enrollment, 'Enrolling with an academic assignment must create an Enrollment row.');
        $this->assertSame($classId, $enrollment->class_id);
        $this->assertSame($sectionId, $enrollment->section_id);
        $this->assertSame($departmentId, $enrollment->department_id);
        $this->assertSame($sessionId, $enrollment->session_id);
        $this->assertSame($schoolId, $enrollment->school_id);
    }

    public function test_enrolling_without_academic_assignment_still_creates_a_student_with_a_sentinel_enrollment(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'enrol.noassign@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $student = User::where('email', 'enrol.noassign@example.com')->first();
        $this->assertNotNull($student, 'A student must still be provisioned even without an academic assignment.');

        // StudentProvisioningService itself only creates a real Enrollment
        // when a complete class/section/department/session was given at
        // Step 6. AdmissionsController::createStudentFromAdmission() layers
        // EnrollmentDefaults::ensureRow() on top as a fallback (see
        // EnrollmentDefaultsTest) — so a 0-sentinel row now exists rather
        // than no row at all, which is what OnlineExam::scopeVisibleToStudent()
        // and CommonController::get_student_details_by_id() both need to
        // resolve the student's current session correctly even before a
        // real class assignment happens.
        $enrollment = Enrollment::where('user_id', $student->id)->first();
        $this->assertNotNull($enrollment, 'A sentinel Enrollment row must exist even without a real academic assignment.');
        $this->assertSame(0, $enrollment->class_id);
        $this->assertSame(0, $enrollment->section_id);
    }

    public function test_department_id_falls_back_to_the_programmes_department_when_not_overridden(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $departmentId = $this->makeDepartment($schoolId);
        $programmeId = $this->makeProgramme($schoolId, ['department_id' => $departmentId]);
        $classId = $this->makeClass($schoolId);
        $sectionId = $this->makeSection($classId);
        $sessionId = $this->makeAcademicSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'enrol.deptfallback@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), [
            'status' => 'enrolled',
            'class_id' => $classId,
            'section_id' => $sectionId,
            'session_id' => $sessionId,
            // department_id intentionally omitted
        ]);

        $student = User::where('email', 'enrol.deptfallback@example.com')->first();
        $enrollment = Enrollment::where('user_id', $student->id)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($departmentId, $enrollment->department_id);
    }

    public function test_retrying_enrollment_does_not_create_duplicate_enrollment_row(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $departmentId = $this->makeDepartment($schoolId);
        $classId = $this->makeClass($schoolId);
        $sectionId = $this->makeSection($classId);
        $sessionId = $this->makeAcademicSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'retry.enrol@example.com',
            'status' => 'accepted',
        ]);

        $payload = [
            'status' => 'enrolled',
            'class_id' => $classId,
            'section_id' => $sectionId,
            'department_id' => $departmentId,
            'session_id' => $sessionId,
        ];

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), $payload);

        // Simulate a retried/duplicated request the same way the existing
        // idempotency test does — bypass the controller's oldStatus guard so
        // this exercises the provisioning service's own idempotency.
        DB::table('admissions')->where('id', $admissionId)->update(['status' => 'accepted']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), $payload);

        $student = User::where('email', 'retry.enrol@example.com')->first();
        $this->assertSame(1, User::where('email', 'retry.enrol@example.com')->count());
        $this->assertSame(1, Enrollment::where('user_id', $student->id)->count(), 'Must not create duplicate Enrollment rows.');
        $this->assertSame(1, StudentProfile::where('user_id', $student->id)->count());
    }

    public function test_registration_numbers_generated_in_bulk_are_unique(): void
    {
        $codes = [];
        for ($i = 0; $i < 25; $i++) {
            $code = student_code();
            $this->assertNotContains($code, $codes, 'student_code() must never repeat a registration number.');
            $codes[] = $code;

            User::create([
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
                'password' => bcrypt('secret'),
                'code' => $code,
                'role_id' => 7,
                'school_id' => 1,
            ]);
        }
    }
}
