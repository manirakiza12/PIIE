<?php

namespace App\Support\Assignments;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Aggregate upload size enforcement for student submissions.
 *
 * PHP itself rejects the whole request when `post_max_size` is exceeded, and
 * before this class existed that rejection arrived as a raw PHP warning and a
 * a generic Laravel error page. The production error was:
 *
 *     POST Content-Length: 38779420 bytes
 *     Server request limit: 25165824 bytes
 *
 * `38779420` is 37 MB - three 12 MB documents, one after another. The per-file
 * limit of 20 MB let each one through individually; the request limit of 24 MB
 * rejected the bundle. The student saw a broken page and lost their written
 * draft, because nothing had been persisted yet.
 *
 * THE LIMITS, AND WHY THEY ARE WHAT THEY ARE
 *
 *   Per file    20 MB   The existing ceiling, matching SubmissionService.
 *   Aggregate   24 MB   The binding number. Deliberately ABOVE the per-file
 *                        ceiling so a single legitimate file is never refused
 *                        by the aggregate, and deliberately BELOW the
 *                        server's own limits so the application catches the
 *                        over-size before the server does.
 *
 * Server limits observed on this deployment:
 *
 *   Nginx       client_max_body_size 32M
 *   PHP         post_max_size        40M
 *   PHP         upload_max_filesize  40M
 *
 * The 24 MB aggregate sits under all three, with headroom for multipart
 * boundary and field overhead. A request between 24 MB and 32 MB reaches
 * Laravel and is refused here with a message the student can act on. A
 * request above 32 MB never reaches Laravel at all - Nginx returns 413 first,
 * and the exception handler converts that into the same shape.
 *
 * WHAT IS AND IS NOT PROMISED ON REJECTION
 *
 * When the application gate refuses a request, the student's written answer
 * HAS been received by PHP and IS available to save as a draft - the gate
 * runs before any file is moved, so the controller saves the text on
 * rejection and the student does not lose it.
 *
 * When the SERVER rejects a request, nothing was ever received. The handler
 * says so plainly and does not claim the text was saved, because it was not.
 * A student whose request was too large for the server to accept at all has
 * lost nothing but the selection they had not yet submitted - the text they
 * typed is in their browser and can be re-pasted.
 */
class UploadLimit
{
    /** The aggregate limit for one submission request, in bytes. */
    public const REQUEST_MAX_BYTES = 24 * 1024 * 1024; // 24 MB

    /** The per-file ceiling, in bytes. The existing constant is reused. */
    public const FILE_MAX_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * How many files a student may attach in one submission.
     *
     * A hard cap, because "any number under the aggregate" invites a student
     * to attach a hundred tiny files and watch the progress bar crawl. This
     * is a product decision, not a technical one, and it is named so it can
     * be changed in one place.
     */
    public const MAX_FILES = 5;

    /**
     * The human-readable label for the form.
     */
    public const LABEL = 'Up to 24 MB total across up to 5 files, each under 20 MB.';

    /**
     * Has this request's aggregate upload size exceeded the limit?
     *
     * Reads `CONTENT_LENGTH` from the server, which is what PHP actually used
     * to decide whether to accept the body. A student's reported file sizes are
     * not trusted: a crafted request can claim small files while sending large
     * ones, and the server's own figure is the one that decides.
     */
    public function requestTooLarge(Request $request): bool
    {
        $contentLength = (int) ($request->server->get('CONTENT_LENGTH') ?? 0);

        return $contentLength > 0 && $contentLength > self::REQUEST_MAX_BYTES;
    }

    /**
     * Has this individual upload exceeded the per-file ceiling?
     */
    public function fileTooLarge(UploadedFile $file): bool
    {
        return $file->getSize() > self::FILE_MAX_BYTES;
    }

    /**
     * Has this request attached too many files?
     */
    public function tooManyFiles(array $files): bool
    {
        return count($files) > self::MAX_FILES;
    }

    /**
     * The message shown when the APPLICATION gate refuses the request.
     *
     * This branch is only reached when PHP accepted the whole body, so the
     * student's written answer really is available to be saved as a draft -
     * and the message says so, because that is true here.
     */
    public function requestMessage(): string
    {
        return 'The files you tried to send total more than '
            .round(self::REQUEST_MAX_BYTES / 1048576, 0).' MB. '
            .'Reduce the number or size of the files and try again. '
            .'Your written answer was received and has been saved as a draft.';
    }

    /**
     * The message shown when the SERVER rejected the request before Laravel
     * saw it. Nothing was received, so nothing was saved - and this must not
     * promise a recovery that did not happen.
     */
    public function serverRejectedMessage(): string
    {
        return 'The files you tried to send were too large for the server to accept. '
            .'Nothing was submitted and nothing was saved - reduce the files and try again. '
            .'The text you typed is still in your browser and can be re-pasted.';
    }

    public function fileMessage(): string
    {
        return 'A single file must be under '
            .round(self::FILE_MAX_BYTES / 1048576, 0).' MB. Compress it, or link to the material instead.';
    }

    public function tooManyFilesMessage(): string
    {
        return 'You may attach up to '.self::MAX_FILES.' files in one submission.';
    }
}