{{--
    THE LECTURER'S COURSE OFFERING WORKSPACE NAVIGATION.

    One partial, included by every lecturer page inside a Course Offering. It is
    supplied automatically by CourseOfferingWorkspaceNavServiceProvider for the
    whole `teacher.course_offerings.*` namespace, so a page cannot reach
    production without it - the defect this replaces was a hand-written tab strip
    that lived in the Overview view and therefore existed nowhere else.

    IT KEEPS THE COURSE OFFERING CONTEXT VISIBLE
    The code, the subject, the academic year and the period, on every page. A
    lecturer who is three screens deep in marking work still needs to know which
    Course Unit they are in, and the browser title is not where anyone looks for
    that.

    ACTIVE STATE IS MARKED BOTH VISUALLY AND FOR ASSISTIVE TECH
    `aria-current="page"` accompanies the `active` class, so the current section
    is announced rather than only coloured - the same rule the Course Content
    three-state list already follows.

    NOTHING HERE IS A DEAD LINK
    A tab with no engine behind it renders as a disabled span with no href: never
    a link to a stub page, never a 404. A tab the lecturer may not use is shown
    as unavailable rather than linked and then refused. Every href is a real URL
    that re-checks the lecturer's own allocation on this exact Course Offering
    when it is opened, so the navigation is a pointer and never the access control.
--}}
@if(!empty($workspaceTabs) && !empty($workspaceNavOffering))
    @php
        /** @var \App\Models\CourseOffering $navOffering */
        $navOffering = $workspaceNavOffering;
        $contextLine = collect([
            $navOffering->subject?->code,
            $navOffering->subject?->name,
        ])->filter()->implode(' — ');
        $termLine = collect([
            $navOffering->academicYear?->label,
            $navOffering->academicPeriod?->label,
        ])->filter()->implode(' · ');
    @endphp

    <div class="mb-3" data-testid="offering-workspace-nav">
        {{-- Context first, so it is read before the tabs and is never lost at the
             bottom of a long page. --}}
        <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
            <div>
                <div class="fw-semibold" data-testid="offering-context-title">
                    {{ $contextLine ?: $navOffering->reference }}
                </div>
                @if($termLine)
                    <div class="small text-muted" data-testid="offering-context-term">{{ $termLine }}</div>
                @endif
            </div>
            <a href="{{ route('teacher.course_offerings.index') }}"
               class="btn btn-sm btn-outline-secondary flex-shrink-0">Back to My Course Offerings</a>
        </div>

        <nav aria-label="Course Offering workspace">
            <ul class="nav nav-tabs flex-nowrap overflow-auto" style="white-space: nowrap">
                @foreach($workspaceTabs as $tab)
                    <li class="nav-item">
                        @if($tab['url'])
                            <a class="nav-link {{ $tab['active'] ? 'active' : '' }}"
                               href="{{ $tab['url'] }}"
                               @if($tab['active']) aria-current="page" @endif
                               title="{{ $tab['description'] }}"
                               @if($tab['active']) data-testid="workspace-tab-active" @endif
                               data-testid="workspace-tab-{{ $tab['key'] }}">{{ $tab['label'] }}</a>
                        @else
                            {{-- No engine, or not available to this lecturer. Plain text
                                 with no href: it cannot be opened, and a tab that
                                 cannot be opened must not look like one that can. --}}
                            <span class="nav-link disabled text-muted {{ $tab['coming_soon'] ? '' : 'opacity-75' }}"
                                  aria-disabled="true"
                                  title="{{ $tab['coming_soon'] ? $tab['description'] : 'Not available for this Course Offering.' }}"
                                  data-testid="workspace-tab-{{ $tab['key'] }}">{{ $tab['label'] }}@if($tab['coming_soon'])<sup class="ms-1" title="Not built yet">*</sup>@endif</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if(collect($workspaceTabs)->contains('coming_soon', true))
                <p class="small text-muted mt-1 mb-0">
                    * Not built yet. Overview, Content, Live Classes, Assignments and Students all work now;
                    Gradebook and Analytics will be built inside this same Course Offering, and neither is faked
                    in the meantime.
                </p>
            @endif
        </nav>
    </div>
@endif
