@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('Progression Review') }} — {{ $student->name }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li><a href="{{ route('admin.progression.index') }}">{{ get_phrase('Progression') }}</a></li>
                <li aria-current="page">{{ $student->name }}</li>
            </ul>
        </div>
    </div>
</div>

@if(session('message'))
    <div class="alert alert-success mt-3">{{ session('message') }}</div>
@endif

<div class="eSection-wrap mt-3">
    @if(isset($evaluation['error']))
        <div class="alert alert-warning">{{ $evaluation['error'] }}</div>
    @else
        <div class="row">
            <div class="col-md-6">
                <p><strong>{{ get_phrase('Programme') }}:</strong> {{ $evaluation['curriculum']?->programme?->name ?? '—' }}</p>
                <p><strong>{{ get_phrase('Curriculum') }}:</strong> {{ $evaluation['curriculum']?->name }}</p>
                <p><strong>{{ get_phrase('Current Level') }}:</strong> {{ $evaluation['current_stage']?->name ?? '—' }}</p>
                <p><strong>{{ get_phrase('Next Level') }}:</strong> {{ $evaluation['next_stage']?->name ?? get_phrase('None') }}</p>
            </div>
            <div class="col-md-6">
                <p><strong>{{ get_phrase('Completed/Exempted') }}:</strong> {{ $evaluation['completed_count'] }}</p>
                <p><strong>{{ get_phrase('Outstanding') }}:</strong> {{ $evaluation['outstanding_count'] }}</p>
                <p><strong>{{ get_phrase('Exempted') }}:</strong> {{ $evaluation['exempted_count'] }}</p>
                <p><strong>{{ get_phrase('Can Progress') }}:</strong> {{ $evaluation['can_progress'] ? get_phrase('Yes') : get_phrase('No') }} — {{ $evaluation['reason'] }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.progression.decide', $student->id) }}" class="mt-3">
            @csrf
            <div class="mb-3">
                <label class="eForm-label">{{ get_phrase('Decision') }}</label>
                <select name="decision" class="form-select eForm-select" required>
                    <option value="promote">{{ get_phrase('Promote') }}</option>
                    <option value="retain">{{ get_phrase('Retain') }}</option>
                    <option value="conditional">{{ get_phrase('Conditional') }}</option>
                    <option value="exceptional">{{ get_phrase('Exceptional') }}</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="eForm-label">{{ get_phrase('Notes') }}</label>
                <textarea name="notes" class="form-control eForm-control" rows="3"></textarea>
            </div>
            <button type="submit" class="eBtn eBtn-primary" onclick="return confirm('{{ get_phrase('Record this progression decision?') }}')">{{ get_phrase('Record Decision') }}</button>
        </form>
    @endif

    <div class="mt-3">
        <a href="{{ route('admin.progression.index') }}" class="eBtn eBtn-outline-secondary">{{ get_phrase('Back to list') }}</a>
    </div>
</div>
@endsection