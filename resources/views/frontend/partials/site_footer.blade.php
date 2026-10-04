{{--
    ===========================================================================
    PIIE PUBLIC SITE — FOOTER
    ===========================================================================

    DESIGN: hardcoded. CONTENT: read from the CMS.

    The institution name, motto, address and copyright are all read from the
    `website_settings` rows the existing homepage already used, so Super Admin
    keeps control of them. Social links and portal links come from
    `website_items` in the sections already seeded for them
    (`social_media_links`, `portals_links`) — nothing here is hardcoded copy that
    the institution might need to change.
--}}

@php
    $piieSettings = $websiteSettings ?? collect();
    $piieItems = $websiteItems ?? collect();

    $piieName = $piieSettings['institution_name'] ?? 'Prime International Institute of Excellence (PIIE)';
    $piieTagline = $piieSettings['tagline'] ?? '';
    $piieAddress = $piieSettings['contact_address'] ?? '';
    $piieCopyright = $piieSettings['footer_copyright'] ?? ('© '.date('Y').' '.$piieName);

    $piieHas = fn (string $name) => Route::has($name);

    /**
     * A link to a CMS page ONLY if such a page is published.
     *
     * The first version of this footer hardcoded the slugs, and `privacy-terms`
     * does not exist in the CMS - so a Privacy Policy link rendered straight to a
     * 404. On a university site a dead legal link is worse than an absent one, so a
     * page that has not been published simply is not linked.
     *
     * Super Admin creates the page in Website Management and the link appears, with
     * no code change.
     */
    $piiePublishedSlugs = collect($allPages ?? collect())->pluck('slug')->filter()->all();
    $piiePageUrl = function (string $slug) use ($piieHas, $piiePublishedSlugs) {
        return ($piieHas('website.page') && in_array($slug, $piiePublishedSlugs, true))
            ? route('website.page', $slug)
            : null;
    };

    $piieProgramsUrl = $piiePageUrl('academic-programmes') ?? url('/');
    $piieAboutUrl = $piiePageUrl('about-us') ?? url('/');
    $piieAdmissionsUrl = $piiePageUrl('admissions') ?? url('/');
    $piieResearchUrl = $piiePageUrl('research-and-innovation') ?? url('/');
    $piieContactUrl = $piiePageUrl('contact-us') ?? url('/');
    $piiePrivacyUrl = $piiePageUrl('privacy-terms');
    $piieTermsUrl = $piiePageUrl('terms');
    $piieApplyUrl = $piieHas('apply.form') ? route('apply.form') : $piieAdmissionsUrl;
    $piieLoginUrl = $piieHas('login') ? route('login') : url('/login');
    $piieBrochureUrl = $piieHas('download.brochure') ? route('download.brochure') : $piieProgramsUrl;

    /**
     * Contact details come from the SAME resolver the header utility bar and the
     * Contact page use.
     *
     * They used to be read here from `website_items` rows on the `contact_page`
     * section — a different source from the header's. That is exactly how a footer
     * ends up advertising a telephone number the contact page does not list. One
     * resolver, one answer, three surfaces.
     *
     * A CMS that publishes channels as `contact_page` ITEMS instead of as settings
     * still works: those items are merged in below as a secondary source, so an
     * institution already using the older arrangement is not left with an empty
     * footer.
     */
    $piieSettingsArray = is_array($piieSettings) ? $piieSettings : $piieSettings->all();

    $piiePhone = \App\Support\Website\PublicContactChannels::phones($piieSettingsArray);
    $piieEmail = \App\Support\Website\PublicContactChannels::emails($piieSettingsArray);

    // Secondary source: channels authored as CMS content items.
    $piieContactItems = collect($piieItems['contact_page'] ?? collect())
        ->filter(fn ($i) => (int) $i->status === 1);

    $piieItemPhone = $piieContactItems->firstWhere('item_type', 'phone')->link ?? null;
    $piieItemEmail = $piieContactItems->firstWhere('item_type', 'email')->link ?? null;

    if ($piieItemPhone && ! in_array($piieItemPhone, $piiePhone, true)) {
        $piiePhone[] = $piieItemPhone;
    }

    if ($piieItemEmail && ! in_array($piieItemEmail, $piieEmail, true)) {
        $piieEmail[] = $piieItemEmail;
    }

    // Kept as single values for the existing template markup, which expects one.
    $piiePhoneLabel = $piiePhone ? \App\Support\Website\PublicContactChannels::phoneLabel($piiePhone[0]) : null;
    $piiePhoneHref = $piiePhone ? \App\Support\Website\PublicContactChannels::telHref($piiePhone[0]) : null;

    // Split out for the footer's labelled contact list: an official switchboard line
    // and a mobile number are different things and are labelled as such.
    $piieOfficialLine = \App\Support\Website\PublicContactChannels::officialLine($piieSettingsArray);
    $piieTelephones = \App\Support\Website\PublicContactChannels::telephoneNumbers($piieSettingsArray);
    $piieSiteWeb = \App\Support\Website\PublicContactChannels::website($piieSettingsArray);

    /**
     * Social links, filtered to those that actually go somewhere.
     *
     * All four CMS rows carry `link = '#'` — placeholders, not addresses. The old
     * filter only rejected an EMPTY link, so the footer rendered four dead
     * `href="#"` links that scrolled nowhere. A link to `#` is worse than no link:
     * it looks like a control, takes a tap, and does nothing.
     *
     * A row whose link is `#`, `javascript:`, or blank is therefore omitted. The CMS
     * row is untouched, so the moment Super Admin pastes a real Facebook or LinkedIn
     * URL the icon appears with no code change.
     */
    $piieSocial = collect($piieItems['social_media_links'] ?? collect())
        ->filter(fn ($i) => (int) $i->status === 1 && ! empty(trim((string) $i->link)))
        ->filter(function ($i) {
            $link = strtolower(trim((string) $i->link));

            return $link !== '#'
                && ! str_starts_with($link, 'javascript:')
                && $link !== 'null';
        })
        ->values();

    // Programme links come from the catalogue sections the CMS already seeds.
    $piieProgrammeLinks = collect()
        ->merge($piieItems['programme_catalog_graduate_school'] ?? collect())
        ->merge($piieItems['programme_categories'] ?? collect())
        ->filter(fn ($i) => $i->status == 1)
        ->take(6);
