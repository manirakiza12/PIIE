@extends('admin.navigation')

@section('page_styles')<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">@endsection
@section('content')
<div class="curriculum-workspace">
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h4>{{ get_phrase('Programme Curriculum') }}</h4><ul class="d-flex align-items-center eBreadcrumb-2"><li>{{ get_phrase('Academics') }}</li><li>{{ get_phrase('Programmes') }}</li></ul></div>
        @if(auth()->user()->hasPermission('academic.curriculum.manage'))
            <a class="eBtn eBtn-primary" href="{{ route('admin.curricula.create', request()->only('programme_id')) }}">{{ get_phrase('Create Curriculum') }}</a>
        @endif
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($selectedProgramme)<div class="alert alert-info">{{ get_phrase('Programme context') }}: <strong>{{ $selectedProgramme->code }} — {{ $selectedProgramme->name }}</strong></div>@endif

<div class="eSection-wrap">
    <form method="GET" action="{{ route('admin.curricula.index') }}" class="row g-2 mb-3">
        @if($selectedProgramme)<input type="hidden" name="programme_id" value="{{ $selectedProgramme->id }}">@endif
        <div class="col-lg-3"><input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="{{ get_phrase('Programme name/code or version') }}"></div>
        @if(!$selectedProgramme)<div class="col-lg-2"><select class="form-select" name="programme_id"><option value="">{{ get_phrase('All Programmes') }}</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)request('programme_id') === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach</select></div>@endif
        <div class="col-lg-2"><select class="form-select" name="department_id"><option value="">{{ get_phrase('All Departments') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)request('department_id') === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
        <div class="col-lg-2"><select class="form-select" name="status"><option value="">{{ get_phrase('All statuses') }}</option>@foreach(\App\Models\Curriculum::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
        <div class="col-lg-2"><select class="form-select" name="academic_year_id"><option value="">{{ get_phrase('Available by Academic Year') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)request('academic_year_id') === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select></div>
        <div class="col-lg-1 d-flex gap-1"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button></div>
    </form>

    <div class="table-responsive"><table class="table eTable align-middle">
        <thead><tr><th>{{ get_phrase('Programme') }}</th><th>{{ get_phrase('Department / Faculty') }}</th><th>{{ get_phrase('Version') }}</th><th>{{ get_phrase('Applies From Academic Year') }}</th><th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Years') }}</th><th>{{ get_phrase('Course Units') }}</th><th>{{ get_phrase('Total Credits') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
        <tbody>
        @forelse($curriculumRows as $row)
            <tr data-curriculum-id="{{ $row->id }}">
                <td><strong>{{ $row->programme_code }}</strong><br>{{ $row->programme_name }}</td>
                <td>{{ $row->department_name ?: '—' }}</td><td>{{ $row->version }}</td><td>{{ $row->academic_year_label ?: '—' }}</td>
                <td><span class="badge {{ $row->status === 'approved' ? 'bg-success' : ($row->status === 'retired' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ ucfirst($row->status) }}</span></td>
                <td>{{ $row->stages_count }}</td><td>{{ $row->memberships_count }}</td><td>{{ number_format((float)($row->total_credits ?? 0), 2) }}</td>
                <td><div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.curricula.show', $row->id) }}">View</a>
                @if(auth()->user()->hasPermission('academic.curriculum.manage'))
                <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.curricula.show', $row->id) }}#metadata-heading">Edit</a>
                @include('shared.academic_delete_button', [
                    'deleteAction' => route('admin.curricula.destroy', $row->id),
                    'deleteTitle' => 'Delete Programme Curriculum',
                    'deleteIdentity' => $row->programme_code.' '.$row->version.' — '.$row->programme_name.' (record #'.$row->id.')',
                    'deleteReference' => trim($row->programme_code.' '.$row->version),
                    'deleteRemoves' => 'This version’s Programme Years, Course Unit placements, assignments, cohort links, exclusive course allocations, registrations and dependent workflows.',
                    'deleteKeeps' => 'Shared Course Units, allocations linked to other versions, other curricula, and student/staff accounts and profiles.',
                ])
                @else
                <button type="button" class="btn btn-sm btn-outline-primary" disabled aria-describedby="curriculum-permission-{{ $row->id }}">Edit</button>
                <button type="button" class="btn btn-sm btn-outline-danger" disabled aria-describedby="curriculum-permission-{{ $row->id }}">Delete</button>
                <span id="curriculum-permission-{{ $row->id }}" class="small text-muted">Curriculum management permission is required to edit or delete this version.</span>
                @endif
                </div></td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-4 text-muted">@if(request()->filled('search') || request()->filled('programme_id') || request()->filled('department_id') || request()->filled('status') || request()->filled('academic_year_id')){{ get_phrase('No programme curriculum match these filters.') }}@else{{ get_phrase('No programme curriculum have been created yet.') }}@endif @if(auth()->user()->hasPermission('academic.curriculum.manage')) <a href="{{ route('admin.curricula.create', request()->only('programme_id')) }}">{{ get_phrase('Create a Curriculum') }}</a>@endif</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $curriculumRows->links() }}
</div>
@include('shared.academic_delete_modal')
</div>
@endsection
