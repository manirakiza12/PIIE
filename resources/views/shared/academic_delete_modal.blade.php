<div class="modal fade" data-compact-dialog id="academic-delete-modal" tabindex="-1" aria-labelledby="academic-delete-title" aria-describedby="academic-delete-identity academic-delete-summary"
    aria-hidden="true" data-reopen-target="{{ $errors->has('confirm_deletion') || $errors->has('delete') ? old('_delete_target') : '' }}">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="academic-delete-title">Confirm deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close deletion confirmation"></button>
            </div>
            <form method="post" data-delete-form class="d-flex flex-column overflow-hidden">
                @csrf @method('DELETE')
                <input type="hidden" name="_delete_target" value="">
                <div class="modal-body">
                    <p id="academic-delete-identity" class="fw-bold text-break"></p>
                    <div id="academic-delete-summary">
                        <h6>Will be removed</h6><p data-delete-removes class="text-break"></p>
                        <h6>Will remain</h6><p data-delete-keeps class="text-break"></p>
                        <p class="small text-muted">This deletion is permanent and cannot be undone.</p>
                    </div>
                    <div data-delete-error class="alert alert-danger" role="alert" tabindex="-1" @if(!$errors->has('confirm_deletion') && !$errors->has('delete')) hidden @endif>{{ $errors->first('confirm_deletion') ?: $errors->first('delete') }}</div>
                    <label for="academic-delete-confirmation" class="form-label">Type <strong>DELETE</strong> or <strong data-delete-reference class="text-break"></strong> to confirm.</label>
                    <input id="academic-delete-confirmation" class="form-control" name="confirm_deletion" required maxlength="150" autocomplete="off" spellcheck="false" aria-describedby="academic-delete-help">
                    <div id="academic-delete-help" class="form-text">Cancel or close this dialog to keep the record and all its links.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-delete-cancel>Cancel</button>
                    <button type="submit" class="btn btn-danger" data-delete-submit disabled>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>
@if(session('success'))<span hidden data-delete-success>{{ session('success') }}</span>@endif
<script src="{{ asset('assets/js/academic-delete-modal.js') }}?v=20261024-1" defer></script>
