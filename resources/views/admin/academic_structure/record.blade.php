@extends('admin.navigation')
@section('content')
@php
    $isPeriod = $kind === 'periods';
    $recordLabel = $isPeriod ? ucfirst($school->academic_calendar_pattern ?: 'semester') : 'Academic Year';
    $listParameters = $isPeriod ? ['section' => 'semesters'] : [];
@endphp
<div class="mainSection-title mb-3">
    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div><h4>{{ $editing ? 'Edit' : 'View' }} {{ $recordLabel }}</h4><p class="text-muted mb-0">{{ $record->label }}</p></div>
        <a class="btn btn-outline-secondary" href="{{ route('admin.academic_structure.index', $listParameters) }}">Back to {{ $isPeriod ? $recordLabel.'s' : 'Academic Years' }}</a>
    </div>
</div>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="eSection-wrap">
            <div class="d-flex justify-content-between align-items-center mb-4"><h5 class="mb-0">{{ $recordLabel }} details</h5><span class="badge bg-light text-dark border">{{ ucfirst($record->status) }}</span></div>
            @if($editing)
            <form method="post" action="{{ route('admin.academic_structure.update', [$kind, $record->id]) }}">
                @csrf @method('PUT')
                <div class="mb-3"><label for="calendar-label" class="form-label">Label</label><input id="calendar-label" class="form-control" name="label" value="{{ old('label', $record->label) }}" required maxlength="100"></div>
                @if($isPeriod)
                <div class="mb-3"><label class="form-label" for="calendar-year">Academic Year</label><select id="calendar-year" class="form-select" name="academic_year_id" required>@foreach($years as $year)<option value="{{ $year->id }}" @selected((string)old('academic_year_id', $record->academic_year_id) === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
                <div class="row"><div class="col-md-6 mb-3"><label class="form-label" for="calendar-type">Type</label><select class="form-select" name="type" id="calendar-type" required>@foreach(['semester','term'] as $type)<option value="{{ $type }}" @selected(old('type', $record->type) === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div><div class="col-md-6 mb-3"><label class="form-label" for="calendar-sequence">Sequence</label><input id="calendar-sequence" class="form-control" type="number" name="sequence" min="1" value="{{ old('sequence', $record->sequence) }}" required></div></div>
                @endif
                <div class="row"><div class="col-md-6 mb-3"><label for="calendar-start" class="form-label">Start date</label><input id="calendar-start" class="form-control" type="date" name="start_date" value="{{ old('start_date', $record->start_date->format('Y-m-d')) }}" required></div><div class="col-md-6 mb-3"><label for="calendar-end" class="form-label">End date</label><input id="calendar-end" class="form-control" type="date" name="end_date" value="{{ old('end_date', $record->end_date->format('Y-m-d')) }}" required></div></div>
                <div class="d-flex gap-2 flex-wrap mt-3"><button class="eBtn eBtn-primary" type="submit">Save changes</button><a class="btn btn-outline-secondary" href="{{ route('admin.academic_structure.show', [$kind, $record->id]) }}">Discard changes</a></div>
            </form>
            @else
            <dl class="row mb-0">
                <dt class="col-sm-4 mb-2">Label</dt><dd class="col-sm-8">{{ $record->label }}</dd>
                @if($isPeriod)<dt class="col-sm-4 mb-2">Academic Year</dt><dd class="col-sm-8">{{ $record->academicYear->label }}</dd><dt class="col-sm-4 mb-2">Type / sequence</dt><dd class="col-sm-8">{{ ucfirst($record->type) }} {{ $record->sequence }}</dd>@endif
                <dt class="col-sm-4 mb-2">Start date</dt><dd class="col-sm-8">{{ $record->start_date->format('Y-m-d') }}</dd>
                <dt class="col-sm-4 mb-2">End date</dt><dd class="col-sm-8">{{ $record->end_date->format('Y-m-d') }}</dd>
                <dt class="col-sm-4 mb-2">Current selection</dt><dd class="col-sm-8">{{ (int)($isPeriod ? $school->current_academic_period_id : $school->current_academic_year_id) === $record->id ? 'Yes' : 'No' }}</dd>
            </dl>
            @include('admin.academic_structure.actions')
            @endif
        </div>
    </div>
</div>
@endsection
