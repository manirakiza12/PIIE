{{--
    SECTION HEADING — one heading treatment for every section on every page.

    USE
        @include('frontend.partials.blocks.section_heading', [
            'eyebrow' => 'Our history',
            'title'   => 'Origin and History',
            'lede'    => 'Optional sentence under the title.',
            'center'  => true,
        ])

    WHY A PARTIAL
        The homepage and the inner pages previously had four different heading
        treatments, which is most of why they read as two different websites. This
        is the only one.
--}}
<section class="piie-section @if(! empty($alt)) piie-section--alt @endif @if(! empty($tight)) piie-section--tight @endif"
         @if(! empty($id)) id="{{ $id }}" @endif
         @if(! empty($labelledby)) aria-labelledby="{{ $labelledby }}" @endif>
    <div class="piie-wrap">
        <div class="@if(! empty($center)) piie-head-center @endif">
            @if(! empty($eyebrow))
                <span class="piie-eyebrow">{{ $eyebrow }}</span>
            @endif

            <h2 @if(! empty($labelledby)) id="{{ $labelledby }}" @endif>{{ $title ?? '' }}</h2>

            @if(! empty($lede))
                <p class="piie-lede">{!! $lede !!}</p>
            @endif
        </div>
    </div>
</section>