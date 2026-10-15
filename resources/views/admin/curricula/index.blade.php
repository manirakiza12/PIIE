@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><h4>{{ get_phrase('Programme Curricula') }}</h4><p class="text-muted mb-0">{{ get_phrase('Define the stages, course units and credit requirements students follow throughout each programme.') }}</p><ul class="d-flex align-items-center eBreadcrumb-2"><li>{{ get_phrase('Academic Structure') }}</li><li>{{ get_phrase('Programme Curricula') }}</li></ul></div>
        @if(auth()->user()->hasPermission('academic.curriculum.manage'))
            <a class="eBtn eBtn-primary" href="{{ route('admin.curricula.create', request()->only('programme_id')) }}">{{ get_phrase('Create Curriculum') }}</a>
        @endif
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($selectedProgramme)<div class="alert alert-info">{{ get_phrase('Programme context') }}: <strong>{{ $selectedProgramme->code }} — {{ $selectedProgramme->name }}</strong></div>@endif

<div class="eSection-wrap">
    <form method="GET" action="{{ route('admin.curricula.index') }}" class="row g-2 mb-3">
        @if($selectedProgramme)<input type="hidden" name="programme_id" value="{{ $selectedProgramme->id }}">@endif
        <div class="col-lg-3"><input class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="{{ get_phrase('Programme name/code or version') }}"></div>
        @if(!$selectedProgramme)<div class="col-lg-2"><select class="form-select" name="programme_id"><option value="">{{ get_phrase('All Programmes') }}</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)request('programme_id') === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach</select></div>@endif
        <div class="col-lg-2"><select class="form-select" name="department_id"><option value="">{{ get_phrase('All Departments') }}</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)request('department_id') === (string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
        <div class="col-lg-2"><select class="form-select" name="status"><option value="">{{ get_phrase('All statuses') }}</option>@foreach(\App\Models\Curriculum::STATUSES as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
        <div class="col-lg-2"><select class="form-select" name="academic_year_id"><option value="">{{ get_phrase('All Academic Years') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)request('academic_year_id') === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
        <div class="col-lg-1 d-flex gap-1"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button></div>
    </form>

    <div class="table-responsive"><table class="table eTable align-middle">
        <thead><tr><th>{{ get_phrase('Programme') }}</th><th>{{ get_phrase('Department / Faculty') }}</th><th>{{ get_phrase('Version') }}</th><th>{{ get_phrase('Applies From Academic Year') }}</th><th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Stages') }}</th><th>{{ get_phrase('Course Units') }}</th><th>{{ get_phrase('Total Credits') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
        <tbody>
        @forelse($curriculumRows as $row)
            <tr>
                <td><strong>{{ $row->programme_code }}</strong><br>{{ $row->programme_name }}</td>
                <td>{{ $row->department_name ?: '—' }}</td><td>{{ $row->version }}</td><td>{{ $row->academic_year_label ?: '—' }}</td>
                <td><span class="badge {{ $row->status === 'approved' ? 'bg-success' : ($row->status === 'retired' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ ucfirst($row->status) }}</span></td>
                <td>{{ $row->stages_count }}</td><td>{{ $row->memberships_count }}</td><td>{{ number_format((float)($row->total_credits ?? 0), 2) }}</td>
                <td><a class="eBtn eBtn-sm eBtn-primary" href="{{ route('admin.curricula.show', $row->id) }}">{{ get_phrase('Open') }}</a></td>
            </tr>
        @empty
            <tr><td colspan="9" class="text-center py-4 text-muted">@if(request()->filled('search') || request()->filled('programme_id') || request()->filled('department_id') || request()->filled('status') || request()->filled('academic_year_id')){{ get_phrase('No programme curricula match these filters.') }}@else{{ get_phrase('No programme curricula have been created yet.') }}@endif @if(auth()->user()->hasPermission('academic.curriculum.manage')) <a href="{{ route('admin.curricula.create', request()->only('programme_id')) }}">{{ get_phrase('Create a Curriculum') }}</a>@endif</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $curriculumRows->links() }}
</div>
@endsection
