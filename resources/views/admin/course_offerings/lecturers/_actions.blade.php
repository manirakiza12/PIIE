@php
    $suffix = $displayVariant.'-'.$item->id;
    $failedHere = (int)old('_allocation_id', 0) === (int)$item->id;
    $canChange = $canManage && $canMutateOffering;
@endphp

@if($canChange && $item->status === \App\Models\CourseOfferingLecturerAllocation::STATUS_PLANNED)
    <form method="POST" action="{{ route('admin.course_offerings.lecturers.destroy', [$offering->id, $item->id]) }}" onsubmit="return confirm('Delete this unused planned lecturer assignment?')" class="mb-2">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete Planned</button></form>
    <details class="lecturer-action">
        <summary class="btn btn-sm btn-outline-primary">{{ get_phrase('Edit Planned') }}</summary>
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.update', [$offering->id, $item->id]) }}" class="lecturer-action-form mt-2">
            @csrf @method('PUT')<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
            <label class="form-label" for="{{ $suffix }}-edit-user">{{ $lecturerLabel }}</label>
            <select id="{{ $suffix }}-edit-user" name="user_id" class="form-select form-select-sm" required>
                @foreach($candidates as $candidate)
                    <option value="{{ $candidate->id }}" @selected((int)old('user_id', $item->user_id) === (int)$candidate->id)>
                        {{ $candidate->name ?: $candidate->email }}@if($candidate->code) · {{ $candidate->code }}@endif @if($candidate->staff_status === \App\Support\Staff\StaffStatus::ON_LEAVE) · {{ get_phrase('On leave — planned only') }}@endif
                    </option>
                @endforeach
            </select>
            <label class="form-label mt-2" for="{{ $suffix }}-edit-role">{{ get_phrase('Role') }}</label>
            <select id="{{ $suffix }}-edit-role" name="role" class="form-select form-select-sm" required>
                @foreach($roleLabels as $role => $label)<option value="{{ $role }}" @selected(old('role', $item->role) === $role)>{{ get_phrase($label) }}</option>@endforeach
            </select>
            <label class="form-label mt-2" for="{{ $suffix }}-edit-start">{{ get_phrase('Starts On') }}</label>
            <input id="{{ $suffix }}-edit-start" type="date" name="starts_on" class="form-control form-control-sm" min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('starts_on', $item->starts_on->format('Y-m-d')) }}" required>
            <label class="form-label mt-2" for="{{ $suffix }}-edit-end">{{ get_phrase('Ends On') }} ({{ get_phrase('optional') }})</label>
            <input id="{{ $suffix }}-edit-end" type="date" name="ends_on" class="form-control form-control-sm" min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('ends_on', $item->ends_on?->format('Y-m-d')) }}">
            <button class="btn btn-sm btn-primary mt-2" type="submit">{{ get_phrase('Save Planned Allocation') }}</button>
        </form>
    </details>
    @if($canActivate)
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.activate', [$offering->id, $item->id]) }}" class="mt-2" onsubmit="return confirm(@json(get_phrase('Activate this lecturer allocation?')))">
            @csrf<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
            <button class="btn btn-sm btn-success" type="submit">{{ get_phrase('Activate') }}</button>
        </form>
    @endif
    <details class="lecturer-action mt-2">
        <summary class="btn btn-sm btn-outline-danger">{{ get_phrase('Cancel Allocation') }}</summary>
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.cancel', [$offering->id, $item->id]) }}" class="lecturer-action-form mt-2" onsubmit="return confirm(@json(get_phrase('Cancel this planned allocation? Its dates and history will be preserved.')))">
            @csrf<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
            <label class="form-label" for="{{ $suffix }}-planned-reason">{{ get_phrase('Reason') }}</label>
            <textarea id="{{ $suffix }}-planned-reason" name="reason" class="form-control form-control-sm" required maxlength="1000">{{ old('reason', $failedHere ? '' : '') }}</textarea>
            <button class="btn btn-sm btn-outline-danger mt-2" type="submit">{{ get_phrase('Cancel Allocation') }}</button>
        </form>
    </details>
