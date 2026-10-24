<div class="d-flex gap-2 flex-wrap mt-3" role="group" aria-label="{{ $record->label }} actions">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.academic_structure.show', [$kind, $record->id]) }}"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.academic_structure.edit', [$kind, $record->id]) }}"><i class="bi bi-pencil" aria-hidden="true"></i> Edit</a>
    @include('shared.academic_delete_button', [
        'deleteAction' => route('admin.academic_structure.destroy', [$kind, $record->id]),
        'deleteTitle' => $kind === 'years' ? 'Delete Academic Year' : 'Delete '.ucfirst($record->type),
        'deleteIdentity' => ($kind === 'periods' ? $record->academicYear->label.' — ' : '').$record->label.' ('.$record->start_date->format('d/m/y').' – '.$record->end_date->format('d/m/y').', record #'.$record->id.')',
        'deleteReference' => $record->label,
        'deleteRemoves' => $kind === 'years'
            ? 'This year’s academic periods, legacy sessions, Course Unit links, course allocations, registrations, placements and dependent workflows. Its current selection will be cleared.'
            : 'This period’s course allocations, registrations and dependent workflows. Its current-period selection will be cleared.',
        'deleteKeeps' => 'Curriculum versions, shared Course Units, links belonging to other years and periods, and student/staff accounts and profiles.',
    ])
</div>
