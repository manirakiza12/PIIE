<?php

namespace Tests\Feature;

use App\Models\Programme;
use App\Models\User;
use App\Models\WebsiteItem;
use App\Support\ProgrammeCatalogue\ProgrammePrice;
use App\Support\ProgrammeCatalogue\ProgrammePublisher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Stage 1: the academic Programme as the single source of truth for the public
 * catalogue.
 *
 * ── WHAT IS PINNED DOWN, AND WHY EACH ONE MATTERS ──────────────────────────
 *
 * The order is deliberate. The security assertions come FIRST, because a
 * catalogue feature that leaks another tenant's programme, or that lets a
 * student publish one, is worse than no catalogue at all.
 *
 *   1. TENANT ISOLATION — every screen, every action, and the projection itself.
 *      A programme id belonging to another school must 404, and a corrupted
 *      `website_item_id` must not let one tenant publish into another's CMS.
 *   2. ADMIN PERMISSIONS — publishing and image changes are writes to public
 *      content, so they are behind the same admin+rbac middleware as the rest of
 *      this controller and a student cannot reach them.
 *   3. PRICE METADATA — the specific failure this stage exists to prevent: an
 *      amount with no basis must NOT reach the public catalogue as a bare figure,
 *      and a stored 0 must not be published as "free".
 *   4. DUPLICATE PREVENTION — one qualification must never be listed twice,
 *      which is the failure a bridge between two systems invites by default.
 *   5. PUBLICATION STATE — publish, unpublish, and the separation from academic
 *      activation, including that a rename updates the live card without an
 *      unpublish/republish cycle.
 *
 * IMAGE TESTS DELIBERATELY USE REAL BYTES. `UploadedFile::fake()->image()`
 * always writes JPEG content whatever the filename, which would make the PNG and
 * WebP cases untestable and would test a lie about the format allow-list.
 */
class ProgrammeCatalogueBridgeTest extends TestCase
{
    use AdmissionsTestHelper;

    private string $publicDir;
    private string $scratch;

