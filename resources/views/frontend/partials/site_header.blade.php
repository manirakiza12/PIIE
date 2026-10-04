{{--
    ===========================================================================
    PIIE PUBLIC SITE — THE ONE SHARED HEADER
    ===========================================================================

    USED BY the homepage, every inner page, and /apply. The inner pages previously
    carried their own `<nav>` with a graduation-cap icon and the institution name
    repeated beside it; that competing header has been removed, so there is exactly
    one header on the public site.

    HIERARCHY
      Row 1  a slim utility bar: email | telephone | Download Brochure
      Row 2  logo | navigation | Apply Now | Student Portal

    BRAND
      The official logo only. The institution name and the motto are NOT repeated
      beside it: the lockup already carries the name. Both remain available to Super
      Admin and still render on the About hero and in the footer, where there is room.

    CONTACT DATA
      Every channel comes from `website_settings` via
      `App\Support\Website\PublicContactChannels`. Nothing is hardcoded and nothing
      is invented. The CMS currently publishes only an address — there is no
      telephone number and no email address — so this bar renders with the brochure
      alone and each missing item is simply absent rather than left blank. The moment
      Super Admin fills `contact_phone` or `contact_email` (a plain string, or a JSON
      array for several) the item appears here, on the contact page and in the
      footer with no code change.

    DESIGN: hardcoded. CONTENT: navigation is built from the published
    `website_pages` records, so a page Super Admin publishes appears automatically
    and an unpublished page is never linked.

    ACCESSIBILITY
      - skip link first in the document order
      - a real <nav> with an accessible name, on desktop and on mobile separately
      - the utility bar is a <nav> too, so its links are reachable as a group
      - the mobile panel is toggled by `aria-expanded`, not by CSS alone
      - dropdowns open on CLICK from a real <button>, so they work with a keyboard,
        a touchscreen and a mouse; Escape closes them
      - `aria-current="page"` on the page you are actually on
--}}

