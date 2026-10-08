<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Stability repair M6 — upload handlers must validate an upload before calling
 * extension()/move()/MIME methods on it. A failed PHP upload (e.g. over
 * upload_max_filesize), a wrong type, or a script disguised as an image must give
 * a validation error — never HTTP 500, never a partially-saved record, never a
 * file written under public/.
 */
class UploadStabilityTest extends TestCase
{
    use StaffModuleTestHelper;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Mail::fake();
        $this->superAdmin = User::factory()->create(['role_id' => 1, 'school_id' => null, 'account_status' => 'active']);
    }

    /** An upload PHP reported as failed (the temp file does not exist). */
    private function failedUpload(string $name): UploadedFile
    {
        return new UploadedFile(tempnam(sys_get_temp_dir(), 'failed'), $name, null, UPLOAD_ERR_INI_SIZE, true);
    }

    /**
     * A real (non-fake) upload of a PHP script: Laravel's fake files report their MIME type from
     * the NAME, so only a real UploadedFile exercises the content sniffing production relies on.
     */
    private function script(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, '<?php echo "owned";');

        return new UploadedFile($path, $name, null, null, true);
    }

    private function publicFilesIn(string $dir): array
    {
        return File::isDirectory(public_path($dir)) ? array_map(fn ($f) => $f->getFilename(), File::files(public_path($dir))) : [];
    }

    public static function badFiles(): array
    {
        return ['failed php upload' => ['failed'], 'script disguised as png' => ['script-png'], 'php file' => ['script-php'], 'text file' => ['text']];
    }

    private function bad(string $kind, string $baseName = 'logo'): UploadedFile
    {
        return match ($kind) {
            'failed' => $this->failedUpload("{$baseName}.png"),
            'script-png' => $this->script("{$baseName}.png"),
            'script-php' => $this->script("{$baseName}.php"),
            'text' => UploadedFile::fake()->createWithContent("{$baseName}.txt", 'hello'),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_create_school_rejects_a_bad_logo_before_creating_anything(string $kind): void
    {
        $schoolsBefore = DB::table('schools')->count();
        $logosBefore = $this->publicFilesIn('assets/uploads/school_logo');

        $response = $this->actingAs($this->superAdmin)->from('/superadmin/school/create')->post(route('superadmin.school.create'), [
            'school_name' => 'Upload Test School', 'school_email' => 'upload-school@example.test', 'school_phone' => '1', 'school_address' => 'x',
            'school_info' => 'x', 'admin_name' => 'A', 'admin_email' => 'upload-admin@example.test', 'admin_password' => 'secret123',
            'school_logo' => $this->bad($kind),
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('school_logo');
        $this->assertSame($schoolsBefore, DB::table('schools')->count(), 'no school created');
        $this->assertSame($logosBefore, $this->publicFilesIn('assets/uploads/school_logo'), 'nothing written under public/');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_system_logo_update_rejects_a_bad_file(string $kind): void
    {
        DB::table('global_settings')->insert(['key' => 'dark_logo', 'value' => 'original.png']);

        $response = $this->actingAs($this->superAdmin)->from('/superadmin/settings/system')->post(route('superadmin.logo.update'), ['dark_logo' => $this->bad($kind)]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('dark_logo');
        $this->assertSame('original.png', DB::table('global_settings')->where('key', 'dark_logo')->value('value'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFiles')]
    public function test_system_settings_update_rejects_a_bad_file_before_saving_settings(string $kind): void
    {
        DB::table('global_settings')->insert(['key' => 'system_title', 'value' => 'Original Title']);

        $response = $this->actingAs($this->superAdmin)->from('/superadmin/settings/system')->post(route('superadmin.system.update'), [
            'system_title' => 'Changed Title', 'email_logo' => $this->bad($kind, 'email-logo'),
        ]);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('email_logo');
        $this->assertSame('Original Title', DB::table('global_settings')->where('key', 'system_title')->value('value'), 'no partial settings write');
    }

    public function test_valid_logo_upload_still_works(): void
    {
        DB::table('global_settings')->insert(['key' => 'dark_logo', 'value' => 'original.png']);

        $this->actingAs($this->superAdmin)->post(route('superadmin.logo.update'), ['dark_logo' => UploadedFile::fake()->image('logo.png')])->assertRedirect();

        $stored = DB::table('global_settings')->where('key', 'dark_logo')->value('value');
        $this->assertNotSame('original.png', $stored);
        $this->assertFileExists(public_path('assets/uploads/logo/' . $stored));
        @unlink(public_path('assets/uploads/logo/' . $stored));
    }

    public function test_staff_photo_failed_upload_is_a_controlled_error(): void
    {
        $school = $this->makeSchool();
        $admin = $this->makeAdminUser($school);

        $this->actingAs($admin)->from('/back')->post(route('admin.teacher.create'), [
            'email' => 'failed-photo@example.test', 'first_name' => 'F', 'last_name' => 'P', 'gender' => 'Male', 'blood_group' => 'o+',
            'birthday' => '01/01/1990', 'phone' => '0700', 'address' => 'x', 'photo' => $this->failedUpload('me.jpg'),
        ])->assertRedirect('/back')->assertSessionHas('error');
        $this->assertFalse(User::where('email', 'failed-photo@example.test')->exists());
    }
}
