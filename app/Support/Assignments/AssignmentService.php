<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentResource;
use App\Models\AssignmentSubmissionItem;
use App\Models\CourseOffering;
use App\Models\CourseOfferingModule;
use App\Models\User;
use App\Support\CourseContent\HtmlSanitizer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything a lecturer or administrator does to an assignment.
 *
 * AUTHORING RULES ENFORCED HERE, NOT IN THE CONTROLLER
 *
 *  - a published or scheduled assignment must have a due date, because a task
 *    with no deadline is not a task a student can plan around
 *  - a published or scheduled assignment must have instructions, so a student
 *    is never shown a task with nothing to do
 *  - a scheduled assignment must have a release time (see AssignmentLifecycle)
 *  - an assignment that already has student submissions cannot be dragged back
 *    to Draft, and cannot be hard deleted
 *
 * FILES GO OUTSIDE THE WEB ROOT
 *
 * Handouts and marking material are written under storage/app with a GENERATED
 * name and served only through an authorising route. A path under public/ would
 * make every lecturer's question paper readable by anyone who guessed the URL.
 * The original filename is kept only as a display label.
 *
 * `instructions` is sanitised on the way IN by the shared HtmlSanitizer, the
 * same filter Course Content lesson bodies pass through. Reusing it rather than
 * writing a second one is the point: two rich-text stores with two filters is
 * how one of them ends up with an XSS hole.
 */
