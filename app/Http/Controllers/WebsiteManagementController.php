<?php

namespace App\Http\Controllers;

use Database\Seeders\WebsiteContentSeeder;
use App\Models\WebsiteItem;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteSeoSetting;
use App\Models\WebsiteSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use App\Support\Images\ImageOptimizer;
use App\Support\PublicTenantResolver;
use App\Support\SafeUpload;
use Illuminate\Validation\Rule;

class WebsiteManagementController extends Controller
{
    private function ensureWebsiteTablesAndSeed()
    {
        if (!Schema::hasTable('website_pages')) {
            Schema::create('website_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('page_key');
                $table->unique(['school_id', 'page_key']);
                $table->string('title');
                $table->string('slug')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->integer('sort_order')->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('website_sections')) {
            Schema::create('website_sections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('page_key')->index();
                $table->string('section_key')->index();
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->longText('content')->nullable();
                $table->longText('extra_json')->nullable();
                $table->string('image')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('website_items')) {
            Schema::create('website_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('section_key')->index();
                $table->string('item_type')->default('general');
                $table->string('title')->nullable();
                $table->string('subtitle')->nullable();
                $table->text('description')->nullable();
                $table->longText('content')->nullable();
                $table->string('image')->nullable();
                $table->string('link')->nullable();
                $table->string('button_text')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->integer('sort_order')->default(0);
                $table->longText('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('website_settings')) {
            Schema::create('website_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('key');
                $table->unique(['school_id', 'key']);
                $table->longText('value')->nullable();
                $table->tinyInteger('is_json')->default(0);
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('website_seo_settings')) {
            Schema::create('website_seo_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('page_key');
                $table->unique(['school_id', 'page_key']);
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->text('meta_keywords')->nullable();
                $table->string('canonical_url')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('website_pages') && WebsitePage::count() === 0) {
            (new WebsiteContentSeeder())->run();

            // Security Phase 2H: the default content is the public site's, so it belongs to the
            // school PIIE serves its public site for (App\Support\PublicTenantResolver).
            $publicSchoolId = PublicTenantResolver::resolveSchoolId();
            if ($publicSchoolId) {
                foreach ([WebsitePage::class, WebsiteSection::class, WebsiteItem::class, WebsiteSetting::class, WebsiteSeoSetting::class] as $model) {
                    $model::whereNull('school_id')->update(['school_id' => $publicSchoolId]);
                }
            }
        }
    }

    /**
     * Security Phase 2H: the school whose website is being managed. School staff
     * manage only their own school's site; Super Admin manages the public site,
     * which PIIE attributes to one school (App\Support\PublicTenantResolver).
     * A submitted school_id is never read.
     */
    private function cmsSchoolId(): int
    {
        $schoolId = (int) auth()->user()->role_id === 1
            ? PublicTenantResolver::resolveSchoolId()
            : auth()->user()->school_id;

        abort_if(empty($schoolId), 404);

        return (int) $schoolId;
    }

    /** A CMS row of the managed school, or 404. */
    private function findOwned(string $model, $id)
    {
        return $model::where('school_id', $this->cmsSchoolId())->findOrFail($id);
    }

    /** Creates a CMS row owned by the managed school (school_id is never mass-assigned). */
    private function createOwned(string $model, array $data)
    {
        $row = new $model($data);
        $row->school_id = $this->cmsSchoolId();
        $row->save();

        return $row;
    }

    /** Updates the managed school's row for a key, or creates it. */
    private function upsertOwned(string $model, array $match, array $values)
    {
        $row = $model::where('school_id', $this->cmsSchoolId())->where($match)->first();

        return $row ? tap($row)->update($values) : $this->createOwned($model, $match + $values);
    }

    private function viewData()
    {
        $this->ensureWebsiteTablesAndSeed();

        $schoolId = $this->cmsSchoolId();

        return [
            'modules' => $this->moduleMap(),
            'pages' => WebsitePage::where('school_id', $schoolId)->orderBy('sort_order')->orderBy('id')->get(),
            'sections' => WebsiteSection::where('school_id', $schoolId)->orderBy('sort_order')->orderBy('id')->get(),
            'items' => WebsiteItem::where('school_id', $schoolId)->orderBy('sort_order')->orderBy('id')->get(),
            'seo' => WebsiteSeoSetting::where('school_id', $schoolId)->orderBy('page_key')->get()->keyBy('page_key'),
            'settings' => WebsiteSetting::where('school_id', $schoolId)->orderBy('key')->get()->keyBy('key'),
        ];
    }

    private function moduleMap()
    {
        return [
            'home_page' => 'Home Page',
            'hero_slider' => 'Hero Slider/Banner',
            'about_institution' => 'About Institution',
            'vision_mission_motto' => 'Vision, Mission, Motto',
            'core_values' => 'Core Values',
            'why_choose_us' => 'Why Choose Us',
            'academic_programmes' => 'Academic Programmes',
            'programme_categories' => 'Programme Categories',
            'fees_structure' => 'Fees Structure',
            'admissions' => 'Admissions',
            'online_learning_odel' => 'Online Learning / ODeL',
            'governance_structure' => 'Governance Structure',
            'leadership_team' => 'Leadership / Team',
            'director_message' => 'Director Message',
            'principal_message' => 'Principal Message',
            'academic_registrar_message' => 'Academic Registrar Message',
            'institute_secretary_message' => 'Institute Secretary Message',
            'strategic_plan' => 'Strategic Plan',
            'research_innovation' => 'Research & Innovation',
            'partnerships_affiliations' => 'Partnerships / Affiliations',
            'student_support_services' => 'Student Support Services',
            'international_students' => 'International Students',
            'news_events' => 'News & Events',
            'faqs' => 'FAQs',
            'contact_page' => 'Contact Page',
            'footer_settings' => 'Footer Settings',
            'quick_links' => 'Quick Links',
            'portals_links' => 'Portals Links',
            'social_media_links' => 'Social Media Links',
            'seo_settings' => 'SEO Settings for each page',
        ];
    }

    /**
     * The image profile an item's own content type calls for.
     *
     * ── WHY THIS IS PER ITEM TYPE AND NOT GLOBAL ─────────────────────────────
     * `saveImage()` is shared by every kind of website content: 67 programmes, 4
     * leadership portraits, news items, partner logos. Applying the 16:9 programme
     * crop to ALL of them would centre-crop a head-and-shoulders portrait into a
     * landscape frame and cut the person in half — a visible regression in exchange
     * for a specification that only ever applied to programme covers.
     *
     * So the crop is applied to programme catalogue items ONLY. Everything else keeps
     * the original upload behaviour byte-for-byte, which is also why the other
     * content on the site is unaffected by the optimiser at all.
     *
     * @return array{width:int,height:int}|null null = leave the image as uploaded
     */
    private function imageProfileFor(?string $sectionKey, ?string $itemType): ?array
    {
        $isProgramme = $itemType === 'programme'
            || (is_string($sectionKey) && str_starts_with($sectionKey, 'programme_catalog'));

        if (! $isProgramme) {
            return null;
        }

        // Dimensions only. The wording shown to an administrator lives in the Blade
        // partial, which reads the same constants, so there is no second copy of
        // the specification here to drift.
        return [
            'width' => ImageOptimizer::TARGET_WIDTH,
            'height' => ImageOptimizer::TARGET_HEIGHT,
        ];
    }

    /**
     * Store an item image, optimising it when the item type calls for it.
     *
     * The rules that must hold for EVERY image are unchanged from `saveImage()`:
     * a generated filename, an extension allow-list, content-based MIME checking, and
     * the superseded file deleted only after the new one is safely written.
     *
     * With a profile, the bytes additionally go through the verified
     * `ImageOptimizer`, which centre-crops to the target ratio and re-encodes.
     * Without one, the original `SafeUpload` path runs untouched.
     *
     * @param  array{width:int,height:int}|null  $profile
     */
    private function saveItemImage(Request $request, ?array $profile, ?string $old = null): ?string
    {
        if (! $request->hasFile('image')) {
            // No replacement uploaded: the existing image is preserved. This is what
            // makes editing a programme's title safe.
            return $old;
        }

        $dir = public_path('assets/uploads/website/');

        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $file = $request->file('image');

        if ($profile === null) {
            $name = SafeUpload::store($file, $dir, ['jpg', 'jpeg', 'png', 'webp'])
                ?? abort(422, 'This file type is not allowed.');

            $this->discardIfPresent($dir, $old);

            return $name;
        }

        // The optimiser validates, crops and re-encodes. A validation failure is a
        // ValidationException, which Laravel renders as a field error on the form —
        // so an administrator sees "That file is not a valid PNG image." rather than
        // a 422 page.
        //
        // Constructed directly rather than through the container: the class takes
        // only plain ints and has no dependencies, and resolving those by name
        // through the container is version-dependent behaviour for no benefit.
        $result = (new ImageOptimizer($profile['width'], $profile['height']))->process($file);

        // Application-generated name. The client's filename never reaches the disk,
        // and the extension comes from what was actually produced, not from what the
        // client claimed.
        $name = bin2hex(random_bytes(16)).'.'.$result['extension'];

        File::put($dir.$name, $result['bytes']);

        $this->discardIfPresent($dir, $old);

        return $name;
    }

    /** Remove a superseded file, if there is one and it is really there. */
    private function discardIfPresent(string $dir, ?string $old): void
    {
        if (! empty($old) && File::exists($dir.$old)) {
            File::delete($dir.$old);
        }
    }

    private function saveImage(Request $request, $field, $old = null)
    {
        if (!$request->hasFile($field)) {
            return $old;
        }

        $dir = public_path('assets/uploads/website/');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        // Security Phase 2F: generated name with an allowed extension (the image|mimes rule only
        // checks content); the old image is removed only once the new one is safely stored.
        $name = SafeUpload::store($request->file($field), $dir, ['jpg', 'jpeg', 'png', 'webp']) ?? abort(422, 'This file type is not allowed.');

        if (!empty($old) && File::exists($dir . $old)) {
            File::delete($dir . $old);
        }

        return $name;
    }

    /**
     * Handle an explicit "remove this image" request.
     *
     * ── WHY THIS IS NEEDED ───────────────────────────────────────────────────
     * `saveImage()` returns the existing filename when no new file is posted, which
     * is correct: an edit form that omits the file field means "leave the image
     * alone", and without that rule every unrelated save of a programme's title would
     * silently delete its photograph.
     *
     * The consequence, however, is that once an image is set there was NO WAY to
     * remove it. The CMS could upload and replace, but not clear — so an
     * administrator who uploaded the wrong photograph, or who wants a programme to
     * return to the designed fallback, had no route to do it except editing the
     * database by hand. The brief asks for images to be "uploaded, replaced and
     * managed", and managing includes removing.
     *
     * Returns the new filename to store, or NULL when nothing was requested (so the
     * caller falls through to `saveImage()`). The old file is deleted only AFTER the
     * database row is updated by the caller, which is the same ordering
     * `saveImage()` uses.
     *
     * @return string|null '' = remove, null = not requested
     */
    private function removeItemImage(Request $request, ?string $current): ?string
    {
        if (! $request->boolean('remove_image')) {
            return null;
        }

        return '';
    }

    /**
     * Delete an item's image file from disk, if it has one.
     *
     * Called after the row has been updated, so a failure here cannot leave the
     * database pointing at a file that no longer exists.
     */
    private function deleteStoredImage(?string $filename): void
    {
        if (empty($filename)) {
            return;
        }

        $path = public_path('assets/uploads/website/'.$filename);

        if (File::exists($path)) {
            File::delete($path);
        }
    }

    private function backToPanel(Request $request, $message)
    {
        if ($request->is('admin/*')) {
            return redirect()->route('admin.website.index')->with('message', $message);
        }

        return redirect()->route('superadmin.website.index')->with('message', $message);
    }

    public function superadminIndex()
    {
        return view('superadmin.website_management.index', $this->viewData());
    }

    public function adminIndex()
    {
        return view('admin.website_management.index', $this->viewData());
    }

    public function storePage(Request $request)
    {
        $this->ensureWebsiteTablesAndSeed();

        $data = $request->validate([
            'page_key' => ['required', 'string', 'max:191', Rule::unique('website_pages', 'page_key')->where('school_id', $this->cmsSchoolId())],
            'title' => 'required|string|max:191',
            'slug' => 'nullable|string|max:191',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $this->createOwned(WebsitePage::class, $data);

        return $this->backToPanel($request, 'Website page created successfully.');
    }

    public function updatePage(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $page = $this->findOwned(WebsitePage::class, $id);

        $data = $request->validate([
            'page_key' => ['required', 'string', 'max:191', Rule::unique('website_pages', 'page_key')->where('school_id', $page->school_id)->ignore($page->id)],
            'title' => 'required|string|max:191',
            'slug' => 'nullable|string|max:191',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['updated_by'] = auth()->id();

        $page->update($data);

        return $this->backToPanel($request, 'Website page updated successfully.');
    }

    public function deletePage(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $page = $this->findOwned(WebsitePage::class, $id);
        $page->delete();

        return $this->backToPanel($request, 'Website page deleted successfully.');
    }

    public function storeSection(Request $request)
    {
        $this->ensureWebsiteTablesAndSeed();

        $data = $request->validate([
            'page_key' => 'required|string|max:191',
            'section_key' => 'required|string|max:191',
            'title' => 'nullable|string|max:191',
            'subtitle' => 'nullable|string|max:191',
            'content' => 'nullable|string',
            'extra_json' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['image'] = $this->saveImage($request, 'image');

        $this->createOwned(WebsiteSection::class, $data);

        return $this->backToPanel($request, 'Section created successfully.');
    }

    public function updateSection(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $section = $this->findOwned(WebsiteSection::class, $id);

        $data = $request->validate([
            'page_key' => 'required|string|max:191',
            'section_key' => 'required|string|max:191',
            'title' => 'nullable|string|max:191',
            'subtitle' => 'nullable|string|max:191',
            'content' => 'nullable|string',
            'extra_json' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['image'] = $this->saveImage($request, 'image', $section->image);

        $section->update($data);

        return $this->backToPanel($request, 'Section updated successfully.');
    }

    public function deleteSection(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $section = $this->findOwned(WebsiteSection::class, $id);

        if (!empty($section->image)) {
            $file = public_path('assets/uploads/website/' . $section->image);
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        $section->delete();

        return $this->backToPanel($request, 'Section deleted successfully.');
    }

    public function storeItem(Request $request)
    {
        $this->ensureWebsiteTablesAndSeed();

        $data = $request->validate([
            'section_key' => 'required|string|max:191',
            'item_type' => 'nullable|string|max:191',
            'title' => 'nullable|string|max:191',
            'subtitle' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'link' => 'nullable|string|max:191',
            'button_text' => 'nullable|string|max:191',
            'meta_json' => 'nullable|string',
            'featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['item_type'] = $data['item_type'] ?? 'general';
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['image'] = $this->saveItemImage(
            $request,
            $this->imageProfileFor($data['section_key'], $data['item_type'])
        );
        $data['meta_json'] = $this->applyFeaturedFlag($request, $data['meta_json'] ?? null);

        // `featured` is a UI convenience for `meta_json`, not a column.
        unset($data['featured']);

        $this->createOwned(WebsiteItem::class, $data);

        return $this->backToPanel($request, 'Item created successfully.');
    }

    public function updateItem(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $item = $this->findOwned(WebsiteItem::class, $id);

        $data = $request->validate([
            'section_key' => 'required|string|max:191',
            'item_type' => 'nullable|string|max:191',
            'title' => 'nullable|string|max:191',
            'subtitle' => 'nullable|string|max:191',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'link' => 'nullable|string|max:191',
            'button_text' => 'nullable|string|max:191',
            'meta_json' => 'nullable|string',
            'featured' => 'nullable|boolean',
            // Explicit "remove the current image" opt-in. See removeItemImage().
            'remove_image' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'status' => 'nullable|integer',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data['item_type'] = $data['item_type'] ?? 'general';
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['image'] = $this->removeItemImage($request, $item->image)
            ?? $this->saveItemImage(
                $request,
                // Read from the request when it was actually posted, else from the
                // stored row. `$data['item_type'] ?? $item->item_type` would be dead:
                // line above defaults it to 'general', so the fallback could never
                // run. That matters because the profile is chosen by item type —
                // silently deciding 'general' here would store a programme cover
                // uncropped and then, on the NEXT save, crop it. The edit form does
                // post item_type, so this is a guard rather than a live path.
                $this->imageProfileFor(
                    $data['section_key'],
                    $request->filled('item_type') ? $data['item_type'] : $item->item_type
                ),
                $item->image
            );

        // Merge onto the STORED meta_json, not the submitted one. The edit form does
        // not render a meta_json textarea, so `$request->input('meta_json')` is null
        // on an ordinary save; reading from the request instead of the stored row
        // would therefore silently wipe any other meta an item already carries.
        $data['meta_json'] = $this->applyFeaturedFlag($request, $item->meta_json);

        unset($data['featured'], $data['remove_image']);

        // Capture the outgoing filename BEFORE the update, so the file can be deleted
        // only once the row no longer points at it. Deleting first would leave a
        // window where the database references a file that is already gone.
        $outgoingImage = $item->image;

        $item->update($data);

        // Only when the image was explicitly cleared, and only when the row really no
        // longer references it.
        if ($request->boolean('remove_image') && $item->fresh()->image !== $outgoingImage) {
            $this->deleteStoredImage($outgoingImage);
        }

        return $this->backToPanel($request, 'Item updated successfully.');
    }

    /**
     * Translate the CMS form's `featured` checkbox into the `meta_json` column.
     *
     * ── WHY meta_json AND NOT A NEW COLUMN ────────────────────────────────────
     * `website_items.meta_json` already exists, is already writable through this
     * controller, and is empty for all 121 rows. A "featured programme" flag is
     * exactly the kind of per-item metadata that column is for, so storing it there
     * needs no migration, no schema lock on a live table, and no change to any
     * existing row.
     *
     * A dedicated `is_featured` column would be marginally faster to query. On 121
     * rows, and with the JSON decoded in PHP after a single fetch, that is not a
     * consideration — and a migration on a table this size in a live system is a
     * larger risk than the query it would optimise.
     *
     * ── WHAT IS PRESERVED ─────────────────────────────────────────────────────
     * Any OTHER key already in meta_json survives. Only `featured` is written, so
     * using this form cannot discard unrelated metadata.
     *
     * ── UNCHECKING REMOVES THE KEY ────────────────────────────────────────────
     * Not set to false. An absent key and an explicit false both read as "not
     * featured"; removing it keeps the stored JSON minimal and means a later reader
     * cannot be confused by a stale `false`.
     */
    private function applyFeaturedFlag(Request $request, ?string $existing): ?string
    {
        // Decode the STORED value. This has to happen before anything is written, or
        // ticking the box silently discards whatever else the item already carried.
        //
        // It was a real bug: `$meta` was initialised to `[]`, which is already an
        // array, so the `if (! is_array($meta))` guard below could never be true and
        // the decode never ran. Every existing meta_json key was therefore dropped on
        // any save that included the Featured checkbox — the precise outcome this
        // method's own comment claims to prevent.
        $meta = [];

        if (is_array($existing)) {
            $meta = $existing;
        } elseif (is_string($existing) && trim($existing) !== '') {
            $decoded = json_decode($existing, true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        if ($request->boolean('featured')) {
            $meta['featured'] = true;
        } else {
            unset($meta['featured']);
        }

        return $meta === [] ? null : json_encode($meta);
    }

    public function deleteItem(Request $request, $id)
    {
        $this->ensureWebsiteTablesAndSeed();

        $item = $this->findOwned(WebsiteItem::class, $id);

        if (!empty($item->image)) {
            $file = public_path('assets/uploads/website/' . $item->image);
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        $item->delete();

        return $this->backToPanel($request, 'Item deleted successfully.');
    }

    public function upsertSettings(Request $request)
    {
        $this->ensureWebsiteTablesAndSeed();

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:191',
            'settings.*.value' => 'nullable|string',
            'settings.*.is_json' => 'nullable|integer',
            'settings.*.status' => 'nullable|integer',
        ]);

        foreach ($validated['settings'] as $setting) {
            $this->upsertOwned(WebsiteSetting::class,
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'] ?? null,
                    'is_json' => $setting['is_json'] ?? 0,
                    'status' => $setting['status'] ?? 1,
                ]
            );
        }

        return $this->backToPanel($request, 'Website settings updated successfully.');
    }

    public function upsertSeo(Request $request)
    {
        $this->ensureWebsiteTablesAndSeed();

        $validated = $request->validate([
            'seo' => 'required|array',
            'seo.*.page_key' => 'required|string|max:191',
            'seo.*.meta_title' => 'nullable|string|max:191',
            'seo.*.meta_description' => 'nullable|string',
            'seo.*.meta_keywords' => 'nullable|string',
            'seo.*.canonical_url' => 'nullable|string|max:191',
            'seo.*.status' => 'nullable|integer',
        ]);

        foreach ($validated['seo'] as $row) {
            $this->upsertOwned(WebsiteSeoSetting::class,
                ['page_key' => $row['page_key']],
                [
                    'meta_title' => $row['meta_title'] ?? null,
                    'meta_description' => $row['meta_description'] ?? null,
                    'meta_keywords' => $row['meta_keywords'] ?? null,
                    'canonical_url' => $row['canonical_url'] ?? null,
                    'status' => $row['status'] ?? 1,
                ]
            );
        }

        return $this->backToPanel($request, 'SEO settings updated successfully.');
    }
}
