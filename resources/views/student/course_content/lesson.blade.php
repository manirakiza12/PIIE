@extends('student.navigation')
@section('content')
@php
    /**
     * The lesson reader.
     *
     * Built mobile-first and light, because PIIE's students are on metered
     * connections. No web fonts, no background images, nothing that must
     * download before the words appear. Images are capped and lazy, and a table
     * scrolls inside its own container instead of forcing the page wide.
     *
     * OFFLINE IS NOT CLAIMED
     *
     * The data model can support downloadable resources later, and every
     * attachment is a plain file with a size, so that work is possible. It is
     * NOT built, so this page does not pretend otherwise: there is no offline
     * badge, no "downloaded" state, and nothing here implies a student can open
     * a lesson without a connection.
     *
     * COMPLETION
     *
     * "Mark complete" is a POST, not a link, so a prefetch or a crawler cannot
     * complete a lesson. It only appears when the lesson's completion rule is
     * one PIIE can actually honour, and pressing it twice is harmless.
     */
@endphp

@include('teacher.course_offerings.content._surface_styles')

<div class="mainSection-title">
    <a href="{{ route('student.courses.content', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to {{ $unitName }}
    </a>

    {{-- "Lesson X of Y" answers where the student is in the course before the
         body does. It counts the same released lessons the progress figure does,
         so the two can never disagree. --}}
    <p class="small text-muted mb-1" data-testid="cc-reader-position">
        Lesson {{ $position }} of {{ $total }}
    </p>
    <h4 class="mb-1">{{ $lesson->title }}</h4>
    <p class="text-muted mb-0">
        @if($lesson->estimatedDurationLabel())
            Estimated study time: {{ $lesson->estimatedDurationLabel() }}
        @else
            <span class="small">Study time not set by your lecturer</span>
        @endif
        @if($lastViewed)
            &middot; <span class="small">Last opened {{ $lastViewed->format('j M Y, H:i') }}</span>
        @endif
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif

{{-- ══ Course progress, in context ═════════════════════════════════════════
     Repeated on the lesson itself, because a student who has navigated into a
     lesson should not have to go back to remember how far through they are. --}}
