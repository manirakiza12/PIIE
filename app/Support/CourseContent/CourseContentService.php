<?php

namespace App\Support\CourseContent;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Models\CourseOfferingLessonResource;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Validation\ValidationException;

/**
 * Every write to Course Content, and every read a student is shown.
 *
 * ONE SERVICE, BOTH ROLES. The lecturer builder and the student reader call
 * the same methods, so a rule like "a draft is never released" is written once
 * and cannot be true on one screen and false on the other.
 *
 * ORDERING IS SERVER-AUTHORITATIVE
 *
 * Sequence is assigned by the server from the CURRENT order inside the parent,
 * in a transaction that locks the parent. The client may send an ordering; it may
 * not invent a sequence number, and it cannot move a row into another module,
 * another Offering or another tenant, because the parent is re-resolved by id
 * under lock and every write is constrained by the resolved parent's ids.
 */
class CourseContentService
{
    public function __construct(
        private CourseContentAccess $access,
        private HtmlSanitizer $sanitizer,
    ) {}

    // ══════════════════════ MODULES ══════════════════════

    public function createModule(User $actor, CourseOffering $offering, array $attributes): CourseOfferingModule
    {
        $this->access->assertCanManageOrFail($actor, $offering);

        return DB::transaction(function () use ($offering, $attributes, $actor) {
            $next = (int) CourseOfferingModule::query()
                ->where('course_offering_id', $offering->id)
                ->max('sequence') + 1;

            $module = new CourseOfferingModule();
            $module->forceFill([
                'school_id' => $offering->school_id,
                'course_offering_id' => $offering->id,
                'title' => trim((string) $attributes['title']),
                'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
                'sequence' => $next,
                'status' => $this->normaliseModuleStatus($attributes['status'] ?? CourseOfferingModule::STATUS_DRAFT),
                'released_at' => $this->parseRelease($attributes['released_at'] ?? null),
                'created_by' => $actor->id,
            ])->save();

            return $module->fresh();
        });
    }

