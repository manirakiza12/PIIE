@extends('student.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div>
                <h4>{{ get_phrase('Class Routine') }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                    <li><a href="{{ route('student.my_courses') }}">{{ get_phrase('My Learning') }}</a></li>
                    <li>{{ get_phrase('Class Routine') }}</li>
                </ul>
            </div>
        </div>
    </div></div>
</div>

<div class="eSection-wrap">
    @if($programme)
        <p class="text-muted">{{ get_phrase('Programme') }}: <strong>{{ $programme->name }}</strong></p>
    @endif
    @if($routines->isEmpty())
        <div class="text-center text-muted py-4" role="status">
            <i class="bi bi-calendar-week" aria-hidden="true"></i>
            <p class="mb-1 mt-2">{{ get_phrase('No programme timetable has been published for the current academic session.') }}</p>
            <a href="{{ route('student.my_courses') }}">{{ get_phrase('View My Courses') }}</a>
        </div>
    @else
        <div class="table-responsive">
            <table class="table eTable align-middle">
                <caption class="visually-hidden">{{ get_phrase('Programme timetable') }}</caption>
                <thead><tr>
                    <th scope="col">{{ get_phrase('Day') }}</th>
                    <th scope="col">{{ get_phrase('Time') }}</th>
                    <th scope="col">{{ get_phrase('Course Unit') }}</th>
                    <th scope="col">{{ get_phrase('Lecturer') }}</th>
                    <th scope="col">{{ get_phrase('Room') }}</th>
                </tr></thead>
                <tbody>
                @foreach($routines as $routine)
                    <tr>
                        <td>{{ get_phrase(ucfirst(strtolower($routine->day))) }}</td>
                        <td>{{ sprintf('%02d:%02d', $routine->starting_hour, $routine->starting_minute) }}–{{ sprintf('%02d:%02d', $routine->ending_hour, $routine->ending_minute) }}</td>
                        <td>{{ $subjectNames[$routine->subject_id] ?? get_phrase('Course unit unavailable') }}</td>
                        <td>{{ $teacherNames[$routine->teacher_id] ?? '—' }}</td>
                        <td>{{ $roomNames[$routine->room_id] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
