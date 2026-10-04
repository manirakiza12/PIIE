{{--
    One question's authoring fields.

    ── USED TWICE, FOR ADDING AND FOR EDITING ───────────────────────────────
    A form's field set that exists in two places will drift, and the drift here
    would be invisible until it mattered: an "Add" form that accepted an answer
    type the "Edit" form could not, so a question created one way could never be
    corrected the other.

    ── `marks` IS ACCEPTED AT ZERO, DELIBERATELY ───────────────────────────
    A lecturer building a paper may not have decided the split yet, and refusing to
    save a question until it is worth marks would make it impossible to build a
    paper up over several sittings. The "every question is worth marks" rule is
    enforced at PUBLICATION instead - which is the moment it actually matters -
    and is listed on the integrity panel so it is visible long before that.

    ── THE ANSWER TYPES ARE A CHECKLIST, AND "ONE OF" IS THE DEFAULT ────────
    Ticking two kinds and leaving "all of them" unticked means "one of these is
    enough", which is what "record audio OR upload audio" means to a student.
    Requiring every ticked kind is a real option and is opt-in.

    ── NO MICROWHERE ────────────────────────────────────────────────────────
    Choosing "audio" on this form says a student MAY hand in an audio file. It is
    not a reason to ask for a microphone, and nothing on this page touches
    `getUserMedia`. The recorder exists only on the student's page, inside a button
    they press.
--}}
@php
    $item = \App\Models\AssignmentSubmissionItem::class;
    $existing = $question?->acceptedResponseKinds() ?? [];
    $editing = $question !== null;
@endphp

<div class="mb-3">
    <label class="form-label small" for="aq-heading-{{ $editing ? $question->id : 'new' }}">
        Short heading <span class="text-muted">(optional)</span>
    </label>
    <input type="text" class="form-control" maxlength="191"
           id="aq-heading-{{ $editing ? $question->id : 'new' }}" name="heading"
           value="{{ old('heading', $question?->heading) }}"
           placeholder="Gross profit">
    <div class="form-text">
        A few words to find this question again on the marking screen. The question
        itself goes below, and the heading never replaces it.
    </div>
</div>

{{--
    THE ACADEMIC EDITOR, and the same one the student answers in.

    A set of figures wants a table, a formula wants a superscript, "less than or
    equal to" wants the sign rather than the words, and a multi-step question wants
    a numbered list so the student can see the parts and answer them in order.

    The symmetry is the argument that settles it: a student now answers this exact
    question with this exact editor, so a question set in formatting the answer box
    cannot produce is a question asking for something. An examiner has to be able to
    set work in the medium the answer comes back in.

    The component renders the label and the help text, so the old ones are replaced
    rather than left beside it - two labels and two help texts saying different
    things is worse than either alone.

    Progressive enhancement: the script adds a class to the document only when the
    editor actually starts, and the stylesheet hides the source field only under
    that class. Without the editor this is still a working multi-line text field
    that posts the same `name`.
--}}
<x-academic-editor
    name="prompt"
    :id="'aq-prompt-'.($editing ? $question->id : 'new')"
    label="The question"
    :value="old('prompt', $question?->prompt)"
    :testid="'aq-prompt-'.($editing ? $question->id : 'new')"
    :height="240"
    placeholder="Calculate the gross profit from the figures below. Use the toolbar for tables, numbered steps, symbols and equations."
    :help="'Formatting and lists are kept, and this is the same editor your students answer in. The same sanitizer every other piece of PIIE rich text passes through is applied when you save.'"
/>

<div class="row g-3 mb-3">
    <div class="col-12 col-sm-4">
        <label class="form-label small" for="aq-marks-{{ $editing ? $question->id : 'new' }}">
            Marks for this question
        </label>
        <input type="number" step="0.5" min="0" class="form-control"
               id="aq-marks-{{ $editing ? $question->id : 'new' }}" name="marks"
               value="{{ old('marks', $question?->marks ?? 5) }}"
               data-testid="aq-field-marks">
        <div class="form-text">The questions must add up to the assignment total.</div>
    </div>

    <div class="col-12 col-sm-8">
        <span class="form-label small d-block">Required?</span>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" value="1"
                   name="is_required" id="aq-req-{{ $editing ? $question->id : 'new' }}-1"
                   @if(old('is_required', $question?->is_required ?? true) !== '0') checked @endif>
            <label class="form-check-label small" for="aq-req-{{ $editing ? $question->id : 'new' }}-1">
                Required &mdash; cannot be left blank
            </label>
        </div>
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" value="0"
                   name="is_required" id="aq-req-{{ $editing ? $question->id : 'new' }}-0"
                   @if(old('is_required', $question?->is_required ?? true) === '0') checked @endif>
            <label class="form-check-label small" for="aq-req-{{ $editing ? $question->id : 'new' }}-0">
                Optional
            </label>
        </div>
        <div class="form-text">
            Unrelated to whether the assignment is required for its
            <em>module</em> &mdash; that is set on the assignment itself.
        </div>
    </div>
</div>

<div class="mb-2">
    <span class="form-label small d-block">What answers does this question accept?</span>

    <div class="row g-2">
        @foreach($configurableKinds as $kind)
            <div class="col-12 col-md-6 col-xl-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="{{ $kind }}"
                           id="aq-kind-{{ $editing ? $question->id : 'new' }}-{{ $kind }}"
                           name="response_kinds[]"
                           data-testid="aq-kind-{{ $kind }}"
                           @if(in_array($kind, $existing, true)) checked @endif>
                    <label class="form-check-label small"
                           for="aq-kind-{{ $editing ? $question->id : 'new' }}-{{ $kind }}">
                        {{ $item::KIND_LABELS[$kind] ?? $kind }}
                        @if($kind === $item::KIND_AUDIO || $kind === $item::KIND_VIDEO)
                            <span class="text-muted d-block" style="font-size:.8em">
                                upload, or record in the browser
                            </span>
                        @endif
                    </label>
                </div>
            </div>
        @endforeach
    </div>

    <div class="form-text mt-1">
        Tick only what you want. A student sees ONLY the answer types ticked here,
        so a question asking for a photograph does not also offer a document box.
    </div>
</div>

<div class="form-check mb-2">
    <input class="form-check-input" type="checkbox" value="1" name="require_all"
           id="aq-all-{{ $editing ? $question->id : 'new' }}"
           @if(old('require_all', $question->require_all ?? false)) checked @endif>
    <label class="form-check-label small" for="aq-all-{{ $editing ? $question->id : 'new' }}">
        The student must provide <strong>every</strong> type ticked above
    </label>
    <div class="form-text">
        Leave this unticked and <strong>one</strong> of them is enough &mdash; which
        is what &ldquo;record it or upload it&rdquo; normally means.
    </div>
</div>
