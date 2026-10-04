{{-- One row of the supporting-documents repeater: a document TYPE plus a FILE.
     A row is a self-contained card, so the section can grow one row at a time
     instead of showing every native file input up front. staff_documents has no
     free-text description column, so none is offered here rather than storing
     it somewhere it would not be read. --}}
<div class="sc-doc-row document-row">
    <div class="sc-doc-head">
        <p class="sc-doc-name">
            <span class="sc-doc-num" data-doc-index>{{ $i + 1 }}</span>
            {{ get_phrase('Document') }}
        </p>
        <button type="button" class="sc-doc-remove remove-document">
            <i class="bi bi-trash3" aria-hidden="true"></i> {{ get_phrase('Remove') }}
        </button>
    </div>

    <div class="sc-doc-grid">
        <div class="sc-doc-cell">
            <label class="sc-label" for="documents_{{ $i }}_category">{{ get_phrase('Document Type') }}<span class="req" aria-hidden="true">*</span></label>
            <select id="documents_{{ $i }}_category" name="documents[{{ $i }}][category]"
                    class="form-select @error("documents.{$i}.category") is-invalid @enderror">
                <option value="">{{ get_phrase('Select a document type') }}</option>
                @foreach($documentCategories as $key => $name)
                    <option value="{{ $key }}" @selected(($row['category'] ?? '') === $key)>{{ get_phrase($name) }}</option>
                @endforeach
            </select>
            @error("documents.{$i}.category")<span class="invalid-feedback">{{ $errors->first("documents.{$i}.category") }}</span>@enderror
        </div>

        <div class="sc-doc-cell">
            <label class="sc-label" for="documents_{{ $i }}_file">{{ get_phrase('File') }}<span class="req" aria-hidden="true">*</span></label>
            {{-- The native input is still what gets posted; the label beside it
                 is the visible control, so the row reads as one clean control. --}}
            <div class="sc-doc-file">
                <input type="file" id="documents_{{ $i }}_file" name="documents[{{ $i }}][file]"
                       class="sc-file-input @error("documents.{$i}.file") is-invalid @enderror"
                       accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                       data-filename="documents_{{ $i }}_filename">
                <label class="sc-file-btn" for="documents_{{ $i }}_file">
                    <i class="bi bi-upload" aria-hidden="true"></i> {{ get_phrase('Choose file') }}
                </label>
                <span class="sc-file-name" id="documents_{{ $i }}_filename">{{ get_phrase('No file selected') }}</span>
            </div>
            @error("documents.{$i}.file")<span class="invalid-feedback">{{ $errors->first("documents.{$i}.file") }}</span>@enderror
        </div>
    </div>
</div>
