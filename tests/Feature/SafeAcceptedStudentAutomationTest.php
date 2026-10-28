<?php

namespace Tests\Feature;

use App\Models\{Admission, StudentFeeManager, StudentProfile, User};
use App\Support\Automation\AcceptedStudentSetup;
use App\Support\Admissions\ApplicationWorkflow;
use Illuminate\Support\Facades\{DB, Mail, Schema};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class SafeAcceptedStudentAutomationTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require database_path('migrations/2026_10_28_190000_create_safe_automation_state.php'))->up();
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        Mail::fake();
    }

    private function accepted(array $changes = []): Admission
    {
        $school = $this->makeSchool();
        $session = $this->makeAcademicSession($school);
        DB::table('schools')->where('id', $school)->update(['running_session' => $session]);
        $admission = Admission::findOrFail($this->makeAdmission($school, ['status' => 'accepted'] + $changes));
        return $this->completeAdmissionForDecision($admission);
    }

    private function fee(Admission $admission, array $changes = []): int
    {
        return $this->makeFeeStructure($admission->school_id, $changes + ['programme_id' => $admission->programme_id, 'per_semester' => 0]);
    }

    private function runSetup(Admission $admission): void
    {
        app(AcceptedStudentSetup::class)->run((int) $admission->school_id, (int) $admission->id);
    }

    public function test_authorized_acceptance_creates_account_profile_and_unpaid_invoice_without_academic_placement(): void
    {
        $admission = $this->accepted();
        $this->fee($admission);
        $admission->update(['status' => 'submitted']);
        $admin = $this->makeAdminUser($admission->school_id);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'accepted'])->assertRedirect();
        $student = User::findOrFail($admission->fresh()->automation_student_id);
        $this->assertSame(7, (int) $student->role_id);
        $this->assertNotEmpty($student->code);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id, 'programme_id' => $admission->programme_id]);
        $this->assertDatabaseHas('student_fee_managers', ['student_id' => $student->id, 'status' => 'unpaid', 'paid_amount' => 0]);
        $this->assertSame('accepted', $admission->fresh()->status);
        $this->assertSame(0, DB::table('enrollment')->count());
        $activations = DB::table('applicant_notification_deliveries')->get()->filter(function ($row) {
            $message = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($row->encrypted_message), true);
            return $message['mail_type'] === 'student_activation' && $message['data']['student_password_setup'] === true && $message['data']['password'] === null;
        });
        $this->assertCount(1, $activations);
    }

    public function test_retries_preserve_credentials_profiles_invoices_and_notification_history(): void
    {
        $admission = $this->accepted();
        $this->fee($admission);
        $this->runSetup($admission);
        $student = User::findOrFail($admission->fresh()->automation_student_id);
        $profile = StudentProfile::where('user_id', $student->id)->firstOrFail();
        $invoice = StudentFeeManager::where('student_id', $student->id)->firstOrFail();
        $invoice->update(['paid_amount' => 250, 'status' => 'unpaid']);
        $before = [$student->getAttributes(), $profile->getAttributes(), $invoice->fresh()->getAttributes()];
        $notifications = DB::table('applicant_notification_deliveries')->count();
        $this->travel(15)->minutes();
        $this->runSetup($admission);
        $this->runSetup($admission);
        $this->assertSame($before, [$student->fresh()->getAttributes(), $profile->fresh()->getAttributes(), $invoice->fresh()->getAttributes()]);
        $this->assertSame($notifications, DB::table('applicant_notification_deliveries')->count());
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertSame(1, StudentProfile::count());
    }

    public function test_missing_fee_is_queued_and_prepared_after_finance_configuration_arrives(): void
    {
        $admission = $this->accepted();
        $this->runSetup($admission);
        $this->assertDatabaseHas('automation_exceptions', ['workflow' => 'mandatory_invoice', 'subject_id' => $admission->id, 'code' => 'mandatory_fee_missing', 'resolved_at' => null]);
        $this->assertSame(0, StudentFeeManager::count());
        $this->fee($admission);
        $this->artisan('admissions:prepare-accepted-students', ['--school' => $admission->school_id])->assertSuccessful();
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertNotNull(DB::table('automation_exceptions')->where('workflow', 'mandatory_invoice')->value('resolved_at'));
    }

    public function test_foreign_or_inactive_session_never_creates_an_invoice_and_can_be_retried(): void
    {
        $admission = $this->accepted();
        $this->fee($admission);
        $other = $this->makeSchool();
        $session = $this->makeAcademicSession($other);
        DB::table('schools')->where('id', $admission->school_id)->update(['running_session' => $session]);
        $this->runSetup($admission);
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'finance_session_invalid']);
        $session = $this->makeAcademicSession($admission->school_id, ['status' => 0]);
        DB::table('schools')->where('id', $admission->school_id)->update(['running_session' => $session]);
        $this->runSetup($admission);
        $this->assertSame(0, StudentFeeManager::count());
        DB::table('sessions')->where('id', $session)->update(['status' => 1]);
        $this->runSetup($admission);
        $this->assertSame(1, StudentFeeManager::count());
    }

    public function test_ambiguous_semester_or_invalid_amount_stops_all_invoice_writes(): void
    {
        $admission = $this->accepted();
        $this->fee($admission);
        $bad = $this->fee($admission, ['name' => 'Semester tuition', 'per_semester' => 1]);
        $this->runSetup($admission);
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'semester_billing_ambiguous']);
        $student = User::findOrFail($admission->fresh()->automation_student_id);
        \App\Support\StudentFeeInvoiceGenerator::ensureForStudent($student);
        $this->assertSame(0, StudentFeeManager::count(), 'Opening Fee Manager must not bypass the Finance blocker.');
        DB::table('fee_structures')->where('id', $bad)->update(['per_semester' => 0, 'amount' => -1]);
        $this->runSetup($admission);
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'mandatory_fee_invalid']);
    }

    public function test_existing_email_is_not_identity_authority_and_other_tenant_records_stay_unchanged(): void
    {
        $admission = $this->accepted();
        $other = $this->makeSchool();
        $student = User::factory()->create(['email' => $admission->email, 'school_id' => $other, 'role_id' => 7]);
        $before = $student->fresh()->getAttributes();
        $application = $admission->getAttributes();
        $this->runSetup($admission);
        $this->assertSame($before, $student->fresh()->getAttributes());
        $this->assertSame($application, $admission->fresh()->getAttributes());
        $this->assertSame(0, StudentProfile::count());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'identity_requires_review']);
        Mail::assertNothingSent();
    }

    public function test_transfer_is_not_reversed_by_a_retry(): void
    {
        $admission = $this->accepted();
        $this->runSetup($admission);
        $profile = StudentProfile::firstOrFail();
        $profile->update(['programme_id' => $this->makeProgramme($admission->school_id, ['code' => 'OTHER'])]);
        $before = $profile->fresh()->getAttributes();
        $this->fee($admission);
        $this->runSetup($admission);
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'profile_requires_review']);
    }

    public function test_stale_paid_cache_does_not_authorize_account_creation(): void
    {
        $admission = $this->accepted();
        $admission->forceFill(['application_fee_amount' => 100, 'application_fee_currency' => 'UGX', 'fee_status' => 'paid'])->save();
        $before = $admission->getAttributes();
        $this->runSetup($admission);
        $this->assertSame(0, User::where('role_id', 7)->count());
        $this->assertSame($before, $admission->fresh()->getAttributes());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'acceptance_requirements_missing']);
    }

    public function test_missing_registration_data_rolls_back_account_and_records_an_actionable_exception(): void
    {
        $admission = $this->accepted();
        DB::table('programmes')->where('id', $admission->programme_id)->update(['code' => '']);
        $before = $admission->getAttributes();
        $this->runSetup($admission);
        $this->assertSame($before, $admission->fresh()->getAttributes());
        $this->assertSame(0, User::where('role_id', 7)->count());
        $this->assertSame(0, StudentProfile::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'registration_details_missing']);
    }

    public function test_unaccepted_and_historical_enrolled_applications_are_not_changed(): void
    {
        $admission = $this->accepted();
        foreach (['submitted', 'withdrawn', 'enrolled'] as $status) {
            $admission->update(['status' => $status]);
            $before = $admission->getAttributes();
            $this->runSetup($admission);
            $this->assertSame($before, $admission->fresh()->getAttributes());
        }
        $this->assertSame(0, User::where('role_id', 7)->count());
        $this->assertSame(0, DB::table('automation_exceptions')->count());
    }

    public function test_policy_blockers_are_deduplicated_and_exception_listing_is_tenant_scoped(): void
    {
        $admission = $this->accepted();
        $other = $this->accepted(['email' => 'second-tenant@example.test']);
        foreach ([$admission, $other] as $row) {
            $this->artisan('admissions:prepare-accepted-students', ['--school' => $row->school_id])->assertSuccessful();
        }
        $this->artisan('admissions:prepare-accepted-students', ['--school' => $admission->school_id])->assertSuccessful();
        $this->assertSame(6, DB::table('automation_exceptions')->where('school_id', $admission->school_id)->where('subject_id', 0)->count());
        $this->assertSame(2, (int) DB::table('automation_exceptions')->where('school_id', $admission->school_id)->where('workflow', 'result_publication')->value('occurrences'));
        $this->artisan('automation:exceptions')->assertExitCode(2);
        $this->artisan('automation:exceptions', ['--school' => $admission->school_id])->expectsOutputToContain('Finance / Bursar')->assertSuccessful();
        $this->assertSame(0, DB::table('enrollment')->count());
    }

    public function test_other_school_and_student_cannot_trigger_acceptance(): void
    {
        $admission = $this->accepted();
        $admission->update(['status' => 'submitted']);
        $otherAdmin = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($otherAdmin)->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'accepted'])->assertNotFound();
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $admission->school_id]);
        $this->actingAs($student)->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'accepted'])->assertRedirect();
        $this->assertNull($admission->fresh()->automation_student_id);
        $this->assertSame('submitted', $admission->fresh()->status);
        $this->assertSame(0, StudentProfile::count());
    }

    public function test_only_applicable_mandatory_programme_fees_are_prepared_and_legacy_invoices_are_preserved(): void
    {
        $admission = $this->accepted();
        $good = $this->fee($admission, ['name' => 'Approved tuition']);
        $this->fee($admission, ['is_mandatory' => 0, 'name' => 'Elective trip']);
        $this->fee($admission, ['programme_id' => $this->makeProgramme($admission->school_id), 'name' => 'Other programme']);
        $this->fee($admission, ['class_id' => $this->makeClass($admission->school_id), 'name' => 'Class fee']);
        $this->fee($admission, ['session_id' => $this->makeAcademicSession($admission->school_id), 'name' => 'Other session']);
        $other = $this->makeSchool();
        $this->makeFeeStructure($other, ['name' => 'Other institution', 'per_semester' => 0]);
        $this->runSetup($admission);
        $this->assertSame([$good], StudentFeeManager::pluck('fee_structure_id')->map(fn ($id) => (int) $id)->all());
        $invoice = StudentFeeManager::firstOrFail();
        $invoice->update(['fee_structure_id' => null, 'paid_amount' => 75]);
        $before = $invoice->fresh()->getAttributes();
        $this->runSetup($admission);
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertSame($before, $invoice->fresh()->getAttributes());
    }

    public function test_session_mapped_to_a_different_academic_year_is_blocked_before_invoice_writes(): void
    {
        Schema::table('schools', fn ($table) => $table->unsignedBigInteger('current_academic_year_id')->nullable());
        Schema::table('sessions', fn ($table) => $table->unsignedBigInteger('academic_year_id')->nullable());
        $admission = $this->accepted();
        $this->fee($admission);
        DB::table('schools')->where('id', $admission->school_id)->update(['current_academic_year_id' => 12]);
        DB::table('sessions')->where('school_id', $admission->school_id)->update(['academic_year_id' => 13]);
        $this->runSetup($admission);
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'finance_session_invalid']);
    }

    public function test_unexpected_invoice_failure_rolls_back_setup_and_sends_no_activation_then_retry_succeeds(): void
    {
        $admission = $this->accepted();
        $this->fee($admission);
        DB::unprepared("CREATE TRIGGER reject_test_invoice BEFORE INSERT ON student_fee_managers BEGIN SELECT RAISE(ABORT, 'test invoice failure'); END");
        $before = $admission->getAttributes();
        $this->artisan('admissions:prepare-accepted-students', ['--school' => $admission->school_id])->assertFailed();
        $this->assertSame($before, $admission->fresh()->getAttributes());
        $this->assertSame(0, User::where('role_id', 7)->count());
        $this->assertSame(0, StudentProfile::count());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertSame(0, DB::table('applicant_notification_deliveries')->count());
        $this->assertDatabaseHas('automation_exceptions', ['workflow' => 'accepted_student', 'code' => 'preparation_failed']);
        Mail::assertNothingSent();
        DB::unprepared('DROP TRIGGER reject_test_invoice');
        $this->artisan('admissions:prepare-accepted-students', ['--school' => $admission->school_id])->assertSuccessful();
        $this->assertSame(1, User::where('role_id', 7)->count());
        $this->assertSame(1, StudentFeeManager::count());
        $this->assertNotNull(DB::table('automation_exceptions')->where('workflow', 'accepted_student')->value('resolved_at'));
    }

    public function test_admissions_review_shows_scoped_responsibility_without_exposing_other_institutions(): void
    {
        $admission = $this->accepted();
        $this->runSetup($admission);
        $other = $this->makeSchool();
        \App\Support\Automation\AutomationExceptions::block($other, 'mandatory_invoice', $admission->id,
            'foreign', 'FOREIGN PRIVATE EXCEPTION', 'Other Finance');
        $admin = $this->makeAdminUser($admission->school_id);
        $this->actingAs($admin)->get(route('admin.hei_admissions.review', $admission->id))
            ->assertOk()->assertSee('Finance / Bursar')->assertSee('approved mandatory programme fee')->assertDontSee('FOREIGN PRIVATE EXCEPTION');
        $this->artisan('automation:exceptions', ['--school' => $admission->school_id])
            ->doesntExpectOutputToContain('FOREIGN PRIVATE EXCEPTION')->assertSuccessful();
    }

    public function test_unpaid_or_incomplete_acceptance_creates_no_account_and_preserves_application(): void
    {
        $admission = $this->accepted();
        $admission->update(['status' => 'submitted', 'submitted_at' => null]);
        $before = $admission->fresh()->getAttributes();
        $admin = $this->makeAdminUser($admission->school_id);
        $this->actingAs($admin)->postJson(route('admin.hei_admissions.status', $admission->id), ['status' => 'accepted'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($before, $admission->fresh()->getAttributes());
        $this->assertSame(0, User::where('role_id', 7)->count());
        $this->assertSame(0, StudentFeeManager::count());
    }

    public function test_later_manual_enrolment_does_not_overwrite_a_transferred_profile_or_bypass_finance_review(): void
    {
        $admission = $this->accepted();
        $this->fee($admission, ['per_semester' => 1]);
        $this->runSetup($admission);
        $profile = StudentProfile::firstOrFail();
        $profile->update(['programme_id' => $this->makeProgramme($admission->school_id, ['code' => 'TRANSFER'])]);
        $before = $profile->fresh()->getAttributes();
        $admin = $this->makeAdminUser($admission->school_id);
        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admission->id), ['status' => 'enrolled'])->assertRedirect();
        $this->assertSame('enrolled', $admission->fresh()->status);
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertSame(0, StudentFeeManager::count());
        $this->runSetup($admission);
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'profile_requires_review']);
        $this->assertSame(0, StudentFeeManager::count());
    }

    public function test_inactive_profile_is_not_reactivated_or_invoiced_when_finance_setup_arrives(): void
    {
        $admission = $this->accepted();
        $this->runSetup($admission);
        $profile = StudentProfile::firstOrFail();
        $profile->update(['status' => 'inactive']);
        $before = $profile->fresh()->getAttributes();
        $this->fee($admission);
        $this->runSetup($admission);
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertSame(0, StudentFeeManager::count());
        $this->assertDatabaseHas('automation_exceptions', ['code' => 'profile_requires_review']);
        $billing = \App\Support\StudentFeeInvoiceGenerator::prepareMandatoryForStudent(User::findOrFail($profile->user_id));
        $this->assertSame('profile_inactive', $billing['code']);
        $this->assertSame(0, StudentFeeManager::count());
    }
}
