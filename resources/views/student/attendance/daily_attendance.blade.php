@extends('student.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div>
                <h4>{{ get_phrase($attendanceMode === 'hei' ? 'Course Attendance' : 'Daily Attendance') }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                    <li><a href="{{ route('student.my_courses') }}">{{ get_phrase('My Learning') }}</a></li>
                    <li>{{ get_phrase($attendanceMode === 'hei' ? 'Course Attendance' : 'Daily Attendance') }}</li>
                </ul>
            </div>
        </div>
    </div></div>
</div>

@if(session('info'))<div class="alert alert-info" role="status">{{ session('info') }}</div>@endif

<div class="eSection-wrap mb-3">
    @if($attendanceMode === 'legacy')
        <p class="text-muted mb-3">
            @if($classContext)
                {{ academic_term('class', auth()->user()->school_id) }} / {{ academic_term('section', auth()->user()->school_id) }}: <strong>{{ $classContext }}</strong>
            @else
                {{ get_phrase('Your current class and section attendance is not available yet. Contact the academic office if this is unexpected.') }}
            @endif
        </p>
    @elseif($attendanceMode === 'hei')
        <p class="text-muted mb-3">
            {{ get_phrase('Finalised attendance for your confirmed course registrations') }}
            @if($programmeContext) &mdash; <strong>{{ $programmeContext }}</strong>@endif
        </p>
    @else
        <div class="alert alert-info mb-0">{{ get_phrase('Your academic pathway is not configured. Contact the academic office for help.') }}</div>
    @endif

    @if(in_array($attendanceMode, ['legacy', 'hei'], true))
        <form method="GET" action="{{ route('student.daily_attendance.filter') }}" class="row g-2 align-items-end mb-3">
            <div class="col-12 col-sm-4 col-md-3">
                <label class="form-label" for="month">{{ get_phrase('Month') }}</label>
                <select name="month" id="month" class="form-select" required>
                    @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $month)
                        <option value="{{ $month }}" @selected($page_data['month'] === $month)>{{ get_phrase(date('F', strtotime($month.' 1'))) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-sm-4 col-md-3">
                <label class="form-label" for="year">{{ get_phrase('Year') }}</label>
                <select name="year" id="year" class="form-select" required>
                    @for($year = 2015; $year <= (int) date('Y'); $year++)
                        <option value="{{ $year }}" @selected((int) $page_data['year'] === $year)>{{ $year }}</option>
                    @endfor
                </select>
            </div>
            <div class="col-12 col-sm-4 col-md-2">
                <button class="eBtn eBtn-primary w-100" type="submit">{{ get_phrase('Show') }}</button>
            </div>
            @if($attendanceMode === 'legacy' && $attendanceRecords->isNotEmpty())

                <div class="col-12 col-md-2">
                    <button class="eBtn eBtn-secondary w-100" type="button" onclick="downloadAttendanceCsv()">{{ get_phrase('Download CSV') }}</button>
                </div>
            @endif
        </form>
    @endif

    @if($attendanceRecords->isEmpty())
        <div class="text-center text-muted py-4" role="status">
            <i class="bi bi-calendar-check" aria-hidden="true"></i>
            <p class="mb-0 mt-2">{{ !$attendanceAvailable
                ? get_phrase('Course attendance is not available for this institution yet.')
                : get_phrase('No attendance has been recorded for this period.') }}</p>
        </div>
    @else
        <div class="table-responsive" id="attendance-history">
            <table class="table eTable align-middle">
                <caption class="visually-hidden">{{ get_phrase('Attendance history') }}</caption>
                <thead><tr>
                    <th scope="col">{{ get_phrase('Date') }}</th>
                    <th scope="col">{{ get_phrase($attendanceMode === 'hei' ? 'Course' : 'Class / Section') }}</th>
                    @if($attendanceMode === 'hei')<th scope="col">{{ get_phrase('Session') }}</th>@endif
                    <th scope="col">{{ get_phrase('Status') }}</th>
                </tr></thead>
                <tbody>
                @foreach($attendanceRecords as $record)
                    @php
                        $status = $attendanceMode === 'hei'
                            ? ($record->status_label ?? 'Unknown')
                            : ((int) $record->status === 1 ? 'Present' : ((int) $record->status === 0 ? 'Absent' : 'Recorded'));
                        $statusClass = $status === 'Present' ? 'success' : ($status === 'Absent' ? 'danger' : ($status === 'Late' ? 'warning' : 'secondary'));
                        $recordDate = $attendanceMode === 'hei' ? $record->session_date : date('Y-m-d', (int) $record->timestamp);
                    @endphp
                    <tr>
                        <td><time datetime="{{ $recordDate }}">{{ \Illuminate\Support\Carbon::parse($recordDate)->format('D, d M Y') }}</time></td>
                        <td>{{ $attendanceMode === 'hei' ? $record->course_name : $classContext }}</td>
                        @if($attendanceMode === 'hei')
                            <td>{{ $record->topic ?: get_phrase('Class session') }}@if($record->starts_at) <span class="text-muted">({{ substr($record->starts_at, 0, 5) }}@if($record->ends_at)–{{ substr($record->ends_at, 0, 5) }}@endif)</span>@endif</td>
                        @endif
                        <td><span class="badge bg-{{ $statusClass }}">{{ get_phrase($status) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@if($attendanceMode === 'legacy')
<script>
    function downloadAttendanceCsv() {
        var month = document.getElementById('month').value;
        var year = document.getElementById('year').value;
        var url = @json(route('student.dailyAttendanceFilter_csv'));
        url += '?month=' + encodeURIComponent(month) + '&year=' + encodeURIComponent(year);
        window.open(url, '_blank', 'noopener');
    }
</script>
@endif
@endsection
