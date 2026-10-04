{{--
    ONE question's answer controls, and only the ones that question accepts.

    ── WHY THIS IS A PARTIAL AND NOT INLINED ────────────────────────────────
    The draft zone and the hand-in zone need identical fields. Two copies of a
    form's field set drift, and the drift here would be nasty: a student could
    prepare work the submit button then refuses, or worse, submit work they
    could not save. One partial, used twice, is the only way that cannot happen.

    ── ONLY THE ACCEPTED KINDS APPEAR ───────────────────────────────────────
    The fields come from the QUESTION's own allowlist. A question that accepts an
    image has no document box, no audio box, no link box - so a kind the lecturer
    did not ask for cannot even be sent, and `QuestionResponseService` re-checks it
    server-side regardless. This is the "do not make every response field appear
    for every question" requirement, enforced by construction rather than by
    hiding a field with CSS.

    ── EVERY SLOT GETS ITS OWN ARRAY INDEX ──────────────────────────────────
    A question may accept audio AND a link, which is two slots. Both are posted as
    `items[N]`, so the index is a RUNNING COUNTER rather than a literal 0 - two
    slots both written as `items[0]` would mean the second silently overwrote the
    first, and a student who recorded an explanation and added a link would submit
    one of them and not know which.

    ── A FILE ALREADY ATTACHED IS STATED, NOT SILENTLY DROPPED ──────────────
    A browser cannot re-populate a file input. If a student attached a photograph
    last visit, this page has to say so and say whether choosing a new one
    replaces it - otherwise the browser's inability to restore it becomes silent
    data loss on submit.

    ── THE RECORDER ASKS FOR NOTHING UNTIL A PERSON PRESSES IT ──────────────
    A "Record" button is rendered only for a question that accepts audio or video,
    and it does not touch `getUserMedia` until it is pressed. No microphone, no
    camera, no permission prompt on page load, and no silent activation. Where
    recording is unsupported or permission is refused, the file picker beside it is
    the answer and this panel says so in words.

    Nothing here claims a recording succeeded. The panel only ever says a recording
    is ready TO ATTACH; whether it arrived is decided by the server, which creates
    the evidence item only from a file it actually received.
--}}
@php
    $item = \App\Models\AssignmentSubmissionItem::class;
    $kinds = $question->acceptedResponseKinds();
    $fieldBase = 'questions['.$question->id.']';

    // A RUNNING index, so a question accepting several kinds posts several slots
    // rather than overwriting one with another.
    $slot = 0;
@endphp

