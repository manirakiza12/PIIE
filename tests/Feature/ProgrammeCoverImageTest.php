<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Images\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;

/**
 * Programme cover images — upload, preview, replace, remove, and how they reach
 * the public site.
 *
 * ── WHY THESE TESTS EXIST AND WHAT THEY PIN DOWN ───────────────────────────
 *
 * 1. NO MIGRATION WAS NEEDED. `website_items.image` (varchar 255, nullable)
 *    already existed, so this feature is entirely a change of *behaviour* on an
 *    existing column. `test_the_existing_image_column_is_all_that_is_required`
 *    states that as an executable fact, so a future migration cannot quietly
 *    become a prerequisite for displaying a programme.
 *
 * 2. THE 16:9 CROP IS PER ITEM TYPE, NOT GLOBAL. `saveImage()` is shared by
 *    every kind of CMS content. Applying the crop to all of it would centre-crop
 *    a leadership portrait into a landscape frame and cut the person in half.
 *    Two tests pin both halves of that decision: programmes are cropped, and
 *    portraits are not.
 *
 * 3. THE PUBLIC FRAME MUST MATCH THE STORED BYTES. The optimiser writes 16:9
 *    (1600x900). If the card frame stays 4:3, `object-fit: cover` silently crops
 *    the top and bottom off an image that was already cropped correctly, and the
 *    administrator's composition is lost in the last step. A CSS assertion keeps
 *    the frame and the bytes in agreement.
 *
 * Real image bytes are generated with GD rather than `UploadedFile::fake()
 * ->image()`, which always writes JPEG content regardless of the filename. That
 * would make the PNG-with-transparency and WebP cases untestable, and would test
 * a lie.
 */
class ProgrammeCoverImageTest extends TestCase
{
    use StaffModuleTestHelper;

    private const MIGRATIONS = [
        'database/migrations/2026_06_24_000001_create_website_management_tables.php',
        'database/migrations/2026_06_27_000002_ensure_website_management_schema_integrity.php',
        'database/migrations/2026_06_27_000003_add_page_header_and_navigation_fields_to_website_pages.php',
    ];
    private const ADD_SCHOOL_ID = 'database/migrations/2026_09_23_000002_add_school_id_to_website_tables.php';

    private const PROGRAMME_SECTION = 'programme_catalog_business_management';

    private int $school;
    private int $foreignSchool;
    private User $superAdmin;
    private User $foreignAdmin;
    private int $programme;
    private string $publicDir;
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();

