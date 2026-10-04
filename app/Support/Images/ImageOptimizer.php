<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * IMAGE OPTIMISATION ON THE EXISTING GD EXTENSION.
 *
 * ── WHY GD AND NOT A PACKAGE ─────────────────────────────────────────────────
 * The decision is to use the PHP GD extension that is already installed and add no
 * image-processing dependency. That is also the only option available here: this
 * environment has GD loaded, and neither Imagick nor Intervention Image is present.
 * Introducing a package for this would add a composer dependency, a lockfile change
 * and a native-image requirement to a codebase that has neither today.
 *
 * ── WHAT IT DOES ─────────────────────────────────────────────────────────────
 *   - validates size, extension AND decoded content, not just the filename
 *   - centre-crops to 16:9 and resizes to 1600x900 WITHOUT distortion
 *   - re-encodes: JPEG q82, WebP q82, PNG preserved when it carries transparency
 *   - refuses anything that is not a real, decodable raster image
 *
 * ── WHY CENTRE-CROP RATHER THAN LETTERBOX ─────────────────────────────────────
 * A card's media box is a fixed 16:9. Padding a mismatched image to 16:9 would
 * letterbox it — bars above and below — which looks broken on a card grid.
 * Centre-cropping fills the frame, and because the crop is taken from the middle it
 * keeps the subject of a photograph, which is what a portrait or a logo shot needs.
 * Proportions are never distorted: the crop is bounded by the source on both axes.
 *
 * ── WHY THE ASPECT IS A WARNING AND NOT A REJECTION ──────────────────────────
 * A 4:3 photograph is a legitimate upload and must not be refused; it is cropped to
 * 16:9 so the grid stays even. The caller is told what happened through
 * `wasCropped()` so the UI can say "cropped to 16:9" rather than silently altering
 * an administrator's picture.
 */
class ImageOptimizer
{
    /** Target media box. 16:9 at 1600x900, as decided. */
    public const TARGET_WIDTH = 1600;
    public const TARGET_HEIGHT = 900;

    /** Maximum ORIGINAL upload, before any processing. */
    public const MAX_KB = 4 * 1024;

    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** Format constants GD is asked to produce. */
    private const QUALITY = 82;

    public function __construct(
        private readonly int $targetWidth = self::TARGET_WIDTH,
        private readonly int $targetHeight = self::TARGET_HEIGHT,
    ) {}

