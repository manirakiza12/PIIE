<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\ProgrammeGalleryImage;
use App\Models\User;
use App\Support\ProgrammeCatalogue\ProgrammeGalleryImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

class ProgrammeGalleryImageTest extends TestCase
{
    use AdmissionsTestHelper;

    private string $publicDir;
    private Programme $programme;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        (require base_path('database/migrations/2026_10_13_100000_create_programme_gallery_images_table.php'))->up();
        $this->publicDir = sys_get_temp_dir().'/piie-gallery-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->publicDir);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);
        $school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($school);
        $this->programme = Programme::findOrFail($this->makeProgramme($school));
    }

    protected function tearDown(): void
    {
        if (isset($this->publicDir)) {
            File::deleteDirectory($this->publicDir);
        }
        parent::tearDown();
    }

    private function upload(string $format = 'jpg'): UploadedFile
    {
        $path = $this->publicDir.'/fixture-'.bin2hex(random_bytes(4)).'.'.$format;
        $image = imagecreatetruecolor(320, 240);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));
        match ($format) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path),
        };
        imagedestroy($image);
        return new UploadedFile($path, 'programme.'.$format, null, null, true);
    }

    private function store(UploadedFile $file, ?Programme $programme = null)
    {
        return $this->actingAs($this->admin)->postJson(
            route('admin.programmes.gallery.store', ($programme ?? $this->programme)->id),
            ['gallery_image' => $file]
        );
    }

    private function editorPayload(array $extra = []): array
    {
        return $extra + ['code' => 'COMBINED', 'name' => 'Combined programme', 'level' => 'Diploma', 'mode' => 'ODEL'];
    }

    public function test_new_programme_saves_details_cover_and_two_gallery_images_together(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.programmes.store'), $this->editorPayload([
            'cover_image' => $this->upload('png'), 'gallery_images' => [$this->upload(), $this->upload('webp')],
        ]))->assertOk();
        $programme = Programme::where('code', 'COMBINED')->firstOrFail();
        $this->assertNotNull(app(\App\Support\ProgrammeCatalogue\ProgrammeCoverImage::class)->url($programme));
        $this->assertSame(2, app(ProgrammeGalleryImageService::class)->countFor($programme));
        $this->assertSame($this->admin->school_id, $programme->school_id);
    }

    public function test_combined_update_replaces_images_and_preserves_an_unchanged_cover(): void
    {
        $covers = app(\App\Support\ProgrammeCatalogue\ProgrammeCoverImage::class);
        $covers->set($this->programme, $this->upload());
        $oldCover = $this->programme->cover_image_path;
        $gallery = app(ProgrammeGalleryImageService::class);
        $old = $gallery->add($this->programme, $this->upload());
        $oldPath = $gallery->absolutePath($old->path);
        $this->actingAs($this->admin)->postJson(route('admin.programmes.update', $this->programme->id), $this->editorPayload([
            'remove_gallery_images' => [$old->id], 'gallery_images' => [$this->upload('png')],
        ]))->assertOk();
        $this->assertSame($oldCover, $this->programme->fresh()->cover_image_path);
        $this->assertFileExists($covers->absolutePath($oldCover));
        $this->assertFileDoesNotExist($oldPath);
        $this->assertSame(1, $gallery->countFor($this->programme));
        $this->assertSame('Combined programme', $this->programme->fresh()->name);
    }

    public function test_invalid_image_does_not_create_a_partial_programme(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.programmes.store'), $this->editorPayload([
            'cover_image' => $this->upload(), 'gallery_images' => [UploadedFile::fake()->create('bad.png', 1, 'image/png')],
        ]))->assertUnprocessable()->assertJsonValidationErrors('gallery_images.0');
        $this->assertNull(Programme::where('code', 'COMBINED')->first());
        $this->assertSame(0, ProgrammeGalleryImage::count());
    }

    public function test_combined_editor_rejects_foreign_image_removal_without_changing_details(): void
    {
        $foreign = Programme::findOrFail($this->makeProgramme($this->makeSchool()));
        $image = app(ProgrammeGalleryImageService::class)->add($foreign, $this->upload());
        $name = $this->programme->name;
        $this->actingAs($this->admin)->postJson(route('admin.programmes.update', $this->programme->id), $this->editorPayload([
            'remove_gallery_images' => [$image->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('remove_gallery_images');
        $this->assertSame($name, $this->programme->fresh()->name);
        $this->assertNotNull($image->fresh());
    }

    public function test_failed_publication_rolls_back_details_and_images_and_retains_original_files(): void
    {
        $covers = app(\App\Support\ProgrammeCatalogue\ProgrammeCoverImage::class);
        $covers->set($this->programme, $this->upload());
        $coverPath = $covers->absolutePath($this->programme->cover_image_path);
        $gallery = app(ProgrammeGalleryImageService::class);
        $image = $gallery->add($this->programme, $this->upload());
        $imagePath = $gallery->absolutePath($image->path);
        $name = $this->programme->name;
        $publisher = $this->createMock(\App\Support\ProgrammeCatalogue\ProgrammePublisher::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('Synthetic publication failure'));
        $this->app->instance(\App\Support\ProgrammeCatalogue\ProgrammePublisher::class, $publisher);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->postJson(route('admin.programmes.update', $this->programme->id), $this->editorPayload([
                'cover_image' => $this->upload('png'), 'remove_gallery_images' => [$image->id],
                'gallery_images' => [$this->upload('webp')], 'is_published' => 1,
            ]));
            $this->fail('Publication should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic publication failure', $e->getMessage());
        }
        $this->assertSame($name, $this->programme->fresh()->name);
        $this->assertNotNull($image->fresh());
        $this->assertFileExists($coverPath);
        $this->assertFileExists($imagePath);
        $this->assertCount(1, File::files(dirname($coverPath)));
        $this->assertCount(1, File::files(dirname($imagePath)));
    }

    public function test_supported_formats_are_reencoded_and_listed_without_changing_the_programme(): void
    {
        $before = $this->programme->getAttributes();
        foreach (['jpg', 'png', 'webp'] as $format) {
            $id = $this->store($this->upload($format))->assertCreated()->json('id');
            $image = ProgrammeGalleryImage::findOrFail($id);
            $path = app(ProgrammeGalleryImageService::class)->absolutePath($image->path);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.(jpe?g|png|webp)$/', $image->path);
            $this->assertSame([1600, 900], array_slice(getimagesize($path), 0, 2));
            $this->assertSame(filesize($path), $image->size);
            $this->getJson(route('admin.programmes.gallery.index', $this->programme->id))
                ->assertOk()->assertJsonPath('images.0.id', $id)->assertJsonPath('remaining', 1);
            $this->deleteJson(route('admin.programmes.gallery.destroy', [$this->programme->id, $id]))->assertOk();
            $this->assertFileDoesNotExist($path);
        }
        $this->assertSame($before, $this->programme->fresh()->getAttributes());
    }

    public function test_editor_and_preview_render_the_gallery_and_capacity(): void
    {
        $id = $this->store($this->upload())->assertCreated()->json('id');
        $url = app(ProgrammeGalleryImageService::class)->url(ProgrammeGalleryImage::findOrFail($id));
        $this->get(route('admin.programmes.open_modal', ['id' => $this->programme->id]))->assertOk()
            ->assertSee('piie-programme-gallery')->assertSee($url, false)->assertSee('1 slot left');
        $this->get(route('admin.programmes.preview', $this->programme->id))->assertOk()->assertSee($url, false);
    }

    public function test_limit_removal_and_slot_reuse_preserve_the_other_image(): void
    {
        $first = $this->store($this->upload())->assertCreated()->json('id');
        $second = $this->store($this->upload())->assertCreated()->json('id');
        $secondImage = ProgrammeGalleryImage::findOrFail($second);
        $this->store($this->upload())->assertUnprocessable()->assertJsonValidationErrors('gallery');
        $this->assertSame(2, ProgrammeGalleryImage::count());
        $this->deleteJson(route('admin.programmes.gallery.destroy', [$this->programme->id, $first]))->assertOk();
        $replacement = $this->store($this->upload())->assertCreated()->json('id');
        $this->assertSame(1, ProgrammeGalleryImage::findOrFail($replacement)->sort_order);
        $this->assertFileExists(app(ProgrammeGalleryImageService::class)->absolutePath($secondImage->path));
        $this->getJson(route('admin.programmes.gallery.index', $this->programme->id))
            ->assertJsonPath('images.0.id', $replacement)->assertJsonPath('images.1.id', $second)
            ->assertJsonPath('remaining', 0);
    }

    public function test_foreign_programmes_and_foreign_image_ids_cannot_be_changed(): void
    {
        $foreign = Programme::findOrFail($this->makeProgramme($this->makeSchool()));
        $service = app(ProgrammeGalleryImageService::class);
        $image = $service->add($foreign, $this->upload());
        $this->actingAs($this->admin)->getJson(route('admin.programmes.gallery.index', $foreign->id))->assertNotFound();
        $this->store($this->upload(), $foreign)->assertNotFound();
        $this->deleteJson(route('admin.programmes.gallery.destroy', [$foreign->id, $image->id]))->assertNotFound();
        $this->deleteJson(route('admin.programmes.gallery.destroy', [$this->programme->id, $image->id]))->assertOk();
        $this->assertNotNull($image->fresh());
        $this->assertFileExists($service->absolutePath($image->path));
    }

    public function test_guests_and_students_cannot_access_gallery_routes(): void
    {
        $url = route('admin.programmes.gallery.index', $this->programme->id);
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, ['gallery_image' => $this->upload()])->assertUnauthorized();
        $this->deleteJson($url.'/1')->assertUnauthorized();
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $this->programme->school_id, 'account_status' => 'active']);
        $this->actingAs($student)->get($url)->assertRedirect();
        $this->post($url, ['gallery_image' => $this->upload()])->assertRedirect();
        $this->delete($url.'/1')->assertRedirect();
        $this->assertSame(0, ProgrammeGalleryImage::count());
    }

    public function test_invalid_content_and_oversized_uploads_report_the_gallery_field(): void
    {
        $path = $this->publicDir.'/invalid.jpg';
        File::put($path, '<?php echo "invalid";');
        $this->store(new UploadedFile($path, 'invalid.jpg', 'image/jpeg', null, true))
            ->assertUnprocessable()->assertJsonValidationErrors('gallery_image');
        // Valid JPEG bytes with a mismatched extension reach the optimiser's validation.
        $jpeg = $this->upload();
        $this->store(new UploadedFile($jpeg->getPathname(), 'invalid.png', 'image/jpeg', null, true))
            ->assertUnprocessable()->assertJsonValidationErrors('gallery_image');
        $this->store(UploadedFile::fake()->create('large.jpg', 4097, 'image/jpeg'))
            ->assertUnprocessable()->assertJsonValidationErrors('gallery_image');
        $this->assertSame(0, ProgrammeGalleryImage::count());
    }

    public function test_missing_files_are_hidden_but_still_consume_capacity(): void
    {
        $service = app(ProgrammeGalleryImageService::class);
        $image = $service->add($this->programme, $this->upload());
        File::delete($service->absolutePath($image->path));
        $this->assertNull($service->url($image));
        $this->actingAs($this->admin)->getJson(route('admin.programmes.gallery.index', $this->programme->id))
            ->assertOk()->assertJsonPath('images.0.id', $image->id)->assertJsonPath('images.0.url', null)->assertJsonPath('remaining', 1);
        $this->get(route('admin.programmes.open_modal', ['id' => $this->programme->id]))
            ->assertOk()->assertSee('Image file missing')
            ->assertSee('name="remove_gallery_images[]"', false)->assertSee('value="'.$image->id.'"', false);
        $this->deleteJson(route('admin.programmes.gallery.destroy', [$this->programme->id, $image->id]))->assertOk();
        $this->assertSame(0, ProgrammeGalleryImage::count());
    }

    public function test_deleting_a_programme_removes_only_its_gallery_records_and_files(): void
    {
        $service = app(ProgrammeGalleryImageService::class);
        $image = $service->add($this->programme, $this->upload());
        $foreign = Programme::findOrFail($this->makeProgramme($this->makeSchool()));
        $foreignImage = $service->add($foreign, $this->upload());
        $path = $service->absolutePath($image->path);
        $this->actingAs($this->admin)->delete(route('admin.programmes.destroy', $this->programme->id), ['confirm_deletion' => (string) $this->programme->code])->assertRedirect();
        $this->assertNull($this->programme->fresh());
        $this->assertNull($image->fresh());
        $this->assertFileDoesNotExist($path);
        $this->assertNotNull($foreignImage->fresh());
        $this->assertFileExists($service->absolutePath($foreignImage->path));
    }

    public function test_paths_outside_gallery_are_neither_exposed_nor_deleted(): void
    {
        $outside = public_path('assets/uploads/private.jpg');
        File::ensureDirectoryExists(dirname($outside));
        File::put($outside, 'must remain');
        $image = ProgrammeGalleryImage::create([
            'school_id' => $this->programme->school_id, 'programme_id' => $this->programme->id,
            'sort_order' => 1, 'path' => '../private.jpg',
        ]);
        $service = app(ProgrammeGalleryImageService::class);
        $this->assertNull($service->url($image));
        $this->assertCount(0, $service->images($this->programme));
        $service->remove($this->programme, $image->id);
        $this->assertFileExists($outside);
    }

    public function test_missing_table_degrades_gracefully_and_migration_is_repeatable(): void
    {
        $migration = require base_path('database/migrations/2026_10_13_100000_create_programme_gallery_images_table.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('programme_gallery_images'));
        Schema::drop('programme_gallery_images');
        $this->actingAs($this->admin)->getJson(route('admin.programmes.gallery.index', $this->programme->id))
            ->assertOk()->assertJsonPath('images', []);
        $this->store($this->upload())->assertUnprocessable()->assertJsonValidationErrors('gallery_image');
    }
}
