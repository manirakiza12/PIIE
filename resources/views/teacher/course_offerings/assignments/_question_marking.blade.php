{{--
    Mark a QUESTION-BASED attempt, question by question.

    ── THERE IS NO OVERALL MARK FIELD, AND THAT IS THE POINT ────────────────
    The total is the SUM of the per-question marks, written to
    `assignment_submissions.marks_awarded` by `QuestionGradingService`. There is
    deliberately nowhere on this form to type a second total, so a lecturer
    physically cannot enter one that contradicts the marks they just gave. The
    running total shown at the top is a CALCULATION, not an input.

    ── EVERY QUESTION IS SHOWN, ANSWERED OR NOT ────────────────────────────
    A response row exists for every question from the moment work was saved or
    submitted, so "not answered" is a stored fact rather than the absence of a
    row. A marker sees all of them in the paper's own order, and a question the
    student left blank is visibly blank instead of quietly missing.

    ── THE EVIDENCE IS NEXT TO THE QUESTION THAT ASKED FOR IT ──────────────
    Grouped by question, because that is the only grouping in which "did they
    answer what I asked" is answerable. A photograph of working filed under
    Question 2 must appear under Question 2, not in a list below the whole paper
    where a marker has to guess which question it belongs to.

    ── INLINE MEDIA ONLY WHERE IT IS SAFE TO RENDER ────────────────────────
    `EvidenceMedia::mayStreamInline()` decides, from the STORED mime against a
    strict per-kind allowlist, whether an item gets a preview. A document always
    downloads. A link is rendered as a link with `rel="noopener noreferrer"`, and
    never fetched server-side.

    ── RECORDING IS STATED AS WHAT IT IS ───────────────────────────────────
    Where an item was captured in the browser, the panel says so. That is
    provenance the student supplied with the file; the server created the item
    only from a file it actually received, so this is a description of a
    received file and not a claim that anything was recorded.
--}}
@php
    $item = \App\Models\AssignmentSubmissionItem::class;
    $media = app(\App\Support\Assignments\EvidenceMedia::class);
    $evidenceByQuestion = $submission->items->whereNotNull('assignment_question_id')->groupBy('assignment_question_id');

    // The running total, CALCULATED from the fields as they are typed. Never sent
    // to the server and never trusted - the server recomputes it from what is
    // actually stored.
    $total = 0.0;
    $counted = 0;
    foreach ($questions as $question) {
        $existingMark = ($answers[$question->id] ?? null)?->marks_awarded;
        if ($existingMark !== null) {
            $total += (float) $existingMark;
            $counted++;
        }
    }
@endphp

