<div class="d-flex gap-2 flex-wrap mt-3" role="group" aria-label="{{ $record->label }} actions">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.academic_structure.show', [$kind, $record->id]) }}"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.academic_structure.edit', [$kind, $record->id]) }}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
    <form method="post" action="{{ route('admin.academic_structure.destroy', [$kind, $record->id]) }}" onsubmit="return confirm('Permanently delete this academic record? This cannot be undone. Linked academic records will prevent deletion.');">
        @csrf @method('DELETE')
        <input type="hidden" name="confirm_deletion" value="1">
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
    </form>
</div>
