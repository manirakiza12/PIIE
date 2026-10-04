{{--
    CTA SECTION — the closing band on every inner page.

    Every URL here is an EXISTING route resolved with `route()`. There is no second
    application system and no invented destination.
--}}
@php
    $piieHasCta = fn (string $n) => Route::has($n);
    $piieCtaProgrammes = $piieHasCta('website.page') && in_array('academic-programmes', $piieSlugs ?? [], true)
        ? route('website.page', 'academic-programmes')
        : url('/');
    $piieCtaAdmissions = $piieHasCta('website.page') && in_array('admissions', $piieSlugs ?? [], true)
        ? route('website.page', 'admissions')
        : url('/');
    $piieCtaContact = $piieHasCta('website.page') && in_array('contact-us', $piieSlugs ?? [], true)
        ? route('website.page', 'contact-us')
        : url('/');
    $piieCtaApply = $piieHasCta('apply.form') ? route('apply.form') : $piieCtaAdmissions;
@endphp

<section class="piie-section piie-section--tight piie-cta" aria-labelledby="{{ $id ?? 'piie-cta-title' }}">
    <div class="piie-wrap">
        <div class="piie-head-center">
            <h2 id="{{ $id ?? 'piie-cta-title' }}">
                {{ $title ?? 'Your future starts at PIIE' }}
            </h2>
            @if(! empty($lede))
                <p class="piie-lede">{!! $lede !!}</p>
            @endif
        </div>

        <div class="piie-btn-row" style="justify-content:center;margin-top:2rem;">
            <a class="piie-btn piie-btn--primary" href="{{ $piieCtaProgrammes }}">Explore Programmes</a>
            <a class="piie-btn piie-btn--primary-alt" href="{{ $piieCtaApply }}">Apply Now</a>
            <a class="piie-btn piie-btn--ghost-light" href="{{ $piieCtaContact }}">Contact Admissions</a>
        </div>
    </div>
</section>