<section class="as-submit-panel border rounded p-3 p-md-4" aria-labelledby="as-work-heading">
    <h5 id="as-work-heading">Your response</h5>
    @if(!$canSubmit)
        <div class="alert alert-secondary mb-0" data-testid="as-cannot-submit">{{ $refusalReason }}</div>
        @if($questionBased)
            <div class="mt-3" data-testid="as-readonly-questions">
                @foreach($questions as $question)
                    <article class="border rounded p-3 mb-3">
                        <h6>Question {{ $loop->iteration }} of {{ $questions->count() }}</h6>
                        @if($question->heading)<p class="fw-semibold">{{ $question->heading }}</p>@endif
                        <div class="piie-prose">{!! $question->prosePrompt() !!}</div>
                        @include('shared.assignment_question_resources', ['teacherView' => false])
                        @if(isset($questionAnswers[$question->id]))
                            <h6 class="mt-3">Your saved answer</h6>
                            <div class="piie-prose">{!! app(\App\Support\CourseContent\HtmlSanitizer::class)->sanitize($questionAnswers[$question->id]->text_response ?? '') !!}</div>
                        @endif
                        @foreach($evidenceByQuestion[$question->id] ?? collect() as $item)
                            <p class="small mb-1">Saved attachment: {{ $item->displayName() }}</p>
                        @endforeach
                    </article>
                @endforeach
            </div>
        @endif
    @else
        <p class="small text-muted">Save a draft while you work. Only <strong>Submit Assignment</strong> uses an attempt.</p>
        @if($draft)<p class="small" data-testid="as-draft-chip">Prepared, not submitted · Draft saved {{ $display->submittedAt($assignment, auth()->user(), $draft->updated_at)?->format('j M Y, H:i') }}</p>@endif
        @php $attachedByKind = ($draft?->items ?? collect())->groupBy('kind'); @endphp
        <form method="POST" enctype="multipart/form-data" id="as-form" novalidate
              @if($questionBased) data-autosave-url="{{ route('student.courses.assignments.autosave', [$offering->id, $assignment->id]) }}" @endif
              action="{{ route('student.courses.assignments.submit', [$offering->id, $assignment->id]) }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $attemptKey) }}">
            @if($questionBased)
                <p id="as-autosave-status" class="small text-muted" role="status" aria-live="polite">Answers save as you type.</p>
                <h6>Questions and answers</h6>
                @include('student.course_assignments._question_fields', [
                    'assignment' => $assignment, 'questions' => $questions, 'answers' => $questionAnswers,
                    'evidenceByQuestion' => $evidenceByQuestion, 'prefix' => 'as-submit-',
                ])
            @else
                @include('student.course_assignments._evidence_fields', [
                    'assignment' => $assignment, 'attachedByKind' => $attachedByKind,
                    'prefix' => 'as-submit-', 'existingText' => old('text', $draft?->writtenResponse() ?? $latest?->writtenResponse()),
                ])
            @endif
            <div class="d-flex flex-wrap align-items-start gap-2 mt-3">
                <button type="submit" class="btn btn-outline-secondary" data-as-draft
                        formaction="{{ route('student.courses.assignments.draft', [$offering->id, $assignment->id]) }}">Save Draft</button>
                <details class="flex-grow-1" id="as-review">
                    <summary class="btn btn-outline-primary">Review and submit</summary>
                    <div class="border rounded p-3 mt-2">
                        <p class="small">Check your answers and attachments above. This submits attempt {{ $attempts_used + 1 }} of {{ $attempts_allowed }}.</p>
                        <ul data-as-review-list class="small"></ul>
                        <p class="small text-muted">Saved attachments shown above will be included. After submission, changes require another allowed attempt.</p>
                        <button type="submit" class="btn btn-success w-100" data-testid="as-submit">Submit Assignment</button>
                    </div>
                </details>
            </div>
            <p data-as-submit-status class="small mt-2 mb-0" role="status" aria-live="polite"></p>
        </form>
    @endif
</section>
