<?php

namespace Tests\Feature;

use App\Mail\NewUserEmail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Characterization of the five existing staff creation flows (Admin, Teacher,
 * Accountant, Librarian, Warden) through their real routes, written BEFORE the
 * creation logic was extracted into StaffProvisioningService. They pin exactly
 * what each flow writes and says, so the extraction is provably behaviour-neutral.
 */
class StaffCreationCharacterizationTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
    }

    /** role => [create route, role_id, success message] */
    public static function flows(): array
    {
        return [
            'admin' => ['admin.create', 2, 'You have successfully add user.'],
            'teacher' => ['admin.teacher.create', 3, 'You have successfully add teacher.'],
            'accountant' => ['admin.accountant.create', 4, 'You have successfully add accountant.'],
            'librarian' => ['admin.librarian.create', 5, 'You have successfully add librarian.'],
            'warden' => ['admin.warden.create', 10, 'You have successfully add warden.'],
        ];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'new.staff@example.com',
            'first_name' => 'Sarah',
            'last_name' => 'Nakato',
            'gender' => 'Female',
            'blood_group' => 'o+',
            'birthday' => '01/15/1990',
            'phone' => '0700000001',
            'address' => '1 Test Street',
        ], $overrides);
    }

    private function created(): ?User
    {
        return User::where('email', 'new.staff@example.com')->first();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_records_role_school_names_hr_fields_status_and_staff_code(string $route, int $roleId, string $message): void
    {
        Mail::fake();
        $department = $this->makeDepartment($this->school, 'ICT');
        $designation = $this->makeDesignation($this->school, 'Lecturer');

        $this->actingAs($this->admin)->from('/back')->post(route($route), $this->payload([
            'department_id' => $department, 'designation_id' => $designation, 'employment_type' => 'Full Time',
            // A submitted school_id / role_id is never trusted.
            'school_id' => 999, 'role_id' => 1,
        ]))->assertRedirect('/back')->assertSessionHas('message', $message);

        $user = $this->created();
        $this->assertNotNull($user);
        $this->assertSame($roleId, (int) $user->role_id);
        $this->assertSame($this->school, (int) $user->school_id);
        $this->assertSame('Sarah Nakato', $user->name);
        $this->assertSame('Sarah', $user->first_name);
        $this->assertSame('Nakato', $user->last_name);
        $this->assertSame($department, (int) $user->department_id);
        $this->assertSame($designation, (int) $user->designation_id);
        $this->assertSame('Full Time', $user->employment_type);
        $this->assertSame('active', $user->staff_status);
        $this->assertSame(1, (int) $user->status);
        $this->assertMatchesRegularExpression('/^STF-' . date('Y') . '-\d{4}-\d{4}$/', $user->code);
        // users.school_role is never set by these flows (a created admin is therefore never the
        // primary admin); the Admin flow records school_role = 0 inside user_information only.
        $this->assertNull($user->school_role);

        $expectedInfo = [
            'gender' => 'Female', 'blood_group' => 'o+', 'birthday' => strtotime('01/15/1990'),
            'phone' => '0700000001', 'address' => '1 Test Street', 'photo' => '',
        ];
        if ($roleId === 2) {
            $expectedInfo['school_role'] = 0;
        }
        $this->assertSame($expectedInfo, json_decode($user->user_information, true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_optional_hr_fields_default_to_null(string $route, int $roleId): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route($route), $this->payload());

        $user = $this->created();
        $this->assertNull($user->department_id);
        $this->assertNull($user->designation_id);
        $this->assertNull($user->employment_type);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_duplicate_email_is_refused_without_creating_anyone(string $route): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'new.staff@example.com', 'school_id' => $this->school, 'role_id' => 3]);
        $before = User::count();

        $this->actingAs($this->admin)->from('/back')->post(route($route), $this->payload())
            ->assertRedirect('/back')->assertSessionHas('error', 'Email was already taken.');

        $this->assertSame($before, User::count());
        Mail::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_auto_password_forces_change_and_emails_the_temporary_password(string $route): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $this->actingAs($this->admin)->post(route($route), $this->payload(['password_mode' => 'auto']));

        $user = $this->created();
        $this->assertTrue((bool) $user->force_password_change);
        $sent = null;
        Mail::assertSent(NewUserEmail::class, function ($mail) use ($user, &$sent) {
            $sent = $mail->data['password'] ?? null;

            return $mail->hasTo($user->email) && $mail->data['name'] === 'Sarah Nakato';
        });
        $this->assertSame(10, strlen((string) $sent));
        $this->assertTrue(Hash::check($sent, $user->password));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_default_password_mode_is_auto(string $route): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route($route), $this->payload());
        $this->assertTrue((bool) $this->created()->force_password_change);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_manual_password_is_hashed_does_not_force_change_and_is_emailed(string $route): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $this->actingAs($this->admin)->post(route($route), $this->payload(['password_mode' => 'manual', 'password' => 'Chosen-Pass-123']));

        $user = $this->created();
        $this->assertFalse((bool) $user->force_password_change);
        $this->assertTrue(Hash::check('Chosen-Pass-123', $user->password));
        Mail::assertSent(NewUserEmail::class, fn ($mail) => $mail->data['password'] === 'Chosen-Pass-123');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_manual_mode_without_a_password_falls_back_to_auto(string $route): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route($route), $this->payload(['password_mode' => 'manual', 'password' => '']));
        $this->assertTrue((bool) $this->created()->force_password_change);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_no_email_is_sent_without_smtp_settings(string $route): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route($route), $this->payload());
        $this->assertNotNull($this->created());
        Mail::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_profile_photo_is_stored_and_recorded(string $route): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->post(route($route), $this->payload(['photo' => UploadedFile::fake()->image('me.jpg', 50, 50)]));

        $photo = json_decode($this->created()->user_information, true)['photo'];
        $this->assertNotSame('', $photo);
        $path = public_path('assets/uploads/user-images/' . $photo);
        $this->assertFileExists($path);
        @unlink($path);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_an_invalid_profile_photo_is_refused_before_anything_is_created(string $route): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->from('/back')->post(route($route), $this->payload(['photo' => UploadedFile::fake()->create('me.php', 10, 'application/x-php')]))
            ->assertRedirect('/back')->assertSessionHas('error', 'Profile photo must be a JPG or PNG image of at most 4 MB.');
        $this->assertNull($this->created());
    }
}
