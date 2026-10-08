<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * RBAC / security Phase 2C — user document upload/removal hardening and
 * parent-account tenant isolation.
 *
 * Every upload in this test goes to a throwaway directory: public_path() is
 * re-bound to a temp folder in setUp(), so nothing is ever written to the
 * application's real public/ directory.
 *
 * Authorization (which staff roles may manage documents/parents) is
 * deliberately unchanged here; this phase adds tenant isolation (target must
 * be in the caller's school, and a parent route only ever resolves a parent)
 * and a safe file policy.
 */
class DocumentAndParentTenantSecurityTest extends TestCase
{
    use StaffModuleTestHelper;

    private string $publicDir;
    private int $schoolA;
    private int $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Mail::fake();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2c-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

        $this->schoolA = $this->makeSchool(['title' => 'School A']);
        $this->schoolB = $this->makeSchool(['title' => 'School B']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function user(int $roleId, int $schoolId, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $roleId,
            'school_id' => $roleId === 1 ? null : $schoolId,
            'account_status' => 'active',
            'user_information' => json_encode(['phone' => '1', 'photo' => '']),
        ], $overrides));
    }

    private function docDir(User $owner): string
    {
        return $this->publicDir . "/assets/uploads/user-docs/{$owner->id}";
    }

    private function documents(User $owner): array
    {
        return json_decode((string) $owner->fresh()->documents, true) ?: [];
    }

    /** Every file anywhere under the temp public dir (relative names). */
    private function storedFiles(): array
    {
        return array_map(fn ($f) => $f->getRelativePathname(), File::allFiles($this->publicDir));
    }

    private function phpUpload(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'p2c');
        file_put_contents($path, "<?php echo 'owned'; ?>");

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function upload(User $actor, User $owner, UploadedFile $file, string $label = 'doc')
    {
        return $this->actingAs($actor)->post(route('admin.documents.upload', $owner->id), ['file_name' => $label, 'file' => $file]);
    }

    private function parentPayload(User $parent, array $overrides = []): array
    {
        return array_merge([
            'name' => $parent->name, 'email' => $parent->email, 'gender' => 'Female', 'blood_group' => 'o+',
            'birthday' => '01/01/1980', 'phone' => '0700000002', 'address' => '1 Test Street',
        ], $overrides);
    }

    private function seedDocument(User $owner, string $key, string $file): string
    {
        File::ensureDirectoryExists($this->docDir($owner));
        $path = $this->docDir($owner) . "/{$file}";
        file_put_contents($path, '%PDF-1.4 seeded');
        $owner->forceFill(['documents' => json_encode([$key => $file])])->save();

        return $path;
    }

    // ── Documents: legitimate use ────────────────────────────────────────────

    public function test_same_school_upload_of_allowed_types_is_stored_under_a_generated_name(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        $this->upload($admin, $owner, UploadedFile::fake()->create('My CV.pdf', 100, 'application/pdf'), 'Curriculum Vitae');
        $this->upload($admin, $owner, UploadedFile::fake()->image('passport photo.jpg'), 'passport');
        $this->upload($admin, $owner, UploadedFile::fake()->image('scan.png'), 'scan');

        $docs = $this->documents($owner);
        $this->assertSame(['curriculum-vitae', 'passport', 'scan'], array_keys($docs));
        foreach (['curriculum-vitae' => 'pdf', 'passport' => 'jpg', 'scan' => 'png'] as $key => $ext) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{40}\.' . $ext . '$/', $docs[$key], $key);
            $this->assertFileExists($this->docDir($owner) . '/' . $docs[$key]);
        }
        $this->assertNotContains('My CV.pdf', $docs);
    }

    public function test_two_uploads_with_the_same_client_name_never_overwrite_each_other(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        $this->upload($admin, $owner, UploadedFile::fake()->create('same.pdf', 10, 'application/pdf'), 'first');
        $this->upload($admin, $owner, UploadedFile::fake()->create('same.pdf', 10, 'application/pdf'), 'second');

        $docs = $this->documents($owner);
        $this->assertNotSame($docs['first'], $docs['second']);
        $this->assertFileExists($this->docDir($owner) . '/' . $docs['first']);
        $this->assertFileExists($this->docDir($owner) . '/' . $docs['second']);
    }

    // ── Documents: unsafe files ──────────────────────────────────────────────

    public function test_server_side_scripts_are_rejected_whatever_they_are_called(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        foreach (['shell.php', 'shell.phtml', 'shell.phar', 'shell.php5', 'shell.php7', 'shell.cgi', 'shell.pl', 'shell.py', 'shell.sh',
                  'shell.jpg', 'shell.pdf', 'shell.php.jpg', 'shell.jpg.php', 'SHELL.PHP'] as $name) {
            $this->upload($admin, $owner, $this->phpUpload($name), 'x');
            $this->assertSame([], $this->documents($owner), "{$name} must be rejected");
            $this->assertSame([], $this->storedFiles(), "{$name} must not be written");
        }
    }

    public function test_a_real_image_with_a_double_extension_is_stored_without_the_dangerous_part(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        $this->upload($admin, $owner, UploadedFile::fake()->image('photo.php.jpg'), 'photo');

        $stored = $this->documents($owner)['photo'] ?? '';
        $this->assertStringEndsWith('.jpg', $stored);
        $this->assertStringNotContainsString('php', $stored);
    }

    public function test_unsupported_types_and_oversized_files_are_rejected(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        $rejected = [
            'text'   => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
            'html'   => UploadedFile::fake()->create('page.html', 1, 'text/html'),
            'svg'    => UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'),
            'docx'   => UploadedFile::fake()->create('letter.docx', 1, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'exe'    => UploadedFile::fake()->create('setup.exe', 1, 'application/x-msdownload'),
            'large'  => UploadedFile::fake()->create('huge.pdf', 5 * 1024 + 1, 'application/pdf'),
        ];

        foreach ($rejected as $label => $file) {
            $this->upload($admin, $owner, $file, $label);
            $this->assertArrayNotHasKey($label, $this->documents($owner), "{$label} must be rejected");
        }
        $this->assertSame([], $this->storedFiles());
    }

    public function test_traversal_in_client_filename_or_label_cannot_escape_the_owners_directory(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);

        $this->upload($admin, $owner, UploadedFile::fake()->create('..\\..\\..\\evil.pdf', 10, 'application/pdf'), '../../../evil');

        foreach ($this->storedFiles() as $relative) {
            $this->assertStringStartsWith('assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'user-docs' . DIRECTORY_SEPARATOR . $owner->id . DIRECTORY_SEPARATOR, $relative);
        }
        foreach ($this->documents($owner) as $key => $file) {
            $this->assertStringNotContainsString('..', $key . $file);
            $this->assertStringNotContainsString('/', $key . $file);
            $this->assertStringNotContainsString('\\', $key . $file);
        }
    }

    // ── Documents: tenant isolation ──────────────────────────────────────────

    public function test_documents_cannot_be_uploaded_viewed_or_removed_across_schools(): void
    {
        $actor = $this->user(2, $this->schoolA);
        $foreign = $this->user(6, $this->schoolB);
        $seeded = $this->seedDocument($foreign, 'contract', 'contract.pdf');

        $this->upload($actor, $foreign, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'intrusion')->assertNotFound();
        $this->assertSame(['contract' => 'contract.pdf'], $this->documents($foreign));

        $this->actingAs($actor)->get(route('admin.documents.remove', ['id' => $foreign->id, 'file_name' => 'contract']))->assertNotFound();
        $this->assertFileExists($seeded);
        $this->assertSame(['contract' => 'contract.pdf'], $this->documents($foreign));

        $this->actingAs($actor)->get(route('admin.parent.documents', $foreign->id))->assertNotFound();
    }

    public function test_removal_is_limited_to_the_owners_listed_document(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $owner = $this->user(3, $this->schoolA);
        $other = $this->user(3, $this->schoolA);
        $ownerFile = $this->seedDocument($owner, 'cv', 'cv.pdf');
        $otherFile = $this->seedDocument($other, 'cv', 'cv.pdf');
        $outside = $this->publicDir . '/keep-me.txt';
        file_put_contents($outside, 'app file');

        // Unknown key or traversal in the route segment: nothing is deleted.
        foreach (['..%2F..%2F..%2Fkeep-me.txt', 'missing'] as $key) {
            $this->actingAs($admin)->get('/admin/documents-remove/' . $owner->id . '/' . $key);
        }
        // A document entry that points outside the owner's folder is never followed.
        // (user-docs/{id}/../../../../ is the public root, where keep-me.txt sits.)
        $owner->forceFill(['documents' => json_encode(['cv' => 'cv.pdf', 'evil' => '../../../../keep-me.txt'])])->save();
        $this->actingAs($admin)->get(route('admin.documents.remove', ['id' => $owner->id, 'file_name' => 'evil']));

        $this->assertFileExists($outside);
        $this->assertFileExists($ownerFile);
        $this->assertFileExists($otherFile);

        // The legitimate removal (legacy original-name entry) still works.
        $this->actingAs($admin)->get(route('admin.documents.remove', ['id' => $owner->id, 'file_name' => 'cv']));
        $this->assertFileDoesNotExist($ownerFile);
        $this->assertArrayNotHasKey('cv', $this->documents($owner));
        $this->assertFileExists($otherFile);
    }

    // ── Parents: the universal-account takeover through parent routes ────────

    public function test_parent_update_cannot_be_used_to_take_over_a_non_parent_account(): void
    {
        Notification::fake();
        $teacher = $this->user(3, $this->schoolA);
        $superAdmin = $this->user(1, $this->schoolA, ['email' => 'root@platform.test']);
        $schoolAdmin = $this->user(2, $this->schoolA, ['email' => 'head@school.test']);

        foreach ([$superAdmin, $schoolAdmin] as $victim) {
            $this->actingAs($teacher)->post(route('admin.parent.update', $victim->id), $this->parentPayload($victim, ['email' => "hijack{$victim->id}@evil.test"]))->assertNotFound();
            $this->assertNotSame("hijack{$victim->id}@evil.test", $victim->fresh()->email);

            auth()->logout();
            $this->post(route('password.email'), ['email' => "hijack{$victim->id}@evil.test"]);
            Notification::assertNotSentTo($victim->fresh(), ResetPassword::class);
        }
    }

    public function test_parent_routes_only_resolve_same_school_parents(): void
    {
        $actor = $this->user(2, $this->schoolA);
        $foreignParent = $this->user(6, $this->schoolB);
        $sameSchoolAdmin = $this->user(2, $this->schoolA);

        $this->actingAs($actor)->get(route('admin.parent_edit_modal', $foreignParent->id))->assertNotFound();
        $this->actingAs($actor)->post(route('admin.parent.update', $foreignParent->id), $this->parentPayload($foreignParent, ['name' => 'Changed', 'email' => 'moved@evil.test']))->assertNotFound();
        $this->actingAs($actor)->get(route('admin.parent.delete', $foreignParent->id))->assertNotFound();
        $this->actingAs($actor)->get(route('admin.parent.delete', $sameSchoolAdmin->id))->assertNotFound();

        $this->assertNotSame('Changed', $foreignParent->fresh()->name);
        $this->assertNotSame('moved@evil.test', $foreignParent->fresh()->email);
        $this->assertTrue(User::whereKey($foreignParent->id)->exists());
        $this->assertTrue(User::whereKey($sameSchoolAdmin->id)->exists());

        $hash = $foreignParent->password;
        $this->actingAs($actor)->post(route('admin.user_password'), ['user_id' => $foreignParent->id, 'password' => 'owned-123']);
        $this->actingAs($actor)->get(route('admin.account_disable', $foreignParent->id));
        $this->assertSame($hash, $foreignParent->fresh()->password);
        $this->assertSame('active', $foreignParent->fresh()->account_status);
    }

    public function test_parent_update_and_create_only_link_same_school_students(): void
    {
        $admin = $this->user(2, $this->schoolA);
        $parent = $this->user(6, $this->schoolA);
        $ownStudent = $this->user(7, $this->schoolA);
        $foreignStudent = $this->user(7, $this->schoolB);
        $sameSchoolTeacher = $this->user(3, $this->schoolA);

        $this->actingAs($admin)->post(route('admin.parent.update', $parent->id), $this->parentPayload($parent, [
            'student_id' => [$ownStudent->id, $foreignStudent->id, $sameSchoolTeacher->id],
        ]));

        $this->assertSame($parent->id, (int) $ownStudent->fresh()->parent_id);
        $this->assertNull($foreignStudent->fresh()->parent_id);
        $this->assertNull($sameSchoolTeacher->fresh()->parent_id);

        $this->actingAs($admin)->post(route('admin.parent.create'), [
            'name' => 'New Parent', 'email' => 'new.parent@example.test', 'password' => 'secret-123',
            'gender' => 'Male', 'blood_group' => 'o+', 'birthday' => '01/01/1980', 'phone' => '1', 'address' => 'a',
            'student_id' => [$foreignStudent->id],
        ]);
        $this->assertTrue(User::where('email', 'new.parent@example.test')->exists(), 'same-school parent creation still works');
        $this->assertNull($foreignStudent->fresh()->parent_id);
    }

    public function test_parent_email_changes_must_stay_unique(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']); // the live users table has no unique index on email
        });
        $admin = $this->user(2, $this->schoolA, ['email' => 'head@school.test']);
        $parent = $this->user(6, $this->schoolA);
        $original = $parent->email;

        $this->actingAs($admin)->post(route('admin.parent.update', $parent->id), $this->parentPayload($parent, ['email' => 'head@school.test']));

        $this->assertSame($original, $parent->fresh()->email);
        $this->assertSame(1, User::where('email', 'head@school.test')->count());
    }

    public function test_same_school_parent_management_is_unchanged(): void
    {
        // Existing reach is preserved: any staff role that passes AdminMiddleware
        // could manage same-school parents before, and still can.
        foreach ([2, 3] as $roleId) {
            $actor = $this->user($roleId, $this->schoolA);
            $parent = $this->user(6, $this->schoolA);

            $this->actingAs($actor)->post(route('admin.parent.update', $parent->id), $this->parentPayload($parent, ['name' => 'Renamed', 'email' => "renamed{$roleId}@example.test"]));
            $this->assertSame('Renamed', $parent->fresh()->name, "role {$roleId}");
            $this->assertSame("renamed{$roleId}@example.test", $parent->fresh()->email, "role {$roleId}");

            $this->upload($actor, $parent, UploadedFile::fake()->create('id.pdf', 10, 'application/pdf'), 'national-id');
            $this->assertArrayHasKey('national-id', $this->documents($parent), "role {$roleId}");

            $this->actingAs($actor)->get(route('admin.parent.delete', $parent->id));
            $this->assertFalse(User::whereKey($parent->id)->exists(), "role {$roleId}");
        }
    }
}
