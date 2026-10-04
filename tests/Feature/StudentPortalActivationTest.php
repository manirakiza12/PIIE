<?php

namespace Tests\Feature;

use App\Mail\StudentPortalActivationEmail;
use App\Models\AuditLog;
use App\Models\StudentFeeManager;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentPortalActivationTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    // ==================================================== Password setup flow
    //
    // The real student activation journey: the activation email carries a
    // temporary password, logging in with it sets force_password_change, and the
    // StudentMiddleware gate then forces the student onto the password page
    // before anything else in the portal will load.
    //
    // Two defects were reported and confirmed here:
    //  1. A student who typed two visibly matching passwords was told they did not
    //     match, because the page silently also required a third field (the
    //     temporary/current password) which sat last with an identical
    //     placeholder, and the only feedback was a generic flash.
    //  2. The comparison was PHP's loose `!=`, so genuinely DIFFERENT numeric
    //     passwords were accepted as matching ('123456' vs '0123456',
    //     '1e3' vs '1000').

    private const TEMP = 'TempPass#2026';
    private const NEW = 'SecureTest#2026';

    /** A student sitting on the forced password page, mid-activation. */
    private function studentAwaitingPasswordSetup(int $schoolId, string $email = 'setup.student@example.com'): User
    {
        return User::factory()->create([
            'name' => 'Setup Student', 'email' => $email, 'role_id' => 7,
            'school_id' => $schoolId, 'code' => 'STU/2026/77',
            'account_status' => 'active', 'force_password_change' => true,
            'password' => Hash::make(self::TEMP),
        ]);
    }

    // 1. The forced setup page loads for a student who must change their password.
    public function test_the_forced_password_page_loads_for_a_activating_student(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        // The middleware gate really does divert them there first.
        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertRedirect(route('student.password', 'edit'));

        $this->actingAs($student)->get(route('student.password', 'edit'))
            ->assertOk()
            ->assertSee('name="new_password"', false)
            ->assertSee('name="confirm_password"', false)
            ->assertSee('name="old_password"', false);
    }

    // 2. THE REPORTED DEFECT: two matching passwords are accepted.
    public function test_two_visibly_matching_passwords_are_accepted(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)
            ->post(route('student.password', 'update'), [
                'old_password' => self::TEMP,
                'new_password' => self::NEW,
                'confirm_password' => self::NEW,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('student.password', 'edit'));

        $student->refresh();
        $this->assertFalse((bool) $student->force_password_change, 'setup is complete');
        $this->assertTrue(Hash::check(self::NEW, $student->password), 'the new password is stored hashed');
        $this->assertFalse(Hash::check(self::TEMP, $student->password), 'the temporary password no longer works');
    }

    // 3. A student who omits the temporary password gets a clear, field-level
    //    message that names the field, instead of a bare "doesn't match".
    public function test_omitting_the_temporary_password_explains_which_field_is_missing(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $response = $this->actingAs($student)
            ->post(route('student.password', 'update'), [
                'new_password' => self::NEW,
                'confirm_password' => self::NEW,
            ]);

        $response->assertSessionHasErrors('old_password');
        $this->assertStringContainsString('temporary password', (string) session('errors')->first('old_password'));

        // No bare "doesn't match" wording any more.
        $this->assertStringNotContainsString("Doesn't match", (string) session('errors')->first('old_password'));

        $student->refresh();
        $this->assertTrue((bool) $student->force_password_change, 'nothing changed');
        $this->assertTrue(Hash::check(self::TEMP, $student->password), 'the password is untouched');
    }

    // 3b. A wrong temporary password is refused with a field-level message.
    public function test_a_wrong_temporary_password_is_refused(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)
            ->post(route('student.password', 'update'), [
                'old_password' => 'NotTheTemporaryOne#1',
                'new_password' => self::NEW,
                'confirm_password' => self::NEW,
            ])
            ->assertSessionHasErrors('old_password');

        $student->refresh();
        $this->assertTrue(Hash::check(self::TEMP, $student->password), 'the password is untouched');
    }

    // 3c. Each of the three fields is individually required.
    public function test_each_password_field_is_required(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        foreach (['old_password', 'new_password', 'confirm_password'] as $missing) {
            $payload = ['old_password' => self::TEMP, 'new_password' => self::NEW, 'confirm_password' => self::NEW];
            unset($payload[$missing]);

            $this->actingAs($student->fresh())
                ->post(route('student.password', 'update'), $payload)
                ->assertSessionHasErrors($missing);
        }

        $student->refresh();
        $this->assertTrue((bool) $student->force_password_change);
    }

    // 3d. The reported security hole: DIFFERENT numeric passwords are refused.
    public function test_differently_numeric_passwords_are_rejected(): void
    {
        $schoolId = $this->makeSchool();

        foreach ([['123456', '0123456'], ['1e3', '1000'], ['20260101', '20260102']] as [$new, $confirm]) {
            $student = $this->studentAwaitingPasswordSetup($schoolId, "numeric.{$new}.{$confirm}@example.com");

            $this->actingAs($student)
                ->post(route('student.password', 'update'), [
                    'old_password' => self::TEMP,
                    'new_password' => $new,
                    'confirm_password' => $confirm,
                ])
                ->assertSessionHasErrors('confirm_password');

            $student->refresh();
            $this->assertTrue((bool) $student->force_password_change, "{$new} vs {$confirm} must not be accepted");
            $this->assertTrue(Hash::check(self::TEMP, $student->password), 'the password is untouched');
        }
    }

    // 3e. Ordinary non-matching passwords are still refused.
    public function test_ordinary_non_matching_passwords_are_rejected(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)
            ->post(route('student.password', 'update'), [
                'old_password' => self::TEMP,
                'new_password' => self::NEW,
                'confirm_password' => self::NEW.'x',
            ])
            ->assertSessionHasErrors('confirm_password');

        $student->refresh();
        $this->assertTrue(Hash::check(self::TEMP, $student->password));
    }

    // 13. The password is hashed, never stored or logged in plaintext.
    public function test_the_new_password_is_never_stored_in_plaintext(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        $hash = (string) User::find($student->id)->password;
        $this->assertNotSame(self::NEW, $hash, 'never stored in plaintext');
        $this->assertStringNotContainsString(self::NEW, $hash);
        $this->assertStringStartsWith('$', $hash, 'stored with the application hasher');
    }

    // 12 + 9 + 10. Role and tenant survive, and the student logs in afterwards.
    public function test_role_and_tenant_are_preserved_and_the_new_password_logs_in(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertSame(7, (int) $student->role_id, 'still a Student');
        $this->assertSame($schoolId, (int) $student->school_id, 'tenant unchanged');
        $this->assertSame('STU/2026/77', $student->code, 'student number unchanged');
        $this->assertSame('active', $student->account_status, 'account status unchanged');

        // The new credential really authenticates through the application's guard.
        $this->assertTrue(\Illuminate\Support\Facades\Auth::attempt([
            'email' => $student->email, 'password' => self::NEW,
        ]), 'the new password authenticates');
        $this->post('/logout');
        $this->assertFalse(\Illuminate\Support\Facades\Auth::attempt([
            'email' => $student->email, 'password' => self::TEMP,
        ]), 'the temporary password no longer authenticates');

        // And the dashboard is no longer diverted to the password page.
        $this->assertNotSame(
            route('student.password', 'edit'),
            $this->actingAs($student->fresh())->get(route('student.dashboard'))->headers->get('Location'),
            'the middleware gate no longer diverts the student'
        );
    }

    // 10. Another student's account cannot be set up through a guessed request.
    public function test_the_setup_action_cannot_touch_another_students_account(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId, 'victim@example.com');
        $other = User::factory()->create([
            'name' => 'Bystander', 'email' => 'bystander@example.com', 'role_id' => 7,
            'school_id' => $schoolId, 'account_status' => 'active',
            'password' => Hash::make('Bystander#2026'),
        ]);

        // The endpoint acts on auth()->user() only: there is no user id parameter.
        $this->actingAs($student)->post(route('student.password', 'update'), [
            'user_id' => $other->id,
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        $other->refresh();
        $this->assertTrue(Hash::check('Bystander#2026', $other->password),
            'the other student is completely unaffected');
    }

    // 11. A student of another institution is unaffected by a cross-tenant attempt.
    public function test_a_cross_tenant_student_cannot_be_used_to_reset_a_password(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $studentA = $this->studentAwaitingPasswordSetup($schoolA, 'tenant.a@example.com');
        $studentB = $this->studentAwaitingPasswordSetup($schoolB, 'tenant.b@example.com');

        $this->actingAs($studentA)->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        $studentB->refresh();
        $this->assertTrue(Hash::check(self::TEMP, $studentB->password), "the other tenant's student is untouched");
        $this->assertTrue((bool) $studentB->force_password_change);
    }

    // 17. The forced setup is audited, and never with the password in it.
    public function test_the_forced_setup_is_audited_without_the_password(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        $audit = AuditLog::where('description', 'like', '%forced password change%')
            ->where('user_id', $student->id)->latest('id')->first();

        $this->assertNotNull($audit, 'the forced setup is audited');
        $this->assertStringNotContainsString(self::NEW, (string) $audit->description);
        $this->assertStringNotContainsString(self::NEW, (string) ($audit->new_values ?? ''));
        $this->assertStringNotContainsString(self::TEMP, (string) ($audit->new_values ?? ''));
    }

    // 18. The activation email carries the temporary password but never a
    //     plaintext new password, and points at the real login route.
    public function test_the_activation_email_never_contains_a_new_password(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $student = $this->studentAwaitingPasswordSetup($schoolId, 'mailed@example.com');

        \App\Support\StudentPortalActivation::sendActivationEmail($student, self::TEMP);

        // The mailable really was sent to the intended student, exactly once,
        // carrying the temporary password and nothing resembling a new one.
        $sent = null;
        Mail::assertSent(StudentPortalActivationEmail::class, function ($mail) use ($student, &$sent) {
            $sent = $mail->data;

            return $mail->hasTo($student->email);
        });

        $this->assertIsArray($sent, 'the activation email was sent');
        $this->assertSame(self::TEMP, $sent['password'], 'the email carries the temporary password');
        $this->assertSame($student->email, $sent['email']);
        $this->assertStringNotContainsString(self::NEW, (string) json_encode($sent),
            'the email never carries a new password');

        // The login URL the student follows is the real route.
        $this->assertSame(url('/login'), route('login'));
    }

    // 7/8/9. There is no setup token in this flow, and that is deliberate: the
    //       student authenticates with the emailed temporary password instead.
    //       These assert the mechanism is genuinely password-based, one-time in
    //       effect, and cannot be replayed once the password has changed.
    public function test_the_temporary_password_is_single_use_by_being_replaced(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->studentAwaitingPasswordSetup($schoolId);

        $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => self::NEW,
            'confirm_password' => self::NEW,
        ])->assertSessionHasNoErrors();

        // The old credential is now useless: it can no longer authenticate.
        $this->post('/logout');
        $this->assertFalse(\Illuminate\Support\Facades\Auth::attempt([
            'email' => $student->email, 'password' => self::TEMP,
        ]), 'the temporary password is spent');

        // And it can no longer be replayed as the "current password" either.
        $this->actingAs($student->fresh())->post(route('student.password', 'update'), [
            'old_password' => self::TEMP,
            'new_password' => 'Another#2026',
            'confirm_password' => 'Another#2026',
        ])->assertSessionHasErrors('old_password');
    }

    // The regular, non-forced password change still works for a normal student.
    public function test_a_normal_student_password_change_still_works(): void
    {
        $schoolId = $this->makeSchool();
        $student = User::factory()->create([
            'name' => 'Normal Student', 'email' => 'normal.student@example.com', 'role_id' => 7,
            'school_id' => $schoolId, 'account_status' => 'active', 'force_password_change' => false,
            'password' => Hash::make('Existing#2026'),
        ]);

        $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => 'Existing#2026',
            'new_password' => 'Rotated#2026',
            'confirm_password' => 'Rotated#2026',
        ])->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertTrue(Hash::check('Rotated#2026', $student->password));
    }

    public function test_rejected_application_does_not_create_a_student_account(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'email' => 'rejected.applicant@example.com',
            'status' => 'under_review',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'rejected']);

        $this->assertNull(User::where('email', 'rejected.applicant@example.com')->first(), 'Rejected applications must never create a student account.');
    }

    public function test_accepted_but_not_enrolled_application_does_not_create_a_student_account(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'email' => 'accepted.only@example.com',
            'status' => 'under_review',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'accepted']);

        $this->assertNull(User::where('email', 'accepted.only@example.com')->first(), 'Acceptance alone (without enrollment) must not create a portal account.');
    }

    public function test_enrolling_creates_one_forced_password_change_student_and_sends_activation_email(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'BSc Computer Science']);
        $intakeId = $this->makeIntakeSession($schoolId, ['name' => 'September 2026 Intake']);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'intake_session_id' => $intakeId,
            'email' => 'activate.me@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $this->assertSame(1, User::where('email', 'activate.me@example.com')->count(), 'Exactly one student account must be created.');

        $student = User::where('email', 'activate.me@example.com')->first();
        $this->assertSame(7, (int) $student->role_id);
        $this->assertTrue((bool) $student->force_password_change, 'A newly converted student must be forced to change their password.');

        Mail::assertSent(StudentPortalActivationEmail::class, function ($mail) use ($student) {
            return $mail->hasTo($student->email)
                && $mail->data['programme'] === 'BSc Computer Science'
                && $mail->data['intake'] === 'September 2026 Intake'
                && ! empty($mail->data['password']);
        });
        Mail::assertSent(StudentPortalActivationEmail::class, 1);
    }

    public function test_temporary_password_is_never_stored_in_plaintext(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'email' => 'hash.check@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $student = User::where('email', 'hash.check@example.com')->first();

        $sentPassword = null;
        Mail::assertSent(StudentPortalActivationEmail::class, function ($mail) use (&$sentPassword) {
            $sentPassword = $mail->data['password'];
            return true;
        });

        $this->assertNotSame($sentPassword, $student->password, 'The password column must never equal the plaintext temporary password.');
        $this->assertTrue(Hash::check($sentPassword, $student->password), 'The stored hash must match the temporary password that was emailed.');
    }

    public function test_student_with_forced_password_change_is_redirected_away_from_dashboard(): void
    {
        $schoolId = $this->makeSchool();
        $student = User::factory()->create([
            'role_id' => 7,
            'school_id' => $schoolId,
            'account_status' => 'active',
            'force_password_change' => true,
        ]);

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertRedirect(route('student.password', 'edit'));
    }

    public function test_password_change_clears_flag_and_unblocks_the_middleware_gate(): void
    {
        $schoolId = $this->makeSchool();
        $student = User::factory()->create([
            'role_id' => 7,
            'school_id' => $schoolId,
            'account_status' => 'active',
            'force_password_change' => true,
            'password' => Hash::make('TempPass123'),
        ]);

        $updateResponse = $this->actingAs($student)->post(route('student.password', 'update'), [
            'old_password' => 'TempPass123',
            'new_password' => 'MyNewSecurePass1',
            'confirm_password' => 'MyNewSecurePass1',
        ]);
        $updateResponse->assertRedirect(route('student.password', 'edit'));

        $student->refresh();
        $this->assertFalse((bool) $student->force_password_change, 'force_password_change must be cleared after a successful password change.');

        // Verify the middleware itself now lets the request through, without
        // depending on rendering the real (heavily-widgeted) dashboard view —
        // that view's unrelated data dependencies aren't this feature's concern.
        $this->actingAs($student);
        $dashboardRoute = (new \Illuminate\Routing\Route('GET', 'student/dashboard', []))->name('student.dashboard');
        $dashboardRequest = \Illuminate\Http\Request::create('/student/dashboard', 'GET');
        $dashboardRequest->setRouteResolver(fn () => $dashboardRoute);

        $response = (new \App\Http\Middleware\StudentMiddleware())->handle($dashboardRequest, fn ($req) => new \Illuminate\Http\Response('dashboard-reached'));

        $this->assertSame('dashboard-reached', $response->getContent(), 'Once force_password_change is cleared, the middleware must let the request through to the dashboard.');
    }

    public function test_resending_activation_does_not_duplicate_user_profile_or_invoice(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $programmeId = $this->makeProgramme($schoolId);
        $this->makeFeeStructure($schoolId, ['programme_id' => $programmeId, 'amount' => 500]);
        $admissionId = $this->makeAdmission($schoolId, [
            'programme_id' => $programmeId,
            'email' => 'resend.me@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);
        $student = User::where('email', 'resend.me@example.com')->first();
        $originalHash = $student->password;

        // student completes activation first, so we can prove resend re-forces it
        $student->update(['force_password_change' => false]);

        $this->actingAs($admin)->get(route('admin.student.resend_activation', $student->id));

        $this->assertSame(1, User::where('email', 'resend.me@example.com')->count(), 'Resend must not create a duplicate user.');
        $this->assertSame(1, StudentProfile::where('user_id', $student->id)->count(), 'Resend must not create a duplicate profile.');
        $this->assertSame(1, StudentFeeManager::where('student_id', $student->id)->count(), 'Resend must not create a duplicate invoice.');

        $student->refresh();
        $this->assertTrue((bool) $student->force_password_change, 'Resend must force a password change again.');
        $this->assertNotSame($originalHash, $student->password, 'Resend must issue a new temporary password.');

        Mail::assertSent(StudentPortalActivationEmail::class, 2); // initial activation + resend
    }

    public function test_audit_logs_never_contain_the_plaintext_password(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $admissionId = $this->makeAdmission($schoolId, [
            'email' => 'audit.safe@example.com',
            'status' => 'accepted',
        ]);

        $this->actingAs($admin)->post(route('admin.hei_admissions.status', $admissionId), ['status' => 'enrolled']);

        $student = User::where('email', 'audit.safe@example.com')->first();

        $sentPassword = null;
        Mail::assertSent(StudentPortalActivationEmail::class, function ($mail) use (&$sentPassword) {
            $sentPassword = $mail->data['password'];
            return true;
        });

        $logs = AuditLog::where('school_id', $schoolId)->get();
        $this->assertGreaterThan(0, $logs->count());

        foreach ($logs as $log) {
            $this->assertStringNotContainsString($sentPassword, (string) $log->description, 'Audit description must never contain the temporary password.');
            $this->assertStringNotContainsString($sentPassword, json_encode($log->old_values), 'old_values must never contain the temporary password.');
            $this->assertStringNotContainsString($sentPassword, json_encode($log->new_values), 'new_values must never contain the temporary password.');
        }
    }

    public function test_school_admin_cannot_resend_activation_for_another_schools_student(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminA = $this->makeAdminUser($schoolA);

        $studentInSchoolB = User::factory()->create([
            'role_id' => 7,
            'school_id' => $schoolB,
            'account_status' => 'active',
        ]);

        $response = $this->actingAs($adminA)->get(route('admin.student.resend_activation', $studentInSchoolB->id));

        $response->assertStatus(404);
    }
}