class AssignmentService
{
    /** 20 MB. A low-bandwidth product decision, matching Course Content. */
    public const MAX_UPLOAD_KB = 20 * 1024;

    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    // ── authoring ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, CourseOffering $offering, array $attributes): Assignment
    {
        $this->access->assertCanManageOrFail($actor, $offering);

        return DB::transaction(function () use ($offering, $attributes, $actor) {
            $assignment = new Assignment();
            $this->apply($assignment, $offering, $actor, $attributes, isNew: true);
            $assignment->created_by = $actor->id;
            $assignment->save();

            return $assignment->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $actor, Assignment $assignment, array $attributes): Assignment
    {
        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $target = $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        return DB::transaction(function () use ($target, $offering, $actor, $attributes) {
            $this->apply($target, $offering, $actor, $attributes, isNew: false);
            $target->updated_by = $actor->id;
            $target->save();

            return $target->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Assignment $assignment, CourseOffering $offering, User $actor, array $attributes, bool $isNew): void
    {
        $requestedStatus = (string) ($attributes['status'] ?? $assignment->status ?: Assignment::STATUS_DRAFT);

        if (! $isNew) {
            AssignmentLifecycle::assertTransition($assignment, $requestedStatus);
        }

        $assignment->school_id = $offering->school_id;
        // The Offering is the container. It is taken from the resolved Offering,
        // never from the form, so a submitted field cannot move an assignment
        // between deliveries.
        $assignment->course_offering_id = $offering->id;
        $assignment->title = trim((string) ($attributes['title'] ?? ''));
        $assignment->instructions = $this->sanitizer->sanitize($attributes['instructions'] ?? null);
        $assignment->learning_objectives = $this->plainText($attributes['learning_objectives'] ?? null);
        $assignment->max_marks = max(0, (int) ($attributes['max_marks'] ?? 100));
        $assignment->submission_type = $this->normaliseSubmissionType($attributes['submission_type'] ?? null);
        $assignment->late_policy = $this->normaliseLatePolicy($attributes['late_policy'] ?? null);
        $assignment->allowed_attempts = $this->normaliseAttempts($attributes['allowed_attempts'] ?? null);
        $assignment->due_date = $this->parseMoment($attributes['due_date'] ?? null);
        $assignment->released_at = $this->parseMoment($attributes['released_at'] ?? null);
        $assignment->closes_at = $this->parseMoment($attributes['closes_at'] ?? null);

        // ── Module/chapter task fields ──
        //
        // The module id is RESOLVED against the already-authorised Offering, not
        // trusted from the form. A substituted id from another Offering or another
        // tenant is refused rather than written, which is what stops a task being
        // attached across a boundary by a crafted request.
        $assignment->course_offering_module_id = $this->resolveModule(
            $offering,
            $attributes['course_offering_module_id'] ?? null
        );

        // 'optional' unless the lecturer deliberately chose otherwise. A task
        // nobody configured never gates a student's progression.
        $assignment->requirement_role = $this->normaliseRequirementRole(
            $attributes['requirement_role'] ?? null
        );

        // Only meaningful for a required task; an optional task is never gated,
        // so its rule is recorded but never consulted.
        $assignment->completion_rule = $this->normaliseCompletionRule(
            $attributes['completion_rule'] ?? null,
            $assignment->requirement_role
        );

        $assignment->submission_kinds = $this->normaliseSubmissionKinds(
            $attributes['submission_kinds'] ?? null
        );

        // A closed assignment has no release time to speak of, and closing is
        // recorded as an event so "when did this close, and who closed it" is
        // answerable.
        if ($requestedStatus === Assignment::STATUS_CLOSED && $assignment->closed_at === null) {
            $assignment->closed_at = now();
            $assignment->closed_by = $actor->id;
        }

        $assignment->status = $requestedStatus;

        // The legacy flag is still maintained so any older query behaves
        // sensibly rather than going dark on HEI rows. Draft and scheduled are
        // NOT published; everything a student may see is.
        $assignment->is_published = in_array(
            $requestedStatus,
            [Assignment::STATUS_PUBLISHED, Assignment::STATUS_SCHEDULED, Assignment::STATUS_CLOSED],
            true
        );

        // He is the single author of an HEI assignment, so a legacy consumer
        // that reads teacher_id still has a sensible value.
        $assignment->teacher_id = $assignment->teacher_id ?: $actor->id;

        AssignmentLifecycle::assertStateRequirements($assignment, $requestedStatus);
        $this->assertPublishable($assignment, $requestedStatus);
    }

    /**
     * A task with no instructions and no deadline is not something a student can
     * act on, so it is refused at save time rather than being discovered by them.
     *
     * AND, FOR A QUESTION-BASED ASSIGNMENT, THE QUESTIONS MUST GOVERN THE MARKS
     *
     * An assignment with at least one question is question-based, and its marks are
     * then governed by those questions rather than by a number typed alongside them:
     *
     *   - every question is worth a positive number of marks
     *   - every question has at least one accepted answer type
     *   - the questions total EXACTLY the assignment's maximum
     *
     * `Assignment::questionReadinessProblems()` is the single definition of that,
     * and the lecturer's own question screen renders the same list as a fixable
     * checklist. One definition, so what the lecturer is told and what blocks
     * publication cannot disagree - which is the failure mode of a rule that lives
     * only inside a form.
     *
     * A DRAFT may be incomplete in every one of these ways. That is what a draft is
     * for, and refusing here would make it impossible to build a paper up over
     * several sittings.
     *
     * A GENERIC assignment - no questions, which is every K12 row, every
     * pre-existing HEI assignment and Assignment #3 as it stands - returns through
     * the same two checks it always did, so nothing about it changes.
     */
    private function assertPublishable(Assignment $assignment, string $status): void
    {
        if (! in_array($status, [Assignment::STATUS_PUBLISHED, Assignment::STATUS_SCHEDULED], true)) {
            return;
        }

        // The one canonical emptiness answer - see HtmlSanitizer::hasMeaningfulText().
    if (! $this->sanitizer->hasMeaningfulText($assignment->instructions)) {
            throw ValidationException::withMessages([
                'instructions' => 'Add the instructions before publishing. A published assignment with nothing to do is not something a student should be shown.',
            ]);
        }

        if (! $assignment->due_date) {
            throw ValidationException::withMessages([
                'due_date' => 'Set a due date and time before publishing, so a student can plan around it.',
            ]);
        }

        // Checked on BOTH the save path and the `transition()` path, because
        // publishing is reachable from a Publish button as well as from a save
        // carrying a status field. The guarantee must not depend on which control
        // the lecturer happened to reach for.
        $problems = $assignment->questionReadinessProblems();

        if ($problems !== []) {
            throw ValidationException::withMessages([
                'questions' => $problems,
                // Repeated as a plain sentence as well, so a form that renders only
                // the first error still tells the lecturer the real reason.
                'max_marks' => 'The questions must add up to the assignment total before you can publish.',
            ]);
        }
    }

    // ── lifecycle ─────────────────────────────────────────────────────────

    /**
     * Move to a new lifecycle state.
     *
     * Returns the assignment and whether it became visible to students, so the
     * caller knows whether to announce it. The announcement decision lives here
     * rather than in the controller so it cannot be forgotten on one path.
     */
    public function transition(User $actor, Assignment $assignment, string $to): array
    {
        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $target = $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        AssignmentLifecycle::assertTransition($target, $to);
        AssignmentLifecycle::assertStateRequirements($target, $to);
        // The SAME publish requirements the save path applies. Without this, the
        // Publish button on the detail screen would release an assignment with no
        // instructions or no due date - the two rules that exist so a student is
        // never shown a task they cannot act on or plan around. Applying them in
        // both places is the point: the guarantee must not depend on which
        // control the lecturer happened to reach for.
        $this->assertPublishable($target, $to);

        // Pulling an assignment back to Draft while students have already worked
        // on it would hide work that may still need grading, so it is refused.
        if ($to === Assignment::STATUS_DRAFT && $target->submissionCount() > 0) {
            throw new DomainException(
                'This assignment already has student submissions, so it cannot be returned to Draft. '
                .'Close it instead - the submissions and any marks are preserved.'
            );
        }

        $wasVisible = AssignmentLifecycle::isOpenToStudents($target);

        $target->status = $to;
        $target->updated_by = $actor->id;
        $target->is_published = in_array(
            $to,
            [Assignment::STATUS_PUBLISHED, Assignment::STATUS_SCHEDULED, Assignment::STATUS_CLOSED],
            true
        );

        if ($to === Assignment::STATUS_CLOSED && $target->closed_at === null) {
            $target->closed_at = now();
            $target->closed_by = $actor->id;
        }

        $target->save();

        $isVisible = AssignmentLifecycle::isOpenToStudents($target);

        return [
            'assignment' => $target->fresh(),
            // Announce exactly once, on the transition into visibility, never on
            // a save that leaves it already visible.
            'became_visible' => ! $wasVisible && $isVisible,
        ];
    }

    /**
     * Remove an assignment.
     *
     * Refused outright once student activity exists: a hard delete would destroy
     * a learner's work and a lecturer's marks. Drafts with no submissions may be
     * removed, because nothing of anyone else's is lost.
     */
    public function delete(User $actor, Assignment $assignment): void
    {
        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $target = $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        if ($target->submissionCount() > 0) {
            throw new DomainException(
                'This assignment has student submissions, so it cannot be deleted. '
                .'Close it instead - everything is preserved and stays available to the students who did the work.'
            );
        }

        foreach ($target->resources as $resource) {
            $this->deleteStoredFile($resource->stored_name);
            $resource->delete();
        }

        // Draft work a student prepared is theirs, not ours to discard silently.
        foreach ($target->submissions()->where('is_draft', true)->get() as $draft) {
            $this->deleteStoredFile($draft->file_path);
        }

        $target->submissions()->delete();
        $target->delete();
    }

    // ── resources ─────────────────────────────────────────────────────────

    public function attachLink(User $actor, Assignment $assignment, string $title, string $url): AssignmentResource
    {
        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);
        $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        $url = trim($url);
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'link_url' => 'The resource link must be a valid http or https address.',
            ]);
        }

        return AssignmentResource::query()->create([
            'school_id' => $offering->school_id,
            'course_offering_id' => $offering->id,
            'assignment_id' => $assignment->id,
            'title' => trim($title) !== '' ? trim($title) : $url,
            'type' => AssignmentResource::TYPE_LINK,
            'link_url' => $url,
            'created_by' => $actor->id,
        ]);
    }

