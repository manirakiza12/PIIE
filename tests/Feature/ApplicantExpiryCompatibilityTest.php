<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ApplicantExpiryCompatibilityTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp(); $this->bootAdmissionsTestSchema();
        $school = $this->makeSchool(['status' => 1]);
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $school]);
        config(['auth.passwords.applicants.expire' => 60]);
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); parent::tearDown();
    }

    public static function ages(): array
    {
        // Preserve whole-minute > 60, including the existing fractional grace.
        return ['fresh' => [0, true], 'exact expiry' => [3600, true], 'fraction below next minute' => [3659, true],
            'expired boundary' => [3660, false], 'expired' => [7200, false],
            'future fresh' => [-60, true], 'future exact expiry' => [-3600, true], 'future expired' => [-3660, false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ages')]
    public function test_reset_expiry_preserves_whole_absolute_minute_policy(int $age, bool $valid): void
    {
        $school = (int) DB::table('global_settings')->where('key', 'primary_school_id')->value('value');
        $applicant = $this->makeApplicant($school); $original = $applicant->password;
        DB::table('applicant_password_resets')->insert(['email' => $applicant->email, 'token' => Hash::make('fixture-token'), 'created_at' => now()->subSeconds($age)]);
        $response = $this->from(route('applicant.password.request'))->post(route('applicant.password.update'), [
            'email' => $applicant->email, 'token' => 'fixture-token', 'password' => 'new-fixture-password', 'password_confirmation' => 'new-fixture-password',
        ]);
        if ($valid) {
            $response->assertRedirect(route('applicant.login'));
            $this->assertTrue(Hash::check('new-fixture-password', $applicant->fresh()->password));
            $this->assertSame(0, DB::table('applicant_password_resets')->count());
        } else {
            $response->assertSessionHas('error'); $this->assertSame($original, $applicant->fresh()->password);
            $this->assertSame(1, DB::table('applicant_password_resets')->count());
        }
    }
}
