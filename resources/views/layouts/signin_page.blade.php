<!DOCTYPE html>
<html lang="en">

<head>

  <title>{{ get_phrase('Login').' | '.get_settings('system_title') }}</title>
  <!-- all the meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta content="" name="description" />
  <meta content="" name="author" />
  <!-- all the css files -->
  {{-- Favicon.
         `favicon` is UNSET on this installation, so the original expression
         concatenated an empty string and produced `.../assets/uploads/logo` - the
         bare directory, which 404s. `favicon.png` ships in the repository.

         Same rule as the sign-in logo: use the configured file only when it is set
         AND exists on disk, otherwise fall back. An unset setting can no longer
         produce a 404 on the sign-in page. Presentation only. --}}
  @php
      $piieFaviconSetting = trim((string) get_settings('favicon'));
      $piieFaviconFile = ($piieFaviconSetting !== '' && is_file(public_path('assets/uploads/logo/'.$piieFaviconSetting)))
          ? $piieFaviconSetting
          : 'favicon.png';
  @endphp
  <link rel="shortcut icon" href="{{ asset('assets/uploads/logo/'.$piieFaviconFile) }}" />
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}">

  <!--Custom css-->
  <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/main.css') }}">
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}">

  <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}">

  <!--Toaster css-->
  <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/toastr.min.css') }}"/>

</head>

<body>

    <div class="container-fluid h-100">
        @yield('content')
    </div>

    @include('external_plugin')

    <!--Main Jquery-->
    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>
    <!--Bootstrap bundle with popper-->
    <script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>

    <!--Custom Script-->
    <script src="{{ asset('assets/js/script.js') }}"></script>
    <script src="{{ asset('assets/js/custom.js') }}"></script>

    <!--Toaster Script-->
    <script src="{{ asset('assets/js/toastr.min.js') }}"></script>

    <script>
        
        "use strict";

        @if(Session::has('message'))
        toastr.options =
        {
            "closeButton" : true,
            "progressBar" : true
        }
                toastr.success("{{ session('message') }}");
        @endif

        @if(Session::has('error'))
        toastr.options =
        {
            "closeButton" : true,
            "progressBar" : true
        }
                toastr.error("{{ session('error') }}");
        @endif

        @if(Session::has('info'))
        toastr.options =
        {
            "closeButton" : true,
            "progressBar" : true
        }
                toastr.info("{{ session('info') }}");
        @endif

        @if(Session::has('warning'))
        toastr.options =
        {
            "closeButton" : true,
            "progressBar" : true
        }
                toastr.warning("{{ session('warning') }}");
        @endif
    </script>
</body>
</html>
