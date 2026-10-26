@extends('admin.navigation')

@section('page_styles')<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">@endsection
@section('content')
<div class="curriculum-workspace">
<div class="mainSection-title"><h4>{{ get_phrase('Create Programme Curriculum') }}</h4><p class="text-muted">{{ get_phrase('A curriculum defines the Programme Years, course units and credit requirements students follow throughout a programme.') }}</p><p class="text-muted">{{ get_phrase('New curricula start as drafts and become official only after review and approval. An approved curriculum remains reusable across later Academic Years.') }}</p></div>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="eSection-wrap col-xl-8">
    <form method="POST" action="{{ route('admin.curricula.store') }}">@csrf
        <div class="mb-3"><label class="form-label">{{ get_phrase('Programme') }} *</label><select name="programme_id" class="form-select" required><option value="">{{ get_phrase('Select Programme') }}</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)old('programme_id', $selectedProgrammeId) === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach</select>@error('programme_id')<div class="text-danger">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label">{{ get_phrase('Curriculum version') }} *</label><input class="form-control" name="version" value="{{ old('version') }}" maxlength="50" required placeholder="{{ get_phrase('e.g. 2026-v1') }}">@error('version')<div class="text-danger">{{ $message }}</div>@enderror</div>
        <div class="mb-3"><label class="form-label">{{ get_phrase('Applies From Academic Year') }}</label><select name="effective_academic_year_id" class="form-select"><option value="">{{ get_phrase('Choose later while draft') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)old('effective_academic_year_id') === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select><small class="text-muted">{{ get_phrase('Required before approval. This is the dated Academic Year from which this curriculum applies, not the relative Semester/Term placement within the curriculum.') }}</small>@error('effective_academic_year_id')<div class="text-danger">{{ $message }}</div>@enderror</div>
        <button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Create Curriculum and continue') }}</button> <a class="eBtn eBtn-secondary" href="{{ route('admin.curricula.index', request()->only('programme_id')) }}">{{ get_phrase('Cancel') }}</a>
    </form>
</div>
</div>
@endsection
