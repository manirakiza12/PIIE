<?php

namespace App\Models;

use App\Support\Assignments\AssignmentLifecycle;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An Assignment.
 *
 * ONE product, two contexts. This table already existed for the legacy K12
 * flow, keyed to Subject / Class / Teacher. Course Offering assignments were
 * added to the SAME table rather than as a parallel one, so there is a single
 * submission model, a single grading path and no "which is authoritative?"
 * question for the academic office.
 *
 * The partition is one nullable column:
 *
 *   course_offering_id IS NULL -> a legacy K12 assignment
 *   course_offering_id IS SET  -> a Course Offering assignment
 *
 * `scopeK12()` makes that partition EXPLICIT at every legacy call site. The
 * alternative - a global scope - was rejected: it would hide HEI assignments
 * from the HEI code as well, forcing every new query to remember an opt-out,
 * and a global scope is invisible at the call site where a future mistake would
 * be made.
 *
 * WHY THE DOMAIN SEPARATION IS A SECURITY MATTER, NOT COSMETICS
 *
 * The legacy student list selects `is_published = 1` and
 * `(class_id IS NULL OR class_id = <the student's class>)`. A Course Offering
 * assignment has `class_id IS NULL` by design, so without the partition it
 * matches EVERY K12 student in the school - and a K12 student could then open
 * its submit modal and post a submission. Legacy K12 rows all have
 * `course_offering_id IS NULL`, so the partition changes nothing for them.
 *
 * @property int $id
 * @property int $school_id
 * @property string $title
 * @property int|null $course_offering_id
 * @property string $instructions|null
 * @property string|null $learning_objectives
 * @property int $max_marks
 * @property Carbon|null $due_date
 * @property Carbon|null $released_at
 * @property Carbon|null $closes_at
 * @property string $submission_type
 * @property string $status
 * @property string $late_policy
 * @property int|null $allowed_attempts
 */
class Assignment extends Model
{
    use HasFactory;

