@extends('student.navigation')
@section('content')

<div class="row g-3">
    {{-- Left column --}}
    <div class="col-lg-8">

        <div class="eSection-wrap mb-3 student-greeting-card">
            <p class="student-greeting-date mb-1">{{ now()->format('d M, Y') }}</p>
            <h4 class="text-dark mb-1">👋 {{ get_phrase('Hello') }}, {{ strtoupper($student->name) }}</h4>
            <p class="text-muted mb-0">{{ get_phrase('Welcome back, your gateway to learning and growth') }}</p>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <p class="student-card-label mb-1">{{ get_phrase('My Programme Active') }}</p>
                    <p class="text-muted mb-2">
                        {{ DB::table('schools')->where('id', $student->school_id)->value('title') }}
                    </p>
                    <p class="student-card-label mb-1">{{ get_phrase('Current Programme') }}</p>
                    @if($programme)
                        <span class="student-programme-link">
                            {{ $programme->code ? $programme->code . ' — ' : '' }}{{ $programme->name }}
                        </span>
                        @if($studentProfile?->year_of_study)
                            <div class="mt-2"><span class="badge bg-light text-dark border">{{ get_phrase('Year') }} {{ $studentProfile->year_of_study }}</span></div>
                        @endif
                    @elseif($classRoom)
                        <a href="{{ route('student.subject_list') }}" class="student-programme-link">
                            {{ $classRoom->name }}{{ $section ? ' / ' . $section->name : '' }}
                        </a>
                    @else
                        <span class="text-muted">{{ get_phrase('Not yet assigned') }}</span>
                    @endif
                </div>
            </div>
            <div class="col-md-6">
                <a href="{{ route('student.routine') }}" class="d-block text-decoration-none">
                    <div class="student-accent-card h-100">
                        <span class="student-accent-title">{{ get_phrase('My Timetable') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="44" height="44" fill="#fff" opacity="0.9"><path d="M19,4h-1V3c0-0.55-0.45-1-1-1s-1,0.45-1,1v1H8V3c0-0.55-0.45-1-1-1S6,2.45,6,3v1H5C3.9,4,3,4.9,3,6v14c0,1.1,0.9,2,2,2h14c1.1,0,2-0.9,2-2V6C21,4.9,20.1,4,19,4z M19,20H5V9h14V20z"/></svg>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <p class="student-card-label mb-3">{{ get_phrase('Course Progress') }}</p>
                    @if($courseProgressPercent !== null)
                        <div class="student-progress-ring" style="--pct: {{ $courseProgressPercent }}">
                            <span>{{ $courseProgressPercent }}%</span>
                        </div>
                    @else
                        <div class="student-empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="#c9cdd6"><path d="M20,6h-8l-2-2H4C2.9,4,2,4.9,2,6v12c0,1.1,0.9,2,2,2h16c1.1,0,2-0.9,2-2V8C22,6.9,21.1,6,20,6z"/></svg>
                            <p class="text-muted mb-0">{{ get_phrase('No Course Found') }}</p>
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <p class="student-card-label mb-3">{{ get_phrase('Overall Progress') }}</p>
                    @if($overallProgressPercent !== null)
                        <div class="student-progress-ring" style="--pct: {{ $overallProgressPercent }}">
                            <span>{{ $overallProgressPercent }}%</span>
                        </div>
                    @else
                        <div class="student-empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" fill="#c9cdd6"><path d="M3,3h18v18H3V3z M12,17l-5-5l1.41-1.41L12,14.17l7.59-7.59L21,8L12,17z"/></svg>
                            <p class="text-muted mb-0">{{ get_phrase('No Progress to show') }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <p class="student-card-label mb-0">{{ get_phrase('Exam Board') }}</p>
                        <a href="{{ route('student.online_exam.list') }}" class="small">{{ get_phrase('View All') }}</a>
                    </div>
                    @forelse($examBoardOngoing->concat($examBoardUpcoming)->take(3) as $exam)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span>{{ $exam->title }}</span>
                            <span class="badge bg-{{ $exam->lifecycle_status === 'active' ? 'success' : 'warning' }}">{{ $exam->lifecycle_status === 'active' ? get_phrase('Ongoing') : get_phrase('Upcoming') }}</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">{{ get_phrase('No exams scheduled') }}</p>
                    @endforelse
                </div>
            </div>
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <p class="student-card-label mb-0">{{ get_phrase('Fees Board') }}</p>
                        <a href="{{ route('student.fee_manager.list') }}" class="small">{{ get_phrase('View All') }}</a>
                    </div>
                    <div class="d-flex align-items-end gap-2 mb-2">
                        <span style="font-size:24px;font-weight:700;color:{{ $totalDue > 0 ? '#e5484d' : '#1fa971' }}">{{ number_format($totalDue, 0) }}</span>
                        <span class="text-muted mb-1">{{ get_phrase('balance due') }}</span>
                    </div>
                    <p class="text-muted mb-0">{{ $unpaidInvoiceCount }} {{ get_phrase('unpaid invoice(s)') }}</p>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <div class="title mb-3 pb-0 border-0 d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">{{ get_phrase('My Teachers') }}</h3>
                        <a href="{{ route('student.teacher') }}" class="small">{{ get_phrase('See all') }}</a>
                    </div>
                    @forelse($myTeachers->take(5) as $teacher)
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <img src="{{ get_user_image($teacher->id) }}" width="32" height="32" style="border-radius:50%;object-fit:cover" alt="">
                            <span>{{ $teacher->name }}</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">
                            @if($classRoom)
                                {{ get_phrase('No teachers assigned to your class yet') }}
                            @else
                                {{ get_phrase('You have not been assigned to a class yet') }}
                            @endif
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="col-md-6">
                <div class="eSection-wrap h-100">
                    <div class="title mb-3 pb-0 border-0 d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">{{ get_phrase('Attendance This Month') }}</h3>
                        <a href="{{ route('student.daily_attendance') }}" class="small">{{ get_phrase('See all') }}</a>
                    </div>
                    @if($markedDays > 0)
                        <div class="d-flex align-items-end gap-2">
                            <span style="font-size:28px;font-weight:700;color:#1a3a6b">{{ $presentDays }}</span>
                            <span class="text-muted mb-1">/ {{ $markedDays }} {{ get_phrase('days marked present') }}</span>
                        </div>
                    @else
                        <p class="text-muted mb-0">{{ get_phrase('No attendance recorded yet this month') }}</p>
                    @endif
                </div>
            </div>
        </div>

    </div>

    {{-- Right column --}}
    <div class="col-lg-4">

        <div class="eSection-wrap mb-3">
            <p class="student-card-label mb-3">{{ get_phrase('Quick Launch') }}</p>
            @php
                $quickLaunchItems = [
                    ['label' => 'ID Card', 'href' => route('student.id_card'), 'icon' => 'M20,4H4C2.9,4,2,4.9,2,6v12c0,1.1,0.9,2,2,2h16c1.1,0,2-0.9,2-2V6C22,4.9,21.1,4,20,4z M9,10c1.1,0,2,0.9,2,2s-0.9,2-2,2 s-2-0.9-2-2S7.9,10,9,10z M13,17H5v-0.75C5,14.9,6.79,14,9,14s4,0.9,4,2.25V17z M20,16h-5v-1.5h5V16z M20,13h-5v-1.5h5V13z M20,10 h-5V8.5h5V10z'],
                    ['label' => 'Exam Results', 'href' => route('student.exam_results'), 'icon' => 'M19,3H5C3.9,3,3,3.9,3,5v14c0,1.1,0.9,2,2,2h14c1.1,0,2-0.9,2-2V5C21,3.9,20.1,3,19,3z M9,17H7v-7h2V17z M13,17h-2V7h2V17z M17,17h-2v-4h2V17z'],
                    ['label' => 'Elections', 'href' => route('student.elections.index'), 'icon' => 'M12,2L1,7l4,2v8l7,3l7-3v-8l2-1v6h2V7L12,2z M12,4.15L18.5,7L12,9.85L5.5,7L12,4.15z M17,15.5l-5,2.15l-5-2.15V10.15l5,2.15l5-2.15V15.5z'],
                    ['label' => 'Student Affairs', 'href' => route('student.requests.index'), 'icon' => 'M12,2C6.48,2,2,6.48,2,12s4.48,10,10,10s10-4.48,10-10S17.52,2,12,2z M13,17h-2v-2h2V17z M13,13h-2V7h2V13z'],
                ];
            @endphp
            <div class="student-quicklaunch-grid">
                @foreach($quickLaunchItems as $item)
                    <a href="{{ $item['href'] }}" class="student-quicklaunch-item" title="{{ get_phrase($item['label']) }}">
                        <span class="student-quicklaunch-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="#fff"><path d="{{ $item['icon'] }}"/></svg>
                        </span>
                        <span class="student-quicklaunch-label">{{ get_phrase($item['label']) }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="eSection-wrap mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="student-card-label mb-0">{{ get_phrase('My Courses') }}</p>
                <a href="{{ route('student.my_courses') }}" class="small text-danger">{{ get_phrase('View All') }}</a>
            </div>
            @forelse($myCourseRegistrations as $registration)
                <div class="mb-3 pb-2 border-bottom">
                    <p class="fw-semibold mb-1">
                        {{ $registration->subject?->code ? $registration->subject->code . ': ' : '' }}{{ $registration->subject?->name }}
                    </p>
                    <div class="d-flex gap-2">
                        @if($studentProfile?->year_of_study)
                            <span class="badge bg-success-subtle text-success">{{ get_phrase('Year') }} {{ $studentProfile->year_of_study }}</span>
                        @endif
                        <span class="badge bg-light text-dark border">{{ ucfirst($registration->status) }}</span>
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">{{ get_phrase('No courses registered yet') }}</p>
            @endforelse
        </div>

        <div class="eSection-wrap">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <p class="student-card-label mb-0">{{ get_phrase('Announcements') }}</p>
                <a href="{{ route('student.noticeboard.list') }}" class="small text-danger">{{ get_phrase('view all') }}</a>
            </div>
            @forelse($announcements as $notice)
                <div class="mb-2 pb-2 border-bottom">
                    <p class="mb-0">{{ $notice->notice_title }}</p>
                    <span class="text-muted small">{{ $notice->start_date }}</span>
                </div>
            @empty
                <p class="text-muted mb-0">{{ get_phrase('No announcements yet') }}</p>
            @endforelse
        </div>

    </div>
</div>

<style>
    .student-greeting-card { background: linear-gradient(135deg, #fff, #f7f9fc); }
    .student-greeting-date { font-size: 12px; color: #9096a2; }
    .student-card-label { font-size: 13px; font-weight: 600; color: #4b5060; text-transform: uppercase; letter-spacing: .02em; }
    .student-programme-link { color: #0d6efd; font-weight: 600; text-decoration: none; }
    .student-programme-link:hover { text-decoration: underline; }

    .student-accent-card {
        background: linear-gradient(135deg, #0f6e6e, #114f52);
        border-radius: 10px;
        padding: 20px;
        color: #fff;
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 100%;
        box-shadow: 0 6px 20px rgba(15, 110, 110, 0.25);
        transition: transform .12s ease;
    }
    .student-accent-card:hover { transform: translateY(-2px); }
    .student-accent-title { font-size: 16px; font-weight: 600; }

    .student-empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 20px 0; gap: 8px; }

    .student-progress-ring {
        --pct: 0;
        width: 90px; height: 90px; border-radius: 50%;
        background: conic-gradient(#0f6e6e calc(var(--pct) * 1%), #eceef2 0);
        display: flex; align-items: center; justify-content: center;
        margin: 8px auto;
    }
    .student-progress-ring span {
        width: 68px; height: 68px; border-radius: 50%; background: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; color: #181c32; font-size: 15px;
    }

    .student-quicklaunch-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
    }
    .student-quicklaunch-item {
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .student-quicklaunch-icon {
        width: 52px; height: 52px; border-radius: 50%;
        background: #0f6e6e;
        display: flex; align-items: center; justify-content: center;
        transition: transform .12s ease, background .12s ease;
    }
    .student-quicklaunch-item:hover .student-quicklaunch-icon { transform: translateY(-2px); background: #114f52; }
    .student-quicklaunch-label { font-size: 11px; color: #4b5060; text-align: center; }

    .student-stat-card {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 16px 18px;
        background: #fff;
        border-radius: 10px;
        border-left: 4px solid var(--stat-color, #cacfd4);
        box-shadow: 0 6px 20px rgba(121, 124, 139, 0.0156862745);
        transition: transform .12s ease, box-shadow .12s ease;
    }
    .student-stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(121, 124, 139, 0.12);
    }
    .student-stat-value {
        font-size: 26px;
        font-weight: 700;
        line-height: 1.1;
        color: #181c32;
    }
    .student-stat-label {
        font-size: 12px;
        color: #797c8b;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
</style>
@endsection
