{{--
    Shared front+back ID card body — used by the student, admin, and parent
    "My/Student ID Card" views, and the student's downloadable PDF. Kept
    dompdf-safe (table layout, no flexbox/grid) so the exact same markup
    renders identically on-screen and in the PDF. Landscape (CR80 card
    ratio, 85.60mm × 53.98mm, ~1.586:1) per ISO ID-1 standard.

    Expects: $school (App\Models\School|null), $studentDetails (array, from
    CommonController::get_student_details_by_id()), $programme
    (App\Models\Programme|null), $cardNumber (string), $validFor
    (string|null), $qrDataUri (string), $staffCard (bool), $staffPosition (string|null),
    $staffDepartment (string|null), $cardLogoSrc (string|null).
    Signature images: $staffSignaturePath, $studentSignaturePath (for PDF data URIs).
--}}
@php($staffCard = $staffCard ?? false)
@php($isPdf = $isPdf ?? false)
@php($staffSignatureSrc = $staffSignatureSrc ?? '')
@php($studentSignatureSrc = $studentSignatureSrc ?? '')
@php($photoSrc = $studentDetails['photo'] ?? '')
<style>
    /* CR80 card: 85.60mm × 53.98mm = 3.37" × 2.125" at 300 DPI = 1011px × 638px */
    /* Web preview at 96 DPI: 324px × 204px; we use 380px width for readability */
    .idc-wrap { display: table; margin: 0 auto; }
    .idc-card {
        display: inline-block;
        vertical-align: top;
        width: 380px;
        height: 240px;
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
        border: 1px solid #d8dce3;
        box-shadow: 0 4px 12px rgba(17, 24, 39, 0.06);
        margin: 0 10px 20px 10px;
        page-break-inside: avoid;
    }
    @media print {
        .idc-card { width: 3.37in; height: 2.125in; margin: 0; box-shadow: none; border: none; }
        .idc-card:first-child { page-break-after: always; }
    }
    .idc-header {
        background: #0f6e6e;
        background-image: linear-gradient(135deg, #0f6e6e, #114f52);
        padding: 8px 12px;
        color: #fff;
        height: 48px;
    }
    .idc-header-table { width: 100%; height: 100%; }
    .idc-logo {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #fff;
        text-align: center;
        overflow: hidden;
        flex-shrink: 0;
    }
    .idc-logo img { width: 30px; height: auto; margin-top: 3px; }
    .idc-school-name { font-size: 11.5px; font-weight: 700; letter-spacing: .02em; padding-left: 8px; line-height: 1.2; }
    .idc-card-type { font-size: 8px; letter-spacing: .1em; opacity: .9; text-align: right; font-weight: 600; }

    .idc-body-table { width: 100%; height: calc(100% - 48px - 42px); padding: 10px 12px 6px 12px; }
    .idc-photo-cell { width: 78px; vertical-align: top; padding-top: 2px; }
    .idc-photo {
        width: 70px;
        height: 88px;
        border-radius: 6px;
        border: 2px solid #eceef2;
        object-fit: cover;
        object-position: center top;
        background: #eceef2;
    }
    .idc-photo-placeholder {
        width: 70px;
        height: 88px;
        border-radius: 6px;
        border: 2px dashed #d8dce3;
        background: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9aa0ad;
        font-size: 9px;
    }
    .idc-details-cell { vertical-align: top; padding-left: 10px; }
    .idc-name { font-size: 13px; font-weight: 700; color: #181c32; margin: 0 0 1px 0; line-height: 1.25; }
    .idc-code { font-size: 9.5px; color: #6a7180; margin-bottom: 5px; font-family: monospace; }

    .idc-fields { font-size: 9.5px; color: #303546; width: 100%; }
    .idc-field-row td { padding: 1.5px 0; border-bottom: 1px dashed #e5e7eb; }
    .idc-field-label { display: inline-block; width: 62px; color: #8a8f9c; font-weight: 600; }
    .idc-field-value { font-weight: 600; color: #181c32; }

    .idc-footer-table { width: 100%; padding: 6px 12px 8px 12px; border-top: 1px solid #eceef2; height: 42px; }
    .idc-card-number { font-size: 9px; color: #6a7180; font-family: monospace; }
    .idc-valid-for { font-size: 8.5px; color: #6a7180; margin-top: 1px; }
    .idc-qr { width: 42px; height: 42px; }

    .idc-back-header { background: #f3f5f8; padding: 7px 12px; text-align: center; border-bottom: 1px solid #e5e7eb; height: 34px; }
    .idc-back-header b { font-size: 10.5px; color: #181c32; line-height: 1.3; }
    .idc-back-body { padding: 9px 12px; font-size: 8.5px; color: #4b5060; line-height: 1.4; height: calc(100% - 34px - 38px); display: flex; flex-direction: column; justify-content: space-between; }
    .idc-terms { margin: 0 0 8px 0; line-height: 1.45; }
    .idc-institution-info { margin-bottom: 8px; font-size: 8.5px; }
    .idc-signature-table { width: 100%; margin-top: auto; }
    .idc-signature-cell { width: 46%; text-align: center; font-size: 8.5px; border-top: 1px solid #9aa0ad; padding-top: 4px; position: relative; min-height: 36px; }
    .idc-signature-img { max-width: 120px; max-height: 28px; margin-bottom: 2px; opacity: 0.9; }
    .idc-back-footer { padding: 5px 12px 8px 12px; font-size: 7.5px; color: #8a8f9c; text-align: center; border-top: 1px solid #eceef2; line-height: 1.3; height: 38px; }
</style>

<div class="idc-wrap">
    {{-- Front --}}
    <div class="idc-card">
        <div class="idc-header">
            <table class="idc-header-table"><tr>
                <td style="width: 36px; vertical-align: middle;">
                    <div class="idc-logo">
                        @if(!empty($cardLogoSrc))
                            <img src="{{ $cardLogoSrc }}" alt="">
                        @elseif(!empty($school?->school_logo))
                            <img src="{{ asset('assets/uploads/school_logo/' . $school->school_logo) }}" alt="">
                        @else
                            <img src="{{ asset('assets/images/id_logo.png') }}" alt="">
                        @endif
                    </div>
                </td>
                <td class="idc-school-name" style="vertical-align: middle;">{{ $school->title ?? get_phrase('School') }}</td>
                <td class="idc-card-type" style="vertical-align: middle;">{{ get_phrase($staffCard ? 'STAFF ID' : 'STUDENT ID') }}</td>
            </tr></table>
        </div>

        <table class="idc-body-table"><tr>
            <td class="idc-photo-cell" style="text-align: center;">
                @if(!empty($photoSrc))
                    <img class="idc-photo" src="{{ $photoSrc }}" alt="{{ get_phrase('Photo') }}">
                @else
                    <div class="idc-photo-placeholder">{{ get_phrase('No Photo') }}</div>
                @endif
            </td>
            <td class="idc-details-cell">
                <div class="idc-name">{{ $studentDetails['name'] }}</div>
                <div class="idc-code">{{ null_checker($studentDetails['code']) }}</div>

                <table class="idc-fields">
                    @if($staffCard)
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Position') }}</span><span class="idc-field-value">{{ $staffPosition }}</span></td></tr>
                        @if($staffDepartment)
                            <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Department') }}</span><span class="idc-field-value">{{ $staffDepartment }}</span></td></tr>
                        @endif
                    @elseif($programme)
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Programme') }}</span><span class="idc-field-value">{{ $programme->name }}</span></td></tr>
                    @else
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Class') }}</span><span class="idc-field-value">{{ empty($studentDetails['class_name']) ? get_phrase('Not assigned') : $studentDetails['class_name'] }}</span></td></tr>
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Section') }}</span><span class="idc-field-value">{{ empty($studentDetails['section_name']) ? get_phrase('Not assigned') : $studentDetails['section_name'] }}</span></td></tr>
                    @endif
                    <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Contact') }}</span><span class="idc-field-value">{{ \App\Support\IdCard::INSTITUTION_PHONE }}</span></td></tr>
                </table>
            </td>
        </tr></table>

        <table class="idc-footer-table"><tr>
            <td style="text-align: left; vertical-align: middle;">
                <div class="idc-card-number">{{ $cardNumber }}</div>
                @if($validFor)
                    <div class="idc-valid-for">{{ get_phrase('Valid for') }} {{ $validFor }}</div>
                @endif
            </td>
            <td style="text-align: right; width: 50px; vertical-align: middle;">
                <img class="idc-qr" src="{{ $qrDataUri }}" alt="{{ get_phrase('Scan to verify') }}">
            </td>
        </tr></table>
    </div>

    {{-- Back --}}
    <div class="idc-card" style="page-break-before: always;">
        <div class="idc-back-header"><b>{{ get_phrase('Terms & Emergency Information') }}</b></div>
        <div class="idc-back-body">
            <p class="idc-terms">
                {{ str_replace(':school', $school->title ?? get_phrase('the school'), get_phrase('This card is the property of :school and must be carried at all times on campus. It is non-transferable. If found, please return it to the school administration office.')) }}
            </p>
            <div class="idc-institution-info">
                <b>{{ get_phrase('Institution') }}:</b> {{ \App\Support\IdCard::INSTITUTION_PHONE }}
                @if(!empty($school?->email)) <br>{{ get_phrase('Email') }}: {{ $school->email }} @endif
            </div>

            <table class="idc-signature-table"><tr>
                <td class="idc-signature-cell">
                    @if($staffCard && !empty($staffSignatureSrc))
                        <img class="idc-signature-img" src="{{ $staffSignatureSrc }}" alt="{{ get_phrase('Authorized Signature') }}">
                        <br>
                    @elseif(!$staffCard && !empty($studentSignatureSrc))
                        <img class="idc-signature-img" src="{{ $studentSignatureSrc }}" alt="{{ get_phrase('Authorized Signature') }}">
                        <br>
                    @endif
                    {{ get_phrase('Authorized Signature') }}
                </td>
                <td style="width: 8%;"></td>
                <td class="idc-signature-cell">
                    {{ get_phrase($staffCard ? 'Staff Signature' : 'Student Signature') }}
                </td>
            </tr></table>
        </div>
        <div class="idc-back-footer">
            {{ $school->address ?? '' }}
            &middot; {{ \App\Support\IdCard::INSTITUTION_PHONE }}
            @if(!empty($school?->email)) · {{ $school->email }} @endif
        </div>
    </div>
</div>