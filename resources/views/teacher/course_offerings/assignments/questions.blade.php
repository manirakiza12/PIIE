@extends('teacher.navigation')

@section('content')
@php
    /**
     * The question builder for one Course Offering assignment.
     *
     * A question paper is a DOCUMENT, and a document does not belong in a modal.
     * This is its own page for the same reason the create and edit screens are:
     * a lecturer building four questions with a mark split and an answer type each
     * needs to be able to leave and come back.
     *
     * ── THE MARK INTEGRITY PANEL IS THE SAME LIST THAT BLOCKS PUBLICATION ──
     * It renders `$readiness`, which is straight out of
     * `Assignment::questionReadinessProblems()` - the exact list
     * `AssignmentService::assertPublishable()` refuses on. One definition, so what a
     * lecturer is told here and what stops them publishing cannot disagree, which
     * is the failure mode of a rule that lives only in a form.
     *
     * ── "ADOPT THE QUESTION TOTAL" EXISTIS BECAUSE OF THE ALTERNATIVE ─────
     * The alternative is asking a lecturer to add up the paper they just wrote and
     * type the same number into another box, which is how a mark-integrity rule gets
     * defeated in practice. PIIE does not make them do arithmetic that can
     * contradict their own questions. It does not do it for them silently either:
     * a published assignment's total is what students are being assessed against,
     * so it is a button they press and it tells them what it did.
     *
     * ── WHY ADDING, EDITING, DELETING AND REORDERING ALL FREEZE AT ONCE ──
     * Once any student has submitted, a question is a promise about what they were
     * asked. Editing it changes what their work is an answer to; reordering changes
     * the numbering their feedback refers to; adding one leaves their attempt
     * missing an answer to a question they never saw. So all four are refused, with
     * the same sentence, and the screen says so before they try.
     *
     * Marking is NOT frozen - only authoring is. A lecturer may revise a mark, add
     * feedback, and re-release; that is the second half of the lifecycle and it is
     * expected to change.
     *
     * ── NO QUESTION IS EVER INVENTED ──────────────────────────────────────
     * Nothing on this page generates a question from the assignment's
     * instructions. A question is something a lecturer wrote, and deriving one from
     * prose would put a question in front of a student that no human ever asked.
     */
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to assignment</a>
    <p class="small text-muted mb-1" data-testid="aq-position">{{ $courseUnitLabel }} &middot; {{ $unitName ?? $offering->reference }}</p>
    <h4 class="mb-1">Questions</h4>
    <p class="text-muted mb-0">
        {{ $assignment->title }}
        @if($module)
            <span class="badge bg-light text-dark border ms-1">Module {{ $module->sequence }}</span>
        @endif
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