@elseif($canChange && $item->status === \App\Models\CourseOfferingLecturerAllocation::STATUS_ACTIVE)
    <details class="lecturer-action">
        <summary class="btn btn-sm btn-outline-primary">{{ get_phrase('End Allocation') }}</summary>
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.end', [$offering->id, $item->id]) }}" class="lecturer-action-form mt-2" onsubmit="return confirm(@json(get_phrase('End this teaching allocation? It will be retained as history.')))">
            @csrf<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
            <label class="form-label" for="{{ $suffix }}-end-date">{{ get_phrase('Effective End Date') }}</label>
            <input id="{{ $suffix }}-end-date" type="date" name="ends_on" class="form-control form-control-sm" min="{{ $item->starts_on->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('ends_on', $failedHere ? '' : '') }}" required>
            <div class="form-text">{{ get_phrase($periodLabel) }}: {{ $period->start_date->format('Y-m-d') }} – {{ $period->end_date->format('Y-m-d') }}.</div>
            <button class="btn btn-sm btn-primary mt-2" type="submit">{{ get_phrase('End Allocation') }}</button>
        </form>
    </details>
    <details class="lecturer-action mt-2">
        <summary class="btn btn-sm btn-outline-primary">{{ $item->role === \App\Models\CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER ? get_phrase('Replace Primary Lecturer') : get_phrase('Replace Lecturer') }}</summary>
        <div class="lecturer-action-form mt-2">
            <p class="small mb-2"><strong>{{ get_phrase('Current allocation') }}:</strong> {{ $item->lecturer?->name ?: get_phrase('Former lecturer') }} · {{ get_phrase($roleLabels[$item->role] ?? $item->role) }} · {{ $item->starts_on->format('Y-m-d') }} – {{ $item->ends_on?->format('Y-m-d') ?? get_phrase('No end date') }}</p>
            <p class="small text-muted">{{ get_phrase('Replacing a lecturer ends the existing allocation and creates a new allocation. Teaching history is preserved.') }}</p>
            <form method="POST" action="{{ route('admin.course_offerings.lecturers.replace', [$offering->id, $item->id]) }}" onsubmit="return confirm(@json(get_phrase('Replace this lecturer? The previous allocation will end and both records will be kept.')))">
                @csrf<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
                <label class="form-label" for="{{ $suffix }}-replacement-user">{{ get_phrase('Replacement Lecturer') }}</label>
                <select id="{{ $suffix }}-replacement-user" name="user_id" class="form-select form-select-sm" required>
                    <option value="">{{ get_phrase('Choose a lecturer') }}</option>
                    @foreach($candidates as $candidate)
                        <option value="{{ $candidate->id }}" @selected((int)old('user_id', $failedHere ? 0 : 0) === (int)$candidate->id)>
                            {{ $candidate->name ?: $candidate->email }}@if($candidate->code) · {{ $candidate->code }}@endif @if($candidate->staff_status === \App\Support\Staff\StaffStatus::ON_LEAVE) · {{ get_phrase('On leave — planned only') }}@endif
                        </option>
                    @endforeach
                </select>
                <label class="form-label mt-2" for="{{ $suffix }}-replacement-role">{{ get_phrase('Replacement Role') }}</label>
                <select id="{{ $suffix }}-replacement-role" name="role" class="form-select form-select-sm" required>
                    @foreach($roleLabels as $role => $label)<option value="{{ $role }}" @selected(old('role', $failedHere ? $item->role : $item->role) === $role)>{{ get_phrase($label) }}</option>@endforeach
                </select>
                <label class="form-label mt-2" for="{{ $suffix }}-old-end">{{ get_phrase('Previous Allocation End Date') }}</label>
                <input id="{{ $suffix }}-old-end" type="date" name="old_ends_on" class="form-control form-control-sm" min="{{ $item->starts_on->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('old_ends_on', $failedHere ? '' : '') }}" required>
                <label class="form-label mt-2" for="{{ $suffix }}-new-start">{{ get_phrase('Replacement Starts On') }}</label>
                <input id="{{ $suffix }}-new-start" type="date" name="starts_on" class="form-control form-control-sm" min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('starts_on', $failedHere ? '' : '') }}" required>
                <div class="form-text">{{ get_phrase('The previous end date must be before the replacement start date. Both dates are inclusive and must be reviewed; no date is inferred.') }}</div>
                <label class="form-label mt-2" for="{{ $suffix }}-replacement-end">{{ get_phrase('Replacement Ends On') }} ({{ get_phrase('optional') }})</label>
                <input id="{{ $suffix }}-replacement-end" type="date" name="ends_on" class="form-control form-control-sm" min="{{ $period->start_date->format('Y-m-d') }}" max="{{ $period->end_date->format('Y-m-d') }}" value="{{ old('ends_on', $failedHere ? '' : '') }}">
                <button class="btn btn-sm btn-primary mt-2" type="submit">{{ get_phrase('Replace Lecturer') }}</button>
            </form>
        </div>
    </details>
    <details class="lecturer-action mt-2">
        <summary class="btn btn-sm btn-outline-danger">{{ get_phrase('Cancel Active Allocation') }}</summary>
        <form method="POST" action="{{ route('admin.course_offerings.lecturers.cancel', [$offering->id, $item->id]) }}" class="lecturer-action-form mt-2" onsubmit="return confirm(@json(get_phrase('Cancel this active allocation? Its dates will be preserved.')))">
            @csrf<input type="hidden" name="_allocation_id" value="{{ $item->id }}">
            <label class="form-label" for="{{ $suffix }}-active-reason">{{ get_phrase('Reason') }}</label>
            <textarea id="{{ $suffix }}-active-reason" name="reason" class="form-control form-control-sm" required maxlength="1000">{{ old('reason', $failedHere ? '' : '') }}</textarea>
            <button class="btn btn-sm btn-outline-danger mt-2" type="submit">{{ get_phrase('Cancel Active Allocation') }}</button>
        </form>
    </details>
@endif
