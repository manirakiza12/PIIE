<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of evidence a student attached to an attempt.
 *
 * WHY A SET OF ITEMS RATHER THAN ONE FILE COLUMN
 *
 * A real piece of work is not one file. A student explaining a ratio might write
 * a paragraph, photograph their working, record a voice note saying why they
 * chose that method, and link to a published spreadsheet. A single `file_path`
 * can hold one of those and would overwrite the rest, destroying evidence the
 * student has actually produced - and destroying it silently, because the previous
 * upload would simply be gone.
 *
 * So an attempt owns a LIST of items, each tagged with what KIND of thing it is.
 * That also makes the shape extendable without a schema change: the natural next
 * kind is in-browser recording, which is the same thing as an uploaded audio file
 * that happened to be captured in the browser rather than chosen from disk.
 *
 * WHAT IS AND IS NOT IMPLEMENTED
 *
 * Uploaded local files and links are implemented. There is NO in-browser
 * recording, and nothing here pretends otherwise: there is no record control, and
 * the submission form only ever offers a file picker. The `audio` and `video`
 * kinds mean "a file the student attached", not "the platform recorded this".
 *
 * NOTHING FAKES A CAPABILITY
 *
 * `kind` is a varchar rather than a closed enum so that a future kind is an
 * additive code change rather than a MySQL table rebuild. The ALLOWED set is
 * enforced here and by validation, so an unsupported kind still cannot be
 * written, and `isSupportedKind()` is the single place that list lives.
 *
 * FILES LIVE OUTSIDE THE WEB ROOT
 *
 * `stored_path` is a generated name under storage/app, never the client's
 * filename and never under public/. A student's photograph of their work, or a
 * voice recording, is personal evidence: it is reachable only through a route
 * that re-checks the confirmed registration (or, for a lecturer, the allocation
 * on that exact Offering). Serving these from a guessable public path would
 * publish the student's work to anyone who guessed it.
 */
class AssignmentSubmissionItem extends Model
{
    use HasFactory;

    protected $table = 'assignment_submission_items';

    // ── the evidence vocabulary ──────────────────────────────────────────
    // A written response. Stored on the SUBMISSION (text_response), not here,
    // so "I wrote something" is one fact with one home rather than two.
    public const KIND_TEXT = 'text';

    // A document: PDF, Word, spreadsheet, presentation.
    public const KIND_DOCUMENT = 'document';

    // A still image: a photograph of working, a diagram, a screenshot.
    public const KIND_IMAGE = 'image';

    // An audio file the student attached. NOT browser recording - see the class
    // docblock. Implemented as an ordinary upload, so the storage, privacy and
    // authorisation paths are already correct when a recorder arrives.
    public const KIND_AUDIO = 'audio';

    // A video file the student attached, for the same reason as audio.
    public const KIND_VIDEO = 'video';

    // A link to work published somewhere else.
    public const KIND_LINK = 'link';

    /**
     * Kinds that carry a FILE, and the extensions each accepts.
     *
     * The per-kind allowlist is the real guard. A single combined list would let
     * a student submit an .exe renamed to .pdf as "an image", and would mean the
     * `image` slot could hold an arbitrary document - which matters when a
     * lecturer is told the student supplied photographic evidence.
     *
     * Extension allowlists are explicit rather than "anything not blocked", so an
     * unexpected type is refused by default instead of accepted by omission.
     */
    public const KIND_EXTENSIONS = [
        self::KIND_DOCUMENT => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'csv', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp'],
        self::KIND_IMAGE => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
        self::KIND_AUDIO => ['mp3', 'm4a', 'wav', 'ogg', 'oga', 'aac', 'flac', 'webm', 'amr', '3gp'],
        self::KIND_VIDEO => ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', '3gp', 'ogv'],
    ];

    /**
     * The ALLOWED kinds for evidence PIIE can actually accept today.
     *
     * This is the single definition. Validation, the authoring form and the
     * student submission form all read it, so a kind cannot be offered somewhere
     * it is not accepted, or accepted somewhere it was never offered.
     */
    public const SUPPORTED_KINDS = [
        self::KIND_DOCUMENT,
        self::KIND_IMAGE,
        self::KIND_AUDIO,
        self::KIND_VIDEO,
        self::KIND_LINK,
    ];

    /**
     * Kinds a lecturer may configure an assignment to accept.
     *
     * Includes text, which lives on the submission rather than here but is still
     * something a student is asked for. The legacy `submission_type` vocabulary -
     * file, text, any - is NOT reused: a single enum value cannot express "a
     * photograph OR a recording", which is the whole point of this feature.
     */
    public const CONFIGURABLE_KINDS = [
        self::KIND_TEXT,
        self::KIND_DOCUMENT,
        self::KIND_IMAGE,
        self::KIND_AUDIO,
        self::KIND_VIDEO,
        self::KIND_LINK,
    ];

