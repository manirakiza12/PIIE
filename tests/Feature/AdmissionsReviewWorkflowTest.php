<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\Applicant;
use App\Models\ApplicationPayment;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The staff side of the portal: reviewing an application, acting on its
 * documents and fee, and handing it back for corrections.
 */
class AdmissionsReviewWorkflowTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        $this->schoolId = $this->makeSchool(['title' => 'Prime International Institute of Excellence']);

        DB::table('global_settings')->insert([
            'key'        => 'primary_school_id',
            'value'      => (string) $this->schoolId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSubmittedAdmission(array $overrides = []): Admission
    {
        $applicant = $this->makeApplicant($this->schoolId);

        $id = $this->makeAdmission($this->schoolId, array_merge([
            'applicant_id' => $applicant->id,
            'app_number'   => 'PIIE-2526-S-P0001',
            'status'       => Admission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ], $overrides));

        return Admission::find($id);
    }

    public function test_review_screen_renders_the_whole_application(): void
    {
        $admission = $this->makeSubmittedAdmission(['nationality' => 'Ugandan']);
        $admin     = $this->makeAdminUser($this->schoolId);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admission->id));

        $response->assertStatus(200);
        $response->assertSee('PIIE-2526-S-P0001');
        $response->assertSee('Ugandan');
    }

    public function test_review_screen_shows_academic_assignment_fields_for_enrolment(): void
    {
        $admission = $this->makeSubmittedAdmission(['status' => Admission::STATUS_ACCEPTED]);
        $admin     = $this->makeAdminUser($this->schoolId);
        $classId   = $this->makeClass($this->schoolId, ['name' => 'Year One']);
        $this->makeSection($classId, ['name' => 'Stream A']);
        $this->makeDepartment($this->schoolId, ['name' => 'Faculty of Science']);
        $this->makeAcademicSession($this->schoolId, ['session_title' => '2026/2027']);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admission->id));

        $response->assertStatus(200);
        $response->assertSee('Academic Assignment');
        $response->assertSee('Year One');
        $response->assertSee('Faculty of Science');
        $response->assertSee('2026/2027');
    }

    public function test_review_screen_shows_read_only_summary_once_already_enrolled(): void
    {
        $admission = $this->makeSubmittedAdmission(['status' => Admission::STATUS_ACCEPTED]);
        $this->completeAdmissionForDecision($admission);
        $admin     = $this->makeAdminUser($this->schoolId);
        $classId   = $this->makeClass($this->schoolId, ['name' => 'Year One']);
        $sectionId = $this->makeSection($classId, ['name' => 'Stream A']);
        $departmentId = $this->makeDepartment($this->schoolId);
        $sessionId = $this->makeAcademicSession($this->schoolId);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admission->id), [
            'status' => 'enrolled',
            'class_id' => $classId,
            'section_id' => $sectionId,
            'department_id' => $departmentId,
            'session_id' => $sessionId,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admission->id));

        $response->assertStatus(200);
        $response->assertSee('Already enrolled');
        $response->assertSee('Year One');
        $response->assertSee('Stream A');
    }

    public function test_review_screen_is_scoped_to_the_reviewers_school(): void
    {
        $otherSchoolId = $this->makeSchool(['title' => 'Other School']);

        $foreignId = $this->makeAdmission($otherSchoolId, [
            'app_number' => 'OTHER-0001',
            'status'     => Admission::STATUS_SUBMITTED,
        ]);

        $admin = $this->makeAdminUser($this->schoolId);

        $this->actingAs($admin)
            ->get(route('admin.hei_admissions.review', $foreignId))
            ->assertStatus(404);
    }

    public function test_a_status_change_records_a_timeline_entry_and_the_decision_note(): void
    {
        $admission = $this->makeSubmittedAdmission();
        $this->completeAdmissionForDecision($admission);
        $admin     = $this->makeAdminUser($this->schoolId);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admission->id), [
            'status'        => Admission::STATUS_ACCEPTED,
            'decision_note' => 'Strong academic record.',
        ]);

        $admission = $admission->fresh();

        $this->assertSame(Admission::STATUS_ACCEPTED, $admission->status);
        $this->assertSame('Strong academic record.', $admission->decision_note);
        $this->assertNotNull($admission->offer_date);
        $this->assertNotNull($admission->decided_at);

        $this->assertDatabaseHas('admission_status_events', [
            'admission_id' => $admission->id,
            'from_status'  => Admission::STATUS_SUBMITTED,
            'to_status'    => Admission::STATUS_ACCEPTED,
            'actor_type'   => 'staff',
        ]);
    }

    public function test_requesting_corrections_reopens_editing_for_the_applicant(): void
    {
        $admission = $this->makeSubmittedAdmission();
        $admin     = $this->makeAdminUser($this->schoolId);

        $this->actingAs($admin)->post(route('admin.hei_admissions.correction', $admission->id), [
            'correction_note' => 'Your transcript is not legible. Please re-scan it.',
        ]);

        $admission = $admission->fresh();

        $this->assertSame(Admission::STATUS_NEEDS_CORRECTION, $admission->status);
        $this->assertSame('Your transcript is not legible. Please re-scan it.', $admission->correction_note);
        $this->assertTrue($admission->isEditableByApplicant());
    }

    public function test_resubmission_after_corrections_clears_the_note_and_returns_to_the_queue(): void
    {
        $applicant = $this->makeApplicant($this->schoolId);

        $id = $this->makeAdmission($this->schoolId, [
            'applicant_id'      => $applicant->id,
            'app_number'        => 'PIIE-2526-S-P0002',
            'status'            => Admission::STATUS_NEEDS_CORRECTION,
            'correction_note'   => 'Fix your transcript.',
            'submitted_at'      => now()->subDay(),
            'programme_id'      => $this->makeProgramme($this->schoolId),
            'intake_session_id' => $this->makeIntakeSession($this->schoolId, ['application_fee' => 0]),
        ]);

        $this->completeApplicationFields(Admission::find($id));

        // A school with no requirements configured falls back to the built-in
        // set, which is required — so give this one a checklist it satisfies,
        // otherwise the resubmission is legitimately blocked on documents.
        AdmissionDocumentRequirement::create([
            'school_id'   => $this->schoolId,
            'key'         => 'optional_extra',
            'label'       => 'Optional Extra',
            'is_required' => false,
        ]);

        $this->be($applicant, 'applicant');

        $this->post(route('applicant.application.submit'), ['declaration' => '1']);

        $admission = Admission::find($id);

        $this->assertSame(Admission::STATUS_SUBMITTED, $admission->status);
        $this->assertNull($admission->correction_note);
    }

    public function test_index_can_be_filtered_to_applications_with_a_pending_document(): void
    {
        $withPendingDoc = $this->makeSubmittedAdmission(['app_number' => 'PIIE-2526-S-P1001', 'first_name' => 'HasPending']);
        $withoutPendingDoc = $this->makeSubmittedAdmission(['app_number' => 'PIIE-2526-S-P1002', 'first_name' => 'NoPending']);

        AdmissionDocument::create([
            'school_id'    => $this->schoolId,
            'admission_id' => $withPendingDoc->id,
            'original_name' => 'a.pdf',
            'stored_name'  => 'stored-a.pdf',
            'status'       => AdmissionDocument::STATUS_PENDING,
        ]);

        AdmissionDocument::create([
            'school_id'    => $this->schoolId,
            'admission_id' => $withoutPendingDoc->id,
            'original_name' => 'b.pdf',
            'stored_name'  => 'stored-b.pdf',
            'status'       => AdmissionDocument::STATUS_VERIFIED,
        ]);

        $admin = $this->makeAdminUser($this->schoolId);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.index', ['has_pending_documents' => 1]));

        $response->assertStatus(200);
        $response->assertSee('HasPending');
        $response->assertDontSee('NoPending');
    }

    public function test_rejecting_a_document_requires_a_reason(): void
    {
        $admission = $this->makeSubmittedAdmission();
        $admin     = $this->makeAdminUser($this->schoolId);

        $document = AdmissionDocument::create([
            'school_id'     => $this->schoolId,
            'admission_id'  => $admission->id,
            'requirement_key' => 'national_id',
            'original_name' => 'id.pdf',
            'stored_name'   => 'stored.pdf',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.hei_admissions.document.review', $document->id), [
                'status' => AdmissionDocument::STATUS_REJECTED,
            ])
            ->assertSessionHas('error');

        $this->assertSame(AdmissionDocument::STATUS_PENDING, $document->fresh()->status);
    }

    public function test_verifying_a_document_stamps_the_reviewer(): void
    {
        $admission = $this->makeSubmittedAdmission();
        $admin     = $this->makeAdminUser($this->schoolId);

        $document = AdmissionDocument::create([
            'school_id'     => $this->schoolId,
            'admission_id'  => $admission->id,
            'original_name' => 'id.pdf',
            'stored_name'   => 'stored.pdf',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.document.review', $document->id), [
            'status' => AdmissionDocument::STATUS_VERIFIED,
        ]);

        $document = $document->fresh();

        $this->assertSame(AdmissionDocument::STATUS_VERIFIED, $document->status);
        $this->assertSame($admin->id, (int) $document->reviewed_by);
        $this->assertNotNull($document->reviewed_at);
    }

    public function test_confirming_a_payment_recomputes_the_cached_fee_status(): void
    {
        $admission = $this->makeSubmittedAdmission([
            'intake_session_id' => $this->makeIntakeSession($this->schoolId, ['application_fee' => 50000]),
            'fee_status'        => Admission::FEE_PENDING,
        ]);

        $payment = ApplicationPayment::create([
            'school_id'    => $this->schoolId,
            'admission_id' => $admission->id,
            'amount'       => 50000,
            'method'       => 'offline',
            'status'       => ApplicationPayment::STATUS_PENDING,
            'reference'    => 'TXN-1',
        ]);

        $admin = $this->makeAdminUser($this->schoolId);

        $this->actingAs($admin)->post(route('admin.hei_admissions.payment.review', $payment->id), [
            'status' => ApplicationPayment::STATUS_PAID,
        ]);

        $this->assertSame(ApplicationPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(Admission::FEE_PAID, $admission->fresh()->fee_status);
    }

    public function test_enrolment_links_the_applicant_account_to_the_new_student(): void
    {
        $applicant = $this->makeApplicant($this->schoolId, ['email' => 'enrolme@example.com']);

        $id = $this->makeAdmission($this->schoolId, [
            'applicant_id' => $applicant->id,
            'app_number'   => 'PIIE-2526-S-P0003',
            'email'        => 'enrolme@example.com',
            'status'       => Admission::STATUS_ACCEPTED,
            'programme_id' => $this->makeProgramme($this->schoolId),
        ]);

        $this->completeAdmissionForDecision(Admission::findOrFail($id));
        $admin = $this->makeAdminUser($this->schoolId);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $id), [
            'status' => Admission::STATUS_ENROLLED,
        ]);

        $student = DB::table('users')->where('email', 'enrolme@example.com')->where('role_id', 7)->first();

        $this->assertNotNull($student);
        $this->assertSame((int) $student->id, (int) Applicant::find($applicant->id)->converted_user_id);
    }
}
