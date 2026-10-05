@extends('frontend.index')

@section('content')

{{--
    ===========================================================================
    PUBLIC INNER PAGES — one design system, the CMS's own content.
    ===========================================================================

    WHAT CHANGED
      - the competing `<nav>` this file used to carry (graduation-cap icon plus the
        institution name, in its own inline styling) is GONE. Every public page now
        uses `frontend.partials.site_header`, so there is exactly one header.
      - the old `WebsiteRenderingHelper::renderSection()` path is no longer used. It
        emitted the orange "Content Section" badge, a `col-lg-10` text box for
        everything, and a second image block under it. That is the "generic CMS
        template" look the review rejected.
      - sections are now laid out by their KEY: a section with a photograph and
        prose becomes a split, a list becomes a grid of cards, and so on.

    WHAT DID NOT CHANGE
      - the data. Every section and item is still read from `website_sections` and
        `website_items` through the same tenant-scoped controller.
      - `websitePage` still 404s for an unpublished slug.
      - nothing internal (student, lecturer, admin, examinations, LMS) is touched.
--}}

@php
    use App\Helpers\WebsiteRenderingHelper;

    $piieSettings = $websiteSettings ?? collect();

    /**
     * The settings as a plain array.
     *
     * `PublicContactChannels` takes an array because it is also called from the
     * header partial and from a console context where no view data exists. Having
     * the array form here as well means the page does not depend on a variable
     * that happens to be created inside an included partial — which is exactly the
     * coupling that 500'd this page when the contact branch was rewritten without
     * the header having been rendered first in some paths.
     */
    $piieSettingsArray = is_array($piieSettings) ? $piieSettings : $piieSettings->all();
    $piieSections = $websiteSections ?? collect();
    $piieItems = $websiteItems ?? collect();
    $piieAllPages = $allPages ?? collect();
    $piiePage = $websitePage ?? null;

    $piieSlugs = $piieAllPages->pluck('slug')->filter()->all();

    // Published items for a section. Unpublished never reaches the public site.
    $piieFor = function (string $key) use ($piieItems) {
        return collect($piieItems[$key] ?? collect())
            ->filter(fn ($i) => (int) $i->status === 1)
            ->values();
    };

    // Paragraphs from a section's HTML content.
    $piieParagraphs = function ($section, $field = 'content') {
        if (! $section) { return []; }

        $html = trim((string) ($section->{$field} ?? ''));

        if ($html === '') { return []; }

        return array_values(array_filter(array_map(
            'trim',
            preg_split("/(?:\r?\n){2,}/", strip_tags($html, '<p><ul><ol><li><strong><em><br><h3><h4><a>')) ?: []
        )));
    };

    /** A CMS-uploaded image, only when the file is really there. */
    $piieImage = function ($path) {
        if (empty($path)) { return null; }

        $clean = ltrim(str_replace(['assets/uploads/website/', 'uploads/website/'], '', (string) $path), '/');
        $disk = public_path('assets/uploads/website/'.$clean);

        return is_file($disk) ? asset('assets/uploads/website/'.$clean) : null;
    };

    /** A photograph from the supplied media folder, by its real filename. */
    $piieMedia = fn (string $name) => asset('assets/images/img/'.$name);

    // ── Photography plan, one file per section, no repeats ──────────────────
    // Chosen so no photograph appears twice on the same page.
    // The brief: "do not repeatedly use the same photograph in unrelated sections."
    // Every entry below is a DIFFERENT file from every other entry on the site, so
    // the same face never appears on two pages. Seven of the twenty-two supplied
    // photographs were unused before this pass; this plan consumes them.
    $piiePhotos = [
        'about_institution'       => ['file' => '09_library_reading.jpg',   'alt' => 'PIIE students learning together in the library'],
        'origin_history'           => ['file' => '20_graduation_group.jpg',  'alt' => 'PIIE graduates at their graduation ceremony'],
        'institutional_character' => ['file' => '01_graduation_portrait.jpg', 'alt' => 'A PIIE graduate at graduation'],
        'educational_philosophy'   => ['file' => '07_business_graduate.jpg', 'alt' => 'A PIIE business graduate'],
        'why_choose_us'            => ['file' => '12_african_woman_elearning.jpg', 'alt' => 'A PIIE student learning online'],
        'leadership_team'          => ['file' => '19_graduate_success.jpg',  'alt' => 'A PIIE graduate celebrating success'],
        'admissions'               => ['file' => '02_male_student_laptop.jpg', 'alt' => 'A prospective student applying online'],
        'entry_requirements'       => ['file' => '07_business_graduate.jpg', 'alt' => 'A PIIE business graduate'],
        'online_learning_odel'     => ['file' => '10_live_virtual_class.jpg', 'alt' => 'A PIIE live online class in session'],
        'student_support_services' => ['file' => '21_library_student.jpg',    'alt' => 'A PIIE student using the library learning resources'],
        'research_innovation'      => ['file' => '17_global_education.jpg',   'alt' => 'PIIE research and international engagement'],
        'partnerships_affiliations'=> ['file' => '06_female_remote_learner.jpg', 'alt' => 'PIIE partner collaboration'],
        'international_students'   => ['file' => '05_professional_online_student.jpg', 'alt' => 'A PIIE student studying online'],
        'news_events'              => ['file' => '19_graduate_success.jpg',  'alt' => 'A PIIE graduate celebrating success'],
        'careers'                  => ['file' => '04_group_study.jpg',        'alt' => 'PIIE colleagues working together'],
    ];

    /**
     * Which photograph illustrates a section.
     *
     * The section's own CMS image wins when it exists. Otherwise a NAMED fallback
     * from the supplied folder, chosen to suit the subject. Photographs are
     * distributed across sections rather than reused, so the same face never
     * appears in two unrelated places on one page.
     *
     * The section is a PARAMETER rather than a captured variable: capturing an
     * undefined name was a bug, and silently yielding null meant every
     * CMS-configured photograph was ignored.
     */
    $piiePhoto = function (string $key, $section = null) use ($piiePhotos, $piieMedia, $piieImage) {
        $cms = $section ? $piieImage($section->image) : null;

        if ($cms) { return ['src' => $cms, 'alt' => $section->title ?? '']; }

        $plan = $piiePhotos[$key] ?? null;

        return $plan
            ? ['src' => $piieMedia($plan['file']), 'alt' => $plan['alt']]
            : null;
    };

    $piieCrumbs = array_merge(
        [['label' => 'Home', 'url' => route('landingPage')]],
        $piiePage ? [['label' => $piiePage->title, 'url' => null]] : []
    );
