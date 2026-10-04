{{--
    ===========================================================================
    STUDENT COURSE CATALOGUE — responsive course cards
    ===========================================================================

    Presentation only. Every row here was already decided by
    `StudentCourseOfferingDiscovery::discover()` and shaped into a card by
    `StudentCourseCatalogue`; this template renders those cards and posts the
    registration actions that already existed. It adds no eligibility rule, no
    query and no academic record.

    WHY A CARDS/CARDS INCLUDE RATHER THAN FOUR NEAR-IDENTICAL BLOCKS
    ------------------------------------------------------------------
    The four sections differ only in which action they offer. One include, keyed
    on `$card['action']`, means the card cannot render a Confirm button on a
    section where Confirm is not allowed.

    THE COVER IS OFFERED ONLY WHERE THE SERVER WOULD SERVE IT
    ------------------------------------------------------------------
    `CourseCoverImage::assertMayView()` admits a student on a CONFIRMED
    registration only. So `$card['has_cover']` is true for confirmed cards and
    nothing else, and every other state gets the branded placeholder. An `<img>`
    pointing at a URL the server answers 404 would be a broken image in the grid.

    The `<img>` src is the AUTHORISING ROUTE, never a storage path. The stored
    path never leaves the server.

    RESPONSIVE CONTRACT
    ------------------------------------------------------------------
    4 columns >= 1200px, 2 columns >= 768px, 1 column below — declared once, in
    `.sc-grid` in `public/css/student-courses.css`. Asserted by
    StudentCourseCatalogueUiTest so the contract cannot quietly change.
--}}

@php
    /**
     * `?? []` defaults throughout. `CourseRegistrationOfferingFoundationTest`
     * renders this view directly with a bare `discover()` array, without going
     * through the controller, so the template must not assume the catalogue keys
     * exist.
     */
    $catalogue = $catalogue ?? [];
    $sections = $catalogue['sections'] ?? [];
    $filters = $catalogue['filters'] ?? ['q' => '', 'year' => '', 'period' => '', 'years' => [], 'periods' => []];
    $hasFilter = (bool) ($catalogue['has_filter'] ?? false);

    /**
     * Sections are only worth rendering when the state allows it.
     *
     * `available` needs a year AND a period: `discover()` cannot resolve either
     * without them, so offering it without would render an empty heading where
     * the old page showed nothing at all.
     *
     * The rest are hidden only while there is genuinely nothing to show AND no
     * filter is applied — so a search that matches nothing explains itself
     * instead of leaving the student on an apparently empty page.
     */
    $showSection = function (string $key) use ($sections, $year, $period, $hasFilter): bool {
        $section = $sections[$key] ?? null;
        if (! $section) {
            return false;
        }
        if ($key === 'available' && (! $year || ! $period)) {
            return false;
        }
        if (! $hasFilter && (int) ($section['total'] ?? 0) === 0) {
            return false;
        }
        return true;
    };
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <h4>{{ get_phrase('My Courses') }}</h4>
            <p class="text-muted mb-0">
                @if($year && $period)
                    {{ $year->label }} &middot; {{ $period->label }}
                @else
                    {{ get_phrase('Higher Education course registration') }}
                @endif
            </p>
        </div>
    </div>
</div>

@if(session('message'))<div class="alert alert-success mt-3">{{ session('message') }}</div>@endif
@if(session('error'))<div class="alert alert-warning mt-3">{{ session('error') }}</div>@endif
@if($message)<div class="alert {{ in_array($state, ['integrity_review', 'configuration', 'programme_mismatch', 'assignment_missing', 'placement_incomplete'], true) ? 'alert-warning' : 'alert-info' }} mt-3">{{ $message }}</div>@endif

{{-- SEARCH AND FILTERS
     A plain GET form to the same route: it works with JavaScript disabled, and
     it cannot be a POST that some extension or a reload could turn into an
     unintended write. The three inputs read `q`, `year` and `period`, which is
     exactly what `StudentCourseCatalogue` filters on. --}}
