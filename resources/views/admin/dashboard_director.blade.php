@extends('admin.navigation')

@section('content')
@php
    $schoolId = auth()->user()->school_id;
    $totalStudents = DB::table('users')->where('role_id', 7)->where('school_id', $schoolId)->count();
    $totalStaff = DB::table('users')->whereIn('role_id', [2,3,4,5,9,10,11,12,13,14,15,16,17,18,19,20])->where('school_id', $schoolId)->count();
    $activeProgrammes = DB::table('programmes')->where('school_id', $schoolId)->where('status', 1)->count();
    $pendingApplications = DB::table('admissions')->where('school_id', $schoolId)->whereIn('status', ['submitted', 'under_review'])->count();
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4 class="title">{{ get_phrase('Director Dashboard') }}</h4>
                    <ul class="breadcrumb-link">
                        <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Dashboard') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <!-- Students -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-people-fill fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $totalStudents }}</div>
                    <div class="text-muted small">{{ get_phrase('Total Students') }}</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Staff -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3">
                    <i class="bi bi-person-badge-fill fs-3 text-success"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $totalStaff }}</div>
                    <div class="text-muted small">{{ get_phrase('Total Staff') }}</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Programmes -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                    <i class="bi bi-mortarboard-fill fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $activeProgrammes }}</div>
                    <div class="text-muted small">{{ get_phrase('Active Programmes') }}</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Admissions -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info bg-opacity-10 p-3">
                    <i class="bi bi-file-earmark-person-fill fs-3 text-info"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $pendingApplications }}</div>
                    <div class="text-muted small">{{ get_phrase('Pending Applications') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">{{ get_phrase('Quick Actions') }}</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-bar-chart-fill me-1"></i>{{ get_phrase('Reports & Analytics') }}
                    </a>
                    <a href="{{ route('admin.transcripts.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-file-earmark-text me-1"></i>{{ get_phrase('Transcripts') }}
                    </a>
                    <a href="{{ route('admin.graduation.index') }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-mortarboard me-1"></i>{{ get_phrase('Graduation') }}
                    </a>
                    <a href="{{ route('admin.programmes.index') }}" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-journal-bookmark me-1"></i>{{ get_phrase('Programmes') }}
                    </a>
                    <a href="{{ route('admin.payroll.index') }}" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-cash-stack me-1"></i>{{ get_phrase('Payroll') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">{{ get_phrase('Notices') }}</div>
            <div class="card-body">
                @php
                    $notices = DB::table('noticeboard')->where('school_id', $schoolId)->orderBy('id','desc')->limit(5)->get();
                @endphp
                @forelse($notices as $notice)
                    <div class="border-bottom pb-2 mb-2">
                        <div class="fw-semibold small">{{ $notice->title }}</div>
                        <div class="text-muted" style="font-size:0.78rem">{{ Str::limit($notice->description, 80) }}</div>
                    </div>
                @empty
                    <p class="text-muted small">{{ get_phrase('No notices yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
