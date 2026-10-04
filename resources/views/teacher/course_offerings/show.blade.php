@extends('teacher.navigation')
@section('content')
@php
    $courseUnitLabel = $terms['course_unit'] ?? 'Course Unit';
    $periodLabel = $terms['academic_period'] ?? 'Academic Period';
    $statusLabels = ['draft' => 'Draft', 'open' => 'Open', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $statusBadges = ['draft' => 'warning text-dark', 'open' => 'success', 'in_progress' => 'primary', 'completed' => 'secondary', 'cancelled' => 'dark'];
    $headline = match ($offering->status) {
        'draft' => 'This Course Offering is still being prepared. Teaching actions become available once it is open.',
        'open' => 'Registration is open for this Course Offering. Teaching actions become available once it starts.',
        'in_progress' => 'Teaching is currently in progress for this Course Offering.',
        'completed' => 'This Course Offering has been completed. Teaching records remain available for reference.',
        default => 'This Course Offering was cancelled.',
    };
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.index') }}" class="btn btn-sm btn-outline-secondary mb-2">Back to My Course Offerings</a>
    <h4 class="mb-1">{{ $offering->subject?->code }} &mdash; {{ $offering->subject?->name }}</h4>
    <p class="text-muted mb-0">{{ $offering->academicYear?->label }} &middot; {{ $offering->academicPeriod?->label }}</p>
</div>

<div class="alert alert-{{ in_array($offering->status, ['in_progress', 'open'], true) ? 'info' : 'secondary' }} mb-3" role="status">{{ $headline }}</div>

@include('teacher.course_offerings._workspace_nav')


@if(!$offering->my_allocation_is_current)
    <div class="alert alert-warning" role="status">
        <strong>Your teaching allocation is not currently in force.</strong>
        @if($offering->my_allocation_status === 'ended')
            This allocation has ended, so the workspace is read-only and no active teaching actions are available.
        @else
            This allocation has been planned but is not in force yet, so the workspace is read-only.
        @endif
    </div>
@endif

@if($offering->my_allocation_is_testing_access ?? false)
    {{-- Authorised pre-start testing. Deliberately NOT presented as normal
         academic access: the lecturer's agreed start date and the Academic
         Period are both still in the future, and nothing has been rewritten. --}}
    <div class="alert alert-info" role="status">
        <strong>Testing access active</strong> &mdash; pre-start access has been granted for authorised system testing.
        This Course Offering was deliberately started early, so your teaching actions are available now. Your allocation
        ({{ $offering->my_allocation_starts_on }} to {{ $offering->my_allocation_ends_on ?: 'no end date' }})
        and the Academic Period ({{ $offering->academicPeriod?->label }}) are unchanged; only the pre-start date
        gate has been relaxed for this account.
    </div>
@endif

<section class="eSection-wrap mb-3" aria-label="Course Offering summary">
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <dl class="row mb-0">
                <dt class="col-5 col-sm-4">{{ $courseUnitLabel }}</dt>
                <dd class="col-7 col-sm-8">{{ $offering->subject?->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject?->name }}</dd>
                <dt class="col-5 col-sm-4">Academic Year</dt>
                <dd class="col-7 col-sm-8">{{ $offering->academicYear?->label }}</dd>
                <dt class="col-5 col-sm-4">{{ $periodLabel }}</dt>
                <dd class="col-7 col-sm-8">{{ $offering->academicPeriod?->label }}</dd>
                <dt class="col-5 col-sm-4">Study Plan / {{ $terms['programme'] }}</dt>
                <dd class="col-7 col-sm-8">
                    @if($offering->my_study_plans->isEmpty())<span class="text-muted">Not linked to a Study Plan</span>
                    @else{{ $offering->my_study_plans->map(fn ($version) => 'Study Plan '.$version)->implode(', ') }}@endif
                    @if($offering->my_programmes->isNotEmpty())<div class="small text-muted">{{ $offering->my_programmes->implode(', ') }}</div>@endif
                </dd>
                <dt class="col-5 col-sm-4">Stage / Year of Study</dt>
                <dd class="col-7 col-sm-8">{{ $offering->my_stages->isNotEmpty() ? $offering->my_stages->implode(', ') : 'Not recorded' }}</dd>
                <dt class="col-5 col-sm-4">Offering Status</dt>
                <dd class="col-7 col-sm-8"><span class="badge bg-{{ $statusBadges[$offering->status] ?? 'secondary' }}">{{ $statusLabels[$offering->status] ?? ucfirst($offering->status) }}</span></dd>
                <dt class="col-5 col-sm-4">My Teaching Role</dt>
                <dd class="col-7 col-sm-8">{{ $offering->my_role_label }}</dd>
            </dl>
        </div>
        <div class="col-6 col-lg-3">
            <h6>Confirmed Students</h6>
            <div class="fs-3">{{ $offering->my_confirmed_students }}</div>
            @if($roster->isNotEmpty())<a class="small" href="{{ route('teacher.course_offerings.students', $offering->id) }}">View students</a>@endif
        </div>
        <div class="col-6 col-lg-3">
            {{-- The total, AND what it is made of. A bare number cannot be
                 checked: a lecturer whose dashboard said "0" while five classes
                 plainly existed had no way to tell a real zero from a broken
                 query. The breakdown makes both visible, and every part of it
                 is derived from the same Offering-scoped set the page lists. --}}
            <h6>Live Classes</h6>
            <div class="fs-3">{{ $liveClassCounts['total'] }}</div>
            @if($liveClassCounts['total'] > 0)
                <div class="small text-muted" data-testid="live-class-breakdown">
                    @foreach($liveClassCounts['parts'] as $partLabel => $partCount)
                        @if($partCount > 0)
                            <span class="d-inline-block me-2">{{ $partLabel }}: {{ $partCount }}</span>
                        @endif
                    @endforeach
                </div>
            @endif
            @if($canTeach)<a class="small" href="{{ collect($modules)->firstWhere('key', 'live_classes')['url'] }}">Go to Live Classes</a>@endif
        </div>
    </div>
</section>

<section class="eSection-wrap mb-3" aria-label="Teaching modules">
    <h5 class="mb-3">Teaching</h5>
    <div class="row g-2">
        @foreach($modules as $module)
            <div class="col-12 col-md-6 col-xl-4">
                <div class="border rounded p-3 h-100 {{ $module['available'] ? '' : 'bg-light' }}">
                    <div class="fw-semibold">{{ $module['label'] }}</div>
                    <p class="small text-muted mb-2">{{ $module['description'] }}</p>
                    @if($module['available'])
                        <a class="btn btn-sm btn-outline-primary" href="{{ $module['url'] }}">Open {{ $module['label'] }}</a>
                    @else
                        <span class="small text-muted">Not available for this Course Offering</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    <p class="small text-muted mt-2 mb-0">Attendance and Assignments are not yet scoped to a Course Offering, so they are not offered here. Use the existing Attendance and Assignments screens from the main menu.</p>
</section>

<section class="eSection-wrap mb-3" aria-label="Live Classes for this Course Offering">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <h5 class="mb-1">Live Classes for this {{ $courseUnitLabel }}</h5>
            @php
                $myStudyPlans = collect($offering->my_study_plans ?? [])->pluck('version')->filter()->unique()->implode(', ');
            @endphp
            <p class="text-muted small mb-0">
                {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
                @if($myStudyPlans) &middot; Study Plan {{ $myStudyPlans }} @endif
                &middot; <span class="badge bg-primary">{{ $statusLabels[$offering->status] ?? ucfirst($offering->status) }}</span>
                @if($offering->my_role_label) &middot; You are facilitating as {{ $offering->my_role_label }} @endif
            </p>
        </div>
        @if($canTeach)
            <a class="btn btn-primary btn-sm" href="{{ route('teacher.course_offerings.live_classes.create', $offering->id) }}">Schedule a Live Class</a>
        @endif
    </div>
    @if($liveClasses->isEmpty())
        <div class="border rounded p-4 text-center" role="status">
            <h6>No Live Classes have been scheduled yet.</h6>
            <p class="text-muted mb-0">Live Classes scheduled for this Course Offering will be listed here.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th scope="col">Title</th><th scope="col">Platform</th><th scope="col">Scheduled</th><th scope="col">Status</th></tr></thead>
                <tbody>
                @foreach($liveClasses as $liveClass)
                    <tr>
                        <td><a href="{{ route('teacher.live_classes.show', $liveClass->id) }}">{{ $liveClass->title }}</a></td>
                        <td>{{ ucfirst($liveClass->platform ?? '—') }}</td>
                        {{-- Through the ONE display resolver, in the VIEWER's own
                             clock. This cell used to call ->format() straight on the
                             stored instant, which renders in the application default -
                             so a lecturer in Kampala read UTC here while the same
                             class showed Kampala on his Live Class page. The
                             SCHEDULER's timezone is never the viewer's. --}}
                        <td>{{ app(App\Support\LiveClasses\LiveClassDisplay::class)->for($liveClass, auth()->user())->timeRange24() ?: '—' }}</td>
                        <td>{{ $liveClass->displayStatusLabel() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- ══════════════════════════════════════════════════════════════════════
     COURSE IMAGE

     The capability existed with no interface: `CourseCoverImage` had the
     relationship, the safe read path and `set()`/`clear()` behind an allocation
     check, and nothing called them - so a lecturer had no way to give a course a
     cover and the authorised read path led to nothing. These two forms are the
     missing third; every rule stays in the service, which re-checks the allocation
     itself rather than trusting the page that rendered this form.

     A LECTURER control, deliberately. The service separates viewing from changing
     on purpose: a confirmed student may read their own course's cover and may not
     alter it, because a student who can decorate their own course unit can
     misrepresent it.
     ══════════════════════════════════════════════════════════════════════ --}}
@if($canTeach)
    <div class="card mt-3">
        <div class="card-body">
            <h5 class="card-title">Course image</h5>

            @if(session('success'))
                <div class="alert alert-success py-2" role="status">{{ session('success') }}</div>
            @endif

            @if($offering->cover_image_path)
                <div class="mb-3">
                    <img src="{{ route('teacher.course_offerings.cover', $offering->id) }}"
                         alt="Current course image for {{ $offering->subject?->name ?? $offering->reference }}"
                         class="img-fluid rounded"
                         style="max-height: 180px; width: auto;"
                         data-testid="cc-current-cover">
                    <p class="form-text mb-0">
                        The current image. Choosing a new one REPLACES it, and the
                        replaced file is deleted.
                    </p>
                </div>
            @else
                {{-- An honest absence, not a placeholder picture. --}}
                <p class="text-muted small mb-3" data-testid="cc-no-cover">
                    This course has no image. Students see the course code on its
                    header instead, which is honest about the absence where a stock
                    photograph would not be.
                </p>
            @endif

            <form method="post" enctype="multipart/form-data"
                  action="{{ route('teacher.course_offerings.cover.set', $offering->id) }}"
                  data-testid="cc-cover-form">
                @csrf

                <div class="mb-2">
                    <label for="cc-cover-image" class="form-label">Choose an image</label>
                    <input type="file"
                           class="form-control @error('cover_image') is-invalid @enderror"
                           id="cc-cover-image"
                           name="cover_image"
                           accept="image/jpeg,image/png,image/webp"
                           required
                           data-testid="cc-cover-input">
                    <div class="form-text">
                        JPEG, PNG or WebP, up to 4 MB. A course image is institution
                        material rather than student evidence, which is why the limit
                        is lower than for submitted work.
                    </div>
                    @error('cover_image')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <button type="submit" class="btn btn-primary btn-sm" data-testid="cc-cover-save">
                    {{ $offering->cover_image_path ? 'Replace the image' : 'Add an image' }}
                </button>
            </form>

            @if($offering->cover_image_path)
                {{-- Removal is a legitimate end state: the relationship is optional,
                     so "no cover" has to be as reachable as "has one". --}}
                <form method="post" class="mt-2"
                      action="{{ route('teacher.course_offerings.cover.clear', $offering->id) }}"
                      onsubmit="return confirm('Remove the course image? The saved file is deleted.');"
                      data-testid="cc-cover-clear-form">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            data-testid="cc-cover-clear">
                        Remove the image
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif
@endsection
