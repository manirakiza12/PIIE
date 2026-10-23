<?php

namespace App\Support\Staff;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StaffUploadLimits
{
    // Keep 4 MiB of headroom below PHP's 24 MiB POST ceiling.
    public const TOTAL_BYTES = 20 * 1024 * 1024;
    public const MESSAGE = 'Combined staff uploads must not exceed 20 MiB. Reduce the files or add remaining documents from the staff profile after creation.';

    public static function validate(Request $request): void
    {
        $bytes = 0;
        $files = $request->allFiles();
        array_walk_recursive($files, function ($file) use (&$bytes) {
            if ($file->isValid()) {
                $bytes += $file->getSize();
            }
        });
        if ($bytes > self::TOTAL_BYTES) {
            throw ValidationException::withMessages(['uploads' => self::MESSAGE]);
        }
    }
}
