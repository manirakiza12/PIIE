<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Security Phase 2E: one place that stores a user profile photo into
 * public/assets/uploads/user-images/ (the existing location every view reads
 * from). Previously each form stored time() . '.' . $file->extension(), so
 * any content type was kept under its detected extension — HTML, SVG and
 * plain text were all written into the web-served folder.
 *
 * Only JPG and PNG are accepted — the only formats real PIIE uploads use —
 * judged by the file's detected content, not its name, and at most MAX_KB.
 * The file is stored under an application-generated name.
 */
class ProfilePhoto
{
    public const MAX_KB = 4096; // same limit as the student additional_photo rule

    private const CONTENT_EXTENSIONS = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png'];

    /** Stores the photo and returns its filename, or null when the file is not an acceptable image. */
    public static function store(UploadedFile $file): ?string
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_KB * 1024) {
            return null;
        }

        $extension = self::CONTENT_EXTENSIONS[strtolower((string) $file->guessExtension())] ?? null;
        if ($extension === null) {
            return null;
        }

        $name = bin2hex(random_bytes(20)) . '.' . $extension;
        \Illuminate\Support\Facades\File::ensureDirectoryExists(public_path('assets/uploads/user-images/'), 0775, true);
        $file->move(public_path('assets/uploads/user-images/'), $name);

        return $name;
    }
}
