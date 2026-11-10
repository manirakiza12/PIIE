<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ get_phrase('ID Card') }}</title>
    <style>
        @page { margin: 0; size: 85.60mm 53.98mm; }
        body { font-family: DejaVu Sans, sans-serif; color: #1f2933; margin: 0; padding: 0; }
        .idc-wrap { page-break-after: always; }
        .idc-card { page-break-inside: avoid; }
    </style>
</head>
<body>
    @php
        $schoolData = App\Models\School::where('id', auth()->user()->school_id)->first();
        $staffCard = false;
        $isPdf = true;
        $staffSignatureSrc = asset('assets/signatures/Twinamatsiko Naboth.jpg');
        $studentSignatureSrc = asset('assets/signatures/academic registrar.jpg');
        $cardLogoSrc = !empty($schoolData->school_logo)
            ? asset('assets/uploads/school_logo/' . $schoolData->school_logo)
            : asset('assets/images/id_logo.png');
    @endphp
    @include('partials.id_card', [
        'school' => $schoolData,
        'studentDetails' => $student_details,
        'programme' => $programme,
        'cardNumber' => $cardNumber,
        'validFor' => $validFor,
        'qrDataUri' => $qrDataUri,
        'staffCard' => $staffCard,
        'staffPosition' => null,
        'staffDepartment' => null,
        'cardLogoSrc' => $cardLogoSrc,
        'isPdf' => $isPdf,
        'staffSignatureSrc' => $staffSignatureSrc,
        'studentSignatureSrc' => $studentSignatureSrc,
    ])
</body>
</html>