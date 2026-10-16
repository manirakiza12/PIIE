<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Mail\StudentPortalActivationEmail;
use App\Support\Admissions\AdmissionsNotificationCopy;
use App\Support\Admissions\ApplicantNotificationDelivery;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\StudentPortalActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdmissionsNotificationCopyTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        foreach (['smtp_user' => 'sender@example.test', 'smtp_pass' => 'fixture', 'smtp_host' => 'localhost', 'smtp_port' => '2525'] as $key => $value) {
            DB::table('global_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        }
        DB::table('global_settings')->insert(['key' => AdmissionsNotificationCopy::settingKey($this->school), 'value' => 'admissions@example.test']);
        Mail::fake();
    }

    public function test_both_recipients_receive_the_notice_without_copying_personal_links(): void
    {
        $applicant = $this->makeApplicant($this->school);
        $this->assertTrue(ApplicantNotifier::welcome($applicant));
        Mail::assertSent(ApplicantNotificationEmail::class, 2);
        Mail::assertSent(ApplicantNotificationEmail::class, fn ($mail) => $mail->hasTo($applicant->email) && isset($mail->data['cta_url']));
        Mail::assertSent(ApplicantNotificationEmail::class, fn ($mail) => $mail->hasTo('admissions@example.test')
            && $mail->data['details']['Student Email'] === $applicant->email && ! isset($mail->data['cta_url']));
    }

    public function test_no_other_institution_receives_a_copy_and_same_recipient_is_not_duplicated(): void
    {
        ApplicantNotifier::welcome($this->makeApplicant($this->makeSchool()));
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
        Mail::fake();
        ApplicantNotifier::welcome($this->makeApplicant($this->school, ['email' => 'admissions@example.test']));
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_password_reset_goes_only_to_the_applicant(): void
    {
        $applicant = $this->makeApplicant($this->school);
        ApplicantNotifier::passwordReset($applicant, 'private-reset-token');
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
        Mail::assertSent(ApplicantNotificationEmail::class, fn ($mail) => $mail->hasTo($applicant->email));
    }

    public function test_activation_copy_has_student_identity_but_no_password(): void
    {
        $student = \App\Models\User::factory()->create(['school_id' => $this->school, 'role_id' => 7]);
        StudentPortalActivation::sendActivationEmail($student, 'PrivateActivationPassword');
        Mail::assertSent(StudentPortalActivationEmail::class, 1);
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) use ($student) {
            return $mail->hasTo('admissions@example.test') && $mail->data['details']['Student Email'] === $student->email
                && ! str_contains(json_encode($mail->data), 'PrivateActivationPassword');
        });
    }

    public function test_durable_copies_have_independent_retry_records_and_rollback_sends_neither(): void
    {
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $applicant = $this->makeApplicant($this->school);
        DB::beginTransaction();
        ApplicantNotifier::welcome($applicant);
        $this->assertSame(2, DB::table('applicant_notification_deliveries')->count());
        Mail::assertNothingSent();
        DB::rollBack();
        $this->assertSame(0, DB::table('applicant_notification_deliveries')->count());
        Mail::assertNothingSent();
        ApplicantNotifier::welcome($applicant);
        Mail::assertSent(ApplicantNotificationEmail::class, 2);
        foreach (DB::table('applicant_notification_deliveries')->pluck('id') as $id) {
            $this->assertFalse(ApplicantNotificationDelivery::deliver($id));
        }
        Mail::assertSent(ApplicantNotificationEmail::class, 2);
    }

    public function test_failed_office_delivery_can_retry_without_resending_to_student(): void
    {
        (require database_path('migrations/2026_10_08_210000_create_applicant_notification_deliveries_table.php'))->up();
        $applicant = $this->makeApplicant($this->school);
        $studentMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $studentMail->shouldReceive('send')->once()->with(\Mockery::type(ApplicantNotificationEmail::class));
        $officeMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $officeMail->shouldReceive('send')->once()->with(\Mockery::type(ApplicantNotificationEmail::class));
        $officeAttempts = 0;
        Mail::shouldReceive('to')->with('admissions@example.test')->twice()->andReturnUsing(function () use (&$officeAttempts, $officeMail) {
            if (++$officeAttempts === 1) throw new \RuntimeException('Simulated transport failure');
            return $officeMail;
        });
        Mail::shouldReceive('to')->with($applicant->email)->once()->andReturn($studentMail);
        $this->assertTrue(ApplicantNotifier::welcome($applicant));
        $this->assertSame(1, DB::table('applicant_notification_deliveries')->whereNotNull('sent_at')->count());
        $pending = DB::table('applicant_notification_deliveries')->whereNull('sent_at')->first();
        $this->assertNotNull($pending);
        DB::table('applicant_notification_deliveries')->where('id', $pending->id)->update(['available_at' => now()->subMinute()]);
        $this->assertTrue(ApplicantNotificationDelivery::deliver($pending->id));
        $this->assertSame(2, DB::table('applicant_notification_deliveries')->whereNotNull('sent_at')->count());
    }

    public function test_office_payment_copy_excludes_signed_payment_and_access_links(): void
    {
        AdmissionsNotificationCopy::send('student@example.test', [
            'school_id' => $this->school, 'subject' => 'Payment invitation',
            'cta_url' => 'https://example.test/pay?signature=private-signature',
            'access_url' => 'https://example.test/reset/private-token',
            'payment_invitation_admission_id' => 123,
        ]);
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) {
            $data = json_encode($mail->data);
            return $mail->hasTo('admissions@example.test') && ! str_contains($data, 'private-signature')
                && ! str_contains($data, 'private-token') && ! isset($mail->data['payment_invitation_admission_id']);
        });
    }
}
