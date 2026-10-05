<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use App\Support\Images\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * An academic Programme's approved PUBLIC cover image.
 *
 * ── THIS IS NOT `CourseCoverImage`, AND THE DIFFERENCE IS THE POINT ─────────
 *
 * A Course Offering cover is PRIVATE teaching material: it is stored outside the
 * web root and read only through a route that re-checks the viewer's allocation
 * or confirmed registration. A programme cover is the opposite — it is public
 * marketing material that an anonymous applicant must be able to load, so it has
 * to be web-served. Both exist after this stage and they must not be merged:
 * giving `CourseCoverImage` a public path would leak private material, and
 * giving this one an authorisation check would break every public catalogue
 * page. Decision 5 requires each to keep its own ownership, and this is that
 * boundary.
 *
 * ── WHY THE WEB ROOT, AND WHY THAT IS SAFE HERE ────────────────────────────
 *
 * Files land in `public/assets/uploads/programme-covers/`, alongside the CMS's
 * existing public uploads, and are served by the same `asset()` helper the rest
 * of the site uses. Two properties make this safe:
 *
 *   1. `docroot.sh` keeps `assets/uploads/` a REAL directory across releases and
 *      only symlinks the other children of `assets/`, so an uploaded cover
 *      survives every future deploy. Verified in deploy/tests/sandbox.sh.
 *   2. `SafeUpload` generates the stored name and refuses script-capable types,
 *      so nothing executable can land in a web-served directory.
 *
 * ── A COVER IS OPTIONAL ────────────────────────────────────────────────────
 *
 * A programme with no image is a normal state, not a fault. The card renders a
 * designed fallback keyed on the programme's own faculty. Nothing here invents
 * a stock photograph: a picture of a library over a Business Mathematics
 * programme is a false claim about that programme and somebody else's to
 * licence.
 */
class ProgrammeCoverImage
{
    /** Subdirectory of the existing public uploads tree. */
    public const DIRECTORY = 'assets/uploads/programme-covers';

    /**
     * The only formats a programme cover may be.
     *
     * An allow-list, not a block-list: an unexpected type is refused by default
     * rather than accepted by omission. `SafeUpload::IMAGES` is deliberately not
     * used as-is because it includes GIF, and a 16:9 crop of an animated GIF is
     * both larger and visually wrong in a catalogue card.
     */
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private ImageOptimizer $optimizer) {}

    public function hasCover(?Programme $programme): bool
    {
        return $this->path($programme) !== null;
    }

    /**
     * The stored filename, or null when there is no usable cover.
     *
     * The file must still exist. A row pointing at bytes that are gone — a
     * manually deleted upload, a half-finished deploy — reports no cover so the
     * card falls back rather than emitting a broken <img>.
     */
    public function path(?Programme $programme): ?string
    {
        $name = $programme->cover_image_path ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return File::isFile($this->absolutePath($name)) ? $name : null;
    }

    /** Public URL, or null when there is no usable cover. */
    public function url(?Programme $programme): ?string
    {
        $name = $this->path($programme);

        return $name === null ? null : asset(self::DIRECTORY.'/'.$name);
    }

    /** Absolute on-disk location. Generated names only, so no traversal. */
    public function absolutePath(string $name): string
    {
        return public_path(self::DIRECTORY.'/'.$name);
    }

    /**
     * Store a cover, replacing any previous one.
     *
     * The previous bytes are deleted only AFTER the new file is safely on disk,
     * so a failed upload cannot leave the programme with no image at all. That
     * ordering is the same one `WebsiteManagementController::saveImage()` uses.
     */
    public function set(Programme $programme, UploadedFile $file): Programme
    {
        $directory = public_path(self::DIRECTORY);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $previous = $programme->cover_image_path;

        // The optimiser validates by CONTENT (not by the client's claim), crops
        // to 16:9 to match the card frame, and re-encodes. A validation failure
        // raises a ValidationException, which Laravel renders as a field error on
        // the form, so the administrator reads "That file is not a valid PNG
        // image." instead of receiving a 422 page.
        //
        // The stored name is application-generated and the extension comes from
        // what was actually produced, never from the client's filename.
        $result = $this->optimizer->process($file);

        $name = bin2hex(random_bytes(16)).'.'.$result['extension'];

        File::put($directory.'/'.$name, $result['bytes']);

        $programme->forceFill([
            'cover_image_path'       => $name,
            'cover_image_name'       => mb_substr((string) $file->getClientOriginalName(), 0, 191),
            'cover_image_mime'       => mb_substr((string) $result['mime'], 0, 100),
            'cover_image_size'       => strlen($result['bytes']),
            'cover_image_updated_at' => now(),
        ])->save();

        // Disposed only after the row and the new bytes are both safely written,
        // so a failure cannot leave the programme with no image at all.
        $this->dispose($previous, $name);

        return $programme;
    }

    /**
     * Remove the cover and its bytes.
     *
     * Removing the image is a legitimate administrative act — the wrong
     * photograph was uploaded, or the programme should return to the designed
     * fallback — so it needs a route, exactly as the CMS needed one.
     */
    public function clear(Programme $programme): Programme
    {
        $previous = $programme->cover_image_path;

        $programme->forceFill([
            'cover_image_path'       => null,
            'cover_image_name'       => null,
            'cover_image_mime'       => null,
            'cover_image_size'       => null,
            'cover_image_updated_at' => null,
        ])->save();

        $this->dispose($previous, null);

        return $programme;
    }

    /**
     * Delete a superseded file.
     *
     * A cover is institution-owned material that the institution may withdraw.
     * Keeping every previous version would keep readable files that were
     * deliberately replaced, so a replacement disposes of what it replaced.
     * `$keep` guards the case where the optimiser produced the same name.
     */
    private function dispose(?string $previous, ?string $keep): void
    {
        if (! is_string($previous) || trim($previous) === '' || $previous === $keep) {
            return;
        }

        // Defence in depth: SafeUpload generates these names, but a value that
        // somehow reached the column by hand must not be able to name a file
        // outside the cover directory.
        if (basename($previous) !== $previous || str_contains($previous, '/') || str_contains($previous, '\\')) {
            return;
        }

        $absolute = $this->absolutePath($previous);

        if (File::isFile($absolute)) {
            File::delete($absolute);
        }
    }
}