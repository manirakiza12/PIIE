<?php

namespace Tests\Feature;

use App\Mail\StudentPortalActivationEmail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class ExistingStudentPortalOnlyTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::create('password_resets', function ($table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_existing_student_email_sets_password_then_goes_only_to_student_portal(): void
    {
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        Mail::fake();
        $this->enableSmtpSettings();
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $this->actingAs($admin)->post(route('admin.student.create'), [
            'name' => 'Existing Student', 'email' => 'existing-only@example.test',
            'registration_number' => 'PIIE/LEGACY/0007', 'password_option' => 'auto',
            'gender' => '', 'blood_group' => '', 'birthday' => '2000-01-01', 'phone' => '', 'address' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $student = User::where('email', 'existing-only@example.test')->firstOrFail();
        $this->assertTrue((bool) DB::table('student_profiles')->where('user_id', $student->id)->value('was_existing_at_portal_creation'));
        $mail = Mail::sent(StudentPortalActivationEmail::class)->first();
        $html = $mail->render();
        $this->assertStringContainsString('Set Password &amp; Open Student Portal', $html);
        $this->assertStringNotContainsString('Temporary Password:', $html);
        $this->assertStringNotContainsString('/applicant/', $html);
        $url = $mail->data['setup_url'];
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertTrue(\Illuminate\Support\Facades\Password::broker('users')->tokenExists($student, $token));
        $this->assertNull($mail->data['password']);
        auth()->logout();
        $this->withSession(['url.intended' => route('applicant.dashboard')])->post(route('password.update'), [
            'email' => $student->email, 'token' => $token,
            'password' => 'StudentOwnPassword#2026', 'password_confirmation' => 'StudentOwnPassword#2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('student.dashboard'));
        $this->assertFalse($student->fresh()->force_password_change);
        $this->assertTrue(Hash::check('StudentOwnPassword#2026', $student->fresh()->password));
        $this->assertAuthenticatedAs($student);
        $this->assertGuest('applicant');
        $this->assertSame(0, DB::table('admissions')->count());
        $this->assertSame(0, DB::table('applicants')->count());
        $this->assertSame(0, DB::table('enrollment')->count());
        $this->assertSame('PIIE/LEGACY/0007', $student->fresh()->code);
        $this->post(route('password.update'), ['email' => $student->email, 'token' => $token,
            'password' => 'AnotherPassword#2026', 'password_confirmation' => 'AnotherPassword#2026'])
            ->assertSessionHasErrors('email');
    }

    public function test_existing_student_cannot_be_given_new_applicant_access(): void
    {
        $school = $this->makeSchool();
        $student = User::factory()->create(['school_id' => $school, 'role_id' => 7, 'email' => 'already@example.test']);
        $admission = \App\Models\Admission::findOrFail($this->makeAdmission($school, ['email' => $student->email]));
        try {
            \App\Support\Admissions\ApplicantPortalAccess::ensureLinked($admission);
            $this->fail('Existing student must not receive applicant access');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertSame(0, DB::table('applicants')->count());
        $this->assertNull($admission->fresh()->applicant_id);
    }

    public function test_admin_can_hold_upload_retrieve_and_remove_existing_student_documents(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);
        $student = User::factory()->create(['school_id' => $school, 'role_id' => 7]);
        $this->actingAs($admin)->get(route('admin.student.documents', $student->id))
            ->assertOk()->assertSee('Administration manages these documents.');
        $this->post(route('admin.documents.upload', $student->id), [
            'file_name' => 'Academic Certificate',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\nCertificate held by admin\n%%EOF"),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $documents = json_decode($student->fresh()->documents, true);
        $key = array_key_first($documents);
        $path = public_path('assets/uploads/user-docs/'.$student->id.'/'.$documents[$key]);
        $this->assertFileExists($path);
        $this->get(route('admin.student.documents', $student->id))
            ->assertOk()->assertSee($documents[$key], false);
        $foreignAdmin = $this->makeAdminUser($this->makeSchool());
        $this->actingAs($foreignAdmin)->get(route('admin.student.documents', $student->id))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.documents.remove', ['id' => $student->id, 'file_name' => $key]))->assertRedirect();
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, DB::table('admissions')->count());
    }

    public function test_old_reset_password_action_also_sends_only_student_setup(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();
        $school = $this->makeSchool();
        $student = User::factory()->create(['school_id' => $school, 'role_id' => 7]);
        $this->actingAs($this->makeAdminUser($school))->get(route('admin.student.reset_password', $student->id))->assertRedirect();
        Mail::assertSent(StudentPortalActivationEmail::class, fn ($mail) =>
            $mail->data['student_password_setup'] === true && $mail->data['password'] === null);
        $this->assertSame(0, DB::table('applicants')->count());
    }
}
