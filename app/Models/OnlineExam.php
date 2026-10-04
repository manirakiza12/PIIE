<?php

namespace App\Models;

use Carbon\Carbon;
use App\Support\OnlineExams\AnswerKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Programme;
use App\Models\Session;
use App\Support\CourseContent\HtmlSanitizer;

class OnlineExam extends Model
{
    use HasFactory;

    protected $table = 'online_exams';

    /**
     * The assessment types the engine's requests actually permit.
     *
     * Mirrored from `Rule::in([...])` in StoreOnlineExamRequest and
     * UpdateOnlineExamRequest, and asserted against those rules by
     * CourseOfferingExamTest - a label map that offered a type the request would
     * reject would show a lecturer a choice that cannot be saved.
     */
    public const TYPES = [
        'cat' => 'CAT',
        'midterm' => 'Midterm',
        'final' => 'Final',
        'quiz' => 'Quiz',
        'assignment' => 'Assignment',
    ];

    /**
     * How each `lifecycle_status` reads to a lecturer, in their words.
     *
     * `lifecycle_status` itself is the engine's own derivation and is untouched -
     * it is what the scopes and the result views already branch on. This is only
     * the sentence shown beside it, so the Course Offering list and the two
     * existing screens cannot call the same state two different things.
     */
    public const LIFECYCLE_LABELS = [
        'draft' => 'Draft',
        'pending_review' => 'Awaiting review',
        'published' => 'Scheduled',
        'active' => 'Open now',
        'ended' => 'Closed',
        'cancelled' => 'Cancelled',
    ];

    public function typeLabel(): string
    {
        return self::TYPES[$this->exam_type] ?? (string) $this->exam_type;
    }

    public function lifecycleLabel(): string
    {
        return self::LIFECYCLE_LABELS[$this->lifecycle_status] ?? (string) $this->lifecycle_status;
    }

    /**
     * May the question set still be changed?
     *
     * Delegated to the engine's own `isStructurallyLocked()` rather than restated,
     * so the Course Offering question page cannot offer to add a fourth question to
     * an exam thirty students have already sat.
     */
    public function questionsAreLocked(): bool
    {
        return $this->isStructurallyLocked();
    }

    protected $fillable = [
        'school_id', 'title', 'subject_id', 'class_id', 'exam_type',
        'programme_id', 'session_id', 'course_offering_id',
        'start_datetime', 'end_datetime', 'duration_mins', 'total_marks',
        'pass_mark', 'instructions', 'is_published', 'auto_submit', 'created_by',
        'workflow_state', 'max_attempts', 'shuffle_questions', 'shuffle_options',
        'allow_previous_navigation', 'result_release_policy', 'webcam_required',
        'fullscreen_required', 'integrity_accommodation', 'creator_id', 'updater_id', 'reviewed_by',
        'reviewed_at', 'cancelled_at', 'cancellation_reason', 'locked_at',
    ];

    protected $appends = ['lifecycle_status', 'duration_minutes'];

    /**
     * Instructions are AUTHORED PROSE, so they are filtered on the way IN.
     *
     * This is the one place every write path passes through - the admin modal,
     * the teacher form and any future caller - so a sanitizer in a single
     * controller would be a sanitizer the others forget.
     *
     * NULL is preserved rather than collapsed to an empty string, so an exam with
     * no instructions is still stored as having none.
     */
    public function setInstructionsAttribute($value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['instructions'] = null;

            return;
        }

