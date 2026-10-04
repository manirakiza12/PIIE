@extends('teacher.navigation')
@section('content')
@php
    $courseUnitLabel = $terms['course_unit'] ?? 'Course Unit';
    $periodLabel = $terms['academic_period'] ?? 'Academic Period';
    $statusLabels = ['draft' => 'Draft', 'open' => 'Open', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $statusBadges = ['draft' => 'warning text-dark', 'open' => 'success', 'in_progress' => 'primary', 'completed' => 'secondary', 'cancelled' => 'dark'];
@endphp

<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h4 class="mb-1">My Course Offerings</h4>
            <p class="text-muted mb-0">The Course Offerings you are allocated to teach at {{ $terms['programme'] === 'Programme' ? 'this institution' : 'this school' }}.</p>
        </div>
    </div>
</div>

<form method="GET" action="{{ route('teacher.course_offerings.index') }}" class="row g-2 align-items-end mb-3" role="search">
    <div class="col-12 col-sm-6 col-lg-3">
        <label class="form-label small mb-1" for="filter-year">Academic Year</label>
        <select id="filter-year" name="academic_year_id" class="form-select form-select-sm">
            <option value="">All Academic Years</option>
            @foreach($filters['years'] as $id => $label)<option value="{{ $id }}" @selected($selectedYearId === (int) $id)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label class="form-label small mb-1" for="filter-period">{{ $periodLabel }}</label>
        <select id="filter-period" name="academic_period_id" class="form-select form-select-sm">
            <option value="">All {{ $periodLabel }}s</option>
            @foreach($filters['periods'] as $id => $label)<option value="{{ $id }}" @selected($selectedPeriodId === (int) $id)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div class="col-12 col-sm-6 col-lg-3">
        <label class="form-label small mb-1" for="filter-status">Status</label>
        <select id="filter-status" name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            @foreach($filters['statuses'] as $status)<option value="{{ $status }}" @selected($selectedStatus === $status)>{{ $statusLabels[$status] ?? ucfirst($status) }}</option>@endforeach
        </select>
    </div>
    <div class="col-12 col-sm-6 col-lg-3 d-flex gap-2">
        <button class="btn btn-primary btn-sm">Filter</button>
        @if($selectedYearId || $selectedPeriodId || $selectedStatus)
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('teacher.course_offerings.index') }}">Clear</a>
        @endif
    </div>
</form>

@if($offerings->isEmpty())
    <section class="eSection-wrap">
        <div class="border rounded p-4 text-center" role="status">
            <h6>You do not currently have any Course Offerings assigned.</h6>
            <p class="text-muted mb-0">When the academic office allocates you to a {{ $courseUnitLabel }}, it will appear here.</p>
        </div>
    </section>
@else
    <p class="text-muted small">{{ $offerings->count() }} {{ \Illuminate\Support\Str::plural('Course Offering', $offerings->count()) }} &middot; {{ $current }} currently allocated.</p>

    {{-- Desktop table --}}
    <div class="d-none d-lg-block">
        <section class="eSection-wrap">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th scope="col">{{ $courseUnitLabel }}</th>
                            <th scope="col">Academic Year</th>
                            <th scope="col">{{ $periodLabel }}</th>
                            <th scope="col">Study Plan / {{ $terms['programme'] }}</th>
                            <th scope="col">Stage / Year of Study</th>
                            <th scope="col">Status</th>
                            <th scope="col">My Teaching Role</th>
                            <th scope="col">Students</th>
                            <th scope="col"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($offerings as $offering)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $offering->subject?->code }}</div>
                                <div>{{ $offering->subject?->name }}</div>
                            </td>
                            <td>{{ $offering->academicYear?->label }}</td>
                            <td>{{ $offering->academicPeriod?->label }}</td>
                            <td>
                                @forelse($offering->my_study_plans as $version)<span class="badge bg-light text-dark border me-1">Study Plan {{ $version }}</span>@empty<span class="text-muted">&mdash;</span>@endforelse
                                @if($offering->my_programmes->isNotEmpty())<div class="small text-muted">{{ $offering->my_programmes->implode(', ') }}</div>@endif
                            </td>
                            <td>{{ $offering->my_stages->isNotEmpty() ? $offering->my_stages->implode(', ') : '—' }}</td>
                            <td><span class="badge bg-{{ $statusBadges[$offering->status] ?? 'secondary' }}">{{ $statusLabels[$offering->status] ?? ucfirst($offering->status) }}</span></td>
                            <td>
                                <div>{{ $offering->my_role_label }}</div>
                                @if($offering->my_allocation_is_testing_access ?? false)
                                    <div class="small text-muted"><span class="badge bg-info text-dark">Testing access active</span> Pre-start testing</div>
                                @elseif(!$offering->my_allocation_is_current)
                                    <div class="small text-muted">{{ $offering->my_allocation_status === 'ended' ? 'Allocation ended' : 'Not yet in force' }}</div>
                                @endif
                            </td>
                            <td>{{ $offering->my_confirmed_students }}</td>
                            <td><a class="btn btn-sm btn-outline-primary text-nowrap" href="{{ route('teacher.course_offerings.show', $offering->id) }}">View</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- Mobile / tablet cards --}}
    <div class="d-lg-none">
        <div class="row g-2">
            @foreach($offerings as $offering)
                <div class="col-12 col-md-6">
                    <div class="eSection-wrap h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold">{{ $offering->subject?->code }} &mdash; {{ $offering->subject?->name }}</div>
                                <div class="small text-muted">{{ $offering->academicYear?->label }} &middot; {{ $offering->academicPeriod?->label }}</div>
                            </div>
                            <span class="badge bg-{{ $statusBadges[$offering->status] ?? 'secondary' }}">{{ $statusLabels[$offering->status] ?? ucfirst($offering->status) }}</span>
                        </div>
                        <dl class="row small mb-2 mt-2">
                            <dt class="col-5">Study Plan</dt><dd class="col-7">{{ $offering->my_study_plans->isNotEmpty() ? $offering->my_study_plans->implode(', ') : '—' }}</dd>
                            <dt class="col-5">Stage / Year of Study</dt><dd class="col-7">{{ $offering->my_stages->isNotEmpty() ? $offering->my_stages->implode(', ') : '—' }}</dd>
                            <dt class="col-5">My Teaching Role</dt><dd class="col-7">{{ $offering->my_role_label }}</dd>
                            <dt class="col-5">Confirmed Students</dt><dd class="col-7">{{ $offering->my_confirmed_students }}</dd>
                        </dl>
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('teacher.course_offerings.show', $offering->id) }}">View</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
