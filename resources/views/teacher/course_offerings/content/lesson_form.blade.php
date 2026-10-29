@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * The lesson editor. A FULL PAGE, deliberately.
     *
     * A lesson with headings, objectives, a table and an image is a document,
     * and a document does not belong in a modal. A dialog scrolls internally, so
     * the lecturer writes into a moving target and the Save button is somewhere
     * they have to hunt for. This page has room, a fixed action bar, and its own
     * URL, so it can be bookmarked, reloaded, or left open in a second tab.
     *
     * UNSAVED WORK
     * Two independent protections, because they fail differently:
     *   - beforeunload, for a tab being closed or navigated away from
     *   - a submit guard, for a second form on the page stealing the click
     * and a LOCAL DRAFT in localStorage, so a crash, a closed laptop or an
     * accidental refresh can be recovered from rather than lost. The recovery is
     * OFFERED, never forced, because silently replacing what a lecturer typed
     * with a stale copy would be its own kind of data loss.
     *
     * THE EDITOR
     * `x-academic-editor` - the single rich-text surface in PIIE. This view used
     * to carry its own copy of the Summernote bootstrap, and stopped working when
     * the layout stopped loading Summernote globally: the local bootstrap found
     * `$.fn.summernote` undefined and replaced the authoring surface with a
     * failure notice, so a lecturer could not write a lesson at all. The shared
     * component loads the vendor bundle itself, so there is nothing to bootstrap
     * here and no second copy of the toolbar to drift.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
    $isEdit = $mode === 'edit';
    $draftKey = 'piie-course-content-draft-' . $offering->id . '-' . ($lesson->id ?? 'new');
@endphp