        $this->attributes['instructions'] = app(HtmlSanitizer::class)->sanitize((string) $value);
    }

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime'   => 'datetime',
        'reviewed_at'    => 'datetime',
        'cancelled_at'   => 'datetime',
        'locked_at'      => 'datetime',
        'is_published'   => 'boolean',
        'auto_submit'    => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'allow_previous_navigation' => 'boolean',
        'webcam_required' => 'boolean',
        'fullscreen_required' => 'boolean',
        'max_attempts'   => 'integer',
        'total_marks'    => 'integer',
        'pass_mark'      => 'integer',
        'duration_mins'  => 'integer',
    ];

    public function questions()
    {
        return $this->hasMany(OnlineExamQuestion::class, 'online_exam_id');
    }

    public function submissions()
    {
        return $this->hasMany(OnlineExamSubmission::class, 'online_exam_id');
    }

    public function notifications()
    {
        return $this->hasMany(OnlineExamNotification::class, 'online_exam_id');
    }

    /**
     * The Course Offering this assessment is delivered through, if any.
     *
     * NULL is the ordinary case for a legacy Class/Section assessment and means
     * exactly what it always meant: not a Course Offering assessment. The
     * relation is never used to widen reachability - see
     * `CourseOfferingExamAccess`, which is the only thing allowed to decide who
     * may sit it.
     */
    public function courseOffering()
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /**
     * Assessments belonging to one Course Offering, in this tenant.
     *
     * The tenant is constrained even though every exam is already school-scoped:
     * a scope that forgets it would be a cross-institution read waiting for a
     * caller that supplies only an offering id.
     */
    public function scopeForOffering($query, $offering, ?int $schoolId = null)
    {
        $id = $offering instanceof CourseOffering ? $offering->id : $offering;

        return $query
            ->where('online_exams.course_offering_id', $id)
            ->when($schoolId, fn ($q) => $q->where('online_exams.school_id', $schoolId));
    }

    /**
     * Sanitised instructions, for a `{!! !!}` render.
     *
     * Deliberately a METHOD and not an accessor. Overriding the attribute would
     * make every plain `{{ $exam->instructions }}` in the thirty-odd existing
     * views emit raw HTML - including the admin tables and exports that have
     * always escaped. A named method means the safe default everywhere else is
     * unchanged, and the raw path is a deliberate, greppable choice.
     */
    public function proseInstructions(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['instructions'] ?? '');
    }

    /**
     * Instructions as plain text, for a summary card or a truncated cell.
     *
     * `Str::limit()` on the raw value would cut markup at an arbitrary offset and
     * leave a broken tag on the page, so a truncated cell has to ask for text
     * rather than for the value. Word boundaries are preserved by the sanitizer's
     * `toText()`, so a cut never joins two words together.
     */
    public function plainInstructions(int $limit = 100): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['instructions'] ?? '', $limit);
    }

    /**
     * The exam window as one sentence, so the Course Home and the exam's own page
     * cannot describe the same window in two different words.
     */
    public function windowSummary(): string
    {
        $start = $this->start_datetime?->format('j M Y, H:i');
        $end = $this->end_datetime?->format('j M Y, H:i');

        if (! $start || ! $end) {
            return 'No scheduled window yet.';
        }

        return $start.' to '.$end;
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function classRoom()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updater_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where(function ($q) use ($teacherId) {
            $q->where('creator_id', $teacherId)->orWhere('created_by', $teacherId);
        });
    }

    public function scopePublished($query)
    {
        return $query->where('workflow_state', 'published');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function academicSession()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    /**
     * Exam schedules are entered as local institutional times. Prefer the
     * existing system timezone setting (for example Africa/Nairobi) when
     * interpreting those database values; fall back to the application
     * timezone for isolated tests and installations without that setting.
     */
    public function scheduleTimezone(): string
    {
        $timezone = function_exists('get_settings') ? get_settings('timezone') : null;
        $timezone = $timezone ?: config('app.timezone', 'UTC');

        return in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : config('app.timezone', 'UTC');
    }

    public function scheduledStartAt(): ?Carbon
    {
        $value = $this->getRawOriginal('start_datetime');

        return $value ? Carbon::parse($value, $this->scheduleTimezone()) : null;
    }

    public function scheduledEndAt(): ?Carbon
    {
        $value = $this->getRawOriginal('end_datetime');

        return $value ? Carbon::parse($value, $this->scheduleTimezone()) : null;
    }

    public function isWithinScheduledWindow(?Carbon $at = null): bool
    {
        $timezone = $this->scheduleTimezone();
        $at = ($at ?: Carbon::now($timezone))->copy()->setTimezone($timezone);
        $start = $this->scheduledStartAt();
        $end = $this->scheduledEndAt();

        return (!$start || $at->gte($start)) && (!$end || $at->lt($end));
    }

    public function scopeActive($query, ?Carbon $at = null)
    {
        $at = $at ?: now();

        return $query->where('workflow_state', 'published')
            ->where(function ($q) use ($at) {
                $q->whereNull('start_datetime')->orWhere('start_datetime', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('end_datetime')->orWhere('end_datetime', '>=', $at);
            });
    }

    public function scopeEnded($query, ?Carbon $at = null)
    {
        $at = $at ?: now();

        return $query->where('workflow_state', 'published')
            ->whereNotNull('end_datetime')
            ->where('end_datetime', '<', $at);
    }

    public function scopeUpcoming($query, ?Carbon $at = null)
    {
        $at = $at ?: now();

        return $query->where('workflow_state', 'published')
            ->whereNotNull('start_datetime')
            ->where('start_datetime', '>', $at);
    }

    public function scopeVisibleToStudent($query, int $schoolId, ?int $classId, ?int $programmeId = null, array $sessionIds = [])
    {
        return $query->forSchool($schoolId)
            ->published()
            ->where(function ($q) use ($classId) {
                $q->whereNull('class_id');
                if ($classId) {
                    $q->orWhere('class_id', $classId);
                }
            })
            ->where(function ($q) use ($programmeId) {
                $q->whereNull('programme_id');
                if ($programmeId) $q->orWhere('programme_id', $programmeId);
            })
            ->where(function ($q) use ($sessionIds) {
                $q->whereNull('session_id');
                if ($sessionIds) $q->orWhereIn('session_id', $sessionIds);
            });
    }

    public function getLifecycleStatusAttribute(): string
    {
        if ($this->workflow_state === 'cancelled') {
            return 'cancelled';
        }

        if ($this->workflow_state !== 'published') {
            return $this->workflow_state ?: 'draft';
        }

        $now = Carbon::now($this->scheduleTimezone());
        $start = $this->scheduledStartAt();
        $end = $this->scheduledEndAt();
        if ($start && $now->lt($start)) {
            return 'published';
        }

        if ($end && $now->gte($end)) {
            return 'ended';
        }

        return 'active';
    }

    public function getDurationMinutesAttribute(): int
    {
        return (int) ($this->duration_mins ?? 0);
    }

    public function setDurationMinutesAttribute($value): void
    {
        $this->attributes['duration_mins'] = $value;
    }

    public function setIsPublishedAttribute($value): void
    {
        $this->attributes['is_published'] = (int) (bool) $value;
    }

    /**
     * THE INTEGRITY CONTROLS FOR THIS EXAMINATION, RESOLVED.
     *
     * ── WHY ONE METHOD ───────────────────────────────────────────────────────
     *
     * Three places need this answer — the attempt view, the restricted-mode script and
     * the invigilation record — and a control whose policy lives in the page and in the
     * script separately is a control whose page and script will disagree. So the
     * defaults come from `config/online_exam_integrity.php`, the APPROVED ACCOMMODATION
     * comes from this exam's own row, and the merge happens here, once.
     *
     * An unknown or absent accommodation falls back to the DEFAULTS rather than to
     * "off". Failing toward more protection is the safe direction for a value that
     * exists only to relax something; failing toward "off" would let a typo silently
     * unmonitor a paper.
     *
     * Deliberately flat and boolean: every consumer needs to answer "may I do this?".
     *
     * @return array{enabled:bool, accommodation:?string, block_clipboard:bool, block_context_menu:bool, block_navigation_shortcuts:bool, warn_on_focus_loss:bool, warning_threshold:int, persistent_banner_after:int, focus_loss_grace_ms:int}
     */
    public function integritySettings(): array
    {
        $defaults = [
            'enabled' => (bool) config('online_exam_integrity.enabled', true),
            'accommodation' => null,
            'block_clipboard' => (bool) config('online_exam_integrity.restricted_interaction.block_clipboard', true),
            'block_context_menu' => (bool) config('online_exam_integrity.restricted_interaction.block_context_menu', true),
            'block_navigation_shortcuts' => (bool) config('online_exam_integrity.restricted_interaction.block_navigation_shortcuts', true),
            'warn_on_focus_loss' => true,
            'warning_threshold' => (int) config('online_exam_integrity.warning_threshold', 1),
            'persistent_banner_after' => (int) config('online_exam_integrity.persistent_banner_after', 3),
            'focus_loss_grace_ms' => (int) config('online_exam_integrity.focus_loss_grace_ms', 1500),
        ];

        if (! $defaults['enabled']) {
            // The master switch is off for the deployment. Every restriction goes with
            // it, and `accommodation` stays null so the record reads "no controls" rather
            // than naming an adjustment that is not what happened.
            return array_merge($defaults, [
                'enabled' => false,
                'block_clipboard' => false,
                'block_context_menu' => false,
                'block_navigation_shortcuts' => false,
                'warn_on_focus_loss' => false,
            ]);
        }

        $accommodation = $this->integrity_accommodation;
        $known = (array) config('online_exam_integrity.accommodations', []);

        if (! is_string($accommodation) || $accommodation === '' || ! isset($known[$accommodation])) {
            return $defaults;
        }

        // ONLY the four control flags come from the accommodation. The thresholds are
        // institutional and are not negotiable by an exam-level setting: an
        // accommodation that could also silence the thresholds would be a way to switch
        // the monitoring off rather than to adjust it.
        $controls = array_intersect_key($known[$accommodation], array_flip([
            'block_clipboard', 'block_context_menu', 'block_navigation_shortcuts', 'warn_on_focus_loss',
        ]));

        return array_merge($defaults, $controls, ['accommodation' => $accommodation]);
    }

    public function isStructurallyLocked(): bool
    {
        if (!empty($this->locked_at)) {
            return true;
        }

        return $this->submissions()->whereIn('status', [
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            OnlineExamSubmission::STATUS_SUBMITTED,
            OnlineExamSubmission::STATUS_TIMED_OUT,
            OnlineExamSubmission::STATUS_PENDING_MANUAL,
            OnlineExamSubmission::STATUS_FINALIZED,
        ])->exists();
    }

    public function publicationReadinessErrors(): array
    {
        $errors = [];

        if (empty($this->title)) {
            $errors[] = 'Title is required.';
        }

        if ((int) $this->duration_mins <= 0) {
            $errors[] = 'Duration must be greater than zero.';
        }

        if ((int) $this->total_marks <= 0) {
            $errors[] = 'Total marks must be greater than zero.';
        }

        if ((int) $this->pass_mark > (int) $this->total_marks) {
            $errors[] = 'Pass mark cannot exceed total marks.';
        }

        if ($this->start_datetime && $this->end_datetime && $this->end_datetime->lte($this->start_datetime)) {
            $errors[] = 'End time must be later than start time.';
        }

        $questions = $this->questions;
        if ($questions->count() < 1) {
            $errors[] = 'At least one question is required.';
        } else {
            $questionMarks = (int) $questions->sum('marks');
            if ($questionMarks !== (int) $this->total_marks) {
                $errors[] = "Total question marks ({$questionMarks}) must equal exam total marks ({$this->total_marks}).";
            }
            foreach ($questions as $question) {
                $type = $question->normalized_type;
                $key = AnswerKey::forQuestion($question);
                if ($type === 'true_false' && $key === null) {
                    $errors[] = "Question {$question->id} has an invalid true/false answer key.";
                }
                if ($type === 'multiple_choice' && $key === null) {
                    $errors[] = "Question {$question->id} has an invalid multiple-choice answer key.";
                }
            }
        }

        $validPolicies = ['immediate', 'after_exam_end', 'manual'];
        if (!in_array($this->result_release_policy ?: 'immediate', $validPolicies, true)) {
            $errors[] = 'Invalid result release policy.';
        }

        return $errors;
    }

    public function isResultVisibleFor(OnlineExamSubmission $submission): bool
    {
        $reviewState = $submission->result_review_state;
        // Rows created before governance metadata existed are readable using
        // their persisted technical publication state; migrated rows always
        // have an explicit result_review_state.
        if ($reviewState === null && $submission->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED) {
            $reviewState = 'published';
        }
        if (!$submission->isAttemptCompleted() || $submission->status !== OnlineExamSubmission::STATUS_RESULT_PUBLISHED || $reviewState !== 'published') {
            return false;
        }

        $policy = $this->result_release_policy ?: 'immediate';
        if ($policy === 'after_exam_end') {
            $end = $this->scheduledEndAt();
            if (!$end) {
                return false;
            }

            return Carbon::now($this->scheduleTimezone())->gte($end);
        }

        // All release policies require the persisted Admin publication state.
        // "immediate" controls when Admin may publish; it never auto-publishes.
        return true;
    }
}
