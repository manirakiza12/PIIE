@extends('student.navigation')
@section('content')
@include('student.partials.study_fee_status')

@if(($workflow ?? 'legacy') === 'hei')
    @include('student.my_courses_hei')
@else

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('My Courses') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('My Courses') }}</a></li>
                    </ul>
                </div>
                @if($programme)
                    <span class="badge bg-secondary">{{ $programme->name }}</span>
                @endif
            </div>
        </div>
    </div>
</div>

@if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap mb-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="text-muted small d-block">{{ get_phrase('Fee Balance') }}</span>
                <strong style="font-size:20px;color:{{ $totalDue > 0 ? '#e5484d' : '#1fa971' }}">
                    {{ number_format($totalDue, 2) }}
                </strong>
            </div>
            <div class="text-muted small" style="max-width:420px;">
                @if($totalDue > 0)
                    {{ get_phrase('You can register and select courses now, but you must clear this balance before you can confirm your registration.') }}
                @else
                    {{ get_phrase('Your fees are settled — you can confirm your course registration.') }}
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap mb-3">
            <div class="title mb-3 pb-0 border-0">
                <h3 class="mb-0">{{ get_phrase('Available Courses') }}</h3>
            </div>
            @if($availableSubjects->isEmpty())
                <p class="text-muted mb-0">{{ get_phrase('No courses are available for your programme/class yet.') }}</p>
            @else
                <form method="POST" action="{{ route('student.my_courses.register') }}">
                    @csrf
                    <div class="table-responsive">
                        <table class="table eTable">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>{{ get_phrase('Code') }}</th>
                                    <th>{{ get_phrase('Title') }}</th>
                                    <th>{{ get_phrase('Credits') }}</th>
                                    <th>{{ get_phrase('Type') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($availableSubjects as $subject)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="subject_ids[]" value="{{ $subject->id }}"
                                            {{ $registeredSubjectIds->contains($subject->id) ? 'disabled checked' : '' }}>
                                    </td>
                                    <td>{{ $subject->code ?: '—' }}</td>
                                    <td>{{ $subject->name }}</td>
                                    <td>{{ $subject->credits ?: '—' }}</td>
                                    <td>{{ $subject->course_type ? ucfirst($subject->course_type) : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Register Selected Courses') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <div class="title mb-3 pb-0 border-0">
                <h3 class="mb-0">{{ get_phrase('My Registered Courses') }}</h3>
            </div>
            <div class="table-responsive">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>{{ get_phrase('Code') }}</th>
                            <th>{{ get_phrase('Title') }}</th>
                            <th>{{ get_phrase('Credits') }}</th>
                            <th>{{ get_phrase('Status') }}</th>
                            <th class="text-end">{{ get_phrase('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($registrations as $registration)
                        <tr>
                            <td>{{ $registration->subject?->code ?: '—' }}</td>
                            <td>{{ $registration->subject?->name ?? get_phrase('Removed course') }}</td>
                            <td>{{ $registration->subject?->credits ?: '—' }}</td>
                            <td>
                                <span class="badge bg-{{ $registration->status === 'confirmed' ? 'success' : 'warning' }}">
                                    {{ ucfirst($registration->status) }}
                                </span>
                            </td>
                            <td class="text-end">
                                @if($registration->status === 'registered')
                                    <form method="POST" action="{{ route('student.my_courses.confirm', $registration->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="eBtn eBtn-sm eBtn-success">{{ get_phrase('Confirm') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('student.my_courses.drop', $registration->id) }}" class="d-inline" onsubmit="return confirm('{{ get_phrase('Drop this course?') }}')">
                                    @csrf
                                    <button type="submit" class="eBtn eBtn-sm eBtn-danger">{{ get_phrase('Drop') }}</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">{{ get_phrase('No courses registered yet') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
