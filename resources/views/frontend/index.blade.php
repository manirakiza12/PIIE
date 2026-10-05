<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $websiteSeo->meta_title ?? 'PIIE - Prime International Institute of Excellence' }}</title>
    <meta name="description" content="{{ $websiteSeo->meta_description ?? 'Prime International Institute of Excellence (PIIE) - internationally benchmarked higher education through flexible, technology-enabled learning.' }}">
    <meta name="keywords" content="{{ $websiteSeo->meta_keywords ?? 'PIIE, Prime International Institute of Excellence, ODeL Uganda, higher education Uganda' }}">
    @if(!empty($websiteSeo) && !empty($websiteSeo->canonical_url))
        <link rel="canonical" href="{{ $websiteSeo->canonical_url }}">
    @else
        {{-- A canonical is emitted even when Super Admin has not set one: without it
             a page can be indexed under any URL that happens to reach it. --}}
        <link rel="canonical" href="{{ url()->current() }}">
    @endif

    {{-- Open Graph / Twitter.
         `frontend.index` is shared by FOUR views and only two of them pass
         $websiteSeo: `apply.blade.php` and `errors/404.blade.php` extend this
         layout without it. An undefined-variable access here took the Apply Now
         page to a 500, so every one of these is read through `?? null` FIRST -
         `??` suppresses a null value, not an undefined variable. --}}
    @php
        $piieSeo = $websiteSeo ?? null;
        $piieSeoSettings = $websiteSettings ?? collect();
        $piieSeoTitle = $piieSeo->meta_title ?? 'Prime International Institute of Excellence (PIIE)';
        $piieSeoDescription = $piieSeo->meta_description
            ?? ($piieSeoSettings['tagline'] ?? null)
            ?? 'Prime International Institute of Excellence (PIIE) - internationally benchmarked higher education through flexible, technology-enabled learning.';
        $piieSeoImage = asset('assets/uploads/logo/logo.png');
    @endphp

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Prime International Institute of Excellence (PIIE)">
    <meta property="og:title" content="{{ $piieSeoTitle }}">
    <meta property="og:description" content="{{ $piieSeoDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $piieSeoImage }}">
    <meta property="og:locale" content="en_UG">
    <meta name="twitter:card" content="summary_large_image">

    {{-- The redesign's design system. Loaded AFTER the existing stylesheets so it
         wins without editing them; the originals still apply to everything the
         redesign does not touch. --}}
    <link rel="stylesheet" href="{{ asset('css/piie-site.css') }}">
    <link rel="stylesheet" href="{{ asset('css/piie-blocks.css') }}">

    @include('frontend.include_top')

</head>

{{--
    `data-bs-spy="scroll"` is REMOVED here, and deliberately so.

    It pointed at `.header-area`, an element from the pre-redesign markup that no
    longer exists anywhere in the project — the header is now `.piie-header`. On a
    page short enough that the spy never activated, the bad selector was invisible;
    on a long CMS page it threw

        TypeError: Cannot read properties of null (reading 'classList')
            at ScrollSpy._activate (bootstrap.bundle.min.js)

    once per page load, found by rendering the site at every width and reading the
    console rather than by reading this template. Nothing consumed the spy: there
    are no `nav-link`s wired to a scroll target, so removing the attributes loses
    no behaviour and stops the exception.

    The redesigned header highlights its own section via `.piie-header.is-scrolled`,
    set in piie-hero.js. Reinstate a spy only alongside a real target selector AND
    something that consumes it.
--}}
<body tabindex="0">

    @yield('content')

    @include('external_plugin')
    
    @include('frontend.include_buttom')

    {{-- Hero video / navigation behaviour. `defer`, and after jQuery, so it never
         blocks the first render. The page is complete and readable without it. --}}
    <script src="{{ asset('js/piie-hero.js') }}" defer></script>
    
</body>
</html>