@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * Preview as a student.
     *
     * Renders the ACTUAL student markup - the same `.as-surface` stylesheet and
     * the same body handling - so "Preview" answers a real question. A separate
     * preview stylesheet is how a lecturer publishes something and then has to
     * go and fix how it looks.
     *
     * It deliberately does NOT show the submission panel, the progress of a
     * particular student, or a "submitted" badge. Those belong to one student's
     * real position in the course, and a preview that invented them would imply
     * something it cannot know. This answers one question well: will this read
     * correctly?
     */
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to the assignment</a>
    <h4 class="mb-1">Preview &mdash; {{ $assignment->title }}</h4>
    <p class="text-muted mb-0">
        {{ $offering->subject?->name ?? $offering->reference }}
        @if($module = null) @endif
    </p>
</div>

@if($isUnsavedPreview)
    <div class="alert alert-info" role="status">
        <strong>Previewing your unsaved changes.</strong>
        This is the current text in the editor, not the last version you saved. Nothing here is visible to
        students.
    </div>
@else
    <div class="alert alert-secondary" role="status">
        <strong>Previewing the saved version.</strong>
        To preview edits you have not saved, use <em>Preview</em> on the editor page.
        <br>It is currently
        <strong>{{ \App\Support\Assignments\AssignmentLifecycle::lecturerLabel($assignment) }}</strong>.
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <article class="as-surface border rounded p-3 p-md-4">
            <h2 class="mt-0">{{ $assignment->title }}</h2>

            <dl class="row small">
                <dt class="col-5 col-sm-4 text-muted">Opens</dt>
                <dd class="col-7 col-sm-8">{{ $display->released($assignment, auth()->user()) }}</dd>

                <dt class="col-5 col-sm-4 text-muted">Due</dt>
                <dd class="col-7 col-sm-8">{{ $display->due($assignment, auth()->user()) }}</dd>

                @if($assignment->closes_at)
                    <dt class="col-5 col-sm-4 text-muted">Final closing</dt>
                    <dd class="col-7 col-sm-8">{{ $display->closes($assignment, auth()->user()) }}</dd>
                @endif

                <dt class="col-5 col-sm-4 text-muted">Marks available</dt>
                <dd class="col-7 col-sm-8">{{ $assignment->max_marks }}</dd>

                <dt class="col-5 col-sm-4 text-muted">What to submit</dt>
                <dd class="col-7 col-sm-8">
                    {{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$assignment->submission_type] ?? $assignment->submission_type }}
                </dd>

                <dt class="col-5 col-sm-4 text-muted">Attempts</dt>
                <dd class="col-7 col-sm-8">
                    {{ $assignment->attemptsAllowed() === 1 ? 'One attempt' : 'Up to '.$assignment->attemptsAllowed().' attempts' }}
                </dd>
            </dl>

            @if($display->zoneNote($assignment, auth()->user()))
                <p class="small text-muted mb-0">{{ $display->zoneNote($assignment, auth()->user()) }}</p>
            @endif

            @if($assignment->learning_objectives)
                <hr>
                <h6>What you should be able to do</h6>
                <ul class="mb-0">
                    @foreach(preg_split('/\r\n|\r|\n|;/', $assignment->learning_objectives) as $line)
                        @php $line = trim($line); @endphp
                        @if($line !== '' && $line !== '•') <li>{{ ltrim($line, '•- ') }}</li> @endif
                    @endforeach
                </ul>
            @endif

            <hr>
            {{-- Already sanitised on the way in, and again for an unsaved preview,
                 so this is reading filtered content. --}}
            <div class="as-body">
                {!! $assignment->proseInstructions() !!}
            </div>
        </article>
    </div>

    <div class="col-12 col-lg-4">
        <div class="border rounded p-3">
            <h6 class="mb-2">What the student will see</h6>
            <ul class="small text-muted mb-3">
                <li>The title, the dates in their own timezone, the marks and the submission type.</li>
                <li>The objectives and the instructions, exactly as above.</li>
                <li>Any resources you have attached, with the file size so they can judge before downloading.</li>
                <li>A submission panel that separates <em>saving your work</em> from <em>handing it in</em>.</li>
            </ul>

            @if($assignment->resources->isNotEmpty())
                <h6 class="mb-2">Resources in this assignment</h6>
                <ul class="small">
                    @foreach($assignment->resources as $resource)
                        <li>{{ $resource->displayName() }} <span class="text-muted">({{ $resource->isFile() ? 'file' : 'link' }})</span></li>
                    @endforeach
                </ul>
            @else
                <p class="small text-muted mb-0">No resources attached yet.</p>
            @endif
        </div>

        <div class="border rounded p-3 mt-3">
            <h6 class="mb-2">Not shown in a preview</h6>
            <p class="small text-muted mb-0">
                Whether this particular student has submitted, and what their mark is, depend on their real
                position in the course. A preview cannot know them, so it does not pretend. Publish, then open
                it as the student to check those.
            </p>
        </div>
    </div>
</div>

<style>
    .as-body table { display: block; width: 100%; overflow-x: auto; border-collapse: collapse; }
    .as-body th, .as-body td { border: 1px solid var(--as-line, #d7dee5); padding: .5em .7em; text-align: left; }
    .as-body th { background: #f4f6f8; font-weight: 600; }
</style>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
