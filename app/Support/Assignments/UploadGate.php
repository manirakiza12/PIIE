<?php

namespace App\Support\Assignments;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The gate that runs BEFORE any file is moved.
 *
 * The order matters. PHP rejects the whole request when `post_max_size` is
 * exceeded, and that rejection is a raw warning - not an exception, not a
 * validation error, nothing a controller can catch. So this class does not
 * try to catch the rejection; it PREVENTS it by refusing the request earlier,
 * at the point where the student's text is still available to be saved as a
 * draft.
 *
 * It is called from the controller, before `saveDraft` or `submit`, and it
 * throws a `ValidationException` - the same kind the existing per-file checks
 * throw - so the existing error-display path handles it without a new one.
 *
 * WHAT THIS GATE CHECKS
 *
 *   Aggregate size, in two ways: `CONTENT_LENGTH` from the server (the figure
 *   PHP actually used to decide whether to accept the body) and the sum of
 *   the real uploaded file sizes (the fallback when the server does not
 *   report a content length, which is common in test environments and some
 *   proxies). Either one is sufficient to refuse.
 *
 *   File count, against a hard cap.
 *
 *   It deliberately does NOT re-check per-file size. The SubmissionService
 *   already validates each file's format, size and kind against the
 *   assignment's own allowlist; a second gate here would reject files the
 *   service would have accepted and break existing assignment tests.
 */
class UploadGate
{
    public function __construct(private UploadLimit $limit) {}

    /**
     * Refuse the request before any work is done, when the aggregate is over
     * the limit. The student's written answer survives because the controller
     * saves a draft on rejection.
     *
     * @throws ValidationException
     */
    public function assertWithinLimits(Request $request): void
    {
        if ($this->limit->requestTooLarge($request)) {
            throw ValidationException::withMessages(['files' => $this->limit->requestMessage()]);
        }

        $files = $this->limit->allFiles($request);

        if ($this->limit->tooManyFiles($files)) {
            throw ValidationException::withMessages(['files' => $this->limit->tooManyFilesMessage()]);
        }
    }
}