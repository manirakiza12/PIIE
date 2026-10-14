<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use App\Models\ProgrammeGalleryImage;
use App\Support\Images\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The additional pictures that accompany a Programme's cover.
 *
 * ── WHY THIS IS NOT `ProgrammeCoverImage`, AND NOT `CourseCoverImage` ────────
 *
 * Three image pipelines exist in this codebase and they must stay separate:
 *
 *   ProgrammeCoverImage   public, one per programme, web-served, 16:9 cropped.
 *   THIS class            public, up to two per programme, web-served, 16:9 cropped.
 *   CourseCoverImage      PRIVATE teaching material, outside the web root, read
 *                         only through a route that re-checks the viewer's
 *                         allocation or confirmed registration.
 *
 * Merging the gallery into the cover would make "which pictures may an
 * anonymous applicant load" ambiguous; merging it into `CourseCoverImage` would
 * leak teaching material. So the gallery gets its own service, its own directory
 * and its own table, and borrows only the content-validating optimiser.
 *
 * ── THE TWO-IMAGE RULE IS ENFORCED HERE, NOT IN THE SCHEMA ───────────────────
 *
 * A unique index on `(programme_id, sort_order)` makes duplicate positions
 * impossible but says nothing about how many images a programme may have — that
 * is a product rule, so it lives in `add()` and is checked inside the same
 * transaction that writes the row. The database therefore cannot be talked into
 * a third gallery image, and neither can a race between two concurrent uploads.
 */
class ProgrammeGalleryImageService
{
    /** Public upload directory, kept beside the cover's own. */
    public const DIRECTORY = 'assets/uploads/programme-gallery';

    /**
     * How many additional images a programme may carry.
     *
     * Two, as specified. Exceeding it is a validation error on the upload, not a
     * silent truncation — an administrator who added three must be told the third
     * was refused rather than discovering later that it vanished.
     */
    public const MAX_IMAGES = 2;

    /** Same allow-list as the cover. An allow-list, never a block-list. */
    public const ALLOWED = ProgrammeCoverImage::ALLOWED;

    /**
     * Whether `programme_gallery_images` exists yet.
     *
     * Memoised on the INSTANCE, not on a static. The suite boots a fresh
     * application per test inside one PHP process, so a static would let whichever
     * test asked first decide the answer for every test afterwards. Resolved from
     * the container the memo lasts exactly one request — or one test.
     */
    private ?bool $ready = null;

