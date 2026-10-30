@php
    $primaryKinds = $assignment->responseKinds();
    $optionalKinds = $assignment->optionalEvidenceKinds();
    $labels = \App\Models\AssignmentSubmissionItem::KIND_LABELS;
    $uploadLimit = \App\Support\Assignments\UploadLimit::LABEL;
@endphp
<fieldset class="mb-3">
    <legend class="h6">Response method</legend>
    <p class="small" id="as-requirement" data-testid="as-response-rule">
        {{ $assignment->requiresAllResponseKinds() ? 'All selected methods are required:' : 'Choose any one accepted method:' }}
        {{ $assignment->evidenceRequirementLabel() }}.
    </p>
    <p class="small text-muted mb-3" data-testid="as-upload-limit">{{ $uploadLimit }}</p>
    @if(!$assignment->requiresAllResponseKinds() && count($primaryKinds) > 1)
        <label for="as-response-method" class="form-label">How would you like to respond?</label>
        <select id="as-response-method" class="form-select mb-3" name="response_method" data-as-method-select aria-describedby="as-requirement">
            @foreach($primaryKinds as $kind)
                <option value="{{ $kind }}" @selected(old('response_method', $draft?->items?->first()?->kind ?? $primaryKinds[0]) === $kind)>{{ $labels[$kind] }}</option>
            @endforeach
        </select>
        <p class="small text-muted">Switch methods without losing entered work. Without JavaScript, use any one of the fields below.</p>
    @endif
    @foreach($assignment->acceptedEvidenceKinds() as $kind)
        @php
            $optional = in_array($kind, $optionalKinds, true);
            $required = $assignment->responseKindRequired($kind);
            $fieldId = $prefix.'kind-'.$kind;
            $fieldName = $kind === 'link' ? 'link_url' : $kind;
            $attached = $attachedByKind->get($kind)?->first();
            $error = $errors->first($fieldName);
        @endphp
        @if($optional)<details class="mb-3"><summary class="small fw-semibold">Optional supporting {{ strtolower($labels[$kind]) }}</summary>@endif
        <div class="mb-3 mt-2" @unless($optional) data-as-method="{{ $kind }}" @endunless>
            <label for="{{ $fieldId }}" class="form-label">{{ $labels[$kind] }} <span class="small text-muted">{{ $optional ? '(optional)' : ($required ? '(required)' : '(choose one method)') }}</span></label>
            @if($kind === 'text')
                <textarea class="form-control @if($error) is-invalid @endif" id="{{ $fieldId }}" name="text" rows="7" data-testid="as-generic-text"
                          aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" @if($error) aria-invalid="true" @endif>{{ $existingText }}</textarea>
            @elseif($kind === 'link')
                <input type="url" class="form-control @if($error) is-invalid @endif" id="{{ $fieldId }}" name="link_url" value="{{ old('link_url', $attached?->url) }}" placeholder="https://"
                       aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" @if($error) aria-invalid="true" @endif>
            @else
                <input type="file" class="form-control @if($error) is-invalid @endif" id="{{ $fieldId }}" name="{{ $kind }}"
                       accept="{{ implode(',', array_map(fn($ext) => '.'.$ext, \App\Models\AssignmentSubmissionItem::extensionsFor($kind))) }}"
                       aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" @if($error) aria-invalid="true" @endif>
            @endif
            <div class="form-text" id="{{ $fieldId }}-help">
                @if($kind === 'text') Write your answer here. Save Draft keeps unfinished work.
                @elseif($kind === 'link') A full http or https address.
                @else Up to 20 MB. Accepted: {{ implode(', ', \App\Models\AssignmentSubmissionItem::extensionsFor($kind)) }}.
                @endif
                @if($attached) <strong>Already attached: {{ $attached->displayName() }}</strong> — kept unless you choose a replacement. @endif
            </div>
            <div class="invalid-feedback d-block" id="{{ $fieldId }}-error">{{ $error }}</div>
        </div>
        @if($optional)</details>@endif
    @endforeach
    @error('files')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
    @error('items')<p class="text-danger small" role="alert">{{ $message }}</p>@enderror
</fieldset>
