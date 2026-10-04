{{--
    ===========================================================================
    PAGE HERO — the shared banner at the top of every inner page.
    ===========================================================================
    DESIGN: hardcoded. CONTENT: `$websitePage` and `website_settings`, so Super
    Admin's title and subtitle are used and nothing is hardcoded twice.

    Variants:
      -image=""        a photograph, cropped 16:9, with the dark overlay for contrast
      -eyebrow="..."   small kicker above the title (optional)
      -motto=""       set true on About to show the motto

    Deliberately NOT a video on inner pages. The welcome video is a HOMEPAGE
    treatment; reusing it on every page would make the video a decoration rather
    than a welcome and would cost 5 MB per page view.
--}}
@php
    $piieHeroImage = ! empty($image) ? $image : null;
    $piieHeroTitle = $title ?? ($websitePage->title ?? 'PIIE');
    $piieHeroSubtitle = $subtitle ?? ($websitePage->subtitle ?? null);
@endphp

<section class="piie-pagehero {{ $piieHeroImage ? 'piie-pagehero--image' : '' }}"
         aria-labelledby="piie-pagehero-title">
    @if($piieHeroImage)
        <div class="piie-pagehero__media" aria-hidden="true">
            <img src="{{ $piieHeroImage }}" alt="" loading="eager" decoding="async" fetchpriority="high">
        </div>
    @endif

    <div class="piie-wrap piie-pagehero__inner">
        @if(! empty($eyebrow))
            <span class="piie-eyebrow piie-eyebrow--light">{{ $eyebrow }}</span>
        @endif

        <h1 id="piie-pagehero-title">{{ $piieHeroTitle }}</h1>

        @if($piieHeroSubtitle)
            <p class="piie-pagehero__lede">{{ $piieHeroSubtitle }}</p>
        @endif

        @if(! empty($motto))
            {{-- The motto lives HERE on the inner page, because the header no longer
                 repeats it. It is still CMS-driven, via the `motto` setting. --}}
            <p class="piie-motto piie-pagehero__motto">{{ $motto }}</p>
        @endif

        @if(! empty($crumbs))
            {{-- Breadcrumbs are emitted last so they sit below the title, which is
                 where a sighted reader expects them and where a screen reader meets
                 them after the heading. --}}
            @include('frontend.partials.blocks.breadcrumbs', ['crumbs' => $crumbs])
        @endif
    </div>
</section>