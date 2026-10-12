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
@php
    // ONLY THE ASSIGNMENT'S OWN ERRORS. Attachment errors are in their own bag
    // and render beside the attachment control, so including them here made a
    // saved assignment appear to have failed.
    $assignmentErrors = $errors->getBag('default');
@endphp
@if($assignmentErrors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following:</strong>
        <ul class="mb-0 mt-1">@foreach($assignmentErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
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

{{-- ══ A TWO-STEP AUTHORING FLOW ═══════════════════════════════════════════
     SETTINGS FIRST, THEN THE WORK. Identical to the lesson form's, and for the
     same reason: an assignment is a document with a dozen settings on it, and the
     two or three that make a good brief are buried under marks, attempts, retry
     policy and release dates.

     ONE FORM, ONE SAVE. Both steps live inside this form, so a submit still writes
     exactly the assignment it always wrote.

     `novalidate` IS LOAD-BEARING. A field on a hidden step is still submitted -
     `hidden` removes it from the display, not from the form data - but it is no
     longer constraint-validated, so the browser will happily post an assignment
     with no title while the lecturer is looking at step 2. Native validation is
     therefore off and step 1 is checked explicitly, with the reason shown.
--}}
<form method="POST" id="as-form" data-as-draft-key="{{ $draftKey }}" novalidate
      @if($isEdit) action="{{ route('teacher.course_offerings.assignments.update', [$offering->id, $assignment->id]) }}" @else action="{{ route('teacher.course_offerings.assignments.store', $offering->id) }}" @endif>
    @csrf
    @if($isEdit) @method('PUT') @endif

    <ul class="nav nav-pills mb-4 gap-2" id="as-steps" role="tablist">
        <li class="nav-item" role="presentation">
            <button type="button" class="nav-link active" data-as-step-tab="1" role="tab"
                    aria-selected="true" aria-controls="as-step-1" id="as-tab-1">
                1. Settings
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button type="button" class="nav-link" data-as-step-tab="2" role="tab"
                    aria-selected="false" aria-controls="as-step-2" id="as-tab-2">
                2. The work
            </button>
        </li>
    </ul>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="mb-3" data-as-step="1">
                <label for="as-title" class="form-label">Assignment title</label>
                <input type="text" class="form-control form-control-lg @error('title') is-invalid @enderror"
                       id="as-title" name="title" maxlength="191" required data-as-dirty
                       placeholder="e.g. Assignment 1 — Business Ratios"
                       value="{{ old('title', $assignment->title) }}">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                {{-- The CLIENT-SIDE reason, which `@error` cannot produce: this form
                     carries `novalidate` so the step controller can own the check, and
                     that is the whole point - the lecturer is taken to the field and
                     told what is wrong, rather than being allowed to walk to step 2
                     and post an assignment with no title. --}}
                <div class="invalid-feedback d-block" id="as-title-note"></div>
            </div>

            <div class="mb-3" data-as-step="2">
                {{-- THE SHARED EDITOR, NOT A HAND-ROLLED ONE.

                     This view carried its own copy of the Summernote bootstrap and
                     its own `.as-editor-shell`. When the layout stopped loading
                     Summernote globally, `$.fn.summernote` came back undefined and
                     the bootstrap REPLACED THE SHELL WITH THE FAILURE PANEL - so a
                     lecturer could not write a single word of assignment
                     instructions. The form looked complete; the one field the
                     screen exists for was a red notice telling them to reload.

                     Identical fault to the lesson form, fixed the same way: the
                     component loads the vendor bundle itself, so there is no
                     bootstrap here to go stale. --}}
                <x-academic-editor
                    name="instructions"
                    id="as-instructions"
                    label="Instructions"
                    :value="old('instructions', $assignment->instructions)"
                    help="What the student must do. Use the toolbar for headings, numbered lists, tables, images and quotations. This is required before publishing."
                    :height="340"
                    testid="as-instructions-editor" />
            </div>

            <div class="mb-3" data-as-step="2">
                <label for="as-objectives" class="form-label">Learning objectives <span class="text-muted fw-normal">(optional)</span></label>
                <textarea class="form-control @error('learning_objectives') is-invalid @enderror"
                          id="as-objectives" name="learning_objectives" rows="3" maxlength="5000" data-as-dirty
                          placeholder="By the end of this assignment the student should be able to:&#10;&#8226; Calculate a ratio from two quantities&#10;&#8226; Explain what a ratio does and does not tell you"
                          >{{ old('learning_objectives', $assignment->learning_objectives) }}</textarea>
                @error('learning_objectives')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">One per line, or separated by <kbd>;</kbd>. Shown to the student before the instructions.</div>
            </div>

            {{-- THE ACTIONS ARE ON BOTH STEPS, ALWAYS.

                 Deliberately unmarked. A lecturer who has just typed the title and
                 the marks on step 1 must be able to press Save Draft there, rather
                 than discovering they have to move to step 2 to save what they have
                 already written - and then arriving on step 2 with the Save button
                 looking like it belongs to the instructions.

                 The Continue and Back buttons ARE marked, because they mean nothing
                 on the step they do not lead from. --}}
            <div class="d-flex flex-wrap gap-2 mb-4">
                <button type="button" class="btn btn-primary" data-as-step-next="2" data-as-step="1">
                    Continue to the work
                </button>
                <button type="button" class="btn btn-link" data-as-step-next="1" data-as-step="2">
                    Back to settings
                </button>

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

        <div class="col-12 col-lg-4" data-as-step="1">
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

                <div class="mb-3">
                    <label for="as-pass" class="form-label">Pass mark</label>
                    <input type="number" class="form-control @error('pass_mark') is-invalid @enderror"
                           id="as-pass" name="pass_mark" min="0" max="100000" step="0.01"
                           value="{{ old('pass_mark', $assignment->pass_mark) }}"
                           placeholder="No pass mark">
                    @error('pass_mark')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        Leave empty and any returned mark counts. Set a mark and the student has to reach it
                        before this is treated as passed &mdash; leave it blank and a returned mark of zero
                        passes, exactly as it does today.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="as-retry" class="form-label">After every attempt is used</label>
                    <select class="form-select" id="as-retry" name="retry_policy">
                        <option value="approval" @selected(old('retry_policy', $assignment->retry_policy ?? \App\Models\Assignment::RETRY_APPROVAL) === 'approval')>
                            Pause, and ask me to authorise another attempt
                        </option>
                        <option value="automatic" @selected(old('retry_policy', $assignment->retry_policy ?? \App\Models\Assignment::RETRY_APPROVAL) === 'automatic')>
                            Let the student try again automatically
                        </option>
                    </select>
                    <div class="form-text">
                        Applies once the student's attempts in this cycle are spent.
                        &ldquo;Ask me&rdquo; is the safer default: it stops the attempt limit being
                        worked around by waiting.
                    </div>
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

            </div>{{-- /the settings column --}}

        {{-- HANDOUTS LIVE ON THE WORK STEP, NOT THE SETTINGS STEP.

             They are the material the student works FROM - a dataset, a case study,
             a worksheet - so they belong beside the instructions they support. And
             for a file-only assignment they ARE the assignment, in which case a
             lecturer who never leaves step 1 would have no way to reach the upload.

             They are a separate column rather than a `data-as-step` on the panel
             itself: a nested step marker would be hidden by its own parent's step,
             so the handouts would vanish on the step that exists to show them. --}}
        <div class="col-12 col-lg-4" data-as-step="2">
            {{-- THE PANEL IS ALWAYS SHOWN.

                 Only the EXISTING-HANDOUT LIST is edit-only, because a new
                 assignment has no handouts to list yet - but it can still be GIVEN
                 one immediately. That distinction is the whole point: the upload
                 controls used to sit inside the same `@if ($isEdit)` as the list,
                 so an assignment whose material is a dataset or a case study
                 could not be started at all without first writing filler
                 instructions to unlock the button. --}}
            <div class="border rounded p-3">
                <h6 class="mb-2">Resources</h6>
                <p class="small text-muted">
                    @if($isEdit)
                        Files are stored outside the public web folder and are only reachable by students
                        registered for this Course Offering.
                    @else
                        An assignment can be <strong>only files</strong> &mdash; a dataset, a case study,
                        a worksheet. Uploading here saves a draft with your title and brief so far, then
                        attaches the handout. Nothing is published until you say so.
                    @endif
                </p>

                @php $resourceErrors = $errors->getBag(\App\Http\Controllers\TeacherCourseOfferingAssignmentController::RESOURCE_ERROR_BAG); @endphp
                @if($resourceErrors->any())
                    <div class="alert alert-danger" role="alert">
                        <strong>The handout was not added:</strong>
                        <ul class="mb-0 mt-1">
                            @foreach($resourceErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                @if($isEdit && $assignment->resources->isNotEmpty())
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
        </div>
    </div>
</form>

{{-- A separate form, because HTML forbids nesting a form inside another. The
     action depends on whether the assignment exists yet: a new one posts to the
     handler that creates a DRAFT and attaches in a single request. --}}
<form id="as-resource-form" method="POST" enctype="multipart/form-data" class="d-none"
      action="{{ $isEdit
          ? route('teacher.course_offerings.assignments.resources.store', [$offering->id, $assignment->id])
          : route('teacher.course_offerings.assignments.resources.store_new', [$offering->id]) }}">
    @csrf
    @unless($isEdit)
        {{-- The draft carries what has been typed so far, so uploading a handout
             never costs the lecturer the assignment they were writing. All
             optional: a file-only assignment simply leaves them blank. --}}
        <input type="hidden" name="title" value="{{ old('title', $assignment->title ?? '') }}">
        <input type="hidden" name="instructions" value="{{ old('instructions', $assignment->instructions ?? '') }}">
        <input type="hidden" name="learning_objectives" value="{{ old('learning_objectives', $assignment->learning_objectives ?? '') }}">
        <input type="hidden" name="max_marks" value="{{ old('max_marks', $assignment->max_marks ?? 100) }}">
        <input type="hidden" name="submission_type" value="{{ old('submission_type', $assignment->submission_type ?? 'text') }}">
        <input type="hidden" name="late_policy" value="{{ old('late_policy', $assignment->late_policy ?? 'allow') }}">
        <input type="hidden" name="allowed_attempts" value="{{ old('allowed_attempts', $assignment->allowed_attempts ?? 1) }}">
        <input type="hidden" name="due_date" value="{{ old('due_date') }}">
        <input type="hidden" name="released_at" value="{{ old('released_at') }}">
        <input type="hidden" name="closes_at" value="{{ old('closes_at') }}">
        <input type="hidden" name="course_offering_module_id" value="{{ old('course_offering_module_id') }}">
        <input type="hidden" name="requirement_role" value="{{ old('requirement_role', 'optional') }}">
        <input type="hidden" name="completion_rule" value="{{ old('completion_rule', 'submission') }}">
    @endunless
</form>

@if($isEdit)
    @foreach($assignment->resources as $resource)
        <form id="as-del-{{ $resource->id }}" method="POST" class="d-none"
              action="{{ route('teacher.course_offerings.assignments.resources.destroy', $resource->id) }}"
              onsubmit="return confirm('Remove this resource from the assignment?')">
            @csrf
            <input type="hidden" name="_method" value="DELETE">
        </form>
    @endforeach
@endif

{{-- The rule that hides the editor's source textarea is NOT here. It lived in
     this view while the editor did, and is now owned by
     `public/css/academic-editor.css` as
     `html.piie-js .piie-editor-shell > textarea.piie-editor-source`.
     Duplicating it would be a second, UNSCOPED copy that also fires without
     JavaScript - hiding the only field a no-JS lecturer could type into. --}}

<script>
// ══ THE TWO-STEP FLOW ══════════════════════════════════════════════════════
//
// Settings first, then the work, and the settings are GONE once you are writing.
// One form and one save: both steps live inside `#as-form`, so submitting still
// writes exactly the assignment it always wrote.
//
// NEITHER STEP IS `hidden` IN THE MARKUP. That is the no-JavaScript fallback: a
// lecturer whose browser blocks the script sees the whole form, which is usable,
// rather than a settings step with no way forward. Hiding is applied here, at
// runtime, so the failure mode of not running is "everything visible", never
// "trapped on step 1".
//
// MOVING FORWARD PAST SETTINGS IS CHECKED. `novalidate` on the form is
// load-bearing: a field on a hidden step is still submitted but is no longer
// constraint-validated, so without this the browser would post an assignment with
// no title while the lecturer is looking at step 2. Only the title is checked
// here - it is the one field the server will not accept as empty - and everything
// else remains the server's call, so the rules here cannot drift from them.
//
// THE ERROR ROUTING IS THE PART WORTH CARING ABOUT. A save rejected for a
// settings problem must come back to SETTINGS, not to the work step the lecturer
// was looking at when they pressed Save - otherwise they are told to fix a field
// they cannot see, which is the same class of fault as an attachment error being
// reported against the assignment.
(function () {
    var steps = Array.prototype.slice.call(document.querySelectorAll('[data-as-step]'));
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-as-step-tab]'));

    if (!steps.length) { return; }

    // Which step owns which field. Anything not listed is treated as the work,
    // which is the right default: a newly added field is more likely to be part of
    // the brief than part of the configuration.
    var SETTINGS_FIELDS = [
        'title', 'course_offering_module_id', 'requirement_role',
        'completion_rule', 'submission_kinds', 'submission_type',
        'max_marks', 'pass_mark', 'allowed_attempts', 'retry_policy',
        'late_policy', 'due_date', 'released_at', 'closes_at'
    ];

    function show(n) {
        steps.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-as-step') !== String(n);
        });

        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-as-step-tab') === String(n);
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function settingsAreAcceptable() {
        var title = document.getElementById('as-title');

        if (!title || title.value.trim() !== '') { return true; }

        // Marked invalid and FOCUSED, so the lecturer is taken to the field rather
        // than told to look for it. A refusal that does not say where is a refusal
        // that gets worked around.
        title.classList.add('is-invalid');
        title.focus();

        var note = document.getElementById('as-title-note');
        if (note) { note.textContent = 'Give the assignment a title before continuing.'; }

        return false;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-as-step-tab');

            // Moving BACK is always allowed. Moving FORWARD has to clear the one
            // check, or step 2 is reachable with nothing in it.
            if (target === '2' && !settingsAreAcceptable()) { return; }

            show(target);
        });
    });

    document.querySelectorAll('[data-as-step-next]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-as-step-next');

            if (target === '2' && !settingsAreAcceptable()) { return; }

            show(target);
        });
    });

    var titleField = document.getElementById('as-title');
    if (titleField) {
        titleField.addEventListener('input', function () {
            titleField.classList.remove('is-invalid');

            var note = document.getElementById('as-title-note');
            if (note) { note.textContent = ''; }
        });
    }

    // Land on the step that owns the first error, if the server sent one.
    var serverErrors = @json($assignmentErrors->keys());
    if (serverErrors.length) {
        var first = serverErrors[0];
        show(SETTINGS_FIELDS.indexOf(first) !== -1 ? '1' : '2');
    } else {
        // No errors: open on the step a lecturer is most likely to want. A NEW
        // assignment opens on settings, because there is nothing to write yet. An
        // existing one opens on the work, because that is where a returning
        // lecturer is going.
        show(@json($isEdit ? 2 : 1));
    }
})();

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

    // The Summernote bootstrap that used to live here was removed when this view
    // moved to the shared `x-academic-editor` component, for the same reason the
    // lesson form lost its own: the layout stopped loading Summernote globally, so
    // the local bootstrap found `$.fn.summernote` undefined and REPLACED THE SHELL
    // WITH THE FAILURE PANEL. Assignment instructions could not be written at all.
    //
    // The component loads the vendor bundle itself, which is why nothing is
    // needed here to make an editor appear.
    //
    // What remains is this form's own behaviour: the rule-visibility toggle, the
    // unsaved-work guard with local recovery, and the preview hand-off.
    var form = document.getElementById('as-form');
    if (!form) { return; }
    var draftKey = form.getAttribute('data-as-draft-key');
    var dirty = false;
    var saveTimer = null;
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

        // 3. Preview POSTs the current form, so it shows unsaved work.
        //
        //    The jQuery sync is GUARDED, not assumed. Without it the preview still
        //    builds and posts the form; it may simply miss the last few
        //    keystrokes. Taking the whole page's script down with an undefined `$`
        //    - which is what this block used to do - is a far worse failure.
        var previewButton = document.querySelector('[data-as-preview]');
        var $body = (window.jQuery) ? window.jQuery('#as-instructions') : null;

        if (!previewButton) { return; }

        previewButton.addEventListener('click', function () {
            if ($body && typeof $body.summernote === 'function') {
                $body.summernote('code', $body.summernote('code'));
            }

            var formAction = form.getAttribute('action') || '';

            if (!/\/assignments\/\d+$/.test(formAction)) {
                window.alert('Save the assignment as a draft first, then you can preview it exactly as a student will read it.');
                return;
            }

            var target = formAction.replace(/\/assignments\/(\d+)$/, '/assignments/$1/preview');

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
                var field = form.querySelector('[name="' + name + '"]');
                input.value = field ? field.value : fields[name];
                previewForm.appendChild(input);
            });

            document.body.appendChild(previewForm);
            previewForm.submit();
        });
});
</script>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