    public const KIND_LABELS = [
        self::KIND_TEXT => 'Written response',
        self::KIND_DOCUMENT => 'Document',
        self::KIND_IMAGE => 'Image',
        self::KIND_AUDIO => 'Audio file',
        self::KIND_VIDEO => 'Video file',
        self::KIND_LINK => 'Web link',
    ];

    /** A URL is only ever offered as a link when it is a real http(s) address. */
    public const LINK_SCHEMES = ['http', 'https'];

    protected $fillable = [
        'school_id', 'assignment_submission_id', 'assignment_id', 'course_offering_id',
        'kind', 'label', 'stored_path', 'original_name', 'mime_type', 'size_bytes',
        'url', 'note', 'created_by',
        // Which question this answers, and where the bytes came from.
        'assignment_question_id', 'capture_method',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'assignment_submission_id' => 'integer',
        'assignment_id' => 'integer',
        'course_offering_id' => 'integer',
        'size_bytes' => 'integer',
        'assignment_question_id' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    /**
     * The question this evidence answers, or null for assignment-level evidence.
     *
     * NULL is not a gap: it is what every K12 row and every pre-existing HEI row
     * carries, and it is what a GENERIC assignment's evidence is. Only a
     * question-based assignment scopes its evidence to a question.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(AssignmentQuestion::class, 'assignment_question_id');
    }

    // ── where the bytes came from ──────────────────────────────────────────

    /** The student chose a file from their device. */
    public const CAPTURE_UPLOAD = 'upload';

    /** The student recorded it in this page with their microphone or camera. */
    public const CAPTURE_BROWSER = 'browser_recording';

    public const CAPTURE_METHODS = [self::CAPTURE_UPLOAD, self::CAPTURE_BROWSER];

    /**
     * Was this captured in the browser rather than chosen from disk?
     *
     * PROVENANCE, NOT PROOF.
     *
     * The server validates the file it actually received against the question's
     * own kind, extension allowlist and size limit, and creates the item only
     * from that file. A client that claims a recording while sending nothing
     * therefore produces NO item - so the marker sees a question with no answer,
     * which is the truth, rather than a row claiming a recording that does not
     * exist.
     */
    public function wasRecordedInBrowser(): bool
    {
        return $this->capture_method === self::CAPTURE_BROWSER;
    }

    public function captureLabel(): string
    {
        return $this->wasRecordedInBrowser() ? 'Recorded in the browser' : 'Uploaded';
    }

    // ── kind helpers ─────────────────────────────────────────────────────

    /** Is this a kind PIIE accepts today? */
    public static function isSupportedKind(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::SUPPORTED_KINDS, true);
    }

    /** Is this a kind a lecturer may configure? */
    public static function isConfigurableKind(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::CONFIGURABLE_KINDS, true);
    }

    /** Kinds that arrive as a file rather than a URL. */
    public static function fileKinds(): array
    {
        return array_keys(self::KIND_EXTENSIONS);
    }

    public static function isFileKind(?string $kind): bool
    {
        return $kind !== null && array_key_exists($kind, self::KIND_EXTENSIONS);
    }

    public static function isLinkKind(?string $kind): bool
    {
        return $kind === self::KIND_LINK;
    }

    /** The extensions a given kind accepts, or [] when it is not a file kind. */
    public static function extensionsFor(?string $kind): array
    {
        return self::KIND_EXTENSIONS[$kind] ?? [];
    }

    /**
     * PIIE's own executable/script blocklist, re-read at call time so it cannot
     * drift from SafeUpload. A file whose extension is not in the kind's
     * allowlist is refused anyway; this is the second, content-based check.
     */
    public static function blockedExtensions(): array
    {
        return \App\Support\SafeUpload::BLOCKED;
    }

    public function hasFile(): bool
    {
        return filled($this->stored_path);
    }

    public function hasUsableLink(): bool
    {
        $url = trim((string) $this->url);

        if ($url === '') {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), self::LINK_SCHEMES, true);
    }

    public function displayName(): string
    {
        if ($this->isFileKind($this->kind)) {
            return $this->original_name ?: ($this->label ?: $this->kindLabel());
        }

        return $this->label ?: ($this->url ?: $this->kindLabel());
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? 'Evidence';
    }

    public function sizeLabel(): string
    {
        $bytes = (int) ($this->size_bytes ?? 0);

        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    /**
     * Does this item really carry the thing it claims to be?
     *
     * Used when deciding whether an attempt satisfies a REQUIRED task, so a
     * zero-byte file or an empty link cannot stand in for evidence. The check is
     * about the stored row, not the upload that produced it, because that is what
     * the lecturer will actually see.
     */
    public function isSubstantive(): bool
    {
        if ($this->isFileKind($this->kind)) {
            return $this->hasFile() && (int) ($this->size_bytes ?? 0) > 0;
        }

        if ($this->isLinkKind($this->kind)) {
            return $this->hasUsableLink();
        }

        return false;
    }

    /** Evidence items for one attempt, in a stable order. */
    public function scopeForSubmission(Builder $query, int $submissionId): Builder
    {
        return $query->where('assignment_submission_id', $submissionId)->orderBy('id');
    }
}
