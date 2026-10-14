@extends('admin.navigation')

@section('content')
@php
    $schoolId = auth()->user()->school_id;
    $totalStudents = DB::table('users')->where('role_id', 7)->where('school_id', $schoolId)->count();
    $todayStart = strtotime(date('Y-m-d 00:00:00'));
    $todayEnd = strtotime(date('Y-m-d 23:59:59'));
    $todayPayments = DB::table('payment_history')->where('school_id', $schoolId)
        ->whereBetween('timestamp', [$todayStart, $todayEnd])->count();
    $pendingFees = DB::table('student_fee_managers')->where('school_id', $schoolId)->whereIn('status', ['unpaid', 'pending'])->count();
    $newApplications = DB::table('admissions')->where('school_id', $schoolId)->whereIn('status', ['submitted', 'under_review'])->count();
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4 class="title">{{ get_phrase('Receptionist Dashboard') }}</h4>
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
                    <i class="bi bi-people-fill fs-3 text-primary"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $totalStudents }}</div>
                    <div class="text-muted small">{{ get_phrase('Total Students') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3">
                    <i class="bi bi-cash-coin fs-3 text-success"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $todayPayments }}</div>
                    <div class="text-muted small">{{ get_phrase("Today's Payments") }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning bg-opacity-10 p-3">
                    <i class="bi bi-exclamation-circle-fill fs-3 text-warning"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $pendingFees }}</div>
                    <div class="text-muted small">{{ get_phrase('Pending Fees') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info bg-opacity-10 p-3">
                    <i class="bi bi-file-earmark-person fs-3 text-info"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">{{ $newApplications }}</div>
                    <div class="text-muted small">{{ get_phrase('New Applications') }}</div>
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
                    <a href="{{ route('admin.student') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-search me-1"></i>{{ get_phrase('Student Search') }}
                    </a>
                    <a href="{{ route('admin.offline_admission.single', ['type' => 'single']) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-person-plus me-1"></i>{{ get_phrase('New Admission') }}
                    </a>
                    <a href="{{ route('admin.fee_manager.list') }}" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-receipt me-1"></i>{{ get_phrase('Fee Inquiry') }}
                    </a>
                    <a href="{{ route('admin.offline_payment_pending') }}" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-credit-card me-1"></i>{{ get_phrase('Payments') }}
                    </a>
                    <a href="{{ route('admin.noticeboard.list') }}" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-megaphone me-1"></i>{{ get_phrase('Noticeboard') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
