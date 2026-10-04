{{--
    The questions of a question-based assignment, in order.

    THE ORDERED QUESTION EXPERIENCE, RENDERED FROM THE QUESTIONS THEMSELVES

    A student should be able to read their way down the page in the same order a
    marker will, and each question should offer only what that question accepts.
    The generic evidence form cannot do this: it renders one set of fields for the
    whole assignment, so "upload a photograph" and "record an explanation" both
    become the same undifferentiated box.

    WHAT THE STUDENT IS GIVEN IS NOT DECIDED HERE

    This partial renders controls. It never decides whether an answer counts, never
    decides whether a question is satisfied, and never says a submission is
    complete. Those are `QuestionResponseService`'s decisions, made from the
    questions and the stored answers - so the same rule governs the form, the
    validation, the marking screen and any future gradebook.

    A DRAFT AND A HAND-IN RENDER THE SAME FIELDS

    The `$prefix` distinguishes the two zones' element ids only. The field NAMES are
    deliberately identical, because both forms post to different routes and each
    needs the same `questions[...]` shape.

    The evidence already filed is passed in per question, so each input can state
    what is on file. A browser cannot restore a file input, so anything it silently
    dropped would be lost on submit unless the page says it is there.
--}}
@php
    $item = \App\Models\AssignmentSubmissionItem::class;
@endphp

@if($questions->isEmpty())
    <div class="alert alert-warning py-2 small">
        This assignment has no questions yet. Your lecturer is still setting it up.
    </div>
@else
    <p class="small text-muted" data-testid="as-question-count">
        {{ $questions->count() }} question{{ $questions->count() === 1 ? '' : 's' }},
        {{ $assignment->max_marks }} marks in total.
    </p>

    @foreach($questions as $question)
        @include('student.course_assignments._question_answer', [
            'question' => $question,
            'questionCount' => $questions->count(),
            'answer' => $answers[$question->id] ?? null,
            'evidence' => $evidenceByQuestion[$question->id] ?? collect(),
            'prefix' => $prefix,
        ])
    @endforeach
@endif