@include('teacher.course_offerings.content._surface_styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.content.index', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to Course Content
    </a>
    <h4 class="mb-1">{{ $isEdit ? 'Edit lesson' : 'New lesson' }} &mdash; {{ $unitName }}</h4>
    <p class="text-muted mb-0 small">
        {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
        &middot; <span class="text-muted">{{ $lesson->status === 'published' ? 'Saving changes updates this published lesson for enrolled students.' : 'Draft lessons stay private until you publish them.' }}</span>
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@php
    // ONLY THE LESSON'S OWN ERRORS. Attachment errors live in their own bag and
    // are rendered beside the attachment control that caused them, so including
    // them here produced "Please fix the following before saving: the file field
    // is required" for a lesson that had saved perfectly well.
    $lessonErrors = $errors->getBag('default');
@endphp
@if($lessonErrors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following before saving:</strong>
        <ul class="mb-0 mt-1">@foreach($lessonErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

{{-- A recovered local draft is offered, never applied silently. --}}
<div class="alert alert-info d-none" id="cc-recovery" role="status">
    <strong>Unsaved work was found on this device.</strong>
    A copy of this lesson was recovered from your browser. Review it before using it &mdash;
    it may be older than what you have already saved.
    <div class="mt-2 d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary" data-cc-recovery-action="restore">Use the recovered copy</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-cc-recovery-action="discard">Discard it</button>
    </div>
</div>

{{-- `novalidate` is load-bearing: a `required` control inside a hidden step
     cannot be focused, and browsers refuse to submit such a form rather than
     saying why. Native validation is off so the step controller can check step 1
     explicitly; the server remains the authority on every rule that matters. --}}
<form method="POST" id="cc-lesson-form" novalidate
      data-cc-draft-key="{{ $draftKey }}"
      @if($isEdit) action="{{ route('teacher.course_offerings.content.lessons.update', [$offering->id, $lesson->id]) }}" @else action="{{ route('teacher.course_offerings.content.lessons.store', [$offering->id]) }}" @endif>
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ══ A TWO-STEP AUTHORING FLOW ══════════════════════════════════

         STEP 1 SETTINGS, THEN STEP 2 CONTENT. Nothing else changes.

         WHY. The form used to be one long column: title, objectives, the whole
         lesson body, the Save/Publish buttons, and THEN - below the fold and past
         the buttons - a sidebar of settings. A lecturer writing a lesson had to
         scroll past their own submit buttons to find the visibility dropdown, and
         the settings read as an afterthought of the content rather than the frame
         around it. The request was simply: set the lesson up first, then add the
         content, and do not keep showing the settings once you are writing.

         WHAT IS DELIBERATELY NOT CHOSEN. This is NOT a multi-request wizard.
         There is no "save settings, then save content" round trip, no partial
         record, and no new column: both steps are inside ONE <form>, so a single
         submit writes exactly the same lesson it always did. Changing the save
         semantics would mean a half-written lesson could exist, which is worse
         than a long page.

         `novalidate` IS LOAD-BEARING. Browsers refuse to submit a form containing
         a `required` control they cannot focus, and a control inside a hidden step
         cannot be focused - so leaving native validation on produced a silent,
         unfixable dead button whenever step 1 was the incomplete one. Native
         validation is therefore off and step 1 is checked explicitly, with the
         SERVER still the authority on every rule that matters. --}}
    <ul class="nav nav-pills mb-4 gap-2" id="cc-steps" role="tablist">
        <li class="nav-item" role="presentation">
            <button type="button" class="nav-link active" data-cc-step-tab="1" role="tab"
                    aria-selected="true" aria-controls="cc-step-1" id="cc-tab-1">
                <span class="badge rounded-pill bg-secondary me-1">1</span> Lesson settings
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="nav-link" data-cc-step-tab="2" role="tab"
                    aria-selected="false" aria-controls="cc-step-2" id="cc-tab-2">
                <span class="badge rounded-pill bg-secondary me-1">2</span> Add the content
            </button>
        </li>
    </ul>

    <div class="row g-3">
        {{-- ── STEP 1 · SETTINGS ───────────────────────────────────── --}}
        <div class="col-12 col-lg-8" id="cc-step-1" data-cc-step="1" role="tabpanel" aria-labelledby="cc-tab-1">
            <div class="mb-3">
                <label for="cc-title" class="form-label">Lesson title</label>
                <input type="text" class="form-control form-control-lg @error('title') is-invalid @enderror"
                       id="cc-title" name="title" maxlength="191"
                       placeholder="Lesson title"
                       value="{{ old('title', $lesson->title) }}">
                <div class="invalid-feedback" id="cc-title-note">{{ $errors->first('title') }}</div>
            </div>

            <div class="mb-3">
                <label for="cc-summary" class="form-label">Short description <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" class="form-control @error('summary') is-invalid @enderror"
                       id="cc-summary" name="summary" maxlength="500"
                       placeholder="One line students see in the module list before they open the lesson"
                       value="{{ old('summary', $lesson->summary) }}">
                @error('summary')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="cc-objectives" class="form-label">Learning objectives</label>
                <textarea class="form-control @error('learning_objectives') is-invalid @enderror"
                          id="cc-objectives" name="learning_objectives" rows="4" maxlength="5000"
                          placeholder="By the end of this lesson the student should be able to:&#10;&#8226; Describe the key concepts&#10;&#8226; Apply the procedure in practice"
                          data-cc-dirty>{{ old('learning_objectives', $lesson->learning_objectives) }}</textarea>
                @error('learning_objectives')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    One per line, or separated by <kbd>;</kbd>. These are shown to the student before the lesson content.
                </div>
            </div>

            {{-- ── The settings, which used to live in a sidebar BELOW the
                 editor and below the Save/Publish buttons ───────────── --}}
            <div class="border rounded p-3">
                <h6 class="mb-3">Lesson settings</h6>

                <div class="mb-3">
                    <label for="cc-module" class="form-label">Module</label>
                    <select class="form-select" id="cc-module" name="course_offering_module_id">
                        @foreach($modules as $option)
                            <option value="{{ $option->id }}" @selected((int) old('course_offering_module_id', $lesson->course_offering_module_id) === (int) $option->id)>
                                Module {{ $option->sequence }} — {{ $option->title }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">The lesson is ordered within this module.</div>
                </div>

                <div class="mb-3">
                    <label for="cc-status" class="form-label">Visibility</label>
                    <select class="form-select" id="cc-status" name="status">
                        <option value="draft" @selected(old('status', $lesson->status) === 'draft')>Draft — only you can see it</option>
                        <option value="published" @selected(old('status', $lesson->status) === 'published')>Published — students can read it</option>
                        <option value="archived" @selected(old('status', $lesson->status) === 'archived')>Archived — withdrawn, kept for the record</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="cc-released" class="form-label">Release on <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="datetime-local" class="form-control" id="cc-released" name="released_at"
                           value="{{ old('released_at', app(\App\Support\CourseContent\LearningSchedule::class)->local($lesson->released_at, auth()->user())) }}">
                    <div class="form-text">
                        Times use {{ app(\App\Support\TenantTimezone::class)->resolve(auth()->user()) }}.
                        Leave empty to appear as soon as it is published. Set a date to have it
                        appear automatically, and the page will show it as <em>Scheduled</em> until then.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="cc-minutes" class="form-label">Estimated study time <span class="text-muted fw-normal">(minutes)</span></label>
                    <input type="number" class="form-control" id="cc-minutes" name="estimated_minutes"
                           min="1" max="1440" step="1"
                           value="{{ old('estimated_minutes', $lesson->estimated_minutes) }}">
                    <div class="form-text">Shown to the student so they know what to set aside.</div>
                </div>

                <div class="mb-0">
                    <label for="cc-rule" class="form-label">How a student completes this</label>
                    <select class="form-select" id="cc-rule" name="completion_rule">
                        <option value="manual" @selected(old('completion_rule', $lesson->completion_rule) === 'manual')>
                            The student marks it complete themselves
                        </option>
                    </select>
                    <div class="form-text">
                        Other rules &mdash; a quiz result, a watched video percentage, a teacher's
                        verification &mdash; are reserved for when those engines exist. They are not
                        offered here rather than offered and not honoured.
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="button" class="btn btn-primary" data-cc-step-next="2">
                    Continue to the content <span aria-hidden="true">&rarr;</span>
                </button>
            </div>
        </div>

        {{-- ── STEP 2 · THE CONTENT ─────────────────────────────────── --}}
        <div class="col-12 col-lg-8" id="cc-step-2" data-cc-step="2" role="tabpanel" aria-labelledby="cc-tab-2" hidden>
            <div class="mb-2">
                {{-- THE SHARED EDITOR, NOT A HAND-ROLLED ONE.

                     This view used to carry its own copy of the Summernote
                     bootstrap, toolbar, table plugin and sub/superscript pair.
                     That stopped working when the layout stopped loading
                     Summernote globally - see the long note in
                     `layouts/app.blade.php` about double-registering
                     `$.fn.summernote`. With the vendor bundle no longer on the
                     page, the local bootstrap found `$.fn.summernote` undefined
                     and replaced the authoring surface with the red "the lesson
                     editor could not be loaded" panel.

                     So the lecturer could not write a lesson at all, and the only
                     visible symptom was a failure notice in the one field the
                     whole screen exists for. The fix is not to re-add a local
                     bootstrap: it is to use `x-academic-editor`, which is the one
                     editor in PIIE and loads the vendor bundle itself. --}}
                <x-academic-editor
                    name="body"
                    id="cc-body"
                    label="Lesson content"
                    :value="old('body', $lesson->body)"
                    help="Use the toolbar for headings, bold, lists, tables, images and quotations. Save Draft keeps it private. Preview shows it exactly as a student will read it. Publish makes it visible to confirmed students."
                    :height="420"
                    testid="cc-body-editor" />
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
                <button type="submit" name="status" value="draft" class="btn btn-outline-secondary" data-cc-unsaved-ok>
                    Save Draft
                </button>
                <button type="button" class="btn btn-outline-primary" data-cc-preview>Preview</button>
                <button type="submit" name="status" value="published" class="btn btn-primary" data-cc-unsaved-ok>
                    Publish
                </button>
                @if($isEdit)
                    <a class="btn btn-outline-secondary" href="{{ route('teacher.course_offerings.content.lessons.preview', [$offering->id, $lesson->id]) }}">
                        Preview saved version
                    </a>
                @endif
                <button type="button" class="btn btn-link" data-cc-step-next="1">
                    <span aria-hidden="true">&larr;</span> Back to settings
                </button>
            </div>

            {{-- ── Attachments ────────────────────────────────────────────
                 OFFERED ON A NEW LESSON TOO.

                 This panel used to be edit-only, with a note telling the lecturer
                 to "save the lesson first". That made a file-only lesson
                 impossible to start: a lecturer whose lesson IS a slide deck or a
                 recording had to invent a paragraph of prose before the upload
                 button existed. The body has always been optional, so nothing but
                 the screen refused.

                 Uploading from here saves a DRAFT carrying whatever has been typed
                 so far, attaches the file, and lands on the edit page. One request,
                 no temporary storage, and the lesson can then be nothing but files. --}}
            <div class="col-12 col-lg-4">
            @if(true)
                <div class="border rounded p-3">
                    <h6 class="mb-2">Resources &amp; attachments</h6>
                    <p class="small text-muted">
                        @if($isEdit)
                            Files are stored outside the public web folder and are only reachable by students
                            registered for this Course Allocation.
                        @else
                            A lesson can be <strong>only files</strong> &mdash; slides, a handout, a
                            recording. Uploading here saves a draft with your title and notes so far, then
                            attaches the file. Nothing is published until you say so.
                        @endif
                    </p>

                    @php $existing = $lesson->resources; @endphp
                    @php $resourceErrors = $errors->getBag(\App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG); @endphp
                    @if($resourceErrors->any())
                        <div class="alert alert-danger" role="alert">
                            <strong>The attachment was not added:</strong>
                            <ul class="mb-0 mt-1">
                                @foreach($resourceErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                    @if($existing->isNotEmpty())
                        <ul class="list-group list-group-flush mb-3">
                            @foreach($existing as $resource)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">
                                    <div style="min-width:0">
                                        <div class="small fw-semibold text-truncate">{{ $resource->displayName() }}</div>
                                        <div class="small text-muted">
                                            {{ $resource->isFile() ? 'File' : 'Link' }}
                                            @if($resource->sizeLabel()) &middot; {{ $resource->sizeLabel() }} @endif
                                        </div>
                                    </div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        @if($resource->isFile())
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="{{ route('teacher.course_offerings.content.resources.file', $resource->id) }}">Open</a>
                                        @elseif($resource->hasUsableLink())
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $resource->link_url }}"
                                               target="_blank" rel="noopener noreferrer">Open</a>
                                        @endif
                                        <button type="submit" form="cc-delete-{{ $resource->id }}"
                                                class="btn btn-sm btn-outline-danger"
                                                aria-label="Remove {{ $resource->displayName() }}">Remove</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-muted">No attachments yet.</p>
                    @endif

                    <div class="border-top pt-3">
                        <label for="cc-res-link" class="form-label small fw-semibold">Add a link</label>
                        <input type="url" class="form-control form-control-sm mb-2" id="cc-res-link"
                               placeholder="https://example.org/reference" form="cc-resource-form" name="link_url">
                        <label for="cc-res-title" class="form-label small fw-semibold">Link label <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm mb-2" id="cc-res-title"
                               placeholder="e.g. Further reading" form="cc-resource-form" name="title">
                        <button type="submit" class="btn btn-sm btn-outline-primary" form="cc-resource-form"
                                name="type" value="link">Add link</button>
                    </div>

                    <div class="border-top pt-3 mt-3">
                        <label for="cc-res-file" class="form-label small fw-semibold">Upload a file</label>
                        <input type="file" class="form-control form-control-sm mb-2" id="cc-res-file"
                               form="cc-resource-form" name="file">
                        <div class="form-text mb-2">Up to {{ number_format(config('course_content.max_upload_kb', 20480) / 1024, 0) }} MB. Documents, images and supported videos; link to larger files.</div>
                        <button type="submit" class="btn btn-sm btn-outline-primary" form="cc-resource-form"
                                name="type" value="file">Upload file</button>
                    </div>

                    {{-- ── Embed a recording in the lesson body ────────────────
                         The editor's "Insert a recording" button reads the radio
                         group and the hidden URL field below. They exist so the
                         button can only ever embed something the lecturer has
                         actually attached to THIS lesson: the id is chosen, not
                         typed, so there is nothing free-form to mistype and
                         nothing for a crafted request to smuggle in. --}}
                    @php
                        $media = $existing->filter(
                            fn ($r) => $r->isFile()
                                && in_array(
                                    strtolower(pathinfo((string) $r->original_name, PATHINFO_EXTENSION)),
                                    ['mp4', 'webm', 'mov', 'mkv', 'm4v', 'mp3', 'm4a', 'wav', 'ogg', 'oga'],
                                    true
                                )
                        );
                    @endphp
                    @if($media->isNotEmpty())
                        <div class="border-top pt-3 mt-3">
                            <h6 class="small fw-semibold mb-2">Place a recording in the lesson</h6>
                            <ul class="list-group list-group-flush mb-2">
                                @foreach($media as $item)
                                    @php $mediaUrl = route('teacher.course_offerings.content.resources.file', $item->id); @endphp
                                    @foreach(['audio', 'video'] as $kind)
                                        <li class="list-group-item px-0 d-flex align-items-center gap-2">
                                            <input class="form-check-input mt-0 flex-shrink-0" type="radio"
                                                   name="cc-media-kind"
                                                   id="cc-media-{{ $kind }}-{{ $item->id }}"
                                                   value="{{ $kind }}|{{ $mediaUrl }}"
                                                   data-cc-media>
                                            <label class="small flex-grow-1 text-truncate mb-0"
                                                   for="cc-media-{{ $kind }}-{{ $item->id }}">
                                                {{ $item->displayName() }}
                                            </label>
                                            <span class="text-muted small flex-shrink-0">{{ $kind }}</span>
                                        </li>
                                    @endforeach
                                @endforeach
                            </ul>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-cc-insert-media>
                                Insert into lesson
                            </button>
                            <div class="form-text mt-1">
                                Tick a recording above, then press Insert. It is placed at your cursor.
                            </div>
                        </div>
                    @endif
                </div>
            @else
                <div class="border rounded p-3">
                    <h6 class="mb-2">Resources &amp; attachments</h6>
                    <p class="small text-muted mb-0">
                        Save the lesson first, then add links and files. That way an attachment is
                        always attached to a lesson that exists.
                    </p>
                </div>
            @endif
            </div>{{-- /col-lg-4 attachments --}}
        </div>{{-- /cc-step-2 --}}
    </div>{{-- /row --}}
</form>

{{-- A separate form so an upload is a normal multipart POST rather than a
     nested form inside the lesson form (which HTML does not allow).

     The action depends on whether the lesson exists. On the create screen it posts
     to the handler that creates a DRAFT and attaches in one request, which is what
     makes a file-only lesson possible. The lecturer's own title, objectives and
     body are carried along as hidden fields on this form, so nothing they have
     typed is thrown away by taking the shortcut. --}}
<form id="cc-resource-form" method="POST" enctype="multipart/form-data" class="d-none"
      action="{{ $isEdit
          ? route('teacher.course_offerings.content.resources.store', [$offering->id, $lesson->id])
          : route('teacher.course_offerings.content.lessons.resources.store_new', [$offering->id]) }}">
    @csrf
    @unless($isEdit)
        {{-- The draft carries what has been typed so far, so uploading a file
             never costs the lecturer the lesson they were in the middle of
             writing. All optional: a lecturer who wants a file-only lesson simply
             leaves them blank and gets "Untitled lesson". --}}
        <input type="hidden" name="title" value="{{ old('title', $lesson->title) }}">
        <input type="hidden" name="summary" value="{{ old('summary', $lesson->summary) }}">
        <input type="hidden" name="learning_objectives" value="{{ old('learning_objectives', $lesson->learning_objectives) }}">
        <input type="hidden" name="course_offering_module_id" value="{{ old('course_offering_module_id', $lesson->course_offering_module_id) }}">
        <input type="hidden" name="body" value="{{ old('body', $lesson->body) }}">
    @endunless