    // Lifecycle vocabulary, in plain words. The stored form is what the UI and
    // the domain share, so a status can never be "displayed" as one thing and
    // "enforced" as another.
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
    ];

    /** States a student may ever see. Draft and scheduled-before-release are not among them. */
    public const STUDENT_VISIBLE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
    ];

    // Submission modes. These are the EXISTING values of the legacy
    // `submission_type` enum (file, text, link, any) - no enum migration, and no
    // K12 row disturbed. 'link' is intentionally absent from the HEI set: PIIE
    // has no external submission integration, so offering it would advertise a
    // capability that does not exist.
    public const SUBMISSION_FILE = 'file';

    public const SUBMISSION_TEXT = 'text';

    public const SUBMISSION_FILE_AND_TEXT = 'any';

    public const HEI_SUBMISSION_TYPES = [
        self::SUBMISSION_FILE,
        self::SUBMISSION_TEXT,
        self::SUBMISSION_FILE_AND_TEXT,
    ];

    public const SUBMISSION_TYPE_LABELS = [
        self::SUBMISSION_FILE => 'File upload',
        self::SUBMISSION_TEXT => 'Written response',
        self::SUBMISSION_FILE_AND_TEXT => 'File and written response',
    ];

    // ── Module/chapter tasks ───────────────────────────────────────────────
    // An assignment may belong to a module, or to no module at all. The
    // relationship is OPTIONAL in both directions: most assignments are not
    // module-scoped, and most modules have no assignment.

    /**
     * Is this task required for its module to count as fully complete?
     *
     * `optional` is the default, and that default is the whole point. If it
     * defaulted to `required`, adding this column would have silently started
     * blocking every existing student's progression through their modules. An
     * optional task never gates anything, in any state, for anyone.
     */
    public const ROLE_OPTIONAL = 'optional';

    public const ROLE_REQUIRED = 'required';

    public const REQUIREMENT_ROLES = [self::ROLE_OPTIONAL, self::ROLE_REQUIRED];

    // ── How a REQUIRED task is satisfied ──────────────────────────────────
    // Vocabulary deliberately mirrors `course_offering_lessons.completion_rule`,
    // so the two systems speak the same words and a lecturer is not asked to
    // learn two vocabularies for one idea.

    /**
     * Satisfied by a real, non-draft attempt. Factual: PIIE observes it.
     */
    public const RULE_SUBMISSION = 'submission';

    /**
     * Satisfied only once the work is marked AND the mark returned to the
     * student. Strictest, and the right default for required graded work - it
     * means "submitted and assessed", not merely "submitted".
     */
    public const RULE_RELEASED_MARK = 'released_mark';

    /**
     * RULES PIIE CAN ACTUALLY OBSERVE.
     *
     * A rule is listed only if satisfaction is derivable from data PIIE already
     * stores. Offering a rule it cannot evaluate would be the same error as
     * faking one: the platform would report a task as permanently blocked on a
     * state it can never reach, and the student would be stuck with no way out.
     *
     * `teacher_verification` is the obvious next rule and is NOT implemented. The
     * lesson side already declares `view_percentage`, `quiz` and `assignment`
     * without implementing them, for the same reason.
     */
    public const IMPLEMENTED_COMPLETION_RULES = [
        self::RULE_SUBMISSION,
        self::RULE_RELEASED_MARK,
    ];

    /**
     * THE FULL DECLARED VOCABULARY, implemented or not.
     *
     * Kept so the intended shape is visible and reviewable, and so a future
     * implementation has a named slot to fill. `supportsRequirementSatisfied()`
     * is what callers should ask, never membership of this list.
     */
    public const COMPLETION_RULES = [
        self::RULE_SUBMISSION,
        self::RULE_RELEASED_MARK,
        'teacher_verification',
    ];

    /** Late policy: whether a submission arriving after the DUE date is accepted. */
    public const LATE_ALLOWED = 'allow';

    public const LATE_BLOCKED = 'block';

    public const LATE_POLICIES = [self::LATE_ALLOWED, self::LATE_BLOCKED];

    /**
     * THE SAFE DEFAULTS, ON THE MODEL.
     *
     * A column default is applied by the database but NOT by Eloquent: `create()`
     * inserts only the attributes present in the payload, so a row written through
     * the model would carry NULL rather than 'optional'. That matters, because
     * `isRequiredForModule()` compares against ROLE_REQUIRED and NULL is neither,
     * so the behaviour would be right by accident on one path and unexplained on
     * another.
     *
     * Declaring them here makes `new Assignment()`, a direct insert and
     * `AssignmentService` agree, so 'optional' is the answer for every creation
     * path and no assignment can start gating a student because a field was
     * omitted.
     */
    protected $attributes = [
        'requirement_role' => self::ROLE_OPTIONAL,
        'completion_rule' => self::RULE_SUBMISSION,
    ];

    protected $fillable = [        // legacy K12 shape - unchanged
        'school_id', 'title', 'subject_id', 'class_id', 'teacher_id',
        'instructions', 'due_date', 'max_marks', 'submission_type', 'is_published',
        // Course Offering shape
        'course_offering_id', 'learning_objectives', 'status', 'released_at',
        'closes_at', 'allowed_attempts', 'late_policy', 'created_by', 'updated_by',
        'closed_at', 'closed_by',
        // Module/chapter task fields
        'course_offering_module_id', 'requirement_role', 'completion_rule', 'submission_kinds',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'released_at' => 'datetime',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
        'max_marks' => 'integer',
        'allowed_attempts' => 'integer',
        'course_offering_id' => 'integer',
        'course_offering_module_id' => 'integer',
        'is_published' => 'boolean',
    ];

    // ── domain partition ───────────────────────────────────────────────────

    /** Legacy K12 assignments only: `course_offering_id IS NULL`. */
    public function scopeK12(Builder $query): Builder
    {
        return $query->whereNull('course_offering_id');
    }

    /** Course Offering assignments only. */
    public function scopeForOffering(Builder $query, int $offeringId): Builder
    {
        return $query->where('course_offering_id', $offeringId);
    }

    /**
     * The module this task belongs to, or null when it belongs to the Course
     * Offering as a whole.
     *
     * Reuses the EXISTING `course_offering_modules` entity. There is no
     * "module task" product: a module task IS an assignment, pointing at the
     * module it belongs to.
     */
    public function module()
    {
        return $this->belongsTo(CourseOfferingModule::class, 'course_offering_module_id');
    }

    /** The tasks attached to this module. */
    public function moduleTasks()
    {
        return $this->hasMany(Assignment::class, 'course_offering_module_id')
            ->orderBy('id');
    }

    public function isCourseOfferingScoped(): bool
    {
        return $this->course_offering_id !== null;
    }

    // ── Module task role ──────────────────────────────────────────────────

    public function isModuleTask(): bool
    {
        return $this->course_offering_module_id !== null;
    }

    /**
     * Does this task gate its module's completion?
     *
     * The single question every completion path asks. Optional tasks NEVER do,
     * whatever else is true about them - they are supplementary by definition,
     * and a student is never blocked by work a lecturer marked optional.
     */
    public function isRequiredForModule(): bool
    {
        return $this->isModuleTask()
            && $this->requirement_role === self::ROLE_REQUIRED;
    }

    public function requirementRoleLabel(): string
    {
        return $this->isRequiredForModule() ? 'Required' : 'Optional';
    }

    /**
     * Can PIIE actually evaluate this task's completion rule?
     *
     * A required task whose rule PIIE cannot observe would be permanently
     * unsatisfiable, so the authoring form refuses to offer such a rule and this
     * returns false for one that somehow exists. Same principle as the lesson's
     * `supportsStudentCompletion()`.
     */
    public function supportsRequirementSatisfied(): bool
    {
        return $this->isRequiredForModule()
            && in_array($this->completion_rule, self::IMPLEMENTED_COMPLETION_RULES, true);
    }

    public function completionRuleLabel(): ?string
    {
        return match ($this->completion_rule) {
            self::RULE_SUBMISSION => 'Once the student has submitted their work',
            self::RULE_RELEASED_MARK => 'Once the work has been marked and the result returned',
            'teacher_verification' => 'When a lecturer verifies it (not yet available)',
            default => null,
        };
    }

    // ── Evidence allowlist ────────────────────────────────────────────────

    /**
     * The evidence kinds this assignment accepts.
     *
     * A SET, because "permitted combinations" is the requirement: an assignment
     * may ask for a photograph OR a recording, not only one of them.
     *
     * NULL - the value on every pre-existing and every K12 row - falls back to the
     * legacy `submission_type`, which is what keeps those rows behaving exactly
     * as they always have. Nothing is inferred about an assignment that never
     * asked the question.
     */
    public function acceptedEvidenceKinds(): array
    {
        $stored = trim((string) $this->submission_kinds);

        if ($stored !== '') {
            $kinds = array_values(array_filter(array_map('trim', explode(',', $stored))));

            // Only kinds PIIE accepts, in the configured order, deduplicated - so
            // a hand-edited value cannot introduce an unsupported kind.
            return array_values(array_intersect(
                \App\Models\AssignmentSubmissionItem::CONFIGURABLE_KINDS,
                $kinds
            ));
        }

        // The legacy mapping, for rows written before this feature existed.
        return match ($this->submission_type) {
            self::SUBMISSION_TEXT => ['text'],
            self::SUBMISSION_FILE => ['document'],
            default => ['text', 'document'],
        };
    }

    public function acceptsEvidenceKind(?string $kind): bool
    {
        return in_array($kind, $this->acceptedEvidenceKinds(), true);
    }

    public function requiresWrittenResponse(): bool
    {
        return $this->acceptsEvidenceKind(\App\Models\AssignmentSubmissionItem::KIND_TEXT);
    }

    /** A short, plain-word description of what the student is asked to hand in. */
    public function evidenceRequirementLabel(): string
    {
        $labels = array_map(
            fn (string $kind) => \App\Models\AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind,
            $this->acceptedEvidenceKinds()
        );

        if ($labels === []) {
            return 'Nothing yet';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' or '.$last;
    }

    // ── relations ──────────────────────────────────────────────────────────

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function courseOffering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    /** Lecturer who authored it, for Course Offering assignments. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(AssignmentResource::class, 'assignment_id');
    }

    // ── questions ──────────────────────────────────────────────────────────

    /**
     * This assignment's questions, in reading order.
     *
     * Ordering is by `sequence` and then `id`, never by `id` alone: a lecturer
     * reorders questions, and an id-ordered list would silently ignore that.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(AssignmentQuestion::class, 'assignment_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * Is this a QUESTION-BASED assignment?
     *
     * DERIVED, not a column. A flag would have to be kept true, and every path
     * that creates or deletes a question would have to remember to maintain it;
     * get it wrong in either direction and students see the wrong form. This
     * cannot drift, because there is nothing to keep in step.
     *
     * The consequence, and it is the compatibility strategy in one line:
     *
     *   false  ->  GENERIC. The evidence form, `submission_kinds`, and all the
     *              existing rules apply, exactly as they do today. Every K12 row
     *              and every pre-existing HEI assignment is here.
     *   true   ->  QUESTION-BASED. The ordered question experience, evidence and
     *              marks per question, and the publication gate below.
     *
     * Assignment #3 has no questions today and therefore keeps behaving exactly
     * as it does now, until a lecturer deliberately gives it questions.
     */
    public function isQuestionBased(): bool
    {
        if ($this->relationLoaded('questions')) {
            return $this->questions->isNotEmpty();
        }

        return $this->questions()->exists();
    }

    /**
     * The sum of the questions' marks, or 0 when there are none.
     *
     * COMPUTED every time, never stored. A stored `questions_total` would be a
     * second fact that has to be updated whenever a question is added, edited,
     * reordered or removed - and the one time it is forgotten is the one time the
     * assignment's marks and its questions disagree, which is precisely the
     * contradiction the brief forbids.
     */
    public function questionMarksTotal(): float
    {
        $questions = $this->relationLoaded('questions')
            ? $this->questions
            : $this->questions()->get();

        return round((float) $questions->sum(fn (AssignmentQuestion $question) => (float) $question->marks), 2);
    }

    public function questionCount(): int
    {
        return $this->relationLoaded('questions')
            ? $this->questions->count()
            : $this->questions()->count();
    }

    /**
     * WHAT IS STILL WRONG WITH THIS ASSIGNMENT'S QUESTIONS.
     *
     * Returns an EMPTY array when the questions are publishable. A non-empty
     * array carries one human sentence per problem, so the same list can be shown
     * on the lecturer's screen as a fixable checklist and used by
     * `AssignmentService::assertPublishable()` as a refusal - one definition, so
     * the thing the lecturer is told and the thing that blocks publication cannot
     * disagree.
     *
     * The checks, in the order a lecturer fixes them:
     *
     *   - at least one question
     *   - every question worth a positive number of marks
     *   - every question configured with at least one accepted response kind
     *     (NULL is "not configured", NOT "accepts anything")
     *   - the questions total exactly the assignment's `max_marks`
     *
     * @return list<string>
     */
    public function questionReadinessProblems(): array
    {
        if (! $this->isQuestionBased()) {
            // A generic assignment has no questions to govern. The existing
            // publish rules (instructions, due date) are the whole check.
            return [];
        }

        $questions = $this->relationLoaded('questions')
            ? $this->questions
            : $this->questions()->get();

        $problems = [];

        $zeroMarks = $questions->filter(fn (AssignmentQuestion $q) => ! $q->hasPositiveMarks())->count();
        if ($zeroMarks > 0) {
            $problems[] = $zeroMarks.' question'.($zeroMarks === 1 ? '' : 's')
                .' worth no marks. Every question must be worth at least one mark.';
        }

        $unconfigured = $questions->filter(fn (AssignmentQuestion $q) => $q->acceptedResponseKinds() === [])->count();
        if ($unconfigured > 0) {
            $problems[] = $unconfigured.' question'.($unconfigured === 1 ? '' : 's')
                .' has no answer type chosen. Choose what each question accepts.';
        }

        $total = $this->questionMarksTotal();
        $maximum = (float) $this->max_marks;

        // Compared as CENTS, not as floats. Two decimal(6,2) values summed in
        // binary floating point can differ by a fraction of a cent, and a lecturer
        // whose questions add up exactly would be told they do not.
        if ($this->centsOf($total) !== $this->centsOf($maximum)) {
            $problems[] = 'The questions add up to '.$this->marksWord($total)
                .' but the assignment is worth '.$this->marksWord($maximum)
                .'. They must be the same before you can publish.';
        }

        return $problems;
    }

    public function questionsArePublishable(): bool
    {
        return $this->questionReadinessProblems() === [];
    }

    /**
     * Make `max_marks` equal the sum of the questions.
     *
     * The alternative is asking a lecturer to add up the questions they just
     * wrote and type the same number into another box, which is how a mark
     * integrity rule gets defeated in practice. The lecturer still chooses to
     * press it; PIIE just does not make them do arithmetic that can contradict
     * their own questions.
     *
     * @return float the total that was adopted
     */
    public function adoptQuestionMarksTotal(): float
    {
        $total = $this->questionMarksTotal();

        $this->max_marks = (int) round($total);

        return $total;
    }

    private function centsOf(float $value): int
    {
        return (int) round($value * 100);
    }

    private function marksWord(float $value): string
    {
        $rounded = (int) round($value);

        return $rounded.' mark'.($rounded === 1 ? '' : 's');
    }


    /**
     * The instant LATENESS is measured against.
     *
     * `closes_at` when set, otherwise the due date. This is a statement about
     * when the work was meant in, so it is the right comparison for a recorded
     * submission instant.
     *
     * Do NOT use this to decide whether a new submission may be made - that is
     * `hardCloseAt()`, and the difference is the entire late policy.
     */
    public function effectiveDeadline(): ?Carbon
    {
        return $this->closes_at ?? $this->due_date;
    }

    /**
     * The instant after which NO NEW submission is accepted, or null when there
     * is no hard stop.
     *
     * DELIBERATELY NOT A FALLBACK TO THE DUE DATE.
     *
     * There are two different deadlines and they answer different questions:
     *
     *   due_date   - when the work is DUE. Passing it does not close anything;
     *                what happens next is the late policy's decision.
     *   closes_at  - when the assignment STOPS accepting work, whatever the late
     *                policy says.
     *
     * Collapsing them makes the late policy unreachable: an assignment past its
     * due date with `allow_late` would be treated as closed and a late
     * submission would be impossible, which is precisely the case the policy
     * exists to permit. `closes_at` is null on most assignments, and for those
     * this returns null - the assignment has no hard stop at all.
     */
    public function hardCloseAt(): ?Carbon
    {
        return $this->closes_at;
    }

    public function displayStatusLabel(): string
    {
        return AssignmentLifecycle::labelFor($this);
    }

    /** Attempts a student is permitted. NULL or 1 means exactly one. */
    public function attemptsAllowed(): int
    {
        $attempts = (int) ($this->allowed_attempts ?? 1);

        return $attempts > 0 ? $attempts : 1;
    }

    public function allowsLateSubmissions(): bool
    {
        return $this->late_policy !== self::LATE_BLOCKED;
    }

    /** Total assignments ever made against this one, whatever their state. */
    public function submissionCount(): int
    {
        return $this->submissions()->where('is_draft', false)->count();
    }

    public function gradedCount(): int
    {
        return $this->submissions()
            ->where('is_draft', false)
            ->whereNotNull('marks_awarded')
            ->count();
    }

    // ══════════════════════════════════════════════════════════════════════
    // RENDERING THE INSTRUCTIONS
    //
    // The same defect as `AssignmentQuestion::prosePrompt()`, on the assignment
    // rather than the question, and found by the same sweep: three templates
    // rendered these instructions with `{!! !!}` and NO filter -
    //
    //     teacher/.../assignments/submission.blade.php   (lecturer marking)
    //     teacher/.../assignments/preview.blade.php      (lecturer preview)
    //     student/course_assignments/show.blade.php      (STUDENT VIEW)
    //
    // The third of those is the one that matters most: an assignment's
    // instructions are written by a lecturer and read by every confirmed student,
    // so an unfiltered `{!! !!}` is a stored-XSS path from one lecturer account to
    // every student on the course. The write half is already safe -
    // `AssignmentService` sanitises on the way in with this same `HtmlSanitizer` -
    // so what was missing was the read, exactly as with a question.
    //
    // No mutator here either, for the same reason: the service owns the write.
    // ══════════════════════════════════════════════════════════════════════

    /** The instructions as sanitised HTML, for rendering. */
    public function proseInstructions(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['instructions'] ?? '');
    }

    /** The instructions as plain text - for a summary line or a notification. */
    public function plainInstructions(int $limit = 200): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['instructions'] ?? '', $limit);
    }
}
