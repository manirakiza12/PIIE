<div class="d-flex gap-2 flex-wrap mt-3" role="group" aria-label="{{ $record->label }} actions">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.academic_structure.show', [$kind, $record->id]) }}"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.academic_structure.edit', [$kind, $record->id]) }}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
    <form method="post" action="{{ route('admin.academic_structure.destroy', [$kind, $record->id]) }}" class="w-100 mt-2">
        @csrf @method('DELETE')
        <p class="small text-danger mb-1">Delete {{ $record->label }} and its academic-period/session records, registrations, placements and dependent workflows. Curriculum versions and shared course units are retained; the current selection will be cleared.</p>
        <label class="form-label small" for="confirm-academic-delete-{{ $record->id }}">Type DELETE or {{ $record->label }}</label>
        <div class="d-flex gap-2"><input class="form-control form-control-sm" id="confirm-academic-delete-{{ $record->id }}" name="confirm_deletion" required><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button></div>
    </form>
</div>
