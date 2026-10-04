@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * The lecturer's Content builder for one Course Offering.
     *
     * THE FIRST THING ON THE PAGE IS AN EXPLANATION, not an empty table.
     *
     * A blank page with a lone "+ Add Module" button reads as a broken feature,
     * so this states where the lecturer is (which Course Unit, which delivery)
     * and what the next step is, and the button sits in that sentence rather than
     * floating above it.
     *
     * STATUS IS SHOWN IN PLAIN WORDS
     * 'draft' is shown as "Draft", 'published' as "Published" or "Scheduled" if
     * its release moment has not arrived, 'archived' as "Archived". A published
     * module with a future release date is deliberately labelled "Scheduled",
     * because telling a lecturer their content is live when it is not would be
     * the single most misleading thing this page could do.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
    $unitCode = $offering->subject?->code ?? $offering->reference;
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.show', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to {{ $offering->subject?->code ?? 'Course Offering' }}
    </a>
    <h4 class="mb-1">Course Content &mdash; {{ $unitName }}</h4>
    <p class="text-muted mb-0">
        Build and organise the learning journey students will follow in {{ $unitName }}.
        <span class="d-block small mt-1">
            {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
            &middot; {{ $offering->reference }}
        </span>
    </p>
</div>

@include('teacher.course_offerings.content._surface_styles')

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following before saving:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </div>
@endif

@if(!$canManage)
    {{-- View-only. The route still authorises every write; this is a courtesy
         about state, not the security boundary. --}}
    <div class="alert alert-warning" role="status">
        <strong>You are viewing this content read-only.</strong>
        You are not currently allocated to teach this Course Offering, so the authoring
        actions are not available to you.
    </div>
@endif

{{-- ══ How this works ════════════════════════════════════════════════════ --}}
<section class="eSection-wrap mb-3" aria-labelledby="cc-how-heading">
    <h5 id="cc-how-heading" class="mb-2">How this works</h5>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">1 &middot; Add a Module</div>
                <p class="small text-muted mb-0">
                    A module is one section of the course, such as
                    <em>Foundations of Business Mathematics</em>. Modules hold ordered
                    lessons and appear to students in the order you set.
                </p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">2 &middot; Add a Lesson</div>
                <p class="small text-muted mb-0">
                    Write the lesson in the editor. Everything stays a
                    <strong>Draft</strong> until you publish it, so nothing you are
                    working on is visible to students by accident.
                </p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">3 &middot; Preview, then Publish</div>
                <p class="small text-muted mb-0">
                    Preview shows the lesson exactly as a student will read it.
                    Publish when you are ready &mdash; or set a release date to have
                    it appear on a schedule.
                </p>
            </div>
        </div>
    </div>
</section>

{{-- ══ Add module ════════════════════════════════════════════════════════ --}}
@if($canManage)
    <section class="eSection-wrap mb-4" aria-labelledby="cc-add-heading">
        <h5 id="cc-add-heading" class="mb-2">Add a Module</h5>
        <form method="POST" action="{{ route('teacher.course_offerings.content.modules.store', $offering->id) }}">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-12 col-lg-5">
                    <label for="cc-module-title" class="form-label">Module title</label>
                    <input type="text" class="form-control @error('title') is-invalid @enderror" id="cc-module-title"
                           name="title" maxlength="191" required
                           placeholder="e.g. Module 1 — Foundations of Business Mathematics"
                           value="{{ old('title') }}">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-lg-5">
                    <label for="cc-module-summary" class="form-label">What this module covers <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" class="form-control @error('summary') is-invalid @enderror" id="cc-module-summary"
                           name="summary" maxlength="2000"
                           placeholder="A short line students will see above the lessons"
                           value="{{ old('summary') }}">
                    @error('summary')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-lg-2">
                    <button type="submit" class="btn btn-primary w-100">+ Add Module</button>
                </div>
            </div>
            <p class="small text-muted mt-2 mb-0">A new module is created as a Draft. Publish it when its lessons are ready.</p>
        </form>
    </section>
@endif

{{-- ══ The modules ═══════════════════════════════════════════════════════ --}}
<section class="eSection-wrap" aria-labelledby="cc-modules-heading">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <h5 id="cc-modules-heading" class="mb-0">Modules &amp; lessons</h5>
        @if($modules->isNotEmpty())
            <p class="small text-muted mb-0" data-testid="cc-counts">
                {{ $publishedCount }} published &middot; {{ $draftCount }} draft
                @if($publishedCount + $draftCount < $modules->sum(fn($m) => $m->lessons->count()))
                    &middot; {{ $modules->sum(fn($m) => $m->lessons->count()) - $publishedCount - $draftCount }} archived
                @endif
            </p>
        @endif
    </div>

    @if($modules->isEmpty())
        <div class="border rounded p-4 text-center" role="status">
            <h6>No modules yet.</h6>
            <p class="text-muted mb-0">
                @if($canManage)
                    Add your first module above. Lessons live inside a module, which is how
                    students see where they are in the course.
                @else
                    The lecturer for this Course Offering has not published any content yet.
                @endif
            </p>
        </div>
    @else
        @foreach($modules as $module)
            <article class="border rounded p-3 mb-3" data-testid="cc-module" data-module-id="{{ $module->id }}">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h6 class="mb-1">
                            Module {{ $module->sequence }} &mdash; {{ $module->title }}
                            <span class="badge bg-{{ $module->status === 'published' ? 'success' : ($module->status === 'archived' ? 'secondary' : 'warning') }} text-{{ $module->status === 'published' ? 'white' : 'dark' }} align-middle ms-1">
                                {{ $module->displayStatusLabel() }}
                            </span>
                        </h6>
                        @if($module->summary)
                            <p class="small text-muted mb-0">{{ $module->summary }}</p>
                        @endif
                        @if($module->released_at)
                            <p class="small text-muted mb-0">
                                {{ $module->released_at->isFuture() ? 'Becomes visible' : 'Visible since' }}:
                                <span data-testid="cc-module-release">{{ $module->released_at->format('j M Y, H:i') }}</span>
                            </p>
                        @endif
                    </div>
                    {{-- ══ THE ACTIONS A LECTURER HAS ON THIS MODULE ══════════════════
                         Three groups, in the order a lecturer thinks about them:
                         put content IN it, put an ASSESSMENT on it, then decide
                         whether students can SEE it.

                         The lifecycle buttons are NOT hand-written per state. They come
                         from `CourseOfferingModuleLifecycle::actionsFor()`, which is
                         the same place the rules live, so a button cannot appear for a
                         move the lifecycle forbids. Before this, the only control was
                         a status <select> and a date field inside a collapsed
                         "Module settings" panel - which meant releasing a module NOW
                         required knowing that "Scheduled" and "Published" are one
                         stored state with a different date.
                    --}}
                    @php
                        $moduleActions = \App\Support\CourseContent\CourseOfferingModuleLifecycle::actionsFor($module);
                        $transitionUrl = fn (string $to) => route(
                            'teacher.course_offerings.content.modules.transition',
                            [$offering->id, $module->id, $to]
                        );
                    @endphp
                    <div class="d-flex gap-2 flex-wrap">
                        @if($canManage)
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('teacher.course_offerings.content.lessons.create', [$offering->id, $module->id]) }}">
                                + Add Lesson
                            </a>

                            {{-- MODULE-AWARE ASSESSMENT LINKS.
                                 Assignments already carry `course_offering_module_id`,
                                 so this arrives with the module already selected.
                                 Online exams deliberately have NO module column - the
                                 exam belongs to the Offering and its Course Unit - so
                                 the exam link goes to the Offering's own create page.
                                 Neither duplicates an engine. --}}
                            <a class="btn btn-sm btn-outline-secondary"
                               data-testid="cc-module-assignment"
                               href="{{ route('teacher.course_offerings.assignments.create', [$offering->id]) }}?course_offering_module_id={{ $module->id }}">
                                + Create Assignment
                            </a>
                            <a class="btn btn-sm btn-outline-secondary"
                               data-testid="cc-module-exam"
                               href="{{ route('teacher.course_offerings.exams.create', [$offering->id]) }}">
                                + Create Quiz / Exam
                            </a>

                            {{-- THE LIFECYCLE, ONE BUTTON PER LEGAL MOVE. --}}
                            @foreach ($moduleActions as $actionKey => $action)
                                @if ($action['schedule'])
                                    {{-- Scheduling needs a date, so it opens the small
                                         panel below rather than posting an instant the
                                         lecturer never chose. --}}
                                    <button type="button" class="btn btn-sm btn-{{ $action['style'] }}"
                                            data-testid="cc-module-schedule"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#cc-module-{{ $module->id }}-schedule"
                                            aria-expanded="false"
                                            aria-controls="cc-module-{{ $module->id }}-schedule"
                                            title="{{ $action['hint'] }}">
                                        {{ $action['label'] }}
                                    </button>
                                @else
                                    <form method="POST" action="{{ $transitionUrl($actionKey === 'draft' ? 'draft' : 'published') }}"
                                          class="d-inline" data-testid="cc-module-action-{{ $actionKey }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-{{ $action['style'] }}"
                                                title="{{ $action['hint'] }}">
                                            {{ $action['label'] }}
                                        </button>
                                    </form>
                                @endif
                            @endforeach

                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="collapse" data-bs-target="#cc-module-{{ $module->id }}-settings"
                                    aria-expanded="false" aria-controls="cc-module-{{ $module->id }}-settings">
                                Edit title &amp; description
                            </button>
                        @endif
                    </div>

                    {{-- THE SCHEDULE PANEL: a date and one Save. Kept separate from
                         "Edit title" so the date a lecturer sets is the obvious
                         thing the panel is for. --}}
                    @if ($canManage && isset($moduleActions['schedule']))
                        <div class="collapse mt-2" id="cc-module-{{ $module->id }}-schedule">
                            <form method="POST"
                                  action="{{ route('teacher.course_offerings.content.modules.transition', [$offering->id, $module->id, 'published']) }}"
                                  class="border rounded p-3 bg-light d-flex flex-wrap gap-2 align-items-end">
                                @csrf
                                <div>
                                    <label class="form-label mb-1" for="cc-mrelease-{{ $module->id }}">
                                        Becomes visible to students at
                                    </label>
                                    {{-- app.timezone is UTC and this is a teaching-time
                                         decision, not a viewer preference, so it is not
                                         routed through LiveClassDisplay. Same reasoning
                                         as the field in Module settings. --}}
                                    <input type="datetime-local" class="form-control"
                                           id="cc-mrelease-{{ $module->id }}"
                                           name="released_at"
                                           data-testid="cc-module-release-input"
                                           value="{{ $module->released_at?->format('Y-m-d\TH:i') }}">
                                </div>
                                <button type="submit" class="btn btn-primary">Schedule publication</button>
                                <span class="form-text mb-0">
                                    Leave this empty and choose <strong>Publish now</strong> instead to release it immediately.
                                </span>
                            </form>
                        </div>
                    @endif
                </div>

                {{-- Module settings: status and release. A collapse rather than a
                     modal, because this is a small edit that sits next to what it
                     describes. --}}
                @if($canManage)
                    <div class="collapse mt-3" id="cc-module-{{ $module->id }}-settings">
                        <form method="POST" action="{{ route('teacher.course_offerings.content.modules.update', [$offering->id, $module->id]) }}"
                              class="border rounded p-3 bg-light">
                            @csrf
                            @method('PUT')
                            <div class="row g-2">
                                <div class="col-12 col-lg-4">
                                    <label class="form-label" for="cc-mtitle-{{ $module->id }}">Title</label>
                                    <input type="text" class="form-control" id="cc-mtitle-{{ $module->id }}" name="title"
                                           maxlength="191" required value="{{ $module->title }}">
                                </div>
                                <div class="col-12 col-lg-3">
                                    <label class="form-label" for="cc-mstatus-{{ $module->id }}">Visibility</label>
                                    <select class="form-select" id="cc-mstatus-{{ $module->id }}" name="status">
                                        <option value="draft" @selected($module->status === 'draft')>Draft — not visible to students</option>
                                        <option value="published" @selected($module->status === 'published')>Published — visible when released</option>
                                        <option value="archived" @selected($module->status === 'archived')>Archived — withdrawn</option>
                                    </select>
                                </div>
                                <div class="col-12 col-lg-3">
                                    <label class="form-label" for="cc-mrel-{{ $module->id }}">Release on <span class="text-muted fw-normal">(optional)</span></label>
                                    {{-- datetime-local, in the institution's own reference. app.timezone is UTC and
                                         this is a teaching-time decision, not a viewer preference, so it is not
                                         routed through LiveClassDisplay. --}}
                                    <input type="datetime-local" class="form-control" id="cc-mrel-{{ $module->id }}" name="released_at"
                                           value="{{ $module->released_at?->format('Y-m-d\TH:i') }}">
                                </div>
                                <div class="col-12 col-lg-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">Save module</button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="cc-msum-{{ $module->id }}">Description</label>
                                    <textarea class="form-control" id="cc-msum-{{ $module->id }}" name="summary" rows="2" maxlength="2000">{{ $module->summary }}</textarea>
                                </div>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- ── The lessons in this module ─────────────────────────── --}}
                <div class="mt-3">
                    @if($module->lessons->isEmpty())
                        <p class="small text-muted mb-0">No lessons in this module yet.</p>
                    @else
                        <ul class="list-group list-group-flush" data-testid="cc-lesson-list" data-module-id="{{ $module->id }}">
                            @foreach($module->lessons as $lesson)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div style="min-width:0">
                                        <span class="text-muted small me-1">{{ $lesson->sequence }}.</span>
                                        <strong>{{ $lesson->title }}</strong>
                                        <span class="badge bg-{{ $lesson->status === 'published' ? 'success' : ($lesson->status === 'archived' ? 'secondary' : 'warning') }} text-{{ $lesson->status === 'published' ? 'white' : 'dark' }} ms-1">
                                            {{ $lesson->status === 'published' && $lesson->released_at?->isFuture() ? 'Scheduled' : ucfirst($lesson->status) }}
                                        </span>
                                        @if($lesson->estimated_minutes)
                                            <span class="small text-muted ms-1">&middot; {{ $lesson->estimatedDurationLabel() }}</span>
                                        @endif
                                        @if($lesson->resources->isNotEmpty())
                                            <span class="small text-muted ms-1">&middot; {{ $lesson->resources->count() }} {{ Str::plural('attachment', $lesson->resources->count()) }}</span>
                                        @endif
                                        @if($lesson->released_at?->isFuture())
                                            <span class="small text-muted ms-1 d-block">Becomes visible {{ $lesson->released_at->format('j M Y, H:i') }}</span>
                                        @endif
                                    </div>
                                    @if($canManage)
                                        <div class="d-flex gap-2 flex-shrink-0">
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="{{ route('teacher.course_offerings.content.lessons.edit', [$offering->id, $lesson->id]) }}">Edit</a>
                                            <a class="btn btn-sm btn-outline-secondary"
                                               href="{{ route('teacher.course_offerings.content.lessons.preview', [$offering->id, $lesson->id]) }}">Preview</a>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </article>
        @endforeach
    @endif
