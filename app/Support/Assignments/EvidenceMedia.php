<?php

namespace App\Support\Assignments;

use App\Models\AssignmentSubmissionItem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rendering a student's evidence INSIDE a page, rather than downloading it.
 *
 * WHY THIS IS A SEPARATE ROUTE AND NOT A FLAG ON THE DOWNLOAD ROUTE
 *
 * A marker needs a photograph of working to appear in the page so they can judge
 * it, and a spreadsheet to download so they can open it. Those are two different
 * acts, and only one of them hands the browser bytes it will interpret.
 *
 * A `?inline=1` flag on the download route would put the decision about whether a
 * file is safe to render inside a page in the hands of whatever built the URL - and
 * a single templating slip would then serve an arbitrary upload as
 * `text/html` inside the portal. Two named routes make the distinction structural:
 * `evidence` always downloads, `media` streams, and this class is the only thing
 * that decides what may be streamed.
 *
 * THE ALLOWLIST IS BY KIND *AND* BY MIME, AND IT IS STRICT
 *
 * Nothing is streamed because its extension looked right. The browser will render
 * whatever Content-Type it is given, so the decision has to be made about the
 * CONTENT. `X-Content-Type-Options: nosniff` is set as well, so that even a
 * mislabelled file is not re-interpreted by sniffing.
 *
 * A document - PDF, spreadsheet, Word file - is NOT streamable. A PDF rendered in
 * a page is a document execution surface, and a marker who wants to read one
 * downloads it, which is the safer default and costs nothing.
 *
 * AUTHORISATION IS NOT THIS CLASS'S JOB
 *
 * This class only answers "may these bytes be rendered, and how". Whether the
 * viewer may see them at all is decided before it is called, by
 * `GradingService::resolveSubmissionForStudent` or
 * `resolveSubmissionForManager` - which is what proves a confirmed registration, or
 * a current allocation, on the exact Offering. The bytes live outside the web root
 * under a generated name, so this route is the only way to reach them at all.
 */
class EvidenceMedia
{
    /**
     * Mimes that may be rendered inside a page, by evidence kind.
     *
     * Written out rather than derived from the extension allowlist on purpose. The
     * extension list answers "may a student upload this"; this list answers the
     * completely different question "may a browser be allowed to interpret this",
     * and deriving one from the other would quietly widen it every time a new
     * upload type was added.
     *
     * Notably ABSENT: anything text-ish. `text/html` in particular is never
     * streamable, so a student-supplied file can never be rendered as a page in
     * this portal, whatever its extension claims.
     */
    public const INLINE_MIMES = [
        AssignmentSubmissionItem::KIND_IMAGE => [
            'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        ],
        AssignmentSubmissionItem::KIND_AUDIO => [
            'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/wav',
            'audio/x-wav', 'audio/wave', 'audio/ogg', 'audio/webm', 'audio/aac',
            'audio/flac', 'audio/amr', 'audio/3gpp',
        ],
        AssignmentSubmissionItem::KIND_VIDEO => [
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo',
            'video/x-matroska', 'video/3gpp', 'video/ogg',
        ],
    ];

    /**
     * May these bytes be rendered inside a page?
     *
     * Three separate questions, and all three must pass:
     *
     *   1. is there a file at all
     *   2. is the STORED mime one this product will render
     *   3. is it a kind whose allowlist contains that mime
     *
     * Question 3 is what stops an upload whose stored mime happens to be
     * `image/png` from being rendered in a slot the lecturer told them to put a
     * spreadsheet in - the same principle that separates the per-kind extension
     * allowlists on upload.
     */
    public function mayStreamInline(?AssignmentSubmissionItem $item): bool
    {
        if (! $item instanceof AssignmentSubmissionItem || ! $item->hasFile()) {
            return false;
        }

        $allowed = self::INLINE_MIMES[$item->kind] ?? null;

        if ($allowed === null) {
            return false;
        }

        $mime = strtolower(trim((string) $item->mime_type));

        return $mime !== '' && in_array($mime, $allowed, true);
    }

    /**
     * Stream this item's bytes for display inside a page.
     *
     * Throws a 404 for anything `mayStreamInline()` refuses, so the refusal is
     * indistinguishable from the item not existing - a caller cannot use this
     * route to learn which stored files have which mime types.
     */
    public function inlineResponse(AssignmentSubmissionItem $item): Response
    {
        if (! $this->mayStreamInline($item)) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $item->stored_path || ! $disk->exists($item->stored_path)) {
            abort(404);
        }

        $path = $disk->path($item->stored_path);

        $response = new BinaryFileResponse($path);

        // SET THROUGH THE HEADER, not `setContentType()`.
        //
        // There is no `setContentType()` on this Symfony version's
        // `BinaryFileResponse`. Calling it is a fatal error, and a fatal error in a
        // route is a 500 to a lecturer trying to look at a photograph - the least
        // helpful possible moment for one. The header is the same instruction, and
        // it is the only thing that matters: the browser is told what these bytes
        // are, and told not to guess otherwise.
        $response->headers->set('Content-Type', strtolower((string) $item->mime_type));

        // `inline` and a FILENAME, so a student who recorded a 40 MB video gets a
        // sensible download name if they save it, without the response becoming an
        // attachment they have to hunt for.
        // `inline`, as a LITERAL. There is no `Response::DISPOSITION_INLINE`
        // constant on this Symfony version either - the same family of missing
        // members that produced the `setContentType()` fatal above. The value is
        // fixed by the HTTP specification, so a literal carries no risk here.
        $response->setContentDisposition('inline', $item->original_name ?: $item->kindLabel());

        // The browser is told to trust the Content-Type we chose and not to sniff
        // the bytes for something else. With the allowlist above, this is the second
        // of two independent controls rather than the only one.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /**
     * The inline media URL for one item, or null when it cannot be rendered.
     *
     * Returned rather than assumed, so a template cannot emit a link to a route
     * that will 404 for a document - which would look to a lecturer like broken
     * evidence rather than like a deliberate design.
     */
    public function inlineUrlFor(
        AssignmentSubmissionItem $item,
        callable $routeFor
    ): ?string {
        return $this->mayStreamInline($item) ? $routeFor($item) : null;
    }
}
