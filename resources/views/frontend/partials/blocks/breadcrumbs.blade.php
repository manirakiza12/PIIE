{{--
    BREADCRUMBS.

    A real <nav> with an accessible name and an ordered list, because a row of
    links separated by slashes tells a screen-reader user nothing about structure.

    $crumbs is an ordered list of ['label' => '...', 'url' => '...'|'null'].
    The final crumb has no link: the current page is not a link to itself.
--}}
@if(! empty($crumbs))
    <nav class="piie-breadcrumbs" aria-label="Breadcrumb">
        <ol>
            @foreach($crumbs as $crumb)
                <li>
                    @if(! empty($crumb['url']) && ! $loop->last)
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                        <span class="piie-breadcrumbs__sep" aria-hidden="true">/</span>
                    @else
                        <span aria-current="page">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif