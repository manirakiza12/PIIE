<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ get_phrase('ID Card') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2933; margin: 28px; }
        .card { width: 380px; border: 1px solid #d8dce3; border-radius: 14px; overflow: hidden; margin: 0 auto 24px auto; }
        .header { background-color: #0f6e6e; padding: 8px 14px; color: #fff; }
        table.header-table { width: 100%; }
        img.logo { width: 30px; height: auto; border-radius: 50%; background: #fff; padding: 2px; }
        .school-title { font-size: 12.5px; font-weight: bold; padding-left: 8px; }
        .card-type { font-size: 9px; text-align: right; }
        table.body-table { width: 100%; padding: 12px 14px 4px 14px; }
        .photo-cell { width: 84px; vertical-align: top; }
        img.photo { border-radius: 10px; width: 74px; height: 74px; object-fit: cover; border: 2px solid #eceef2; }
        .details-cell { vertical-align: top; padding-left: 12px; }
        .name { font-size: 14px; font-weight: bold; margin: 0 0 1px 0; }
        .code { font-size: 10px; color: #6a7180; margin-bottom: 6px; }
        table.details { width: 100%; font-size: 10px; }
        table.details td { padding: 2px 0; border-bottom: 1px dashed #e5e7eb; }
        table.details td.label { color: #8a8f9c; width: 66px; font-weight: bold; }
        table.footer-table { width: 100%; padding: 8px 14px 10px 14px; border-top: 1px solid #eceef2; }
        .footer-left { font-size: 9px; color: #6a7180; text-align: left; }
        .qr { width: 44px; height: 44px; }
        .back-card { border: 1px solid #d8dce3; border-radius: 14px; width: 380px; margin: 0 auto; overflow: hidden; }
        .back-header { background-color: #f3f5f8; padding: 8px; font-size: 11px; font-weight: bold; text-align: center; border-bottom: 1px solid #e5e7eb; }
        .back-body { padding: 10px 16px; font-size: 9.5px; color: #4b5060; line-height: 1.45; }
        .signature-table { width: 100%; margin-top: 18px; font-size: 9.5px; text-align: center; }
        .signature-table td { border-top: 1px solid #9aa0ad; padding-top: 4px; }
        .back-footer { font-size: 8.5px; color: #8a8f9c; text-align: center; padding: 6px 16px 12px 16px; }
    </style>
</head>
<body>
    @php
        $schoolData = App\Models\School::where('id', auth()->user()->school_id)->first();
        $logoPath = !empty($schoolData->school_logo)
            ? public_path('assets/uploads/school_logo/' . $schoolData->school_logo)
            : public_path('assets/images/id_logo.png');
        $photoPath = str_replace(asset(''), public_path(''), (string) $student_details['photo']);
    @endphp
    <div class="card">
        <div class="header">
            <table class="header-table"><tr>
                <td style="width: 30px;">
                    @if(file_exists($logoPath))
                        <img class="logo" src="{{ $logoPath }}">
                    @endif
                </td>
                <td class="school-title">{{ optional($schoolData)->title }}</td>
                <td class="card-type">{{ get_phrase('STUDENT ID') }}</td>
            </tr></table>
        </div>

        <table class="body-table"><tr>
            <td class="photo-cell">
                @if(file_exists($photoPath))
                    <img class="photo" src="{{ $photoPath }}">
                @endif
            </td>
            <td class="details-cell">
                <div class="name">{{ $student_details['name'] }}</div>
                <div class="code">{{ null_checker($student_details['code']) }}</div>

                <table class="details">
                    @if($programme)
                        <tr><td class="label">{{ get_phrase('Programme') }}</td><td>{{ $programme->name }}</td></tr>
                    @else
                        <tr><td class="label">{{ get_phrase('Class') }}</td><td>{{ null_checker($student_details['class_name']) }}</td></tr>
                        <tr><td class="label">{{ get_phrase('Section') }}</td><td>{{ null_checker($student_details['section_name']) }}</td></tr>
                    @endif
                    <tr><td class="label">{{ get_phrase('Blood') }}</td><td>{{ null_checker(strtoupper((string) $student_details['blood_group'])) }}</td></tr>
                    <tr><td class="label">{{ get_phrase('Contact') }}</td><td>{{ \App\Support\IdCard::INSTITUTION_PHONE }}</td></tr>
                </table>
            </td>
        </tr></table>

        <table class="footer-table"><tr>
            <td class="footer-left">
                {{ $cardNumber }}<br>
                @if($validFor){{ get_phrase('Valid for') }} {{ $validFor }}@endif
            </td>
            <td style="text-align: right; width: 50px;">
                <img class="qr" src="{{ $qrDataUri }}">
            </td>
        </tr></table>
    </div>

    <div class="back-card">
        <div class="back-header">{{ get_phrase('Terms & Emergency Information') }}</div>
        <div class="back-body">
            {{ str_replace(':school', optional($schoolData)->title ?: get_phrase('the school'), get_phrase('This card is the property of :school and must be carried at all times on campus. It is non-transferable. If found, please return it to the school administration office.')) }}
            <br><br>
            <b>{{ get_phrase('Institution contact') }}:</b> {{ \App\Support\IdCard::INSTITUTION_PHONE }}

            <table class="signature-table">
                <tr>
                    <td style="width: 45%;">{{ get_phrase('Student Signature') }}</td>
                    <td style="width: 10%; border-top: none;"></td>
                    <td style="width: 45%;">{{ get_phrase('Authorized Signature') }}</td>
                </tr>
            </table>
        </div>
        <div class="back-footer">
            {{ optional($schoolData)->address }}
            &middot; {{ \App\Support\IdCard::INSTITUTION_PHONE }}
            @if(!empty($schoolData?->email)) · {{ $schoolData->email }} @endif
        </div>
    </div>
</body>
</html>