</form>

@if($isEdit)
    @foreach($existing as $resource)
        <form id="cc-delete-{{ $resource->id }}" method="POST" class="d-none"
              action="{{ route('teacher.course_offerings.content.resources.store', [$offering->id, $lesson->id]) }}"
              onsubmit="return confirm('Remove this attachment from the lesson?')">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
            <input type="hidden" name="resource" value="{{ $resource->id }}">
        </form>
    @endforeach
@endif

<style>
    /* Suppress Summernote's default 350px height in favour of the roomy shell. */
    .cc-editor-shell .note-container { margin: 0; }
    .cc-editor-shell .note-resizebar { display: none; }
    .cc-editor-shell .note-popover { display: none; }
    .cc-editor-shell .note-dropdown { z-index: 1080; }

    /*
     * THE SOURCE FIELD IS HIDDEN, NOT REMOVED.
     *
     * Summernote does not hide the textarea it is initialised on - that is the
     * integrator's job, and omitting it left a raw HTML box sitting above the
     * editor, so a lecturer saw two authoring surfaces and the plainer one was
     * the more prominent. There should be exactly one place to write.
     *
     * `display: none` is the right tool rather than a visual trick, and nothing
     * depends on the field being visible:
     *   - it is still submitted, because `display: none` is not `disabled`;
     *   - Summernote keeps writing the current markup into it on every
     *     keystroke, so form submission, the sanitizer and draft recovery all
     *     read the value the editor holds;
     *   - it also leaves the accessibility tree, which is correct: this is a
     *     synchronization field, not a control for a person to operate.
     * The editor keeps the label's accessible name via aria-labelledby below.
     *
     * The rule that hides the source textarea is NOT here any more. It lived in
     * this view while the editor did, and is now owned by
     * `public/css/academic-editor.css` as
     * `html.piie-js .piie-editor-shell > textarea.piie-editor-source`. Duplicating
     * it here would be a second, unscoped copy that also fires without
     * JavaScript - hiding the only field a no-JS lecturer could type into.
     */
