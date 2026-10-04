@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * The full-page assignment authoring screen.
     *
     * A FULL PAGE, deliberately. An assignment is a document - instructions,
     * objectives, marks, a release moment, a deadline, a submission mode and
     * handouts - and a document does not belong in a modal. A dialog scrolls
     * internally, so the lecturer writes into a moving target and the Save button
     * is somewhere they have to hunt for.
     *
     * THE SOURCE FIELD IS HIDDEN, NOT REMOVED
     *
     * Summernote does not hide the textarea it is initialised on - that is the
     * integrator's job. `display: none` leaves the field in the DOM and still
     * submitted (it is not `disabled`), and Summernote keeps writing the editor's
     * markup into it, so submission, the sanitizer and draft recovery all read
     * the value the editor holds. The editor keeps the label's accessible name.
     *
     * UNSAVED WORK
     * A localStorage copy plus a beforeunload guard, the same two-layer approach
     * Course Content uses. The recovered copy is OFFERED, never applied silently.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
    $isEdit = $mode === 'edit';
    $draftKey = 'piie-assignment-draft-' . $offering->id . '-' . ($assignment->id ?? 'new');
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ $isEdit ? route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) : route('teacher.course_offerings.assignments.index', $offering->id) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back</a>
    <h4 class="mb-1">{{ $isEdit ? 'Edit assignment' : 'New assignment' }} &mdash; {{ $unitName }}</h4>
    <p class="text-muted mb-0 small">
        Saving keeps it a {{ $isEdit ? $assignment->displayStatusLabel() : 'Draft' }}. Students see nothing until you publish or schedule it.
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following:</strong>
        <ul class="mb-0 mt-1">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

<div class="alert alert-info d-none" id="as-recovery" role="status">
    <strong>Unsaved work was found on this device.</strong>
    A copy of this assignment was recovered from your browser. Review it before using it &mdash; it may be
    older than what you have already saved.
    <div class="mt-2 d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-primary" data-as-recovery="restore">Use the recovered copy</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-as-recovery="discard">Discard it</button>
    </div>
</div>

