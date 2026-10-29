@extends('admin.navigation')

@section('content')
@include('admin.course_offerings.lecturers._context')

<div class="eSection-wrap">
    <h5>{{ get_phrase('Assign Lecturer') }}</h5>
    <p class="text-muted">{{ get_phrase('Teaching access activates automatically when the Course Allocation and lecturer are eligible. During the assignment dates, lecturers can manage course content, assignments and live classes for their assigned course units.') }}</p>

    <p class="text-muted">A lecturer can teach different programmes and courses during the same semester. Assign them to each programme's Course Allocation. A shared allocation uses one teaching team for all its linked programmes.</p>
    @if($errors->any())
        <div class="alert alert-danger" role="alert" aria-labelledby="lecturer-errors-title">
            <h6 id="lecturer-errors-title" class="alert-heading">{{ get_phrase('Please review this allocation') }}</h6>
            <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            @if(old('user_id'))<a class="btn btn-outline-primary mt-2" href="{{ route('admin.lecturer_assignments.index', ['lecturer_id' => old('user_id')]) }}">Assign this lecturer to another programme</a>@endif
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
    @elseif(!$canMutateOffering)
        <div class="alert alert-info" role="status">{{ get_phrase('Lecturer allocations cannot be changed for this Allocation state.') }}</div>
    @elseif(!$canManage)
        <div class="alert alert-info" role="status">{{ get_phrase('You can view this workspace but do not have permission to assign lecturers.') }}</div>
    @elseif($candidates->isEmpty())
        <div class="alert alert-info" role="status">
            <strong>{{ get_phrase('No eligible lecturers are currently available.') }}</strong>
            <div>{{ get_phrase('Only enabled, same-institution Teacher/Lecturer accounts that satisfy staff eligibility requirements can be selected.') }}</div>
            <p class="mb-0">{{ get_phrase('Maintain lecturer accounts in the Staff module before assigning them here.') }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.store', $offering->id) }}" class="row g-3" aria-describedby="allocation-period-help">
            @csrf
            <div class="col-12 col-lg-6">
                <label for="lecturer-user" class="form-label">{{ $lecturerLabel }} <span aria-hidden="true">*</span></label>
                <select id="lecturer-user" name="user_id" class="form-select" required>
                    <option value="">{{ get_phrase('Choose a lecturer') }}</option>
                    @foreach($candidates as $candidate)
                        @php($onLeave = $candidate->staff_status === \App\Support\Staff\StaffStatus::ON_LEAVE)
                        <option value="{{ $candidate->id }}" @selected((string)old('user_id', request('user_id')) === (string)$candidate->id)>
                            {{ $candidate->name ?: $candidate->email }}
                            @if($candidate->code) · {{ get_phrase('Staff No.') }} {{ $candidate->code }}@endif
                            @if($candidate->department) · {{ $candidate->department->name }}@endif
                            @if($candidate->designationRecord) · {{ $candidate->designationRecord->name }}@endif
                            @if($candidate->staffProfile?->academic_title) · {{ $candidate->staffProfile->academic_title }}@endif
                            @if($candidate->staffProfile?->specialisation) · {{ $candidate->staffProfile->specialisation }}@endif
                            @if($onLeave) · {{ get_phrase('On leave — planned only') }}@endif
                        </option>
                    @endforeach
                </select>
                <div class="form-text">{{ get_phrase('Optional staff profile information is shown when available; it is not required.') }}</div>
                <div class="form-text">{{ get_phrase('On leave — this lecturer may remain planned but cannot be activated while on leave.') }}</div>
            </div>
            <div class="col-12 col-lg-6">
                <label for="allocation-role" class="form-label">{{ get_phrase('Teaching Role') }} <span aria-hidden="true">*</span></label>
                <select id="allocation-role" name="role" class="form-select" required>
                    @foreach($roleLabels as $role => $label)
                        <option value="{{ $role }}" @selected(old('role') === $role)>{{ get_phrase($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12" id="allocation-period-help">
                <div class="alert alert-light border mb-0">
                    <strong>{{ get_phrase($periodLabel) }} bounds:</strong>
                    {{ $period->start_date->format('Y-m-d') }} – {{ $period->end_date->format('Y-m-d') }}.
                    {{ get_phrase('Both allocation date endpoints are inclusive.') }}
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label for="allocation-start" class="form-label">{{ get_phrase('Starts On') }} <span aria-hidden="true">*</span></label>
                <input id="allocation-start" class="form-control" type="date" name="starts_on" required min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('starts_on') }}">
            </div>
            <div class="col-12 col-md-6">
                <label for="allocation-end" class="form-label">{{ get_phrase('Ends On') }} <span class="text-muted">({{ get_phrase('optional') }})</span></label>
                <input id="allocation-end" class="form-control" type="date" name="ends_on" min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('ends_on') }}">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">{{ get_phrase('Assign Lecturer') }}</button>
                <a class="btn btn-light" href="{{ route('admin.course_offerings.lecturers.index', $offering->id) }}">{{ get_phrase('Back to Teaching Team') }}</a>
            </div>
        </form>
    @endif
</div>
@endsection
