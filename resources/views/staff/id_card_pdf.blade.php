<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ get_phrase('Staff ID Card') }}</title>
    <style>
        @page { margin: 0; size: 85.60mm 53.98mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2933; margin: 0; padding: 0; }
        .idc-wrap { page-break-after: always; }
        .idc-card { page-break-inside: avoid; }
    </style>
</head>
<body>
    @php
        $staff = auth()->user();
        $school = \App\Models\School::find($staff->school_id);
        $staffCard = true;
        $isPdf = true;
        $staffSignatureSrc = asset('assets/signatures/Twinamatsiko Naboth.jpg');
        $studentSignatureSrc = asset('assets/signatures/academic registrar.jpg');
        $cardLogoSrc = !empty($school->school_logo)
            ? asset('assets/uploads/school_logo/' . $school->school_logo)
            : asset('assets/images/id_logo.png');
        $data = \App\Support\Staff\StaffIdCard::data($staff, true);
    @endphp
    @include('partials.id_card', array_merge($data, [
        'isPdf' => $isPdf,
        'staffSignatureSrc' => $staffSignatureSrc,
        'studentSignatureSrc' => $studentSignatureSrc,
        'cardLogoSrc' => $cardLogoSrc,
    ]))
</body>
</html>