{{-- ══ MARK INTEGRITY ═════════════════════════════════════════════════════ --}}
<section class="as-surface border rounded p-3 mb-3" aria-labelledby="aq-integrity-heading">
    <h5 id="aq-integrity-heading" class="mb-2">Marks</h5>

    @if($questions->isEmpty())
        <p class="mb-0" data-testid="aq-generic-notice">
            This assignment has <strong>no questions</strong>, so it is a
            <strong>single-piece assignment</strong>: students get one evidence
            form for the whole thing, exactly as before this feature existed.
            Adding a question below switches it to a question paper &mdash; the
            ordered questions, per-question evidence and per-question marking.
        </p>
    @else
        <div class="row g-3 align-items-end" data-testid="aq-integrity">
            <div class="col-12 col-sm-4">
                <div class="text-muted small text-uppercase">Questions add up to</div>
                <div class="fs-5 fw-semibold" data-testid="aq-questions-total">{{ (int) $questionsTotal }} marks</div>
            </div>
            <div class="col-12 col-sm-4">
                <div class="text-muted small text-uppercase">Assignment total</div>
                <div class="fs-5 fw-semibold" data-testid="aq-assignment-total">{{ $assignment->max_marks }} marks</div>
            </div>
            <div class="col-12 col-sm-4">
                @if((int) $questionsTotal === (int) $assignment->max_marks)
                    <span class="badge cc-badge-complete" data-testid="aq-marks-agree">
                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i> The questions govern the total
                    </span>
                @else
                    <span class="badge cc-badge-pending" data-testid="aq-marks-disagree">
                        These do not match
                    </span>
                @endif
            </div>
        </div>

        @if($readiness !== [])
            <div class="alert alert-warning mt-3 mb-0" role="status" data-testid="aq-readiness">
                <p class="fw-semibold mb-1">Fix this before you publish</p>
                <ul class="mb-0">
                    @foreach($readiness as $problem)<li>{{ $problem }}</li>@endforeach
                </ul>
            </div>
        @else
            <p class="small text-success mt-3 mb-0" data-testid="aq-ready">
                This paper can be published. Every question is worth marks, has an
                answer type, and the questions total the assignment.
            </p>
        @endif

        @if(! $frozen)
            <form method="POST" class="mt-3"
                  action="{{ route('teacher.course_offerings.assignments.questions.adopt_marks', [$offering->id, $assignment->id]) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-primary" data-testid="aq-adopt-marks">
                    Set the assignment total to the question total
                </button>
                <span class="small text-muted ms-2">
                    Rather than adding the questions up by hand and typing the same
                    number in the assignment form.
                </span>
            </form>
        @endif
    @endif
</section>

{{-- ══ THE FREEZE NOTICE ══════════════════════════════════════════════════ --}}
@if($frozen)
    <div class="alert alert-info" role="status" data-testid="aq-frozen">
        <i class="bi bi-lock-fill" aria-hidden="true"></i>
        <strong>These questions are locked.</strong>
        {{ $freezeReason }}
    </div>
@endif

