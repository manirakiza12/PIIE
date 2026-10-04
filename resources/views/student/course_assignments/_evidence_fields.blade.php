{{--
    The inputs for whatever evidence an assignment accepts.

    ONE PARTIAL, USED TWICE. The draft zone and the hand-in zone need identical
    fields, and two copies of a form's field set will drift - which would mean the
    student could prepare something the submit button then refuses.

    RENDERED FROM THE ALLOWLIST, NOT FROM A FIXED LIST
    The fields come from `acceptedEvidenceKinds()`, so what the student sees is
    exactly what the lecturer asked for. A kind the assignment does not accept has
    no field at all, so it cannot be sent even by tampering with the form - and
    `evidenceFrom()` re-checks it server-side regardless.

    NO RECORDING CONTROL
    Audio and video render a FILE CHOOSER, nothing more. No microphone is
    requested, no camera is requested, and nothing simulates a recorder: a platform
    that offered a Record button it could not honour would be worse than one that
    does not offer it. An in-browser recorder, when it exists, will feed this same
    file input - which is why the storage, privacy and authorisation paths were
    built to carry it without a schema change.

    A FILE ALREADY ATTACHED IS STATED, NOT SILENTLY DROPPED
    A browser cannot re-populate a file input. If a student prepared a file last
    visit, this page must say so and say whether choosing a new one replaces it -
    otherwise the browser's inability to restore it becomes a silent data loss on
    submit.
--}}
@php
    $evidenceKinds = $assignment->acceptedEvidenceKinds();
@endphp

@foreach($evidenceKinds as $kind)
    @php
        $label = \App\Models\AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind;
        $fieldId = $prefix.'kind-'.$kind;
    @endphp

    @if($kind === \App\Models\AssignmentSubmissionItem::KIND_TEXT)
        <div class="mb-2">
            <label for="{{ $fieldId }}" class="form-label small">{{ $label }}</label>
            {{-- `data-testid` is a STABLE HOOK for "this is the single written
                 box of a generic assignment". Asserting on `name="text"` instead
                 means a test has to guess the exact attribute order, and a
                 harmless reformat of this tag then fails a test that was really
                 about something else. --}}
            <textarea class="form-control" id="{{ $fieldId }}" name="text" rows="6"
                      data-testid="as-generic-text"
                      placeholder="Type your answer here.">{{ $existingText }}</textarea>
        </div>

    @elseif(\App\Models\AssignmentSubmissionItem::isLinkKind($kind))
        <div class="mb-2">
            <label for="{{ $fieldId }}" class="form-label small">{{ $label }}</label>
            <input type="url" class="form-control" id="{{ $fieldId }}" name="link_url"
                   placeholder="https://"
                   value="{{ old('link_url', $attachedByKind->get($kind)?->first()?->url) }}">
            <div class="form-text">A full http or https address.</div>
        </div>

    @else
        @php $attached = $attachedByKind->get($kind)?->first(); @endphp
        <div class="mb-2">
            <label for="{{ $fieldId }}" class="form-label small">{{ $label }}</label>
            <input type="file" class="form-control" id="{{ $fieldId }}" name="{{ $kind }}">
            <div class="form-text">
                @if($attached)
                    Already attached: {{ $attached->displayName() }}
                    @if($attached->sizeLabel()) ({{ $attached->sizeLabel() }}) @endif
                    &mdash; choose a new file to replace it.
                @else
                    Up to 20 MB.
                    {{ \App\Models\AssignmentSubmissionItem::isFileKind($kind)
                        ? 'Accepted: '.implode(', ', \App\Models\AssignmentSubmissionItem::extensionsFor($kind)).'.'
                        : '' }}
                @endif
            </div>
        </div>
    @endif
@endforeach
