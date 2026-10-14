@extends('admin.navigation')

@section('content')
@php
    $schoolId = auth()->user()->school_id;
    $totalStaff = DB::table('users')->whereIn('role_id', [2,3,4,5,9,10,11,12,13,14,15,16,17,18,19,20])->where('school_id', $schoolId)->count();
    $pendingLeave = DB::table('leavelists')->where('school_id', $schoolId)->where('status', 'pending')->count();
    $onLeaveToday = DB::table('leavelists')->where('school_id', $schoolId)->where('status', 'approved')
        ->where('from_date', '<=', now()->toDateString())->where('to_date', '>=', now()->toDateString())->count();
    $departments = DB::table('departments')->where('school_id', $schoolId)->count();
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4 class="title">{{ get_phrase('HR Manager Dashboard') }}</h4>
                    <ul class="breadcrumb-link">
                        <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Dashboard') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-1">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-person-badge-fill fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $totalStaff }}</div>
                    <div class="text-muted small">{{ get_phrase('Total Staff') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                    <i class="bi bi-clock-history fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $pendingLeave }}</div>
                    <div class="text-muted small">{{ get_phrase('Pending Leave Requests') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger bg-opacity-10 p-3">
                    <i class="bi bi-calendar-x fs-3 text-danger"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $onLeaveToday }}</div>
                    <div class="text-muted small">{{ get_phrase('On Leave Today') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3">
                    <i class="bi bi-diagram-3-fill fs-3 text-success"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $departments }}</div>
                    <div class="text-muted small">{{ get_phrase('Departments') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mt-2">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">{{ get_phrase('Quick Actions') }}</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.teacher') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-people me-1"></i>{{ get_phrase('Staff List') }}
                    </a>
                    <a href="{{ route('admin.leave.index') }}" class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-calendar3 me-1"></i>{{ get_phrase('Leave Management') }}
                    </a>
                    <a href="{{ route('admin.leave_types.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-list-check me-1"></i>{{ get_phrase('Leave Types') }}
                    </a>
                    <a href="{{ route('admin.payroll.index') }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-cash me-1"></i>{{ get_phrase('Payroll') }}
                    </a>
                    <a href="{{ route('admin.salary_structures.index') }}" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>{{ get_phrase('Salary Structures') }}
                    </a>
                    <a href="{{ route('admin.department_list') }}" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-building me-1"></i>{{ get_phrase('Departments') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
