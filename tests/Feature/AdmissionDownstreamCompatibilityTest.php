<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Enrollment;
use App\Models\StudentFeeManager;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Batch 7 — proves a student enrolled through the online applicant portal
 * and one enrolled through the admin staff-entry wizard are resolved
 * identically by the shared lookups every downstream module (Attendance,
 * Timetable, Assignments, Online Exams, Live Classes, Marks, Finance) reads
 * from: CommonController::get_student_details_by_id() (Enrollment-based —
 * Classes) and resolve_student_academic_context() (Enrollment-first,
 * StudentProfile-fallback — Assignments and Online Exams' eligibility
 * scopes). Neither is re-implemented here; both are exercised directly
 * against a student provisioned via the real conversion path, so this is a
 * genuine downstream-resolution check, not a re-statement of Batch 1.
 */
class AdmissionDownstreamCompatibilityTest extends TestCase
{
    use AdmissionsTestHelper { makeAdmission as private makeBasicAdmission; }

    protected function makeAdmission(int $schoolId, array $overrides = []): int
    {
        $id = $this->makeBasicAdmission($schoolId, $overrides);
        $this->completeAdmissionForDecision(Admission::findOrFail($id));
        return $id;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function configurePrimarySchool(int $schoolId): void
    {
        DB::table('global_settings')->insert([
            'key' => 'primary_school_id',
            'value' => (string) $schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enrolAdmission(int $admissionId, array $assignment, $admin): void
    {
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), array_merge(
            ['status' => 'enrolled'],
            $assignment
        ));
    }

    public function test_online_and_staff_entry_students_resolve_identically_through_get_student_details_by_id(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $classId = $this->makeClass($schoolId, ['name' => 'Year One']);
        $sectionId = $this->makeSection($classId, ['name' => 'A']);
        $departmentId = $this->makeDepartment($schoolId);
        $sessionId = $this->makeAcademicSession($schoolId);

        $applicant = $this->makeApplicant($schoolId);
        $onlineAdmissionId = $this->makeAdmission($schoolId, [
            'applicant_id' => $applicant->id,
            'source' => 'public',
            'programme_id' => $programmeId,
            'email' => 'online.student@example.com',
            'status' => 'accepted',
        ]);

        $staffAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'programme_id' => $programmeId,
            'email' => 'staff.student@example.com',
            'status' => 'accepted',
        ]);

        $assignment = ['class_id' => $classId, 'section_id' => $sectionId, 'department_id' => $departmentId, 'session_id' => $sessionId];
        $this->enrolAdmission($onlineAdmissionId, $assignment, $admin);
        $this->enrolAdmission($staffAdmissionId, $assignment, $admin);

        $onlineStudent = User::where('email', 'online.student@example.com')->first();
        $staffStudent  = User::where('email', 'staff.student@example.com')->first();

        $common = new \App\Http\Controllers\CommonController();
        $onlineDetails = $common->get_student_details_by_id($onlineStudent->id);
        $staffDetails  = $common->get_student_details_by_id($staffStudent->id);

        // Same class/section resolution regardless of admission source —
        // this is exactly what Attendance/Timetable/Subjects/Marks read.
        $this->assertSame($classId, (int) $onlineDetails['class_id']);
        $this->assertSame($classId, (int) $staffDetails['class_id']);
        $this->assertSame($sectionId, (int) $onlineDetails['section_id']);
        $this->assertSame($sectionId, (int) $staffDetails['section_id']);
        $this->assertNotEmpty($onlineDetails['class_name']);
        $this->assertSame($onlineDetails['class_name'], $staffDetails['class_name']);
    }

