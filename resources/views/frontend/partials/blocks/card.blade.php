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
            <span class="piie-card__fallback-mark" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($piieFallbackLabel, 0, 3)) }}</span>
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

        @if(! empty($link) && ! empty($link['url']))
            <div class="piie-card__foot">
                <a class="piie-btn piie-btn--ghost" href="{{ $link['url'] }}">
                    {{ $link['text'] ?? 'View Details' }}
                </a>
            </div>
        @endif
    </div>
</article>