@if($lessonTotal > 0)
    <div class="border rounded px-3 py-2 mb-3 d-flex justify-content-between align-items-center gap-2 flex-wrap"
         data-testid="cc-reader-progress">
        <span class="small">
            {{ $completedCount }} of {{ $lessonTotal }} lessons completed
            <span class="text-muted">({{ $percent }}%)</span>
        </span>
        <div class="progress flex-grow-1" style="height:6px; max-width:220px"
             role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"
             aria-label="{{ $completedCount }} of {{ $lessonTotal }} lessons completed">
            <div class="progress-bar bg-success" style="width: {{ $percent }}%"></div>
        </div>
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <article class="cc-surface border rounded p-3 p-md-4">
            @if($lesson->summary)
                <p class="lead fs-6">{{ $lesson->summary }}</p>
            @endif

            @if($lesson->learning_objectives)
                {{-- The objectives come before the content on purpose: a student
                     should know what they are about to be able to do before they
                     start reading, not after. Plain text, rendered as a list. --}}
                <section class="mb-3" aria-labelledby="cc-reader-objectives">
                    <h6 id="cc-reader-objectives">What you will be able to do</h6>
                    <ul>
                        @foreach(preg_split('/\r\n|\r|\n|;/', $lesson->learning_objectives) as $objective)
                            @php $objective = trim($objective); @endphp
                            @if($objective !== '' && $objective !== '•')
                                <li>{{ ltrim($objective, '•- ') }}</li>
                            @endif
                        @endforeach
                    </ul>
                </section>
                <hr>
            @endif

            {{-- The body was sanitized on the way IN by a model mutator, so this
                 is reading already-filtered content. This is the one place in
                 Course Content where {!! !!} is correct. --}}
            <div class="cc-body">
                {!! $lesson->body !!}
            </div>
        </article>

        {{-- ══ Resources ═══════════════════════════════════════════════════
             The size is shown next to every file so a student on a metered
             connection can decide before spending data, rather than
             discovering it half-way through a download. --}}
        @if($resources->isNotEmpty())
            <section class="border rounded p-3 mt-3" aria-labelledby="cc-reader-resources">
                <h6 id="cc-reader-resources" class="mb-2">Resources for this lesson</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($resources as $resource)
                        <li class="d-flex justify-content-between align-items-center gap-2 py-1">
                            <span style="min-width:0">
                                {{ $resource->displayName() }}
                                <span class="small text-muted">
                                    &middot; {{ $resource->isFile() ? 'Download' : 'Web link' }}
                                    @if($resource->sizeLabel()) &middot; {{ $resource->sizeLabel() }} @endif
                                </span>
                            </span>
                            @if($resource->isFile() && $resource->stored_name)
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ route('student.courses.content.resources.show', $resource->id) }}">Open</a>
                            @elseif(! $resource->isFile() && $resource->hasUsableLink())
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ $resource->link_url }}" target="_blank" rel="noopener noreferrer">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- ══ The right-hand column: complete, and where next ═══════════════════ --}}
    <div class="col-12 col-lg-4">
        <div class="border rounded p-3 mb-3">
            @if($completed)
                <div class="alert alert-success mb-0 py-2" role="status" data-testid="cc-completed">
                    <strong>&#10003; You completed this lesson.</strong>
                </div>
            @else
                <h6 class="mb-2">Finished this lesson?</h6>
                @if($canComplete)
                    {{-- Completion is a POST. It is idempotent: a second press
                         updates the same record and says so, and never counts
                         twice. --}}
                    <form method="POST" action="{{ route('student.courses.content.lessons.complete', [$offering->id, $lesson->id]) }}">
                        @csrf
                        <button type="submit" class="btn btn-success w-100" data-cc-unsaved-ok>Mark Complete</button>
                    </form>
                    <p class="small text-muted mt-2 mb-0">
                        Marking a lesson complete records that you have read it. It is your own
                        confirmation, and you can revisit the lesson at any time.
                    </p>
                @else
                    {{-- The rule exists but PIIE cannot observe it yet. Say that,
                         rather than showing a button that would mark something
                         complete that the rule was meant to gate. --}}
                    <p class="small text-muted mb-0" data-testid="cc-rule-not-honoured">
                        This lesson is completed by its own activity rather than a button. Your
                        progress will update when that activity is finished.
                    </p>
                @endif
            @endif
        </div>

        <div class="border rounded p-3">
            <h6 class="mb-2">In this course</h6>
            <a href="{{ route('student.courses.content', $offering->id) }}" class="btn btn-sm btn-outline-secondary w-100 mb-2">
                All modules and lessons
            </a>

            {{-- Previous / Next are derived from the SAME released set the
                 progress count uses, so "Next" can never walk a student into a
                 draft lesson, and the two figures can never disagree. --}}
            <div class="d-grid gap-2">
                @if($previous)
                    <a class="btn btn-outline-secondary btn-sm text-start"
                       href="{{ route('student.courses.content.lessons.show', [$offering->id, $previous->id]) }}">
                        <span aria-hidden="true">&larr;</span> Previous lesson
                        <span class="d-block small text-muted text-truncate">{{ $previous->title }}</span>
                    </a>
                @else
                    <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                        <span aria-hidden="true">&larr;</span> Previous lesson
                        <span class="d-block small">This is the first lesson</span>
                    </button>
                @endif

                @if($next)
                    <a class="btn btn-primary btn-sm text-start"
                       href="{{ route('student.courses.content.lessons.show', [$offering->id, $next->id]) }}">
                        Next lesson <span aria-hidden="true">&rarr;</span>
                        <span class="d-block small text-truncate">{{ $next->title }}</span>
                    </a>
                @else
                    <button type="button" class="btn btn-primary btn-sm" disabled>
                        Next lesson <span aria-hidden="true">&rarr;</span>
                        <span class="d-block small">This is the last available lesson</span>
                    </button>
                @endif
            </div>

            @if(! $next && ! $previous)
                <p class="small text-muted mt-2 mb-0">
                    You have reached the end of the lessons currently released.
                </p>
            @endif
        </div>
    </div>
</div>

<style>
    /* Summernote writes bare tables. A table must scroll inside itself rather
       than widening the page, which on a phone means a horizontal swipe instead
       of a page the student cannot read. */
    .cc-body table { display: block; width: 100%; overflow-x: auto; border-collapse: collapse; }
    .cc-body th, .cc-body td { border: 1px solid var(--cc-line, #d7dee5); padding: .5em .7em; text-align: left; vertical-align: top; }
    .cc-body th { background: #f4f6f8; font-weight: 600; }
    .cc-body .cc-equation { font-family: ui-serif, Georgia, serif; font-style: italic; }
</style>
@endsection