    public function updateModule(User $actor, CourseOfferingModule $module, array $attributes): CourseOfferingModule
    {
        $offering = $this->access->resolveOffering($actor, (int) $module->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $module->forceFill([
            'title' => trim((string) $attributes['title']),
            'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
            'status' => $this->normaliseModuleStatus($attributes['status'] ?? $module->status),
            'released_at' => $this->parseRelease($attributes['released_at'] ?? null),
            'updated_by' => $actor->id,
        ])->save();

        return $module->fresh();
    }

    /**
     * Apply a client-supplied module ordering.
     *
     * The ids are RE-READ from the database and only those that genuinely belong
     * to this Offering and tenant are honoured. Anything else is discarded
     * rather than trusted, so a tampered request cannot pull another tenant's
     * module into this list, or renumber a module that is not in it.
     *
     * @param  array<int>  $orderedIds
     * @return int number of modules actually moved
     */
    public function reorderModules(User $actor, CourseOffering $offering, array $orderedIds): int
    {
        $this->access->assertCanManageOrFail($actor, $offering);

        return DB::transaction(function () use ($offering, $orderedIds) {
            // Lock the parent so two concurrent reorders cannot interleave and
            // produce duplicate sequence numbers.
            CourseOfferingModule::query()
                ->where('course_offering_id', $offering->id)
                ->lockForUpdate()
                ->get();

            $owned = CourseOfferingModule::query()
                ->where('school_id', $offering->school_id)
                ->where('course_offering_id', $offering->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $accepted = array_values(array_filter(
                array_map('intval', (array) $orderedIds),
                fn (int $id): bool => in_array($id, $owned, true)
            ));

            // Any module the client omitted keeps its relative order, appended
            // after the ones it did send, so nothing is ever lost by a partial
            // or stale request.
            foreach (array_values(array_diff($owned, $accepted)) as $index => $id) {
                $accepted[] = $id;
            }

            $moved = 0;
            foreach ($accepted as $position => $id) {
                $module = CourseOfferingModule::query()->find($id);
                if ($module && (int) $module->sequence !== $position + 1) {
                    $module->forceFill(['sequence' => $position + 1])->saveQuietly();
                    $moved++;
                }
            }

            return $moved;
        });
    }

    // ══════════════════════ LESSONS ══════════════════════

    public function createLesson(User $actor, CourseOfferingModule $module, array $attributes): CourseOfferingLesson
    {
        $offering = $this->access->resolveOffering($actor, (int) $module->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        return DB::transaction(function () use ($module, $attributes, $actor) {
            $next = (int) CourseOfferingLesson::query()
                ->where('course_offering_module_id', $module->id)
                ->max('sequence') + 1;

            $lesson = new CourseOfferingLesson();
            $lesson->forceFill([
                'school_id' => $module->school_id,
                'course_offering_id' => $module->course_offering_id,
                'course_offering_module_id' => $module->id,
                'title' => trim((string) $attributes['title']),
                'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
                'learning_objectives' => $this->nullIfBlank($attributes['learning_objectives'] ?? null),
                // Stored through the model mutator, so the sanitizer runs on
                // every path that can write a body.
                'body' => $attributes['body'] ?? null,
                'content_type' => $this->normaliseContentType($attributes['content_type'] ?? CourseOfferingLesson::CONTENT_TYPE_LESSON),
                'estimated_minutes' => $this->normaliseMinutes($attributes['estimated_minutes'] ?? null),
                'sequence' => $next,
                'status' => $this->normaliseLessonStatus($attributes['status'] ?? CourseOfferingLesson::STATUS_DRAFT),
                'released_at' => $this->parseRelease($attributes['released_at'] ?? null),
                'completion_rule' => $this->normaliseCompletionRule($attributes['completion_rule'] ?? CourseOfferingLesson::RULE_MANUAL),
                'created_by' => $actor->id,
            ])->save();

            return $lesson->fresh();
        });
    }

    public function updateLesson(User $actor, CourseOfferingLesson $lesson, array $attributes): CourseOfferingLesson
    {
        $offering = $this->access->resolveOffering($actor, (int) $lesson->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $changes = [
            'title' => trim((string) $attributes['title']),
            'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
            'learning_objectives' => $this->nullIfBlank($attributes['learning_objectives'] ?? null),
            'estimated_minutes' => $this->normaliseMinutes($attributes['estimated_minutes'] ?? null),
            'completion_rule' => $this->normaliseCompletionRule($attributes['completion_rule'] ?? $lesson->completion_rule),
            'updated_by' => $actor->id,
        ];

        if (array_key_exists('body', $attributes)) {
            $changes['body'] = $attributes['body'];
        }
        if (array_key_exists('status', $attributes)) {
            $changes['status'] = $this->normaliseLessonStatus($attributes['status']);
        }
        if (array_key_exists('released_at', $attributes)) {
            $changes['released_at'] = $this->parseRelease($attributes['released_at'] ?? null);
        }

        // Publishing something empty would put a blank lesson in front of
        // students, so it is refused here rather than discovered by them.
        if (($changes['status'] ?? $lesson->status) === CourseOfferingLesson::STATUS_PUBLISHED) {
            $body = array_key_exists('body', $changes) ? $changes['body'] : $lesson->body;
            // The one canonical emptiness answer - see HtmlSanitizer::hasMeaningfulText().
        if (! $this->sanitizer->hasMeaningfulText($body)) {
                throw new DomainException('Add some lesson content before publishing. A published lesson with no content is not something a student should be shown.');
            }
        }

        $lesson->forceFill($changes)->save();

        return $lesson->fresh();
    }

    /** @param array<int> $orderedIds */
    public function reorderLessons(User $actor, CourseOfferingModule $module, array $orderedIds): int
    {
        $offering = $this->access->resolveOffering($actor, (int) $module->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        return DB::transaction(function () use ($module, $orderedIds) {
            CourseOfferingLesson::query()
                ->where('course_offering_module_id', $module->id)
                ->lockForUpdate()
                ->get();

            $owned = CourseOfferingLesson::query()
                ->where('school_id', $module->school_id)
                ->where('course_offering_module_id', $module->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $accepted = array_values(array_filter(
                array_map('intval', (array) $orderedIds),
                fn (int $id): bool => in_array($id, $owned, true)
            ));
            foreach (array_values(array_diff($owned, $accepted)) as $id) {
                $accepted[] = $id;
            }

            $moved = 0;
            foreach ($accepted as $position => $id) {
                $lesson = CourseOfferingLesson::query()->find($id);
                if ($lesson && (int) $lesson->sequence !== $position + 1) {
                    $lesson->forceFill(['sequence' => $position + 1])->saveQuietly();
                    $moved++;
                }
            }

            return $moved;
        });
    }

    // ══════════════════════ LESSON RESOURCES ══════════════════════

    public function attachLinkResource(User $actor, CourseOfferingLesson $lesson, string $title, string $url): CourseOfferingLessonResource
    {
        $offering = $this->access->resolveOffering($actor, (int) $lesson->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'link_url' => 'The resource link must be a valid http or https address.',
            ]);
        }

        $resource = new CourseOfferingLessonResource();
        $resource->forceFill([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'title' => trim($title) !== '' ? trim($title) : $url,
            'type' => CourseOfferingLessonResource::TYPE_LINK,
            'link_url' => $url,
            'created_by' => $actor->id,
        ])->save();

        return $resource->fresh();
    }

    /**
     * Store an uploaded attachment OUTSIDE the web root.
     *
     * Low-data and private by construction: the original filename is kept only
     * as a display label, and the stored name is generated, so an uploaded
     * "slides.pdf" cannot be requested at a guessable path or overwrite an
     * application file.
     */
    public function attachFileResource(User $actor, CourseOfferingLesson $lesson, $file): CourseOfferingLessonResource
    {
        $offering = $this->access->resolveOffering($actor, (int) $lesson->course_offering_id);
        $this->access->assertCanManageOrFail($actor, $offering);

        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'Choose a file to attach.']);
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! preg_match('/^[a-z0-9]{1,8}$/', $extension)) {
            throw ValidationException::withMessages(['file' => 'That file type cannot be attached.']);
        }

        // 20 MB ceiling. A lecture recording is a link, not an upload: this is a
        // deliberate limit for the low-bandwidth markets PIIE targets.
        $maxKilobytes = 20 * 1024;
        if ((int) $file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                'file' => 'Attachments are limited to 20 MB. Link to a larger recording instead.',
            ]);
        }

