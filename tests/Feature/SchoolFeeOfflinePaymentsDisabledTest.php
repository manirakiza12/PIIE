<?php

namespace Tests\Feature;

use App\Models\StudentFeeManager;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class SchoolFeeOfflinePaymentsDisabledTest extends TestCase
{
    use AdmissionsTestHelper;

    public function test_retired_offline_decision_urls_cannot_change_historical_records(): void
    {
        $this->bootAdmissionsTestSchema();
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $fee = StudentFeeManager::create(['school_id' => $school, 'student_id' => 1, 'title' => 'Historical invoice',
            'total_amount' => 5000, 'class_id' => 0, 'payment_method' => 'offline', 'paid_amount' => 0, 'status' => 'pending']);
        foreach (['admin','accountant','bursar'] as $area) {
            foreach (['approve','decline'] as $action) {
                $this->actingAs($admin)->get("/$area/student_fee/delete/{$fee->id}/$action")->assertNotFound();
            }
        }
        $this->assertSame('pending', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_offline_submission_urls_no_longer_accept_proofs(): void
    {
        $this->bootAdmissionsTestSchema();
        $this->post('/student/student_fee/offline_payment/1', ['amount' => 5000])->assertNotFound();
        $this->post('/parent/student_fee/offline_payment/1', ['amount' => 5000])->assertNotFound();
    }
}
