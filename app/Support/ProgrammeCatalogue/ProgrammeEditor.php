<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use App\Support\Images\ImageOptimizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Save details, public images and catalogue visibility as one operation. */
class ProgrammeEditor
{
    public function __construct(
        private ImageOptimizer $optimizer,
        private ProgrammeCoverImage $covers,
        private ProgrammeGalleryImageService $gallery,
        private ProgrammePublisher $publisher,
        private ProgrammeCatalogueSchema $schema,
    ) {}

    public function save(Request $request, array $validated, ?Programme $existing = null): Programme
    {
        $cover = $request->file('cover_image');
        $uploads = array_filter((array) $request->file('gallery_images', []));
        $removals = $validated['remove_gallery_images'] ?? [];
        if (($cover || $request->boolean('remove_cover') || $request->boolean('is_published')) && ! $this->schema->columnsExist()) {
            throw ValidationException::withMessages(['cover_image' => 'Catalogue images and publication are not available yet.']);
        }
        if (($uploads || $removals) && ! $this->gallery->ready()) {
            throw ValidationException::withMessages(['gallery_images' => 'The programme gallery is not available yet.']);
        }
        if ($request->boolean('is_published') && $existing && ! $existing->is_active) {
            throw ValidationException::withMessages(['is_published' => 'Activate this programme before publishing it.']);
        }
        // Decode every image before changing any record or replacing existing files.
        $prepared = [];
        foreach (array_filter(['cover_image' => $cover]) + collect($uploads)->mapWithKeys(fn ($file, $i) => ['gallery_images.'.$i => $file])->all() as $key => $file) {
            try {
                $prepared[$key] = $this->optimizer->process($file);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([$key => collect($e->errors())->flatten()->all()]);
            }
        }
        $newFiles = [];
        try {
            return DB::transaction(function () use ($request, $validated, $existing, $cover, $uploads, $removals, $prepared, &$newFiles) {
                $programme = $existing
                    ? Programme::where('school_id', $existing->school_id)->whereKey($existing->id)->lockForUpdate()->firstOrFail()
                    : new Programme();
                $owned = $existing && $this->gallery->ready() ? $this->gallery->records($programme)->pluck('id')->all() : [];
                if (array_diff($removals, $owned)) {
                    throw ValidationException::withMessages(['remove_gallery_images' => 'Choose images belonging to this programme.']);
                }
                if (count($owned) - count($removals) + count($uploads) > ProgrammeGalleryImageService::MAX_IMAGES) {
                    throw ValidationException::withMessages(['gallery_images' => 'A programme can have at most two gallery images. Select an existing image for removal to replace it.']);
                }
                $programme->fill(Arr::except($validated, ['cover_image', 'gallery_images', 'remove_cover', 'remove_gallery_images', 'is_published', 'section_key']))->save();
                if (($validated['department_id'] ?? null) && Schema::hasColumn('programmes', 'legacy_department_id')) {
                    $programme->legacy_department_id = null;
                    $programme->save();
                }
                if ($request->boolean('is_published') && ! $programme->is_active) {
                    throw ValidationException::withMessages(['is_published' => 'Activate this programme before publishing it.']);
                }
                if ($cover) {
                    $this->covers->set($programme, $cover, $prepared['cover_image']);
                    $newFiles[] = $this->covers->absolutePath($programme->cover_image_path);
                } elseif ($request->boolean('remove_cover')) {
                    $this->covers->clear($programme);
                }
                foreach ($removals as $id) $this->gallery->remove($programme, (int) $id);
                foreach ($uploads as $i => $upload) {
                    $image = $this->gallery->add($programme, $upload, $prepared['gallery_images.'.$i]);
                    $newFiles[] = $this->gallery->absolutePath($image->path);
                }
                if ($this->schema->columnsExist() && $request->has('is_published')) {
                    if ($request->boolean('is_published')) $this->publisher->publish($programme, $request->input('section_key'));
                    elseif ($programme->is_published) $this->publisher->unpublish($programme);
                } elseif ($programme->is_published) {
                    $this->publisher->refreshProjection($programme);
                }
                return $programme->refresh();
            });
        } catch (\Throwable $e) {
            foreach ($newFiles as $path) File::delete($path);
            throw $e;
        }
    }
}