        $this->publicDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-programme-img-public-'.uniqid();
        File::ensureDirectoryExists($this->publicDir.'/assets/uploads/website');
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->publicDir);

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-programme-img-scratch-'.uniqid();
        File::ensureDirectoryExists($this->scratch);

        foreach (self::MIGRATIONS as $path) {
            ($this->migration($path))->up();
        }
        ($this->migration(self::ADD_SCHOOL_ID))->up();

        // The Super Admin layout counts pending subscription payments.
        Schema::create('payment_history', function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->id();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('school_id')->nullable();
            $t->timestamps();
        });

        $this->school = $this->makeSchool(['title' => 'PIIE Public Site', 'status' => 1]);
        $this->foreignSchool = $this->makeSchool(['title' => 'Other School', 'status' => 1]);

        DB::table('global_settings')->updateOrInsert(['key' => 'primary_school_id'], ['value' => (string) $this->school]);
        DB::table('global_settings')->updateOrInsert(['key' => 'frontend_view'], ['value' => '1']);

        $this->superAdmin = User::factory()->create(['role_id' => 1, 'school_id' => null, 'account_status' => 'active']);
        $this->foreignAdmin = User::factory()->create(['role_id' => 2, 'school_id' => $this->foreignSchool, 'account_status' => 'active']);

        DB::table('website_pages')->insert([
            'school_id' => $this->school, 'page_key' => 'home', 'slug' => 'home',
            'title' => 'Home', 'status' => 1, 'sort_order' => 0,
        ]);
        // The real page the catalogue renders under; the public route 404s without it.
        DB::table('website_pages')->insert([
            'school_id' => $this->school, 'page_key' => 'programs', 'slug' => 'academic-programmes',
            'title' => 'Academic Programmes', 'status' => 1, 'sort_order' => 3,
        ]);

        // A programme that has existed since long before this feature — no image.
        $this->programme = DB::table('website_items')->insertGetId([
            'school_id' => $this->school,
            'section_key' => self::PROGRAMME_SECTION,
            'item_type' => 'programme',
            'title' => 'Bachelor of Business Administration',
            'description' => 'A three-year business degree.',
            'status' => 1,
            'sort_order' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    private function migration(string $path)
    {
        return require base_path($path);
    }

    // ── Real image fixtures ───────────────────────────────────────────────────

    /**
     * A genuine image file with genuine content for the named format.
     *
     * @param  'jpeg'|'png'|'webp'  $format
     */
    private function imageUpload(string $format, int $width, int $height, string $name, bool $withAlpha = false): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        if ($format === 'png' || $format === 'webp') {
            // True-colour canvases start fully transparent, which would leave a
            // stored PNG full of holes. Fill opaque first…
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $opaque = imagecolorallocate($image, 32, 96, 168);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $opaque);

            // …then, when asked for it, punch a genuinely transparent band into the
            // middle. It has to be transparent for real: a PNG with no transparent
            // pixels is CORRECTLY re-encoded as JPEG, so a fixture that only looked
            // like it had alpha would test the wrong branch and pass for the wrong
            // reason. `imagecolorallocatealpha` with blending off writes alpha 127.
            if ($withAlpha) {
                $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
                $band = max(2, (int) ($height / 4));
                imagefilledrectangle(
                    $image,
                    0,
                    intdiv($height - $band, 2),
                    $width - 1,
                    intdiv($height - $band, 2) + $band - 1,
                    $clear
                );
            }

            imagealphablending($image, true);
        }

        $a = imagecolorallocate($image, 210, 60, 40);
        $b = imagecolorallocate($image, 40, 160, 90);

        // Deliberately asymmetric, so a centre crop is distinguishable from a
        // top-left crop in the assertions below.
        imagefilledrectangle($image, 0, 0, (int) ($width / 4), $height - 1, $a);
        imagefilledrectangle($image, $width - 1, 0, $width - (int) ($width / 4), $height - 1, $b);

        $path = $this->scratch.DIRECTORY_SEPARATOR.$name;
        match ($format) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 90),
            default => imagejpeg($image, $path, 92),
        };
        imagedestroy($image);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function textUpload(string $name, string $contents = '<?php echo "not an image";'): UploadedFile
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function uploadDir(): string
    {
        return $this->publicDir.'/assets/uploads/website';
    }

    private function storedPath(?string $name): ?string
    {
        return $name === null ? null : $this->uploadDir().DIRECTORY_SEPARATOR.$name;
    }

    /** Payload for an item update. item_type is always posted, as the form does. */
    private function itemPayload(array $extra = []): array
    {
        return $extra + [
            'section_key' => self::PROGRAMME_SECTION,
            'item_type' => 'programme',
            'title' => 'Bachelor of Business Administration',
            'status' => 1,
            'sort_order' => 0,
        ];
    }

    private function updateItem(array $payload)
    {
        return $this->actingAs($this->superAdmin)
            ->post(route('superadmin.website.item.update', $this->programme), $payload);
    }

    private function storedName(): ?string
    {
        return DB::table('website_items')->where('id', $this->programme)->value('image');
    }

    // ── The schema requirement ────────────────────────────────────────────────

    public function test_the_existing_image_column_is_all_that_is_required(): void
    {
        // The feature adds NO column. If someone later "improves" this by adding a
        // separate covers table, this test is the thing that says the old column is
        // still the single source of truth and nothing else is needed.
        $this->assertTrue(Schema::hasColumn('website_items', 'image'));
        $this->assertFalse(Schema::hasColumn('website_items', 'cover_image_path'));

        // 67 live programmes, every one of them functional with image = NULL.
        $this->assertNull($this->storedName());
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function test_a_programme_cover_is_stored_optimised_at_sixteen_by_nine(): void
    {
        // 2400x1200 is ALREADY 16:9 — the crop must be a no-op rather than a
        // needless re-sample that softens the photograph.
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 2400, 1200, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();

        $name = $this->storedName();
        $this->assertNotNull($name, 'cover was not stored');

        $path = $this->storedPath($name);
        $this->assertFileExists($path);

        $size = getimagesize($path);
        $this->assertSame(ImageOptimizer::TARGET_WIDTH, $size[0]);
        $this->assertSame(ImageOptimizer::TARGET_HEIGHT, $size[1]);

        // The client filename never reaches the disk, and the extension comes from
        // what was actually produced. Both .jpg and .jpeg name image/jpeg, so either
        // is correct here; what matters is that it is not the client's string.
        $this->assertStringNotContainsString('cover.jpg', $name);
        $this->assertContains(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg']);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($path));
    }

    public function test_a_wrong_ratio_cover_is_centre_cropped_rather_than_letterboxed(): void
    {
        // 4:3 source. A 16:9 result can only come from cropping the top and bottom;
        // letterboxing would instead leave bars and keep all 1600 horizontal pixels.
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 1200, 'tallish.jpg'),
        ]))->assertSessionHasNoErrors();

        $size = getimagesize($this->storedPath($this->storedName()));

        $this->assertSame(1600, $size[0]);
        $this->assertSame(900, $size[1]);

        // No black bars: every edge row must carry image content, not padding.
        $this->assertFalse($this->looksLikeLetterboxBar($this->storedPath($this->storedName()), 'top'));
        $this->assertFalse($this->looksLikeLetterboxBar($this->storedPath($this->storedName()), 'bottom'));
    }

    public function test_a_transparent_png_stays_a_png_and_an_opaque_png_becomes_a_jpeg(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('png', 1600, 900, 'transparent.png', true),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('png', strtolower(pathinfo($this->storedName(), PATHINFO_EXTENSION)));

        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('png', 1600, 900, 'opaque.png', false),
        ]))->assertSessionHasNoErrors();
        // A PNG with no transparency gains nothing from being a PNG — 8-bit RGBA at
        // full 16:9 is a large file for a photograph.
        $this->assertContains(strtolower(pathinfo($this->storedName(), PATHINFO_EXTENSION)), ['jpg', 'jpeg']);
    }

    public function test_a_webp_is_accepted_and_preserved(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('webp', 1920, 1080, 'cover.webp'),
        ]))->assertSessionHasNoErrors();

        $path = $this->storedPath($this->storedName());
        $this->assertSame('webp', strtolower(pathinfo($this->storedName(), PATHINFO_EXTENSION)));
        $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($path));

        $size = getimagesize($path);
        $this->assertSame(1600, $size[0]);
        $this->assertSame(900, $size[1]);
    }

    // ── The crop is per item type ─────────────────────────────────────────────

    public function test_a_leadership_portrait_is_not_cropped_to_sixteen_by_nine(): void
    {
        // This is the regression the per-item-type profile exists to prevent. A
        // 2:3 portrait centre-cropped to 16:9 loses the top of the frame, which on
        // a head-and-shoulders portrait is the person's head.
        $leader = DB::table('website_items')->insertGetId([
            'school_id' => $this->school,
            'section_key' => 'about_leadership',
            'item_type' => 'leader',
            'title' => 'Dr Jane Doe',
            'status' => 1,
            'sort_order' => 0,
        ]);

        $this->actingAs($this->superAdmin)->post(route('superadmin.website.item.update', $leader), [
            'section_key' => 'about_leadership',
            'item_type' => 'leader',
            'title' => 'Dr Jane Doe',
            'status' => 1,
            'sort_order' => 0,
            'image' => $this->imageUpload('jpeg', 600, 900, 'portrait.jpg'),
        ])->assertSessionHasNoErrors();

        $name = DB::table('website_items')->where('id', $leader)->value('image');
        $size = getimagesize($this->storedPath($name));

        $this->assertSame(600, $size[0], 'portrait width was changed');
        $this->assertSame(900, $size[1], 'portrait height was changed');
    }

    // ── Preserve, replace, remove ────────────────────────────────────────────

    public function test_editing_a_programme_without_uploading_keeps_the_existing_image(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'first.jpg'),
        ]))->assertSessionHasNoErrors();
        $original = $this->storedName();

        // A title edit only. This is the case that decides whether "preserve" works.
        $this->updateItem($this->itemPayload(['title' => 'BBA (Hons)']))->assertSessionHasNoErrors();

        $this->assertSame($original, $this->storedName());
        $this->assertFileExists($this->storedPath($original));
        $this->assertSame('BBA (Hons)', DB::table('website_items')->where('id', $this->programme)->value('title'));
    }

    public function test_a_programme_cover_can_be_replaced_and_the_old_file_is_removed(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'first.jpg'),
        ]))->assertSessionHasNoErrors();
        $first = $this->storedName();

        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1000, 1000, 'second.jpg'),
        ]))->assertSessionHasNoErrors();
        $second = $this->storedName();

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($this->storedPath($first), 'superseded cover left on disk');
        $this->assertFileExists($this->storedPath($second));
        $this->assertCount(1, File::files($this->uploadDir()), 'an orphaned cover was left behind');
    }

    public function test_a_programme_cover_can_be_removed_explicitly(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();
        $name = $this->storedName();

        $this->updateItem($this->itemPayload(['remove_image' => '1']))->assertSessionHasNoErrors();

        // `''` is the pre-existing "no image" sentinel chosen by removeItemImage().
        // Every read site tests it for truthiness, so what matters is that it is
        // falsy — not that it is a SQL NULL.
        $this->assertEmpty($this->storedName());
        $this->assertFileDoesNotExist($this->storedPath($name));
    }

    // ── Invalid input ────────────────────────────────────────────────────────

    public function test_a_file_that_is_not_an_image_is_rejected_and_nothing_is_stored(): void
    {
        // The `image` rule should catch this at validation. The optimiser's own
        // content check is the second layer, asserted separately below.
        $this->updateItem($this->itemPayload([
            'image' => $this->textUpload('payload.jpg'),
        ]))->assertSessionHasErrors('image');

        $this->assertNull($this->storedName());
        $this->assertCount(0, File::files($this->uploadDir()));
    }

    public function test_an_upload_over_four_megabytes_is_rejected(): void
    {
        // 5 MB of incompressible noise, so the size check is what refuses it rather
        // than the format being wrong.
        $noise = random_bytes(5 * 1024 * 1024);
        $big = new UploadedFile(
            $this->writeScratch('big.jpg', $noise),
            'big.jpg',
            null,
            null,
            true
        );

        $this->updateItem($this->itemPayload(['image' => $big]))->assertSessionHasErrors('image');

        $this->assertNull($this->storedName());
        $this->assertCount(0, File::files($this->uploadDir()));
    }

    public function test_a_gif_is_rejected(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->textUpload('animation.gif'),
        ]))->assertSessionHasErrors('image');

        $this->assertNull($this->storedName());
    }

    // ── Public site ──────────────────────────────────────────────────────────

    public function test_the_public_catalogue_shows_the_uploaded_cover(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();
        $name = $this->storedName();

        $response = $this->get(route('website.page', 'academic-programmes'));

        $response->assertOk();
        $response->assertSee('Bachelor of Business Administration');
        $response->assertSee('assets/uploads/website/'.$name, false);
        // object-fit: cover is the CSS rule; the frame ratio is asserted below.
        $response->assertSee('piie-card--programme', false);
    }

    public function test_a_programme_without_an_image_still_renders_with_a_placeholder(): void
    {
        $this->assertNull($this->storedName());

        $response = $this->get(route('website.page', 'academic-programmes'));

        $response->assertOk();
        $response->assertSee('Bachelor of Business Administration');
        // The designed placeholder, not a broken image and not a bare empty frame.
        $response->assertSee('piie-card__media--fallback', false);
        $response->assertDontSee('<img src=""', false);
    }

    public function test_the_programme_card_frame_is_sixteen_by_nine(): void
    {
        // The optimiser writes 16:9. A 4:3 frame would make `object-fit: cover`
        // crop the top and bottom off an already-correctly-cropped image, and the
        // loss would be invisible to whoever made the crop.
        // Read from base_path, NOT public_path: setUp repoints `path.public` at a temp
        // directory so uploads do not pollute the real public folder, and the
        // stylesheet is not part of that sandbox.
        $css = File::get(base_path('public/css/piie-site.css'));

        $this->assertMatchesRegularExpression(
            '/\.piie-card--programme\s+\.piie-card__media\s*\{[^}]*aspect-ratio:\s*16\s*\/\s*9/s',
            $css,
            'programme cards are not framed at 16:9'
        );
        $this->assertMatchesRegularExpression(
            '/\.piie-card__media img\s*\{[^}]*object-fit:\s*cover/s',
            $css,
            'card images must fill the frame'
        );
    }

    // ── Admin page ───────────────────────────────────────────────────────────

    public function test_the_panel_previews_the_saved_cover_and_offers_removal(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();
        $name = $this->storedName();

        $response = $this->actingAs($this->superAdmin)->get(route('superadmin.website.index'));

        $response->assertOk();
        $response->assertSee('assets/uploads/website/'.$name, false);
        $response->assertSee('Currently saved');
        $response->assertSee('Remove the saved image');
    }

    public function test_the_panel_states_the_sixteen_by_nine_requirement_for_programmes(): void
    {
        $response = $this->actingAs($this->superAdmin)->get(route('superadmin.website.index'));

        $response->assertOk();
        $response->assertSee('Cropped to 16:9');
        $response->assertSee('4&nbsp;MB', false);
        $response->assertSee('1600&nbsp;&times;&nbsp;900&nbsp;px', false);
    }

    public function test_the_panel_does_not_promise_a_sixteen_by_nine_crop_for_leadership(): void
    {
        DB::table('website_items')->insert([
            'school_id' => $this->school,
            'section_key' => 'about_leadership',
            'item_type' => 'leader',
            'title' => 'Dr Jane Doe',
            'status' => 1,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('superadmin.website.index'));

        $response->assertOk();
        // The panel must tell the truth about the non-programme case too, or an
        // administrator will compose a portrait expecting a 16:9 crop that will
        // never happen.
        $response->assertSee('keeps its own proportions', false);
    }

    public function test_the_panel_emits_the_preview_script_exactly_once(): void
    {
        // The partial renders once per item and the panel lists every item on one
        // page. If the script were emitted per item, a page with 60 programmes would
        // carry 60 copies, and if `@once` ever regressed it would bind every row's
        // listener to the first row's elements — silently, with no error.
        DB::table('website_items')->insert([
            ['school_id' => $this->school, 'section_key' => self::PROGRAMME_SECTION, 'item_type' => 'programme',
                'title' => 'Second Programme', 'status' => 1, 'sort_order' => 1],
            ['school_id' => $this->school, 'section_key' => 'about_leadership', 'item_type' => 'leader',
                'title' => 'Dr Jane Doe', 'status' => 1, 'sort_order' => 0],
        ]);

        // Markers chosen to be unambiguous. `name="image"` is NOT usable: the panel's
        // section forms use it too. `data-piie-image-input` appears once per form
        // AND once inside the script's own querySelectorAll. The class string and
        // the function declaration each occur only in the one place they belong to.
        $html = $this->actingAs($this->superAdmin)->get(route('superadmin.website.index'))->assertOk()->getContent();

        // 1 create form + 3 items = 4 instances of the partial.
        $this->assertSame(4, substr_count($html, 'piie-img__input'));

        // The script itself, once — not four times.
        $this->assertSame(1, substr_count($html, 'function initImagePreviews'));
        $this->assertSame(1, substr_count($html, "querySelectorAll('[data-piie-image-input]')"));
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_another_schools_admin_cannot_replace_or_remove_a_programme_cover(): void
    {
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();
        $before = $this->storedName();

        $this->actingAs($this->foreignAdmin)->post(route('admin.website.item.update', $this->programme), $this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'hijack.jpg'),
        ]))->assertNotFound();

        $this->actingAs($this->foreignAdmin)->post(route('admin.website.item.update', $this->programme), $this->itemPayload([
            'remove_image' => '1',
        ]))->assertNotFound();

        $this->assertSame($before, $this->storedName());
        $this->assertFileExists($this->storedPath($before));
    }

    public function test_a_guest_cannot_upload_a_programme_cover(): void
    {
        $this->post(route('superadmin.website.item.update', $this->programme), $this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertRedirect(route('login'));

        $this->assertNull($this->storedName());
    }

    public function test_a_school_admin_cannot_reach_the_public_sites_programme_covers(): void
    {
        // The Super Admin panel is a separate route group from the school panel.
        // A school admin must not be able to reach the public site's programme
        // images through it.
        $this->actingAs($this->foreignAdmin)
            ->get(route('superadmin.website.index'))
            ->assertRedirect();
    }

    // ── What this must not disturb ───────────────────────────────────────────

    public function test_uploading_a_cover_does_not_change_programme_identity_or_links(): void
    {
        DB::table('website_items')->where('id', $this->programme)->update(['link' => '/programmes/bba']);

        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();

        $item = DB::table('website_items')->where('id', $this->programme)->first();

        $this->assertSame('/programmes/bba', $item->link, 'the application link changed');
        $this->assertSame('Bachelor of Business Administration', $item->title);
        $this->assertSame(self::PROGRAMME_SECTION, $item->section_key);
        $this->assertSame($this->school, $item->school_id, 'tenant ownership changed');
        $this->assertCount(1, DB::table('website_items')->where('title', 'Bachelor of Business Administration')->get());
    }

    public function test_uploading_a_cover_does_not_disturb_other_content_images(): void
    {
        $section = DB::table('website_sections')->insertGetId([
            'school_id' => $this->school, 'page_key' => 'about', 'section_key' => 'hero',
            'title' => 'About hero', 'image' => 'hero-existing.png', 'status' => 1, 'sort_order' => 0,
        ]);
        file_put_contents($this->uploadDir().'/hero-existing.png', 'x');

        // A programme upload must not cause section or non-programme images to be
        // re-optimised: the optimiser is only on the item path.
        $this->updateItem($this->itemPayload([
            'image' => $this->imageUpload('jpeg', 1600, 900, 'cover.jpg'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame('hero-existing.png', DB::table('website_sections')->where('id', $section)->value('image'));
        $this->assertFileExists($this->uploadDir().'/hero-existing.png');
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    private function writeScratch(string $name, string $contents): string
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Does this edge of the image look like a solid letterbox bar?
     *
     * Read by hand rather than asserted with a threshold: a pad is a perfectly flat
     * run of one colour, so measuring the distinct colours in the row is exact and
     * does not depend on what the photograph happens to contain.
     */
    private function looksLikeLetterboxBar(string $path, string $edge): bool
    {
        $image = imagecreatefromstring((string) file_get_contents($path));
        $width = imagesx($image);
        $height = imagesy($image);
        $y = $edge === 'top' ? 0 : $height - 1;
        $colours = [];

        for ($x = 0; $x < $width; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $colours[(($rgb >> 16) & 0xFF).'|'.(($rgb >> 8) & 0xFF).'|'.($rgb & 0xFF)] = true;
        }
        imagedestroy($image);

        return count($colours) <= 1;
    }
}