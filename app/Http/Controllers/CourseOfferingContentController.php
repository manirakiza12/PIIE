<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonResource;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseContent\CourseContentService;
use App\Support\CourseContent\CourseOfferingModuleLifecycle;
use App\Support\CourseContent\HtmlSanitizer;
use App\Support\CourseContent\ModulePublicationCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The lecturer's Course Content builder.
 *
 * A FULL PAGE for authoring, never a drawer or a modal. A lesson with tables,
 * images and objectives is a document, and a document does not belong in a
 * dialog: a lecturer writing one needs room to see the structure they are
 * producing, and a modal that scrolls internally is the single most common way
 * real content gets lost.
 *
 * The route carries the Allocation id for every action INCLUDING lesson and module
 * mutations, so the container is context rather than a client-supplied field,
 * and every write re-resolves the parent by id and re-checks authority. A
 * tampered form cannot move a lesson into another module, another Allocation or
 * another tenant.
 */
class CourseOfferingContentController extends Controller
{
    /**
     * The error bag lesson ATTACHMENTS report into.
     *
     * Separate from the lesson's own errors so that "the file field is required"
     * appears beside the attachment control that caused it, rather than at the
     * top of a lesson form that never failed.
     */
    public const RESOURCE_ERROR_BAG = 'lesson_resources';

    public function __construct(
        private CourseContentAccess $access,
        private CourseContentService $content,
        private HtmlSanitizer $sanitizer,
    ) {}

    /**
     * GET /teacher/course-offerings/{id}/content
     *
     * The builder. Answers "where am I and what do I do" before it shows a list,
     * because an empty page with an unexplained "+ Add Module" button reads as a
     * broken feature rather than an empty course.
     */
    public function index(Request $request, int $id): View
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $canManage = $this->access->canLecturerManage($request->user(), $offering);