<form method="POST" data-as-question-total
      action="{{ route('teacher.course_offerings.assignments.submissions.grade_by_question', [$offering->id, $assignment->id, $submission->id]) }}">
    @csrf

    <div class="as-surface border rounded p-3 mb-3" data-testid="as-question-marking">
        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
            <h6 class="mb-0">Mark each question</h6>
            <span class="small text-nowrap" data-testid="as-question-running-total">
                Total so far:
                <strong data-as-total-value>{{ rtrim(rtrim(number_format($total, 2), '0'), '.') }}</strong>
                / {{ $assignment->max_marks }}
                <span class="text-muted" data-as-total-count>({{ $counted }} of {{ $questions->count() }} marked)</span>
            </span>
        </div>

        <p class="small text-muted mb-3">
            The total above is calculated from the question marks as you type them.
            It is not saved &mdash; it is recalculated from what is stored when you
            save, so it can never disagree with the marks you give.
        </p>

        @foreach($questions as $question)
            @php
                $qid = (int) $question->id;
                $row = $answers[$qid] ?? null;
                $evidence = $evidenceByQuestion->get($qid, collect());
            @endphp

            <article class="border rounded p-3 mb-3" data-testid="as-mark-question-{{ $qid }}">
                <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-1">
                    <h6 class="mb-0" data-testid="as-mark-question-number">
                        Question {{ $loop->iteration }}
                        <span class="text-muted small">of {{ $questions->count() }}</span>
                    </h6>
                    <span class="small text-nowrap" data-testid="as-mark-question-max">
                        worth {{ $question->marksLabelWithUnit() }}
                        @if(! $question->is_required)
                            <span class="text-muted">&middot; optional</span>
                        @endif
                    </span>
                </div>

                @if($question->heading)
                    <p class="small fw-semibold mb-1">{{ $question->heading }}</p>
                @endif
                <div class="as-body mb-2 piie-prose">{!! $question->prosePrompt() !!}</div>

                {{-- ── WHAT THE STUDENT ACTUALLY SUBMITTED FOR THIS QUESTION ── --}}
                <div class="border rounded p-2 mb-2" data-testid="as-mark-evidence-{{ $qid }}">
                    <h6 class="small text-uppercase text-muted mb-2">Student answer and evidence</h6>

                    @if($row?->hasWrittenAnswer())
                        <div class="as-surface small mb-2" data-testid="as-mark-written-{{ $qid }}">
                            {!! $row->text_response !!}
                        </div>
                    @endif

                    @if($evidence->isNotEmpty())
                        <ul class="list-group list-group-flush">
                            @foreach($evidence as $piece)
                                <li class="list-group-item px-0">
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                        <div style="min-width:0">
                                            <div class="fw-semibold text-truncate">{{ $piece->displayName() }}</div>
                                            <div class="small text-muted">
                                                {{ $piece->kindLabel() }}
                                                @if($piece->sizeLabel()) &middot; {{ $piece->sizeLabel() }} @endif
                                                @if($piece->wasRecordedInBrowser())
                                                    <span data-testid="as-mark-recorded">&middot; recorded in the browser</span>
                                                @endif
                                            </div>
                                            @if($piece->note)
                                                <div class="small"><em>{{ $piece->note }}</em></div>
                                            @endif
                                        </div>
                                        <div class="flex-shrink-0">
                                            @if($piece->hasFile())
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="{{ route('teacher.course_offerings.assignments.submissions.evidence', [$offering->id, $assignment->id, $submission->id, $piece->id]) }}"
                                                   data-testid="as-mark-evidence-open">Open</a>
                                            @elseif($piece->hasUsableLink())
                                                {{-- `noopener noreferrer` so the target cannot reach
                                                     back into this page, and the URL is
                                                     never fetched by the server. --}}
                                                <a class="btn btn-sm btn-outline-primary"
                                                   href="{{ $piece->url }}" target="_blank" rel="noopener noreferrer"
                                                   data-testid="as-mark-evidence-link">Open link</a>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- An IMAGE, AUDIO or VIDEO gets a player IN the page.
                                         A document never does: `mayStreamInline()` is
                                         false for it, and a PDF rendered in a page is a
                                         document execution surface. --}}
                                    @if($media->mayStreamInline($piece))
                                        @php $url = route('teacher.course_offerings.assignments.submissions.media', [$offering->id, $assignment->id, $submission->id, $piece->id]); @endphp
                                        <div class="mt-1" data-testid="as-mark-evidence-preview">
                                            @if($piece->kind === $item::KIND_IMAGE)
                                                <img src="{{ $url }}" alt="Evidence for question {{ $loop->parent->iteration }}"
                                                     class="img-fluid rounded border" style="max-height:320px">
                                            @elseif($piece->kind === $item::KIND_AUDIO)
                                                <audio controls preload="metadata" src="{{ $url }}" class="w-100">
                                                    Your browser cannot play this recording. Download it to listen.
                                                </audio>
                                            @elseif($piece->kind === $item::KIND_VIDEO)
                                                <video controls preload="metadata" src="{{ $url }}"
                                                       class="w-100 rounded border" style="max-height:320px">
                                                    Your browser cannot play this video. Download it to watch.
                                                </video>
                                            @endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if(! $row?->hasWrittenAnswer() && $evidence->isEmpty())
                        <p class="small text-warning mb-0" data-testid="as-mark-unanswered">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            Not answered.
                        </p>
                    @endif
                </div>

                <div class="row g-2">
                    <div class="col-12 col-sm-4">
                        <label class="form-label small" for="as-qmark-{{ $qid }}">
                            Marks
                        </label>
                        <input type="number" step="0.5" min="0" max="{{ $question->marksLabel() }}"
                               class="form-control @error('marks.'.$qid) is-invalid @enderror"
                               id="as-qmark-{{ $qid }}"
                               name="marks[{{ $qid }}]"
                               data-as-question-mark
                               data-max="{{ $question->marksLabel() }}"
                               value="{{ old('marks.'.$qid, $row?->marks_awarded) }}"
                               data-testid="as-mark-input-{{ $qid }}">
                        <div class="form-text">Out of {{ $question->marksLabelWithUnit() }}. Leave blank for not marked.</div>
                        @error('marks.'.$qid)<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Marking feedback is where an examiner writes MATHEMATICS, so
                         it gets the same editor as the question. A note like "your
                         rearrangement is right, but 4,200 x (1 + 0.05)^2 is not
                         4,200 x 1.05" needs a superscript and a sign, and the
                         student reads this on the same page as their released
                         result - so it should not be the only plain-text surface in
                         the journey.

                         `maxlength` is gone deliberately. It counted MARKUP, so a
                         marker who formatted their feedback would have been refused
                         at save for a length they could neither see nor reason
                         about. The column is the real bound. --}}
                    <div class="col-12 col-sm-8">
                        <x-academic-editor
                            :name="'feedback_by_question['.$qid.']'"
                            :id="'as-qfeedback-'.$qid"
                            :label="'Feedback on question '.($loop->iteration ?? $qid)"
                            :value="old('feedback_by_question.'.$qid, $row?->feedback)"
                            :testid="'as-mark-feedback-'.$qid"
                            :height="200"
                            :rows="3"
                            placeholder="What was right, and what to do differently. Use the toolbar for worked steps, symbols and equations."
                        />
                    </div>
                </div>
            </article>
        @endforeach

        <x-academic-editor
            name="feedback"
            id="as-qfeedback-overall"
            label="Overall feedback"
            :value="old('feedback', $submission->feedback)"
            testid="as-feedback-overall"
            :height="240"
            :rows="4"
            placeholder="A summary for the student, on top of the per-question notes."
            help="Shown with the per-question feedback, and only once you return the result."
        />

        <button type="submit" class="btn btn-primary w-100" data-testid="as-save-question-marks">
            Save these marks
        </button>
    </div>
</form>
