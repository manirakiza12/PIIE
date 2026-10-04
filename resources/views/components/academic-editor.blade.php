{{--
    THE ACADEMIC EDITOR - the ONE rich-text surface in PIIE.

    WHY A SINGLE COMPONENT
    Four views previously carried their own copy of the toolbar, the table plugin,
    the sub/superscript pair and a failure notice, and they had already drifted
    apart. This component plus `public/js/academic-editor.js` is the only place any
    of that is decided now, so a lecturer who learns one editor knows them all and
    a fix applies everywhere at once.

    Progressive enhancement, and the reason for the class the script adds: the
    textarea underneath is the real submitted field. The stylesheet hides it only
    under `html.piie-js`, which the script adds when the editor actually starts.
    With JavaScript unavailable or Summernote missing, the reader sees a plain
    multi-line text field that posts the same value. The editor is never the only
    way to answer a question.

    The value is rendered as the field's own content, so a browser without the
    script shows the last saved markup rather than an empty box. That is a
    deliberate choice: swapping in stripped plain text instead would silently
    discard formatting the next time an author saved without JavaScript, which is
    data loss triggered by an unrelated failure.

    Errors are associated with the textarea by id, and the editor's editable
    region is handed the same accessible name in JS, so the control is LABELLED
    rather than merely present.
--}}

@props([
    'name',
    'id' => null,
    'label' => null,
    'value' => null,
    'placeholder' => null,
    'help' => null,
    'height' => 360,
    'allowImages' => true,
    'required' => false,
    'maxlength' => null,
    'testid' => null,
    'rows' => 10,
    'labelSuffix' => null,

    // Attributes for the SOURCE TEXTAREA, not the wrapper.
    //
    // Added for the online exam's attempt page, whose autosave identifies each
    // answer by `textarea.exam-answer-input[data-question-id="…"]` - the same way
    // it has always identified the plain box it replaced. Without this the editor
    // would mount cleanly and the student's typing would then be invisible to the
    // page's own save routine, which is the worst possible failure for an exam:
    // it looks like it is working.
    //
    // These land on the textarea, which remains the real submitted field, so they
    // are part of the form's contract with its own scripts rather than decoration.
    'fieldAttributes' => null,
])

@php
    // DETERMINISTIC, not unique. A page that genuinely needs two editors for one
    // concept (a draft zone and a submit zone) must pass an explicit `id`, which
    // is a decision worth making visibly rather than one to be handed silently by
    // a counter. A generated id that changed on every render would also make the
    // label/for pairing impossible to assert on.
    $fieldId = $id ?: 'rte-'.preg_replace('/[^A-Za-z0-9]+/', '-', str_replace(['[', ']'], '', $name));
    $labelId = $fieldId.'-label';
    $helpId = $fieldId.'-help';
    $errorId = $fieldId.'-error';
    $fieldError = $errors->first($name);

    $describedBy = trim(($help ? $helpId : '').' '.($fieldError ? $errorId : ''));

    // ── EXTRA ATTRIBUTES FOR THE SOURCE TEXTAREA: A WHITELIST ──────────────
    //
    // A passthrough bag does NOT make this safe, and that was verified rather than
    // assumed: `ComponentAttributeBag::merge()` emits everything it is given,
    // `onfocus` and `onclick` included. Relying on Laravel to drop event handlers
    // would have shipped an injection point into a component that is now mounted on
    // a dozen screens, including three the institution did not write.
    //
    // So the allowed keys are named here, and the ones this component already owns
    // are simply absent from the list - a caller that could set `name` or `id`
    // could repoint the editor at a different field, or post a second value under
    // the same name.
    //
    // `class` is allowed and is MERGED onto the component's own rather than
    // replacing it, because the online exam's autosave has to keep finding this
    // textarea by `exam-answer-input` once the editor is mounted over it. That is
    // the whole reason the prop exists.
    //
    // `placeholder` is deliberately absent too: the component's own `placeholder`
    // prop already emits `data-placeholder`, which is what the script reads, so
    // allowing it here would offer a key that silently does nothing.
    $piieFieldAttributeAllowlist = ['class', 'rows', 'data-question-id', 'data-answer-type'];

    $piieFieldAttributes = collect($fieldAttributes ?? [])
        ->filter(static fn ($value, $key) => in_array($key, $piieFieldAttributeAllowlist, true), ARRAY_FILTER_USE_BOTH)
        ->mapWithKeys(static fn ($value, $key) => [$key => $value])
        ->toArray();

    $piieFieldAttributes['class'] = trim(
        'piie-editor-source form-control'
        .(isset($piieFieldAttributes['class']) ? ' '.$piieFieldAttributes['class'] : '')
        .($fieldError ? ' is-invalid' : '')
    );
