<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/** Covers the student's self-edit form and preserves opaque legacy JSON keys. */
class StudentProfileUpdateTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
    }

    private function makeStudent(int $schoolId, string $email, array $userInfo = []): User
    {
        return User::create([
            'name' => 'Profile Student', 'email' => $email,
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
            'user_information' => json_encode($userInfo),
        ]);
    }

    public function test_student_profile_does_not_request_or_accept_blood_group(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'bloodgroup@example.com');

        $this->actingAs($student)->get(route('student.profile'))
            ->assertOk()->assertDontSee('Blood Group')->assertDontSee('blood_group');

        $response = $this->actingAs($student)->post(route('student.profile.update'), [
            'name' => $student->name,
            'email' => $student->email,
            'eDefaultDateRange' => now()->format('m/d/Y'),
            'gender' => 'Male',
            'phone' => '0700111222',
            'address' => 'Kampala',
            'blood_group' => 'O+', // Forged legacy field must be ignored.
            'old_photo' => '',
        ]);

        $response->assertRedirect(route('student.profile'));
        $info = json_decode($student->fresh()->user_information, true);
        $this->assertArrayNotHasKey('blood_group', $info);
    }

    public function test_profile_edits_preserve_legacy_blood_group_without_displaying_it(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'preexisting@example.com', ['blood_group' => 'AB-', 'phone' => '0700000000']);

        $this->actingAs($student)->post(route('student.profile.update'), [
            'name' => $student->name,
            'email' => $student->email,
            'eDefaultDateRange' => now()->format('m/d/Y'),
            'gender' => 'Male',
            'phone' => '0700999888',
            'address' => 'Entebbe',
            'blood_group' => 'forged-value',
            'old_photo' => '',
        ]);

        $info = json_decode($student->fresh()->user_information, true);
        $this->assertSame('AB-', $info['blood_group']);
        $this->assertSame('0700999888', $info['phone']);
        $this->get(route('student.profile'))->assertOk()->assertDontSee('Blood Group')->assertDontSee('AB-');
    }

    public function test_the_profile_page_hides_a_legacy_blood_group_value(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'showbloodgroup@example.com', ['blood_group' => 'B+']);

        $response = $this->actingAs($student)->get(route('student.profile'));

        $response->assertOk();
        $response->assertDontSee('Blood Group')->assertDontSee('B+');
    }

    public function test_a_student_can_upload_a_new_profile_photo(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'photoupload@example.com');
        \Illuminate\Support\Facades\Storage::fake('public');

        $response = $this->actingAs($student)->post(route('student.profile.update'), [
            'name' => $student->name,
            'email' => $student->email,
            'eDefaultDateRange' => now()->format('m/d/Y'),
            'gender' => 'Male',
            'phone' => '0700111222',
            'address' => 'Kampala',
            'old_photo' => '',
            'photo' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $response->assertRedirect(route('student.profile'));
        $info = json_decode($student->fresh()->user_information, true);
        $this->assertNotEmpty($info['photo']);
        $this->assertFileExists(public_path('assets/uploads/user-images/' . $info['photo']));

        @unlink(public_path('assets/uploads/user-images/' . $info['photo']));
    }

    public function test_photo_only_upload_preserves_opaque_legacy_information(): void
    {
        $original = ['blood_group' => 'AB+', 'phone' => '0700123456', 'admission_note' => 'Keep this'];
        $student = $this->makeStudent($this->makeSchool(), 'photoonly@example.com', $original);
        $this->actingAs($student)->post(route('student.profile.update'), [
            'photo' => UploadedFile::fake()->image('passport.jpg'),
        ])->assertRedirect(route('student.profile'));

        $updated = $student->fresh();
        $info = json_decode($updated->user_information, true);
        try {
            $this->assertSame($student->name, $updated->name);
            $this->assertSame($student->email, $updated->email);
            $this->assertSame($original, array_diff_key($info, ['photo' => true]));
            $this->assertFileExists(public_path('assets/uploads/user-images/' . $info['photo']));
        } finally {
            if (! empty($info['photo'])) {
                @unlink(public_path('assets/uploads/user-images/' . $info['photo']));
            }
        }
    }

    public function test_invalid_photo_submissions_are_rejected_without_changing_the_profile(): void
    {
        $student = $this->makeStudent($this->makeSchool(), 'invalidphoto@example.com', ['photo' => 'existing.jpg']);
        $original = $student->user_information;
        foreach (['not-an-upload', ['unexpected'], UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->image('large.jpg')->size(4097)] as $photo) {
            $this->actingAs($student)->from(route('student.profile'))->post(route('student.profile.update'), [
                'photo' => $photo,
            ])->assertRedirect(route('student.profile'))->assertSessionHasErrors('photo');
            $this->assertSame($original, $student->fresh()->user_information);
        }
        $this->get(route('student.profile'))->assertOk()->assertSee('alert-danger');
    }

    public function test_client_cannot_replace_the_saved_photo_using_old_photo(): void
    {
        $student = $this->makeStudent($this->makeSchool(), 'savedphoto@example.com', ['photo' => 'existing.jpg']);
        $this->actingAs($student)->post(route('student.profile.update'), [
            'phone' => '0700999888', 'old_photo' => 'another-student.jpg',
        ])->assertRedirect(route('student.profile'));
        $this->assertSame('existing.jpg', json_decode($student->fresh()->user_information, true)['photo']);
    }

    public function test_student_cannot_change_institutional_identity_even_with_a_forged_request(): void
    {
        $birthday = strtotime('2000-05-12');
        $student = $this->makeStudent($this->makeSchool(), 'identity@example.com', ['birthday' => $birthday]);
        $originalName = $student->name;
        $originalCode = $student->code;
        $this->actingAs($student)->post(route('student.profile.update'), [
            'name' => 'Forged Name', 'email' => 'forged@example.com', 'eDefaultDateRange' => '01/01/2010',
            'birthday' => '2010-01-01', 'code' => '2026/05/MPAM/999',
            'user_information' => ['birthday' => 1], 'phone' => '0700999888',
        ])->assertRedirect(route('student.profile'));
        $saved = $student->fresh();
        $this->assertSame($originalName, $saved->name);
        $this->assertSame('identity@example.com', $saved->email);
        $this->assertSame($originalCode, $saved->code);
        $this->assertSame($birthday, json_decode($saved->user_information, true)['birthday']);
        $this->assertSame('0700999888', json_decode($saved->user_information, true)['phone']);
        $this->get(route('student.profile'))->assertOk()
            ->assertSee('Contact the Registrar for corrections.')
            ->assertDontSee('name="name"', false)->assertDontSee('name="email"', false)
            ->assertDontSee('name="eDefaultDateRange"', false);
    }
}