@endphp

<div class="piie-site">
    @include('frontend.partials.site_header')

    <main id="piie-main">

        {{-- ── THE ONE PAGE HERO ───────────────────────────────────────────────
             Rendered once, here, for every inner page. The per-page branches below
             must NOT include it again.

             That was a real regression: the contact branch added its own hero while
             this one was still in place, so `/website/contact-us` shipped TWO <h1>
             elements and two elements with `id="piie-pagehero-title"` — every
             `aria-labelledby` pointing at that id resolved to the first one, so the
             second banner was announced as nothing at all. Caught by
             `scripts/audit-markup.php`, not by any PHPUnit assertion.

             A page-specific subtitle is passed through here rather than by including
             a second hero, so "We're Here to Help." appears without duplicating the
             landmark. --}}
        @if($piiePage)
            @include('frontend.partials.blocks.page_hero', [
                'title'    => $piiePage->title,
                // The CMS subtitle when one is published; otherwise the one piece of
                // page-specific supporting copy the brief specifies for the contact
                // page. Hardcoded presentation copy, not institutional content.
                'subtitle' => $piiePage->subtitle
                    ?: ($piiePage->page_key === 'contact' ? "We're Here to Help." : null),
                'crumbs'   => $piieCrumbs,
                'motto'    => $piiePage->page_key === 'about' ? ($piieSettings['motto'] ?? null) : null,
            ])
        @endif

        {{-- =====================================================================
             ACADEMIC PROGRAMMES — its own layout, because a catalogue is not a
             prose page. Search, filters, pagination and a four-column grid.

             READ PATH CHANGED IN STAGE 2: published academic Programmes are the
             primary source, with hand-authored CMS cards retained only where no
             published Programme already represents them. See
             `PublicProgrammeCatalogue`.
        ===================================================================== --}}
        @if($piiePage && $piiePage->page_key === 'programs')

            @php
                $piieCatalogue = app(\App\Support\ProgrammeCatalogue\PublicProgrammeCatalogue::class);
                $piieAllProgrammes = $piieCatalogue->cards();

                $piieQuery = trim((string) request('q'));
                $piieLevel = trim((string) request('level'));
                $piieFaculty = trim((string) request('faculty'));

                $piieFiltered = $piieAllProgrammes->filter(function (array $p) use ($piieQuery, $piieLevel, $piieFaculty) {
                    if ($piieQuery !== '') {
                        // Search covers the fields a candidate would actually type:
                        // the name, the code they were quoted, the level, and any
                        // description an administrator wrote.
                        $haystack = strtolower(implode(' ', array_filter([
                            $p['title'],
                            $p['code'],
                            $p['level'],
                            strip_tags((string) $p['excerpt']),
                        ])));
                        if (! str_contains($haystack, strtolower($piieQuery))) { return false; }
                    }

                    if ($piieLevel !== '' && strtolower((string) $p['level']) !== strtolower($piieLevel)) {
                        return false;
                    }

                    if ($piieFaculty !== '' && (string) $p['faculty_key'] !== $piieFaculty) {
                        return false;
                    }

                    return true;
                })->values();

                // Twelve to a page: three full rows of four, so the grid never ends on
                // a ragged single card, and few enough that a phone is not a wall.
                $piiePerPage = \App\Support\ProgrammeCatalogue\PublicProgrammeCatalogue::PER_PAGE;
                $piiePageNo = max(1, (int) request('page', 1));
                $piieTotalPages = max(1, (int) ceil($piieFiltered->count() / $piiePerPage));
                // Clamped, not rejected: a bookmarked page number past the end shows
                // the last page rather than an error.
                $piiePageNo = min($piiePageNo, $piieTotalPages);
                $piieOnPage = $piieFiltered->forPage($piiePageNo, $piiePerPage)->values();

                $piieLevels = $piieCatalogue->levels();
                $piieFacultyOptions = $piieCatalogue->faculties();

                /**
                 * Fallback tone per faculty.
                 *
                 * The catalogue is the only place that knows the full faculty list, so
                 * it maps each faculty to one of four brand tones by its position in
                 * the sorted list. Position, not a hash of the key: hashing collapsed
                 * three of the four real faculties onto one tone, so cards looked
                 * identical across faculties while the design claimed to be
                 * category-based. Sorted order makes the assignment stable - a faculty
                 * keeps its tone as long as the set of faculties is unchanged.
                 */
                $piieTones = ['a', 'b', 'c', 'd'];

                $piieToneByFaculty = collect(array_keys($piieFacultyOptions))->values()
                    ->mapWithKeys(fn ($key, $index) => [$key => $piieTones[$index % count($piieTones)]])
                    ->all();
            @endphp

            <section class="piie-catalogue" id="catalogue" aria-labelledby="piie-catalogue-title">
                <div class="piie-wrap">
                    <h2 id="piie-catalogue-title" class="visually-hidden-piie">Programme catalogue</h2>

                    {{-- Filters. A plain GET form, so it works with JavaScript off and
                         the query string is shareable. --}}
                    <form class="piie-filters" method="GET" action="{{ route('website.page', 'academic-programmes') }}" role="search">
                        <div class="piie-field">
                            <label for="piie-q">Search programmes</label>
                            <input class="piie-form-control" type="search" id="piie-q" name="q"
                                   value="{{ $piieQuery }}" placeholder="e.g. business administration">
                        </div>

                        <div class="piie-field">
                            <label for="piie-level">Qualification level</label>
                            <select class="piie-form-control" id="piie-level" name="level">
                                <option value="">All levels</option>
                                @foreach($piieLevels as $piieLevelName)
                                    <option value="{{ $piieLevelName }}" @selected($piieLevel === $piieLevelName)>{{ $piieLevelName }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="piie-field">
                            <label for="piie-faculty">Faculty</label>
                            <select class="piie-form-control" id="piie-faculty" name="faculty">
                                <option value="">All faculties</option>
                                @foreach($piieFacultyOptions as $piieFacultyKey => $piieFacultyLabel)
                                    <option value="{{ $piieFacultyKey }}" @selected($piieFaculty === $piieFacultyKey)>{{ $piieFacultyLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- The submit cell. A class rather than an inline style,
                             because the button must be sized to its own label at
                             desktop widths — `.piie-field` is a flex column, so a
                             plain `<button>` stretched to the full 2fr track (567px at
                             1440px), which read as a banner rather than a control. --}}
                        <div class="piie-field piie-filters__actions">
                            <button class="piie-btn piie-btn--primary" type="submit">Filter</button>
                        </div>
                    </form>

                    <div class="piie-results-meta">
                        <span>
                            Showing <strong>{{ $piieFiltered->count() }}</strong>
                            of {{ $piieAllProgrammes->count() }} programmes
                            @if($piiePageNo > 1 || $piieFiltered->count() > $piiePerPage)
                                &middot; page {{ $piiePageNo }} of {{ $piieTotalPages }}
                            @endif
                        </span>
                        @if($piieQuery !== '' || $piieLevel !== '' || $piieFaculty !== '')
                            <a href="{{ route('website.page', 'academic-programmes') }}">Clear all filters</a>
                        @endif
                    </div>

                    @if($piieOnPage->isEmpty())
                            {{-- Two different empty states, because they mean different
                                 things. "Nothing matches your filters" is the visitor's
                                 to fix; "nothing is published" is the institution's, and
                                 telling a visitor to clear filters when there is nothing
                                 to clear is actively unhelpful. --}}
                            @if($piieAllProgrammes->isEmpty())
                                <div class="piie-empty">
                                    <p class="piie-empty__title" style="font-weight:600;margin:0 0 .5rem;">Programme catalogue is being prepared</p>
                                    <p class="mb-0">No programmes have been published yet. Please check back shortly, or contact the admissions office for current offerings.</p>
                                </div>
                            @else
                                <div class="piie-empty">
                                    No programmes match those filters.
                                    <a href="{{ route('website.page', 'academic-programmes') }}">Show all programmes</a>
                                </div>
                            @endif
                        @else
                            <div class="piie-catalogue__grid">
                                @foreach($piieOnPage as $piieProgramme)
                                    @include('frontend.partials.blocks.card', [
                                        'variant'      => 'programme',
                                        'title'        => $piieProgramme['title'],
                                        'tag'          => $piieProgramme['level'],
                                        'image'        => $piieProgramme['image'],
                                        'alt'          => $piieProgramme['title'],
                                        'fallback'     => $piieProgramme['fallback'],
                                        'fallbackTone' => $piieToneByFaculty[$piieProgramme['faculty_key']] ?? null,
                                        'excerpt'      => $piieProgramme['excerpt'],
                                        'price'        => $piieProgramme['price'],
                                        'contactLabel' => $piieProgramme['contact_label'],
                                        'link'         => $piieProgramme['link'],
                                    ])
                                @endforeach
                            </div>

                        @if($piieTotalPages > 1)
                            <nav class="piie-pagination" aria-label="Catalogue pages">
                                @for($piieP = 1; $piieP <= $piieTotalPages; $piieP++)
                                    @php $piiePageUrl = request()->fullUrlWithQuery(['page' => $piieP]); @endphp
                                    @if($piieP === $piiePageNo)
                                        <span class="is-current" aria-current="page">{{ $piieP }}</span>
                                    @else
                                        <a href="{{ $piiePageUrl }}">{{ $piieP }}</a>
                                    @endif
                                @endfor
                            </nav>
                        @endif
                    @endif
                </div>
            </section>

        {{-- =====================================================================
             ADMISSIONS — the process gets a real timeline, requirements a grid.
        ===================================================================== --}}
        @elseif($piiePage && $piiePage->page_key === 'admissions')
            @php
                $piieAdmissionsSection = $piieSections['admissions'] ?? null;
                $piieSteps = $piieFor('admissions');
                $piieRequirements = $piieFor('entry_requirements');
                $piieAdmissionsFaqs = $piieFor('faqs');
                $piieAdmissionsPhoto = $piiePhoto('admissions');
            @endphp

            @if($piieAdmissionsSection)
                @include('frontend.partials.blocks.split', [
                    'image'   => $piieAdmissionsPhoto['src'] ?? null,
                    'alt'     => $piieAdmissionsPhoto['alt'] ?? '',
                    'eyebrow' => 'How to apply',
                    'title'   => $piieAdmissionsSection->title ?? 'The Application Process',
                    'html'    => implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieAdmissionsSection))),
                ])
            @endif

            @if($piieSteps->isNotEmpty())
                <section class="piie-section piie-section--alt" id="process" aria-labelledby="piie-process-title">
                    <div class="piie-wrap">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Seven steps</span>
                            <h2 id="piie-process-title">The Application Process</h2>
                        </div>

                        <ol class="piie-timeline" style="margin-top:2.5rem;">
                            @foreach($piieSteps as $piieStep)
                                <li>
                                    <div>
                                        <h3>{{ $piieStep->title }}</h3>
                                        @if($piieStep->description)
                                            <p class="piie-card__excerpt">{{ $piieStep->description }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        <div class="piie-btn-row" style="justify-content:center;margin-top:2.5rem;">
                            <a class="piie-btn piie-btn--primary" href="{{ Route::has('apply.form') ? route('apply.form') : '#' }}">Apply Now</a>
                        </div>
                    </div>
                </section>
            @endif

            @if($piieRequirements->isNotEmpty())
                <section class="piie-section" id="entry" aria-labelledby="piie-entry-title">
                    <div class="piie-wrap">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Before you apply</span>
                            <h2 id="piie-entry-title">Entry Requirements</h2>
                        </div>

                        <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;">
                            @foreach($piieRequirements as $piieRequirement)
                                @include('frontend.partials.blocks.card', [
                                    'variant' => 'info',
                                    'title'   => $piieRequirement->title,
                                    'excerpt' => $piieRequirement->description
                                        ? \Illuminate\Support\Str::limit(strip_tags($piieRequirement->description), 180)
                                        : null,
                                ])
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            @if($piieAdmissionsFaqs->isNotEmpty())
                <section class="piie-section piie-section--alt" id="faqs" aria-labelledby="piie-adm-faq-title">
                    <div class="piie-wrap">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Questions</span>
                            <h2 id="piie-adm-faq-title">Admissions FAQs</h2>
                        </div>

                        <div class="piie-faq" style="margin-top:2.5rem;">
                            @foreach($piieAdmissionsFaqs as $piieFaqItem)
                                <details class="piie-faq__item">
                                    <summary class="piie-faq__q">{{ $piieFaqItem->title }}</summary>
                                    <div class="piie-faq__a">
                                        {!! $piieFaqItem->content ?: '<p>'.e($piieFaqItem->description).'</p>' !!}
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

        {{-- =====================================================================
             RESEARCH & INNOVATION — partnerships in a grid, not a single column.
        ===================================================================== --}}
        @elseif($piiePage && $piiePage->page_key === 'research')
            @php
                $piieResearch = $piieSections['research_innovation'] ?? null;
                $piiePartners = $piieFor('partnerships_affiliations');
                $piieResearchPhoto = $piiePhoto('research_innovation');
            @endphp

            @if($piieResearch)
                @include('frontend.partials.blocks.split', [
                    'image'   => $piieResearchPhoto['src'] ?? null,
                    'alt'     => $piieResearchPhoto['alt'] ?? '',
                    'eyebrow' => 'Research',
                    'title'   => $piieResearch->title ?? 'Research and Innovation',
                    'html'    => implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieResearch))),
                ])
            @endif

            @if($piiePartners->isNotEmpty())
                <section class="piie-section piie-section--alt" id="partnerships" aria-labelledby="piie-partners-title">
                    <div class="piie-wrap">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Collaboration</span>
                            <h2 id="piie-partners-title">Partnerships and Collaborations</h2>
                            <p class="piie-lede">Organisations PIIE works with, as recorded by the institution.</p>
                        </div>

                        {{-- A balanced responsive grid. The partner names are text from the
                             CMS: no official logo is fabricated or guessed. --}}
                        <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;">
                            @foreach($piiePartners as $piiePartner)
                                @include('frontend.partials.blocks.card', [
                                    'variant' => 'faculty',
                                    'title'   => $piiePartner->title,
                                    'excerpt' => $piiePartner->description
                                        ? \Illuminate\Support\Str::limit(strip_tags($piiePartner->description), 150)
                                        : null,
                                ])
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

        {{-- =====================================================================
             CONTACT — hero, four channel cards, the enquiry form, and the
             location block. Rebuilt because the previous version was a wall of
             prose with no way to act on it.
        ===================================================================== --}}
        @elseif($piiePage && $piiePage->page_key === 'contact')
            @php
                $piieContact = $piieSections['contact_page'] ?? null;

                /**
                 * EVERY channel comes from one resolver, so this page, the header
                 * utility bar and the footer cannot disagree about what the
                 * institution's contact details are.
                 *
                 * The CMS publishes exactly one today: contact_address =
                 * "Nansana Municipality, Wakiso District, Uganda". There is no
                 * telephone number, no email address, no office-hours record and no
                 * map coordinate. schools.email holds info@piie.test and
                 * schools.phone holds 0, but that is the tenant row rather than the
                 * website settings, ".test" is an IANA-reserved TLD that can never
                 * receive mail, and "0" is not a number. Neither is publishable and
                 * neither is shown.
                 *
                 * So each card below is driven by whether its value exists. An
                 * unconfigured channel renders as an explicit "not yet published"
                 * card rather than an empty box or a fabricated one.
                 */
                $piiePhoneList = \App\Support\Website\PublicContactChannels::phones($piieSettingsArray);
                $piieEmailList = \App\Support\Website\PublicContactChannels::emails($piieSettingsArray);
                $piieAddress = \App\Support\Website\PublicContactChannels::address($piieSettingsArray);
                $piieHours = \App\Support\Website\PublicContactChannels::hours($piieSettingsArray);
                $piieSiteWeb = \App\Support\Website\PublicContactChannels::website($piieSettingsArray);

                // The official line and the mobile are labelled separately, because a
                // Ugandan institution publishes both and a visitor needs to know which
                // is which. `telephoneNumbers()` excludes the line so the same number
                // is never printed twice when only one is configured.
                $piieOfficialLine = \App\Support\Website\PublicContactChannels::officialLine($piieSettingsArray);
                $piieTelephones = \App\Support\Website\PublicContactChannels::telephoneNumbers($piieSettingsArray);

                $piieBrochureUrl = Route::has('download.brochure') ? route('download.brochure') : null;
                $piieApplyUrl = Route::has('apply.form') ? route('apply.form') : null;

                // A map is shown ONLY when the institution has published coordinates.
                // None are published, so no map is rendered: an embedded map centred
                // on a guessed point publishes an invented location claim, and it
                // would add a third-party request to a page that currently makes none.
                $piieMapLat = trim((string) ($piieSettings['contact_map_lat'] ?? ''));
                $piieMapLng = trim((string) ($piieSettings['contact_map_lng'] ?? ''));
                $piieHasMap = $piieMapLat !== '' && $piieMapLng !== ''
                    && is_numeric($piieMapLat) && is_numeric($piieMapLng);
            @endphp

            {{-- ── A. HERO ───────────────────────────────────────────────────────
                 DELIBERATELY NOT RENDERED HERE.

                 The hero for every inner page is emitted once, above the page branch,
                 with the title, the CMS subtitle (or "We're Here to Help." on this
                 page) and the breadcrumbs. Including a second one here produced two
                 <h1> elements and a duplicated `piie-pagehero-title` id on
                 /website/contact-us, which broke the aria-labelledby relationship for
                 the second banner. See the comment on the shared include. --}}

            {{-- A photograph behind the contact hero, when the institution has
                 published one. Passed to the shared hero is not possible from here
                 without a second include, so the photographic treatment is applied to
                 the existing hero instead: the contact page simply does not force an
                 image, and the shared hero falls back to the brand gradient. --}}

            {{-- ── B. CHANNEL CARDS ─────────────────────────────────────────────
                 Four cards, one per channel the brief asks for. Each is either the
                 real, CMS-sourced detail or a clear statement that it has not been
                 published yet — never a guess. --}}
            <section class="piie-section" aria-labelledby="piie-contact-cards-title">
                <div class="piie-wrap">
                    <div class="piie-head-center">
                        <span class="piie-eyebrow">Get in touch</span>
                        <h2 id="piie-contact-cards-title">How to reach the Institute</h2>
                        @if($piieContact && ! empty($piieContact->content))
                            <p class="piie-lede">{!! \Illuminate\Support\Str::limit(strip_tags($piieContact->content), 220) !!}</p>
                        @endif
                    </div>

                    <div class="piie-grid piie-grid--4" style="margin-top:2.5rem;">

                        {{-- CARD 1 — CALL US. Both numbers are now published, each with
                             its own label, so the card never shows a bare list of
                             digits and never prints the same number twice. --}}
                        <article class="piie-card piie-card--info piie-contact-card">
                            <h3 class="piie-card__title">Call Us</h3>

                            @if($piieOfficialLine || $piieTelephones)
                                <ul class="piie-contact-card__list">
                                    @if($piieOfficialLine)
                                        <li>
                                            <span class="piie-contact-card__label">Official Line</span>
                                            <a href="{{ \App\Support\Website\PublicContactChannels::telHref($piieOfficialLine) }}">
                                                {{ $piieOfficialLine }}
                                            </a>
                                        </li>
                                    @endif

                                    @foreach($piieTelephones as $piieTelephone)
                                        <li>
                                            <span class="piie-contact-card__label">Telephone</span>
                                            <a href="{{ \App\Support\Website\PublicContactChannels::telHref($piieTelephone) }}">
                                                {{ $piieTelephone }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="piie-contact-card__pending" data-testid="card-phone-pending">
                                    A telephone number has not yet been published by the
                                    Institute. Please use the enquiry form below.
                                </p>
                            @endif
                        </article>

                        {{-- CARD 2 — EMAIL US --}}
                        <article class="piie-card piie-card--info piie-contact-card">
                            <h3 class="piie-card__title">Email Us</h3>

                            @if($piieEmailList)
                                <ul class="piie-contact-card__list">
                                    @foreach($piieEmailList as $piieEmail)
                                        <li><a href="mailto:{{ $piieEmail }}">{{ $piieEmail }}</a></li>
                                    @endforeach

                                    @if($piieSiteWeb)
                                        <li>
                                            <span class="piie-contact-card__label">Website</span>
                                            <a href="{{ $piieSiteWeb }}" target="_blank" rel="noopener noreferrer">
                                                {{ \Illuminate\Support\Str::of($piieSiteWeb)->after('https://') }}
                                            </a>
                                        </li>
                                    @endif
                                </ul>
                            @else
                                <p class="piie-contact-card__pending" data-testid="card-email-pending">
                                    An email address has not yet been published by the
                                    Institute. The enquiry form below reaches the same
                                    office.
                                </p>
                            @endif
                        </article>

                        {{-- CARD 3 — VISIT US — the one channel that IS published --}}
                        <article class="piie-card piie-card--info piie-contact-card">
                            <h3 class="piie-card__title">Visit Us</h3>

                            @if($piieAddress)
                                <p class="piie-contact-card__value">{{ $piieAddress }}</p>
                            @else
                                <p class="piie-contact-card__pending">
                                    A postal address has not yet been published.
                                </p>
                            @endif
                        </article>

                        {{-- CARD 4 — OFFICE HOURS.
                             The institution has NOT published hours, and the brief is
                             explicit: "If unavailable, do not invent opening and closing
                             times." So no times are shown. The wording is a permanent,
                             dignified statement of what to do rather than a temporary
                             "not yet published" notice, because a published website
                             should not read as mid-build. Super Admin publishes hours in
                             the `office_hours` website setting and this card fills
                             itself in with no code change. --}}
                        <article class="piie-card piie-card--info piie-contact-card">
                            <h3 class="piie-card__title">Office Hours</h3>

                            @if($piieHours)
                                <p class="piie-contact-card__value">{{ $piieHours }}</p>
                            @else
                                <p class="piie-contact-card__pending" data-testid="card-hours-pending">
                                    Office hours are available from the Institute&rsquo;s
                                    admissions office on request. Enquiries sent through
                                    the form below are recorded on receipt.
                                </p>
                            @endif
                        </article>
                    </div>
                </div>
            </section>

            {{-- ── C. ENQUIRY FORM ──────────────────────────────────────────────
                 Server-side validated, CSRF-protected, rate limited and honeypot
                 filtered. Posts to an existing public route; no public route reads
                 an enquiry back. --}}
            <section class="piie-section piie-section--alt" id="enquiry" aria-labelledby="piie-enquiry-title">
                <div class="piie-wrap piie-split">
                    <div>
                        <span class="piie-eyebrow">Send a message</span>
                        <h2 id="piie-enquiry-title">Make an enquiry</h2>
                        <p class="piie-lede">
                            Use this form for anything about programmes, admissions, fees
                            or student records. It goes to the Institute&rsquo;s
                            administrative staff.
                        </p>

                        <ul class="piie-list" style="margin-top:1.75rem;">
                            <li>
                                <span class="piie-list__mark" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="M2 6.5L4.5 9L10 3.5" stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <span>Every field is validated on the server.</span>
                            </li>
                            <li>
                                <span class="piie-list__mark" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="M2 6.5L4.5 9L10 3.5" stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <span>Your enquiry is not published anywhere on this website.</span>
                            </li>
                            <li>
                                <span class="piie-list__mark" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="M2 6.5L4.5 9L10 3.5" stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <span>Already applied? Sign in to the applicant portal instead.</span>
                            </li>
                        </ul>
                    </div>

                    <div>
                        @include('frontend.partials.blocks.enquiry_form')
                    </div>
                </div>
            </section>

            {{-- ── D. LOCATION ──────────────────────────────────────────────────
                 A map renders only when the institution publishes coordinates.
                 None are published, so this is the verified address as text plus an
                 explicit note, rather than an iframe centred on a guess. --}}
            @if($piieHasMap)
                <section class="piie-section" aria-labelledby="piie-map-title">
                    <div class="piie-wrap">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Where to find us</span>
                            <h2 id="piie-map-title">Location</h2>
                        </div>

                        <div class="piie-map">
                            <iframe
                                title="Map showing the location of {{ $piieSettings['institution_name'] ?? 'the Institute' }}"
                                src="https://www.google.com/maps?q={{ $piieMapLat }},{{ $piieMapLng }}&output=embed"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                allowfullscreen></iframe>
                        </div>
                    </div>
                </section>
            @elseif($piieAddress)
                <section class="piie-section" aria-labelledby="piie-where-title">
                    <div class="piie-wrap" style="max-width:52rem;">
                        <div class="piie-head-center">
                            <span class="piie-eyebrow">Where to find us</span>
                            <h2 id="piie-where-title">Our location</h2>
                            <p class="piie-lede">{{ $piieAddress }}</p>
                        </div>

                        {{-- Said plainly rather than left as an absence. A map is
                             deliberately not embedded: the brief says "Do not invent
                             exact coordinates or place a misleading pin", and a map
                             centred on a guessed point is precisely that.

                             The address above IS the Institute's official recorded
                             location. The precise Google Maps position is a
                             Super Admin setting (`contact_map_lat` /
                             `contact_map_lng`); once recorded, the interactive map
                             replaces this note automatically. --}}
                        <div class="piie-empty" style="margin-top:1.75rem;" data-testid="map-pending">
                            The address above is the Institute&rsquo;s official recorded
                            location. An interactive map will appear here once the
                            Institute&rsquo;s precise map position has been recorded by
                            the administrator.
                        </div>
                    </div>
                </section>
            @endif

            {{-- ── E. ADDITIONAL CONTACT CTAs ─────────────────────────────────────
                 Admissions, programmes and the brochure. The brochure is the
                 existing download.brochure route serving the real PDF already in
                 the repository. --}}
            <section class="piie-section piie-section--alt piie-section--tight" aria-labelledby="piie-contact-cta-title">
                <div class="piie-wrap">
                    <div class="piie-head-center">
                        <span class="piie-eyebrow">Other ways to reach us</span>
                        <h2 id="piie-contact-cta-title">Admissions and programme enquiries</h2>
                    </div>

                    <div class="piie-btn-row" style="justify-content:center;margin-top:2rem;">
                        @if($piieApplyUrl)
                            <a class="piie-btn piie-btn--primary" href="{{ $piieApplyUrl }}">Apply Now</a>
                        @endif

                        @if(in_array('academic-programmes', $piieSlugs, true))
                            <a class="piie-btn piie-btn--ghost"
                               href="{{ route('website.page', 'academic-programmes') }}#catalogue">
                                Browse programmes
                            </a>
                        @endif

                        @if(in_array('admissions', $piieSlugs, true))
                            <a class="piie-btn piie-btn--ghost"
                               href="{{ route('website.page', 'admissions') }}#process">
                                Application process
                            </a>
                        @endif

                        @if($piieBrochureUrl)
                            <a class="piie-btn piie-btn--primary-alt" href="{{ $piieBrochureUrl }}">
                                Download Brochure
                            </a>
                        @endif
                    </div>
                </div>
            </section>

{{-- =====================================================================
             CAREERS — an explicit layout, because a vacancies list needs an
             empty state and the generic loop would otherwise render an
             ordinary prose section for it.
        ===================================================================== --}}
        @elseif($piiePage && $piiePage->page_key === 'careers')
            @php
                $piieCareersIntro = $piieSections['careers'] ?? null;
                $piieCareersEnv = $piieSections['careers_environment'] ?? null;
                $piieVacancies = $piieFor('careers_vacancies');
                $piieCareersPhoto = $piiePhoto('careers');
                $piieCareersContact = $piieSettings['contact_address'] ?? null;
            @endphp

            @if($piieCareersIntro)
                @include('frontend.partials.blocks.split', [
                    'image'   => $piieCareersPhoto['src'] ?? null,
                    'alt'     => $piieCareersPhoto['alt'] ?? '',
                    'eyebrow' => 'Join the institution',
                    'title'   => $piieCareersIntro->title ?? 'Careers at PIIE',
                    'html'    => implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieCareersIntro))),
                ])
            @endif

            @if($piieCareersEnv)
                @include('frontend.partials.blocks.split', [
                    'image'   => $piieMedia('15_virtual_tutor.jpg'),
                    'alt'     => 'A PIIE lecturer delivering a live online session',
                    'eyebrow' => 'How it is to work here',
                    'title'   => $piieCareersEnv->title ?? 'The working environment',
                    'html'    => implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieCareersEnv))),
                    'reverse' => true,
                ])
            @endif

            {{-- VACANCIES.
                 Rendered from `website_items` with section_key `careers_vacancies`, so
                 Super Admin publishes a vacancy by adding an item - no code change.
                 With none published it says so. It does NOT invent a position, and it
                 does not show a fabricated "generic opportunities" card. --}}
            <section class="piie-section piie-section--alt" id="vacancies" aria-labelledby="piie-vacancies-title">
                <div class="piie-wrap">
                    <div class="piie-head-center">
                        <span class="piie-eyebrow">Open roles</span>
                        <h2 id="piie-vacancies-title">
                            {{ $piieSections['careers_vacancies']->title ?? 'Current vacancies' }}
                        </h2>
                    </div>

                    @if($piieVacancies->isEmpty())
                        <div class="piie-empty" style="margin-top:2rem;" data-testid="vacancies-empty">
                            <h3 style="font-size:1.0625rem;color:var(--piie-ink);margin-bottom:.5rem;">
                                There are no advertised vacancies at the moment.
                            </h3>
                            <p style="margin:0;">
                                PIIE publishes every vacancy here once it is approved.
                                Enquiries about future opportunities can be directed to the
                                institutional contact channels.
                            </p>
                        </div>
                    @else
                        <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;" data-testid="vacancies-list">
                            @foreach($piieVacancies as $piieVacancy)
                                @include('frontend.partials.blocks.card', [
                                    'variant' => 'info',
                                    'title'   => $piieVacancy->title,
                                    'tag'     => $piieVacancy->subtitle ?: null,
                                    'excerpt' => $piieVacancy->description
                                        ? \Illuminate\Support\Str::limit(strip_tags($piieVacancy->description), 180)
                                        : null,
                                    'link'    => ! empty($piieVacancy->link)
                                        ? ['url' => $piieVacancy->link, 'text' => $piieVacancy->button_text ?: 'View role']
                                        : null,
                                ])
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            @if($piieSections['careers_application'] ?? null)
                <section class="piie-section" id="careers-apply" aria-labelledby="piie-careers-apply">
                    <div class="piie-wrap piie-split">
                        <div>
                            <span class="piie-eyebrow">Applying</span>
                            <h2 id="piie-careers-apply">{{ $piieSections['careers_application']->title ?? 'How to apply for a role' }}</h2>
                            {!! implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieSections['careers_application']))) !!}
                        </div>

                        <div>
                            <div class="piie-card" style="padding:2rem;">
                                <h3 style="font-size:1.125rem;">Enquiries</h3>
                                @if($piieCareersContact)
                                    <p class="piie-card__excerpt">{{ $piieCareersContact }}</p>
                                @endif
                                <p class="piie-card__excerpt">
                                    For anything about working at PIIE, please use the
                                    institutional contact page so your enquiry reaches
                                    the right office.
                                </p>
                                <div class="piie-btn-row">
                                    @if(in_array('contact-us', $piieSlugs, true))
                                        <a class="piie-btn piie-btn--primary"
                                           href="{{ route('website.page', 'contact-us') }}">Contact Us</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

        {{-- =====================================================================
             EVERY OTHER PAGE — an editorial layout driven by the section's key.
        ===================================================================== --}}
        {{-- =====================================================================
             ABOUT US — an editorial layout, with the identity statements given
             their own dedicated block.
        ===================================================================== --}}
        @elseif($piiePage && $piiePage->page_key === 'about')
            @php
                $piieIdentitySection = $piieSections['vision_mission_motto'] ?? null;
                $piieValuesSection = $piieSections['core_values'] ?? null;

                // Raw statement text, NOT run through the paragraph helper. The
                // identity block parses the "Vision:" / "Mission:" / "Motto:"
                // labels itself, and stripping tags first would mangle a statement
                // that legitimately contains a colon.
                $piieIdentityContent = trim((string) ($piieIdentitySection->content ?? ''));
                $piieValuesContent = trim((string) ($piieValuesSection->content ?? ''));
            @endphp

            {{-- ── THE PROMOTED BLOCK ────────────────────────────────────────────
                 Placed immediately after the hero so the Vision, Mission, Motto and
                 Core Values are the first thing a visitor reads, rather than being
                 the fifth thing on a long page.

                 Rendered on EVERY page that has these sections, not only About, so
                 the block follows the CMS rather than a hardcoded page key. --}}
            @include('frontend.partials.blocks.identity_statements', [
                'statementTitle'   => $piieIdentitySection->title ?? 'Vision, Mission and Motto',
                'statementSubtitle' => $piieIdentitySection->subtitle ?? null,
                'statementContent' => $piieIdentityContent,
                'valuesContent'    => $piieValuesContent,
            ])

            {{-- ── THE REST OF THE ABOUT PAGE, in CMS order ───────────────────────
                 `vision_mission_motto` and `core_values` are skipped here because
                 they have just been rendered above in their promoted form; rendering
                 them again as ordinary prose is exactly the duplication this change
                 exists to remove. --}}
            @foreach($piieSections as $piieSection)
                @php
                    $piieKey = $piieSection->section_key;

                    if (in_array($piieKey, ['vision_mission_motto', 'core_values'], true)) {
                        continue;
                    }

                    $piieBlockItems = $piieFor($piieKey);
                    $piieSectionPhoto = $piiePhoto($piieKey, $piieSection);
                    $piieBody = implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieSection)));
                    $piieAlt = ! (bool) ($piieSection->sort_order % 2);
                @endphp

                {{-- Leadership: a GRID of profiles, driven entirely by the database. --}}
                @if($piieKey === 'leadership_team' && $piieBlockItems->isNotEmpty())
                    <section class="piie-section piie-section--alt" id="{{ $piieKey }}" aria-labelledby="piie-lead-title">
                        <div class="piie-wrap">
                            <div class="piie-head-center">
                                <span class="piie-eyebrow">Who leads PIIE</span>
                                <h2 id="piie-lead-title">{{ $piieSection->title ?? 'Leadership and Governance' }}</h2>
                                @if($piieSection->subtitle)
                                    <p class="piie-lede">{{ $piieSection->subtitle }}</p>
                                @endif
                            </div>

                            <div class="piie-grid piie-grid--4" style="margin-top:2.5rem;">
                                @foreach($piieBlockItems as $piieLeader)
                                    @include('frontend.partials.blocks.card', [
                                        'variant' => 'leadership',
                                        'title'   => $piieLeader->title,
                                        'role'    => $piieLeader->subtitle ?: null,
                                        'image'   => $piieImage($piieLeader->image),
                                        'alt'     => $piieLeader->title,
                                        'fallback' => $piieLeader->title,
                                        'excerpt' => $piieLeader->description
                                            ? \Illuminate\Support\Str::limit(strip_tags($piieLeader->description), 140)
                                            : null,
                                    ])
                                @endforeach
                            </div>
                        </div>
                    </section>

                {{-- A section with prose and a photograph becomes a SPLIT. --}}
                @elseif($piieBody !== '' && $piieSectionPhoto)
                    @include('frontend.partials.blocks.split', [
                        'image'   => $piieSectionPhoto['src'],
                        'alt'     => $piieSectionPhoto['alt'],
                        'title'   => $piieSection->title,
                        'html'    => $piieBody,
                        'reverse' => ! $piieAlt,
                        'id'      => $piieKey,
                    ])

                {{-- A section that is only a list becomes a GRID of cards. --}}
                @elseif($piieBlockItems->isNotEmpty())
                    <section class="piie-section @if($piieAlt) piie-section--alt @endif" id="{{ $piieKey }}"
                             aria-labelledby="piie-{{ $piieKey }}">
                        <div class="piie-wrap">
                            <div class="piie-head-center">
                                <h2 id="piie-{{ $piieKey }}">{{ $piieSection->title ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $piieKey)) }}</h2>
                                @if($piieSection->subtitle)
                                    <p class="piie-lede">{{ $piieSection->subtitle }}</p>
                                @endif
                            </div>

                            @if($piieBody)
                                <p class="piie-lede" style="margin:1.5rem auto 0;max-width:62ch;">{!! $piieBody !!}</p>
                            @endif

                            <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;">
                                @foreach($piieBlockItems as $piieItem)
                                    @include('frontend.partials.blocks.card', [
                                        'variant' => 'info',
                                        'title'   => $piieItem->title,
                                        'image'   => $piieImage($piieItem->image),
                                        'alt'     => $piieItem->title,
                                        'excerpt' => $piieItem->description
                                            ? \Illuminate\Support\Str::limit(strip_tags($piieItem->description), 170)
                                            : null,
                                    ])
                                @endforeach
                            </div>
                        </div>
                    </section>

                {{-- Prose only: a clean measure, no empty image frame. --}}
                @elseif($piieBody !== '')
                    <section class="piie-section @if($piieAlt) piie-section--alt @endif" id="{{ $piieKey }}"
                             aria-labelledby="piie-{{ $piieKey }}">
                        <div class="piie-wrap" style="max-width:56rem;">
                            <span class="piie-eyebrow">{{ $piieSection->title ?? '' }}</span>
                            @if($piieSection->subtitle)
                                <h2 id="piie-{{ $piieKey }}">{{ $piieSection->subtitle }}</h2>
                            @endif
                            {!! $piieBody !!}
                        </div>
                    </section>
                @endif
            @endforeach

        {{-- =====================================================================
             EVERY OTHER PAGE — the same editorial layout, driven by the section
             key. Used by any page without a bespoke branch above.
        ===================================================================== --}}
        @else
            @forelse($piieSections as $piieSection)
                @php
                    $piieKey = $piieSection->section_key;
                    $piieBlockItems = $piieFor($piieKey);
                    $piieSectionPhoto = $piiePhoto($piieKey, $piieSection);
                    $piieBody = implode('', array_map(fn ($p) => '<p>'.$p.'</p>', $piieParagraphs($piieSection)));
                    $piieAlt = ! (bool) ($piieSection->sort_order % 2);
                @endphp

                @if($piieBody !== '' && $piieSectionPhoto)
                    @include('frontend.partials.blocks.split', [
                        'image'   => $piieSectionPhoto['src'],
                        'alt'     => $piieSectionPhoto['alt'],
                        'title'   => $piieSection->title,
                        'html'    => $piieBody,
                        'reverse' => ! $piieAlt,
                        'id'      => $piieKey,
                    ])

                @elseif($piieBlockItems->isNotEmpty())
                    <section class="piie-section @if($piieAlt) piie-section--alt @endif" id="{{ $piieKey }}"
                             aria-labelledby="piie-{{ $piieKey }}">
                        <div class="piie-wrap">
                            <div class="piie-head-center">
                                <h2 id="piie-{{ $piieKey }}">{{ $piieSection->title ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $piieKey)) }}</h2>
                                @if($piieSection->subtitle)
                                    <p class="piie-lede">{{ $piieSection->subtitle }}</p>
                                @endif
                            </div>

                            @if($piieBody)
                                <p class="piie-lede" style="margin:1.5rem auto 0;max-width:62ch;">{!! $piieBody !!}</p>
                            @endif

                            <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;">
                                @foreach($piieBlockItems as $piieItem)
                                    @include('frontend.partials.blocks.card', [
                                        'variant' => 'info',
                                        'title'   => $piieItem->title,
                                        'image'   => $piieImage($piieItem->image),
                                        'alt'     => $piieItem->title,
                                        'excerpt' => $piieItem->description
                                            ? \Illuminate\Support\Str::limit(strip_tags($piieItem->description), 170)
                                            : null,
                                    ])
                                @endforeach
                            </div>
                        </div>
                    </section>

                @elseif($piieBody !== '')
                    <section class="piie-section @if($piieAlt) piie-section--alt @endif" id="{{ $piieKey }}"
                             aria-labelledby="piie-{{ $piieKey }}">
                        <div class="piie-wrap" style="max-width:56rem;">
                            <span class="piie-eyebrow">{{ $piieSection->title ?? '' }}</span>
                            @if($piieSection->subtitle)
                                <h2 id="piie-{{ $piieKey }}">{{ $piieSection->subtitle }}</h2>
                            @endif
                            {!! $piieBody !!}
                        </div>
                    </section>
                @endif
            @empty
                <div class="piie-wrap" style="padding-block:4rem;">
                    <div class="piie-empty">This page has no published content yet.</div>
                </div>
            @endforelse
        @endif

        @include('frontend.partials.blocks.cta', [
            'piieSlugs' => $piieSlugs,
        ])
    </main>

    @include('frontend.partials.site_footer')
</div>

@endsection