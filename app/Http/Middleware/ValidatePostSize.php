<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;

class ValidatePostSize extends \Illuminate\Foundation\Http\Middleware\ValidatePostSize
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (PostTooLargeException $exception) {
            $message = 'The upload exceeds the server request limit. Return to the form, reduce the files and select them again.';
            if ($request->is('admin/staff/create/*')) {
                $message .= ' Staff photos may be up to 4 MiB; each supporting document up to '.(\App\Support\Staff\StaffDocumentStorage::maxKb() / 1024).' MiB. '.\App\Support\Staff\StaffUploadLimits::MESSAGE;
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 413);
            }
            // Global middleware runs before sessions/CSRF. Do not redirect or
            // attempt to flash input: PHP has already discarded this body.
            return response()->view('errors.upload-too-large', ['message' => $message], 413);
        }
    }
}