</section>

{{-- ── Ordering ──────────────────────────────────────────────────────────
     Server-side and validated; see CourseContentService::reorderModules().
     Up/Down controls are used rather than a drag library: a fragile JS
     dependency purely for animation is a poor trade for the authoritative
     ordering being correct, and these work on a phone and with a keyboard. --}}
@if($canManage && $modules->count() > 1)
    <section class="eSection-wrap mt-4" aria-labelledby="cc-order-heading">
        <h6 id="cc-order-heading" class="mb-2">Module order</h6>
        <form method="POST" action="{{ route('teacher.course_offerings.content.modules.reorder', $offering->id) }}">
            @csrf
            @foreach($modules as $index => $module)
                <input type="hidden" name="order[]" value="{{ $module->id }}" data-cc-order-module
                       data-cc-direction="{{ $index === 0 ? 'first' : 'up' }}"
                       data-cc-index="{{ $index }}">
            @endforeach
            <div class="d-flex flex-wrap gap-2 mb-2">
                @foreach($modules as $index => $module)
                    <div class="border rounded px-2 py-1 d-flex align-items-center gap-2" data-cc-order-row="{{ $module->id }}">
                        <span class="small text-muted">{{ $module->sequence }}.</span>
                        <span class="small">{{ $module->title }}</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-cc-move="up"
                                aria-label="Move {{ $module->title }} up">&uarr;</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-cc-move="down"
                                aria-label="Move {{ $module->title }} down">&darr;</button>
                    </div>
                @endforeach
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Save order</button>
        </form>
    </section>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Move rows and keep the hidden order[] inputs in step. The inputs are the
    // real submission; the visual reorder is only a preview of it, so a blocked
    // or failed save cannot leave the page claiming an order the server refused.
    var form = document.querySelector('form[action*="modules/order"]');
    if (!form) return;
    var container = form.querySelector('div.d-flex.flex-wrap');
    if (!container) return;

    function renumber() {
        var inputs = form.querySelectorAll('[data-cc-order-module]');
        var rows = container.querySelectorAll('[data-cc-order-row]');
        var ids = [];
        for (var i = 0; i < rows.length; i++) ids.push(rows[i].getAttribute('data-cc-order-row'));
        for (var j = 0; j < inputs.length; j++) inputs[j].value = ids[j];
    }

    form.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cc-move]');
        if (!button) return;
        event.preventDefault();
        var row = button.closest('[data-cc-order-row]');
        var direction = button.getAttribute('data-cc-move');
        var sibling = direction === 'up' ? row.previousElementSibling : row.nextElementSibling;
        if (!sibling) return;
        if (direction === 'up') container.insertBefore(row, sibling);
        else container.insertBefore(sibling, row);
        renumber();
    });
});
</script>
@endsection
