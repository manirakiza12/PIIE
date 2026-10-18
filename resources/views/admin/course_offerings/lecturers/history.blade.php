@extends('admin.navigation')

@section('content')
@include('admin.course_offerings.lecturers._context')

<section class="eSection-wrap">
    <h5>{{ get_phrase('Lecturer Allocation History') }}</h5>
    @if(empty($history))
        <p class="text-muted mb-0">{{ get_phrase('No lecturer allocation history is available.') }}</p>
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <caption class="visually-hidden">{{ get_phrase('Lecturer allocation events for this Course Allocation') }}</caption>
                <thead><tr><th scope="col">{{ get_phrase('Event') }}</th><th scope="col">{{ get_phrase('Lecturer') }}</th><th scope="col">{{ get_phrase('Role') }}</th><th scope="col">{{ get_phrase('Dates') }}</th><th scope="col">{{ get_phrase('Actor') }}</th><th scope="col">{{ get_phrase('Timestamp') }}</th></tr></thead>
                <tbody>@foreach($history as $event)
                    <tr><td>{{ get_phrase($event['event']) }}@if($event['reason'])<div class="small text-muted">{{ get_phrase('Reason') }}: {{ $event['reason'] }}</div>@endif</td><td>{{ $event['lecturer'] }}</td><td>{{ get_phrase($event['role']) }}</td><td>{{ $event['starts_on'] ?: '—' }} – {{ $event['ends_on'] ?: get_phrase('No end date') }}</td><td>{{ $event['actor'] }}</td><td>{{ $event['created_at']?->format('Y-m-d H:i') }}</td></tr>
                @endforeach</tbody>
            </table>
        </div>
    @endif
    <a class="btn btn-outline-secondary" href="{{ route('admin.course_offerings.lecturers.index', $offering->id) }}">{{ get_phrase('Back to Lecturers') }}</a>
</section>
@endsection
