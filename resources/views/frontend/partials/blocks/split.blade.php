{{--
    IMAGE / TEXT SPLIT — the workhorse for alternating photography and prose.

    USE
        @include('frontend.partials.blocks.split', [
            'image'  => $photo,
            'alt'    => 'Students collaborating',
            'title'  => 'Why Choose PIIE',
            'html'   => $paragraphsHtml,
            'items'  => $benefits,     // optional list of strings
            'link'   => ['url' => $url, 'text' => 'Read more'],
            'reverse'=> true,           // image on the right
        ])

    WHY ONE PARTIAL
        The old inner pages alternated by hand with two different grid structures,
        so a wide screen got a narrow column on one section and a full-width one on
        the next. `minmax(0, 1fr)` on both columns plus a 1280px measure makes the
        halves identical at every width.
--}}
<section class="piie-section @if(! empty($alt)) piie-section--alt @endif @if(! empty($tight)) piie-section--tight @endif"
         @if(! empty($id)) id="{{ $id }}" @endif>
    <div class="piie-wrap piie-split {{ ! empty($reverse) ? 'piie-split--reverse' : '' }}">

        <div class="piie-split__media">
            @if(! empty($image))
                <img src="{{ $image }}" alt="{{ $alt ?? '' }}"
                     loading="lazy" decoding="async">
            @endif
        </div>

        <div>
            @if(! empty($eyebrow))
                <span class="piie-eyebrow">{{ $eyebrow }}</span>
            @endif

            @if(! empty($title))
                <h2>{{ $title }}</h2>
            @endif

            @if(! empty($html))
                {!! $html !!}
            @endif

            @if(! empty($items) && count($items))
                <ul class="piie-list" style="margin-top:1.5rem;">
                    @foreach($items as $item)
                        <li>
                            <span class="piie-list__mark" aria-hidden="true">
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="M2 6.5L4.5 9L10 3.5" stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span>{!! $item !!}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(! empty($link) && ! empty($link['url']))
                <div class="piie-btn-row" style="margin-top:1.75rem;">
                    <a class="piie-btn piie-btn--primary" href="{{ $link['url'] }}">
                        {{ $link['text'] ?? 'Read more' }}
                    </a>
                </div>
            @endif
        </div>
    </div>
</section>