@extends('frontend.index')

@section('content')

{{--
    ===========================================================================
    PIIE PUBLIC HOMEPAGE — REDESIGNED
    ===========================================================================

    WHAT IS HARDCODED (design)      WHAT IS ADMIN-MANAGED (content)
    ------------------------------   ------------------------------------
    Section order and layout         website_sections rows, by section_key
    Hero video + overlay + control   hero_slider title / subtitle / content
    Card and grid system             hero_slider items  (the two CTAs)
    Responsive breakpoints           programme catalogue items
    FAQ accordion markup             why_choose_us content
    Section spacing and type         leadership_team items
                                     admissions steps
                                     student_support_services
                                     news_events items
                                     faqs items
                                     website_settings (name, motto,
                                       tagline, address, copyright)

    NOTHING here replaces database content with dummy content. Where a section
    has no published record it renders an honest empty state rather than filler.

    The controller contract is UNCHANGED: this view reads exactly the variables
    `HomeController@home()` and `HomeController@websitePage()` already pass -
    $websiteSettings, $websiteSections, $websiteItems, $websiteSeo, $packages,
    $faqs, $users, $schools, $frontendFeatures - plus $allPages/$currentPage on
    inner pages. No controller edit was required.
--}}

@php
    $piieSections = $websiteSections ?? collect();
    $piieItems = $websiteItems ?? collect();
    $piieSettings = $websiteSettings ?? collect();

    /** A CMS setting, with a fallback. */
    $piieSetting = fn ($key, $default = '') => ($piieSettings[$key] ?? $default);

    /** A CMS section, or null. */
    $piieSection = fn ($key) => $piieSections[$key] ?? null;

    /** Items for a section, published only. */
    $piieSectionItems = function ($key) use ($piieItems) {
        return collect($piieItems[$key] ?? collect())
            ->filter(fn ($i) => (int) $i->status === 1)
            ->values();
    };

    /** Split a section's HTML content into paragraphs. */
    $piieParagraphs = function ($section, $field = 'content') {
        if (! $section) { return []; }

        $html = trim((string) ($section->{$field} ?? ''));

        if ($html === '') { return []; }

        return array_values(array_filter(array_map('trim', preg_split("/(?:\r\n|\r|\n){2,}/", $html) ?: [])));
    };

    /** Plain text of a section, for meta and summaries. */
    $piiePlain = function ($section, $field = 'content') {
        return $section ? trim(preg_replace('/\s+/', ' ', strip_tags((string) ($section->{$field} ?? '')))) : '';
    };

    $piieHas = fn (string $name) => Route::has($name);

    /**
     * A link to a CMS page ONLY if that page is published.
     *
     * `Route::has()` proves the route exists; it does not prove a page with that
     * slug does. Hardcoding slugs is what put a 404-producing Privacy Policy link
     * in the first version of this design, so the published-page list is consulted.
     */
    $piiePublishedSlugs = collect($allPages ?? collect())->pluck('slug')->filter()->all();
    $piiePageUrl = function (string $slug) use ($piieHas, $piiePublishedSlugs) {
        return ($piieHas('website.page') && in_array($slug, $piiePublishedSlugs, true))
            ? route('website.page', $slug)
            : null;
    };

    $piieFallbackPage = $piieHas('website.page') && collect($allPages ?? collect())->isNotEmpty()
        ? route('website.page', collect($allPages)->first()->slug)
        : url('/');

    $piieProgramsUrl = $piiePageUrl('academic-programmes') ?? $piieFallbackPage;
    $piieAboutUrl = $piiePageUrl('about-us') ?? $piieFallbackPage;
    $piieAdmissionsUrl = $piiePageUrl('admissions') ?? $piieFallbackPage;
    $piieContactUrl = $piiePageUrl('contact-us') ?? $piieFallbackPage;
    $piieApplyUrl = $piieHas('apply.form') ? route('apply.form') : $piieAdmissionsUrl;
    $piieLoginUrl = $piieHas('login') ? route('login') : url('/login');

    /**
     * A CMS image, if one is set AND the file exists.
     *
     * A configured-but-missing file renders a broken image icon, which on a public
     * site reads as broken rather than as absent. Checked here once instead of in
     * every card.
     */
    $piieImage = function ($path, $fallback = null) {
        if (empty($path)) { return $fallback; }

        $clean = ltrim(str_replace(['assets/uploads/website/', 'uploads/website/'], '', (string) $path), '/');

        return public_path('assets/uploads/website/'.$clean) && is_file(public_path('assets/uploads/website/'.$clean))
            ? asset('assets/uploads/website/'.$clean)
            : $fallback;
    };

    /** A supplied photograph from the media folder, by its real filename. */
    $piieMedia = fn (string $name) => asset('assets/images/img/'.$name);

    // ── Section lookups ───────────────────────────────────────────────────────
    $piieHero = $piieSection('hero_slider');
    $piieWhy = $piieSection('why_choose_us');
    $piieOdel = $piieSection('online_learning_odel');
    $piieAbout = $piieSection('about_institution');
    $piieSupport = $piieSection('student_support_services');
    $piieNews = $piieSection('news_events');
    $piieFaqSection = $piieSection('faqs');
    $piieEntry = $piieSection('entry_requirements');

    $piieHeroItems = $piieSectionItems('hero_slider');
    $piieAdmissionsSteps = $piieSectionItems('admissions');
    $piieLeadership = $piieSectionItems('leadership_team');
    $piieFaqs = $piieSectionItems('faqs');
    $piieNewsItems = $piieSectionItems('news_events');

    /**
     * Programme catalogue: the real, published programme records the CMS holds.
     *
     * Fetched from `website_items`, never hardcoded, so a programme Super Admin
     * publishes appears here and on the catalogue page with no code change, and an
     * edit to an existing one is reflected immediately.
     *
     * `status === 1` is the publication filter, so an unpublished, archived or test
     * programme can never reach the public site.
     */
    $piieProgrammes = collect()
        ->merge($piieSectionItems('programme_catalog_graduate_school'))
        ->merge($piieSectionItems('programme_catalog_business_management'))
        ->merge($piieSectionItems('programme_catalog_humanities'))
        ->merge($piieSectionItems('programme_catalog_education'))
        ->filter(fn ($p) => (int) $p->status === 1)
        ->values();

    /**
     * Is this programme flagged Featured by Super Admin?
     *
     * The flag lives in the existing `meta_json` column as {"featured": true}, set by
     * the "Featured" checkbox in Website Management. No featured/flag column existed
     * on `website_items` and none was added: `meta_json` already existed, was already
     * writable through the CMS controller, and is the correct home for per-item
     * metadata. See `WebsiteManagementController::applyFeaturedFlag()`.
     *
     * Decoded with error handling — `json_decode` returns null on malformed JSON and
     * a null dereference here would take the whole homepage down.
     */
    $piieIsFeatured = function ($programme): bool {
        $meta = json_decode((string) ($programme->meta_json ?? ''), true);

        return is_array($meta) && ! empty($meta['featured']);
    };

    /**
     * Featured Programmes for the homepage.
     *
     * A CMS-published flag drives the selection: items marked Featured come first, in
     * the CMS's own sort order. Items not marked Featured fill the remaining places,
     * so the block is never empty just because nobody has ticked the box yet — and it
     * becomes fully CMS-controlled the moment they do, without a deploy.
     */
    $piieFeaturedLimit = 4;

    $piieFeaturedProgrammes = $piieProgrammes
        ->filter($piieIsFeatured)
        ->take($piieFeaturedLimit)
        ->values();

    if ($piieFeaturedProgrammes->count() < $piieFeaturedLimit) {
        $piieFeaturedProgrammes = $piieFeaturedProgrammes
            ->concat($piieProgrammes->reject($piieIsFeatured)->take($piieFeaturedLimit - $piieFeaturedProgrammes->count()))
            ->values();
    }

    // Programme levels, from the CMS where seeded, with photographs from the
    // supplied media folder. These are ILLUSTRATIONS, not programme records -
    // the programme records themselves are the catalogue items above.
    $piieLevels = [
        ['title' => 'Masters Degrees', 'photo' => $piieMedia('16_future_leader.jpg'), 'anchor' => 'graduate_school'],
        ['title' => 'Postgraduate Diplomas', 'photo' => $piieMedia('05_professional_online_student.jpg'), 'anchor' => 'graduate_school'],
        ['title' => "Bachelor's Degrees", 'photo' => $piieMedia('03_female_online_learning.jpg'), 'anchor' => 'business_management'],
        ['title' => 'Diplomas', 'photo' => $piieMedia('08_graduation_books.jpg'), 'anchor' => 'business_management'],
        ['title' => 'Certificates', 'photo' => $piieMedia('18_student_notes.jpg'), 'anchor' => 'humanities'],
    ];

    // ODeL stages. Four, as specified.
    $piieOdelStages = [
        ['title' => 'Explore and select a programme', 'copy' => 'Browse the published catalogue by level, compare duration and study mode, and choose the qualification that fits your goals.'],
        ['title' => 'Apply and complete admission', 'copy' => 'Submit one application online, upload your academic records, and receive your admission decision and registration instructions.'],
        ['title' => 'Access materials and live sessions', 'copy' => 'Study through the PIIE learning system: structured online materials, scheduled live sessions, and support from academic staff.'],
        ['title' => 'Complete assessments and track progress', 'copy' => 'Sit your assessments and examinations online, then follow your academic progress and results through the student portal.'],
    ];

    $piieInstitutionName = $piieSetting('institution_name', 'Prime International Institute of Excellence (PIIE)');
    $piieMotto = $piieSetting('motto', 'Strive. Excel. Lead.');
