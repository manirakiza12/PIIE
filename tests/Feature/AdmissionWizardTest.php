<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDocument;
use App\Models\AdmissionDocumentRequirement;
use App\Models\AdmissionQualification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdmissionWizardTest extends TestCase
{
    use AdmissionsTestHelper;

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

    public function test_opening_student_entry_does_not_create_blank_drafts_and_offers_existing_students(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.create'))
            ->assertOk()->assertSee('Add Existing Student')->assertSee('Start New Application')
            ->assertSee(route('admin.student.open_modal'), false);
        $this->get(route('admin.hei_admissions.wizard.create'))->assertOk();
        $this->assertSame(0, Admission::count());
        $this->assertSame(0, DB::table('admission_status_events')->count());
    }

    public function test_draft_list_offers_completion_instead_of_invalid_status_changes(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $id = $this->makeAdmission($school, ['status' => 'draft', 'source' => 'staff_entry', 'submitted_at' => null]);
        $this->actingAs($admin)->get(route('admin.hei_admissions.index', ['status' => 'draft']))
            ->assertOk()->assertSee('Complete Application')->assertSee('Add Existing Student')
            ->assertDontSee('name="status" class="form-select form-select-sm"', false);
        $this->assertSame('draft', Admission::findOrFail($id)->status);
    }

    public function test_admin_can_create_a_staff_entry_draft_and_is_sent_to_step_one(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.start'));

        $admission = Admission::first();
        $this->assertNotNull($admission, 'Creating a wizard application must create an Admission row.');
        $this->assertSame('staff_entry', $admission->source);
        $this->assertSame(Admission::STATUS_DRAFT, $admission->status);
        $this->assertNull($admission->applicant_id);
        $response->assertRedirect(route('admin.hei_admissions.wizard.step', [$admission->id, 'personal']));
    }

    public function test_admin_completes_personal_information_step(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.personal', $admissionId), [
            'first_name' => 'Grace',
            'last_name' => 'Candidate',
            'email' => 'grace.candidate@example.com',
            'phone_code' => '+256',
            'phone_number' => '0700555666',
            'dob' => '1999-05-10',
            'gender' => 'Female',
            'nationality' => 'Ugandan',
            'physical_address' => 'Plot 9, Kampala',
            'nok_name' => 'John Candidate',
            'nok_relationship' => 'Father',
            'nok_phone' => '0700777888',
            'action' => 'continue',
        ]);

        $response->assertRedirect(route('admin.hei_admissions.wizard.step', [$admissionId, 'programme']));

        $admission = Admission::find($admissionId);
        $this->assertSame('Grace', $admission->first_name);
        $this->assertSame('grace.candidate@example.com', $admission->email);
        $this->assertSame('+256 0700555666', $admission->phone);
    }

    public function test_admin_completes_programme_selection_step(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.programme', $admissionId), [
            'programme_id' => $programmeId,
            'intake_session_id' => $intakeId,
            'action' => 'continue',
        ]);

        $response->assertRedirect(route('admin.hei_admissions.wizard.step', [$admissionId, 'education']));

        $admission = Admission::find($admissionId);
        $this->assertSame($programmeId, $admission->programme_id);
        $this->assertSame($intakeId, $admission->intake_session_id);
    }

    public function test_admin_adds_structured_education_history(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.education', $admissionId), [
            'education' => [
                ['institution' => 'Makerere University', 'award' => 'BSc', 'start_year' => 2018, 'end_year' => 2021],
            ],
            'action' => 'save',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, AdmissionQualification::where('admission_id', $admissionId)->count());
        $this->assertSame('Makerere University', AdmissionQualification::where('admission_id', $admissionId)->first()->institution);
    }

    public function test_admin_can_resume_a_draft_and_see_its_saved_data(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'status' => Admission::STATUS_DRAFT,
            'first_name' => 'Resumed',
            'last_name' => 'Candidate',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'personal']));

        $response->assertStatus(200);
        $response->assertSee('Resumed');
    }

    public function test_progress_percentage_increases_as_steps_are_completed(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT, 'first_name' => '', 'last_name' => 'X']);

        $before = $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'personal']));
        $before->assertViewHas('percent', 0);

        $this->completeApplicationFields(Admission::find($admissionId), ['first_name' => 'Prog', 'last_name' => 'Ress']);

        $after = $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'personal']));
        $this->assertGreaterThan(0, $after->viewData('percent'));
    }

    public function test_wizard_cannot_edit_an_application_that_has_already_been_decided(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_ACCEPTED]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.personal', $admissionId), [
            'first_name' => 'Should Not',
            'last_name' => 'Save',
            'email' => 'blocked@example.com',
            'phone_code' => '+256',
            'phone_number' => '0700000000',
            'dob' => '1999-01-01',
            'gender' => 'Male',
            'nationality' => 'Ugandan',
            'physical_address' => 'Somewhere',
            'nok_name' => 'X',
            'nok_relationship' => 'Y',
            'nok_phone' => '0700000000',
        ]);

        $response->assertRedirect(route('admin.hei_admissions.review', $admissionId));
        $this->assertNotSame('Should Not', Admission::find($admissionId)->first_name);
    }

    public function test_staff_entry_wizard_locks_after_submission_until_the_fee_is_paid(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'source' => 'staff_entry',
            'status' => Admission::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'application_fee_amount' => 50000,
            'application_fee_currency' => 'UGX',
            'fee_status' => Admission::FEE_UNPAID,
        ]);

        $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'personal']))
            ->assertOk()
            ->assertViewHas('readOnly', true);

        $this->post(route('admin.hei_admissions.wizard.personal', $admissionId), [
            'first_name' => 'Changed',
            'last_name' => 'Too Soon',
            'email' => 'blocked@example.test',
            'phone_code' => '+256',
            'phone_number' => '0700000000',
            'dob' => '1999-01-01',
            'gender' => 'Male',
            'nationality' => 'Ugandan',
            'physical_address' => 'Kampala',
            'nok_name' => 'Kin',
            'nok_relationship' => 'Parent',
            'nok_phone' => '0700000001',
        ])->assertRedirect(route('admin.hei_admissions.review', $admissionId));

        $this->assertNotSame('Changed', Admission::findOrFail($admissionId)->first_name);
    }

    public function test_admin_can_upload_a_supporting_document_attributed_to_staff(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        AdmissionDocumentRequirement::create([
            'school_id' => $schoolId,
            'key' => 'national_id',
            'label' => 'National ID',
            'is_required' => true,
            'allow_multiple' => false,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.documents.store', $admissionId), [
            'requirement_key' => 'national_id',
            'files' => [UploadedFile::fake()->create('id.pdf', 40, 'application/pdf')],
        ]);

        $response->assertSessionHasNoErrors();

        $document = AdmissionDocument::where('admission_id', $admissionId)->first();
        $this->assertNotNull($document, 'Uploading a document must create an AdmissionDocument row.');
        $this->assertSame('national_id', $document->requirement_key);
        $this->assertNotNull($document->uploaded_by_user_id, 'A staff upload must be attributed to the admin user, not the applicant.');
        $this->assertSame($admin->id, $document->uploaded_by_user_id);
        $this->assertNull($document->uploaded_by_applicant_id);

        @unlink($document->absolute_path);
    }

    public function test_required_document_validation_blocks_submission_until_supplied(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        AdmissionDocumentRequirement::create([
            'school_id' => $schoolId,
            'key' => 'passport_photo',
            'label' => 'Passport Photograph',
            'is_required' => true,
            'allow_multiple' => false,
        ]);

        $this->assertFalse(\App\Support\Admissions\ApplicationDocuments::isComplete(Admission::find($admissionId)));

        AdmissionDocument::create([
            'school_id' => $schoolId,
            'admission_id' => $admissionId,
            'requirement_key' => 'passport_photo',
            'original_name' => 'photo.jpg',
            'stored_name' => 'stored.jpg',
            'status' => AdmissionDocument::STATUS_PENDING,
        ]);

        $this->assertTrue(\App\Support\Admissions\ApplicationDocuments::isComplete(Admission::find($admissionId)));
    }

    public function test_admin_cannot_submit_an_incomplete_application(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        // Only first/last name — nowhere near complete.
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.submit', $admissionId));

        $response->assertRedirect();
        $this->assertSame(Admission::STATUS_DRAFT, Admission::find($admissionId)->status);
    }

    public function test_admin_can_submit_a_complete_staff_entry_application_into_the_admissions_queue(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'status' => Admission::STATUS_DRAFT,
            'programme_id' => $programmeId,
            'intake_session_id' => $intakeId,
        ]);
        $this->completeApplicationFields(Admission::find($admissionId));
        // No documents are required for this school, so the documents step
        // is trivially complete and does not block submission.
        AdmissionDocumentRequirement::create(['school_id' => $schoolId, 'key' => 'other', 'label' => 'Other', 'is_required' => false]);

        $response = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.submit', $admissionId));

        $response->assertRedirect(route('admin.hei_admissions.review', $admissionId));

        $admission = Admission::find($admissionId);
        $this->assertSame(Admission::STATUS_SUBMITTED, $admission->status);
        $this->assertNotNull($admission->submitted_at);
        $this->assertSame('staff_entry', $admission->source);

        $event = \App\Models\AdmissionStatusEvent::where('admission_id', $admissionId)->latest('id')->first();
        $this->assertSame('staff', $event->actor_type);
        $this->assertSame($admin->id, $event->actor_id);
    }

    public function test_full_wizard_walkthrough_from_create_to_submission(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId);
        AdmissionDocumentRequirement::create(['school_id' => $schoolId, 'key' => 'other', 'label' => 'Other', 'is_required' => false]);

        $create = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.start'));
        $admission = Admission::first();
        $admissionId = $admission->id;

        $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.personal', $admissionId), [
            'first_name' => 'Walk', 'last_name' => 'Through',
            'email' => 'walkthrough@example.com', 'phone_code' => '+256', 'phone_number' => '0700111222',
            'dob' => '2000-01-01', 'gender' => 'Male', 'nationality' => 'Ugandan',
            'physical_address' => 'Kampala', 'nok_name' => 'Kin', 'nok_relationship' => 'Uncle', 'nok_phone' => '0700333444',
            'action' => 'continue',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.programme', $admissionId), [
            'programme_id' => $programmeId, 'intake_session_id' => $intakeId, 'action' => 'continue',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.education', $admissionId), [
            'education' => [['institution' => 'Test High School', 'award' => 'A-Level', 'start_year' => 2016, 'end_year' => 2018]],
            'action' => 'continue',
        ]);

        $submit = $this->actingAs($admin)->post(route('admin.hei_admissions.wizard.submit', $admissionId));
        $submit->assertRedirect(route('admin.hei_admissions.review', $admissionId));

        $final = Admission::find($admissionId);
        $this->assertSame(Admission::STATUS_SUBMITTED, $final->status);
        $this->assertSame('Walk', $final->first_name);
        $this->assertSame($programmeId, $final->programme_id);
        $this->assertSame(1, \App\Models\AdmissionQualification::where('admission_id', $admissionId)->count());
    }

    public function test_wizard_review_step_shows_the_application_fee_and_send_payment_request_button(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $intakeId = $this->makeIntakeSession($schoolId, ['application_fee' => 45000]);
        $admissionId = $this->makeAdmission($schoolId, [
            'status' => Admission::STATUS_DRAFT,
            'app_number' => 'PIIE-2627-O-P7001',
            'intake_session_id' => $intakeId,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'review']));

        $response->assertStatus(200);
        $response->assertSee('Application Fee');
        $response->assertSee('PIIE-2627-O-P7001');
        $response->assertSee('Send Payment Request');
    }

    public function test_wizard_only_operates_on_staff_entry_admissions_not_public_ones(): void
    {
        $schoolId = $this->makeSchool();
        $this->configurePrimarySchool($schoolId);
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, ['status' => Admission::STATUS_DRAFT, 'source' => 'public']);

        $response = $this->actingAs($admin)->get(route('admin.hei_admissions.wizard.step', [$admissionId, 'personal']));

        $response->assertStatus(404);
    }
}