@php
    use App\Support\Website\PublicContactChannels;

    $piieSettings = $websiteSettings ?? collect();
    $piieSettingsArray = is_array($piieSettings) ? $piieSettings : $piieSettings->all();

    $piiePhones = PublicContactChannels::phones($piieSettingsArray);
    $piieEmails = PublicContactChannels::emails($piieSettingsArray);
    $piieWebsite = PublicContactChannels::website($piieSettingsArray);

    $piiePages = collect($allPages ?? [])
        ->filter(fn ($p) => (int) $p->status === 1)
        ->sortBy(fn ($p) => [(int) $p->display_order, (int) $p->sort_order])
        ->values();

    $piieHas = fn (string $name) => Route::has($name);

    /**
     * A link to a CMS page ONLY if that page is published.
     *
     * `Route::has()` proves the route exists; it does not prove a page with that slug
     * does. Hardcoded slugs produced a navigation entry that 404s, so the published
     * list is consulted instead.
     */
    $piiePublished = $piiePages->pluck('slug')->filter()->all();
    $piiePageUrl = function (string $slug) use ($piieHas, $piiePublished) {
        return ($piieHas('website.page') && in_array($slug, $piiePublished, true))
            ? route('website.page', $slug)
            : null;
    };

    // A CMS page of that title, whatever its slug happens to be.
    $piieUrlByTitle = function (string $needle) use ($piiePages, $piieHas) {
        $match = $piiePages->first(function ($p) use ($needle) {
            return str_contains(strtolower((string) $p->title), strtolower($needle));
        });

        return ($match && $piieHas('website.page')) ? route('website.page', $match->slug) : null;
    };

    $piieHome = route('landingPage');
    $piieAbout = $piieUrlByTitle('About') ?? $piiePageUrl('about-us');
    $piieProgrammes = $piieUrlByTitle('Programme') ?? $piiePageUrl('academic-programmes');
    $piieAdmissions = $piieUrlByTitle('Admission') ?? $piiePageUrl('admissions');
    $piieResearch = $piieUrlByTitle('Research') ?? $piiePageUrl('research-and-innovation');
    $piieContact = $piieUrlByTitle('Contact') ?? $piiePageUrl('contact-us');
    $piieCareers = $piiePageUrl('careers');

    // Applied Now, the portal and the brochure are fixed EXISTING routes, never CMS
    // slugs. The brochure is an existing `download.brochure` route serving the real
    // 13 MB PDF already in public/assets/uploads/documents.
    $piieApply = $piieHas('apply.form') ? route('apply.form') : $piieAdmissions;
    $piieLogin = $piieHas('login') ? route('login') : url('/login');
    $piieBrochure = $piieHas('download.brochure') ? route('download.brochure') : null;

    // On the homepage there is no current inner page; on an inner page the
    // controller passes $websitePage.
    $piieCurrentSlug = $websitePage->slug ?? null;

    /**
     * The About dropdown holds only GENUINE child pages.
     *
     * It previously held "everything published except About and Programmes", which
     * at the time meant Admissions, Research, Contact and Careers - four pages that
     * are ALSO top-level navigation items. A visitor opening "About PIIE" was shown
     * pages that were not about PIIE, and that were one click away in the bar above.
     *
     * So: subtract every slug already used as a top-level entry. With the CMS as it
     * stands that leaves nothing, and no dropdown is rendered - a flat navigation is
     * honest when there are no children. The moment Super Admin publishes a real
     * sub-page it appears here automatically, and the keyboard, touch and Escape
     * handling in `piie-hero.js` is already in place for it.
     */
    $piieTopLevel = array_values(array_filter([
        // `home` is published too, and is reached by the logo and the logo's aria
        // label rather than by a nav entry. Left out of this list it became the sole
        // "child" of the About dropdown, which is how a dropdown containing one
        // duplicate Home link ended up on the site.
        'home',
        $piieAbout ? 'about-us' : null,
        $piieProgrammes ? 'academic-programmes' : null,
        $piieAdmissions ? 'admissions' : null,
        $piieResearch ? 'research-and-innovation' : null,
        $piieContact ? 'contact-us' : null,
        $piieCareers ? 'careers' : null,
    ]));

    $piieAboutDropdown = $piiePages
        ->reject(fn ($p) => in_array($p->slug, $piieTopLevel, true))
        ->values();

    // The utility bar hides itself when it would have nothing in it. An empty strip
    // above the header reads as a rendering fault.
    $piieUtilityLinks = count($piieEmails)
        + count($piiePhones)
        + ($piieWebsite ? 1 : 0)
        + ($piieBrochure ? 1 : 0);
@endphp

<a class="piie-skip-link" href="#piie-main">Skip to main content</a>

