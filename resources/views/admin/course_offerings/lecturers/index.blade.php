@extends('admin.navigation')

@section('content')
@include('admin.course_offerings.lecturers._context')

<style>
    .lecturer-desktop { display: none; }
    .lecturer-mobile { display: grid; gap: .75rem; }
    .lecturer-mobile-card { border: 1px solid #dee2e6; border-radius: .5rem; padding: 1rem; min-width: 0; }
    .lecturer-mobile-card dl { display: grid; grid-template-columns: minmax(7rem, 38%) minmax(0, 1fr); gap: .35rem .75rem; margin: .75rem 0; }
    .lecturer-mobile-card dt { color: #6c757d; font-weight: 500; }
    .lecturer-mobile-card dd { margin: 0; overflow-wrap: anywhere; }
    .lecturer-actions { display: flex; flex-wrap: wrap; gap: .4rem; }
    .lecturer-action { min-width: 0; }
    .lecturer-action summary { display: inline-block; cursor: pointer; }
    .lecturer-action-form { width: min(100%, 28rem); padding: .75rem; border: 1px solid #dee2e6; border-radius: .5rem; background: #fff; }
    .lecturer-action-form .form-control, .lecturer-action-form .form-select { max-width: 100%; }
    @media (min-width: 992px) {
        .lecturer-desktop { display: block; }
        .lecturer-mobile { display: none; }
    }
</style>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert" aria-labelledby="lecturer-errors-title">
        <h6 id="lecturer-errors-title" class="alert-heading">{{ get_phrase('Lecturer allocation needs attention') }}</h6>
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

@if(in_array($offering->status, ['completed', 'cancelled'], true))
    <div class="alert alert-info" role="status">
        @if($offering->status === 'completed')
            {{ get_phrase('This Allocation is complete. Lecturer allocations are retained as history.') }}
        @else
            {{ get_phrase('This Allocation is cancelled. Lecturer allocations are read-only.') }}
        @endif
    </div>
@endif

<section class="eSection-wrap mb-3" aria-labelledby="lecturers-heading">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h5 id="lecturers-heading">{{ get_phrase('Teaching Team') }}</h5>
            @unless($hasPrimary)<p class="alert alert-info py-2 mb-2">{{ get_phrase('No Primary Lecturer is currently allocated.') }}</p>@endunless
        </div>
        @if($canManage && $canMutateOffering)
            <a class="btn btn-primary" href="{{ route('admin.course_offerings.lecturers.create', $offering->id) }}">{{ get_phrase('Assign Lecturer') }}</a>
        @endif
    </div>
    @if($allocations->isEmpty())
        <p class="text-muted mb-0">{{ get_phrase('No teaching team members are assigned to this Course Allocation yet.') }}</p>
        @if(!$canManage)<p class="text-muted small mb-0">{{ get_phrase('You have read-only access to this workspace.') }}</p>@endif
    @else
        @include('admin.course_offerings.lecturers._group', ['heading' => get_phrase('Current Teaching Team'), 'items' => $currentAllocations, 'statusLabel' => get_phrase('Active'), 'displayVariant' => 'current'])
        @include('admin.course_offerings.lecturers._group', ['heading' => get_phrase('Planned'), 'items' => $plannedAllocations, 'statusLabel' => get_phrase('Planned'), 'displayVariant' => 'planned'])
        @include('admin.course_offerings.lecturers._group', ['heading' => get_phrase('Historical'), 'items' => $historicalAllocations, 'statusLabel' => null, 'displayVariant' => 'historical'])
    @endif
</section>

<section class="eSection-wrap" id="history" aria-labelledby="allocation-history-heading">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 id="allocation-history-heading" class="mb-0">{{ get_phrase('Lecturer Allocation History') }}</h5>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.course_offerings.lecturers.history', $offering->id) }}">{{ get_phrase('View History') }}</a>
    </div>
    @if(empty($history))
        <p class="text-muted mt-3 mb-0">{{ get_phrase('No lecturer allocation history is available.') }}</p>
    @else
        <div class="table-responsive mt-3">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden">{{ get_phrase('Lecturer allocation events for this Course Allocation') }}</caption>
                <thead><tr><th scope="col">{{ get_phrase('Event') }}</th><th scope="col">{{ get_phrase('Lecturer') }}</th><th scope="col">{{ get_phrase('Role / Dates') }}</th><th scope="col">{{ get_phrase('Actor') }}</th><th scope="col">{{ get_phrase('When') }}</th></tr></thead>
                <tbody>@foreach(array_slice($history, 0, 8) as $event)
                    <tr><td>{{ get_phrase($event['event']) }}@if($event['reason'])<div class="small text-muted">{{ get_phrase('Reason') }}: {{ $event['reason'] }}</div>@endif</td>
                        <td>{{ $event['lecturer'] }}</td><td>{{ get_phrase($event['role']) }}<div class="small text-muted">{{ $event['starts_on'] ?: '—' }} – {{ $event['ends_on'] ?: get_phrase('No end date') }}</div></td>
                        <td>{{ $event['actor'] }}</td><td>{{ $event['created_at']?->format('Y-m-d H:i') }}</td></tr>
                @endforeach</tbody>
            </table>
        </div>
    @endif
</section>
@endsection
