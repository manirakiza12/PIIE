<?php

namespace App\Support;

/**
 * Passport-style photo processing using native GD.
 * Non-destructive: processes on-the-fly, never overwrites originals.
 * Target aspect ratio: 35:45 (passport standard) ≈ 1:1.2857
 * Card photo display area: 70×88px at 380px card width (1:1.257)
 */
final class PhotoProcessing
{
    private const TARGET_WIDTH = 210;   // 70px × 3 (for 300 DPI print quality)
    private const TARGET_HEIGHT = 264;  // 88px × 3
    private const TARGET_RATIO = self::TARGET_WIDTH / self::TARGET_HEIGHT; // ~0.795
    private const PASSPORT_RATIO = 35 / 45; // ~0.778

    // Fallback placeholder SVG (inline, no external dependency)
    private const PLACEHOLDER_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="210" height="264" viewBox="0 0 210 264"><rect fill="#eceef2" width="210" height="264"/><rect x="55" y="30" width="100" height="100" rx="12" fill="#d8dce3"/><circle cx="105" cy="55" r="25" fill="#bcc0ca"/><ellipse cx="105" cy="155" rx="35" ry="20" fill="#bcc0ca"/></svg>';

    /**
     * Get a processed photo data URI for PDF embedding.
     * Returns data:image/... URI or empty string if unavailable.
     */
    public static function getProcessedPhotoDataUri(?int $userId): string
    {
        if (!$userId) {
            return self::placeholderDataUri();
        }

        $photoUrl = get_user_image($userId);
        if (!$photoUrl || str_starts_with($photoUrl, 'data:')) {
            return self::placeholderDataUri();
        }

        $path = self::urlToPath($photoUrl);
        if (!$path || !is_file($path)) {
            return self::placeholderDataUri();
        }

        return self::processImageToDataUri($path);
    }

    /**
     * Get a processed photo URL for browser display.
     * For now, returns the original URL - browser CSS handles sizing via object-fit.
     * Could be extended to serve a processed version via a route.
     */
    public static function getProcessedPhotoUrl(?int $userId): string
    {
        if (!$userId) {
            return asset('assets/uploads/user-images/thumbnail.png');
        }

        $photoUrl = get_user_image($userId);
        return $photoUrl ?: asset('assets/uploads/user-images/thumbnail.png');
    }

    /**
     * Convert asset URL to filesystem path.
     */
    private static function urlToPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return null;

        $relative = ltrim($path, '/');
        $candidates = [];
        // Deployments may serve the application from a subdirectory, so anchor
        // the path on the public assets directory rather than the URL root.
        if (($assetsAt = strpos($relative, 'assets/')) !== false) {
            $candidates[] = public_path(substr($relative, $assetsAt));
        }
        $candidates[] = public_path($relative);

        $roots = array_filter([realpath(public_path()), realpath(public_path('assets/uploads'))]);
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if (!$resolved || !is_file($resolved)) continue;

            $allowed = collect($roots)->contains(fn ($root) => str_starts_with($resolved, $root.DIRECTORY_SEPARATOR));
            if ($allowed) return $resolved;
        }

        return null;
    }

    /**
     * Process image: crop to passport ratio (center-top bias for face), resize to target.
     */
    private static function processImageToDataUri(string $path): string
    {
        // GD is optional on some production hosts; fall back to the inline
        // placeholder rather than fataling while rendering a PDF.
        if (!function_exists('imagecreatefromjpeg')) {
            return self::placeholderDataUri();
        }

        $mime = mime_content_type($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return self::placeholderDataUri();
        }

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            default => null,
        };

        if (!$src) {
            return self::placeholderDataUri();
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $srcRatio = $srcW / $srcH;

        // Calculate crop box for passport ratio (35:45)
        // Bias toward top (face is usually in upper portion)
        if ($srcRatio > self::PASSPORT_RATIO) {
            // Image is wider than passport ratio - crop sides
            $cropH = $srcH;
            $cropW = (int) round($srcH * self::PASSPORT_RATIO);
            $cropX = (int) round(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            // Image is taller than passport ratio - crop top/bottom, bias top
            $cropW = $srcW;
            $cropH = (int) round($srcW / self::PASSPORT_RATIO);
            $cropX = 0;
            $cropY = (int) round(($srcH - $cropH) * 0.15); // 15% from top (face bias)
        }

        // Ensure crop bounds are valid
        $cropW = min($cropW, $srcW - $cropX);
        $cropH = min($cropH, $srcH - $cropY);
        if ($cropW <= 0 || $cropH <= 0) {
            imagedestroy($src);
            return self::placeholderDataUri();
        }

        // Create cropped image
        $cropped = imagecreatetruecolor($cropW, $cropH);
        imagecopyresampled($cropped, $src, 0, 0, $cropX, $cropY, $cropW, $cropH, $cropW, $cropH);
        imagedestroy($src);

        // Resize to target dimensions
        $dst = imagecreatetruecolor(self::TARGET_WIDTH, self::TARGET_HEIGHT);
        imagecopyresampled($dst, $cropped, 0, 0, 0, 0, self::TARGET_WIDTH, self::TARGET_HEIGHT, $cropW, $cropH);
        imagedestroy($cropped);

        // Output as JPEG data URI
        ob_start();
        imagejpeg($dst, null, 85);
        $data = ob_get_clean();
        imagedestroy($dst);

        return 'data:image/jpeg;base64,'.base64_encode($data);
    }

    private static function placeholderDataUri(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::PLACEHOLDER_SVG);
    }
}