<header class="piie-header">

    {{-- ── ROW 1: utility bar ────────────────────────────────────────────────
         Email | Telephone(s) | Website ....... Download Brochure (right)

         Slim, low-contrast, and entirely CMS-driven. Each item renders only if its
         value exists, so an unconfigured field costs nothing.

         The channels sit on the LEFT and the brochure is pushed to the RIGHT with
         `margin-left: auto`, which is the arrangement the institution asked for and
         which also stops the brochure drifting into the middle of the row when a
         second telephone number is added. --}}
    @if($piieUtilityLinks > 0)
        <div class="piie-utility">
            <div class="piie-wrap piie-utility__inner">
                <nav class="piie-utility__nav" aria-label="Contact and publications">
                    <ul class="piie-utility__list">
                        @foreach($piieEmails as $piieEmail)
                            <li>
                                <a href="mailto:{{ $piieEmail }}">
                                    <span class="piie-utility__label">Email</span>
                                    <span class="piie-utility__value">{{ $piieEmail }}</span>
                                </a>
                            </li>
                        @endforeach

                        @foreach($piiePhones as $piieIndex => $piiePhone)
                            <li>
                                <a href="{{ PublicContactChannels::telHref($piiePhone) }}">
                                    <span class="piie-utility__label">
                                        {{ $piieIndex === 0 ? 'Telephone' : 'Official Line' }}
                                    </span>
                                    <span class="piie-utility__value">
                                        {{ PublicContactChannels::phoneLabel($piiePhone) }}
                                    </span>
                                </a>
                            </li>
                        @endforeach

                        @if($piieWebsite)
                            <li>
                                <a href="{{ $piieWebsite }}" target="_blank" rel="noopener noreferrer">
                                    <span class="piie-utility__label">Website</span>
                                    <span class="piie-utility__value">
                                        {{ \Illuminate\Support\Str::of($piieWebsite)->after('https://') }}
                                    </span>
                                </a>
                            </li>
                        @endif

                        @if($piieBrochure)
                            <li class="piie-utility__item--end">
                                <a href="{{ $piieBrochure }}">
                                    <span class="piie-utility__value">Download Brochure</span>
                                </a>
                            </li>
                        @endif
                    </ul>
                </nav>
            </div>
        </div>
    @endif

    {{-- ── ROW 2: logo | navigation | actions ──────────────────────────────── --}}
    <div class="piie-wrap">
        <div class="piie-header__bar">

            {{-- The official logo. No name, no motto beside it: both are removed. --}}
            <a class="piie-brand" href="{{ $piieHome }}" aria-label="{{ $piieSettings['institution_name'] ?? 'PIIE' }} — home">
                <img class="piie-brand__mark"
                     src="{{ asset('assets/uploads/logo/logo.png') }}"
                     alt="{{ $piieSettings['institution_name'] ?? 'Prime International Institute of Excellence (PIIE)' }}"
                     width="44" height="44"
                     loading="eager" decoding="async">
            </a>

            <nav class="piie-nav" aria-label="Primary">
                <ul class="piie-nav__list">
                    <li>
                        <a class="piie-nav__link" href="{{ $piieHome }}"
                           @if(! $piieCurrentSlug) aria-current="page" @endif>Home</a>
                    </li>

                    {{-- A plain link when About has no child pages, a dropdown when it
                         does. Never a dropdown whose entries are other top-level
                         pages: that reads as a page hierarchy that does not exist.

                         The outer `@if($piieAbout)` is what guarantees a non-empty
                         href. Without it, a CMS with no About page would still reach
                         the `@else` branch and render a link to nowhere. --}}
                    @if($piieAbout)
                        @if($piieAboutDropdown->isNotEmpty())
                            <li class="piie-nav__group" data-pii-dropdown>
                                <button type="button" class="piie-nav__link"
                                        aria-expanded="false" aria-haspopup="true"
                                        @if($piieCurrentSlug === 'about-us') aria-current="page" @endif>
                                    About PIIE <span class="piie-nav__caret" aria-hidden="true">&#9662;</span>
                                </button>
                                <ul class="piie-nav__dropdown">
                                    <li><a href="{{ $piieAbout }}">About PIIE</a></li>
                                    @foreach($piieAboutDropdown as $child)
                                        <li>
                                            <a href="{{ $piiePageUrl($child->slug) }}"
                                               @if($piieCurrentSlug === $child->slug) aria-current="page" @endif>{{ $child->nav_title ?: $child->title }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </li>
                        @else
                            <li>
                                <a class="piie-nav__link" href="{{ $piieAbout }}"
                                   @if($piieCurrentSlug === 'about-us') aria-current="page" @endif>About PIIE</a>
                            </li>
                        @endif
                    @endif

                    @if($piieProgrammes)
                        <li>
                            <a class="piie-nav__link" href="{{ $piieProgrammes }}#catalogue"
                               @if($piieCurrentSlug === 'academic-programmes') aria-current="page" @endif>Programmes</a>
                        </li>
                    @endif

                    @if($piieAdmissions)
                        <li>
                            <a class="piie-nav__link" href="{{ $piieAdmissions }}"
                               @if($piieCurrentSlug === 'admissions') aria-current="page" @endif>Admissions</a>
                        </li>
                    @endif

                    <li>
                        <a class="piie-nav__link" href="{{ $piieHome }}#odel">Online Learning</a>
                    </li>

                    <li>
                        <a class="piie-nav__link" href="{{ $piieHome }}#news">News &amp; Events</a>
                    </li>

                    {{-- Careers. Rendered only when the page is published, so it can
                         never be a link to a 404. --}}
                    @if($piieCareers)
                        <li>
                            <a class="piie-nav__link" href="{{ $piieCareers }}"
                               @if($piieCurrentSlug === 'careers') aria-current="page" @endif>Careers</a>
                        </li>
                    @endif

                    @if($piieContact)
                        <li>
                            <a class="piie-nav__link" href="{{ $piieContact }}"
                               @if($piieCurrentSlug === 'contact-us') aria-current="page" @endif>Contact</a>
                        </li>
                    @endif
                </ul>
            </nav>

            <div class="piie-header__actions">
                <a class="piie-btn piie-btn--primary" href="{{ $piieApply }}">Apply Now</a>
                <a class="piie-btn piie-btn--ghost" href="{{ $piieLogin }}">Student Portal</a>
            </div>

            {{-- Below 1024px the navigation collapses to this control. --}}
            <button type="button" class="piie-burger" data-pii-burger
                    aria-expanded="false" aria-controls="piie-mobile-nav">
                <span aria-hidden="true"></span>
                <span class="visually-hidden-piie">Menu</span>
            </button>
        </div>
    </div>

    <div class="piie-mobile-nav" id="piie-mobile-nav">
        <div class="piie-wrap">
            <nav aria-label="Mobile">
                <ul>
                    <li><a href="{{ $piieHome }}">Home</a></li>
                    @if($piieAbout)
                        <li><a href="{{ $piieAbout }}">About PIIE</a></li>
                    @endif
                    @if($piieProgrammes)
                        <li><a href="{{ $piieProgrammes }}#catalogue">Programmes</a></li>
                    @endif
                    @if($piieAdmissions)
                        <li><a href="{{ $piieAdmissions }}">Admissions</a></li>
                    @endif
                    <li><a href="{{ $piieHome }}#odel">Online Learning</a></li>
                    <li><a href="{{ $piieHome }}#news">News &amp; Events</a></li>
                    @if($piieCareers)
                        <li><a href="{{ $piieCareers }}">Careers</a></li>
                    @endif
                    @if($piieResearch)
                        <li><a href="{{ $piieResearch }}">Research &amp; Innovation</a></li>
                    @endif
                    @if($piieContact)
                        <li><a href="{{ $piieContact }}">Contact</a></li>
                    @endif
                </ul>

                {{-- The utility-bar channels are repeated here, because on a phone the
                     utility bar is hidden and these are the only ways to reach the
                     institution. Rendered only when configured, like the bar. --}}
                @if($piieUtilityLinks > 0)
                    <ul class="piie-mobile-nav__utility">
                        @foreach($piieEmails as $piieEmail)
                            <li><a href="mailto:{{ $piieEmail }}">{{ $piieEmail }}</a></li>
                        @endforeach
                        @foreach($piiePhones as $piiePhone)
                            <li>
                                <a href="{{ PublicContactChannels::telHref($piiePhone) }}">
                                    {{ PublicContactChannels::phoneLabel($piiePhone) }}
                                </a>
                            </li>
                        @endforeach
                        @if($piieWebsite)
                            <li>
                                <a href="{{ $piieWebsite }}" target="_blank" rel="noopener noreferrer">
                                    {{ \Illuminate\Support\Str::of($piieWebsite)->after('https://') }}
                                </a>
                            </li>
                        @endif
                        @if($piieBrochure)
                            <li><a href="{{ $piieBrochure }}">Download Brochure</a></li>
                        @endif
                    </ul>
                @endif

                <div class="piie-btn-row">
                    <a class="piie-btn piie-btn--primary" href="{{ $piieApply }}">Apply Now</a>
                    <a class="piie-btn piie-btn--ghost" href="{{ $piieLogin }}">Student Portal</a>
                </div>
            </nav>
        </div>
    </div>
</header>
