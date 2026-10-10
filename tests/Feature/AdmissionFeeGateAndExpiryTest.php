<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\IntakeSession;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationWorkflow;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The institution's two admission rules:
 *  1. an application may not progress until its fee is settled, and
 *  2. a submitted application whose intake closed unpaid is expired afterwards.
 */
class AdmissionFeeGateAndExpiryTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;

    private IntakeSession $intake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->schoolId = $this->makeSchool();
        $this->intake = IntakeSession::create([
            'school_id' => $this->schoolId, 'name' => 'Test intake',
            'open_date' => now()->subDays(60)->toDateString(),
            'close_date' => now()->addDays(30)->toDateString(),
            'application_fee' => 50000, 'is_open' => 1,
        ]);
        config(['app.url' => 'https://piie.example.test']);
        // The fee currency is read from global_settings; without this a payment
        // row in UGX would never match the application's currency.
        \Illuminate\Support\Facades\DB::table('global_settings')->insert([
            ['key' => 'primary_school_id', 'value' => (string) $this->schoolId],
            ['key' => 'system_currency', 'value' => 'UGX'],
        ]);
    }

    private function submittedAdmission(array $overrides = []): Admission
    {
        // A programme must exist and be selected, and the required documents
        // must be on file, or ApplicationProgress::canSubmit() is false and the
        // fee gate could never be observed to lift.
        $programmeId = $overrides['programme_id'] ?? \App\Models\Programme::create([
            'school_id' => $this->schoolId, 'name' => 'Test programme', 'code' => 'TST1',
            'level' => 'Degree', 'mode' => 'fulltime', 'tuition_fee' => 0, 'is_active' => 1,
        ])->id;

        $admission = Admission::create(array_merge([
            'school_id' => $this->schoolId,
            'app_number' => 'TEST-' . uniqid(),
            'first_name' => 'Fee', 'last_name' => 'Gate',
            'email' => uniqid() . '@example.test', 'phone' => '+256700000000',
            'status' => Admission::STATUS_SUBMITTED, 'source' => 'public',
            'current_step' => 'review', 'submitted_at' => now(),
            'intake_session_id' => $this->intake->id, 'programme_id' => $programmeId,
            'dob' => '2000-01-01', 'gender' => 'Male', 'nationality' => 'Ugandan',
            'physical_address' => 'Kampala', 'nok_name' => 'Kin', 'nok_phone' => '+256700000001',
            'qualifications' => 'Cert',
        ], $overrides));
        ApplicationFee::freezeObligation($admission);
        ApplicationFee::refreshStatus($admission);
        $admission = $admission->fresh();

        foreach (\App\Support\Admissions\ApplicationDocuments::requirementsFor($admission) as $requirement) {
            \App\Models\AdmissionDocument::create([
                'school_id' => $admission->school_id, 'admission_id' => $admission->id,
                'requirement_key' => $requirement->key, 'label' => $requirement->label,
                'file_path' => 'test/' . $requirement->key . '.pdf',
                'stored_name' => $requirement->key . '.pdf', 'original_name' => $requirement->key . '.pdf',
                'status' => 'received',
            ]);
        }

        return $admission->fresh();
    }

    public function test_unpaid_application_cannot_be_moved_into_review_accepted_or_enrolled(): void
    {
        $admission = $this->submittedAdmission();
        $this->assertFalse(ApplicationFee::isSettled($admission), 'precondition: fee is outstanding');

        foreach (['under_review', 'accepted', 'enrolled'] as $target) {
            $this->assertFalse(
                ApplicationWorkflow::canTransition($admission, $target),
                "an unpaid application must not reach {$target}"
            );
        }
    }

    public function test_settled_fee_unlocks_review_and_acceptance(): void
    {
        $admission = $this->submittedAdmission();
        \App\Models\ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admission->id,
            'amount' => 50000, 'currency' => 'UGX', 'method' => 'offline',
            'status' => \App\Models\ApplicationPayment::STATUS_PAID, 'reference' => 'R1',
        ]);
        ApplicationFee::refreshStatus($admission);
        $admission = $admission->fresh();

        $this->assertTrue(ApplicationFee::isSettled($admission));
        $this->assertTrue(ApplicationWorkflow::canTransition($admission, 'under_review'));
        $this->assertTrue(ApplicationWorkflow::canTransition($admission, 'accepted'));
    }

    /** Collecting the fee must never be blocked by the gate that demands it. */
    public function test_payment_and_rejection_paths_remain_available_while_unpaid(): void
    {
        $admission = $this->submittedAdmission();
        $this->assertTrue(ApplicationWorkflow::canTransition($admission, 'rejected'));
        $this->assertTrue(ApplicationWorkflow::canTransition($admission, 'withdrawn'));
        $this->assertTrue(ApplicationWorkflow::canTransition($admission, 'needs_correction'));
    }

    public function test_expiry_leaves_drafts_alone_and_only_runs_after_the_intake_closes(): void
    {
        $closed = IntakeSession::create([
            'school_id' => $this->schoolId, 'name' => 'Closed intake',
            'open_date' => now()->subDays(90)->toDateString(),
            'close_date' => now()->subDay()->toDateString(),
            'application_fee' => 50000, 'is_open' => 1,
        ]);

        $unpaid = $this->submittedAdmission(['intake_session_id' => $closed->id]);
        $stillOpen = $this->submittedAdmission();               // intake closes in 30 days
        $draft = Admission::create([
            'school_id' => $this->schoolId, 'app_number' => 'DRAFT-1',
            'first_name' => 'Dra', 'last_name' => 'Fty',
            'status' => Admission::STATUS_DRAFT, 'source' => 'public',
            'intake_session_id' => $closed->id,
        ]);

        $this->artisan('admissions:expire-unpaid --dry-run')->assertSuccessful();

        $this->assertSame(Admission::STATUS_SUBMITTED, $unpaid->fresh()->status, 'dry-run must not change anything');
        $this->assertSame(Admission::STATUS_DRAFT, $draft->fresh()->status);

        $this->artisan('admissions:expire-unpaid')->assertSuccessful();

        $this->assertSame(Admission::STATUS_EXPIRED, $unpaid->fresh()->status);
        $this->assertSame(Admission::STATUS_SUBMITTED, $stillOpen->fresh()->status, 'intake has not closed');
        $this->assertSame(Admission::STATUS_DRAFT, $draft->fresh()->status, 'drafts are never expired');
    }

    public function test_expired_applications_are_never_expired_twice_and_keep_an_explanation(): void
    {
        $closed = IntakeSession::create([
            'school_id' => $this->schoolId, 'name' => 'Closed intake',
            'open_date' => now()->subDays(90)->toDateString(),
            'close_date' => now()->subDay()->toDateString(),
            'application_fee' => 50000, 'is_open' => 1,
        ]);
        $admission = $this->submittedAdmission(['intake_session_id' => $closed->id]);

        $this->artisan('admissions:expire-unpaid')->assertSuccessful();
        $this->assertSame(1, \App\Models\AdmissionStatusEvent::where('admission_id', $admission->id)
            ->where('to_status', Admission::STATUS_EXPIRED)->count());

        // Running again must be a no-op, not a second timeline entry.
        $this->artisan('admissions:expire-unpaid')->assertSuccessful();
        $this->assertSame(1, \App\Models\AdmissionStatusEvent::where('admission_id', $admission->id)
            ->where('to_status', Admission::STATUS_EXPIRED)->count());
        $this->assertSame(Admission::STATUS_EXPIRED, $admission->fresh()->status);
    }

    public function test_paid_applications_are_never_expired(): void
    {
        $closed = IntakeSession::create([
            'school_id' => $this->schoolId, 'name' => 'Closed intake',
            'open_date' => now()->subDays(90)->toDateString(),
            'close_date' => now()->subDay()->toDateString(),
            'application_fee' => 50000, 'is_open' => 1,
        ]);
        $admission = $this->submittedAdmission(['intake_session_id' => $closed->id]);
        \App\Models\ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admission->id,
            'amount' => 50000, 'currency' => 'UGX', 'method' => 'offline',
            'status' => \App\Models\ApplicationPayment::STATUS_PAID, 'reference' => 'PAID-1',
        ]);
        ApplicationFee::refreshStatus($admission);

        $this->artisan('admissions:expire-unpaid')->assertSuccessful();

        $this->assertSame(Admission::STATUS_SUBMITTED, $admission->fresh()->status);
    }

    public function test_expired_status_is_terminal(): void
    {
        $expired = Admission::create([
            'school_id' => $this->schoolId, 'app_number' => 'E-1',
            'first_name' => 'Exp', 'last_name' => 'Ired',
            'status' => Admission::STATUS_EXPIRED, 'source' => 'public',
        ]);
        $this->assertContains(Admission::STATUS_EXPIRED, Admission::STATUSES);
        $this->assertNotContains(Admission::STATUS_EXPIRED, Admission::STAFF_SETTABLE_STATUSES,
            'staff must not be able to hand-set expired');
        foreach (['under_review', 'accepted', 'enrolled', 'submitted'] as $target) {
            $this->assertFalse(ApplicationWorkflow::canTransition($expired, $target));
        }
    }
}