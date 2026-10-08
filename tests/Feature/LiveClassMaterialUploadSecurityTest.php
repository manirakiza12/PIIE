<?php

namespace Tests\Feature;

use App\Models\LiveClassMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * Security Phase 2F — live-class material uploads.
 *
 * The material's stored name keeps the client extension, while the mimes rule
 * only checks the file's content, so a genuine image or document *named*
 * x.html / x.svg / x.js used to be stored with that extension in a
 * web-served folder. The client extension must now also be one of the
 * allowed material types. Uploads go to a temp public path.
 */
class LiveClassMaterialUploadSecurityTest extends TestCase
{
    use LiveClassTestHelper;

    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piie-phase2f-lc-' . uniqid();
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        parent::tearDown();
    }

    private function realPngNamed(string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        $image = imagecreatetruecolor(4, 4);
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    public function test_material_uploads_keep_only_allowed_extensions(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeStaffUser($schoolId, 2);
        $liveClass = $this->makeLiveClass($schoolId);
        $dir = $this->publicDir . DIRECTORY_SEPARATOR . LiveClassMaterial::UPLOAD_DIR;

        foreach (['slides.html', 'slides.svg', 'slides.js', 'slides.shtml'] as $name) {
            $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
                'type' => 'file', 'title' => 'Slides', 'file' => $this->realPngNamed($name),
            ]);
            $this->assertSame(0, LiveClassMaterial::count(), "{$name}: no material row");
            $this->assertSame([], File::exists($dir) ? File::files($dir) : [], "{$name}: nothing written");
        }

        // Allowed types still upload. (Real-file uploads are covered by LiveClassMaterialRealUploadTest.)
        $this->actingAs($admin)->post(route('admin.live_classes.materials.store', $liveClass->id), [
            'type' => 'file', 'title' => 'Slides', 'file' => UploadedFile::fake()->create('slides.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.pdf', LiveClassMaterial::firstOrFail()->stored_name);
    }
}