<form method="POST" id="as-form" data-as-draft-key="{{ $draftKey }}"
      @if($isEdit) action="{{ route('teacher.course_offerings.assignments.update', [$offering->id, $assignment->id]) }}" @else action="{{ route('teacher.course_offerings.assignments.store', $offering->id) }}" @endif>
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="mb-3">
                <label for="as-title" class="form-label">Assignment title</label>
                <input type="text" class="form-control form-control-lg @error('title') is-invalid @enderror"
                       id="as-title" name="title" maxlength="191" required data-as-dirty
                       placeholder="e.g. Assignment 1 — Business Ratios"
                       value="{{ old('title', $assignment->title) }}">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                {{-- The label names the EDITOR, not the hidden source field. `for`
                     still points at the textarea so the markup stays honest about
                     what carries the value, and the editable region takes the same
                     accessible name in JS once Summernote has built it. --}}
                <label for="as-instructions" id="as-instructions-label" class="form-label">Instructions</label>
                <div class="as-editor-shell">
                    <textarea id="as-instructions" name="instructions" data-as-dirty>{{ old('instructions', $assignment->instructions) }}</textarea>
                </div>
                @error('instructions')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="form-text">
                    What the student must do. Use the toolbar for headings, numbered lists, tables, images and
                    quotations. This is required before publishing.
                </div>
            </div>

            <div class="mb-3">
                <label for="as-objectives" class="form-label">Learning objectives <span class="text-muted fw-normal">(optional)</span></label>
                <textarea class="form-control @error('learning_objectives') is-invalid @enderror"
                          id="as-objectives" name="learning_objectives" rows="3" maxlength="5000" data-as-dirty
                          placeholder="By the end of this assignment the student should be able to:&#10;&#8226; Calculate a ratio from two quantities&#10;&#8226; Explain what a ratio does and does not tell you"
                          >{{ old('learning_objectives', $assignment->learning_objectives) }}</textarea>
                @error('learning_objectives')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">One per line, or separated by <kbd>;</kbd>. Shown to the student before the instructions.</div>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
                <button type="submit" name="status" value="draft" class="btn btn-outline-secondary" data-as-unsaved-ok>
                    Save Draft
                </button>
                <button type="button" class="btn btn-outline-primary" data-as-preview>Preview</button>
                <button type="submit" name="status" value="published" class="btn btn-primary" data-as-unsaved-ok>
                    Publish now
                </button>
                @if($isEdit && $assignment->status === 'draft')
                    <button type="submit" name="status" value="scheduled" class="btn btn-outline-primary" data-as-unsaved-ok>
                        Save and schedule
                    </button>
                @endif
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="border rounded p-3 mb-3" data-testid="as-module-panel">
                <h6 class="mb-3">Where this task belongs</h6>

                {{-- OPTIONAL. "Not attached to a module" is first and is the default,
                     because the association is optional in both directions: a module
                     may have no task, and most tasks are not module-scoped. A module
                     with no task is a normal state, not something to correct. --}}
                <div class="mb-3">
                    <label for="as-module" class="form-label">Module / Chapter</label>
                    <select class="form-select" id="as-module" name="course_offering_module_id">
                        <option value="">Not attached to a module</option>
                        @foreach($modules as $module)
                            <option value="{{ $module->id }}"
                                @selected((int) old('course_offering_module_id', $assignment->course_offering_module_id ?? null) === (int) $module->id)>
                                {{ $module->sequence ? $module->sequence.'. ' : '' }}{{ $module->title }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Only this Course Offering&rsquo;s modules are listed. A module needs no task.
                    </div>
                </div>

                {{-- The role, and its consequence, stated plainly. Optional is the
                     default because it is the safe state: blocking a student's
                     progression must be chosen, never inherited. --}}
                <div class="mb-3">
                    <label for="as-role" class="form-label">Is this task required?</label>
                    <select class="form-select" id="as-role" name="requirement_role"
                            data-as-role-toggle>
                        <option value="optional"
                            @selected(old('requirement_role', $assignment->requirement_role ?? 'optional') === 'optional')>
                            Optional &mdash; supplementary practice
                        </option>
                        <option value="required"
                            @selected(old('requirement_role', $assignment->requirement_role ?? 'optional') === 'required')>
                            Required &mdash; must be done for the module to count as complete
                        </option>
                    </select>
                    <div class="form-text">
                        An optional task never blocks anyone, in any state. A required task
                        has to be satisfied before the module counts as fully complete.
                    </div>
                </div>

                {{-- Shown ONLY for a required task. An optional task is never gated,
                     so a rule control for it would suggest a setting that does
                     nothing. Hidden rather than disabled, deliberately. --}}
                <div class="mb-3 d-none" data-as-rule-wrap>
                    <label for="as-rule" class="form-label">What counts as done</label>
                    <select class="form-select" id="as-rule" name="completion_rule">
                        @foreach($implementedRules as $rule)
                            <option value="{{ $rule }}"
                                @selected(old('completion_rule', $assignment->completion_rule ?? 'submission') === $rule)>
                                {{ $rule === 'submission' ? 'Once the student has submitted their work' : 'Once the work is marked and the result returned' }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Only rules PIIE can actually check are offered. A required task whose
                        rule cannot be evaluated would be impossible to complete.
                    </div>
                </div>

                {{-- The evidence a student may hand in. A SET, because a task may ask
                     for a photograph OR a recording, which a single select cannot
                     express. No recording control anywhere: audio and video mean a
                     file the student attaches, and nothing here captures anything. --}}
                <div class="mb-0">
                    <label class="form-label d-block">What the student hands in</label>
                    @php $chosen = old('submission_kinds', $assignment->acceptedEvidenceKinds()); @endphp
                    @foreach($evidenceKinds as $kind)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="submission_kinds[]"
                                   value="{{ $kind }}" id="as-kind-{{ $kind }}"
                                   @checked(in_array($kind, (array) $chosen, true))>
                            <label class="form-check-label" for="as-kind-{{ $kind }}">
                                {{ \App\Models\AssignmentSubmissionItem::KIND_LABELS[$kind] }}
                            </label>
                        </div>
                    @endforeach
                    <div class="form-text mt-1">
                        Tick every kind that will be accepted. A submission missing one of
                        these is refused rather than half-accepted.
                    </div>
                </div>
            </div>

            <div class="border rounded p-3 mb-3">
                <h6 class="mb-3">Requirements</h6>

                <div class="mb-3">
                    <label for="as-marks" class="form-label">Total marks available</label>
                    <input type="number" class="form-control @error('max_marks') is-invalid @enderror"
                           id="as-marks" name="max_marks" min="0" max="100000" step="1" required
                           value="{{ old('max_marks', $assignment->max_marks ?? 100) }}">
                    @error('max_marks')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">A mark above this is refused when you grade.</div>
                </div>

                <div class="mb-3">
                    <label for="as-type" class="form-label">What the student submits</label>
                    <select class="form-select" id="as-type" name="submission_type">
                        @foreach(\App\Models\Assignment::HEI_SUBMISSION_TYPES as $type)
                            <option value="{{ $type }}" @selected(old('submission_type', $assignment->submission_type) === $type)>
                                {{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$type] }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Set the expectation explicitly, and a submission that misses it is refused rather than half-accepted.</div>
                </div>

                <div class="mb-3">
                    <label for="as-attempts" class="form-label">Allowed attempts</label>
                    <input type="number" class="form-control" id="as-attempts" name="allowed_attempts"
                           min="1" max="99" step="1"
                           value="{{ old('allowed_attempts', $assignment->allowed_attempts ?? 1) }}">
                    <div class="form-text">1 means a single hand-in. More than 1 lets a student resubmit, and each attempt is kept.</div>
                </div>

                <div class="mb-0">
                    <label for="as-late" class="form-label">After the due date</label>
                    <select class="form-select" id="as-late" name="late_policy">
                        <option value="allow" @selected(old('late_policy', $assignment->late_policy) === 'allow')>
                            Accept, and record it as late
                        </option>
                        <option value="block" @selected(old('late_policy', $assignment->late_policy) === 'block')>
                            Refuse new submissions
                        </option>
                    </select>
                    <div class="form-text">
                        The final closing time below stops new work either way. A late submission is decided
                        from the recorded submission time, never from the student's own device clock.
                    </div>
                </div>
            </div>

            <div class="border rounded p-3 mb-3">
                <h6 class="mb-3">Dates and times</h6>
                <div class="mb-3">
                    <label for="as-released" class="form-label">Opens <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="datetime-local" class="form-control" id="as-released" name="released_at" data-as-dirty
                           value="{{ old('released_at', $assignment->released_at?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">Leave empty to open as soon as it is published. A future time shows it as <em>Scheduled</em>.</div>
                </div>
                <div class="mb-3">
                    <label for="as-due" class="form-label">Due</label>
                    <input type="datetime-local" class="form-control @error('due_date') is-invalid @enderror"
                           id="as-due" name="due_date" data-as-dirty
                           value="{{ old('due_date', $assignment->due_date?->format('Y-m-d\TH:i')) }}">
                    @error('due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Required before publishing. Each student reads it in their own timezone.</div>
                </div>
                <div class="mb-0">
                    <label for="as-closes" class="form-label">Final closing time <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="datetime-local" class="form-control" id="as-closes" name="closes_at" data-as-dirty
                           value="{{ old('closes_at', $assignment->closes_at?->format('Y-m-d\TH:i')) }}">
                    <div class="form-text">After this, no new submission is accepted whatever the late policy says. Everything already handed in is kept.</div>
                </div>
            </div>

            @if($isEdit)
                <div class="border rounded p-3">
                    <h6 class="mb-2">Resources</h6>
                    <p class="small text-muted">
                        Files are stored outside the public web folder and are only reachable by students
                        registered for this Course Offering.
                    </p>

                    @if($assignment->resources->isNotEmpty())
                        <ul class="list-group list-group-flush mb-3">
                            @foreach($assignment->resources as $resource)
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
                                               href="{{ route('teacher.course_offerings.assignments.resources.file', $resource->id) }}">Open</a>
                                        @elseif($resource->hasUsableLink())
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ $resource->link_url }}"
                                               target="_blank" rel="noopener noreferrer">Open</a>
                                        @endif
                                        <button type="submit" form="as-del-{{ $resource->id }}"
                                                class="btn btn-sm btn-outline-danger"
                                                aria-label="Remove {{ $resource->displayName() }}">Remove</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-muted">No resources yet.</p>
                    @endif

                    <div class="border-top pt-3">
                        <label for="as-res-link" class="form-label small fw-semibold">Add a link</label>
                        <input type="url" class="form-control form-control-sm mb-2" id="as-res-link"
                               placeholder="https://example.org/dataset" form="as-resource-form" name="link_url">
                        <label for="as-res-title" class="form-label small fw-semibold">Link label <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm mb-2" id="as-res-title"
                               placeholder="e.g. Dataset for question 4" form="as-resource-form" name="title">
                        <button type="submit" class="btn btn-sm btn-outline-primary" form="as-resource-form" name="type" value="link">
                            Add link
                        </button>
                    </div>

                    <div class="border-top pt-3 mt-3">
                        <label for="as-res-file" class="form-label small fw-semibold">Upload a file</label>
                        <input type="file" class="form-control form-control-sm mb-2" id="as-res-file"
                               form="as-resource-form" name="file">
                        <div class="form-text mb-2">Up to 20 MB. Documents and images only.</div>
                        <button type="submit" class="btn btn-sm btn-outline-primary" form="as-resource-form" name="type" value="file">
                            Upload file
                        </button>
                    </div>
                </div>
            @else
                <div class="border rounded p-3">
                    <h6 class="mb-2">Resources</h6>
                    <p class="small text-muted mb-0">
                        Save the assignment first, then add links and files. That way a handout is always
                        attached to an assignment that exists.
                    </p>
                </div>
            @endif
        </div>
    </div>
</form>

@if($isEdit)
    {{-- A separate form, because HTML forbids nesting a form inside another. --}}
    <form id="as-resource-form" method="POST" enctype="multipart/form-data" class="d-none"
          action="{{ route('teacher.course_offerings.assignments.resources.store', [$offering->id, $assignment->id]) }}">
        @csrf
    </form>
    @foreach($assignment->resources as $resource)
        <form id="as-del-{{ $resource->id }}" method="POST" class="d-none"
              action="{{ route('teacher.course_offerings.assignments.resources.destroy', $resource->id) }}"
              onsubmit="return confirm('Remove this resource from the assignment?')">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
        </form>
    @endforeach
@endif

<style>
    /*
     * THE SOURCE FIELD IS HIDDEN, NOT REMOVED. `display: none` is not
     * `disabled`, so it is still submitted, and Summernote keeps writing the
     * editor's markup into it on every keystroke - which is what submission, the
     * server-side sanitizer and draft recovery all read. The editor takes the
     * label's accessible name instead.
     */
    .as-editor-shell > textarea#as-instructions,
    .as-editor-shell textarea[name="instructions"] { display: none; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // The completion rule only matters for a REQUIRED task. Hiding it otherwise is
    // not merely tidiness: a visible-but-inert control suggests a setting that has
    // an effect, and a lecturer would reasonably believe it does.
    var role = document.querySelector('[data-as-role-toggle]');
    var ruleWrap = document.querySelector('[data-as-rule-wrap]');

    if (role && ruleWrap) {
        var syncRule = function () {
            ruleWrap.classList.toggle('d-none', role.value !== 'required');
        };
        role.addEventListener('change', syncRule);
        syncRule();
    }
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.summernote === 'undefined') {
        var shell = document.querySelector('.as-editor-shell');
        if (shell) {
            shell.innerHTML = '<div class="alert alert-danger m-3" role="alert">' +
                '<strong>The editor could not be loaded.</strong> Your work has not been lost, but please ' +
                'reload this page before writing.</div>';
        }
        return;
    }

    jQuery(function ($) {
        var $body = $('#as-instructions');

        // The editor is the authoring surface, so the label's `for` no longer
        // points at something reachable. Hand the same name to the editable
        // region Summernote builds.
        var nameTheEditor = function () {
            var editable = document.querySelector('.as-editor-shell .note-editable');
            if (editable) { editable.setAttribute('aria-labelledby', 'as-instructions-label'); }
        };

        var options = {
            height: 380,
            placeholder: 'What must the student do? Use the toolbar for headings, numbered lists, tables and images.',
            disableDragAndDrop: true,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear']],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['insert', ['link', 'picture', 'fileLink', 'superscript', 'subscript']],
                ['alignment', ['left', 'center', 'right', 'justify']],
                ['table', ['table']],
                ['quote', ['blockquote']],
                ['list', ['outdent', 'indent']],
                ['undo', ['undo']],
                ['redo', ['redo']],
                ['fullscreen', ['fullscreen']]
            ],
            popover: { image: [], link: [] },
            callbacks: { onInit: function () { nameTheEditor(); } }
        };

        $body.summernote(options);

        // Summernote Lite ships no table dialog of its own. Built through its own
        // API rather than beside it, so the editor still owns the document.
        $.summernote.plugins.table = function (context) {
            var ui = $.summernote.ui;
            context.addButton('table', 'table', {
                tooltip: 'Insert a table',
                icon: '<i class="fa fa-table"></i>',
                click: function () {
                    var html = ui.dialog({
                        title: 'Insert table',
                        callback: function (dialogHtml) {
                            var $d = $(dialogHtml);
                            var rows = parseInt($d.find('input.cc-rows').val(), 10) || 2;
                            var cols = parseInt($d.find('input.cc-cols').val(), 10) || 2;
                            var out = '<table><thead><tr>';
                            for (var c = 0; c < cols; c++) { out += '<th scope="col">Heading ' + (c + 1) + '</th>'; }
                            out += '</tr></thead><tbody>';
                            for (var r = 0; r < rows; r++) {
                                out += '<tr>';
                                for (var k = 0; k < cols; k++) { out += '<td>Cell</td>'; }
                                out += '</tr>';
                            }
                            return out + '</tbody></table>';
                        }
                    });
                    if (html) { context.invoke('insertNode', $.parseHTML(html)[0]); }
                }
            });
        };

        var subSuper = function (command) {
            return function (context) {
                context.addButton(command, command, {
                    tooltip: command === 'superscript' ? 'Superscript' : 'Subscript',
                    icon: command === 'superscript'
                        ? '<i class="fa fa-superscript"></i>'
                        : '<i class="fa fa-subscript"></i>',
                    click: function () { document.execCommand(command, false, null); }
                });
            };
        };
        $.summernote.plugins.superscript = subSuper('superscript');
        $.summernote.plugins.subscript = subSuper('subscript');

        // Destroy and rebuild so the two custom buttons exist. Documented
        // Summernote practice, and safe: the body is synced into the textarea on
        // destroy, so the content survives it.
        $body.summernote('destroy');
        $body.summernote(options);

        // ── Dirty-form protection and local recovery ─────────────────────
        var form = document.getElementById('as-form');
        var draftKey = form.getAttribute('data-as-draft-key');
        var dirty = false;
        var saveTimer = null;

        function snapshot() {
            try {
                window.localStorage.setItem(draftKey, JSON.stringify({
                    title: document.getElementById('as-title').value,
                    objectives: document.getElementById('as-objectives').value,
                    marks: document.getElementById('as-marks').value,
                    due: document.getElementById('as-due').value,
                    body: $body.summernote('code'),
                    saved_at: new Date().toISOString()
                }));
            } catch (e) { /* private browsing: the other layers still apply */ }
        }

        var recovery = null;
        try {
            var raw = window.localStorage.getItem(draftKey);
            if (raw) { recovery = JSON.parse(raw); }
        } catch (e) { recovery = null; }

        var box = document.getElementById('as-recovery');
        if (recovery && box) {
            var when = recovery.saved_at ? new Date(recovery.saved_at) : null;
            box.querySelector('strong').textContent = 'Unsaved work was found on this device'
                + (when ? ' (from ' + when.toLocaleString() + ')' : '') + '.';
            box.classList.remove('d-none');
            box.addEventListener('click', function (event) {
                var action = event.target.getAttribute('data-as-recovery');
                if (!action) { return; }
                if (action === 'restore' && recovery) {
                    document.getElementById('as-title').value = recovery.title || '';
                    document.getElementById('as-objectives').value = recovery.objectives || '';
                    document.getElementById('as-marks').value = recovery.marks || '';
                    document.getElementById('as-due').value = recovery.due || '';
                    $body.summernote('code', recovery.body || '');
                    dirty = true;
                }
                box.classList.add('d-none');
            });
        }

        form.addEventListener('input', function () {
            dirty = true;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(snapshot, 1500);
        });
        form.addEventListener('change', function () {
            dirty = true;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(snapshot, 300);
        });

        // 1. Leaving the page. The browser shows its own generic wording, which is
        //    deliberate: a custom dialog here is not supported.
        window.addEventListener('beforeunload', function (event) {
            if (!dirty) { return; }
            event.preventDefault();
            event.returnValue = '';
        });

        // 2. Saving is NOT leaving. Buttons that save opt out explicitly, so a
        //    legitimate save never raises a prompt.
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && submitter.hasAttribute('data-as-unsaved-ok')) {
                dirty = false;
                try { window.localStorage.removeItem(draftKey); } catch (e) {}
                return;
            }
            if (dirty && !window.confirm('This action will discard unsaved changes. Continue?')) {
                event.preventDefault();
            }
        });

        // 3. Preview POSTs the current form, so it shows unsaved work. The sync
        //    is forced first, because the copy below reads the hidden field and
        //    Summernote debounces its own writes.
        document.querySelector('[data-as-preview]').addEventListener('click', function () {
            $body.summernote('code', $body.summernote('code'));

            var target = $body.closest('form').getAttribute('action')
                .replace(/\/assignments\/(\d+)$/, '/assignments/$1/preview');
            if (!/\/assignments\/(\d+)$/.test($body.closest('form').getAttribute('action'))) {
                window.alert('Save the assignment as a draft first, then you can preview it exactly as a student will read it.');
                return;
            }

            var previewForm = document.createElement('form');
            previewForm.method = 'POST';
            previewForm.action = target;

            var token = document.querySelector('meta[name="csrf-token"]');
            var fields = { _token: token ? token.getAttribute('content') : '', preview_draft: '1',
                title: '', instructions: '', learning_objectives: '', max_marks: '',
                submission_type: '', allowed_attempts: '', late_policy: '',
                released_at: '', due_date: '', closes_at: '' };

            Object.keys(fields).forEach(function (name) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                var field = $body.closest('form').querySelector('[name="' + name + '"]');
                input.value = field ? field.value : fields[name];
                previewForm.appendChild(input);
            });

            document.body.appendChild(previewForm);
            previewForm.submit();
        });
    });
});
</script>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