{{-- ══ THE QUESTIONS ══════════════════════════════════════════════════════ --}}
<section aria-labelledby="aq-list-heading" class="mb-4">
    <h5 id="aq-list-heading" class="mb-2">
        {{ $questions->count() }} question{{ $questions->count() === 1 ? '' : 's' }}
    </h5>

    @if($questions->isEmpty())
        <p class="text-muted">No questions yet. Add the first one below.</p>
    @else
        <div data-testid="aq-list">
            @foreach($questions as $question)
                <article class="as-surface border rounded p-3 mb-2" data-testid="aq-question-{{ $question->id }}">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div style="min-width:0">
                            <h6 class="mb-1" data-testid="aq-question-number">
                                Question {{ $loop->iteration }} of {{ $questions->count() }}
                            </h6>
                            @if($question->heading)
                                <p class="fw-semibold mb-1">{{ $question->heading }}</p>
                            @endif
                            <div class="as-body piie-prose">{!! $question->prosePrompt() !!}</div>
                        </div>
                        <div class="text-nowrap">
                            <span class="badge bg-light text-dark border"
                                  data-testid="aq-question-marks">{{ $question->marksLabelWithUnit() }}</span>
                        </div>
                    </div>

                    <p class="small text-muted mt-2 mb-1" data-testid="aq-question-requirement">
                        @if($question->is_required)
                            <span class="text-danger fw-semibold">Required</span> &mdash; a student cannot hand this
                            assignment in leaving it blank.
                        @else
                            <span>Optional</span> &mdash; may be left blank.
                        @endif
                        <br>
                        Accepts: {{ $question->responseRequirementLabel() }}
                        @if($question->acceptedResponseKinds() !== []
                            && ($question->acceptsResponseKind('audio') || $question->acceptsResponseKind('video')))
                            <br>
                            <span class="text-muted">
                                A student may record {{ $question->acceptsResponseKind('audio') && $question->acceptsResponseKind('video') ? 'audio or video' : ($question->acceptsResponseKind('audio') ? 'audio' : 'video') }}
                                in the browser, or upload a file &mdash; whichever they prefer.
                            </span>
                        @endif
                    </p>

                    @unless($frozen)
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                                    data-bs-target="#aq-edit-{{ $question->id }}">Edit</button>

                            <form method="POST"
                                  action="{{ route('teacher.course_offerings.assignments.questions.destroy', [$offering->id, $assignment->id, $question->id]) }}"
                                  onsubmit="return confirm('Remove this question? Its evidence and answers go with it.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" data-testid="aq-question-delete">
                                    Remove
                                </button>
                            </form>
                        </div>

                        {{-- ── The edit form, collapsed by default ── --}}
                        <div class="collapse mt-2" id="aq-edit-{{ $question->id }}">
                            <form method="POST"
                                  action="{{ route('teacher.course_offerings.assignments.questions.update', [$offering->id, $assignment->id, $question->id]) }}"
                                  class="border rounded p-3">
                                @csrf
                                @method('PUT')
                                @include('teacher.course_offerings.assignments._question_fields', [
                                    'question' => $question,
                                    'configurableKinds' => $configurableKinds,
                                ])
                                <button type="submit" class="btn btn-sm btn-primary">Save this question</button>
                            </form>
                        </div>
                    @endunless
                </article>
            @endforeach
        </div>

        {{-- ── REORDER ─────────────────────────────────────────────────────
             A POST carrying the whole list, not a drag-and-drop PUT, because a
             form of hidden inputs works with JavaScript switched off and degrades
             to "leave them where they are" - a far better failure than a broken
             drag. Every id is present, so a partial move is expressed as the
             lecturer's new complete order. --}}
        @unless($frozen)
            <form method="POST" class="mt-2"
                  action="{{ route('teacher.course_offerings.assignments.questions.reorder', [$offering->id, $assignment->id]) }}">
                @csrf
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <label class="small text-muted">Order:</label>
                    @foreach($questions as $question)
                        <input type="hidden" name="order[]" value="{{ $question->id }}">
                        <span class="badge bg-light text-dark border">
                            {{ $question->heading ?: 'Q'.$loop->iteration }}
                        </span>
                    @endforeach
                </div>
                <p class="small text-muted mt-2 mb-0">
                    Questions are numbered in the order shown. To reorder, change
                    this page's order and press Save &mdash; the numbers a student
                    and a marker both use follow it.
                </p>
                <button type="submit" class="btn btn-sm btn-outline-primary mt-2" data-testid="aq-reorder">
                    Save this order
                </button>
            </form>
        @endunless
    @endif
</section>

{{-- ══ ADD A QUESTION ═════════════════════════════════════════════════════ --}}
@if($frozen)
    {{-- Nothing here on purpose. A disabled form invites a submission, and a
         refusal discovered at the point of pressing the button is worse than a
         panel that is simply not offered. --}}
@else
    <section class="as-surface border rounded p-3" aria-labelledby="aq-add-heading" data-testid="aq-add">
        <h5 id="aq-add-heading" class="mb-2">Add a question</h5>

        <form method="POST"
              action="{{ route('teacher.course_offerings.assignments.questions.store', [$offering->id, $assignment->id]) }}">
            @csrf
            @include('teacher.course_offerings.assignments._question_fields', [
                'question' => null,
                'configurableKinds' => $configurableKinds,
            ])
            <button type="submit" class="btn btn-primary" data-testid="aq-add-submit">Add this question</button>
        </form>
    </section>
@endif

<style>
    /* Reused from the student course-content page, so a "complete" badge means
       the same thing wherever a reader meets it. Tinted rather than solid, so it
       sits with the page's surfaces instead of becoming the loudest thing in it. */
    .cc-badge-complete { background: #e7f6ec; color: #14663a; }
    .cc-badge-pending  { background: #fdf1dc; color: #8a5300; }
</style>
@endsection