    /** Columns added by 2026_10_04_000003, mirrored here rather than assumed. */
    private const CATALOGUE_COLUMNS = [
        'cover_image_path', 'cover_image_name', 'cover_image_mime',
        'cover_image_size', 'cover_image_updated_at',
        'tuition_currency', 'tuition_fee_basis',
        'is_published', 'website_sort_order', 'published_at', 'website_item_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        $this->addCatalogueColumns();
        $this->addCmsAndTenantSchema();

        $this->publicDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-cat-public-'.uniqid();
        $this->scratch   = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-cat-scratch-'.uniqid();
        File::ensureDirectoryExists($this->publicDir);
        File::ensureDirectoryExists($this->scratch);
        File::ensureDirectoryExists(
            $this->publicDir.'/assets/uploads/programme-covers'
        );

        // The cover is PUBLIC and web-served, so it is stored under public_path()
        // rather than on a private disk. Redirect the public path at a scratch
        // directory so the suite writes nothing into the real public/ tree — the
        // same technique ProgrammeCoverImageTest uses for the CMS uploads.
        $this->app->instance('path.public', $this->publicDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDir);
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    /**
     * Mirror the migration's columns onto the test schema.
     *
     * Stated explicitly rather than running the migration, because the suite is
     * SQLite and several project migrations are MySQL-only. The `up()` closure
     * is exercised directly in test_the_migration_is_additive_and_safe_for_existing_rows.
     */
    private function addCatalogueColumns(): void
    {
        if (Schema::hasColumn('programmes', 'tuition_fee_basis')) {
            return; // The shared fixture already declares them.
        }

        Schema::table('programmes', function ($table): void {
            $table->string('cover_image_path', 255)->nullable();
            $table->string('cover_image_name', 191)->nullable();
            $table->string('cover_image_mime', 100)->nullable();
            $table->unsignedBigInteger('cover_image_size')->nullable();
            $table->dateTime('cover_image_updated_at')->nullable();
            $table->string('tuition_currency', 10)->nullable();
            $table->string('tuition_fee_basis', 32)->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->integer('website_sort_order')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unsignedBigInteger('website_item_id')->nullable();
        });
    }

    /**
     * The CMS table the bridge projects onto, plus the tenant fields the price
     * and the listing read.
     *
     * `website_items` is created here rather than borrowed from the public-site
     * trait because that trait resets the whole database connection, which would
     * discard the admissions schema this suite's users and programmes live in.
     */
    private function addCmsAndTenantSchema(): void
    {
        if (! Schema::hasTable('website_items')) {
            Schema::create('website_items', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable();
                $table->string('section_key')->nullable();
                $table->string('item_type')->nullable();
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->text('description')->nullable();
                $table->text('content')->nullable();
                $table->string('image')->nullable();
                $table->string('link')->nullable();
                $table->string('button_text')->nullable();
                $table->boolean('status')->default(1);
                $table->integer('sort_order')->default(0);
                $table->text('meta_json')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('schools', function ($table): void {
            if (! Schema::hasColumn('schools', 'school_currency')) {
                $table->string('school_currency', 20)->nullable();
            }
            // ProgrammeController@index reads it to decide whether this tenant is
            // an HEI at all, and the listing hides itself for a k12 school.
            if (! Schema::hasColumn('schools', 'school_type')) {
                $table->string('school_type', 20)->nullable();
            }
        });

        DB::table('schools')->update(['school_type' => 'higher_ed']);
    }

    private function setTenantCurrency(int $schoolId, ?string $currency): void
    {
        DB::table('schools')->where('id', $schoolId)->update(['school_currency' => $currency]);
    }

    private function programme(int $schoolId, array $overrides = []): Programme
    {
        $id = $this->makeProgramme($schoolId, array_merge([
            'name'  => 'Bachelor of Science in Computer Science',
            'code'  => 'BSC-CS',
            // Current client-preferred values, so a payload built from this
            // fixture passes the same validation rules a real edit form does.
            // The shared helper leaves these unset, which would make every
            // round-trip test fail on a missing required field rather than on
            // the behaviour under test.
            'level' => 'Bachelors',
            'mode'  => 'ODEL',
        ], $overrides));

        return Programme::query()->findOrFail($id);
    }

    private function catalogueItems(int $schoolId): \Illuminate\Support\Collection
    {
        return WebsiteItem::query()
            ->whereIn('section_key', ProgrammePublisher::CATALOGUE_SECTIONS)
            ->where(fn ($q) => $q->where('school_id', $schoolId)->orWhereNull('school_id'))
            ->get();
    }

    /**
     * A student of this tenant.
     *
     * Role 7 is the platform's Student role. The helper builds it inline rather
     * than growing the shared trait for one caller, because only this suite needs
     * a plain student with no enrolment fixtures attached.
     */
    private function makeStudent(int $schoolId): User
    {
        return User::factory()->create([
            'role_id'         => 7,
            'school_id'       => $schoolId,
            'account_status'  => 'active',
        ]);
    }

    /** A real image on disk, so the upload path handles actual bytes. */
    private function realImage(string $name = 'cover.png', int $width = 400, int $height = 300): UploadedFile
    {
        $path = $this->scratch.'/'.$name;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 60, 120));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. TENANT ISOLATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_administrator_cannot_publish_another_tenants_programme(): void
    {
        $mine    = $this->makeSchool();
        $theirs  = $this->makeSchool();
        $myAdmin = $this->makeAdminUser($mine);

        $foreign = $this->programme($theirs, ['name' => 'Their Programme', 'code' => 'THEIRS']);

        $this->actingAs($myAdmin)
            ->post(route('admin.programmes.publish', $foreign->id))
            ->assertNotFound();

        $this->assertFalse($foreign->refresh()->is_published);
    }

    public function test_an_administrator_cannot_edit_or_see_another_tenants_programme_modal(): void
    {
        $mine    = $this->makeSchool();
        $theirs  = $this->makeSchool();
        $myAdmin = $this->makeAdminUser($mine);
        $foreign = $this->programme($theirs, ['name' => 'Their Programme', 'code' => 'THEIRS']);

        $this->actingAs($myAdmin)
            ->get(route('admin.programmes.open_modal', ['id' => $foreign->id]))
            ->assertNotFound();

        $this->actingAs($myAdmin)
            ->get(route('admin.programmes.preview', $foreign->id))
            ->assertNotFound();
    }

    public function test_an_administrator_cannot_change_another_tenants_cover(): void
    {
        $mine    = $this->makeSchool();
        $theirs  = $this->makeSchool();
        $myAdmin = $this->makeAdminUser($mine);
        $foreign = $this->programme($theirs, ['name' => 'Their Programme', 'code' => 'THEIRS']);

        $this->actingAs($myAdmin)
            ->post(route('admin.programmes.cover.store', $foreign->id), [
                'cover_image' => $this->realImage(),
            ])
            ->assertNotFound();

        $this->assertNull($foreign->refresh()->cover_image_path);
    }

    /**
     * A `website_item_id` pointing at another school's CMS row must be ignored.
     *
     * This is the specific attack the publisher's tenant check exists for: the
     * column is not mass-assignable, but a corrupted value, a restored backup or
     * a hand-edited row could carry one, and following it would publish one
     * institution's qualification into another institution's catalogue.
     */
    public function test_a_projection_id_pointing_at_another_tenants_row_is_refused(): void
    {
        $mine    = $this->makeSchool();
        $theirs  = $this->makeSchool();
        $myAdmin = $this->makeAdminUser($mine);

        $theirItem = WebsiteItem::create([
            'school_id'   => $theirs,
            'section_key' => ProgrammePublisher::DEFAULT_SECTION,
            'item_type'   => 'programme',
            'title'       => 'Their Programme',
            'status'      => 1,
        ]);

        $mineProgramme = $this->programme($mine, ['name' => 'My Programme', 'code' => 'MINE']);

        // Force the bad pointer, exactly as a bad restore would.
        DB::table('programmes')->where('id', $mineProgramme->id)
            ->update(['website_item_id' => $theirItem->id]);

        $publisher = app(ProgrammePublisher::class);

        $this->assertNull(
            $publisher->projectionFor($mineProgramme->refresh()),
            'A projection pointer outside this tenant must not be followed.'
        );

        $this->actingAs($myAdmin)
            ->post(route('admin.programmes.publish', $mineProgramme->id))
            ->assertRedirect();

        // A NEW row was created for this tenant; the other tenant's row is intact.
        $theirItem->refresh();
        $this->assertSame('Their Programme', $theirItem->title);
        $this->assertSame(1, $theirItem->status);

        $own = $this->catalogueItems($mine)->firstWhere('title', 'My Programme');
        $this->assertNotNull($own, 'The programme must be published into its OWN tenant catalogue.');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. ADMIN PERMISSIONS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_cannot_reach_any_catalogue_action(): void
    {
        $schoolId  = $this->makeSchool();
        $student   = $this->makeStudent($schoolId);
        $programme = $this->programme($schoolId);

        // AdminMiddleware REFUSES by redirecting to the caller's own portal rather
        // than by 403, which is the platform's long-standing behaviour for every
        // admin screen. The assertion that matters is therefore that no state
        // changed — a redirect that had already published the programme would be a
        // far worse outcome than the wrong status code.
        $this->actingAs($student)
            ->get(route('admin.programmes.preview', $programme->id))
            ->assertRedirect();

        $this->actingAs($student)
            ->post(route('admin.programmes.publish', $programme->id))
            ->assertRedirect();

        $this->assertFalse(
            $programme->refresh()->is_published,
            'A student must not be able to publish a programme to the public site.'
        );

        $this->assertCount(0, $this->catalogueItems($schoolId));
    }

    public function test_a_guest_cannot_reach_the_preview(): void
    {
        $schoolId = $this->makeSchool();
        $programme = $this->programme($schoolId);

        $this->get(route('admin.programmes.preview', $programme->id))
            ->assertRedirect(route('login'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. PRICE METADATA — a missing price is never a zero
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_amount_with_no_basis_is_withheld_rather_than_shown_bare(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 4500000,
            'tuition_fee_basis' => null,
        ]);

        // An amount alone is not a price. Showing it would state, specifically and
        // wrongly, whether a candidate pays this per year or in full.
        $this->assertFalse(ProgrammePrice::isPriced($programme));
        $this->assertNull(ProgrammePrice::forDisplay($programme));

        // And the admin is told exactly why it is not shown.
        $this->assertStringContainsString('basis not stated', ProgrammePrice::adminSummary($programme));
    }

    public function test_a_stored_zero_is_withheld_and_never_reads_as_free(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 0,
            'tuition_fee_basis' => 'per_programme',
        ]);

        // "0" in a catalogue card is a claim that the programme is free. That is a
        // business fact, not a formatting choice, so it is withheld and the
        // administrator is pointed at the honest alternative.
        $this->assertFalse(ProgrammePrice::isPriced($programme));
        $this->assertStringContainsString('withheld', ProgrammePrice::adminSummary($programme));
    }

    public function test_a_complete_price_renders_with_currency_and_basis(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 4500000,
            'tuition_fee_basis' => 'per_year',
        ]);

        $price = ProgrammePrice::forDisplay($programme);

        $this->assertNotNull($price);
        $this->assertSame('4,500,000', $price['amount']);
        $this->assertSame('UGX', $price['currency'], 'The tenant currency is inherited, not assumed.');
        $this->assertSame('per academic year', $price['basis']);
    }

