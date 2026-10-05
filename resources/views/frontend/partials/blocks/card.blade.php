{{--
    THE CARD FAMILY — programme, information, faculty and leadership cards.

    ONE partial with a `variant`, rather than four near-identical files, so the
    border radius, image treatment, padding and button all stay identical by
    construction. The brief asks for consistent card presentation across every
    public page; four separate templates is exactly how that drifts.

    VARIANTS
      programme   title, optional tag, excerpt, View Programme CTA, 4:3 media
      info        title, body, optional link
      faculty     title, body — for programme categories
      leadership  name, role, optional portrait (never invented — see below)

    MISSING IMAGES
      `fallback` names a category. The fallback is a DEDIGNED panel — a brand
      gradient with the qualification level set in type — not an empty image
      rectangle and not an invented photograph.
--}}
@php
    $piieVariant = $variant ?? 'info';
    $piieMediaSrc = ! empty($image) ? $image : null;
    $piieFallbackLabel = $fallback ?? null;

    /**
     * A fallback tone, so the fallback is CATEGORY-based rather than one flat panel
     * repeated sixty-seven times.
     *
     * The CMS has no fixed list of faculties - they are `website_items.section_key`
     * values a Super Admin creates - so the tone has to be derived from something.
     * `$fallbackTone` is supplied by the catalogue, which knows each faculty's
     * position in the sorted faculty list, and that spreads four faculties across
     * four distinct tones.
     *
     * Hashing the key was tried first and abandoned: `crc32` modulo four put three of
     * the four real faculties on the same tone, so the fallback looked identical
     * across faculties while claiming to be category-based.
     *
     * The hash remains only as the fallback for a card rendered outside the
     * catalogue, where no faculty list exists. `crc32` rather than a random value
     * because it is stable across requests and platforms - the same programme must
     * always get the same tone, or the grid reshuffles between page loads.
     */
    $piieTones = ['a', 'b', 'c', 'd'];

    if (! empty($fallbackTone) && in_array($fallbackTone, $piieTones, true)) {
        $piieTone = $fallbackTone;
    } else {
        $piieTone = $piieTones[abs(crc32((string) ($fallback ?? 'piie'))) % count($piieTones)];
    }
@endphp

<article class="piie-card piie-card--{{ $piieVariant }}">

    @if($piieMediaSrc)
        <div class="piie-card__media">
            <img src="{{ $piieMediaSrc }}" alt="{{ $alt ?? ($title ?? '') }}"
                 loading="lazy" decoding="async">
        </div>
    @elseif($piieFallbackLabel)
        {{-- A designed placeholder. An empty frame reads as a broken page; this
             reads as a deliberate category. --}}
        <div class="piie-card__media piie-card__media--fallback piie-card__media--fb-{{ $piieTone }}">
            {{--
                The mark is the first three letters of the most DISTINGUISHING word, not
                of the label.

                Taking the first three characters of the label gives "FAC" for "Faculty of
                Business and Management", "Faculty of Engineering and Technology" AND
                "Faculty of Education and Humanities" — three different faculties wearing
                identical initials, which is the opposite of category-based. Found by
                rendering the catalogue and reading the marks off the panels.

                So a leading "Faculty of" is skipped and the next word used instead: BUS,
                ENG, EDU. A label with no such prefix ("Graduate School") keeps its own
                first three letters, GRA. The full name is always printed underneath, so
                the mark is a visual anchor and never the only label — an abbreviation
                that meant nothing would be worse than none.
            --}}
            @php
                $piieMarkWords = preg_split('/\s+/', trim((string) $piieFallbackLabel)) ?: [];

                // Skip the WHOLE "faculty of" prefix, not just the first word.
                // Shifting only "Faculty" left "of" behind, so the mark became "OF" for
                // all three faculties — the same defect as "FAC", one word further along.
                $piieGeneric = ['faculty', 'faculties', 'school', 'department', 'of', 'the', 'and', 'for'];
                while (count($piieMarkWords) > 1
                    && in_array(strtolower(rtrim($piieMarkWords[0], ',')), $piieGeneric, true)) {
                    array_shift($piieMarkWords);
                }

                $piieMark = \Illuminate\Support\Str::upper(
                    mb_substr((string) ($piieMarkWords[0] ?? 'PIIE'), 0, 3)
                );
            @endphp
            <span class="piie-card__fallback-mark" aria-hidden="true">{{ $piieMark }}</span>
            <span class="piie-card__fallback-label">{{ $piieFallbackLabel }}</span>
        </div>
    @endif

    <div class="piie-card__body">
        @if(! empty($tag))
            <span class="piie-tag">{{ $tag }}</span>
        @endif

        <h3 class="piie-card__title">{{ $title ?? '' }}</h3>

        @if(! empty($role))
            <span class="piie-tag piie-tag--accent">{{ $role }}</span>
        @endif

        @if(! empty($meta))
            <div class="piie-card__meta">
                @foreach($meta as $metaItem)
                    <span>{{ $metaItem }}</span>
                @endforeach
            </div>
        @endif

        @if(! empty($excerpt))
            <p class="piie-card__excerpt">{!! $excerpt !!}</p>
        @endif

        {{-- Price. Rendered ONLY when there is a complete price to state: an amount,
             a currency and a basis. Anything less renders the contact line instead,
             because a bare figure in a catalogue card reads as the price whatever is
             missing — and a stored 0 reads as "free", which is a claim about the
             institution rather than an absence of information.
             The absence of a price is therefore a designed state, not a gap. --}}
        @if(! empty($price) && is_array($price))
            <p class="piie-card__price">
                <span class="piie-card__price-amount">
                    @if(! empty($price['currency']))
                        <span class="piie-card__price-currency">{{ $price['currency'] }}</span>
                    @endif
                    <span class="piie-card__price-figure">{{ $price['amount'] }}</span>
                </span>
                @if(! empty($price['basis']))
                    <span class="piie-card__price-basis">{{ $price['basis'] }}</span>
                @endif
            </p>
        @elseif(! empty($contactLabel))
            <p class="piie-card__price piie-card__price--contact">{{ $contactLabel }}</p>
        @endif

        @if(! empty($link) && ! empty($link['url']))
            <div class="piie-card__foot">
                <a class="piie-btn piie-btn--ghost" href="{{ $link['url'] }}">
                    {{ $link['text'] ?? 'View Details' }}
                </a>
            </div>
        @endif
    </div>
</article>