{{--
    Public-catalogue preview of ONE academic programme, for an administrator.

    WHY THIS RENDERS THE REAL CARD PARTIAL
    A preview built from its own markup is a preview of something other than what
    ships, and the drift stays invisible until after publication. This includes
    `frontend.partials.blocks.card` — the same partial, the same class names and
    the same two stylesheets the live catalogue loads — inside a container that
    matches the real catalogue grid, so what an administrator approves here is
    what a visitor sees.

    It reads the PROGRAMME record, not the CMS projection, so a preview works
    BEFORE publication. That is the point: publishing should be a decision made
    after seeing the result, not a leap of faith followed by a check.

    Rendered inside the admin chrome rather than the public header and footer on
    purpose. An administrator previewing a card needs the publish controls to
    hand; putting them on a public-looking page would invite a premature click.
--}}
@extends('layouts.app')

@push('public_design_styles')
    {{-- The two sheets the public catalogue loads, and nothing else, so the
         preview is measured against the real design rather than an approximation. --}}
    <link rel="stylesheet" href="{{ asset('css/piie-site.css') }}">
    <link rel="stylesheet" href="{{ asset('css/piie-blocks.css') }}">
@endpush

@section('content')
    <div class="pg programmes-preview">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">

                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                        <div>
                            <h3 class="mb-1">{{ get_phrase('Public catalogue preview') }}</h3>
                            <p class="text-muted mb-0" style="font-size:.875rem;max-width:64ch">
                                {{ get_phrase('This is exactly how the programme card will appear on the public website. Nothing is published until you choose to publish it.') }}
                            </p>
                        </div>

                        <div class="d-flex gap-2">
                            @if($programme->is_published)
                                <form method="POST" action="{{ route('admin.programmes.unpublish', $programme->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger">
                                        {{ get_phrase('Unpublish from website') }}
                                    </button>
                                </form>
                                <span class="badge bg-success align-self-center" style="font-size:.75rem">
                                    {{ get_phrase('Currently published') }}
                                </span>
                            @else
                                <form method="POST" action="{{ route('admin.programmes.publish', $programme->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">
                                        {{ get_phrase('Publish to website') }}
                                    </button>
                                </form>
                                <span class="badge bg-secondary align-self-center" style="font-size:.75rem">
                                    {{ get_phrase('Not published') }}
                                </span>
                            @endif
                            <a href="{{ route('admin.programmes.index') }}" class="btn btn-light align-self-center">
                                {{ get_phrase('Back to programmes') }}
                            </a>
                        </div>
                    </div>

                    {{-- The warning that matters most: an inactive programme cannot
                         be published, and saying so here is better than letting the
                         administrator press publish and get a refusal. --}}
                    @unless($programme->is_active)
                        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
                            <div class="flex-grow-1">
                                {{ get_phrase('This programme is deactivated, so it cannot be published. Activate it first.') }}
                            </div>
                        </div>
                    @endunless

                    {{--
                        A thin strip explaining the container width, because the
                        reviewer's question is always "does four-across still work
                        when a title wraps to three lines". The two side panels are
                        inert and are labelled so they are not mistaken for part of
                        the design.
                    --}}
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-1 d-none d-lg-block">
                            <p class="text-muted" style="font-size:.7rem;writing-mode:vertical-rl;margin:0 auto;">
                                {{ get_phrase('desktop: 4 columns') }}
                            </p>
                        </div>

                        <div class="col-lg-10">
                            {{-- Same grid class the live catalogue uses, so the card is
                                 measured at its real width. --}}
                            <div class="piie-catalogue__grid">
                                @include('frontend.partials.blocks.card', [
                                    'variant'  => 'programme',
                                    'title'    => $programme->name,
                                    'tag'      => $programme->level,
                                    'image'    => $coverUrl,
                                    'alt'      => $programme->name,
                                    'fallback' => $fallback,
                                    'excerpt'  => $programme->code
                                        ? \Illuminate\Support\Str::limit($programme->name, 130)
                                        : null,
                                    'price'        => $price,
                                    'contactLabel' => $contactLabel,
                                    'link'     => ['url' => '#', 'text' => get_phrase('View Programme')],
                                ])
                            </div>
                        </div>

                        <div class="col-lg-1 d-none d-lg-block">
                            <p class="text-muted" style="font-size:.7rem;writing-mode:vertical-rl;margin:0 auto;">
                                {{ get_phrase('tablet: 2 columns / mobile: 1 column') }}
                            </p>
                        </div>
                    </div>

                    {{-- What the card is NOT showing, stated plainly. An
                         administrator needs to know that a missing price is a
                         deliberate withholding rather than an oversight. --}}
                    <div class="card mt-4" style="max-width:70ch">
                        <div class="card-body">
                            <h6 class="card-title" style="font-size:.95rem">
                                {{ get_phrase('What the public sees') }}
                            </h6>
                            <ul class="mb-0" style="font-size:.875rem;line-height:1.6">
                                <li>
                                    {{ get_phrase('Price') }}:
                                    <strong>
                                        @if($price)
                                            {{ $price['currency'] }} {{ $price['amount'] }}
                                            — {{ $price['basis'] }}
                                        @else
                                            {{ get_phrase('Not shown') }} —
                                            {{ \App\Support\ProgrammeCatalogue\ProgrammePrice::contactLabel() }}
                                            @if(\App\Support\ProgrammeCatalogue\ProgrammePrice::amount($programme) !== null
                                                && \App\Support\ProgrammeCatalogue\ProgrammePrice::basis($programme) === null)
                                                <span class="text-muted">
                                                    ({{ get_phrase('an amount is set but no fee basis, so the website will not guess one') }})
                                                </span>
                                            @endif
                                        @endif
                                    </strong>
                                </li>
                                <li>
                                    {{ get_phrase('Image') }}:
                                    <strong>
                                        @if($coverUrl)
                                            {{ get_phrase('Approved cover photograph') }}
                                        @else
                                            {{ get_phrase('Branded fallback panel') }} —
                                            {{ get_phrase('no stock photograph is invented') }}
                                        @endif
                                    </strong>
                                </li>
                                <li>
                                    {{ get_phrase('Website status') }}:
                                    <strong>
                                        {{ $programme->is_published ? get_phrase('Published') : get_phrase('Not published') }}
                                    </strong>
                                </li>
                            </ul>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
@endsection