</style>

<script>
// The Summernote bootstrap that used to live here was removed when this view
// moved to the shared `x-academic-editor` component. It could not work: the
// layout stopped loading Summernote globally, so the local bootstrap found
// `$.fn.summernote` undefined and replaced the authoring surface with a
// failure notice. The component loads the vendor bundle itself, which is why
// nothing is needed here to make an editor appear.
//
// What remains is the behaviour that is about THIS form rather than about
// rich text: unsaved-work protection, the preview hand-off, and the two-step
// authoring flow.

// ══ THE TWO-STEP FLOW ═══════════════════════════════════════════════════
//
// Settings first, then content, and the settings are GONE once you are
// writing - which is the whole point. Both steps live inside the one form, so
// one submit still writes exactly the lesson it always wrote.
//
// A field on the hidden step is still submitted: `hidden` removes it from the
// display, not from the form data. The one thing `hidden` DOES break is native
// constraint validation, which is why the form carries `novalidate` and step 1
// is checked here instead.
//
// THE ERROR ROUTING IS THE PART WORTH CARING ABOUT. A save rejected for a
// settings problem must come back to SETTINGS, not to the content step the
// lecturer was looking at when they pressed Save - otherwise they are told to
// fix a field they cannot see, which is the same class of fault as an
// attachment error being reported against the lesson.
(function () {
    var steps = Array.prototype.slice.call(document.querySelectorAll('[data-cc-step]'));
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-cc-step-tab]'));

    if (!steps.length) { return; }

    // Which step owns which field. Anything not listed is treated as content.
    var SETTINGS_FIELDS = [
        'title', 'summary', 'learning_objectives',
        'course_offering_module_id', 'status', 'released_at',
        'estimated_minutes', 'completion_rule'
    ];

    function show(n) {
        steps.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-cc-step') !== String(n);
        });

        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-cc-step-tab') === String(n);
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Step 1 has exactly one field the server will not accept as empty, so it is
    // the only one worth checking here. Everything else is the server's call.
    function settingsAreAcceptable() {
        var title = document.getElementById('cc-title');

        if (!title || title.value.trim() !== '') { return true; }

        title.classList.add('is-invalid');
        title.focus();

        var note = document.getElementById('cc-title-note');
        if (note) { note.textContent = 'Give the lesson a title before continuing.'; }

        return false;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-cc-step-tab');

            // Moving BACK is always allowed. Moving FORWARD past settings has to
            // clear the one check, or step 2 is reachable with no title in it.
            if (target === '2' && !settingsAreAcceptable()) { return; }

            show(target);
        });
    });

    document.querySelectorAll('[data-cc-step-next]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-cc-step-next');

            if (target === '2' && !settingsAreAcceptable()) { return; }

            show(target);
        });
    });

    var title = document.getElementById('cc-title');
    if (title) {
        title.addEventListener('input', function () {
            title.classList.remove('is-invalid');
            var note = document.getElementById('cc-title-note');
            if (note) { note.textContent = ''; }
        });
    }

    // Land on the step that owns the first error, if the server sent one.
    var serverErrors = @json($lessonErrors->keys());
    if (serverErrors.length) {
        var first = serverErrors[0];
        show(SETTINGS_FIELDS.indexOf(first) !== -1 ? '1' : '2');
    }
})();

