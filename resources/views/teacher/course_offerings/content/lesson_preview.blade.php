@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * Preview as a Student.
     *
     * Renders the ACTUAL reader markup - the same `.cc-surface` stylesheet, the
     * same body handling - so "Preview" means what it says. A separate
     * "preview" stylesheet is how a lecturer publishes something and then has to
     * go and fix how it looks.
     *
     * What it deliberately does NOT show is the progress bar, Previous/Next and
     * the Mark Complete button: those belong to a registered student's real
     * position in the course, and inventing a fake "Lesson 1 of 1" here would
     * imply a preview tells you something it cannot. This answers one question
     * well: will this read correctly?
     */
@endphp

@include('teacher.course_offerings.content._surface_styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.content.lessons.edit', [$offering->id, $lesson->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to the editor</a>
    <h4 class="mb-1">Preview &mdash; {{ $lesson->title }}</h4>
    <p class="text-muted mb-0">
        {{ $offering->subject?->name ?? $offering->reference }}
        @if($module) &middot; Module {{ $module->sequence }}: {{ $module->title }} @endif
    </p>
</div>

@if($isUnsavedPreview)
    <div class="alert alert-info" role="status">
        <strong>Previewing your unsaved changes.</strong>
        This is the current text in the editor, not the last version you saved.
        Nothing here is visible to students.
    </div>
@else
    <div class="alert alert-secondary" role="status">
        <strong>Previewing the last saved version.</strong>
        To preview edits you have not saved yet, use <em>Preview</em> on the editor page.
        @if($lesson->status === 'draft')
            <br>This lesson is still a <strong>Draft</strong>, so no student can see it.
        @else
            <br>It is currently <strong>{{ $lesson->status === 'published' ? 'Published' : 'Archived' }}</strong>.
        @endif
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <article class="cc-surface border rounded p-3 p-md-4">
            <h2 class="mt-0">{{ $lesson->title }}</h2>

            <p class="small text-muted">
                @if($lesson->estimatedDurationLabel())
                    Estimated study time: {{ $lesson->estimatedDurationLabel() }}
                @else
                    <span>Study time not set</span>
                @endif
            </p>

            @if($lesson->summary)
                <p class="lead fs-6">{{ $lesson->summary }}</p>
            @endif

            @if($lesson->learning_objectives)
                <section class="mb-3" aria-labelledby="cc-preview-objectives">
                    <h6 id="cc-preview-objectives">What you will be able to do</h6>
                    {{-- Objectives are PLAIN TEXT the lecturer typed, one per line.
                         Rendered as a list, escaped - never as HTML. --}}
                    <ul>
                        @foreach(preg_split('/\r\n|\r|\n|;/', $lesson->learning_objectives) as $objective)
                            @php $objective = trim($objective); @endphp
                            @if($objective !== '' && $objective !== '•')
                                <li>{{ ltrim($objective, '•- ') }}</li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif

            <hr>

            {{-- The body was sanitized on the way IN (a model mutator, so no path
                 stores raw HTML) and again here for an unsaved preview. Rendering
                 it unescaped is therefore reading already-filtered content, and
                 this is the one place in Course Content where {!! !!} appears. --}}
            <div class="cc-body">
                {!! $lesson->body !!}
            </div>
        </article>
    </div>

    <div class="col-12 col-lg-4">
        <div class="border rounded p-3">
            <h6 class="mb-2">What a student will see</h6>
            <ul class="small text-muted mb-3">
                <li>The title, the estimated study time and the objectives, exactly as above.</li>
                <li>The lesson content, exactly as above.</li>
                <li>Any resources attached to the lesson, below.</li>
                <li><em>Previous lesson</em>, <em>Mark complete</em> and <em>Next lesson</em>, using their real position in the course.</li>
            </ul>

            @if($lesson->resources->isNotEmpty())
                <h6 class="mb-2">Resources in this lesson</h6>
                <ul class="small">
                    @foreach($lesson->resources as $resource)
                        <li>{{ $resource->displayName() }} <span class="text-muted">({{ $resource->isFile() ? 'file' : 'link' }})</span></li>
                    @endforeach
                </ul>
            @else
                <p class="small text-muted mb-0">No resources attached to this lesson yet.</p>
            @endif
        </div>

        <div class="border rounded p-3 mt-3">
            <h6 class="mb-2">Not shown in a preview</h6>
            <p class="small text-muted mb-0">
                Progress and Previous/Next depend on the student's real place in the course, so a
                preview cannot show them honestly. Save and publish, then open the lesson as the
                student to check those.
            </p>
        </div>
    </div>
</div>

<style>
    /* Summernote writes bare tables; a table must never widen the whole page. */
    .cc-body table { width: 100%; border-collapse: collapse; }
    .cc-body th, .cc-body td { border: 1px solid var(--cc-line, #d7dee5); padding: .5em .7em; text-align: left; }
    .cc-body th { background: #f4f6f8; font-weight: 600; }
    .cc-body table { display: block; overflow-x: auto; }
    .cc-body .cc-equation { font-family: ui-serif, Georgia, serif; font-style: italic; }
</style>
@endsection
