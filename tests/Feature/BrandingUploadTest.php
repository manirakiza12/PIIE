<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class BrandingUploadTest extends TestCase
{
    use StaffModuleTestHelper;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', function (Blueprint $table) {
            foreach (['school_logo', 'email_logo', 'socialLogo1', 'socialLogo2', 'socialLogo3', 'school_info'] as $field) {
                $table->string($field)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) unlink($file);
        }
        parent::tearDown();
    }

    public function test_first_superadmin_upload_creates_missing_settings_and_renders_saved_preview(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => 1, 'school_id' => null]));
        foreach (['jpg', 'png'] as $extension) {
            $response = $this->post(route('superadmin.logo.update'), [
                'dark_logo' => UploadedFile::fake()->image('logo.'.$extension),
                'white_logo' => UploadedFile::fake()->image('nav.'.$extension),
            ]);
            $response->assertRedirect()->assertSessionHasNoErrors();
            foreach (['dark_logo', 'white_logo'] as $key) {
                $name = get_settings($key);
                $this->files[] = public_path('assets/uploads/logo/'.$name);
                $this->assertFileExists(end($this->files));
                $this->assertStringEndsWith('.'.$extension, $name);
                $this->assertSame(1, DB::table('global_settings')->where('key', $key)->count());
                $this->assertStringContainsString($name, view('shared.branding_preview', ['imageUrl' => branding_image_url($key), 'imageLabel' => 'Logo'])->render());
            }
            $this->assertStringContainsString(get_settings('white_logo'), institution_logo_url());
            $this->assertStringContainsString(get_settings('dark_logo'), institution_logo_url('login'));
        }
    }

    public function test_school_logo_is_visible_in_own_portal_and_does_not_change_another_school(): void
    {
        $school = $this->makeSchool();
        $other = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $this->post(route('admin.school.update'), [
            'school_name' => 'My Institution', 'school_info' => 'Approved description',
            'school_logoo' => UploadedFile::fake()->image('school.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $name = DB::table('schools')->where('id', $school)->value('school_logo');
        $this->files[] = public_path('assets/uploads/school_logo/'.$name);
        $this->assertFileExists(end($this->files));
        $this->assertStringContainsString($name, institution_logo_url());
        $this->assertStringContainsString($name, branding_image_url('school_logo', true));
        $this->assertSame('Approved description', DB::table('schools')->where('id', $school)->value('school_info'));
        $this->assertNull(DB::table('schools')->where('id', $other)->value('school_logo'));
        $this->actingAs($this->makeAdminUser($other));
        $this->assertStringNotContainsString($name, institution_logo_url());
    }

    public function test_failed_upload_does_not_report_success_or_change_school_details(): void
    {
        $school = $this->makeSchool();
        $this->actingAs($this->makeAdminUser($school));
        $old = DB::table('schools')->where('id', $school)->value('title');
        $this->post(route('admin.school.update'), [
            'school_name' => 'Should not save', 'school_logoo' => UploadedFile::fake()->create('fake.png', 5, 'text/plain'),
        ])->assertSessionHasErrors('school_logoo')->assertSessionMissing('message');
        $this->assertSame($old, DB::table('schools')->where('id', $school)->value('title'));
    }

    public function test_empty_superadmin_submission_does_not_report_success(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => 1, 'school_id' => null]));
        $this->post(route('superadmin.logo.update'))->assertSessionHasErrors('logo')->assertSessionMissing('message');
    }

    public function test_system_email_logo_saves_a_filename_instead_of_a_temporary_upload_path(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => 1, 'school_id' => null]));
        $this->post(route('superadmin.system.update'), [
            'email_logo' => UploadedFile::fake()->image('email.jpg'),
            'system_title' => 'Approved title',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $name = get_settings('email_logo');
        $this->files[] = public_path('assets/uploads/email_logo/'.$name);
        $this->assertFileExists(end($this->files));
        $this->assertSame(basename($name), $name);
        $this->assertStringEndsWith('.jpg', $name);
        $this->assertSame('Approved title', get_settings('system_title'));
    }
}