@endphp

<footer class="piie-footer" role="contentinfo">
    <div class="piie-wrap">
        <div class="piie-footer__grid">
            {{-- Brand + short institutional introduction.

                 ── THE LOGO ──────────────────────────────────────────────────────
                 This used to render `white logo with text.png`, which is a BLANK
                 WHITE IMAGE — 1993x631 pixels of nothing. The footer therefore showed
                 no logo at all, and on a dark background a browser would draw
                 nothing where the institution's identity should be.

                 `logo.png` is the genuine PIIE crest: "PIIE / PRIME INTERNATIONAL
                 INSTITUTE OF EXCELLENCE / Learning for Impact". It is the same file
                 the header uses, so the identity is consistent.

                 It sits on a white rounded panel because the crest has a white
                 background and dark blue/orange ink, which would be invisible
                 directly on the dark footer. The panel is the standard treatment for
                 a light crest on a dark ground; it is not a substitute logo, and the
                 crest itself is the institution's real asset, unmodified.

                 ── WHY NOT THE OTHER CANDIDATES ──────────────────────────────────
                 `logo-removebg-preview.png` and `Logo1-removebg-preview.png` are the
                 crest of a DIFFERENT institution — "TDIET / Trinity Divine Interpreted
                 Theological Institute of Business & Technology". They must never be
                 rendered on a PIIE page. `scripts/audit-logo-provenance.php` exists so
                 that a future editor can check a logo file before using it. --}}
            <div class="piie-footer__brand">
                <div class="piie-footer__crest">
                    <img src="{{ asset('assets/uploads/logo/logo.png') }}"
                         alt="{{ $piieName }}" width="1042" height="1042"
                         loading="lazy" decoding="async">
                </div>

                {{-- The institution's name and motto are set as TEXT, not baked into
                     the image, so they are selectable, searchable, translatable and
                     readable by assistive technology — and they stay correct if the
                     crest is ever replaced. --}}
                <p class="piie-footer__name">{{ $piieName }}</p>

                @if($piieTagline)
                    <p>{{ $piieTagline }}</p>
                @endif

                <p class="piie-motto piie-footer__motto">{{ $piieSettings['motto'] ?? 'Strive. Excel. Lead.' }}</p>

                {{-- Official contact details, from the same resolver as the header and
                     the Contact page, so the three surfaces cannot disagree. --}}
                <ul class="piie-footer__contact">
                    @if($piieOfficialLine)
                        <li>
                            <span class="piie-footer__contact-label">Official Line</span>
                            <a href="{{ \App\Support\Website\PublicContactChannels::telHref($piieOfficialLine) }}">
                                {{ $piieOfficialLine }}
                            </a>
                        </li>
                    @endif

                    @foreach($piieTelephones as $piieTelephone)
                        <li>
                            <span class="piie-footer__contact-label">Telephone</span>
                            <a href="{{ \App\Support\Website\PublicContactChannels::telHref($piieTelephone) }}">
                                {{ $piieTelephone }}
                            </a>
                        </li>
                    @endforeach

                    @foreach($piieEmail as $piieEmailAddress)
                        <li>
                            <span class="piie-footer__contact-label">Email</span>
                            <a href="mailto:{{ $piieEmailAddress }}">{{ $piieEmailAddress }}</a>
                        </li>
                    @endforeach

                    @if($piieSiteWeb)
                        <li>
                            <span class="piie-footer__contact-label">Website</span>
                            <a href="{{ $piieSiteWeb }}" target="_blank" rel="noopener noreferrer">
                                {{ \Illuminate\Support\Str::of($piieSiteWeb)->after('https://') }}
                            </a>
                        </li>
                    @endif
                </ul>
                @if($piieSocial->isNotEmpty())
                    <h3 style="margin-top:1.5rem;">Follow</h3>
                    <div class="piie-footer__social">
                        @foreach($piieSocial as $social)
                            {{-- The full platform name, not a two-letter truncation.

                                 `Str::limit($title, 2)` produced "Fa", "Li", "Yo" and
                                 "In", which reads as a rendering fault rather than as
                                 a brand. The name comes from the CMS, so it is
                                 whatever the institution recorded — and it is also the
                                 accessible name, so a screen-reader user hears
                                 "Facebook", not "Fa". --}}
                            <a href="{{ $social->link }}"
                               title="{{ $social->title }}"
                               aria-label="{{ $social->title ?: 'Social media link' }}"
                               rel="noopener noreferrer"
                               target="_blank">{{ $social->title }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Programmes --}}
            <nav aria-label="Programmes">
                <h3>Programmes</h3>
                <ul class="piie-footer__links">
                    <li><a href="{{ $piieProgramsUrl }}">All Programmes</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#levels">Master's Degrees</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#levels">Postgraduate Diplomas</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#levels">Bachelor's Degrees</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#levels">Diplomas</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#levels">Certificates</a></li>
                </ul>
            </nav>

            {{-- Admissions + portal --}}
            <nav aria-label="Admissions and portals">
                <h3>Admissions</h3>
                <ul class="piie-footer__links">
                    <li><a href="{{ $piieAdmissionsUrl }}">How to Apply</a></li>
                    <li><a href="{{ $piieAdmissionsUrl }}#entry">Entry Requirements</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#odel">Online Learning</a></li>
                    <li><a href="{{ $piieProgramsUrl }}#support">Student Support</a></li>
                    <li><a href="{{ $piieBrochureUrl }}">Download Brochure</a></li>
                    <li><a href="{{ $piieApplyUrl }}">Apply Now</a></li>
                    <li><a href="{{ $piieLoginUrl }}">Student Portal</a></li>
                </ul>
            </nav>

            {{-- Contact, from the CMS --}}
            <div>
                <h3>Contact</h3>
                <ul class="piie-footer__links">
                    @if($piieAddress)
                        <li>{{ $piieAddress }}</li>
                    @endif
                    @if($piiePhone)
                        <li><a href="{{ $piiePhoneHref }}">{{ $piiePhoneLabel }}</a></li>

                        @foreach(array_slice($piiePhone, 1) as $piieExtraPhone)
                            <li>
                                <a href="{{ \App\Support\Website\PublicContactChannels::telHref($piieExtraPhone) }}">
                                    {{ \App\Support\Website\PublicContactChannels::phoneLabel($piieExtraPhone) }}
                                </a>
                            </li>
                        @endforeach
                    @endif

                    {{-- Every configured address, not only the first, because the
                         resolver supports a list and the footer must not be the one
                         place a second address silently disappears. --}}
                    @if($piieEmail)
                        <li><a href="mailto:{{ $piieEmail[0] }}">{{ $piieEmail[0] }}</a></li>

                        @foreach(array_slice($piieEmail, 1) as $piieExtraEmail)
                            <li><a href="mailto:{{ $piieExtraEmail }}">{{ $piieExtraEmail }}</a></li>
                        @endforeach
                    @endif
                    <li><a href="{{ $piieContactUrl }}">Contact Us</a></li>
                    <li><a href="{{ $piieResearchUrl }}">Research &amp; Innovation</a></li>
                </ul>
            </div>
        </div>

        <div class="piie-footer__bottom">
            <span>{{ $piieCopyright }}</span>

            <nav class="piie-footer__legal" aria-label="Legal">
                {{-- Linked only when the page is actually published. See the note
                     above: a dead legal link is worse than no legal link. --}}
                @if($piiePrivacyUrl)
                    <a href="{{ $piiePrivacyUrl }}">Privacy Policy</a>
                @endif
                @if($piieTermsUrl)
                    <a href="{{ $piieTermsUrl }}">Terms</a>
                @endif
                <a href="{{ route('landingPage') }}#faqs">FAQs</a>
                <a href="{{ $piieLoginUrl }}">Staff &amp; Student Login</a>
            </nav>
        </div>
    </div>
</footer>