        $modules = CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->with(['lessons' => fn ($query) => $query->orderBy('sequence')->orderBy('id')])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return view('teacher.course_offerings.content.index', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'modules' => $modules,
            'canManage' => $canManage,
            'publishedCount' => $modules->sum(fn ($module) => $module->lessons->where('status', 'published')->count()),
            'draftCount' => $modules->sum(fn ($module) => $module->lessons->where('status', 'draft')->count()),
            'progression' => $this->progressionFacts($offering),
        ]);
    }

    /**
     * WHAT SWITCHING GATING ON WOULD ACTUALLY DO, FOR THIS COURSE.
     *
     * The gate is enforced by `ProgressionGate`, and this is a fact sheet for the
     * person deciding to switch it. It exists because "a student cannot read the
     * next topic until this one is finished" means two materially different things
     * depending on how the course is set up, and a lecturer cannot tell which from
     * a checkbox:
     *
     *   - With a PASS MARK on the required tasks, "finished" means "passed", which
     *     is what a lecturer usually intends.
     *   - WITHOUT one, "finished" means "handed in" - the student's satisfaction is
     *     the act of submission, whatever mark comes back. That is a real policy,
     *     but it is not the one most people mean when they tick the box.
     *
     * A lecturer who cannot see that difference will switch on a gate they believe
     * is about passing, and find it stopping students who submitted a week's
     * earlier work that has not been marked yet. So the counts are supplied to the
     * view rather than left for the lecturer to discover by clicking.
     *
     * Read-only and cheap: two counts over the offering's own tasks.
     *
     * @return array{students:int, required:int, thresholded:int, unthresholded:int}
     */
    private function progressionFacts(\App\Models\CourseOffering $offering): array
    {
        $required = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('requirement_role', Assignment::ROLE_REQUIRED)
            ->get(['pass_mark']);

        $thresholded = $required->filter(fn (Assignment $task) => $task->hasPassMark())->count();

        return [
            'students' => \App\Models\CourseRegistration::query()
                ->where('course_offering_id', $offering->id)
                ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
                ->count(),
            'required' => $required->count(),
            'thresholded' => $thresholded,
            'unthresholded' => $required->count() - $thresholded,
        ];
    }

    /**
     * SWITCH PROGRESSION GATING ON OR OFF FOR THIS COURSE.
     *
     * WHY A SCREEN EXISTS WHEN THE FEATURE WAS DELIBERATELY OPT-IN
     *
     * The gate defaults OFF, so that no existing student is locked out of content
     * they have already paid for and may be part-way through. That is the right
     * default. But `gate_progression` existed only as a column on the model, so
     * "off" also meant "off forever" - there was no route, no control and no form,
     * and switching it on meant editing the database by hand. A safety default that
     * nobody can deliberately lift is not a safety default; it is a missing feature.
     *
     * So the switch is exposed, per course, to the lecturer who owns it - the one
     * person who knows whether their students are ready for it. It is deliberately
     * NOT switched on in bulk by a migration or a seeder: doing that would apply a
     * policy to courses whose content nobody has looked at, and would change what
     * students can reach without anybody having decided that.
     *
     * The same authorisation as every other write here: an allocation is
     * re-checked in the handler, not inferred from the button being rendered.
     */
    public function updateProgression(Request $request, int $id): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);

        // `assertCanManage`, not `canLecturerManage`. The second is a question for
        // rendering; this one throws. A POST that only checks a boolean is a POST
        // anybody can make.
        $this->access->assertCanManage($request->user(), $offering);

        $enabled = $request->boolean('gate_progression');
        $request->validate(['sequential_lessons' => ['sometimes', 'boolean']]);

        // Through the service, NOT `$offering->save()`. `CourseOffering` is
        // `$guarded = ['*']` with an `updating` hook that refuses every direct
        // write, because an Allocation is historical record. A direct assign-and-save
        // here reads as correct and throws a DomainException on the first click -
        // a switch that appears broken rather than one that is absent.
        app(\App\Support\CourseOffering\CourseOfferingService::class)
            ->setProgressionGate($offering->school_id, $offering->id, $enabled,
                $request->has('sequential_lessons') ? $request->boolean('sequential_lessons') : null);

        return back()->with(
            'success',
            $enabled
                ? 'Progression gating is ON. A student must finish each topic before the next one opens.'
                : 'Progression gating is OFF. Every student can reach every topic again.'
        );
    }

    public function storeModule(Request $request, int $id): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        // Assert at the HTTP layer so an unauthorised write is a 403 and not an
        // uncaught exception. The service re-checks as a backstop for callers
        // that are not HTTP, but it raises a DomainException, which the
        // exception handler would surface as a 500.
        $this->access->assertCanManage($request->user(), $offering);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->guarded(fn () => $this->content->createModule($request->user(), $offering, $validated));

        return redirect()
            ->route('teacher.course_offerings.content.index', $offering->id)
            ->with('success', 'Module created. It is a draft until you publish it, so students cannot see it yet.');
    }

    public function updateModule(Request $request, int $id, int $module): RedirectResponse
    {
        $target = CourseOfferingModule::query()
            ->where('school_id', $request->user()->school_id)
            ->where('course_offering_id', $id)
            ->whereKey($module)
            ->firstOrFail();

        $this->access->assertCanManage(
            $request->user(),
            $this->access->resolveOffering($request->user(), (int) $target->course_offering_id)
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:draft,published,archived'],
            'released_at' => ['nullable', 'date'],
        ]);

        $this->guarded(fn () => $this->content->updateModule($request->user(), $target, $validated));

        return back()->with('success', 'Module saved.');
    }

    /**
     * POST reorder. Server-authoritative, validated against the ids this
     * Allocation actually owns - see CourseContentService::reorderModules().
     */
    /**
     * THE MODULE LIFECYCLE: publish now, schedule, or return to draft.
     *
     * ── WHY THIS IS A DEDICATED ACTION AND NOT THE SETTINGS FORM ────────────
     *
     * `updateModule()` already accepts `status` and `released_at`, so the lifecycle
     * was never missing - it was unreachable. The only control was a `<select>` plus
     * an optional datetime inside a collapsed "Module settings" panel, which meant a
     * lecturer who wanted to release a module NOW had to know that "Scheduled" and
     * "Published" are the same stored state with a different date. That is the
     * reported symptom exactly.
     *
     * So the moves the rules permit are offered as one button each, and this is what
     * they post to. The rules live in `CourseOfferingModuleLifecycle`, the buttons
     * come from the same place via `actionsFor()`, and the page never re-derives a
     * legal move from the status itself.
     *
     * ── AUTHORISATION IS NOT RELAXED, IT IS REUSED ──────────────────────────
     *
     * The module is re-resolved inside the Allocation in the URL and scoped to the
     * actor's own school - byte-identical to `updateModule()` - and `assertCanManage`
     * is called exactly as it is there. A lecturer can therefore only move a module
     * in an Allocation they are currently allocated to, and one they are not gets the
     * same refusal as for editing.
     *
     * The target state arrives from a `whereIn` on the route, so only 'draft' and
     * 'published' can be named at all, and `assertTransition()` then refuses any move
     * the lifecycle does not permit - a tampered form included.
     */
    public function transitionModule(Request $request, int $id, int $module, string $to): RedirectResponse
    {
        $target = CourseOfferingModule::query()
            ->where('school_id', $request->user()->school_id)
            ->where('course_offering_id', $id)
            ->whereKey($module)
            ->firstOrFail();

        $this->access->assertCanManage(
            $request->user(),
            $this->access->resolveOffering($request->user(), (int) $target->course_offering_id)
        );

        // `released_at` is optional: absent means publish NOW. A `date` rule rather
        // than `date_format`, because the field posts `datetime-local` and `date`
        // accepts it along with every other representation the engine accepts.
        $validated = $request->validate([
            'released_at' => ['nullable', 'date'],
        ]);

        $before = CourseOfferingModuleLifecycle::labelFor($target);

        // A module with nothing in it must not reach a student. Checked here, at
        // the one place a module becomes visible, rather than inside the
        // lifecycle: that class answers "is this move legal", and folding content
        // opinions into it would make it a second source of truth about status.
        if ($to === CourseOfferingModule::STATUS_PUBLISHED) {
            $this->guarded(fn () => app(ModulePublicationCheck::class)->assertPublishable($target));
        }

        $updated = $this->guarded(fn () => CourseOfferingModuleLifecycle::apply(
            $target,
            $to,
            app(\App\Support\CourseContent\LearningSchedule::class)
                ->parse($validated['released_at'] ?? null, $request->user())?->toIso8601String(),
            $request->user()->id
        ));

        return redirect()
            ->route('teacher.course_offerings.content.index', $id)
            ->with('success', 'Module '.$updated->sequence.' is now '
                .CourseOfferingModuleLifecycle::labelFor($updated).'. It was '.$before.'.');
    }
    public function reorderModules(Request $request, int $id): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $moved = $this->guarded(fn () => $this->content->reorderModules($request->user(), $offering, $validated['order']));

        return back()->with('success', $moved > 0
            ? 'Module order saved.'
            : 'The order was already up to date.');
    }

    public function reorderLessons(Request $request, int $id, int $module): RedirectResponse
    {
        $moduleRow = CourseOfferingModule::query()
            ->where('school_id', $request->user()->school_id)
            ->where('course_offering_id', $id)
            ->whereKey($module)
            ->firstOrFail();

        $this->access->assertCanManage(
            $request->user(),
            $this->access->resolveOffering($request->user(), (int) $moduleRow->course_offering_id)
        );

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $moved = $this->guarded(fn () => $this->content->reorderLessons($request->user(), $moduleRow, $validated['order']));

        return back()->with('success', $moved > 0 ? 'Lesson order saved.' : 'The order was already up to date.');
    }

    /**
     * GET /teacher/course-offerings/{id}/content/lessons/create
     *
     * The authoring page, on its own route rather than a modal, so it can be
     * bookmarked, reloaded and - importantly - left by accident without losing
     * work.
     */
    public function createLesson(Request $request, int $id, ?int $module = null): View|RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $modules = CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->orderBy('sequence')
            ->get();

        if ($modules->isEmpty()) {
            return redirect()
                ->route('teacher.course_offerings.content.index', $offering->id)
                ->with('error', 'Add a module first. Lessons live inside a module so students can see where they are.');
        }

        $lesson = new CourseOfferingLesson();
        $lesson->course_offering_id = $offering->id;
        $lesson->course_offering_module_id = $module ?: (int) $modules->first()->id;
        $lesson->status = CourseOfferingLesson::STATUS_DRAFT;
        $lesson->content_type = CourseOfferingLesson::CONTENT_TYPE_LESSON;
        $lesson->completion_rule = CourseOfferingLesson::RULE_MANUAL;

        return view('teacher.course_offerings.content.lesson_form', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'modules' => $modules,
            'lesson' => $lesson,
            'mode' => 'create',
        ]);
    }

    /**
     * GET /teacher/course-offerings/{id}/content/lessons/{lesson}/edit
     */
    public function editLesson(Request $request, int $id, int $lesson): View
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $row = CourseOfferingLesson::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($lesson)
            ->with('resources')
            ->firstOrFail();

        return view('teacher.course_offerings.content.lesson_form', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'modules' => CourseOfferingModule::query()
                ->where('school_id', $offering->school_id)
                ->where('course_offering_id', $offering->id)
                ->orderBy('sequence')
                ->get(),
            'lesson' => $row,
            'mode' => 'edit',
        ]);
    }

    public function storeLesson(Request $request, int $id, ?int $module = null): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $validated = $request->validate($this->lessonRules($request, null));

        $parent = $this->resolveModule($offering, $module ?: ($validated['course_offering_module_id'] ?? null));

        $lesson = $this->guarded(fn () => $this->content->createLesson($request->user(), $parent, $validated));

        return redirect()
            ->route('teacher.course_offerings.content.lessons.edit', [$offering->id, $lesson->id])
            ->with('success', 'Lesson saved as a draft. Use Preview to see it as a student, then Publish when you are ready.');
    }

    public function updateLesson(Request $request, int $id, int $lesson): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $row = CourseOfferingLesson::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($lesson)
            ->firstOrFail();

        $validated = $request->validate($this->lessonRules($request, $row));

        // A lesson may be moved between modules of the SAME Allocation. The id is
        // re-resolved under the Allocation, so a module id from another Allocation or
        // tenant cannot be substituted here.
        if (! empty($validated['course_offering_module_id'])) {
            $row->course_offering_module_id = $this->resolveModule($offering, $validated['course_offering_module_id'])->id;
        }

        $this->guarded(fn () => $this->content->updateLesson($request->user(), $row, $validated));

        $status = $validated['status'] ?? $row->status;

        return redirect()
            ->route('teacher.course_offerings.content.lessons.edit', [$offering->id, $row->id])
            ->with('success', $status === CourseOfferingLesson::STATUS_PUBLISHED
                ? 'Lesson published. Confirmed students can now see it.'
                : 'Lesson saved.');
    }

    /**
     * GET preview. Renders the ACTUAL student reader markup against unsaved
     * input, so "Preview" means what it says instead of publishing and hoping.
     */
    public function previewLesson(Request $request, int $id, int $lesson): View
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $row = CourseOfferingLesson::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($lesson)
            ->with('resources')
            ->firstOrFail();

        // Unsaved form input wins, so a lecturer can preview an edit before
        // committing it. Sanitised through the same filter as a save, because a
        // preview must not become a way to render unsanitised HTML.
        $draft = $request->boolean('preview_draft');

        $preview = clone $row;
        if ($draft) {
            $preview->title = (string) $request->input('title', $row->title);
            $preview->summary = $request->input('summary', $row->summary);
            $preview->learning_objectives = $request->input('learning_objectives', $row->learning_objectives);
            $preview->estimated_minutes = $request->input('estimated_minutes', $row->estimated_minutes);
            $preview->body = $this->sanitizer->sanitize($request->input('body', (string) $row->body));
        }

        return view('teacher.course_offerings.content.lesson_preview', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'lesson' => $preview,
            'module' => $row->module,
            'isUnsavedPreview' => $draft,
        ]);
    }

    /**
     * Attach a file to a lesson that does not exist yet.
     *
     * WHY THIS EXISTS
     *
     * Not every lesson is written prose. A lecturer whose lesson IS the material -
     * a scanned handout, a slide deck, a recorded lecture - had no way to add it:
     * attachments were only offered on the EDIT screen, so the first thing they
     * were forced to do was type a paragraph of text purely to make the file
     * upload possible. The body is `nullable` all the way down, so the data model
     * always allowed a file-only lesson; only the screen refused to offer one.
     *
     * WHY IT SAVES A DRAFT RATHER THAN STASHING THE FILE
     *
     * The obvious alternative is a temporary holding area keyed by session, then a
     * move on first save. That introduces orphan files, a second lifetime for
     * every upload, and a cleanup job for the orphans. Saving the draft instead
     * costs one row the lecturer wanted anyway, writes the attachment to its
     * permanent home immediately, and cannot strand anything: if the upload is
     * refused, the lesson is a draft they can delete, not a cache entry nobody
     * will ever find.
     *
     * THE LESSON IS FORCED TO A DRAFT HERE, ALWAYS. A file must never be able to
     * publish a lesson, because publishing is a decision about whether the lesson
     * is ready - and "someone attached a PDF" is not that decision.
     */
    public function storeResourceForNewLesson(Request $request, int $id): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        // `$request->validate()` CANNOT BE USED HERE.
        //
        // Its signature is `validate($rules, $messages, $customAttributes)` - there
        // is no error-bag argument, and a fifth one is silently discarded. So
        // calling it with a bag looks like it works and quietly reports the
        // refusal in the DEFAULT bag, which is the lesson's own "before saving"
        // block: an upload that failed to attach would once again be reported as
        // the lesson having failed. Built by hand so the bag is really applied.
        $validator = Validator::make($request->all(), $this->resourceRules());

        if ($validator->fails()) {
            throw new ValidationException($validator, null, self::RESOURCE_ERROR_BAG);
        }

        $validated = $validator->validated();

        $parent = $this->resolveModule($offering, $request->input('course_offering_module_id') ?: null);

        // ONE TRANSACTION, so a refused attachment cannot leave a half-made lesson.
        //
        // Creating the lesson and attaching the file are two writes that only make
        // sense together: a lesson with no attachment is precisely the empty
        // draft this route exists to avoid producing. Validation catches the
        // obvious cases, but an upload that passes validation and then fails to
        // store - a full disk, an extension the service refuses on content rather
        // than on name - would otherwise leave the lecturer a blank lesson they
        // never asked for and cannot explain.
        $lesson = DB::transaction(function () use ($request, $validated, $parent) {
            // `status` is not taken from the request. See the method note.
            $created = $this->guarded(fn () => $this->content->createLesson($request->user(), $parent, [
                'title' => $request->input('title') ?: 'Untitled lesson',
                'summary' => $request->input('summary') ?: null,
                'learning_objectives' => $request->input('learning_objectives') ?: null,
                'status' => CourseOfferingLesson::STATUS_DRAFT,
                'body' => $request->input('body'),
            ]));

            if ($validated['type'] === 'file') {
                $this->guarded(fn () => $this->content->attachFileResource(
                    $request->user(),
                    $created,
                    $request->file('file')
                ));
            } else {
                $this->guarded(fn () => $this->content->attachLinkResource(
                    $request->user(),
                    $created,
                    (string) ($validated['title'] ?: $validated['link_url']),
                    (string) $validated['link_url']
                ));
            }

            return $created;
        });

        return redirect()
            ->route('teacher.course_offerings.content.lessons.edit', [$offering->id, $lesson->id])
            ->with(
                'success',
                'Lesson created as a draft and the file attached. A lesson can be only files - '
                .'add written notes if you want them, then Publish when you are ready.'
            );
    }

    /** Validate only the selected resource type, shared by new and saved content. */
    private function resourceRules(): array
    {
        return [
            'type' => ['required', 'in:link,file'],
            'title' => ['nullable', 'string', 'max:191'],
            'link_url' => ['exclude_unless:type,link', 'required', 'url:http,https', 'max:500'],
            'file' => ['exclude_unless:type,file', 'required', 'file', 'max:'.config('course_content.max_upload_kb', 20480)],
        ];
    }

    public function storeResource(Request $request, int $id, int $lesson): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $row = CourseOfferingLesson::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($lesson)
            ->firstOrFail();

        // ATTACHMENT ERRORS GO IN THEIR OWN BAG.
        //
        // Without this, a lecturer who taps "Upload file" without choosing a file
        // is told, at the top of the page under "Please fix the following before
        // saving", that the file field is required - which reads as the LESSON
        // having failed to save, not the attachment. The lesson was never
        // involved in that request. The same message was equally true of the
        // lesson and was about neither.
        //
        // A named bag lets the Resources panel show its own message in its own
        // place, and lets the lesson's error block stay silent when the lesson
        // itself is fine.
        $validator = Validator::make($request->all(), $this->resourceRules());

        if ($validator->fails()) {
            throw new ValidationException($validator, null, self::RESOURCE_ERROR_BAG);
        }

        $validated = $validator->validated();

        if ($validated['type'] === 'file') {
            $this->guarded(fn () => $this->content->attachFileResource($request->user(), $row, $request->file('file')));
        } else {
            $this->guarded(fn () => $this->content->attachLinkResource(
                $request->user(),
                $row,
                (string) ($validated['title'] ?? $validated['link_url']),
                (string) $validated['link_url']
            ));
        }

        return back()->with('success', 'Attachment added to this lesson.');
    }

    public function destroyResource(Request $request, int $resource): RedirectResponse
    {
        $this->guarded(fn () => $this->content->deleteResource($request->user(), $resource));

        return back()->with('success', 'Attachment removed.');
    }

    /**
     * Serve an uploaded attachment through the authorised route.
     *
     * Files live outside the web root, so this is the only way to reach one -
     * and it re-checks registration and release exactly as the reader does, so
     * a copied file id is worth nothing to anybody else.
     */
    public function resourceFile(Request $request, int $resource)
    {
        $row = CourseOfferingLessonResource::query()
            ->where('school_id', (int) $request->user()->school_id)
            ->whereKey($resource)
            ->firstOrFail();

        $lesson = CourseOfferingLesson::query()
            ->where('school_id', $row->school_id)
            ->whereKey($row->course_offering_lesson_id)
            ->firstOrFail();

        $offering = $this->access->resolveOffering($request->user(), (int) $lesson->course_offering_id);

        $isManager = (int) $request->user()->role_id === 3
            ? $this->access->canLecturerManage($request->user(), $offering)
            : $this->access->canAdminManage($request->user(), $offering);

        if (! $isManager) {
            // A student may only have an attachment if they are registered AND
            // the lesson is actually released.
            if (! $this->access->canStudentAccess($request->user(), $offering) || ! $lesson->isReleasedToStudents()) {
                abort(404);
            }
        }

        if (! $row->stored_name || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($row->stored_name)) {
            abort(404);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $row->stored_name,
            $row->original_name ?: $row->title
        );
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * Run a content service call, turning a domain refusal into a form error.
     *
     * CourseContentService raises DomainException for rules a lecturer can fix -
     * publishing an empty lesson, naming a module that is not theirs. Those are
     * the lecturer's problem to correct, not a server fault, so they are
     * reported back on the form with a readable message.
     *
     * Without this the exception reaches the handler and a lecturer who ticks
     * the wrong box gets a 500. Everything else is still reported, never
     * swallowed: a non-domain failure is re-thrown.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function guarded(callable $operation)
    {
        try {
            return $operation();
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function lessonRules(Request $request, ?CourseOfferingLesson $existing): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:500'],
            'learning_objectives' => ['nullable', 'string', 'max:5000'],
            // No length ceiling on the body: a lesson is a document, and the
            // editor is the limit. The sanitizer is the real boundary.
            'body' => ['nullable', 'string'],
            'content_type' => ['nullable', 'in:lesson'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'course_offering_module_id' => ['nullable', 'integer'],
            'status' => ['required', 'in:draft,published,archived'],
            'released_at' => ['nullable', 'date'],
            'completion_rule' => ['nullable', 'in:manual'],
        ];

        // An UNSAVED PREVIEW must not be held to the publish rules, or a
        // lecturer could not preview the empty first lesson they are about to
        // write.
        if ($request->boolean('preview_draft')) {
            $rules['status'] = ['nullable', 'in:draft,published,archived'];
        }

        return $rules;
    }

    /** Re-resolve a module inside the Allocation; a foreign id is a 404, not a move. */
    private function resolveModule(\App\Models\CourseOffering $offering, $moduleId): CourseOfferingModule
    {
        return CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey((int) $moduleId)
            ->firstOrFail();
    }

    private function courseUnitLabel(\App\Models\CourseOffering $offering): string
    {
        $terminology = app(\App\Support\TenantConfiguration::class)->terminology(
            \App\Models\School::query()->find($offering->school_id)
        );

        return $terminology['course_unit'] ?? 'Course Unit';
    }
}