    public function __construct(private ImageOptimizer $optimizer) {}

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('programme_gallery_images');
    }

    /**
     * The programme's gallery images, in display order.
     *
     * A row whose bytes have gone missing is skipped rather than emitted, so a
     * half-finished deploy cannot produce a broken <img> on a public page.
     *
     * @return Collection<int, ProgrammeGalleryImage>
     */
    public function images(Programme $programme): Collection
    {
        return $this->records($programme)->filter(fn (ProgrammeGalleryImage $image) => $this->fileExists($image))->values();
    }

    /** Administrative records include images whose files need recovery or removal. */
    public function records(Programme $programme): Collection
    {
        if (! $this->ready()) {
            return new Collection;
        }

        return ProgrammeGalleryImage::query()
            ->where('school_id', $programme->school_id)
            ->where('programme_id', $programme->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** How many images are actually stored, including any whose bytes are missing. */
    public function countFor(Programme $programme): int
    {
        if (! $this->ready()) {
            return 0;
        }

        return ProgrammeGalleryImage::query()
            ->where('school_id', $programme->school_id)
            ->where('programme_id', $programme->id)
            ->count();
    }

    public function hasCapacity(Programme $programme): bool
    {
        return $this->countFor($programme) < self::MAX_IMAGES;
    }

    /** Public URL for one image, or null when its bytes are gone. */
    public function url(?ProgrammeGalleryImage $image): ?string
    {
        if (! $image instanceof ProgrammeGalleryImage || ! $this->fileExists($image)) {
            return null;
        }

        return asset(self::DIRECTORY.'/'.$image->path);
    }

    /** Absolute on-disk path. Generated names only, so no traversal is possible. */
    public function absolutePath(string $path): string
    {
        return public_path(self::DIRECTORY.'/'.$path);
    }

    /**
     * Add one image to a programme's gallery.
     *
     * The capacity check and the insert share a transaction and take a row lock on
     * the programme, so two simultaneous uploads cannot both observe the last
     * free slot and both take it.
     */
    public function add(Programme $programme, UploadedFile $file, ?array $processed = null): ProgrammeGalleryImage
    {
        if (! $this->ready()) {
            // A rolling deploy can serve the new UI a moment before the table
            // exists. Saying so plainly is better than a SQL error page, and
            // better than silently succeeding and losing the picture.
            throw ValidationException::withMessages([
                'gallery_image' => 'The programme gallery is not available yet on this installation.',
            ]);
        }

        return DB::transaction(function () use ($programme, $file, $processed) {
            // Locks the programme row for the duration, serialising concurrent
            // adds. The images themselves are counted under the same lock.
            Programme::query()->where('school_id', $programme->school_id)->whereKey($programme->id)->lockForUpdate()->firstOrFail();

            $existing = ProgrammeGalleryImage::query()
                ->where('school_id', $programme->school_id)
                ->where('programme_id', $programme->id)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            if ($existing->count() >= self::MAX_IMAGES) {
                throw ValidationException::withMessages([
                    'gallery' => 'A programme can have at most '.self::MAX_IMAGES
                        .' additional images. Remove one before adding another.',
                ]);
            }

            // The next free slot. Reusing the lowest free position keeps the
            // unique (programme_id, sort_order) index satisfied without renumbering.
            $used = $existing->pluck('sort_order')->map(fn ($n) => (int) $n);
            $order = 1;
            while ($used->contains($order)) {
                $order++;
            }

            // Same content validation, crop and re-encode as the cover: the
            // optimiser checks the real bytes with finfo and GD, never the
            // client's filename. A ValidationException here renders as a field
            // error on the form.
            try {
                $result = $processed ?? $this->optimizer->process($file);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'gallery_image' => collect($e->errors())->flatten()->all(),
                ]);
            }

            $name = bin2hex(random_bytes(16)).'.'.$result['extension'];

            $directory = public_path(self::DIRECTORY);
            if (! File::isDirectory($directory)) {
                File::makeDirectory($directory, 0755, true);
            }

            File::put($directory.'/'.$name, $result['bytes']);

            try {
                return ProgrammeGalleryImage::create([
                    'school_id'     => $programme->school_id,
                    'programme_id'  => $programme->id,
                    'sort_order'    => $order,
                    'path'          => $name,
                    // Truncated for display only. Never used to build a path.
                    'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 191),
                    'mime'          => mb_substr((string) $result['mime'], 0, 100),
                    'size'          => strlen($result['bytes']),
                    'width'         => $result['width'],
                    'height'        => $result['height'],
                ]);
            } catch (\Throwable $e) {
                // The row could not be written, so the bytes just written are
                // unreachable. Removing them here is what stops an orphan file
                // accumulating in a web-served directory on every failed insert.
                File::delete($directory.'/'.$name);

                throw $e;
            }
        });
    }

    /**
     * Remove one gallery image.
     *
     * Scoped to the programme that owns it, so an image id from another programme
     * — or another institution — is simply not found. The programme itself is
     * never touched: only the image row and its own bytes go.
     */
    public function remove(Programme $programme, int $imageId): void
    {
        if (! $this->ready()) {
            return;
        }

        $image = ProgrammeGalleryImage::query()
            ->where('school_id', $programme->school_id)
            ->where('programme_id', $programme->id)
            ->whereKey($imageId)
            ->first();

        if (! $image) {
            return;
        }

        $image->delete();

        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->dispose($image->path));
        } else {
            $this->dispose($image->path);
        }
    }

    /**
     * Delete an image's bytes.
     *
     * Defence in depth against a hand-edited path naming something outside the
     * gallery directory. The check is on the SHAPE of the value, not on where it
     * resolves, so a traversal attempt is discarded instead of acted on.
     */
    private function dispose(?string $path): void
    {
        $this->deleteFile($path);
    }

    public function deleteFile(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        if (basename($path) !== $path || str_contains($path, '/') || str_contains($path, '\\')) {
            return;
        }

        $absolute = $this->absolutePath($path);

        if (File::isFile($absolute)) {
            File::delete($absolute);
        }
    }

    private function fileExists(ProgrammeGalleryImage $image): bool
    {
        return is_string($image->path)
            && trim($image->path) !== ''
            && basename($image->path) === $image->path
            && ! str_contains($image->path, '/')
            && ! str_contains($image->path, '\\')
            && File::isFile($this->absolutePath($image->path));
    }
}
