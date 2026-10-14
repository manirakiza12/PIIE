<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Models\Admission;
use App\Models\AdmissionLetter;
use App\Models\User;
use App\Support\Admissions\AdmissionLetterDocument;
use App\Support\Admissions\ApplicantNotificationDelivery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdmissionLetterDeliveryTest extends TestCase
{
    use AdmissionsTestHelper;
    protected int $school;
    protected User $admin;
    protected Admission $admission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        foreach (['2026_10_13_120000_create_admission_letters_table.php', '2026_10_13_121000_add_admission_letter_dates_to_intake_sessions.php', '2026_10_08_210000_create_applicant_notification_deliveries_table.php'] as $migration) (require database_path('migrations/'.$migration))->up();
        foreach (['school_currency', 'address', 'phone', 'email'] as $column) {
            if (! Schema::hasColumn('schools', $column)) Schema::table('schools', fn (Blueprint $table) => $table->string($column)->nullable());
        }
        $this->school = $this->makeSchool(['title' => 'Prime International Institute of Excellence', 'school_currency' => 'UGX', 'address' => 'Kampala, Uganda']);
        $this->admin = $this->makeAdminUser($this->school);
        $programme = $this->makeProgramme($this->school, ['code' => 'MPAM', 'name' => 'Master of Public Administration and Management', 'mode' => 'ODEL', 'duration' => 'Two academic years']);
        $intake = $this->makeIntakeSession($this->school, ['name' => 'January 2027 Intake', 'application_fee' => 0, 'start_date' => '2027-01-11', 'orientation_start_date' => '2027-01-12', 'orientation_end_date' => '2027-01-13', 'registration_deadline' => '2027-02-08']);
        $this->makeFeeStructure($this->school, ['programme_id' => $programme, 'name' => 'Semester tuition', 'amount' => 900000, 'fee_type' => 'tuition']);
        $this->makeFeeStructure($this->school, ['programme_id' => $programme, 'name' => 'Library access', 'amount' => 30000, 'fee_type' => 'other']);
        $this->admission = Admission::findOrFail($this->makeAdmission($this->school, ['programme_id' => $programme, 'intake_session_id' => $intake, 'email' => 'synthetic.letter@example.test', 'first_name' => 'Alex', 'last_name' => 'Example', 'nationality' => 'Ugandan', 'status' => 'accepted']));
        $this->completeAdmissionForDecision($this->admission);
        $this->enableSmtpSettings();
        Mail::fake();
    }

    protected function enrol(): AdmissionLetter
    {
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.status', $this->admission->id), ['status' => 'enrolled'])->assertRedirect();
        return AdmissionLetter::where('admission_id', $this->admission->id)->firstOrFail();
    }

    public function test_enrolment_archives_a_branded_letter_and_attaches_it_to_the_confirmation(): void
    {
        $letter = $this->enrol();
        $snapshot = $letter->snapshot;
        $student = User::findOrFail($letter->student_id);
        $this->assertSame($student->code, $snapshot['registration_number']);
        $this->assertSame('2027/01/MPAM/001/', $student->code);
        $this->assertSame('info@piie.ac.ug', $snapshot['email']);
        $this->assertSame('https://piie.ac.ug/', $snapshot['website']);
        $this->assertSame('Online Distance Learning', $snapshot['mode']);
        $this->assertSame('11 January 2027', $snapshot['start_date']);
        $this->assertStringStartsWith('data:image/png;base64,', $snapshot['logo']);
        $this->assertCount(2, $snapshot['fees']);
        $this->assertStringNotContainsString('Alex', DB::table('admission_letters')->where('id', $letter->id)->value('snapshot'));
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) use ($student) {
            if (empty($mail->data['admission_letter'])) return false;
            $this->assertSame($student->code, $mail->data['admission_letter']['registration_number']);
            $mail->build();
            $this->assertCount(1, $mail->rawAttachments);
            $this->assertStringStartsWith('%PDF-', $mail->rawAttachments[0]['data']);
            $this->assertSame('application/pdf', $mail->rawAttachments[0]['options']['mime']);
            return true;
        });
    }

    public function test_repeated_enrolment_does_not_issue_another_letter_or_confirmation(): void
    {
        $letter = $this->enrol();
        $count = DB::table('applicant_notification_deliveries')->count();
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.status', $this->admission->id), ['status' => 'enrolled'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.status', $this->admission->id), ['status' => 'enrolled', 'decision_note' => 'Administrative note updated'])->assertRedirect();
        $this->assertSame(1, AdmissionLetter::count());
        $this->assertSame($count, DB::table('applicant_notification_deliveries')->count());
        $this->assertSame($letter->snapshot, AdmissionLetterDocument::forAdmission($this->admission->fresh()));
    }

    public function test_letter_does_not_change_when_fees_or_programme_names_change_later(): void
    {
        $letter = $this->enrol();
        DB::table('fee_structures')->where('school_id', $this->school)->update(['amount' => 1]);
        DB::table('programmes')->where('id', $this->admission->programme_id)->update(['name' => 'Changed after issue']);
        $this->assertSame($letter->snapshot, AdmissionLetterDocument::forAdmission($this->admission->fresh()));
    }

    public function test_rollback_discards_letter_and_all_notifications(): void
    {
        DB::beginTransaction();
        $this->enrol();
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertSame(0, AdmissionLetter::count());
        $this->assertSame(0, DB::table('applicant_notification_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_a_student_can_download_only_their_own_archived_letter(): void
    {
        $letter = $this->enrol();
        $student = User::findOrFail($letter->student_id);
        $student->update(['force_password_change' => false]);
        $this->actingAs($student)->get(route('student.admission_letter'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = User::factory()->create(['school_id' => $this->school, 'role_id' => 7, 'force_password_change' => false, 'account_status' => 'active']);
        $this->actingAs($other)->get(route('student.admission_letter'))->assertNotFound();
    }

    public function test_pending_delivery_uses_original_letter_and_rejects_a_changed_recipient(): void
    {
        DB::table('global_settings')->where('key', 'smtp_host')->update(['value' => '']);
        $letter = $this->enrol();
        $row = DB::table('applicant_notification_deliveries')->where('school_id', $this->school)->orderByDesc('id')->first();
        $this->admission->update(['email' => 'changed@example.test']);
        $this->enableSmtpSettings();
        DB::table('applicant_notification_deliveries')->where('id', $row->id)->update(['available_at' => now()->subMinute()]);
        $this->assertFalse(ApplicantNotificationDelivery::deliver($row->id));
        $this->assertNull(DB::table('applicant_notification_deliveries')->where('id', $row->id)->value('sent_at'));
        Mail::assertNothingSent();
        $this->assertSame('Alex Example', $letter->snapshot['name']);
    }

    public function test_failed_mail_configuration_can_retry_the_same_letter_without_reenrolment(): void
    {
        DB::table('global_settings')->where('key', 'smtp_host')->update(['value' => '']);
        $letter = $this->enrol();
        $row = DB::table('applicant_notification_deliveries')->where('school_id', $this->school)->orderByDesc('id')->first();
        DB::table('fee_structures')->where('school_id', $this->school)->update(['amount' => 1]);
        $this->enableSmtpSettings();
        DB::table('applicant_notification_deliveries')->where('id', $row->id)->update(['available_at' => now()->subMinute()]);
        $this->assertTrue(ApplicantNotificationDelivery::deliver($row->id));
        Mail::assertSent(ApplicantNotificationEmail::class, fn ($mail) => ($mail->data['admission_letter'] ?? []) === $letter->snapshot);
        $this->assertSame(1, AdmissionLetter::count());
        $this->assertNotNull(DB::table('applicant_notification_deliveries')->where('id', $row->id)->value('sent_at'));
    }

    public function test_letter_fees_exclude_other_schools_programmes_and_classes(): void
    {
        $otherSchool = $this->makeSchool();
        $this->makeFeeStructure($otherSchool, ['name' => 'Foreign school fee', 'amount' => 1]);
        $otherProgramme = $this->makeProgramme($this->school, ['code' => 'OTHER']);
        $this->makeFeeStructure($this->school, ['programme_id' => $otherProgramme, 'name' => 'Other programme fee', 'amount' => 1]);
        $this->makeFeeStructure($this->school, ['programme_id' => $this->admission->programme_id, 'class_id' => 999, 'name' => 'Other class fee', 'amount' => 1]);
        $letter = $this->enrol();
        $this->assertSame(['Semester tuition', 'Library access'], array_column($letter->snapshot['fees'], 'name'));
    }

    public function test_unpaid_application_cannot_be_enrolled_or_receive_a_letter(): void
    {
        DB::table('intake_sessions')->where('id', $this->admission->intake_session_id)->update(['application_fee' => 50000]);
        $this->admission->forceFill(['application_fee_amount' => 50000, 'application_fee_currency' => 'UGX', 'fee_status' => 'unpaid'])->save();
        $this->actingAs($this->admin)->post(route('admin.hei_admissions.status', $this->admission->id), ['status' => 'enrolled'])->assertUnprocessable();
        $this->assertSame(0, AdmissionLetter::count());
        Mail::assertNothingSent();
    }

    public function test_intake_letter_dates_are_saved_and_invalid_orientation_dates_are_rejected(): void
    {
        $intake = \App\Models\IntakeSession::findOrFail($this->admission->intake_session_id);
        $payload = ['name' => $intake->name, 'is_open' => 1, 'start_date' => '2027-01-11', 'orientation_start_date' => '2027-01-12', 'orientation_end_date' => '2027-01-13', 'registration_deadline' => '2027-02-08'];
        $this->actingAs($this->admin)->postJson(route('admin.intake_sessions.update', $intake->id), $payload)->assertRedirect();
        $this->assertSame('2027-02-08', $intake->fresh()->registration_deadline);
        $payload['orientation_end_date'] = '2027-01-01';
        $this->postJson(route('admin.intake_sessions.update', $intake->id), $payload)->assertUnprocessable()->assertJsonValidationErrors('orientation_end_date');
        $this->assertSame('2027-01-13', $intake->fresh()->orientation_end_date);
    }
}
