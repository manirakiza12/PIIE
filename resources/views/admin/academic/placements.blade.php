@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="d-flex justify-content-between flex-wrap gap-2"><h4>Student Placement</h4><a class="btn btn-primary" href="{{ route('admin.academic.direct_placement') }}">Create Student Placement</a></div><p class="text-muted">Assign an existing student to an approved curriculum, Programme Year and Academic Year. Matching Course Allocations review the student under Not Registered. Placement does not register the student for a course.</p></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="eSection-wrap"><div class="table-responsive"><table class="table eTable align-middle"><thead><tr><th>Student</th><th>Programme / Curriculum</th><th>Programme Year</th><th>Academic Year</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($placements as $row)<tr><td>{{ $row->student_name }}<br><small>{{ $row->student_code }}</small></td><td>{{ $row->programme_name }} · {{ $row->version }}</td><td>{{ $row->year_label ?: 'Needs placement year' }}</td><td>{{ $row->academic_year }}</td><td>{{ $row->ended_at ? 'Historical' : 'Active' }}</td><td>
<details><summary class="btn btn-sm btn-outline-secondary">View</summary><dl class="mt-2"><dt>Student</dt><dd>{{ $row->student_name }}</dd><dt>Curriculum</dt><dd>{{ $row->version }}</dd><dt>Reason / notes</dt><dd>{{ $row->reason ?: 'Initial placement' }}</dd></dl></details>
@if(!$row->ended_at && !$row->programme_cohort_membership_id && !$row->has_registrations)
<div class="d-flex flex-wrap gap-2 mt-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.academic.direct_placement', ['placement_id' => $row->id]) }}">Edit</a><form method="POST" action="{{ route('admin.academic.placements.destroy', $row->id) }}" onsubmit="return confirm('Delete this unused placement? The student account is kept. Placements with course registration history cannot be deleted.')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form></div><small class="text-muted">Editing and deletion are allowed only before course registration history exists.</small>
@else<small class="text-muted">Placements with academic history or a group link are read-only.</small>@endif
</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No student placements yet. Create a placement for an existing student to make them available for matching course allocations.</td></tr>@endforelse
</tbody></table></div>{{ $placements->links() }}</section>
@endsection
