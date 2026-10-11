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
        &middot; <span class="text-muted">Everything stays private to you until you publish it.</span>
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following before saving:</strong>
        <ul class="mb-0 mt-1">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
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

<form method="POST" id="cc-lesson-form"
      data-cc-draft-key="{{ $draftKey }}"
      @if($isEdit) action="{{ route('teacher.course_offerings.content.lessons.update', [$offering->id, $lesson->id]) }}" @else action="{{ route('teacher.course_offerings.content.lessons.store', [$offering->id]) }}" @endif>
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-3">
        {{-- ── The document ────────────────────────────────────────── --}}
        <div class="col-12 col-lg-8">
            <div class="mb-3">
                <label for="cc-title" class="form-label">Lesson title</label>
                <input type="text" class="form-control form-control-lg @error('title') is-invalid @enderror"
                       id="cc-title" name="title" maxlength="191" required
                       placeholder="e.g. Fractions, Ratios &amp; Percentages"
                       value="{{ old('title', $lesson->title) }}">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                          placeholder="By the end of this lesson the student should be able to:&#10;&#8226; Convert a fraction to a decimal&#10;&#8226; Explain why a denominator of 100 makes a percentage"
                          data-cc-dirty>{{ old('learning_objectives', $lesson->learning_objectives) }}</textarea>
                @error('learning_objectives')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    One per line, or separated by <kbd>;</kbd>. These are shown to the student before the lesson content.
                </div>
            </div>

            {{-- ── The rich text editor ────────────────────────────────
                 A plain textarea is NOT the authoring experience. This is a
                 document editor with headings, formatting, lists, alignment,
                 links, tables, images, quotations, sub/superscript, undo/redo
                 and Word-paste handling. --}}
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
            </div>
        </div>

        {{-- ── Settings ────────────────────────────────────────────── --}}
        <div class="col-12 col-lg-4">
            <div class="border rounded p-3 mb-3">
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
                           value="{{ old('released_at', $lesson->released_at?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">
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

            {{-- ── Attachments ───────────────────────────────────────── --}}
            @if($isEdit)
                <div class="border rounded p-3">
                    <h6 class="mb-2">Resources &amp; attachments</h6>
                    <p class="small text-muted">
                        Files are stored outside the public web folder and are only reachable by students
                        registered for this Course Offering.
                    </p>

                    @php $existing = $lesson->resources; @endphp
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
                        <div class="form-text mb-2">Up to 20 MB. For anything larger, add a link instead.</div>
                        <button type="submit" class="btn btn-sm btn-outline-primary" form="cc-resource-form"
                                name="type" value="file">Upload file</button>
                    </div>
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
        </div>
    </div>
</form>

{{-- A separate form so an upload is a normal multipart POST rather than a
     nested form inside the lesson form (which HTML does not allow). --}}
@if($isEdit)
    <form id="cc-resource-form" method="POST" enctype="multipart/form-data" class="d-none"
          action="{{ route('teacher.course_offerings.content.resources.store', [$offering->id, $lesson->id]) }}">
        @csrf
    </form>
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
     */
    .cc-editor-shell > textarea#cc-body,
    .cc-editor-shell textarea[name="body"] {
        display: none;
    }
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
// rich text: unsaved-work protection and the preview hand-off.
        // ══ Dirty-form protection ═════════════════════════════════════════
        // Three independent layers, because each covers a case the others miss.
        var form = document.getElementById('cc-lesson-form');
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
        document.querySelector('[data-cc-preview]').addEventListener('click', function () {
            // Force the hidden source field up to date before it is read.
            // Summernote keeps it in step on a short debounce, so clicking
            // Preview immediately after typing could otherwise copy a value a
            // few characters behind the editor. Writing the editor's own content
            // back through the documented setter closes that window, and it is
            // the only thing read from a field the lecturer cannot see.
            $body.summernote('code', $body.summernote('code'));
            var previewForm = document.createElement('form');
            previewForm.method = 'POST';
            previewForm.action = $body.closest('form').getAttribute('action')
                .replace(/\/lessons\/(\d+)$/, '/lessons/$1/preview');
            if (!$body.closest('form').getAttribute('action').match(/\/lessons\/(\d+)$/)) {
                // New lesson: preview only exists for a saved lesson.
                window.alert('Save the lesson as a draft first, then you can preview it exactly as a student will read it.');
                return;
            }
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
                var field = $body.closest('form').querySelector('[name="' + name + '"]');
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
});
</script>
@endsection
