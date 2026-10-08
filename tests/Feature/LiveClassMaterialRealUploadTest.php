<?php

namespace Tests\Feature;

use App\Models\LiveClassMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * Pre-RBAC cleanup E — live-class material upload with a REAL file.
 *
 * LiveClassController::storeMaterial() read the file's MIME type and size
 * after move(), when the temporary upload no longer exists, so every real
 * upload failed with a 500 (only UploadedFile::fake() survived, which is why
 * earlier tests used it). Metadata is now read before the move. The Phase 2F
 * extension check and the mimes/max validation are unchanged.
 *
 * Files go to an isolated temporary public path.
 */
class LiveClassMaterialRealUploadTest extends TestCase
{
    use LiveClassTestHelper;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-precleanup-lc-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    /** A real PNG on disk, uploaded under $clientName (not a fake). */
    private function realPng(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** A real plain-text file on disk (content is not an allowed material type). */
    private function realText(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, "<?php echo 'not a document';");

        return new UploadedFile($path, $clientName, null, null, true);
    }

    public function test_a_teacher_uploads_a_real_file_that_students_can_reach_and_another_school_cannot(): void
    {
        $schoolId = $this->makeSchool();
        $teacher = $this->makeStaffUser($schoolId, 3);
        $liveClass = $this->makeLiveClass($schoolId, ['teacher_id' => $teacher->id, 'created_by' => $teacher->id, 'is_published' => 1]);
        $upload = $this->realPng('Week 1 Slides.png');
        $expectedSize = filesize($upload->getPathname());

        $response = $this->actingAs($teacher)->post(route('teacher.live_classes.materials.store', $liveClass->id), [
            'type' => 'file', 'title' => 'Week 1 Slides', 'file' => $upload,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Stored, and the database row points at the stored file with correct metadata.
        $material = LiveClassMaterial::firstOrFail();
        $this->assertSame('file', $material->type);
        $this->assertSame('Week 1 Slides.png', $material->original_name);
        $this->assertStringStartsWith('lcm' . $liveClass->id . '_', $material->stored_name);
        $this->assertStringEndsWith('.png', $material->stored_name);
        $this->assertSame('image/png', $material->mime_type);
        $this->assertSame($expectedSize, (int) $material->size_bytes);
        $this->assertFileExists($material->absolute_path);
        $norm = fn (string $path) => str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $this->assertSame($norm($this->publicDir . '/' . LiveClassMaterial::UPLOAD_DIR . '/' . $material->stored_name), $norm($material->absolute_path));

        // An authorized student of the school sees it (with its link).
        $student = $this->makeStudentUser($schoolId);
        $page = $this->actingAs($student)->get(route('student.live_classes.materials', $liveClass->id));
        $page->assertOk();
        $page->assertSee('Week 1 Slides');
        $page->assertSee($material->stored_name);

        // Another school's student and teacher cannot reach it.
        $otherSchool = $this->makeSchool(['title' => 'Other School']);
        $this->actingAs($this->makeStudentUser($otherSchool))->get(route('student.live_classes.materials', $liveClass->id))->assertNotFound();
        $this->actingAs($this->makeStaffUser($otherSchool, 3))->get(route('teacher.live_classes.materials', $liveClass->id))->assertNotFound();
        $this->actingAs($this->makeStaffUser($otherSchool, 3))->post(route('teacher.live_classes.materials.store', $liveClass->id), [
            'type' => 'file', 'title' => 'Planted', 'file' => $this->realPng('planted.png'),
        ])->assertNotFound();
        $this->actingAs($this->makeStaffUser($otherSchool, 2))->delete(route('admin.live_classes.materials.destroy', $material->id))->assertNotFound();
        $this->assertSame(1, LiveClassMaterial::count());
        $this->assertFileExists($material->absolute_path);

        // Deletion follows the existing design: row and file removed.
        $this->actingAs($teacher)->delete(route('teacher.live_classes.materials.destroy', $material->id))->assertRedirect();
        $this->assertSame(0, LiveClassMaterial::count());
        $this->assertFileDoesNotExist($material->absolute_path);
    }

    public function test_a_real_image_is_rejected_as_a_recording(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        // Recordings accept different types; a real image is not one of them.
        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'type' => 'file', 'category' => LiveClassMaterial::CATEGORY_RECORDING, 'title' => 'Rec', 'file' => $this->realPng('rec.png'),
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, LiveClassMaterial::count());
    }

    public function test_disallowed_real_uploads_are_rejected_and_nothing_is_written(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);
        $dir = $this->publicDir . DIRECTORY_SEPARATOR . LiveClassMaterial::UPLOAD_DIR;

        // Disallowed content, with an allowed-looking and a script name.
        foreach (['notes.pdf', 'shell.php'] as $name) {
            $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
                'type' => 'file', 'title' => 'Bad', 'file' => $this->realText($name),
            ])->assertSessionHasErrors('file');
        }
        // Allowed content under a web-executable name (Phase 2F rule).
        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'type' => 'file', 'title' => 'Bad', 'file' => $this->realPng('slides.html'),
        ]);

        $this->assertSame(0, LiveClassMaterial::count());
        $this->assertSame([], File::exists($dir) ? File::files($dir) : []);
    }

    public function test_a_second_real_upload_is_stored_alongside_the_first(): void
    {
        // Existing design: materials are added, not replaced — each upload is its own row and file.
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);

        foreach (['one.png', 'two.png'] as $name) {
            $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
                'type' => 'file', 'title' => $name, 'file' => $this->realPng($name),
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, LiveClassMaterial::count());
        foreach (LiveClassMaterial::all() as $material) {
            $this->assertFileExists($material->absolute_path);
        }
    }
}