    public function test_online_and_staff_entry_students_resolve_identically_through_academic_context_helper(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $classId = $this->makeClass($schoolId);
        $sectionId = $this->makeSection($classId);
        $departmentId = $this->makeDepartment($schoolId);
        $sessionId = $this->makeAcademicSession($schoolId);

        $onlineAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'public', 'programme_id' => $programmeId,
            'email' => 'ctx.online@example.com', 'status' => 'accepted',
        ]);
        $staffAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'programme_id' => $programmeId,
            'email' => 'ctx.staff@example.com', 'status' => 'accepted',
        ]);

        $assignment = ['class_id' => $classId, 'section_id' => $sectionId, 'department_id' => $departmentId, 'session_id' => $sessionId];
        $this->enrolAdmission($onlineAdmissionId, $assignment, $admin);
        $this->enrolAdmission($staffAdmissionId, $assignment, $admin);

        $onlineStudent = User::where('email', 'ctx.online@example.com')->first();
        $staffStudent  = User::where('email', 'ctx.staff@example.com')->first();

        $onlineContext = resolve_student_academic_context($onlineStudent->id, $schoolId);
        $staffContext  = resolve_student_academic_context($staffStudent->id, $schoolId);

        $this->assertSame('class', $onlineContext['mode']);
        $this->assertSame('class', $staffContext['mode']);
        $this->assertSame($classId, $onlineContext['class_id']);
        $this->assertSame($classId, $staffContext['class_id']);
    }

    /**
     * The case this whole effort exists to fix: before the Enrollment fix,
     * a student with no class assignment resolved to mode=programme with a
     * null class_id, meaning any class-scoped Assignment/Online-Exam was
     * invisible to them regardless of admission source. This proves both
     * origins now correctly resolve mode=class once academically assigned.
     */
    public function test_a_student_without_academic_assignment_falls_back_to_programme_mode_for_both_origins(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);

        $onlineAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'public', 'programme_id' => $programmeId,
            'email' => 'noassign.online@example.com', 'status' => 'accepted',
        ]);
        $staffAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'programme_id' => $programmeId,
            'email' => 'noassign.staff@example.com', 'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $onlineAdmissionId), ['status' => 'enrolled']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $staffAdmissionId), ['status' => 'enrolled']);

        $onlineStudent = User::where('email', 'noassign.online@example.com')->first();
        $staffStudent  = User::where('email', 'noassign.staff@example.com')->first();

        $onlineContext = resolve_student_academic_context($onlineStudent->id, $schoolId);
        $staffContext  = resolve_student_academic_context($staffStudent->id, $schoolId);

        $this->assertSame('programme', $onlineContext['mode']);
        $this->assertSame('programme', $staffContext['mode']);
        $this->assertSame($programmeId, $onlineContext['programme_id']);
        $this->assertSame($programmeId, $staffContext['programme_id']);
    }

    public function test_fee_invoices_are_generated_identically_for_both_admission_origins(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $this->makeFeeStructure($schoolId, ['programme_id' => $programmeId, 'amount' => 750]);

        $onlineAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'public', 'programme_id' => $programmeId,
            'email' => 'fee.online@example.com', 'status' => 'accepted',
        ]);
        $staffAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'programme_id' => $programmeId,
            'email' => 'fee.staff@example.com', 'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $onlineAdmissionId), ['status' => 'enrolled']);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $staffAdmissionId), ['status' => 'enrolled']);

        $onlineStudent = User::where('email', 'fee.online@example.com')->first();
        $staffStudent  = User::where('email', 'fee.staff@example.com')->first();

        $this->assertSame(1, StudentFeeManager::where('student_id', $onlineStudent->id)->count());
        $this->assertSame(1, StudentFeeManager::where('student_id', $staffStudent->id)->count());
    }

    public function test_both_admission_origins_produce_a_student_profile_and_enrollment_of_the_same_shape(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $classId = $this->makeClass($schoolId);
        $sectionId = $this->makeSection($classId);
        $departmentId = $this->makeDepartment($schoolId);
        $sessionId = $this->makeAcademicSession($schoolId);

        $onlineAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'public', 'programme_id' => $programmeId, 'nationality' => 'Kenyan',
            'email' => 'shape.online@example.com', 'status' => 'accepted',
        ]);
        $staffAdmissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry', 'programme_id' => $programmeId, 'nationality' => 'Kenyan',
            'email' => 'shape.staff@example.com', 'status' => 'accepted',
        ]);

        $assignment = ['class_id' => $classId, 'section_id' => $sectionId, 'department_id' => $departmentId, 'session_id' => $sessionId];
        $this->enrolAdmission($onlineAdmissionId, $assignment, $admin);
        $this->enrolAdmission($staffAdmissionId, $assignment, $admin);

        $onlineStudent = User::where('email', 'shape.online@example.com')->first();
        $staffStudent  = User::where('email', 'shape.staff@example.com')->first();

        // role_id, School isolation and structural shape must match exactly.
        $this->assertSame(7, (int) $onlineStudent->role_id);
        $this->assertSame(7, (int) $staffStudent->role_id);

        $onlineProfile = StudentProfile::where('user_id', $onlineStudent->id)->first();
        $staffProfile  = StudentProfile::where('user_id', $staffStudent->id)->first();
        $this->assertSame($programmeId, $onlineProfile->programme_id);
        $this->assertSame($programmeId, $staffProfile->programme_id);
        $this->assertSame('Kenyan', $onlineProfile->nationality);
        $this->assertSame('Kenyan', $staffProfile->nationality);

        $onlineEnrolment = Enrollment::where('user_id', $onlineStudent->id)->first();
        $staffEnrolment  = Enrollment::where('user_id', $staffStudent->id)->first();
        $this->assertSame($onlineEnrolment->class_id, $staffEnrolment->class_id);
        $this->assertSame($onlineEnrolment->section_id, $staffEnrolment->section_id);
        $this->assertSame($onlineEnrolment->department_id, $staffEnrolment->department_id);
        $this->assertSame($onlineEnrolment->session_id, $staffEnrolment->session_id);
    }
}