    public function attachFile(User $actor, Assignment $assignment, UploadedFile $file): AssignmentResource
    {
        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);
        $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        $this->assertUploadable($file);

        // storage/app is OUTSIDE the web root. The name is generated, so an
        // uploaded "handout.pdf" cannot be requested at a guessable path or
        // overwrite an application file.
        $storedName = 'assignments/'.$offering->id.'/'.bin2hex(random_bytes(16)).'.'.strtolower((string) $file->getClientOriginalExtension());
        Storage::disk('local')->put($storedName, file_get_contents($file->getRealPath()));

        return AssignmentResource::query()->create([
            'school_id' => $offering->school_id,
            'course_offering_id' => $offering->id,
            'assignment_id' => $assignment->id,
            'title' => trim((string) $file->getClientOriginalName()) ?: 'Attachment',
            'type' => AssignmentResource::TYPE_FILE,
            'original_name' => (string) $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
            'created_by' => $actor->id,
        ]);
    }

    public function deleteResource(User $actor, int $resourceId): void
    {
        $resource = AssignmentResource::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($resourceId)
            ->first();

        if (! $resource) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Attachment not found.');
        }

        $offering = $this->access->resolveOffering($actor, (int) $resource->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);
        $this->access->resolveForManager($actor, (int) $offering->id, (int) $resource->assignment_id);

        $this->deleteStoredFile($resource->stored_name);
        $resource->delete();
    }

    /**
     * Refuse an upload that is not a file, is empty, is too large, or has an
     * extension that is not a permitted document type.
     *
     * The extension allowlist is explicit rather than "anything not blocked", so
     * an unexpected type is refused by default instead of accepted by omission.
     * PIIE's SafeUpload does the executable/content sniffing; this narrows it to
     * the document types an assignment hand-in can legitimately be.
     */
    public function assertUploadable(?UploadedFile $file): void
    {
        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a file to upload.']);
        }

        $allowed = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'csv',
            'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'png', 'jpg', 'jpeg', 'webp'];

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'file' => 'That file type cannot be uploaded here. Allowed: '.implode(', ', $allowed).'.',
            ]);
        }

        if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Files are limited to 20 MB. Upload a compressed copy, or link to the material instead.',
            ]);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function deleteStoredFile(?string $storedName): void
    {
        if ($storedName) {
            Storage::disk('local')->delete($storedName);
        }
    }

    private function normaliseSubmissionType($type): string
    {
        return in_array($type, Assignment::HEI_SUBMISSION_TYPES, true)
            ? $type
            : Assignment::SUBMISSION_FILE_AND_TEXT;
    }

    /**
     * Resolve the module this task belongs to, INSIDE the authorised Offering.
     *
     * Three outcomes: no module (null - the common case), a module of this
     * Offering, or a refusal. A module id that exists but belongs to a different
     * Offering or tenant is NOT silently ignored, because writing null would look
     * like a successful save and the lecturer would believe the task was attached
     * when it was not.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolveModule(CourseOffering $offering, $moduleId): ?int
    {
        if ($moduleId === null || $moduleId === '' || (int) $moduleId === 0) {
            return null;
        }

        $module = CourseOfferingModule::query()
            ->where('school_id', (int) $offering->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->find((int) $moduleId);

        if (! $module) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'course_offering_module_id' => 'That module does not belong to this Course Offering.',
            ]);
        }

        return (int) $module->id;
    }

    /**
     * `optional` is the default, and it is the safe one.
     */
    private function normaliseRequirementRole($role): string
    {
        return in_array($role, Assignment::REQUIREMENT_ROLES, true)
            ? $role
            : Assignment::ROLE_OPTIONAL;
    }

    /**
     * A REQUIRED task must carry a rule PIIE can actually evaluate.
     *
     * This is the check that makes the feature safe to offer. `teacher_verification`
     * is a real word in the vocabulary but nothing implements it, so a required
     * task saved with it would be permanently unsatisfiable and would strand
     * every student on that module. The form does not offer the rule and the
     * service refuses it, so a crafted request cannot create the situation.
     */
    private function normaliseCompletionRule($rule, string $requirementRole): string
    {
        $requested = is_string($rule) && $rule !== '' ? $rule : Assignment::RULE_SUBMISSION;

        if ($requirementRole !== Assignment::ROLE_REQUIRED) {
            // An optional task is never gated, so any rule is harmless. Recorded
            // for the lecturer's own reference, normalised to a known word.
            return in_array($requested, Assignment::COMPLETION_RULES, true)
                ? $requested
                : Assignment::RULE_SUBMISSION;
        }

        if (! in_array($requested, Assignment::IMPLEMENTED_COMPLETION_RULES, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'completion_rule' => 'A required task must use a rule PIIE can check. '
                    .'Satisfaction is currently evaluated by "'.Assignment::RULE_RELEASED_MARK.'" '
                    .'(marked and returned) or "'.Assignment::RULE_SUBMISSION.'" (submitted). '
                    .'"'.$requested.'" is declared but not yet available, because PIIE cannot yet observe it '
                    .'and would leave the module permanently incomplete.',
            ]);
        }

        return $requested;
    }

    /**
     * The evidence allowlist, stored as a comma-separated set.
     *
     * Only kinds a lecturer may configure, in the canonical order and
     * deduplicated, so the stored value is stable and a hand-edited request cannot
     * introduce a kind PIIE does not accept. An empty selection falls back to NULL
     * rather than to an empty string, so "not configured" stays distinguishable
     * from "configured to accept nothing".
     */
    private function normaliseSubmissionKinds($kinds): ?string
    {
        if ($kinds === null || $kinds === '') {
            return null;
        }

        $list = is_array($kinds) ? $kinds : explode(',', (string) $kinds);
        $clean = array_values(array_intersect(
            AssignmentSubmissionItem::CONFIGURABLE_KINDS,
            array_filter(array_map('trim', $list))
        ));

        return $clean === [] ? null : implode(',', $clean);
    }

    private function normaliseLatePolicy($policy): string
    {
        return in_array($policy, Assignment::LATE_POLICIES, true) ? $policy : Assignment::LATE_ALLOWED;
    }

    private function normaliseAttempts($attempts): ?int
    {
        if ($attempts === null || $attempts === '') {
            return null;
        }

        $value = (int) $attempts;

        // More than 99 attempts is a data error, not a generous policy.
        return ($value >= 1 && $value <= 99) ? $value : null;
    }

    private function parseMoment($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = $value instanceof Carbon ? $value->copy() : Carbon::parse((string) $value);

        return $parsed;
    }

    /**
     * Objectives are PLAIN TEXT the lecturer typed, one per line, rendered as a
     * list. Escaped, never treated as markup, because there is no reason for a
     * student to be able to inject HTML into an objectives list.
     */
    private function plainText($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // A lecturer pasting a bulleted list should not have to reformat it.
        $value = preg_replace('/^\s*[•\-*]\s*/m', '', $value) ?? $value;

        return Str::of($value)->squish()->toString() ?: null;
    }
}
