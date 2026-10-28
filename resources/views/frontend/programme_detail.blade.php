@extends('frontend.index')

@section('content')
<div class="piie-site">
    @include('frontend.partials.site_header')
    <main id="piie-main">
        @include('frontend.partials.blocks.page_hero', [
            'title' => $card['title'],
            'subtitle' => $card['faculty_label'],
            'image' => $card['image'],
            'eyebrow' => 'Academic Programme',
        ])
        <section class="piie-section">
            <div class="piie-wrap">
                <a href="{{ route('website.page', 'academic-programmes') }}">Back to programmes</a>
                <h2>Programme information</h2>
                <p>A Programme is your full qualification. Its subjects or modules are called Course Units and appear under My Courses in the student portal.</p>
                <dl>
                    @foreach(['level' => 'Award', 'mode' => 'Study mode', 'duration' => 'Duration', 'code' => 'Programme code'] as $key => $label)
                        @if(! empty($card[$key]))
                            <dt>{{ $label }}</dt><dd>{{ $card[$key] }}</dd>
                        @endif
                    @endforeach
                </dl>
                @if(! empty($description))
                    <div style="white-space:pre-line">{{ strip_tags((string) $description) }}</div>
                @endif
                @if($card['price'])
                    <p>{{ $card['price']['currency'] ?? '' }} {{ $card['price']['amount'] }} {{ $card['price']['basis'] ?? '' }}</p>
                @else
                    <p>{{ $card['contact_label'] }}</p>
                @endif
                <a class="piie-btn piie-btn--primary" href="{{ $applicationUrl }}">Apply for this programme</a>
                @if($card['link']['url'] !== $applicationUrl)
                    <a class="piie-btn piie-btn--ghost" href="{{ $card['link']['url'] }}">{{ $card['link']['text'] }}</a>
                @endif
            </div>
        </section>
        @if(! empty($galleryUrls))
            <section class="piie-section piie-section--alt" aria-labelledby="programme-gallery-title" data-testid="public-programme-gallery">
                <div class="piie-wrap">
                    <h2 id="programme-gallery-title">Programme gallery</h2>
                    <div class="piie-grid piie-grid--2">
                        @foreach($galleryUrls as $galleryUrl)
                            <img src="{{ $galleryUrl }}" alt="{{ $card['title'] }} — gallery image {{ $loop->iteration }}"
                                 width="1600" height="900" loading="lazy" decoding="async"
                                 style="width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;border-radius:8px;">
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </main>
    @include('frontend.partials.site_footer')
</div>
@endsection