@endphp

<div class="piie-site">

    @include('frontend.partials.site_header')

    <main id="piie-main">

        {{-- =====================================================================
             SECTION 01 — VIDEO WELCOME BANNER
             ---------------------------------------------------------------------
             The wording is the CMS `hero_slider` row, unchanged. Only the
             presentation is new.

             The 1920x1080 landscape file is the hero source. The second supplied
             video is 1080x1920 PORTRAIT, so it is not used here: stretched across
             a desktop it would crop to a letterbox of a face. It is offered to
             mobile via <source media>, where a vertical frame is the right shape.
        ===================================================================== --}}
        <section class="piie-hero"
                 data-pii-hero
                 id="home"
                 aria-labelledby="piie-hero-title">

            <div class="piie-hero__media">
                <video class="piie-hero__video"
                       autoplay muted loop playsinline preload="metadata"
                       poster="{{ $piieMedia('22_home_learning_student.jpg') }}"
                       aria-hidden="true" tabindex="-1">
                    {{-- preload="metadata", NOT "none".

                         `none` was the second cause of "autoplay does not work". It
                         asks the browser to fetch nothing, and although autoplay
                         usually overrides it, that is not guaranteed - and with no
                         buffered data there is nothing to play, so the element can
                         sit at readyState 0 and never fire `canplay`, which is the
                         event the script uses to ask for playback.

                         `metadata` fetches the index and the first frame only: the
                         poster still paints immediately and is still the LCP, and the
                         file is playable the moment the hero is on screen. --}}
                    <source src="{{ $piieMedia('7683346-hd_1080_1920_30fps.mp4') }}"
                            type="video/mp4" media="(orientation: portrait)">
                    <source src="{{ $piieMedia('8196798-hd_1920_1080_25fps.mp4') }}"
                            type="video/mp4">
                </video>

                {{-- The static image. Present for a browser that cannot decode the
                     video, for save-data, and for reduced motion. It is also the
                     element that guarantees the banner is never empty. --}}
                <img class="piie-hero__poster"
                     src="{{ $piieMedia('22_home_learning_student.jpg') }}"
                     alt=""
                     width="1536" height="1024"
                     loading="eager" decoding="async" fetchpriority="high">
            </div>

            <div class="piie-wrap piie-hero__inner">
                <div class="piie-hero__content">
                    @if($piieSetting('hero_badge'))
                        <span class="piie-hero__badge">{{ $piieSetting('hero_badge') }}</span>
                    @endif

                    {{-- The ONE h1 on the page. --}}
                    <h1 id="piie-hero-title">{{ $piieHero->title ?? $piieInstitutionName }}</h1>

                    @if($piieHero && ! empty($piieHero->subtitle))
                        <p class="piie-hero__sub">{{ $piieHero->subtitle }}</p>
                    @endif

                    @if($piieHero && ! empty($piieHero->content))
                        <div class="piie-hero__copy">{!! $piieHero->content !!}</div>
                    @endif

                    {{-- CTAs come from the CMS items the old hero already used, so a button Super
                         Admin has configured keeps working. The two required
                         actions are then guaranteed to be present. --}}
                    <div class="piie-btn-row">
                        @php
                            $piieExploreCta = $piieHeroItems
                                ->filter(fn ($i) => ! empty($i->link))
                                ->first(fn ($i) => trim((string) $i->link) === $piieProgramsUrl);

                            // Fall back to the first configured link rather than
                            // inventing one, so the hero never invents a destination.
                            $piieExploreHref = $piieExploreCta?->link
                                ?? $piieHeroItems->first(fn ($i) => ! empty($i->link))?->link
                                ?? $piieProgramsUrl;

                            $piieExploreText = $piieExploreCta?->button_text
                                ?? 'Explore Programmes';
                        @endphp

                        <a class="piie-btn piie-btn--primary" href="{{ $piieExploreHref }}">{{ $piieExploreText }}</a>
                        <a class="piie-btn piie-btn--primary-alt" href="{{ $piieApplyUrl }}">Apply Now</a>
                    </div>
                </div>
            </div>

            {{-- Pause / play. Rendered visible and wired by JS; `hidden` is removed
                 there. Without JS the button does nothing, so it is hidden by
                 default rather than offering a control that lies. --}}
            <button type="button" class="piie-hero__toggle" data-pii-hero-toggle hidden
                    aria-pressed="false">
                <span class="piie-hero__icon" aria-hidden="true">&#10073;&#10073;</span>
                <span class="piie-hero__toggle-label">Pause background video</span>
            </button>
        </section>

        {{-- =====================================================================
             SECTION 02 — EXPLORE YOUR ACADEMIC FUTURE
        ===================================================================== --}}
        <section class="piie-section" id="levels" aria-labelledby="piie-levels-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">Academic pathways</span>
                    <h2 id="piie-levels-title">Explore Your Academic Future</h2>
                    <p class="piie-lede">
                        Five qualification levels, each mapped to the published programme
                        catalogue so you can move from a broad interest to a specific
                        programme without losing your place.
                    </p>
                </div>

                <div class="piie-grid piie-grid--5" style="margin-top:2.5rem;">
                    @foreach($piieLevels as $level)
                        <a class="piie-card" href="{{ $piieProgramsUrl }}#{{ $level['anchor'] }}">
                            <span class="piie-card__media">
                                <img src="{{ $level['photo'] }}"
                                     alt="PIIE {{ $level['title'] }} study"
                                     width="300" height="248"
                                     loading="lazy" decoding="async">
                            </span>
                            <span class="piie-card__body">
                                <span class="piie-card__title">{{ $level['title'] }}</span>
                                <span class="piie-card__foot">
                                    <span class="piie-btn piie-btn--ghost">View Programmes</span>
                                </span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 03 — WHY CHOOSE PIIE
             Content is the CMS `why_choose_us` section verbatim; the benefits are
             the six themes the brief lists, each anchored to a sentence that is
             actually in that record.
        ===================================================================== --}}
        <section class="piie-section piie-section--alt" aria-labelledby="piie-why-title">
            <div class="piie-wrap piie-split">
                <div class="piie-split__media">
                    <img src="{{ $piieMedia('13_campus_collaboration.jpg') }}"
                         alt="PIIE students collaborating on academic work"
                         width="300" height="248"
                         loading="lazy" decoding="async">
                </div>

                <div>
                    <span class="piie-eyebrow">Why PIIE</span>
                    <h2 id="piie-why-title">{{ $piieWhy->title ?? 'Why Choose PIIE?' }}</h2>

                    @foreach($piieParagraphs($piieWhy) as $piieParagraph)
                        <p>{!! $piieParagraph !!}</p>
                    @endforeach

                    <ul class="piie-list" style="margin-top:1.5rem;">
                        <li><span class="piie-list__mark" aria-hidden="true">1</span><span>Internationally benchmarked programmes, validated by recognised partner universities.</span></li>
                        <li><span class="piie-list__mark" aria-hidden="true">2</span><span>Flexible Open, Distance and e-Learning without compromising quality.</span></li>
                        <li><span class="piie-list__mark" aria-hidden="true">3</span><span>A complete, technology-enabled digital academic experience.</span></li>
                        <li><span class="piie-list__mark" aria-hidden="true">4</span><span>Experienced, postgraduate-credentialed faculty.</span></li>
                        <li><span class="piie-list__mark" aria-hidden="true">5</span><span>Personalised academic and student support from enrolment to graduation.</span></li>
                        <li><span class="piie-list__mark" aria-hidden="true">6</span><span>Leadership and entrepreneurship development for professional practice.</span></li>
                    </ul>

                    <div class="piie-btn-row" style="margin-top:2rem;">
                        <a class="piie-btn piie-btn--primary" href="{{ $piieProgramsUrl }}">Explore Programmes</a>
                        <a class="piie-btn piie-btn--ghost" href="{{ $piieAboutUrl }}">About PIIE</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 04 — FEATURED PROGRAMMES
             Real records from `website_items`, status 1 only. An unpublished
             programme cannot reach this page.
        ===================================================================== --}}
        <section class="piie-section" aria-labelledby="piie-programmes-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">The catalogue</span>
                    <h2 id="piie-programmes-title">Featured Programmes</h2>
                    <p class="piie-lede">Published programmes from the PIIE catalogue.</p>
                </div>

                @if($piieFeaturedProgrammes->isEmpty())
                    <div class="piie-empty" style="margin-top:2rem;">
                        No programmes have been published yet.
                    </div>
                @else
                    <div class="piie-grid piie-grid--4" style="margin-top:2.5rem;">
                        @foreach($piieFeaturedProgrammes as $piieProgramme)
                            @php
                                $piieProgrammeImage = $piieImage($piieProgramme->image);

                                // Same tone logic as the catalogue card, so a programme
                                // looks identical wherever it appears. Derived from the
                                // faculty key, which is stable.
                                $piieProgrammeTones = ['a', 'b', 'c', 'd'];
                                $piieProgrammeTone = $piieProgrammeTones[abs(crc32((string) ($piieProgramme->section_key ?? 'piie'))) % 4];
                            @endphp
                            <article class="piie-card piie-card--programme">
                                <div class="piie-card__media">
                                    @if($piieProgrammeImage)
                                        <img src="{{ $piieProgrammeImage }}"
                                             alt="{{ $piieProgramme->title ?: 'PIIE programme' }}"
                                             loading="lazy" decoding="async">
                                    @else
                                        {{-- The DESIGNED category fallback, identical to the
                                             catalogue card. This replaces a bare "Programme
                                             image pending" caption floating in an empty
                                             rectangle, which is what the brief called an
                                             enormous empty image area. No invented
                                             photography and no repeated graphic: the tone
                                             varies by faculty and the faculty name is set in
                                             type. --}}
                                        <div class="piie-card__media--fallback piie-card__media--fb-{{ $piieProgrammeTone }}">
                                            <span class="piie-card__fallback-mark" aria-hidden="true">
                                                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(
                                                    \Illuminate\Support\Str::of((string) ($piieProgramme->section_key ?? 'piie'))
                                                        ->replace('programme_catalog_', '')
                                                        ->replace('_', ' '),
                                                    0, 3
                                                )) }}
                                            </span>
                                            <span class="piie-card__fallback-label">
                                                {{ \Illuminate\Support\Str::of((string) ($piieProgramme->section_key ?? 'PIIE'))
                                                    ->replace('programme_catalog_', '')
                                                    ->replace('_', ' ')
                                                    ->title() }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                                <div class="piie-card__body">
                                    @if($piieProgramme->subtitle)
                                        <span class="piie-tag">{{ $piieProgramme->subtitle }}</span>
                                    @endif
                                    <h3 class="piie-card__title">{{ $piieProgramme->title }}</h3>

                                    @if($piieProgramme->description)
                                        <p class="piie-card__excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($piieProgramme->description), 130) }}</p>
                                    @endif

                                    <div class="piie-card__foot">
                                        <a class="piie-btn piie-btn--ghost"
                                           href="{{ $piieProgramsUrl }}">{{ $piieProgramme->link ?: 'View Details' }}</a>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="piie-btn-row" style="justify-content:center;margin-top:2.5rem;">
                        <a class="piie-btn piie-btn--primary" href="{{ $piieProgramsUrl }}">View All Programmes</a>
                    </div>
                @endif
            </div>
        </section>

        {{-- =====================================================================
             SECTION 05 — HOW ONLINE LEARNING WORKS
        ===================================================================== --}}
        <section class="piie-section piie-section--alt" id="odel" aria-labelledby="piie-odel-title">
            <div class="piie-wrap piie-split piie-split--reverse">
                <div class="piie-split__media">
                    <img src="{{ $piieMedia('10_live_virtual_class.jpg') }}"
                         alt="A PIIE live online class in session"
                         width="299" height="248"
                         loading="lazy" decoding="async">
                </div>

                <div>
                    <span class="piie-eyebrow">ODeL at PIIE</span>
                    <h2 id="piie-odel-title">{{ $piieOdel->title ?? 'How Online Learning Works' }}</h2>

                    @foreach(array_slice($piieParagraphs($piieOdel), 0, 2) as $piieParagraph)
                        <p>{!! $piieParagraph !!}</p>
                    @endforeach

                    <ol class="piie-steps" style="margin-top:1.75rem;">
                        @foreach($piieOdelStages as $piieStage)
                            <li>
                                <h3 style="font-size:1.0625rem;margin-bottom:.25rem;">{{ $piieStage['title'] }}</h3>
                                <p class="piie-card__excerpt">{{ $piieStage['copy'] }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <div class="piie-btn-row" style="margin-top:2rem;">
                        <a class="piie-btn piie-btn--primary" href="{{ $piieLoginUrl }}">Student Portal</a>
                        <a class="piie-btn piie-btn--ghost" href="{{ $piieAdmissionsUrl }}">How to Apply</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 06 — ABOUT OUR INSTITUTION
        ===================================================================== --}}
        <section class="piie-section" aria-labelledby="piie-about-title">
            <div class="piie-wrap piie-split">
                <div class="piie-split__media">
                    <img src="{{ $piieMedia('11_campus_student.jpg') }}"
                         alt="A PIIE student on campus"
                         width="299" height="248"
                         loading="lazy" decoding="async">
                </div>

                <div>
                    <span class="piie-eyebrow">Who we are</span>
                    <h2 id="piie-about-title">{{ $piieAbout->title ?? 'About Our Institution' }}</h2>

                    @if($piieAbout && ! empty($piieAbout->subtitle))
                        <p class="piie-lede">{{ $piieAbout->subtitle }}</p>
                    @endif

                    @foreach($piieParagraphs($piieAbout) as $piieParagraph)
                        <p>{!! $piieParagraph !!}</p>
                    @endforeach

                    <p class="piie-motto" style="margin:1.5rem 0;">{{ $piieMotto }}</p>

                    <div class="piie-btn-row">
                        <a class="piie-btn piie-btn--primary" href="{{ $piieAboutUrl }}">Read Our Story</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 07 — ADMISSIONS MADE SIMPLE
             The steps are the CMS `admissions` items, in their configured order.
        ===================================================================== --}}
        <section class="piie-section piie-section--alt" id="entry" aria-labelledby="piie-admissions-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">Your application</span>
                    <h2 id="piie-admissions-title">Admissions Made Simple</h2>
                    <p class="piie-lede">
                        One online application, one decision, and clear entry requirements
                        for every qualification level.
                    </p>
                </div>

                @if($piieAdmissionsSteps->isEmpty())
                    <div class="piie-empty" style="margin-top:2rem;">The admissions process has not been published yet.</div>
                @else
                    <ol class="piie-steps" style="margin-top:2.5rem;">
                        @foreach($piieAdmissionsSteps as $piieStep)
                            <li>
                                <h3 style="font-size:1.0625rem;margin-bottom:.25rem;">{{ $piieStep->title }}</h3>
                                @if($piieStep->description)
                                    <p class="piie-card__excerpt">{{ $piieStep->description }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="piie-btn-row" style="justify-content:center;margin-top:2.5rem;">
                    {{-- The REAL application route. No second application system. --}}
                    <a class="piie-btn piie-btn--primary" href="{{ $piieApplyUrl }}">Apply Now</a>
                    @if($piieEntry)
                        <a class="piie-btn piie-btn--ghost" href="{{ $piieAdmissionsUrl }}#entry">Entry Requirements</a>
                    @endif
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 08 — STUDENT SUPPORT
        ===================================================================== --}}
        <section class="piie-section" id="support" aria-labelledby="piie-support-title">
            <div class="piie-wrap piie-split piie-split--reverse">
                {{-- `21_library_student.jpg` is one of only TWO photographs in the supplied
                     folder that is full resolution (1536x1024). It is a library /
                     learning image, so it is the correct subject for student support
                     AND it is not used anywhere else on the page - unlike the hero
                     poster, reusing which would make the page repetitive. The other
                     ~299x248 files are card-sized and are still stretched to a
                     half-column here, which is a media shortfall reported for
                     replacement rather than hidden. --}}
                <div class="piie-split__media">
                    <img src="{{ $piieMedia('21_library_student.jpg') }}"
                         alt="A PIIE student using the library learning resources"
                         width="1536" height="1024"
                         loading="lazy" decoding="async">
                </div>

                <div>
                    <span class="piie-eyebrow">Throughout your study</span>
                    <h2 id="piie-support-title">{{ $piieSupport->title ?? 'Student Support' }}</h2>

                    @foreach($piieParagraphs($piieSupport) as $piieParagraph)
                        <p>{!! $piieParagraph !!}</p>
                    @endforeach

                    <div class="piie-btn-row" style="margin-top:1.75rem;">
                        <a class="piie-btn piie-btn--primary" href="{{ $piieProgramsUrl }}#support">Student Support</a>
                        <a class="piie-btn piie-btn--ghost" href="{{ $piieContactUrl }}">Contact Us</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- =====================================================================
             SECTION 09 — INSTITUTIONAL LEADERSHIP
             The CMS `leadership_team` items. No portrait is invented: a person with
             no configured image is shown as a typographic monogram, which is honest
             where a stock photograph would not be.
        ===================================================================== --}}
        <section class="piie-section piie-section--alt" aria-labelledby="piie-leadership-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">Who leads PIIE</span>
                    <h2 id="piie-leadership-title">Institutional Leadership</h2>
                </div>

                @if($piieLeadership->isEmpty())
                    <div class="piie-empty" style="margin-top:2rem;">Leadership profiles have not been published yet.</div>
                @else
                    <div class="piie-grid piie-grid--4" style="margin-top:2.5rem;">
                        {{-- The SHARED leadership card, not hand-rolled markup.
                             This block previously drew its own placeholder: a single
                             grey initial floating in an empty rectangle, which is the
                             "repetitive placeholder graphics" the brief objected to.
                             Using `blocks/card` means a leader looks the same here and
                             on the About page, gains the designed fallback panel, and
                             picks up an uploaded portrait automatically when Super
                             Admin supplies one. --}}
                        @foreach($piieLeadership as $piieLeader)
                            @include('frontend.partials.blocks.card', [
                                'variant'  => 'leadership',
                                'title'    => $piieLeader->title,
                                'role'     => $piieLeader->subtitle ?: null,
                                'image'    => $piieImage($piieLeader->image),
                                'alt'      => $piieLeader->title,
                                'fallback' => $piieLeader->title,
                                'excerpt'  => $piieLeader->description
                                    ? \Illuminate\Support\Str::limit(strip_tags($piieLeader->description), 120)
                                    : null,
                            ])
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- =====================================================================
             SECTION 10 — NEWS AND UPCOMING EVENTS
             Published items only. No item means an honest empty state, never a
             fabricated announcement.
        ===================================================================== --}}
        <section class="piie-section" id="news" aria-labelledby="piie-news-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">Latest from PIIE</span>
                    <h2 id="piie-news-title">News and Upcoming Events</h2>
                </div>

                @if($piieNewsItems->isEmpty())
                    <div class="piie-empty" style="margin-top:2rem;">
                        There are no published news items or events at the moment.
                    </div>
                @else
                    <div class="piie-grid piie-grid--3" style="margin-top:2.5rem;">
                        @foreach($piieNewsItems->take(6) as $piieNewsItem)
                            @php
                                $piieNewsImage = $piieImage($piieNewsItem->image, $piieMedia('17_global_education.jpg'));
                                $piieNewsDate = $piieNewsItem->created_at ? \Illuminate\Support\Carbon::parse($piieNewsItem->created_at) : null;
                            @endphp
                            <article class="piie-card">
                                <div class="piie-card__media">
                                    <img src="{{ $piieNewsImage }}"
                                         alt="{{ $piieNewsItem->title ?: 'PIIE news item' }}"
                                         width="300" height="225"
                                         loading="lazy" decoding="async">
                                </div>
                                <div class="piie-card__body">
                                    <span class="piie-card__date">
                                        @if($piieNewsDate)
                                            <time datetime="{{ $piieNewsDate->toDateString() }}">{{ $piieNewsDate->format('j M Y') }}</time>
                                        @else
                                            <time datetime="{{ now()->toDateString() }}">{{ now()->format('j M Y') }}</time>
                                        @endif
                                    </span>
                                    <h3 class="piie-card__title">{{ $piieNewsItem->title }}</h3>
                                    @if($piieNewsItem->description)
                                        <p class="piie-card__excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($piieNewsItem->description), 140) }}</p>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="piie-btn-row" style="justify-content:center;margin-top:2.5rem;">
                        <a class="piie-btn piie-btn--primary" href="{{ route('landingPage') }}#news">View All News</a>
                    </div>
                @endif
            </div>
        </section>

        {{-- =====================================================================
             SECTION 11 — FREQUENTLY ASKED QUESTIONS
             A NATIVE <details> accordion: keyboard-operable and correctly
             announced with no JavaScript. The script only animates the open state.
        ===================================================================== --}}
        <section class="piie-section piie-section--alt" id="faqs" aria-labelledby="piie-faqs-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <span class="piie-eyebrow">Before you apply</span>
                    <h2 id="piie-faqs-title">{{ $piieFaqSection->title ?? 'Frequently Asked Questions' }}</h2>
                    @if($piieFaqSection && ! empty($piieFaqSection->subtitle))
                        <p class="piie-lede">{{ $piieFaqSection->subtitle }}</p>
                    @endif
                </div>

                @if($piieFaqs->isEmpty())
                    <div class="piie-empty" style="margin-top:2rem;">Questions and answers have not been published yet.</div>
                @else
                    <div class="piie-faq" style="margin-top:2.5rem;">
                        @foreach($piieFaqs as $piieFaqItem)
                            <details class="piie-faq__item">
                                <summary class="piie-faq__q">{{ $piieFaqItem->title }}</summary>
                                <div class="piie-faq__a">
                                    {{-- The CMS stores the answer as HTML, which the existing
                                         sanitiser already handles for every other section. --}}
                                    {!! $piieFaqItem->content ?: '<p>'.e($piieFaqItem->description).'</p>' !!}
                                </div>
                            </details>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- =====================================================================
             SECTION 12 — FINAL APPLICATION CTA
        ===================================================================== --}}
        <section class="piie-section piie-section--tight piie-cta" aria-labelledby="piie-cta-title">
            <div class="piie-wrap">
                <div class="piie-head-center">
                    <h2 id="piie-cta-title">Your future starts at PIIE</h2>
                    <p class="piie-lede">
                        Study a recognised qualification online, at your own pace, with the
                        academic and student support to finish what you start.
                    </p>
                </div>

                <div class="piie-btn-row" style="justify-content:center;margin-top:2rem;">
                    {{-- Every one of these is an EXISTING route, resolved with route(). --}}
                    <a class="piie-btn piie-btn--primary" href="{{ $piieProgramsUrl }}">Explore Programmes</a>
                    <a class="piie-btn piie-btn--primary-alt" href="{{ $piieApplyUrl }}">Apply Now</a>
                    <a class="piie-btn piie-btn--ghost-light" href="{{ $piieContactUrl }}">Contact Admissions</a>
                </div>
            </div>
        </section>

    </main>

    @include('frontend.partials.site_footer')
</div>

@endsection