    /**
     * Validate, crop and optimise an upload.
     *
     * @return array{bytes:string,mime:string,extension:string,width:int,height:int,
     *               originalWidth:int,originalHeight:int,wasCropped:bool,bytesIn:int}
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function process(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['image' => 'The upload did not complete. Choose the file again.']);
        }

        $sizeKb = (int) round($file->getSize() / 1024);

        if ($sizeKb > self::MAX_KB) {
            throw ValidationException::withMessages([
                'image' => 'That image is '.number_format($sizeKb).' KB. The limit is 4 MB.',
            ]);
        }

        $source = $this->readAndValidate($file);

        try {
            $resource = @imagecreatefromstring($source);
        } catch (\Throwable) {
            $resource = false;
        }

        if ($resource === false) {
            throw ValidationException::withMessages([
                'image' => 'That file could not be read as an image. Upload a JPEG, PNG or WebP.',
            ]);
        }

        try {
            return $this->render($resource, $file->getClientOriginalExtension(), strlen($source));
        } finally {
            imagedestroy($resource);
        }
    }

    /**
     * Confirm the file really is one of the allowed image formats.
     *
     * Three independent checks, because any one alone is defeatable:
     *   - the client extension is on the allow-list;
     *   - the extension matches what `finfo` detects, so a `.png` that is really a
     *     PHP script is refused;
     *   - GD can actually decode it.
     */
    private function readAndValidate(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'image' => 'Images must be one of: '.implode(', ', self::EXTENSIONS).'.',
            ]);
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw ValidationException::withMessages(['image' => 'That file could not be read.']);
        }

        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));

        // The client type must be an image AND must agree with the extension.
        // `finfo` is content-based, so this cannot be satisfied by renaming.
        $expected = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ][$extension] ?? [];

        if (! in_array($detected, $expected, true)) {
            throw ValidationException::withMessages([
                'image' => 'That file is not a valid '.strtoupper($extension).' image.',
            ]);
        }

        $dimensions = @getimagesize($path);

        if (! $dimensions || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1) {
            throw ValidationException::withMessages(['image' => 'That image could not be decoded.']);
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['image' => 'That file is empty.']);
        }

        return $contents;
    }

    /**
     * Centre-crop to the target ratio, scale, and re-encode.
     *
     * @param resource $resource
     */
    private function render($resource, string $clientExtension, int $bytesIn): array
    {
        $sourceWidth = imagesx($resource);
        $sourceHeight = imagesy($resource);

        $targetRatio = $this->targetWidth / $this->targetHeight;
        $sourceRatio = $sourceWidth / max(1, $sourceHeight);

        // A ratio difference under half a percent is treated as already correct, so
        // a 1600x900 upload is not needlessly re-cropped and softened.
        $wasCropped = abs($sourceRatio - $targetRatio) / $targetRatio > 0.005;

        if ($wasCropped) {
            // Crop the largest 16:9 rectangle that fits inside the source.
            if ($sourceRatio > $targetRatio) {
                $cropHeight = $sourceHeight;
                $cropWidth = (int) round($cropHeight * $targetRatio);
            } else {
                $cropWidth = $sourceWidth;
                $cropHeight = (int) round($cropWidth / $targetRatio);
            }

            $cropX = (int) max(0, floor(($sourceWidth - $cropWidth) / 2));
            $cropY = (int) max(0, floor(($sourceHeight - $cropHeight) / 2));
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;
            $cropX = 0;
            $cropY = 0;
        }

        /**
         * Resample the crop window onto the canvas.
         *
         * ── THE ARGUMENT CONTRACT, WHICH IS EASY TO GET WRONG ────────────────
         *
         *   imagecopyresampled(
         *       dst, src,
         *       dst_x, dst_y,        <- WHERE TO WRITE ON THE CANVAS
         *       src_x, src_y,        <- WHERE TO READ FROM THE SOURCE, IN SOURCE PIXELS
         *       dst_width, dst_height,<- SIZE OF THE CANVAS REGION
         *       src_width, src_height<- SIZE OF THE SOURCE REGION, IN SOURCE PIXELS
         *   );
         *
         * GD maps the source rectangle onto the destination rectangle and does the
         * scaling itself. So NO scale factor belongs in this call at all.
         *
         * The first version computed `$scale`, then passed
         * `(int) round($cropX * $scale)` and `(int) round($cropY * $scale)` as the
         * source origin. That is wrong twice over:
         *
         *   - the source origin is already in source pixels, so multiplying it by the
         *     scale factor shifts the crop window away from the centre;
         *   - for any source SMALLER than the target, `$scale` is greater than 1, so
         *     `$cropX * $scale + $scaledWidth` can run past the right edge of the
         *     source and GD reads out of bounds — which on a real photograph shows up
         *     as a black band or a smeared edge, not as an exception.
         *
         * Passing the crop rectangle unscaled is both correct and simpler: the
         * scale, the scaled width and the scaled height are all unnecessary, because
         * GD derives them from the two rectangles.
         */
        $canvas = imagecreatetruecolor($this->targetWidth, $this->targetHeight);

        // Alpha must survive the resample. Blending is off while the transparent
        // canvas is filled, then back on so imagecopyresampled composites normally.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        imagecopyresampled(
            $canvas,
            $resource,
            0,
            0,
            $cropX,
            $cropY,
            $this->targetWidth,
            $this->targetHeight,
            $cropWidth,
            $cropHeight
        );

        $outputFormat = $this->outputFormat($resource, $clientExtension);
        $bytes = $this->encode($canvas, $outputFormat);

        imagedestroy($canvas);

        return [
            'bytes' => $bytes,
            'mime' => $outputFormat === 'jpeg' ? 'image/jpeg' : 'image/'.$outputFormat,
            'extension' => $outputFormat,
            'width' => $this->targetWidth,
            'height' => $this->targetHeight,
            'originalWidth' => $sourceWidth,
            'originalHeight' => $sourceHeight,
            'wasCropped' => $wasCropped,
            'bytesIn' => $bytesIn,
        ];
    }

    /**
     * Choose the output format.
     *
     * PNG is kept ONLY when it actually carries transparency, because converting
     * transparent artwork to JPEG paints a black box where the transparency was.
     * Everything else becomes JPEG, which is far smaller than PNG for the
     * photographic content a course or programme cover almost always is.
     *
     * WebP is kept when it was supplied, since re-encoding a WebP to JPEG would make
     * it larger for no benefit.
     *
     * The earlier version expressed this as a `extension => format` lookup, which
     * could not express the conditional PNG case and so mapped an OPAQUE png to png
     * — meaning no PNG was ever converted and the "optimised" output was routinely
     * 5x larger than necessary. The probe caught it.
     */
    private function outputFormat($resource, string $clientExtension): string
    {
        if ($clientExtension === 'png' && $this->hasTransparency($resource)) {
            return 'png';
        }

        if ($clientExtension === 'webp') {
            return 'webp';
        }

        return 'jpeg';
    }

    /** @param resource $resource */
    private function hasTransparency($resource): bool
    {
        if (! function_exists('imageistruecolor')) {
            return false;
        }

        // A palette image carries its transparency as a single transparent INDEX,
        // which is the only correct way to ask.
        if (! imageistruecolor($resource)) {
            return imagecolortransparent($resource) >= 0;
        }

        /**
         * Truecolor: GD's ARGB alpha byte runs 0 = FULLY OPAQUE to 127 = FULLY
         * TRANSPARENT, so "has transparency" means the byte is NON-ZERO.
         *
         * This was originally written as `$alpha < 127`, which is inverted — it
         * reported every ordinary photograph as transparent. Verified empirically in
         * `scripts/probe-image-optimizer.php`: opaque truecolor reads back alpha 0,
         * fully transparent reads back 127.
         *
         * ── WHY A GRID AND NOT FIVE POINTS ────────────────────────────────────
         * The first version sampled the four corners and the centre. That is wrong
         * for exactly the case that matters: a logo or a cut-out with a transparent
         * middle band and opaque edges reads as OPAQUE at all five of those points,
         * gets converted to JPEG, and arrives with a black rectangle where the
         * transparency was.
         *
         * A strided grid over the whole frame catches transparency anywhere. The
         * stride keeps it bounded: a 4000x3000 source samples roughly 4096 points
         * rather than 12 million, which is imperceptible and cannot be used to stall
         * an upload.
         */
        $width = imagesx($resource);
        $height = imagesy($resource);

        if ($width < 1 || $height < 1) {
            return false;
        }

        $stepX = max(1, (int) floor($width / 64));
        $stepY = max(1, (int) floor($height / 64));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                if (((imagecolorat($resource, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        // The grid can step over a thin transparent feature. Probe the exact
        // centre row and column as well, which a regular stride can miss.
        $midX = intdiv($width, 2);
        $midY = intdiv($height, 2);

        for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($resource, $x, $midY) >> 24) & 0x7F) > 0) {
                return true;
            }
        }

        for ($y = 0; $y < $height; $y++) {
            if (((imagecolorat($resource, $midX, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @param resource $canvas */
    private function encode($canvas, string $format): string
    {
        ob_start();

        match ($format) {
            'png' => imagepng($canvas, null, 6),
            'webp' => imagewebp($canvas, null, self::QUALITY),
            default => imagejpeg($canvas, null, self::QUALITY),
        };

        return (string) ob_get_clean();
    }
}
