<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ get_phrase('Student ID Card') }}</title>
    <style>
        @page {
            size: 85.60mm 53.98mm;
            margin: 0;
        }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; padding: 0; }
    </style>
</head>
<body>
    @include('partials.id_card')
</body>
</html>