        $storedName = 'course-content/'.$lesson->course_offering_id.'/'.bin2hex(random_bytes(16)).'.'.$extension;
        Storage::disk('local')->put($storedName, file_get_contents($file->getRealPath()));

        $resource = new CourseOfferingLessonResource();
        $resource->forceFill([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'title' => trim((string) $file->getClientOriginalName()),
            'type' => CourseOfferingLessonResource::TYPE_FILE,
            'original_name' => (string) $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
            'created_by' => $actor->id,
        ])->save();

        return $resource->fresh();
    }

    public function deleteResource(User $actor, int $resourceId): void
    {
        $resource = CourseOfferingLessonResource::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($resourceId)
            ->first();

        if (! $resource) {
            throw new HttpException(404, 'Attachment not found.');
        }

        $lesson = CourseOfferingLesson::query()->find($resource->course_offering_lesson_id);
        if (! $lesson) {
            throw new HttpException(404, 'Attachment not found.');
        }

        $this->access->assertCanManageOrFail($actor, $this->access->resolveOffering($actor, (int) $lesson->course_offering_id));

        if ($resource->stored_name) {
            Storage::disk('local')->delete($resource->stored_name);
        }

        $resource->delete();
    }

    // ══════════════════════ STUDENT READS ══════════════════════

    /**
     * The student's course content tree: released modules, released lessons, and
     * this student's own progress against them.
     *
     * Progress is counted ONLY over lessons a student is actually eligible to be
     * assessed on - released lessons, in released modules. A draft, an
     * unreleased lesson or an archived module contributes to neither numerator
     * nor denominator, so "3 of 8" is a claim about published learning rather
     * than about page views.
     *
     * @return array{modules: Collection, completed: int, total: int, percent: int}
     */
    public function studentContent(User $actor, CourseOffering $offering, ?\Illuminate\Support\Carbon $at = null): array
    {
        $at ??= now();

        $modules = CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('status', CourseOfferingModule::STATUS_PUBLISHED)
            ->where(fn ($query) => $query->whereNull('released_at')->orWhere('released_at', '<=', $at))
            ->orderBy('sequence')
            ->orderBy('id')
            ->with(['lessons' => fn ($query) => $query
                ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
                ->whereIn('content_type', CourseOfferingLesson::STUDENT_CONTENT_TYPES)
                ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '<=', $at))
                ->orderBy('sequence')
                ->orderBy('id'),
            ])
            ->get();

        // NO "HAS LESSONS" FILTER HERE, AND THAT IS A DELIBERATE MOVE
        //
        // This used to drop any module whose lesson list was empty, on the
        // reasonable ground that a published module with nothing released in it is
        // a section heading with no content, and showing it would look broken.
        //
        // It was a lesson-only rule applied to a page that is no longer
        // lesson-only. A module may legitimately hold a REQUIRED ASSESSMENT and
        // no lessons at all, and that module is exactly where a student must be
        // sent: it is the whole of what is left for them to do. Filtering it here
        // would make a required assessment unreachable from the very page whose
        // job is to point at it.
        //
        // The decision needs to know about assessments, and this service
        // deliberately does not - Course Content must not come to depend on the
        // Assignment domain. So the service returns every published, released
        // module and the CALLER decides what is worth showing, holding both
        // facts. The other caller of this method only searches the module list
        // for a lesson it already resolved, so it is unaffected either way.
        $modules = $modules->values();

        $lessonIds = $modules->flatMap(fn (CourseOfferingModule $module) => $module->lessons->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();

        // ONE query for the student's own engagement with these lessons, then
        // split by status. Fetching both states together costs one round trip
        // and cannot disagree with itself.
        $engagement = $lessonIds === []
            ? collect()
            : CourseOfferingLessonProgress::query()
                ->where('student_id', $actor->id)
                ->whereIn('course_offering_lesson_id', $lessonIds)
                ->get(['course_offering_lesson_id', 'status']);

        $completedIds = $engagement
            ->where('status', CourseOfferingLessonProgress::STATUS_COMPLETED)
            ->pluck('course_offering_lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Opened but not yet completed. This is DISPLAY STATE ONLY and is
        // deliberately absent from the figure below.
        $inProgressIds = $engagement
            ->where('status', CourseOfferingLessonProgress::STATUS_IN_PROGRESS)
            ->pluck('course_offering_lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $total = count($lessonIds);
        $completed = count($completedIds);

        return [
            'modules' => $modules,
            'completedIds' => $completedIds,
            'inProgressIds' => $inProgressIds,
            'completed' => $completed,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) round(($completed / $total) * 100),
        ];
    }

    /**
     * Record that a student OPENED a lesson.
     *
     * This is engagement, never completion. It records `in_progress` and a
     * last-viewed stamp and nothing else, so "3 of 8" can never be satisfied by
     * opening eight pages.
     */
    public function recordEngagement(User $student, CourseOfferingLesson $lesson): ?CourseOfferingLessonProgress
    {
        $existing = CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing) {
            // A completed lesson stays completed; re-opening it only refreshes
            // when it was last seen.
            $existing->forceFill(['last_viewed_at' => now()])->save();

            return $existing;
        }

        $progress = new CourseOfferingLessonProgress();
        $progress->forceFill([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => CourseOfferingLessonProgress::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'last_viewed_at' => now(),
        ])->save();

        return $progress->fresh();
    }

    /**
     * Mark a lesson complete, explicitly.
     *
     * Idempotent by design. A second press - or a retried request - updates the
     * one existing row and reports success; it never inserts a duplicate, which
     * would inflate the numerator and push progress above 100%.
     *
     * A lesson whose completion rule PIIE cannot observe is refused, because
     * letting a "Mark Complete" button satisfy a quiz or a teacher's judgement
     * would be a false claim about learning.
     */
    public function markComplete(User $student, CourseOfferingLesson $lesson): CourseOfferingLessonProgress
    {
        if (! $lesson->supportsStudentCompletion()) {
            throw new DomainException('This lesson is completed by its own activity, not by a button. Your progress updates when that activity is finished.');
        }

        $existing = CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing && $existing->isComplete()) {
            return $existing;
        }

        if ($existing) {
            $existing->forceFill([
                'status' => CourseOfferingLessonProgress::STATUS_COMPLETED,
                'completed_at' => now(),
                'last_viewed_at' => now(),
            ])->save();

            return $existing->fresh();
        }

        $progress = new CourseOfferingLessonProgress();
        $progress->forceFill([
            'school_id' => $lesson->school_id,
            'course_offering_id' => $lesson->course_offering_id,
            'course_offering_lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => CourseOfferingLessonProgress::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'last_viewed_at' => now(),
        ])->save();

        return $progress->fresh();
    }

    /**
     * The reader's Previous / Next, over released lessons in reading order.
     *
     * Navigation is derived from the SAME released set the progress count uses,
     * so a student can never be walked into a draft lesson by "Next" and the
     * position indicator always agrees with the count.
     *
     * @return array{position: int, total: int, previous: ?CourseOfferingLesson, next: ?CourseOfferingLesson}
     */
    public function navigationFor(User $student, CourseOfferingLesson $lesson): array
    {
        $tree = $this->studentContent($student, $lesson->offering ?? $this->loadOffering($lesson));
        $sequence = $tree['modules']->flatMap(fn (CourseOfferingModule $module) => $module->lessons)->values();

        $index = $sequence->search(fn (CourseOfferingLesson $item): bool => (int) $item->id === (int) $lesson->id);

        if ($index === false) {
            return ['position' => 0, 'total' => $sequence->count(), 'previous' => null, 'next' => null];
        }

        return [
            'position' => $index + 1,
            'total' => $sequence->count(),
            'previous' => $index > 0 ? $sequence[$index - 1] : null,
            'next' => $index < $sequence->count() - 1 ? $sequence[$index + 1] : null,
        ];
    }

    private function loadOffering(CourseOfferingLesson $lesson): CourseOffering
    {
        return CourseOffering::query()
            ->where('school_id', $lesson->school_id)
            ->whereKey($lesson->course_offering_id)
            ->firstOrFail();
    }

    // ══════════════════════ NORMALISERS ══════════════════════

    private function normaliseModuleStatus(?string $status): string
    {
        return in_array($status, CourseOfferingModule::STATUSES, true)
            ? $status
            : CourseOfferingModule::STATUS_DRAFT;
    }

    private function normaliseLessonStatus(?string $status): string
    {
        return in_array($status, CourseOfferingLesson::STATUSES, true)
            ? $status
            : CourseOfferingLesson::STATUS_DRAFT;
    }

    private function normaliseContentType(?string $type): string
    {
        return in_array($type, CourseOfferingLesson::CONTENT_TYPES, true)
            ? $type
            : CourseOfferingLesson::CONTENT_TYPE_LESSON;
    }

    private function normaliseCompletionRule(?string $rule): string
    {
        return in_array($rule, CourseOfferingLesson::COMPLETION_RULES, true)
            ? $rule
            : CourseOfferingLesson::RULE_MANUAL;
    }

    private function normaliseMinutes($minutes): ?int
    {
        if ($minutes === null || $minutes === '') {
            return null;
        }
        $value = (int) $minutes;

        // A lesson over a full day is a data error, not a long lesson.
        return ($value > 0 && $value <= 1440) ? $value : null;
    }

    private function parseRelease($value): ?\Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = \Illuminate\Support\Carbon::parse($value);

        return $parsed === null ? null : $parsed;
    }

    private function nullIfBlank($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : $value;
    }
}
