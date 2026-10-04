@extends('student.navigation')
@section('content')
<style>
    /* Deterrent-level lockdown, not a security boundary — a determined
       student can always defeat client-side controls (a second device, a
       screenshot, disabling JS). This raises the bar for casual copying and
       gives staff a proctoring trail to review; it is not a substitute for
       exam design that assumes some attempts are dishonest. */
    #examTakeRoot {
        user-select: none;
        -webkit-user-select: none;
    }
    #examTakeRoot textarea,
    #examTakeRoot input[type="text"] {
        user-select: text;
        -webkit-user-select: text;
    }
    @media print {
        #examTakeRoot { display: none !important; }
        body::after {
            content: "{{ get_phrase('Printing is disabled during this exam.') }}";
            display: block;
            text-align: center;
            padding: 40px;
            font-size: 20px;
        }
    }
    #fullscreenWarning {
        position: fixed; inset: 0; z-index: 2000;
        background: rgba(20, 20, 20, .92);
        color: #fff;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center; padding: 30px;
    }
    .save-status { font-size: 12px; }
    .save-status.is-saving { color: #b58900; }
    .save-status.is-saved { color: #0f6e3d; }
    .save-status.is-dirty { color: #b42318; }
    .save-status.is-retrying { color: #7a5af8; }
    .save-status.is-failed { color: #b42318; }
    .question-nav-dot {
        width: 32px; height: 32px; border-radius: 6px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 12px; font-weight: 600; text-decoration: none;
        border: 1px solid #d0d5dd; color: #344054; margin: 2px;
    }
    .question-nav-dot.is-answered,
    .question-nav-dot.is-saved { background: #0f6e3d; border-color: #0f6e3d; color: #fff; }
    .question-nav-dot.is-dirty { background: #fff8e1; border-color: #b58900; color: #7a5a00; }
    .question-nav-dot.is-saving { background: #fff8e1; border-color: #b58900; color: #7a5a00; }
    .question-nav-dot.is-retrying { background: #f3f0ff; border-color: #7a5af8; color: #4a2fbf; }
    .question-nav-dot.is-failed { background: #fdecea; border-color: #b42318; color: #8a1c13; }
    .question-nav-dot.is-unanswered { background: #fff; border-color: #d0d5dd; color: #667085; }

    /* The state dot beside each navigator number, so the state is not carried by
       colour alone — a student who cannot distinguish the greens can still read it. */
    .question-nav-dot::after {
        content: ''; display: inline-block; width: 6px; height: 6px;
        border-radius: 50%; margin-left: 5px; vertical-align: middle;
        background: currentColor; opacity: .85;
    }

    /* The answer area must be UNMISTAKABLE. An answer control a student has to hunt
       for is an answer they may never fill in. */
    .online-exam-question-card .exam-answer-short-answer textarea,
    .online-exam-question-card .exam-answer-input {
        border: 2px solid #b7c2cf;
        border-radius: 8px;
    }
    .online-exam-question-card .exam-answer-short-answer textarea:focus,
    .online-exam-question-card .exam-answer-input:focus {
        border-color: #1a5490;
        box-shadow: 0 0 0 3px rgba(26, 84, 144, .18);
    }
    .online-exam-question-card .exam-answer-short-answer { margin-top: .5rem; }
    .exam-answer-short-answer .form-label { font-weight: 600; }

    /* One editor, one toolbar, and never a second one drawn over the first.
       The brief's screenshots showed duplicated rich-text toolbars; this pins the
       editor to a width the page can lay out, so a toolbar wraps instead of
       overflowing on a phone or overlapping the question above it. */
    .online-exam-question-card .piie-editor-shell { max-width: 100%; }
    .online-exam-question-card .note-toolbar { flex-wrap: wrap; }
    @media (max-width: 767.98px) {
        .online-exam-question-card .piie-editor-shell .note-editor,
        .online-exam-question-card .piie-editor-shell .note-editable { min-height: 180px; }
    }

    /* Rich text the LECTURER must read in a dense table: never let a formula or a
       long word push the table sideways off a phone screen. */
    .piie-prose { overflow-wrap: anywhere; word-break: break-word; }
</style>

<div id="fullscreenWarning" class="d-none">
    <i class="bi bi-exclamation-triangle-fill fs-1 mb-3"></i>
    <h4>{{ get_phrase('You have exited fullscreen') }}</h4>
    <p class="mb-4">{{ get_phrase('This exam requires fullscreen mode. This has been recorded. Return to fullscreen to continue.') }}</p>
    <button type="button" class="eBtn eBtn-primary" id="returnFullscreenBtn">{{ get_phrase('Return to Fullscreen') }}</button>
</div>

{{--
    THE PROTECTED EXAM AREA.

    `exam-restricted-mode.js` tests containment against this element, NOT against a
    list of element types. That distinction is the whole fix for "copying was still
    possible": the earlier tag list treated the question STATEMENT — a `<p>` — as
    outside the protected set, so a student who selected the question with the mouse
    and right-clicked got a fully working context menu, while Ctrl+C was blocked. The
    keyboard route was closed and the menu route was open.

    Everything the student is allowed to read during this exam is inside this element,
    so everything they might try to copy out of it is inside it too.
--}}
<div id="examTakeRoot" data-piie-exam-area="1">
    <div class="mainSection-title"><div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div class="d-flex flex-column">
                <h4>{{ $exam->title }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('student.online_exam.list') }}">{{ get_phrase('Exams') }}</a></li><li><a href="#">{{ get_phrase('Take Exam') }}</a></li></ul>
            </div>
            <div class="text-end">
                <span class="badge bg-danger fs-6" id="timer">--:--</span>
                <div id="overallSaveStatus" class="save-status mt-1"></div>
            </div>
        </div>
    </div></div></div>

    <div class="row">
        <div class="col-lg-9">
            <div class="eSection-wrap">
                @if($exam->instructions)
                <div class="alert alert-info mb-4 piie-prose"><strong>{{ get_phrase('Instructions') }}:</strong> {!! $exam->proseInstructions() !!}</div>
                @endif

                @foreach($questions as $qi => $q)
                @php
                    $existing = $existingAnswers->get($q->id);
                    $options = $optionOrders->get($q->id, []);
                    $publicQuestion = $q->public_question ?? null;
                    $questionType = $q->normalized_type;
                    $structuredOptions = is_array($publicQuestion) ? ($publicQuestion['options'] ?? []) : [];
                    $matchingLeft = is_array($publicQuestion) ? ($publicQuestion['left_items'] ?? []) : [];
                    $matchingRight = is_array($publicQuestion) ? ($publicQuestion['right_items'] ?? []) : [];
                    $orderingItems = is_array($publicQuestion) ? ($publicQuestion['items'] ?? []) : [];
                    $structuredPrompt = is_array($publicQuestion) ? ($publicQuestion['prompt'] ?? $q->question) : $q->question;
                    $existingPayload = optional($existing)->answer_payload ? json_decode($existing->answer_payload, true) : null;
                @endphp
                <div class="card mb-3 online-exam-question-card" data-question-id="{{ $q->id }}" id="question-block-{{ $q->id }}">
                    <div class="card-body">
                        {{--
                            THE QUESTION STATEMENT, AND WHAT IS SHOWN WHEN THERE IS NONE.

                            Exam 20's four prompts are all stored as `<p><br></p>`, and
                            `{!! prosePrompt() !!}` renders that as nothing. The card then
                            showed only a number and a mark allocation — the Q4 screenshot
                            the brief describes — and a student had no way to know the exam
                            paper was faulty.

                            So an empty prompt is now a NAMED, VISIBLE state rather than
                            blank space. It is not repaired: inventing a question would be
                            worse than admitting one is missing, and the row is left exactly
                            as it is for the examiner to see.

                            `data-question-prompt="empty"` also gives the page's own checks
                            something to assert against — "is every question on this paper
                            readable" becomes testable instead of a judgement call. --}}
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1">
                                <p class="mb-2 piie-prose online-exam-question-statement"
                                   data-question-prompt="{{ $q->hasPrompt() ? 'present' : 'empty' }}">
                                    <strong>Q{{ $qi + 1 }}.</strong>
                                    @if($questionType !== 'fill_blank' || $q->question_schema_version === null)
                                        {!! $q->prosePrompt() !!}
                                    @endif
                                    <span class="badge bg-secondary ms-2">{{ $q->marks }} {{ get_phrase('mark(s)') }}</span>
                                </p>
                                @unless($q->hasPrompt())
                                    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert"
                                         data-testid="missing-question-prompt">
                                        <strong>{{ get_phrase('This question has no text.') }}</strong>
                                        {{ get_phrase('The exam paper is missing the wording for this question. Tell your invigilator now. You can still answer, but your answer cannot be matched to a question that does not exist.') }}
                                    </div>
                                @endunless
                            </div>
                            <span class="save-status flex-shrink-0" id="save-status-{{ $q->id }}" role="status" aria-live="polite"></span>
                        </div>

                        @if($questionType === 'fill_blank' && $q->question_schema_version !== null)
                            @foreach(preg_split('/(\[\[[a-z][a-z0-9_-]{0,31}\]\])/i', $structuredPrompt, -1, PREG_SPLIT_DELIM_CAPTURE) as $promptPart)
                                @if(preg_match('/^\[\[([a-z][a-z0-9_-]{0,31})\]\]$/i', $promptPart, $blankMatch))
                                    <input class="form-control d-inline-block exam-answer-input fill-blank-input" style="max-width:260px" type="text" data-question-id="{{ $q->id }}" data-answer-type="fill_blank" data-blank-id="{{ $blankMatch[1] }}" value="{{ data_get($existingPayload, 'blanks.'.$blankMatch[1], '') }}" aria-label="{{ get_phrase('Answer blank') }} {{ $blankMatch[1] }}">
                                @else
                                    {{ $promptPart }}
                                @endif
                            @endforeach
                        @elseif($questionType === 'multiple_select')
                            <div class="small text-muted mb-2">{{ get_phrase('Select all that apply.') }}</div>
                            @foreach($structuredOptions as $option)
                            <div class="form-check">
                                <input class="form-check-input exam-answer-input" type="checkbox" value="{{ $option['id'] }}"
                                       id="q{{ $q->id }}{{ $option['id'] }}" data-question-id="{{ $q->id }}" data-answer-type="multiple_select"
                                       @checked(in_array($option['id'], (array) data_get($existingPayload, 'selected_option_ids', []), true))>
                                <label class="form-check-label" for="q{{ $q->id }}{{ $option['id'] }}">{{ $option['label'] }}</label>
                            </div>
                            @endforeach
                        @elseif($questionType === 'numeric')
                            <input class="form-control eForm-control exam-answer-input" type="text" inputmode="decimal"
                                   data-question-id="{{ $q->id }}" data-answer-type="numeric"
                                   value="{{ data_get($existingPayload, 'value', optional($existing)->answer_text) }}"
                                   placeholder="{{ get_phrase('Enter a numerical answer') }}">
                        @elseif($questionType === 'matching')
                            <div class="small text-muted mb-2">{{ get_phrase('Select the matching item for each row.') }}</div>
                            @foreach($matchingLeft as $left)
                            <div class="row align-items-center mb-2"><div class="col-md-5">{{ $left['text'] }}</div><div class="col-md-7"><select class="form-select exam-answer-input" data-question-id="{{ $q->id }}" data-answer-type="matching" data-left-id="{{ $left['id'] }}"><option value="">{{ get_phrase('Select a match') }}</option>@foreach($matchingRight as $right)<option value="{{ $right['id'] }}" @selected(data_get($existingPayload, 'pairs.'.$left['id']) === $right['id'])>{{ $right['text'] }}</option>@endforeach</select></div></div>
                            @endforeach
                        @elseif($questionType === 'ordering')
                            <div class="small text-muted mb-2">{{ get_phrase('Arrange the items in the correct order.') }}</div>
                            <ol class="ordering-list list-group" data-question-id="{{ $q->id }}">@foreach($orderingItems as $item)<li class="list-group-item d-flex justify-content-between align-items-center" data-order-id="{{ $item['id'] }}"><span>{{ $item['text'] }}</span><span><button type="button" class="btn btn-sm btn-outline-secondary order-up" aria-label="Move up">↑</button> <button type="button" class="btn btn-sm btn-outline-secondary order-down" aria-label="Move down">↓</button></span></li>@endforeach</ol>
                        @elseif($q->type === 'mcq')
                            @foreach($options as $optKey => $optText)
                            <div class="form-check">
                                <input class="form-check-input exam-answer-input" type="radio"
                                       name="answers[{{ $q->id }}]" value="{{ $optKey }}"
                                       id="q{{ $q->id }}{{ $optKey }}" data-question-id="{{ $q->id }}" data-answer-type="option"
                                       {{ optional($existing)->selected_option === $optKey ? 'checked' : '' }}>
                                <label class="form-check-label" for="q{{ $q->id }}{{ $optKey }}">{{ strtoupper($optKey) }}. {{ $optText }}</label>
                            </div>
                            @endforeach
                        @elseif($q->type === 'true_false')
                            <div class="form-check">
                                <input class="form-check-input exam-answer-input" type="radio" name="answers[{{ $q->id }}]" value="true"
                                       id="q{{ $q->id }}t" data-question-id="{{ $q->id }}" data-answer-type="option"
                                       {{ optional($existing)->selected_option === 'true' ? 'checked' : '' }}>
                                <label class="form-check-label" for="q{{ $q->id }}t">{{ get_phrase('True') }}</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input exam-answer-input" type="radio" name="answers[{{ $q->id }}]" value="false"
                                       id="q{{ $q->id }}f" data-question-id="{{ $q->id }}" data-answer-type="option"
                                       {{ optional($existing)->selected_option === 'false' ? 'checked' : '' }}>
                                <label class="form-check-label" for="q{{ $q->id }}f">{{ get_phrase('False') }}</label>
                            </div>
                        @elseif($questionType === 'essay')
                            {{-- ESSAY: the rich-text editor.

                                 A derivation is a document: df/dx, a line of working, a
                                 boxed final value, a set of conditions. As raw text in a
                                 three-row box the student cannot lay it out, and the
                                 lecturer then marks against an unreadable transcript.

                                 `exam-answer-input` and `data-question-id` are
                                 PRESERVED deliberately — the autosave below finds this
                                 field by exactly those two, and dropping them would
                                 mount the editor while making the student's typing
                                 invisible to the page's own save routine.

                                 Images are refused here, as they are in lesson bodies
                                 and assignment instructions: an embedded picture is the
                                 same governed flag, one rule, applied through the
                                 toolbar, the paste path and the server-side filter.

                                 It is LABELLED, and the label is handed to the editable
                                 region the editor builds. Without one, a `<textarea>` that
                                 Summernote then hides is a control with no accessible
                                 name at all — announced by a screen reader as an empty
                                 text region, which is a question a student cannot
                                 answer with assistive technology. --}}
                            <x-academic-editor
                                name="answers[{{ $q->id }}]"
                                :id="'exam-answer-'.$q->id"
                                :value="optional($existing)->answer_text"
                                :placeholder="get_phrase('Type your essay answer here. Use the toolbar for headings, lists, tables and equations.')"
                                :rows="6"
                                :height="260"
                                :allow-images="false"
                                :label="get_phrase('Your answer')"
                                :field-attributes="[
                                    'class' => 'eForm-control exam-answer-input exam-answer-essay',
                                    'data-question-id' => (string) $q->id,
                                    'data-answer-type' => 'text',
                                ]" />
                        @else
                            {{-- SHORT ANSWER: a plain, labelled, always-working text box.

                                 This is a DELIBERATE change, and it is the fix for
                                 exam 20's question 4.

                                 Short Answer and Essay were both rendered through the
                                 rich-text editor. A short answer is a sentence or two —
                                 "State the formula for compound interest." A student types
                                 it in the box and gets on with the paper. But mounting a
                                 vendor editor for it meant that every one of the editor's
                                 failure modes became a way for a student to LOSE that
                                 answer, and the reported symptom was exactly that: Q4 showed
                                 only its number and marks, and the student had nothing to
                                 type into.

                                 The underlying cause is recorded in academic-editor.js: an
                                 exception raised while initialising ONE field aborts
                                 `attachAll` for the rest, and the stylesheet hides the
                                 source textarea as soon as the document is marked
                                 `piie-js`. A field the editor never reached is therefore
                                 hidden with nothing in its place.

                                 For a question whose entire answer is plain prose, that
                                 risk buys nothing. A real `<textarea>` cannot fail to
                                 mount, is keyboard accessible with no scripting at all,
                                 is labelled, is read by assistive technology as a text
                                 field rather than as a contenteditable region, and is
                                 found by the same autosave selector the editor was found
                                 by.

                                 The Essay branch above keeps the editor, where the
                                 formatting is worth the dependency.

                                 The saved value is still submitted as `answer_text`, which
                                 is what the marking screen and the sanitizer expect, so
                                 this changes how a student types a short answer and
                                 nothing about how it is stored, restored or marked.

                                 The id stays `exam-answer-{questionId}`, the SAME contract
                                 the essay field uses, because that id is what the label
                                 pair, the autosave status indicator and the page's own
                                 element lookups address. One written answer cannot be
                                 addressed and the other cannot, or the addressing
                                 becomes a thing to remember per question type. --}}
                            <div class="exam-answer-short-answer">
                                <label class="form-label" for="exam-answer-{{ $q->id }}">
                                    {{ get_phrase('Your answer') }}
                                </label>
                                <textarea
                                    class="form-control eForm-control exam-answer-input exam-answer-text"
                                    id="exam-answer-{{ $q->id }}"
                                    name="answers[{{ $q->id }}]"
                                    rows="3"
                                    data-question-id="{{ $q->id }}"
                                    data-answer-type="text"
                                    data-testid="short-answer-input"
                                    placeholder="{{ get_phrase('Type your answer here.') }}"
                                    aria-describedby="exam-answer-help-{{ $q->id }}">{{ optional($existing)->answer_text }}</textarea>
                                <div class="form-text" id="exam-answer-help-{{ $q->id }}">
                                    {{ get_phrase('Answers are saved automatically as you type.') }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
                @endforeach

                <div class="text-center mt-4">
                    <button type="button" class="eBtn eBtn-primary" id="finalSubmitBtn">{{ get_phrase('Submit Exam') }}</button>
                    <div class="small text-muted mt-2" id="submitBlockNotice" data-testid="submit-blocked-notice" hidden>
                        {{ get_phrase('Your exam has not been submitted because an answer could not be saved. Nothing has been lost — reconnect and press Submit Exam again.') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <div class="eSection-wrap" style="position:sticky; top:16px;">
                <h6>{{ get_phrase('Questions') }}</h6>
                {{--
                    The navigator's job is to tell a student WHICH questions still need
                    attention, and it can only do that if the four states are
                    distinguishable. It previously had one: "answered" (green) and
                    nothing at all. A question whose save had failed looked identical to
                    a question never attempted, which is precisely the question a student
                    would not go back to check.

                    So each state gets its own class, a text label for assistive
                    technology, and a title. The text is what a screen reader announces;
                    the colour is only the visual reinforcement.
                --}}
                <div class="mb-3" role="list" aria-label="{{ get_phrase('Question navigator') }}">
                    @foreach($questions as $qi => $q)
                        @php
                            $initialState = 'unanswered';
                            if ($existingAnswers->has($q->id)
                                && \App\Support\OnlineExams\OnlineExamMarking::hasResponse($existingAnswers->get($q->id))) {
                                $initialState = 'saved';
                            }
                        @endphp
                        <a href="#question-block-{{ $q->id }}"
                           class="question-nav-dot is-{{ $initialState }}"
                           id="nav-dot-{{ $q->id }}"
                           data-question-state="{{ $initialState }}"
                           data-testid="nav-dot"
                           aria-describedby="nav-dot-label-{{ $q->id }}"
                           aria-label="{{ get_phrase('Question') }} {{ $qi + 1 }}">{{ $qi + 1 }}</a>
                        <span class="visually-hidden" id="nav-dot-label-{{ $q->id }}" data-testid="nav-dot-label">
                            @switch($initialState)
                                @case('saved'){{ get_phrase('Answered and saved') }}@break
                                @default{{ get_phrase('Not answered yet') }}@break
                            @endswitch
                        </span>
                    @endforeach
                </div>
                <p class="small text-muted mb-0">
                    <i class="bi bi-info-circle"></i>
                    {{ get_phrase('Answers are saved automatically. You do not need to save manually.') }}
                </p>
            </div>
        </div>
    </div>
</div>

{{--
    THE LIMITS, STATED PLAINLY.

    A restricted mode that implied it could stop a student minimising the window,
    Alt+Tab-ing away or opening the exam on a second device would be making a promise
    no web page can keep, and a student who discovered it would reasonably distrust
    everything else the page says. So the boundary is written down where the student
    will read it.

    What IS enforced while this page is open: copy, cut, paste, drag, the context menu
    and their keyboard equivalents, including inside the rich-text editors. Typing,
    Enter, Backspace, caret movement and the formatting tools all continue to work —
    a student must still be able to correct their own work.
--}}
<div class="alert alert-light border small" data-testid="restricted-notice">
    <div class="fw-semibold">{{ get_phrase('Copying and pasting are disabled while this examination is open.') }}</div>
    <div class="text-muted">
        {{ get_phrase('This page cannot prevent you minimising the window, switching to another program, or opening the examination on a second device. Leaving this page is recorded and an administrator may review it.') }}
        {{ get_phrase('Leaving the page does not end your examination and does not affect your marks on its own.') }}
    </div>
</div>

<div class="alert alert-warning d-none" data-testid="focus-return-warning" role="alert"></div>

{{-- Shown once the configured threshold is reached, and then KEPT. See
     `M.onPersistentNotice` in the script block below. It is a notice to the student,
     not a sanction: nothing on this page ends an attempt or changes a mark. --}}
<div class="alert alert-danger d-none" data-testid="persistent-integrity-banner" role="alert">
    <strong>{{ get_phrase('Leaving this page is being recorded.') }}</strong>
    <span data-testid="persistent-integrity-text"></span>
</div>

{{-- The approved accommodation for this paper, stated plainly when one exists.
     A student entitled to an adjustment must be able to see that it is in force, and
     an examiner must be able to see it from the student's own screen. --}}
@if($integritySettings['accommodation'])
    <div class="alert alert-info small" data-testid="integrity-accommodation" role="status">
        <i class="bi bi-universal-access-circle"></i>
        {{ get_phrase('An approved adjustment applies to this paper. Some on-screen controls are relaxed for you. Your examination and your answers are recorded exactly as everyone else’s are.') }}
    </div>
@endif

{{-- Shown only if an answer box is found not to be connected to autosave. Better a
     visible warning during an examination than a silently unrecorded answer. --}}
<div class="alert alert-danger d-none" data-testid="editor-health-warning" role="alert"></div>

@push('scripts')
<script>
/* Wire the restricted-mode module to the student's own page. Kept in the view because
   the messages are the ones this page shows, and because the module must stay usable
   — and testable — without any of this. */
(function () {
    "use strict";

    var M = window.PIIEExamRestricted;
    if (!M) { return; }

    function say(text, cls) {
        var box = document.querySelector('[data-testid="focus-return-warning"]');
        if (!box) { return; }
        box.classList.remove('d-none');
        box.classList.toggle('alert-' + (cls || 'warning'), true);
        box.textContent = text;
    }

    M.onBlocked = function (what) {
        var messages = {
            copy: "{{ get_phrase('Copying is disabled during this examination.') }}",
            cut: "{{ get_phrase('Cutting is disabled during this examination.') }}",
            paste: "{{ get_phrase('Pasting is disabled during this examination. Type your answer instead.') }}",
            'editor-paste': "{{ get_phrase('Pasting is disabled during this examination. Type your answer instead.') }}",
            dragstart: "{{ get_phrase('Dragging content into the examination is disabled.') }}",
            contextmenu: "{{ get_phrase('The right-click menu is disabled during this examination.') }}",
            'middle-click-paste': "{{ get_phrase('Pasting is disabled during this examination.') }}"
        };
        say(messages[what] || "{{ get_phrase('That action is disabled during this examination.') }}");
    };

    M.onFocusReturned = function (reason, awayMs) {
        if (awayMs === null) { return; }

        var seconds = Math.max(1, Math.round(awayMs / 1000));

        say(seconds < 5
            ? "{{ get_phrase('Welcome back. Your answers were saved automatically.') }}"
            : "{{ get_phrase('You were away for :seconds seconds. This has been recorded for the examiner.') }}".replace(':seconds', seconds));
    };

    /**
     * Past the configured threshold the notice stops being transient.
     *
     * The student is told ONCE that leaving the page is being recorded, and the banner
     * then stays until the paper is handed in. Two reasons it is not simply repeated:
     * a warning shown on every incident is a warning nobody reads, and a banner that
     * appears once and disappears is not evidence the institution could rely on.
     *
     * This is NOT a penalty. Nothing here ends the attempt, subtracts a mark, or
     * changes how an answer is marked — the record is for an examiner to act on.
     */
    M.onPersistentNotice = function (count) {
        var box = document.querySelector('[data-testid="persistent-integrity-banner"]');
        if (!box) { return; }
        box.classList.remove('d-none');
        var text = box.querySelector('[data-testid="persistent-integrity-text"]');
        if (text) {
            text.textContent = "{{ get_phrase('You have left this examination page :count times. This is recorded for your examiner. It does not affect your marks on its own, and your exam has not ended.') }}"
                .replace(':count', count);
        }
    };

    M.onConnectionChange = function (online) {
        say(online
            ? "{{ get_phrase('Your connection is back. Any unsaved answers will be retried.') }}"
            : "{{ get_phrase('Your connection has been lost. Answers already saved are safe and will be retried automatically.') }}");
    };
}());
</script>
@endpush

<form method="POST" action="{{ route('student.online_exam.timeout_submit', $submission->id) }}" id="timeoutForm" class="d-none">
    @csrf
</form>
<form method="POST" action="{{ route('student.online_exam.submit', $exam->id) }}" id="finalSubmitForm" class="d-none">
    @csrf
    <input type="hidden" name="submission_id" value="{{ $submission->id }}">
</form>

{{--
    RESTRICTED INTERACTION MODE — THIS PAGE ONLY.

    `piie-exam-restricted` is the switch `public/js/exam-restricted-mode.js` looks for,
    and it is set here because this is the one page where a student is answering an
    examination. Nothing global is touched: the clipboard everywhere else in PIIE is
    left alone, because protecting an exam at the cost of breaking every other form in
    the application would be a poor trade.

    The script also attaches to Summernote's own editable regions, since it binds on
    the document rather than on a node that does not exist until the editors mount.
--}}
@if($integritySettings['enabled'])
    <script>
        document.documentElement.classList.add('piie-exam-restricted');
        window.PIIEExamRestricted = window.PIIEExamRestricted || {};
        window.PIIEExamRestricted.endpoint = @json(route('student.online_exam.incident', $submission->id));
        window.PIIEExamRestricted.csrfToken = @json(csrf_token());
        window.PIIEExamRestricted.submissionId = @json((int) $submission->id);

        // The RESOLVED policy, sent from the server rather than read from config in the
        // browser. Two reasons it must not be decided client-side: a student can change
        // anything in their own browser, and the notice text on this page has to agree
        // with what the controls actually do.
        window.PIIEExamRestricted.settings = @json($integritySettings);
    </script>
    <script src="{{ asset('js/exam-restricted-mode.js') }}"></script>
@endif

@endsection

@push('scripts')
<script>
(function () {
    "use strict";

    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var submissionId = {{ $submission->id }};
    var fullscreenRequired = @json((bool) $exam->fullscreen_required);
    var saveAnswerUrl = "{{ route('student.online_exam.save_answer', $submission->id) }}";
    var heartbeatUrl = "{{ route('student.online_exam.heartbeat', $submission->id) }}";
    var proctoringUrl = "{{ route('student.online_exam.proctoring_event', $submission->id) }}";
    var recoveryKey = 'piie.exam.recovery.v1.' + {{ (int) $submission->student_id }} + '.' + {{ (int) $exam->id }} + '.' + submissionId;
    var serverAnswers = @json($serverAnswers ?? []);
    var recoveryState = {};
    var retryAttempts = {};
    var retryTimers = {};
    var tabLeaseKey = recoveryKey + '.tab';
    var tabId = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + Math.random();
    var tabConflict = false;

    // ── Navigation / copy-paste / print lockdown ───────────────────────
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    ['copy', 'cut', 'paste'].forEach(function (evt) {
        document.addEventListener(evt, function (e) { e.preventDefault(); });
    });
    // Warns against an accidental close/refresh, but must never fire for
    // the exam's own submit paths — otherwise every legitimate submission
    // (manual or timed-out) would trigger a confusing "leave site?" dialog
    // right as the form is navigating away on purpose.
    var isSubmitting = false;
    var timeoutFinalizing = false;
    window.addEventListener('beforeunload', function (e) {
        if (isSubmitting) return;
        e.preventDefault();
        e.returnValue = '';
    });

    // ── Timer: a DEADLINE, not a count of ticks ───────────────────────
    //
    // `remainingSeconds--` on a 1000 ms interval was the previous implementation and it
    // is wrong in a way that matters. A browser throttles timers in a background tab
    // to once a minute or slower, so the ticks do not fire once per second: the counter
    // runs SLOW and the student is shown — and effectively granted — more time than the
    // server will allow. The heartbeat eventually corrects it, so the bug is invisible
    // until it isn't.
    //
    // The authoritative thing is a WALL-CLOCK DEADLINE, which is what the server
    // already computes and hands over. Everything else derives from it:
    //
    //   boot      deadline = now + serverRemaining
    //   each tick remaining = ceil((deadline - now) / 1000)
    //   heartbeat deadline  = re-set from the server's own expires_at
    //
    // So a throttled tab, a sleeping laptop and a slow machine all show the same number,
    // a refresh cannot extend anything (the page is only ever handed the server's
    // remaining time), and the page can still FINALIZE the attempt promptly because the
    // tick is driven off elapsed time rather than off a tally.
    var serverRemainingSeconds = {{ (int) $remainingSeconds }};
    var deadlineAt = Date.now() + (serverRemainingSeconds * 1000);
    var remainingSeconds = serverRemainingSeconds;
    var timerEl = document.getElementById('timer');

    /** Recompute from the deadline. The only thing that writes `remainingSeconds`. */
    function remainingFromDeadline() {
        return Math.max(0, Math.ceil((deadlineAt - Date.now()) / 1000));
    }

    /** Adopt a server-authoritative remaining time, in place. */
    function syncDeadlineToServer(seconds) {
        if (typeof seconds !== 'number' || !isFinite(seconds)) { return; }
        deadlineAt = Date.now() + (Math.max(0, seconds) * 1000);
        remainingSeconds = remainingFromDeadline();
        renderTimer();
    }

    function finalizeTimeout() {
        if (timeoutFinalizing || isSubmitting) return;
        timeoutFinalizing = true;
        clearInterval(timerInterval);

        // The time is up whether or not the last save made it, so the attempt IS
        // finalized here — there is no later. What the flush decides is only whether
        // the outstanding questions are labelled honestly on the marking screen, so a
        // failed save is never mistaken for a question the student skipped. See
        // requirement 23: a persistence failure and a genuine blank are different facts
        // and must look different to a marker.
        flushPendingSaves().then(function (saved) {
            if (!saved) {
                Object.keys(dirtyQuestions).forEach(function (questionId) {
                    if (dirtyQuestions[questionId] || savingQuestions[questionId]) {
                        setStatus(questionId, 'failed');
                    }
                });
            }
            submitViaForm('timeoutForm');
        });
    }

    function renderTimer() {
        var m = Math.floor(Math.max(0, remainingSeconds) / 60);
        var s = Math.max(0, remainingSeconds) % 60;
        timerEl.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    renderTimer();
    // Derived from the deadline, so a throttled interval cannot slow the clock. See the
    // note above `serverRemainingSeconds`.
    var timerInterval = setInterval(function () {
        remainingSeconds = remainingFromDeadline();
        renderTimer();
        if (remainingSeconds <= 0) {
            clearInterval(timerInterval);
            finalizeTimeout();
        }
    }, 1000);

    function submitViaForm(formId) {
        isSubmitting = true;
        document.getElementById(formId).submit();
    }

    // ── Heartbeat: the authoritative clock, and server-side expiry ──
    //
    // This is what re-syncs the deadline, and it is a RE-SYNC rather than an
    // adjustment: the server computes the expiry from `started_at + duration` and
    // answers with its own remaining time, so a client whose wall clock has drifted —
    // or which has had its tab throttled for ten minutes — is corrected rather than
    // trusted.
    function heartbeat() {
        fetch(heartbeatUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.expired) {
                finalizeTimeout();
                return;
            }
            if (data.expires_at) {
                // `server_time` is subtracted rather than the browser's own clock, so a
                // student whose device clock is wrong is not penalised and not helped.
                var secondsLeft = Math.round((new Date(data.expires_at) - new Date(data.server_time)) / 1000);
                syncDeadlineToServer(secondsLeft);
            }
        })
        .catch(function () { /* offline — the local deadline stands until reconnected */ });
    }
    setInterval(heartbeat, 20000);
    heartbeat();

    // ── Proctoring events ───────────────────────────────────────────────
    function logProctoringEvent(eventType, metadata, useBeacon) {
        var payload = {
            submission_id: submissionId,
            event_type: eventType,
            metadata: metadata || null,
        };

        if (useBeacon && navigator.sendBeacon) {
            var data = new FormData();
            data.append('_token', csrfToken);
            data.append('submission_id', submissionId);
            data.append('event_type', eventType);
            navigator.sendBeacon(proctoringUrl, data);
            return;
        }

        fetch(proctoringUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        }).catch(function () {});
    }

    // Tab-switch detection.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            logProctoringEvent('tab_hidden', null, true);
        }
    });

    // Fullscreen enforcement.
    var fullscreenWarning = document.getElementById('fullscreenWarning');

    function requestFullscreen() {
        var el = document.documentElement;
        var request = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
        if (request) {
            request.call(el).catch(function () {});
        }
    }

    if (fullscreenRequired) {
        requestFullscreen();

        document.addEventListener('fullscreenchange', function () {
            if (document.fullscreenElement) {
                fullscreenWarning.classList.add('d-none');
                logProctoringEvent('fullscreen_started');
            } else {
                fullscreenWarning.classList.remove('d-none');
                logProctoringEvent('fullscreen_exited');
            }
        });

        var returnBtn = document.getElementById('returnFullscreenBtn');
        if (returnBtn) {
            returnBtn.addEventListener('click', requestFullscreen);
        }
    }

    // ── Editor content must be VISIBLE to autosave even with no bridge ──────
    //
    // The bridge (an `input` listener on the Summernote editable that copies its
    // document into the hidden source textarea) is what normally tells this page
    // that a written answer changed. Exam 20's question 3 was lost because that
    // listener was never bound: the editor was fully usable, the page said
    // "Saved", and the answer was never sent.
    //
    // Depending on one listener for the correctness of a student's whole paper is the
    // wrong shape. So the page does not rely on it alone: `captureEditorChanges()`
    // reads each answer control DIRECTLY and compares it with what the server last
    // acknowledged. If they differ, the question is dirty, whatever the bridge did.
    //
    // This is also the honest implementation of requirement 6 — "save rich-text
    // content using the editor API, not merely the underlying textarea's stale
    // value" — because `codeFor()` asks the editor, and falls back to the textarea
    // only when no editor is running.
    //
    // ── Answer autosave ─────────────────────────────────────────────────
    // Per-question dirty tracking; radios save immediately on change (a
    // discrete action), text answers debounce while typing, and a periodic
    // sweep catches anything still unsaved either way — matching all three
    // paths back to the one endpoint the server already trusts.
    var dirtyQuestions = {};
    var savingQuestions = {};
    var answerRevisions = {};
    var acknowledgedRevisions = {};
    var revisionConflicts = {};
    var lastWrittenRecovery = {};
    /**
     * What the SERVER last acknowledged, per question.
     *
     * The page's own `dirtyQuestions` flag is driven by DOM events, and a DOM event is
     * exactly what a broken editor bridge does not send. This is the independent
     * record: a content signature, compared against the live field rather than against
     * whether an event happened to fire. See `captureEditorChanges()`.
     */
    var acknowledgedSignatures = {};
    Object.keys(serverAnswers).forEach(function (id) {
        answerRevisions[id] = acknowledgedRevisions[id] = Number(serverAnswers[id].answer_revision || 0);
        acknowledgedSignatures[id] = signatureOf({
            selected_option: serverAnswers[id].selected_option,
            answer_text: serverAnswers[id].answer_text,
            answer_payload: serverAnswers[id].answer_payload
        });
    });

    /**
     * A per-question request counter.
     *
     * `savingQuestions` already serialises one request per question, so two responses
     * for the same question cannot normally overlap. This counter defends the
     * invariant that a response is only ever applied to the request that produced it,
     * which is what makes "a delayed acknowledgement must never overwrite newer
     * content" true by construction rather than by timing luck.
     */
    var requestSequence = {};
    /**
     * What each in-flight request actually SENT, per question.
     *
     * Without it, the periodic sweep cannot tell "the student typed again while the
     * save was open" from "the student is still looking at the text we are sending",
     * and it would either miss the newer draft or duplicate the in-flight request.
     */
    var inFlightSignatures = {};
    var debounceTimers = {};

    /**
     * A comparable one-line form of an answer value.
     *
     * ── WHY "EMPTY" IS ONE VALUE, NOT TWO ───────────────────────────────────
     *
     * The server stores "no answer" as NULL. A field the student has not touched reads
     * as `''`. Those are the same fact, and if they produced different signatures then
     * `captureEditorChanges()` would read every blank question as a fresh edit — which
     * is how an untouched answer becomes a queue of pointless saves, and how a student
     * who left a question blank would be nagged about one they never touched.
     *
     * So emptiness is resolved BEFORE the comparison: an empty string, a whitespace
     * string, and the empty rich-text documents `<p><br></p>` and `&nbsp;` all collapse
     * to null. That mirrors `OnlineExamMarking::isMeaningfulHtml()` on the server, so
     * the page and the marker agree on what counts as an answer.
     */
    function meaningfulText(text) {
        if (typeof text !== 'string') { return null; }
        var flat = text
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;|&#160;|&#xa0;/gi, ' ')
            .replace(/[\s\u200B\u200C\u200D\uFEFF\u2060]+/g, '')
            .trim();
        return flat === '' ? null : text;
    }

    function signatureOf(value) {
        if (!value) { return ''; }
        return JSON.stringify([
            value.selected_option || null,
            meaningfulText(value.answer_text),
            value.answer_payload || null
        ]);
    }

    /** Every question id on the page, derived from the DOM so it cannot drift. */
    function questionIds() {
        var ids = [];
        document.querySelectorAll('[data-question-id]').forEach(function (node) {
            var id = node.getAttribute('data-question-id');
            if (id && ids.indexOf(id) === -1 && document.getElementById('question-block-' + id)) {
                ids.push(id);
            }
        });
        return ids;
    }

    /**
     * READ EVERY ANSWER CONTROL AND MARK ANYTHING THE SERVER HAS NOT ACKNOWLEDGED.
     *
     * The independence from DOM events is the point. Exam 20's question 3 was lost
     * because a single `input` listener on the Summernote editable was never bound:
     * the editor looked perfect, the page said "Saved", and the answer was never sent.
     * Reading the controls directly makes that whole class of failure unable to lose
     * work, whatever the editor is doing.
     */
    function captureEditorChanges() {
        questionIds().forEach(function (questionId) {
            var value = currentValueFor(questionId);
            if (!value) { return; }

            var signature = signatureOf(value);

            // Nothing typed. Never manufacture dirtiness from an empty field: an empty
            // answer is a fact about the student, and inferring "unsaved" from it would
            // make the page nag about a question they have chosen to leave blank.
            if (signature === '' || signature === '[null,null,null]') {
                return;
            }

            var acknowledged = acknowledgedSignatures[questionId];

            if (acknowledged === undefined) {
                // The server has never confirmed anything for this question. Record
                // what it holds so the first genuine edit is detectable, but do not
                // claim the question is dirty on the strength of a value we have not
                // seen acknowledged.
                acknowledgedSignatures[questionId] = signature;
                return;
            }

            if (signature === acknowledged) {
                return;
            }

            // A save already in flight for exactly this content is not a new edit —
            // and must not be re-saved, or every sweep would duplicate the request.
            if (savingQuestions[questionId] && signature === inFlightSignatures[questionId]) {
                return;
            }

            // A genuine change from what the server holds.
            //
            // The revision is bumped when a request is IN FLIGHT even if the question
            // was already dirty, and that is the subtle part. The response the server
            // is about to send was produced for older content; if the revision has not
            // moved, `saveQuestion()` will read the response as confirmation of the
            // current text and mark a draft that the server does not have as "Saved".
            // Bumping here is what makes the arrival be recognised as stale and the
            // newer text be sent.
            if (!dirtyQuestions[questionId] || savingQuestions[questionId]) {
                answerRevisions[questionId] = (answerRevisions[questionId] || 0) + 1;
            }

            dirtyQuestions[questionId] = true;
            rememberLocalAnswer(questionId, value);
            setStatus(questionId, 'dirty');
        });
    }

    function setStatus(questionId, state) {
        var el = document.getElementById('save-status-' + questionId);
        if (!el) return;
        el.classList.remove('is-saving', 'is-saved', 'is-dirty', 'is-retrying', 'is-failed');
        if (state === 'saving') { el.textContent = "{{ get_phrase('Saving…') }}"; el.classList.add('is-saving'); }
        else if (state === 'saved') { el.textContent = "{{ get_phrase('Saved') }}"; el.classList.add('is-saved'); }
        else if (state === 'dirty') { el.textContent = "{{ get_phrase('Unsaved') }}"; el.classList.add('is-dirty'); }
        else if (state === 'retrying') { el.textContent = "{{ get_phrase('Offline / retrying') }}"; el.classList.add('is-retrying'); }
        else if (state === 'failed') { el.textContent = "{{ get_phrase('Save failed') }}"; el.classList.add('is-failed'); }
        else { el.textContent = ''; }

        // The navigator follows the status, with 'dirty' rendered as its own state.
        // It is deliberately NOT mapped to 'saving': a question the student has typed
        // into but whose save has not gone out yet is not the same as one currently in
        // flight, and conflating them hides a stuck save behind a reassuring colour.
        if (state === 'dirty') setNavState(questionId, 'dirty');
        else if (state) setNavState(questionId, state);
    }

    function setOverallStatus(state) {
        var el = document.getElementById('overallSaveStatus');
        if (!el) return;
        el.className = 'save-status mt-1';
        if (state === 'offline') { el.textContent = "{{ get_phrase('Offline — answers are kept on this device') }}"; el.classList.add('is-failed'); }
        else if (state === 'connected') { el.textContent = "{{ get_phrase('Connected') }}"; el.classList.add('is-saved'); }
        else if (state === 'retrying') { el.textContent = "{{ get_phrase('Retrying unsaved answers') }}"; el.classList.add('is-retrying'); }
        else if (state === 'conflict') { el.textContent = "{{ get_phrase('Another exam tab is open — keep only one tab active') }}"; el.classList.add('is-failed'); }
    }

    function readRecovery() {
        try {
            var raw = window.localStorage.getItem(recoveryKey);
            recoveryState = raw ? JSON.parse(raw) : {};
            if (!recoveryState || typeof recoveryState !== 'object') recoveryState = {};
            lastWrittenRecovery = JSON.parse(JSON.stringify(recoveryState));
        } catch (e) { recoveryState = {}; }
    }

    function writeRecovery() {
        try {
            var stored = JSON.parse(window.localStorage.getItem(recoveryKey) || '{}');
            if (!stored || typeof stored !== 'object') stored = {};
            Object.keys(lastWrittenRecovery).forEach(function (id) {
                // An acknowledgement in this tab must not erase another tab's draft.
                if (!recoveryState[id] && JSON.stringify(stored[id]) === JSON.stringify(lastWrittenRecovery[id])) {
                    delete stored[id];
                }
            });
            Object.keys(recoveryState).forEach(function (id) {
                if (JSON.stringify(recoveryState[id]) !== JSON.stringify(lastWrittenRecovery[id])) stored[id] = recoveryState[id];
            });
            if (Object.keys(stored).length === 0) window.localStorage.removeItem(recoveryKey);
            else window.localStorage.setItem(recoveryKey, JSON.stringify(stored));
            lastWrittenRecovery = JSON.parse(JSON.stringify(recoveryState));
        } catch (e) { /* private browsing or quota limits: server autosave still applies */ }
    }

    function claimTabLease() {
        try {
            var nowMs = Date.now();
            var current = JSON.parse(window.localStorage.getItem(tabLeaseKey) || 'null');
            if (current && current.tabId !== tabId && nowMs - (current.lastSeen || 0) < 15000) {
                tabConflict = true;
                setOverallStatus('conflict');
            }
            window.localStorage.setItem(tabLeaseKey, JSON.stringify({ tabId: tabId, lastSeen: nowMs }));
        } catch (e) {}
    }

    function refreshTabLease() {
        try { window.localStorage.setItem(tabLeaseKey, JSON.stringify({ tabId: tabId, lastSeen: Date.now() })); } catch (e) {}
    }

    claimTabLease();
    setInterval(refreshTabLease, 5000);
    window.addEventListener('storage', function (event) {
        if (event.key === tabLeaseKey && event.newValue) {
            try {
                var other = JSON.parse(event.newValue);
                if (other.tabId !== tabId) {
                    tabConflict = true;
                    setOverallStatus('conflict');
                }
            } catch (e) {}
        }
    });
    window.addEventListener('beforeunload', function () {
        try {
            var current = JSON.parse(window.localStorage.getItem(tabLeaseKey) || 'null');
            if (current && current.tabId === tabId) window.localStorage.removeItem(tabLeaseKey);
        } catch (e) {}
    });

    function rememberLocalAnswer(questionId, value) {
        var previous = recoveryState[questionId];
        recoveryState[questionId] = {
            selected_option: value.selected_option,
            answer_text: value.answer_text,
            answer_payload: value.answer_payload || null,
            revision: answerRevisions[questionId] || 0,
            acknowledged_revision: acknowledgedRevisions[questionId] || 0,
            server_updated_at: previous && Object.prototype.hasOwnProperty.call(previous, 'server_updated_at')
                ? previous.server_updated_at
                : ((serverAnswers[questionId] || {}).updated_at || null),
        };
        writeRecovery();
    }

    function applyRecoveredValue(questionId, value) {
        var checked = document.querySelectorAll('input.exam-answer-input[data-question-id="' + questionId + '"]');
        checked.forEach(function (input) {
            if (input.dataset.answerType === 'multiple_select') {
                input.checked = (value.answer_payload && Array.isArray(value.answer_payload.selected_option_ids))
                    ? value.answer_payload.selected_option_ids.indexOf(input.value) !== -1 : false;
            } else if (input.dataset.answerType === 'fill_blank') {
                input.value = value.answer_payload && value.answer_payload.blanks ? (value.answer_payload.blanks[input.dataset.blankId] || '') : '';
            } else if (input.dataset.answerType === 'matching') {
                input.value = value.answer_payload && value.answer_payload.pairs ? (value.answer_payload.pairs[input.dataset.leftId] || '') : '';
            } else input.checked = input.value === value.selected_option;
        });
        var textarea = document.querySelector('textarea.exam-answer-input[data-question-id="' + questionId + '"]');
        if (textarea) {
            // Restoring a recovered draft writes THROUGH the editor, not at the
            // textarea underneath it.
            //
            // Assigning `textarea.value` on a RUNNING Summernote instance changes
            // the hidden source but not the visible document: the student would be
            // told their unsaved answer was restored, be looking at an empty box,
            // and their next autosave would then overwrite the server with that
            // empty box - destroying precisely the work the recovery existed to
            // protect. `setCode` handles both states, so recovery works in the same
            // conditions a save does.
            var block = document.getElementById('question-block-' + questionId);
            var editor = window.PIIEAademicEditor;

            /**
             * A RESTORE MUST NEVER WIPE THE STUDENT'S ANSWER.
             *
             * Recovery runs on load and on every `visibilitychange`. Writing an empty
             * value THROUGH a live editor replaces real work with nothing, and the next
             * autosave then persists that emptiness — the destructive twin of the
             * persistence defect: the first loses an answer on the way to the server,
             * this one loses it on the way back.
             *
             * So a restore with nothing to restore is skipped, and a restore that would
             * replace content the editor already holds is skipped.
             */
            var restoring = value.answer_text || '';
            var live = (editor && editor.codeFor) ? editor.codeFor(textarea.name, block || document) : null;

            if (!restoring) {
                // Nothing recovered. Leave whatever is in the editor alone.
            } else if (editor && editor.setCode) {
                if (restoring !== live) {
                    editor.setCode(textarea.name, restoring, block || document);
                }
            } else {
                textarea.value = restoring;
            }
        }
        var numeric = document.querySelector('input.exam-answer-input[data-answer-type="numeric"][data-question-id="' + questionId + '"]');
        if (numeric && value.answer_payload) numeric.value = value.answer_payload.value ?? '';
        var ordering = document.querySelector('.ordering-list[data-question-id="' + questionId + '"]');
        if (ordering && value.answer_payload && Array.isArray(value.answer_payload.ordered_ids)) value.answer_payload.ordered_ids.forEach(function(id){ var item=ordering.querySelector('[data-order-id="'+id+'"]'); if(item) ordering.appendChild(item); });
    }

    function recoverLocalAnswers() {
        readRecovery();
        Object.keys(recoveryState).forEach(function (questionId) {
            var local = recoveryState[questionId];
            var server = serverAnswers[questionId] || {};
            if (!local) return;
            applyRecoveredValue(questionId, local);
            answerRevisions[questionId] = Math.max(answerRevisions[questionId] || 0, local.revision || 1);
            dirtyQuestions[questionId] = true;
            // Old timestamp-only drafts require explicit reconciliation as well.
            if (!Number.isInteger(local.acknowledged_revision)
                || Number(server.answer_revision || 0) > local.acknowledged_revision) {
                showRevisionConflict(questionId, server);
                return;
            }
            setStatus(questionId, 'retrying');
            markNavAnswered(questionId);
        });
        writeRecovery();
        Object.keys(dirtyQuestions).forEach(function (questionId) { saveQuestion(questionId); });
    }

    /**
     * The answer this question holds RIGHT NOW, read the way the question is answered.
     *
     * Returns null when the page renders NO control for the question at all, which is
     * the signal the health check below turns into a visible warning. It must never
     * return a fabricated empty answer for an absent field: an empty answer is a fact
     * about a student, and inventing one would overwrite their work with nothing.
     */
    function currentValueFor(questionId) {
        var fillBlanks = document.querySelectorAll('input.exam-answer-input[data-answer-type="fill_blank"][data-question-id="' + questionId + '"]');
        if (fillBlanks.length) {
            var blankValues = {};
            fillBlanks.forEach(function (input) { if (input.value !== '') blankValues[input.dataset.blankId] = input.value; });
            return { selected_option: null, answer_text: null, answer_payload: Object.keys(blankValues).length ? { type: 'fill_blank', blanks: blankValues } : null };
        }
        var multiple = document.querySelectorAll('input.exam-answer-input[data-answer-type="multiple_select"][data-question-id="' + questionId + '"]:checked');
        if (multiple.length) return { selected_option: null, answer_text: null, answer_payload: { type: 'multiple_select', selected_option_ids: Array.from(multiple).map(function (input) { return input.value; }) } };
        var numeric = document.querySelector('input.exam-answer-input[data-answer-type="numeric"][data-question-id="' + questionId + '"]');
        if (numeric) return numeric.value.trim() === '' ? { selected_option: null, answer_text: null, answer_payload: null } : { selected_option: null, answer_text: null, answer_payload: { type: 'numeric', value: numeric.value.trim() } };
        var matching = document.querySelectorAll('select.exam-answer-input[data-answer-type="matching"][data-question-id="' + questionId + '"]');
        if (matching.length) { var pairs={}; matching.forEach(function(s){ if(s.value) pairs[s.dataset.leftId]=s.value; }); return {selected_option:null,answer_text:null,answer_payload:Object.keys(pairs).length?{type:'matching',pairs:pairs}:null}; }
        var ordering = document.querySelector('.ordering-list[data-question-id="' + questionId + '"]');
        if (ordering) return {selected_option:null,answer_text:null,answer_payload:{type:'ordering',ordered_ids:Array.from(ordering.querySelectorAll('[data-order-id]')).map(function(i){return i.dataset.orderId;})}};
        var checked = document.querySelector('input.exam-answer-input[data-question-id="' + questionId + '"]:checked');
        if (checked) {
            return { selected_option: checked.value, answer_text: null, answer_payload: null };
        }

        // Scoped to the question's own card. `document.querySelector` alone would find
        // the FIRST textarea on the page with this question id, which is the same
        // element today and a different one the moment a page has two — and an answer
        // read from the wrong control is data loss.
        var block = document.getElementById('question-block-' + questionId);
        if (!block) return null;
        var textarea = block.querySelector('textarea.exam-answer-input[data-question-id="' + questionId + '"]');

        if (textarea) {
            // Read the editor's own document rather than the source textarea.
            //
            // Summernote updates the textarea as the student types, but a save fired
            // in the same tick as a paste, an undo, or a toolbar click can beat the
            // last edit to the field - and on an exam that window loses an answer.
            // `codeFor` asks the editor directly and falls back to the raw value
            // when no editor is running, so the plain-textarea path is unchanged.
            var editor = window.PIIEAademicEditor;
            var answerText = null;

            if (editor && editor.codeFor) {
                answerText = editor.codeFor(textarea.name, block);
            }

            // An editor that never mounted has been REVEALED as a plain textarea, so
            // this branch is reached and the student's typing is saved normally. The
            // distinction from a running editor is deliberately not made here: the
            // stored value is the same string either way, and the sanitizer accepts
            // both a plain string and a rich-text document.
            return {
                selected_option: null,
                answer_text: answerText === null || answerText === undefined ? textarea.value : answerText,
                answer_payload: null
            };
        }
        return null;
    }

    /**
     * THE NAVIGATOR MIRRORS THE REAL SAVE STATE.
     *
     * One function, so a dot can never say "answered" while the status label says
     * "Save failed" — the previous version only ever added an `is-answered` class and
     * never removed it, so a failed save left a green dot the student trusted.
     *
     * The four states the brief asks for are distinct here: unanswered, saving
     * (including retrying), saved, failed. Colour is reinforced by `aria-describedby`
     * on the dot, so the state is available without colour vision.
     */
    var NAV_STATES = {
        unanswered: { label: "{{ get_phrase('Not answered yet') }}" },
        saving: { label: "{{ get_phrase('Saving your answer') }}" },
        retrying: { label: "{{ get_phrase('Answer not saved yet — retrying') }}" },
        saved: { label: "{{ get_phrase('Answered and saved') }}" },
        failed: { label: "{{ get_phrase('Not saved — please tell your invigilator') }}" }
    };

    function setNavState(questionId, state) {
        var dot = document.getElementById('nav-dot-' + questionId);
        if (!dot) return;

        Object.keys(NAV_STATES).forEach(function (name) {
            dot.classList.toggle('is-' + name, name === state);
        });
        dot.setAttribute('data-question-state', state);

        var label = document.getElementById('nav-dot-label-' + questionId);
        if (label && NAV_STATES[state]) {
            label.textContent = NAV_STATES[state].label;
        }
    }

    /** Legacy alias, so the pre-existing call sites keep their meaning. */
    function markNavAnswered(questionId) {
        setNavState(questionId, 'saved');
    }

    function saveQuestion(questionId) {
        if (revisionConflicts[questionId]) return;
        var value = currentValueFor(questionId);
        if (!value) return;

        var requestRevision = answerRevisions[questionId] || 0;
        if (retryTimers[questionId]) return;
        if (savingQuestions[questionId]) {
            dirtyQuestions[questionId] = true;
            setStatus(questionId, 'retrying');
            return;
        }

        // The identity of THIS request. Every response is matched against it below, so
        // a response for an older request can never be mistaken for the current one —
        // which is what makes out-of-order delivery harmless rather than a race that
        // happens to be won by timing.
        var sequence = (requestSequence[questionId] = (requestSequence[questionId] || 0) + 1);
        var sentSignature = signatureOf(value);

        inFlightSignatures[questionId] = sentSignature;
        var superseded = function () {
            return requestSequence[questionId] !== sequence;
        };

        savingQuestions[questionId] = true;
        setStatus(questionId, 'saving');
        rememberLocalAnswer(questionId, value);

        fetch(saveAnswerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                submission_id: submissionId,
                question_id: questionId,
                answer_revision: requestRevision,
                selected_option: value.selected_option,
                answer_text: value.answer_text,
                answer_payload: value.answer_payload,
            }),
        })
        .then(async function (r) {
            // A body that is not JSON — a proxy error page, a gateway timeout, a login
            // redirect — must not throw out of this handler and leave the question stuck
            // in "saving" forever. It is a failed save, and it is reported as one.
            var data = null;
            try { data = await r.json(); } catch (e) { data = null; }

            if (superseded()) {
                // A newer request for this question has already been issued. This one is
                // discarded whole — including `savingQuestions`, which the newer request
                // owns — because applying it would acknowledge an older answer as saved.
                return;
            }

            savingQuestions[questionId] = false;
            delete inFlightSignatures[questionId];

            if (r.status === 409) {
                showRevisionConflict(questionId, data || {});
                return;
            }
            if (r.status === 422) {
                // Expired or submission no longer active — stop trying and
                // let the timer/heartbeat path handle finalising.
                dirtyQuestions[questionId] = true;
                setStatus(questionId, 'failed');
                return;
            }
            if (!r.ok) {
                dirtyQuestions[questionId] = true;
                scheduleRetry(questionId);
                return;
            }

            acknowledgedRevisions[questionId] = Number(data.answer_revision);
            serverAnswers[questionId] = {
                selected_option: value.selected_option,
                answer_text: value.answer_text,
                answer_payload: value.answer_payload,
                answer_revision: Number(data.answer_revision),
                updated_at: data.answer_updated_at,
            };
            // The server now holds EXACTLY what was sent. Recording that is what lets
            // `captureEditorChanges()` tell a real edit from a re-read of the same text.
            acknowledgedSignatures[questionId] = sentSignature;

            if ((answerRevisions[questionId] || 0) !== requestRevision) {
                // Typed again while the request was in flight. The newer content is
                // still unsaved, so this is NOT "saved" — the student is told "retrying"
                // and the newer text is sent immediately.
                dirtyQuestions[questionId] = true;
                setStatus(questionId, 'retrying');
                saveQuestion(questionId);
                return;
            }
            dirtyQuestions[questionId] = false;
            retryAttempts[questionId] = 0;
            delete recoveryState[questionId];
            writeRecovery();
            // "Saved" is reached HERE and nowhere else: after a 200 for this exact
            // request. No pending, failed, superseded or retried request can produce it.
            setStatus(questionId, 'saved');
            markNavAnswered(questionId);
        })
        .catch(function () {
            if (superseded()) { return; }
            savingQuestions[questionId] = false;
            delete inFlightSignatures[questionId];
            dirtyQuestions[questionId] = true;
            scheduleRetry(questionId);
        });
    }

    function showRevisionConflict(questionId, server) {
        serverAnswers[questionId] = server;
        revisionConflicts[questionId] = true;
        dirtyQuestions[questionId] = true;
        setStatus(questionId, 'failed');
        var container = document.getElementById('save-status-' + questionId);
        if (!container) return;
        container.textContent = 'Answer conflict. Your draft is kept on this device. ';
        function choice(label, keepLocal) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary ms-1';
            button.textContent = label;
            button.addEventListener('click', function () {
                var revision = Number(server.answer_revision || 0);
                acknowledgedRevisions[questionId] = revision;
                answerRevisions[questionId] = Math.max(answerRevisions[questionId] || 0, revision);
                delete revisionConflicts[questionId];
                if (keepLocal) {
                    if (answerRevisions[questionId] >= 4294967295) {
                        showRevisionConflict(questionId, server);
                        return;
                    }
                    answerRevisions[questionId]++;
                    rememberLocalAnswer(questionId, currentValueFor(questionId));
                    saveQuestion(questionId);
                } else {
                    applyRecoveredValue(questionId, server);
                    answerRevisions[questionId] = revision;
                    dirtyQuestions[questionId] = false;
                    delete recoveryState[questionId];
                    writeRecovery();
                    setStatus(questionId, 'saved');
                }
            });
            container.appendChild(button);
        }
        choice('Use server answer', false);
        choice('Save my draft', true);
    }

    /**
     * AN EDITOR THAT IS NOT WIRED CANNOT SAVE, AND MUST SAY SO.
     *
     * The autosave listens for `input` on each field. For a rich-text answer the field
     * is a hidden textarea fed by a bridge from the Summernote editable — so an editor
     * whose bridge never bound is fully usable to the student and completely invisible
     * to the save routine. Exam 19's question 3 failed exactly that way: typed into,
     * reported "Saved", stored as an empty document.
     *
     * So coverage is checked after boot and again after the module's retries have run.
     * If anything is unwired the student is told plainly, rather than being allowed to
     * type an exam that is not being recorded.
     */
    function reportEditorHealth() {
        var api = null;

        // The module is published on `window` under a name that file owns, so it is
        // discovered by capability rather than hardcoded — a rename cannot silently
        // disable this check.
        Object.keys(window).forEach(function (k) {
            if (/^PIIE/i.test(k) && window[k] && typeof window[k] === 'object'
                && typeof window[k].bridgeCoverage === 'function') {
                api = window[k];
            }
        });

        if (!api) { return; }

        var coverage = api.bridgeCoverage(document);
        if (!coverage) { return; }

        // ── A QUESTION WITH NO ANSWER CONTROL AT ALL ─────────────────────────
        //
        // Checked separately from the bridge, because it is a different failure with a
        // different consequence. A missing bridge still saves: `captureEditorChanges()`
        // reads the field directly. A MISSING FIELD saves nothing, ever, and there is
        // nothing for the student to type into — which is exactly exam 20's question 4.
        var orphanQuestions = questionIds().filter(function (id) {
            return currentValueFor(id) === null;
        });

        var box = document.querySelector('[data-testid="editor-health-warning"]');
        var problems = [];

        if (orphanQuestions.length) {
            problems.push("{{ get_phrase('These questions have no answer box on your screen:') }} "
                + orphanQuestions.join(', ')
                + ". {{ get_phrase('Tell your invigilator now.') }}");
        }

        // An editor that mounted and is NOT bridged looks perfect to the student and
        // records nothing through the bridge. It is still saved by the direct read
        // above, so it is reported as degraded rather than as a lost answer — a warning
        // that overstates the problem trains people to ignore the warning.
        if (coverage.unbridged && coverage.unbridged.length) {
            problems.push("{{ get_phrase('Some answer boxes lost their connection to autosave. Your typing is still being captured directly, but please tell your invigilator.') }}");
        }

        if (!problems.length) { return; }

        if (box) {
            box.classList.remove('d-none');
            box.textContent = problems.join(' ');
        }
    }

    function scheduleRetry(questionId) {
        if (revisionConflicts[questionId]) return;
        if (retryTimers[questionId]) return;
        var attempt = retryAttempts[questionId] || 0;
        var delay = Math.min(30000, 1000 * Math.pow(2, attempt));
        retryAttempts[questionId] = Math.min(attempt + 1, 5);
        setStatus(questionId, 'retrying');
        setOverallStatus('retrying');
        retryTimers[questionId] = setTimeout(function () {
            delete retryTimers[questionId];
            if (dirtyQuestions[questionId] && navigator.onLine !== false) saveQuestion(questionId);
        }, delay);
    }

    // Checked once now, and again after the editor module's own deferred-binding
    // retries have had a chance to run — the second pass is the one that matters,
    // because a late-created editable is exactly the case that must be caught.
    reportEditorHealth();
    setTimeout(reportEditorHealth, 400);
    setTimeout(reportEditorHealth, 1200);
    setTimeout(reportEditorHealth, 3000);

    document.querySelectorAll('.exam-answer-input').forEach(function (input) {
        var questionId = input.getAttribute('data-question-id');

        if (input.getAttribute('data-answer-type') === 'option') {
            input.addEventListener('change', function () {
                answerRevisions[questionId] = (answerRevisions[questionId] || 0) + 1;
                dirtyQuestions[questionId] = true;
                rememberLocalAnswer(questionId, currentValueFor(questionId));
                setStatus(questionId, 'dirty');
                if (revisionConflicts[questionId]) showRevisionConflict(questionId, serverAnswers[questionId]);
                saveQuestion(questionId);
            });

            if (input.checked) {
                markNavAnswered(questionId);
            }
        } else {
            input.addEventListener('input', function () {
                answerRevisions[questionId] = (answerRevisions[questionId] || 0) + 1;
                dirtyQuestions[questionId] = true;
                rememberLocalAnswer(questionId, currentValueFor(questionId));
                setStatus(questionId, 'dirty');
                if (revisionConflicts[questionId]) showRevisionConflict(questionId, serverAnswers[questionId]);

                clearTimeout(debounceTimers[questionId]);
                debounceTimers[questionId] = setTimeout(function () {
                    saveQuestion(questionId);
                }, 1500);
            });

            if (input.value.trim() !== '') {
                markNavAnswered(questionId);
            }
        }
    });

    document.querySelectorAll('select[data-answer-type="matching"]').forEach(function(select){
        select.addEventListener('change', function(){ var q=select.dataset.questionId; answerRevisions[q]=(answerRevisions[q]||0)+1; dirtyQuestions[q]=true; setStatus(q,'dirty'); saveQuestion(q); });
    });
    document.querySelectorAll('.ordering-list').forEach(function(list){ list.addEventListener('click', function(e){ var b=e.target.closest('button'); if(!b)return; var item=b.closest('[data-order-id]'); if(b.classList.contains('order-up')&&item.previousElementSibling) list.insertBefore(item,item.previousElementSibling); if(b.classList.contains('order-down')&&item.nextElementSibling) list.insertBefore(item.nextElementSibling,item); var q=list.dataset.questionId; answerRevisions[q]=(answerRevisions[q]||0)+1; dirtyQuestions[q]=true; setStatus(q,'dirty'); saveQuestion(q); }); });

    recoverLocalAnswers();

    window.addEventListener('offline', function () { setOverallStatus('offline'); });
    window.addEventListener('online', function () {
        setOverallStatus('connected');
        Object.keys(dirtyQuestions).forEach(function (questionId) {
            if (dirtyQuestions[questionId]) scheduleRetry(questionId);
        });
    });
    if (tabConflict) setOverallStatus('conflict');
    else setOverallStatus(navigator.onLine === false ? 'offline' : 'connected');

    // The safety-net sweep.
    //
    // Two jobs, and the first is the new one:
    //
    //   1. `captureEditorChanges()` — read every answer control and mark dirty anything
    //      the server has not acknowledged. This does not depend on a DOM event, so an
    //      editor whose bridge never bound still has its typing saved. Without it, the
    //      correctness of a whole paper rested on one `input` listener.
    //   2. re-issue saves for anything already dirty (a failed save awaiting retry).
    setInterval(function () {
        captureEditorChanges();

        Object.keys(dirtyQuestions).forEach(function (questionId) {
            if (dirtyQuestions[questionId]) {
                saveQuestion(questionId);
            }
        });
    }, 3000);

    /**
     * FLUSH EVERY PENDING EDIT, AND WAIT FOR THE SERVER TO CONFIRM IT.
     *
     * Called before the attempt is finalized, and it resolves TRUE only when every
     * question is clean AND acknowledged. Anything else resolves FALSE, and the caller
     * refuses to submit.
     *
     * Three things happen here that the previous version did not do:
     *
     *   `syncAll()`    pushes each editor's document into its source textarea, so the
     *                  last keystroke is not still sitting in the editable when the
     *                  flush runs.
     *   capture        reads every control directly, so a question the event-driven
     *                  paths missed is dirty before anything is finalised.
     *   deadline       the promise resolves FALSE on timeout rather than assuming
     *                  success. A submission must never be finalised on the strength of
     *                  a save that is still in flight.
     */
    function flushPendingSaves() {
        var editor = window.PIIEAademicEditor;

        if (editor && editor.syncAll) {
            try { editor.syncAll(document); } catch (e) { /* best effort */ }
        }

        captureEditorChanges();

        Object.keys(dirtyQuestions).forEach(function (questionId) {
            if (dirtyQuestions[questionId]) saveQuestion(questionId);
        });

        return new Promise(function (resolve) {
            var deadline = Date.now() + 10000;
            (function waitForSaves() {
                var pending = Object.keys(dirtyQuestions).some(function (id) {
                    return dirtyQuestions[id] || savingQuestions[id];
                });
                if (!pending) { resolve(true); return; }
                if (Date.now() >= deadline) {
                    // Timed out with work outstanding. FALSE, so the caller stops and
                    // tells the student — which is the only safe answer. Resolving true
                    // here is how an incomplete attempt becomes a published result.
                    resolve(false);
                    return;
                }
                setTimeout(waitForSaves, 100);
            })();
        });
    }

    // ── Final submit ─────────────────────────────────────────────────────
    //
    // The attempt is finalized ONLY after every pending answer has been acknowledged
    // by the server. If the flush fails, nothing is submitted: the button stays
    // active, the failure is stated, and the student can retry. Nothing about the
    // attempt is lost by not submitting — that is the whole point.
    var submitting = false;

    document.getElementById('finalSubmitBtn').addEventListener('click', function () {
        if (submitting) { return; }

        var unansweredCount = document.querySelectorAll('.question-nav-dot[data-question-state="unanswered"]').length
            + document.querySelectorAll('.question-nav-dot[data-question-state="dirty"]').length;

        var confirmMsg = unansweredCount > 0
            ? unansweredCount + " {{ get_phrase('question(s) are still unanswered.') }} {{ get_phrase('Submit anyway? You cannot change answers after submission.') }}"
            : "{{ get_phrase('Submit exam? You cannot change answers after submission.') }}";

        if (!window.confirm(confirmMsg)) {
            return;
        }

        var button = document.getElementById('finalSubmitBtn');
        button.disabled = true;
        submitting = true;

        var notice = document.getElementById('submitBlockNotice');
        if (notice) { notice.hidden = true; }

        flushPendingSaves().then(function (saved) {
            if (!saved) {
                // Recoverable: re-enable the control, mark every unsaved question, and
                // let the student try again. The 3-second sweep will also keep retrying
                // in the meantime, so a transient network failure resolves itself.
                button.disabled = false;
                submitting = false;
                questionIds().forEach(function (id) {
                    if (dirtyQuestions[id] || savingQuestions[id]) {
                        setStatus(id, savingQuestions[id] ? 'saving' : 'failed');
                    }
                });
                if (notice) { notice.hidden = false; }
                window.alert("{{ get_phrase('Your exam was NOT submitted because some answers could not be saved. Nothing has been lost. Check your connection and press Submit Exam again.') }}");
                return;
            }
            clearInterval(timerInterval);
            submitViaForm('finalSubmitForm');
        });
    });
})();
</script>
@endpush