// The dirty-form guard, draft recovery and preview hand-off used to live inside
// `document.addEventListener('DOMContentLoaded', ...)` and `jQuery(function ($) {...})`.
// Both openers were removed with the Summernote bootstrap and, for a while, their
// three closing braces were left behind pointing at nothing.
//
// That is not cosmetic. A file with unmatched braces is a SYNTAX ERROR, so the
// browser discards the whole `<script>` - including the two-step controller above,
// which is why "Continue to the content" did nothing at all. Nothing in this file
// ran.
//
// It survived because neither Blade nor the PHP test suite can execute
// JavaScript: `Blade::compileString` only resolves directives, and the feature
// tests assert on markup. The only thing that catches this class of fault is
// parsing the emitted script, which `test_the_emitted_script_is_valid_javascript`
// now does.
//
// The guard is plain DOM work and needs no library, so it is wrapped in a DOM
// ready listener rather than reintroducing a jQuery dependency it never had a use
// for now.
document.addEventListener('DOMContentLoaded', function () {
        // ══ Dirty-form protection ═════════════════════════════════════════
        // Three independent layers, because each covers a case the others miss.
        var form = document.getElementById('cc-lesson-form');
        if (!form) { return; }
        var draftKey = form.getAttribute('data-cc-draft-key');
        var dirty = false;
        var saveTimer = null;

        function isDirty() {
            if (dirty) return true;
            // A recovered draft is dirty: there is unsaved work on screen.
            return false;
        }

        // 1. Local recovery. A copy of the lesson is kept in this browser so a
        //    crash or a closed tab is recoverable. The copy is only ever OFFERED
        //    on the next visit - applying it silently would be replacing what the
        //    lecturer can see with an older copy of their own work.
        function snapshot() {
            try {
                window.localStorage.setItem(draftKey, JSON.stringify({
                    title: document.getElementById('cc-title').value,
                    summary: document.getElementById('cc-summary').value,
                    objectives: document.getElementById('cc-objectives').value,
                    minutes: document.getElementById('cc-minutes').value,
                    body: $body.summernote('code'),
                    saved_at: new Date().toISOString()
                }));
            } catch (e) { /* private browsing / quota: the other layers still apply */ }
        }

        var recovery = null;
        try {
            var raw = window.localStorage.getItem(draftKey);
            if (raw) recovery = JSON.parse(raw);
        } catch (e) { recovery = null; }

        var recoveryBox = document.getElementById('cc-recovery');
        if (recovery && recoveryBox) {
            var savedAt = recovery.saved_at ? new Date(recovery.saved_at) : null;
            recoveryBox.querySelector('strong').textContent =
                'Unsaved work was found on this device' +
                (savedAt ? ' (from ' + savedAt.toLocaleString() + ')' : '') + '.';
            recoveryBox.classList.remove('d-none');
            recoveryBox.addEventListener('click', function (event) {
                var action = event.target.getAttribute('data-cc-recovery-action');
                if (!action) return;
                if (action === 'restore' && recovery) {
                    document.getElementById('cc-title').value = recovery.title || '';
                    document.getElementById('cc-summary').value = recovery.summary || '';
                    document.getElementById('cc-objectives').value = recovery.objectives || '';
                    document.getElementById('cc-minutes').value = recovery.minutes || '';
                    $body.summernote('code', recovery.body || '');
                    dirty = true;
                }
                recoveryBox.classList.add('d-none');
            });
        }

        form.addEventListener('input', function () {
            dirty = true;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(snapshot, 1500);
        });
        form.addEventListener('change', function () { dirty = true; clearTimeout(saveTimer); saveTimer = setTimeout(snapshot, 300); });

        // 2. Leaving the page. beforeunload covers a closed tab, a back
        //    navigation and a refresh. The browser shows its own generic wording,
        //    which is intentional: a custom dialog here is not supported.
        window.addEventListener('beforeunload', function (event) {
            if (!isDirty()) return;
            event.preventDefault();
            event.returnValue = '';
        });

        // 3. Submitting is NOT leaving - a save is the point. Buttons that save
        //    opt out explicitly, so a legitimate save never raises a prompt.
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && submitter.hasAttribute('data-cc-unsaved-ok')) {
                form.removeEventListener('submit', arguments.callee);
                dirty = false;
                try { window.localStorage.removeItem(draftKey); } catch (e) {}
                return;
            }
            if (isDirty() && !window.confirm('This action will discard unsaved changes. Continue?')) {
                event.preventDefault();
            }
        });

        // ══ Preview ════════════════════════════════════════════════════════
        // Preview POSTS the CURRENT form to the preview route, so it shows
        // unsaved work rather than the last saved version. It passes
        // preview_draft, which is what tells the controller to prefer request
        // input - and to hold the preview to the same sanitizer as a save, so
        // preview cannot become a way to render unfiltered HTML.
        var previewButton = document.querySelector('[data-cc-preview]');

        if (!previewButton) { return; }

        // Summernote's API is jQuery's, and this is the ONE place on this form
        // that genuinely needs it - to force the hidden source field up to date
        // before it is read. Summernote keeps it in step on a short debounce, so
        // clicking Preview immediately after typing could otherwise copy a value a
        // few characters behind the editor. Writing the editor's own content back
        // through the documented setter closes that window, and it is the only
        // thing read from a field the lecturer cannot see.
        //
        // Guarded rather than assumed: without jQuery the preview still builds and
        // posts the form, it simply may not pick up the last few keystrokes. That
        // is a far better failure than taking the whole page's script down with an
        // undefined `$`, which is what this block used to do.
        var $body = (window.jQuery) ? window.jQuery('#cc-body') : null;

        previewButton.addEventListener('click', function () {
            var lessonForm = document.getElementById('cc-lesson-form');
            var action = lessonForm.getAttribute('action');

            if (!action.match(/\/lessons\/\d+$/)) {
                // New lesson: preview only exists for a saved lesson.
                window.alert('Save the lesson as a draft first, then you can preview it exactly as a student will read it.');
                return;
            }

            if ($body && typeof $body.summernote === 'function') {
                $body.summernote('code', $body.summernote('code'));
            }

            var previewForm = document.createElement('form');
            previewForm.method = 'POST';
            previewForm.action = action.replace(/\/lessons\/(\d+)$/, '/lessons/$1/preview');

            var token = document.querySelector('meta[name="csrf-token"]');
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_token';
            hidden.value = token ? token.getAttribute('content') : '';
            previewForm.appendChild(hidden);

            var draftFlag = document.createElement('input');
            draftFlag.type = 'hidden';
            draftFlag.name = 'preview_draft';
            draftFlag.value = '1';
            previewForm.appendChild(draftFlag);

            var fields = ['title', 'summary', 'learning_objectives', 'estimated_minutes', 'body', 'course_offering_module_id', 'status', 'released_at', 'completion_rule'];
            fields.forEach(function (name) {
                var field = lessonForm.querySelector('[name="' + name + '"]');
                if (!field || !field.value) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = field.value;
                previewForm.appendChild(input);
            });

            document.body.appendChild(previewForm);
            previewForm.submit();
        });
});
</script>
@endsection