<div class="eSection-wrap mt-3 mb-4">
    <form method="GET" action="{{ route('student.my_courses') }}" class="sc-filters" role="search">
        <div class="sc-filters__field">
            <label class="sc-filters__label" for="sc-q">{{ get_phrase('Search courses') }}</label>
            <input type="search" id="sc-q" name="q" value="{{ $filters['q'] }}"
                   placeholder="{{ get_phrase('Course title, code or lecturer') }}"
                   class="form-control eForm-control" autocomplete="off">
        </div>

        <div class="sc-filters__field">
            <label class="sc-filters__label" for="sc-year">{{ get_phrase('Academic Year') }}</label>
            <select id="sc-year" name="year" class="form-select eForm-select">
                <option value="">{{ get_phrase('All years') }}</option>
                @foreach($filters['years'] as $option)
                    <option value="{{ $option }}" @selected($filters['year'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div class="sc-filters__field">
            <label class="sc-filters__label" for="sc-period">{{ get_phrase('Semester') }}</label>
            <select id="sc-period" name="period" class="form-select eForm-select">
                <option value="">{{ get_phrase('All semesters') }}</option>
                @foreach($filters['periods'] as $option)
                    <option value="{{ $option }}" @selected($filters['period'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div class="sc-filters__field">
            <button type="submit" class="eBtn eBtn-primary w-100">{{ get_phrase('Filter') }}</button>
        </div>

        @if($hasFilter)
            <div class="sc-filters__field">
                <a href="{{ route('student.my_courses') }}" class="btn btn-link p-0">{{ get_phrase('Clear') }}</a>
            </div>
        @endif
    </form>

    @if($hasFilter)
        {{-- Both numbers, always. A plain "0 results" is indistinguishable from
             "you have no courses"; "0 / 2" says the filter hid everything the
             student actually has.

             The numbers are interpolated here rather than passed as `:count`
             placeholders: `get_phrase()` is a phrase lookup and performs no
             placeholder substitution, so `:count` would reach the page literally. --}}
        <p class="sc-card__note mt-2 mb-0">
            {{ (int) ($catalogue['matching_count'] ?? 0) }} / {{ (int) ($catalogue['total_count'] ?? 0) }}
            {{ get_phrase('courses match your search') }}
        </p>
    @endif
</div>

@foreach(['available', 'pending', 'confirmed', 'history'] as $piieSectionKey)
    @if($showSection($piieSectionKey))
        @php($piieSection = $sections[$piieSectionKey])

        <div class="sc-section" data-sc-section="{{ $piieSectionKey }}">
            <div class="sc-section__head">
                <h5 class="sc-section__title">{{ $piieSection['heading'] }}</h5>
                <span class="sc-section__count">
                    {{ $piieSection['cards']->count() }}@if($piieSection['total'] !== $piieSection['cards']->count()) / {{ $piieSection['total'] }}@endif
                </span>
            </div>

            @if($piieSectionKey === 'available')
                {{-- The tenant's own term for a course unit ("Course Unit" at PIIE,
                     possibly something else elsewhere) plus the period it applies to.
                     Dropped from this view during the redesign by accident; the
                     controller still supplies it and multi-tenant terminology is not
                     something a card grid should hard-code away. --}}
                <p class="text-muted small">
                    {{ get_phrase('Choose one Offering per course unit. Parallel Offerings are listed separately.') }}
                    <span class="d-block">
                        {{ $courseUnitLabel }}: {{ $year->label }} &middot; {{ $period->label }}
                    </span>
                </p>
            @endif

            @if($piieSection['cards']->isEmpty())
                {{-- Two different messages on purpose: "you have none" is not the
                     same as "the filter hid them", and showing the wrong one sends
                     a student looking for a course they actually have. --}}
                <p class="sc-empty">
                    {{ $piieSection['filtered_empty']
                        ? get_phrase('No courses match your search in this section.')
                        : $piieSection['empty_state'] }}
                </p>
            @else
                <div class="sc-grid">
                    @foreach($piieSection['cards'] as $card)
                        <article class="sc-card" data-sc-card="{{ $card['key'] }}">
                            <div class="sc-card__media">
                                @if($card['has_cover'])
                                    <img src="{{ route('student.courses.cover', $card['offering_id']) }}"
                                         alt="{{ $card['title'] }}"
                                         loading="lazy" decoding="async">
                                @else
                                    {{-- Neutral PIIE placeholder. The course code is set in
                                         type rather than a repeated stock image, and the
                                         tone is derived from the Offering so a course keeps
                                         the same colour between loads. --}}
                                    <div class="sc-card__fallback sc-card__fb-{{ $card['tone'] }}">
                                        <span class="sc-card__fb-mark" aria-hidden="true">
                                            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(
                                                $card['code'] !== '' ? $card['code'] : ($card['title'] ?? 'PIIE'),
                                                0,
                                                6
                                            )) }}
                                        </span>
                                        @if($card['code'] !== '')
                                            <span class="sc-card__fb-label">{{ $card['code'] }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="sc-card__body">
                                <span class="sc-card__status sc-card__status--{{ $card['status_tone'] }}">
                                    {{ $card['status_label'] }}
                                </span>

                                @if($card['code'] !== '')
                                    <span class="sc-card__code">{{ $card['code'] }}</span>
                                @endif

                                <h6 class="sc-card__title">{{ $card['title'] }}</h6>

                                <div class="sc-card__meta">
                                    @if($card['year'] !== '')
                                        <span>{{ $card['year'] }}</span>
                                    @endif
                                    @if($card['period'] !== '')
                                        <span>&middot; {{ $card['period'] }}</span>
                                    @endif
                                    @if($card['credits'] !== null)
                                        <span>&middot; {{ number_format((float) $card['credits'], 2) }} {{ get_phrase('credits') }}</span>
                                    @endif
                                    {{-- The Offering reference is the identifier the academic
                                         office quotes, so it belongs on the card rather than
                                         only in a form the student cannot see. --}}
                                    @if($card['reference'] !== '')
                                        <span>&middot; {{ $card['reference'] }}</span>
                                    @endif
                                </div>

                                @if($card['lecturer'] !== '')
                                    <div class="sc-card__lecturer">
                                        {{ get_phrase('Lecturer') }}: {{ $card['lecturer'] }}
                                        @php($piieOthers = collect($card['team'])->reject(fn ($n) => $n === $card['lecturer'])->values())
                                        @if($piieOthers->isNotEmpty())
                                            <span class="text-muted">({{ $piieOthers->implode(', ') }})</span>
                                        @endif
                                    </div>
                                @elseif($card['section'] === 'confirmed')
                                    {{-- Said explicitly on a confirmed course. Silence would be
                                         read as "no lecturer assigned", which is a different
                                         and wrong statement. --}}
                                    <div class="sc-card__lecturer text-muted">
                                        {{ get_phrase('Lecturer not yet assigned') }}
                                    </div>
                                @endif

                                <div class="sc-card__foot">
                                    @switch($card['action'])
                                        @case('register')
                                            <form method="POST" action="{{ route('student.my_courses.register') }}">
                                                @csrf
                                                <input type="hidden" name="course_offering_id" value="{{ $card['offering_id'] }}">
                                                <button type="submit" class="eBtn eBtn-primary w-100">
                                                    {{ get_phrase('Register for this Offering') }}
                                                </button>
                                            </form>
                                            @break

                                        @case('confirm')
                                            {{-- Confirmation is gated on financial eligibility
                                                 exactly as the table was: the card must not
                                                 offer a button the controller will refuse. --}}
                                            <form method="POST" action="{{ route('student.my_courses.confirm', $card['registration_id']) }}">
                                                @csrf
                                                <button type="submit" class="eBtn eBtn-success w-100">{{ get_phrase('Confirm') }}</button>
                                            </form>
                                            @break

                                        @case('blocked')
                                            <span class="sc-card__note">
                                                {{ get_phrase('Confirmation is unavailable until your outstanding balance is resolved.') }}
                                            </span>
                                            <a href="{{ route('student.fee_manager.list') }}" class="eBtn eBtn-sm w-100">
                                                {{ get_phrase('View fee information') }}
                                            </a>
                                            @break

                                        @case('open')
                                            {{-- Both links sit on a CONFIRMED registration, which is
                                                 the same authority each page re-checks, so neither
                                                 can 404 for a registered student. --}}
                                            <a href="{{ route('student.courses.content', $card['offering_id']) }}"
                                               class="eBtn eBtn-primary w-100">{{ get_phrase('Open Course') }}</a>
                                            <a href="{{ route('student.courses.assignments.index', $card['offering_id']) }}"
                                               class="eBtn eBtn-sm w-100">{{ get_phrase('Assignments') }}</a>
                                            @break

                                        @default
                                            @if($card['section'] === 'history')
                                                <span class="sc-card__note">{{ get_phrase('No actions available for a dropped course.') }}</span>
                                            @endif
                                    @endswitch

                                    {{-- Withdrawal rules are unchanged and still keyed off the
                                         Offering's own status, not the registration's. --}}
                                    @if(in_array($card['section'], ['pending', 'confirmed'], true) && $card['registration_id'] !== null)
                                        @if($card['offering_status'] === 'open')
                                            <form method="POST" action="{{ route('student.my_courses.drop', $card['registration_id']) }}"
                                                  onsubmit="return confirm('{{ get_phrase('Drop this course?') }}')">
                                                @csrf
                                                <button type="submit" class="eBtn eBtn-sm eBtn-danger w-100">{{ get_phrase('Drop') }}</button>
                                            </form>
                                        @elseif($card['offering_status'] === 'in_progress')
                                            <span class="sc-card__note">{{ get_phrase('Withdrawal requires Academic Office assistance.') }}</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
@endforeach

{{-- Nothing at all rendered and no filter applied: say so, rather than
     presenting an empty page. --}}
@if(empty(array_filter(array_keys($sections), fn ($k) => $showSection($k))))
    <p class="sc-empty">{{ get_phrase('You have no course registrations yet.') }}</p>
@endif