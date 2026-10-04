@extends('teacher.navigation')
@section('content')
@php
    /**
     * The higher-education "Schedule Live Class" entry point.
     *
     * A Course Offering is the only academic choice a lecturer makes. Choosing
     * one here carries it into the real form as route context, so the Offering
     * is never re-selected and the class can never end up attached to a
     * different Offering than the one the lecturer read on this screen.
     *
     * Every row is derived from the lecturer's own allocation via
     * LecturerCourseOfferingAccess, so a lecturer who has not been allocated to
     * an Offering simply cannot see it, and a lecturer whose allocation has not
     * started yet does not see it either - unless they are a governed
     * pre-start tester, in which case it appears exactly as the workspace shows
     * it.
     */
@endphp

<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gr-15">
        <div>
            <h4>{{ get_phrase('Schedule Live Class') }}</h4>
            <p class="text-muted mb-0">{{ get_phrase('Choose the Course Offering this class belongs to.') }}</p>
        </div>
        <a href="{{ route('teacher.live_classes.index') }}" class="eBtn eBtn-dark">{{ get_phrase('Back to Live Classes') }}</a>
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            @if($offerings->isEmpty())
                <div class="border rounded p-4 text-center" role="status">
                    <h6>{{ get_phrase('You have no Course Offering to schedule into yet.') }}</h6>
                    <p class="text-muted mb-3">{{ get_phrase('A Live Class belongs to a Course Offering. Ask your administrator to allocate you to one, then this page will list it here.') }}</p>
                    <a href="{{ route('teacher.course_offerings.index') }}" class="eBtn eBtn-primary">{{ get_phrase('Go to My Course Offerings') }}</a>
                </div>
            @else
                <div class="row g-3">
                    @foreach($offerings as $offeringRow)
                        @php
                            $unit = optional($offeringRow->subject)->name ?? $offeringRow->reference;
                        @endphp
                        <div class="col-12 col-lg-6">
                            <div class="border rounded p-3 h-100 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div>
                                        <h6 class="mb-1">{{ $offeringRow->reference ?: $unit }}</h6>
                                        <p class="mb-1">{{ $unit }}</p>
                                        <p class="text-muted small mb-2">
                                            {{ $offeringRow->academicYear?->label ?? '' }}
                                            @if($offeringRow->academicPeriod?->label)&middot; {{ $offeringRow->academicPeriod->label }}@endif
                                            @if($offeringRow->my_role_label)&middot; {{ $offeringRow->my_role_label }}@endif
                                        </p>
                                    </div>
                                    <span class="badge bg-primary">{{ \Illuminate\Support\Str::headline($offeringRow->status) }}</span>
                                </div>
                                <div class="mt-auto">
                                    <a href="{{ route('teacher.course_offerings.live_classes.create', $offeringRow->id) }}"
                                       class="eBtn eBtn-primary w-100 text-center">{{ get_phrase('Schedule Live Class') }}</a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