<div class="as-question border rounded p-3 mb-3" data-testid="as-question-{{ $question->id }}"
     data-question-id="{{ $question->id }}">

    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-1">
        <h6 class="mb-0" data-testid="as-question-number">
            Question {{ $loop->iteration }} of {{ $questionCount }}
        </h6>
        <span class="small text-nowrap" data-testid="as-question-marks">
            <span class="fw-semibold">{{ $question->marksLabelWithUnit() }}</span>
            @if(! $question->is_required)
                <span class="text-muted">&middot; optional</span>
            @endif
        </span>
    </div>

    @if($question->heading)
        <p class="small fw-semibold mb-1">{{ $question->heading }}</p>
    @endif

    {{-- Already sanitised on the way IN by QuestionService, through the same
         HtmlSanitizer as lesson bodies and assignment instructions. The one place
         {!! !!} is correct. --}}
    <div class="as-body mb-2 piie-prose">{!! $question->prosePrompt() !!}</div>

    <p class="small text-muted mb-2" data-testid="as-question-requirement">
        {{ $question->require_all ? 'You must provide' : 'Provide' }}:
        {{ $question->responseRequirementLabel() }}
    </p>

    @if($kinds === [])
        <div class="alert alert-warning py-2 small mb-0" data-testid="as-question-unconfigured">
            Your lecturer has not finished setting up this question. Contact them if you need it.
        </div>
    @else
        @if($question->acceptsWrittenResponse())
            {{--
                THE ACADEMIC EDITOR, not a plain textarea.

                A student answering "explain your reasoning" or "set out your
                working" needs headings, numbered steps, a table of figures, an
                equation and a superscript - and needs them in the browser, because
                the alternative is markup typed by hand into a box that shows nothing
                of what it will look like. A student who has just been taught
                quadratic equations should be able to type x squared and a summation
                sign, not a tag and an entity reference.

                It is the same component every lecturer surface uses, so the toolbar
                a student learns is the one their lecturer uses, and a fix to the
                editor lands everywhere at once.

                Progressive enhancement: the script adds a class to the document
                only when the editor actually starts, and the stylesheet hides the
                source field only under that class. With JavaScript off, or
                Summernote unavailable, this is still a working multi-line text field
                that posts the same name and is validated the same way. A student is
                never locked out of answering a question by a missing script.

                No maxlength: a rich-text document is measured in words and the stored
                value is markup, so a character cap would count the tag and refuse an
                answer for its length. The server's own limits are the ones that
                apply, and they are checked against meaningful text.
            --}}
            <x-academic-editor
                name="{{ $fieldBase }}[text]"
                :id="$prefix.'text-'.$question->id"
                :label="$item::KIND_LABELS[$item::KIND_TEXT]"
                :value="old($fieldBase.'[text]', $answer?->text_response)"
                :testid="'as-question-text-'.$question->id"
                :height="260"
                placeholder="Write your answer here. Use the toolbar for headings, numbered steps, tables, symbols and equations."
                :help="'Formatting is saved with your answer. If the toolbar does not appear, this box still works exactly the same way.'"
            />
        @endif

        @if($question->acceptsLink())
            @php
                $attachedLink = $evidence->firstWhere('kind', $item::KIND_LINK);
                $thisSlot = $slot++;
            @endphp
            <div class="mb-2" data-testid="as-question-slot-link-{{ $question->id }}">
                <label for="{{ $prefix }}link-{{ $question->id }}" class="form-label small">
                    {{ $item::KIND_LABELS[$item::KIND_LINK] }}
                </label>
                <input type="hidden" name="{{ $fieldBase }}[items][{{ $thisSlot }}][kind]" value="{{ $item::KIND_LINK }}">
                <input type="url" class="form-control" placeholder="https://"
                       id="{{ $prefix }}link-{{ $question->id }}"
                       name="{{ $fieldBase }}[items][{{ $thisSlot }}][url]"
                       data-testid="as-question-link-{{ $question->id }}"
                       value="{{ old($fieldBase.'[items]['.$thisSlot.'][url]', $attachedLink?->url) }}">
                <div class="form-text">A full http or https address.</div>
            </div>
        @endif

        {{-- ── File and recording controls, per accepted FILE kind ── --}}
        @foreach($question->acceptedFileKinds() as $kind)
            @php
                $attached = $evidence->firstWhere('kind', $kind);
                $recorded = $attached?->wasRecordedInBrowser() ?? false;
                $thisSlot = $slot++;
                $inputId = $prefix.'file-'.$kind.'-'.$question->id;
                $accept = $kind === $item::KIND_IMAGE ? 'image/*'
                    : ($kind === $item::KIND_AUDIO ? 'audio/*'
                    : ($kind === $item::KIND_VIDEO ? 'video/*' : implode(',', $item::extensionsFor($kind))));
            @endphp

            <div class="mb-3" data-testid="as-question-slot-{{ $kind }}-{{ $question->id }}">
                <label for="{{ $inputId }}" class="form-label small">
                    {{ $item::KIND_LABELS[$kind] }}
                </label>

                <input type="hidden" name="{{ $fieldBase }}[items][{{ $thisSlot }}][kind]" value="{{ $kind }}">

                <input type="file" class="form-control" id="{{ $inputId }}"
                       name="{{ $fieldBase }}[items][{{ $thisSlot }}][file]"
                       accept="{{ $accept }}"
                       data-testid="as-question-file-{{ $kind }}-{{ $question->id }}">

                <div class="form-text">
                    @if($attached)
                        Already attached: {{ $attached->displayName() }}
                        @if($attached->sizeLabel()) ({{ $attached->sizeLabel() }}) @endif
                        @if($recorded)
                            <span class="text-muted" data-testid="as-attached-recorded">&middot; recorded in the browser</span>
                        @endif
                        &mdash; choose a new file to replace it.
                    @else
                        Up to 20 MB.
                        Accepted: {{ implode(', ', $item::extensionsFor($kind)) }}.
                    @endif
                </div>

                {{-- ── WITHDRAWING AN ATTACHED FILE ────────────────────────────
                     A file input cannot express removal: choosing nothing is
                     indistinguishable from not touching it, and on a Submit the
                     browser cannot resend the file at all. Since an unused slot now
                     means "unchanged" rather than "empty", something has to be able
                     to say the opposite, or a file could never be taken back.

                     So this control exists, and it appears ONLY when a file is
                     actually attached - never on an empty slot, and never asking a
                     student to withdraw something they did not send. It is
                     irreversible once saved, so the wording says exactly that. --}}
                @if($attached)
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1"
                               id="{{ $prefix }}remove-{{ $kind }}-{{ $question->id }}"
                               name="{{ $fieldBase }}[items][{{ $thisSlot }}][remove]"
                               data-testid="as-question-remove-{{ $kind }}-{{ $question->id }}">
                        <label class="form-check-label small"
                               for="{{ $prefix }}remove-{{ $kind }}-{{ $question->id }}">
                            Withdraw this {{ strtolower($item::KIND_LABELS[$kind] ?? 'file') }} from my answer
                        </label>
                        <div class="form-text">
                            The saved copy and its file will be deleted. Choosing a
                            new file above replaces it instead.
                        </div>
                    </div>
                @endif

                {{-- ── The recorder, only where the question accepts this kind ──
                     Rendered as a panel that starts CLOSED and inert. The
                     permission request happens inside the button's click handler
                     and nowhere else. --}}
                @if($kind === $item::KIND_AUDIO || $kind === $item::KIND_VIDEO)
                    <div class="as-recorder mt-2"
                         data-as-recorder
                         data-kind="{{ $kind }}"
                         data-file-input-id="{{ $inputId }}"
                         data-preview-prefix="{{ $prefix }}rec-{{ $kind }}-{{ $question->id }}-">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-as-recorder-toggle
                                data-testid="as-record-toggle-{{ $kind }}-{{ $question->id }}">
                            {{ $kind === $item::KIND_AUDIO ? 'Record audio' : 'Record video' }}
                        </button>

                        <p class="small text-muted mt-2 mb-0" data-as-recorder-status
                           data-testid="as-record-status-{{ $kind }}-{{ $question->id }}"
                           role="status">
                            Nothing is recorded until you choose to. Your browser will ask your permission first.
                        </p>

                        {{-- Staged only AFTER a real recording ends. Empty means "no
                             recording ready", and the upload above is then the
                             whole story. --}}
                        <input type="hidden" data-as-recorder-staged
                               name="{{ $fieldBase }}[items][{{ $thisSlot }}][capture_method]" value="">
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
