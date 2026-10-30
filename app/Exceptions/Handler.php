<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'consumer_key',
        'consumer_secret',
        'client_secret',
        // Staff NIN: never re-displayed from the session after a failed form.
        'nin',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * PHP rejects the whole request when `post_max_size` is exceeded. That
     * rejection is a raw warning, not an exception, and it arrives here as an
     * `HttpException` with code 413 before any controller runs. Without this
     * override the student sees the Symfony "Unsupported media type" page, which
     * says nothing about what to do next and nothing about the work they just
     * lost.
     *
     * This converts it into the same shape the in-request `UploadGate` throws -
     * a redirect back with a validation error - so the existing form error
     * display handles it without a new code path.
     *
     * WHAT THIS MUST NOT PROMISE
     *
     * A 413 from the server means the body never reached Laravel. Nothing was
     * received, nothing was saved, and the draft path did not run. The message
     * says exactly that: the text the student typed is still in their browser
     * and can be re-pasted, but it was never stored. Claiming otherwise would
     * be a false comfort, and a student who trusted it would lose work twice.
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof HttpException && $exception->getStatusCode() === 413) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The files you tried to send were too large for the server to accept. Nothing was submitted and nothing was saved.',
                    'files' => \App\Support\Assignments\UploadLimit::LABEL,
                ], 413);
            }

            throw \Illuminate\Validation\ValidationException::withMessages([
                'files' => \App\Support\Assignments\UploadLimit::serverRejectedMessage(),
            ]);
        }

        return parent::render($request, $exception);
    }
}