@endphp

<div {{ $attributes->class(['mb-3']) }}>
    @if ($label)
        <label for="{{ $fieldId }}" id="{{ $labelId }}" class="form-label">
            {{ $label }}
            @if ($required)
                <span class="text-danger" aria-hidden="true">*</span>
                <span class="visually-hidden">required</span>
            @endif
            @if ($labelSuffix)
                <span class="text-muted fw-normal">{{ $labelSuffix }}</span>
            @endif
        </label>
    @endif

    {{-- The shell carries the field id so the script finds the editable region
         it builds, rather than guessing at the first editor on the page. Two
         editors on one page are addressed individually. --}}
    <div class="piie-editor-shell" data-for="{{ $fieldId }}">
        <textarea
            id="{{ $fieldId }}"
            name="{{ $name }}"
            class="{{ $piieFieldAttributes['class'] }}"
            rows="{{ $piieFieldAttributes['rows'] ?? $rows }}"
            data-piie-editor
            data-placeholder="{{ $placeholder }}"
            data-height="{{ (int) $height }}"
            data-allow-images="{{ $allowImages ? '1' : '0' }}"
            @if ($maxlength) maxlength="{{ (int) $maxlength }}" @endif
            @if ($required) required @endif
            @if ($testid) data-testid="{{ $testid }}" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @isset($piieFieldAttributes['data-question-id']) data-question-id="{{ $piieFieldAttributes['data-question-id'] }}" @endisset
            @isset($piieFieldAttributes['data-answer-type']) data-answer-type="{{ $piieFieldAttributes['data-answer-type'] }}" @endisset
        >{{ $value }}</textarea>
    </div>

    @if ($fieldError)
        <div class="invalid-feedback d-block" id="{{ $errorId }}" role="alert">{{ $fieldError }}</div>
    @endif

    @if ($help)
        <div class="form-text" id="{{ $helpId }}">{!! $help !!}</div>
    @endif
</div>

@once('piie-academic-editor-assets')
    {{--
        THE EDITOR IS SELF-SUFFICIENT.

        Summernote is loaded HERE rather than left to the page's layout. The
        lecturer layouts happen to load it; the student layout does not, so a
        student on the assignment page found `$.fn.summernote` undefined and every
        answer field quietly fell back to a plain textarea. Where the editor is
        available is a property of the EDITOR, not of whichever layout a page
        happened to inherit.

        The lecturer layouts therefore load it a second time, which costs a
        conditional request against an already-cached asset in exchange for one
        code path that works on every page. A micro-optimisation is not worth two
        ways to be broken.

        Document order does the rest: these are plain synchronous tags, so the
        browser has executed Summernote, then the special-characters plugin, then
        this component's script, before any of the page's own script runs. The
        script ALSO waits for Summernote before giving up, because a promise about
        document order is one a page author can break, and the fallback must stay a
        real fallback rather than a race it loses.

        All four are inside @once, so a page with several editors - a four-question
        assignment, say - downloads them once.
    --}}
    <link rel="stylesheet" href="{{ asset('assets/css/summernote-lite.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/academic-editor.css') }}" />
    <script src="{{ asset('assets/js/summernote-lite.min.js') }}"></script>
    <script src="{{ asset('assets/css/plugin/specialchars/summernote-ext-specialchars.js') }}"></script>
    <script src="{{ asset('js/academic-editor.js') }}"></script>
@endonce