    public function test_a_programme_override_beats_the_tenant_currency(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 1200,
            'tuition_fee_basis' => 'per_programme',
            'tuition_currency'  => 'USD',
        ]);

        $this->assertSame('USD', ProgrammePrice::currency($programme));
    }

    public function test_the_contact_basis_publishes_no_amount_at_all(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 9999999,
            'tuition_fee_basis' => 'contact',
        ]);

        // "Enquire" is a real answer. Honouring the amount as well would publish a
        // number the administrator asked not to publish.
        $this->assertFalse(ProgrammePrice::isPriced($programme));
        $this->assertNull(ProgrammePrice::forDisplay($programme));
        $this->assertSame('Contact us for tuition fees', ProgrammePrice::contactLabel());
    }

    public function test_saving_price_metadata_is_validated_and_reconciled(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');
        $admin = $this->makeAdminUser($schoolId);

        $programme = $this->programme($schoolId, [
            'tuition_fee'       => 4500000,
            'tuition_fee_basis' => 'per_year',
            'tuition_currency'  => 'USD',
        ]);

        // An invented basis is refused: the vocabulary is what the public card can
        // render, and a free-text basis would put unrenderable text on the site.
        $this->actingAs($admin)
            ->post(route('admin.programmes.update', $programme->id), [
                'code' => 'BSC-CS', 'name' => $programme->name,
                'level' => $programme->level, 'mode' => $programme->mode,
                'tuition_fee' => 4500000, 'tuition_fee_basis' => 'per_fortnight',
            ])
            ->assertSessionHasErrors('tuition_fee_basis');

        // Clearing the amount clears its unit with it. A currency or basis left
        // describing nothing would be rendered against a future amount by mistake.
        $this->actingAs($admin)
            ->post(route('admin.programmes.update', $programme->id), [
                'code' => 'BSC-CS', 'name' => $programme->name,
                'level' => $programme->level, 'mode' => $programme->mode,
                'tuition_fee' => '',
            ]);

        $fresh = $programme->refresh();
        $this->assertNull($fresh->tuition_fee_basis);
        $this->assertNull($fresh->tuition_currency);
    }

    public function test_the_admin_list_states_a_withheld_price_rather_than_a_blank(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');
        $admin = $this->makeAdminUser($schoolId);

        $this->programme($schoolId, [
            'name' => 'Unpriced Programme', 'code' => 'UNP1',
            'tuition_fee' => 1200000, 'tuition_fee_basis' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.programmes.index'))
            ->assertOk()
            // The gap is visible where it can be fixed, not hidden behind a blank cell.
            ->assertSee('basis not stated')
            ->assertSee('not shown publicly');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. IMAGE VALIDATION AND STORAGE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_valid_cover_is_stored_under_a_generated_name(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)
            ->post(route('admin.programmes.cover.store', $programme->id), [
                'cover_image' => $this->realImage('my programme cover.png'),
            ])
            ->assertSessionHasNoErrors();

        $fresh = $programme->refresh();

        $this->assertNotNull($fresh->cover_image_path);
        // The client's filename never becomes the path, so an uploaded
        // "my programme cover.png" cannot be requested at a guessable location.
        $this->assertStringNotContainsString('my programme', $fresh->cover_image_path);
        // The extension is what was PRODUCED, not what the client claimed: an
        // opaque PNG is re-encoded as JPEG (only a PNG carrying transparency
        // keeps its format, or a transparent logo arrives as a black rectangle).
        // So `.jpeg` here is the optimiser working, not a mismatch.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.(png|jpe?g|webp)$/', $fresh->cover_image_path);
        // The original name is kept as a display label only.
        $this->assertSame('my programme cover.png', $fresh->cover_image_name);
    }

    public function test_a_non_image_is_refused(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $document = $this->scratch.'/notes.pdf';
        file_put_contents($document, "%PDF-1.4 not an image");

        $this->actingAs($admin)
            ->post(route('admin.programmes.cover.store', $programme->id), [
                'cover_image' => new UploadedFile($document, 'notes.pdf', 'application/pdf', null, true),
            ])
            ->assertSessionHasErrors('cover_image');

        // A refused upload changes nothing.
        $this->assertNull($programme->refresh()->cover_image_path);
    }

    public function test_a_renamed_executable_is_refused_despite_a_valid_extension(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        // An allow-list on the extension is not enough; ImageOptimizer also
        // validates the CONTENT, so PHP bytes in a .png are refused.
        $payload = $this->scratch.'/payload.png';
        file_put_contents($payload, "<?php echo 'x'; ?>\n".str_repeat('A', 64));

        $this->actingAs($admin)
            ->post(route('admin.programmes.cover.store', $programme->id), [
                'cover_image' => new UploadedFile($payload, 'payload.png', 'image/png', null, true),
            ])
            ->assertSessionHasErrors('cover_image');

        $this->assertNull($programme->refresh()->cover_image_path);
    }

    public function test_replacing_a_cover_disposes_of_the_superseded_bytes(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $directory = $this->publicDir.'/assets/uploads/programme-covers';

        $this->actingAs($admin)->post(route('admin.programmes.cover.store', $programme->id), [
            'cover_image' => $this->realImage('first.png'),
        ]);
        $first = $programme->refresh()->cover_image_path;
        $this->assertFileExists($directory.'/'.$first);

        $this->actingAs($admin)->post(route('admin.programmes.cover.store', $programme->id), [
            'cover_image' => $this->realImage('second.png'),
        ]);
        $second = $programme->refresh()->cover_image_path;

        $this->assertNotSame($first, $second);
        // A cover is institution-owned material the institution may withdraw.
        // Keeping every previous version keeps readable files it has replaced.
        $this->assertFileDoesNotExist($directory.'/'.$first);
        $this->assertFileExists($directory.'/'.$second);
    }

    public function test_a_cover_can_be_removed_and_the_card_returns_to_the_fallback(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $directory = $this->publicDir.'/assets/uploads/programme-covers';

        $this->actingAs($admin)->post(route('admin.programmes.cover.store', $programme->id), [
            'cover_image' => $this->realImage('cover.png'),
        ]);
        $path = $programme->refresh()->cover_image_path;

        $this->actingAs($admin)
            ->post(route('admin.programmes.cover.remove', $programme->id))
            ->assertSessionHasNoErrors();

        $this->assertNull($programme->refresh()->cover_image_path);
        $this->assertFileDoesNotExist($directory.'/'.$path);
    }

    public function test_a_programme_with_no_cover_reports_none_and_is_not_a_fault(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->programme($schoolId);

        $this->actingAs($admin)
            ->get(route('admin.programmes.open_modal', ['id' => Programme::query()->first()->id]))
            ->assertOk()
            ->assertSee('piie-no-cover', false)
            ->assertSee('No cover', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. PUBLISHING, UNPUBLISHING, DUPLICATE PREVENTION
    // ══════════════════════════════════════════════════════════════════════

    public function test_publishing_projects_the_programme_onto_the_catalogue(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');
        $admin = $this->makeAdminUser($schoolId);

        $programme = $this->programme($schoolId, [
            'name' => 'Bachelor of Science in Computer Science',
            'level' => 'Bachelors',
            'tuition_fee' => 4500000, 'tuition_fee_basis' => 'per_year',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.programmes.publish', $programme->id))
            ->assertSessionHas('success');

        $fresh = $programme->refresh();
        $this->assertTrue($fresh->is_published);
        $this->assertNotNull($fresh->published_at);
        $this->assertNotNull($fresh->website_item_id);

        $item = WebsiteItem::query()->findOrFail($fresh->website_item_id);
        $this->assertSame('Bachelor of Science in Computer Science', $item->title);
        $this->assertSame('Bachelors', $item->subtitle);
        $this->assertSame(1, $item->status);

        // The reverse link is what makes duplicate detection possible after the
        // forward pointer is lost.
        $meta = json_decode($item->meta_json, true);
        $this->assertSame($fresh->id, $meta[ProgrammePublisher::META_KEY]);
    }

    public function test_publishing_twice_updates_one_card_rather_than_creating_two(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $firstId = $programme->refresh()->website_item_id;

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $secondId = $programme->refresh()->website_item_id;

        $this->assertSame($firstId, $secondId, 'Republishing must be idempotent.');
        $this->assertCount(1, $this->catalogueItems($schoolId)->where('title', $programme->name));
    }

    public function test_an_existing_same_titled_catalogue_card_is_adopted_not_duplicated(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        // A hand-authored CMS card, already on the public site.
        $existing = WebsiteItem::create([
            'school_id'   => $schoolId,
            'section_key' => ProgrammePublisher::DEFAULT_SECTION,
            'item_type'   => 'programme',
            'title'       => 'Bachelor of Science in Computer Science',
            'description' => 'Hand-written marketing copy.',
            'status'      => 1,
        ]);

        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));

        // One qualification, one card. This is the failure a two-system bridge
        // invites by default: the same programme listed twice.
        $this->assertSame(1, $this->catalogueItems($schoolId)->where('title', $programme->name)->count());
        $this->assertSame($existing->id, $programme->refresh()->website_item_id);

        // The administrator's copy survives; the bridge adds the linkage, not a rewrite.
        $this->assertSame('Hand-written marketing copy.', $existing->refresh()->description);
    }

    public function test_a_marker_only_row_is_found_when_the_forward_pointer_is_lost(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $itemId = $programme->refresh()->website_item_id;

        // Simulate a lost pointer — a partial restore, or a hand edit.
        DB::table('programmes')->where('id', $programme->id)->update(['website_item_id' => null]);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));

        $this->assertSame($itemId, $programme->refresh()->website_item_id);
        $this->assertCount(1, $this->catalogueItems($schoolId));
    }

    public function test_unpublishing_deactivates_the_card_without_deleting_it(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $itemId = $programme->refresh()->website_item_id;

        $this->actingAs($admin)
            ->post(route('admin.programmes.unpublish', $programme->id))
            ->assertSessionHas('success');

        $fresh = $programme->refresh();
        $this->assertFalse($fresh->is_published);

        // Deactivated, not destroyed: an administrator who edited that card, or
        // wants it back, must not find it gone. A toggle that deletes content on
        // the way off is a toggle nobody trusts twice.
        $item = WebsiteItem::query()->findOrFail($itemId);
        $this->assertSame(0, $item->status);
    }

    public function test_renaming_a_published_programme_updates_the_live_card(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $itemId = $programme->refresh()->website_item_id;

        $this->actingAs($admin)->post(route('admin.programmes.update', $programme->id), [
            'code' => 'BSC-CS',
            'name' => 'Bachelor of Science in Computing',
            'level' => 'Bachelors',
            'mode' => $programme->mode,
        ]);

        // The academic record is the source of truth, so a rename reaches the site
        // without a second manual step — and without withdrawing the card.
        $this->assertSame('Bachelor of Science in Computing', WebsiteItem::query()->findOrFail($itemId)->title);
        $this->assertTrue($programme->refresh()->is_published);
    }

    public function test_an_inactive_programme_cannot_be_published_and_the_reason_is_given(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId, ['is_active' => 0]);

        $this->actingAs($admin)
            ->post(route('admin.programmes.publish', $programme->id))
            ->assertSessionHas('error');

        $this->assertFalse($programme->refresh()->is_published);
        $this->assertCount(0, $this->catalogueItems($schoolId));
    }

    public function test_deactivating_a_published_programme_hides_it_without_unpublishing_it(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $programme = $this->programme($schoolId);

        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));
        $itemId = $programme->refresh()->website_item_id;

        $this->actingAs($admin)->get(route('admin.programmes.toggle', $programme->id));

        // A withdrawn qualification that still advertises itself is worse than a
        // missing card, so the CMS row goes. `is_published` is left alone so
        // re-activating does NOT silently re-advertise something an administrator
        // had deliberately withdrawn.
        $this->assertSame(0, WebsiteItem::query()->findOrFail($itemId)->status);
        $this->assertTrue($programme->refresh()->is_published);
    }

    public function test_a_manual_cms_card_outside_the_catalogue_is_never_touched(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $news = WebsiteItem::create([
            'school_id'   => $schoolId,
            'section_key' => 'news',
            'item_type'   => 'article',
            'title'       => 'Bachelor of Science in Computer Science',  // same title on purpose
            'status'      => 1,
        ]);

        $programme = $this->programme($schoolId);
        $this->actingAs($admin)->post(route('admin.programmes.publish', $programme->id));

        // Adoption is restricted to catalogue sections, so unrelated content is
        // never captured by a title match.
        $this->assertNull($news->refresh()->meta_json);
        $this->assertNotSame($news->id, $programme->refresh()->website_item_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. THE MIGRATION ITSELF
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A BLANK tuition box must not be written as NULL.
     *
     * Found by rendering against a real MySQL schema rather than the SQLite fixture:
     * `programmes.tuition_fee` is `decimal(15,2) NOT NULL DEFAULT 0.00` and was never
     * made nullable. An empty form field arrives as null (ConvertEmptyStringsToNull),
     * so writing it through is an integrity-constraint error — a 500 on saving any
     * programme whose amount box was cleared.
     *
     * The SQLite fixture declares the column nullable, so this cannot be reproduced
     * there. It is asserted here as a schema fact plus a behavioural check, which is
     * the most a SQLite suite can honestly say about a MySQL constraint.
     */
    public function test_a_blank_tuition_amount_is_stored_as_zero_and_never_as_null(): void
    {
        $schoolId = $this->makeSchool();
        $this->setTenantCurrency($schoolId, 'UGX');
        $admin = $this->makeAdminUser($schoolId);

        $programme = $this->programme($schoolId, [
            'tuition_fee' => 4500000, 'tuition_fee_basis' => 'per_year', 'tuition_currency' => 'UGX',
        ]);

        // The form posts an empty amount box, which Laravel turns into null.
        $this->actingAs($admin)
            ->post(route('admin.programmes.update', $programme->id), [
                'code' => $programme->code, 'name' => $programme->name,
                'level' => $programme->level, 'mode' => $programme->mode,
                'tuition_fee' => '', 'tuition_currency' => '', 'tuition_fee_basis' => '',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $programme->refresh();

        // Stored as 0, which ProgrammePrice treats as unpriced so the public card
        // renders the contact line rather than a price of zero.
        //
        // assertEqualsWithDelta, not assertSame: `tuition_fee` is cast to `decimal:2`,
        // which yields a float, so the stored value is 0.0 rather than integer 0.
        // What matters is that it is zero and not NULL — the NOT NULL column is the
        // whole point of this test.
        $this->assertNotNull($fresh->tuition_fee);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->tuition_fee, 0.0001);
        $this->assertNull($fresh->tuition_currency);
        $this->assertNull($fresh->tuition_fee_basis);
        $this->assertFalse(\App\Support\ProgrammeCatalogue\ProgrammePrice::isPriced($fresh));
    }

    public function test_the_migration_is_additive_and_publishes_nothing_on_its_own(): void
    {
        $schoolId = $this->makeSchool();
        $this->makeProgramme($schoolId, ['name' => 'Existing Programme', 'code' => 'OLD1']);

        // Run the real migration's up() against the fixture schema. The columns
        // already exist here, so every branch is the hasColumn() no-op path — which
        // is exactly the path taken on an installation where the migration has
        // already run, and the one that must not destroy data.
        $migration = require database_path('migrations/2026_10_04_000003_add_catalogue_publication_to_programmes.php');
        $migration->up();

        foreach (self::CATALOGUE_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('programmes', $column),
                "programmes.{$column} must exist"
            );
        }

        $programme = Programme::query()->where('code', 'OLD1')->firstOrFail();

        // A schema change must never publish a catalogue as a side effect. Every
        // existing programme stays off the public site until an administrator says
        // otherwise.
        $this->assertFalse((bool) $programme->is_published);
        $this->assertNull($programme->website_item_id);
        $this->assertNull($programme->published_at);
        $this->assertNull($programme->tuition_fee_basis);
        $this->assertSame('Existing Programme', $programme->name);
    }

    public function test_published_scope_excludes_inactive_programmes(): void
    {
        $schoolId = $this->makeSchool();

        $live = $this->programme($schoolId, ['name' => 'Live Programme', 'code' => 'LIVE', 'is_published' => 1, 'is_active' => 1]);
        $held = $this->programme($schoolId, ['name' => 'Held Programme', 'code' => 'HELD', 'is_published' => 1, 'is_active' => 0]);
        $draft = $this->programme($schoolId, ['name' => 'Draft Programme', 'code' => 'DRAF', 'is_published' => 0, 'is_active' => 1]);

        $published = Programme::query()->published()->pluck('id');

        $this->assertTrue($published->contains($live->id));
        // A withdrawn qualification must not be advertised, and a draft must not
        // be either.
        $this->assertFalse($published->contains($held->id));
        $this->assertFalse($published->contains($draft->id));
    }
}