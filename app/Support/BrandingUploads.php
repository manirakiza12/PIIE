<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandingUploads
{
    /** Save references only after every selected file is safely stored. */
    public static function persist(Request $request, array $directories, Closure $save): void
    {
        $stored = [];
        $paths = [];
        try {
            foreach ($directories as $field => $directory) {
                if (!$request->hasFile($field)) {
                    continue;
                }
                $allowed = $field === 'favicon' ? [...SafeUpload::IMAGES, 'ico']
                    : ($field === 'off_pay_ins_file' ? ['jpg', 'png', 'pdf'] : SafeUpload::IMAGES);
                $name = SafeUpload::store($request->file($field), public_path($directory), $allowed);
                if ($name === null) {
                    throw ValidationException::withMessages([$field => 'Choose a supported image file.']);
                }
                $paths[] = public_path($directory.'/'.$name);
                if (!chmod(end($paths), 0644)) {
                    throw new \RuntimeException('Uploaded branding image could not be made readable.');
                }
                $stored[$field] = $name;
            }
            DB::transaction(fn () => $save($stored));
        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages([
                'logo' => 'The images could not be saved. Your previous settings have been kept. Please try again.',
            ]);
        }
    }
}
