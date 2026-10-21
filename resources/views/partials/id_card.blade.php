{{--
    Shared front+back ID card body — used by the student, admin, and parent
    "My/Student ID Card" views, and the student's downloadable PDF. Kept
    dompdf-safe (table layout, no flexbox/grid) so the exact same markup
    renders identically on-screen and in the PDF. Landscape (CR80 card
    ratio, ~1.586:1) rather than the earlier portrait "badge" shape, per
    explicit request.

    Expects: $school (App\Models\School|null), $studentDetails (array, from
    CommonController::get_student_details_by_id()), $programme
    (App\Models\Programme|null), $cardNumber (string), $validFor
    (string|null), $qrDataUri (string).
--}}
@php($staffCard = $staffCard ?? false)
<style>
    .idc-wrap { display: table; margin: 0 auto; }
    .idc-card {
        display: inline-block;
        vertical-align: top;
        width: 380px;
        border-radius: 14px;
        overflow: hidden;
        background: #fff;
        border: 1px solid #d8dce3;
        box-shadow: 0 8px 24px rgba(17, 24, 39, 0.08);
        margin: 0 10px 20px 10px;
    }
    .idc-header {
        background: #0f6e6e;
        background-image: linear-gradient(135deg, #0f6e6e, #114f52);
        padding: 8px 14px;
        color: #fff;
    }
    .idc-header-table { width: 100%; }
    .idc-logo {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #fff;
        text-align: center;
        overflow: hidden;
    }
    .idc-logo img { width: 28px; height: auto; margin-top: 3px; }
    .idc-school-name { font-size: 12.5px; font-weight: 700; letter-spacing: .02em; padding-left: 8px; }
    .idc-card-type { font-size: 9px; letter-spacing: .1em; opacity: .9; text-align: right; }

    .idc-body-table { width: 100%; padding: 12px 14px 4px 14px; }
    .idc-photo-cell { width: 84px; vertical-align: top; padding-top: 2px; }
    .idc-photo {
        width: 74px;
        height: 74px;
        border-radius: 10px;
        border: 2px solid #eceef2;
        object-fit: cover;
        background: #eceef2;
    }
    .idc-details-cell { vertical-align: top; padding-left: 12px; }
    .idc-name { font-size: 14.5px; font-weight: 700; color: #181c32; margin: 0 0 1px 0; }
    .idc-code { font-size: 10.5px; color: #6a7180; margin-bottom: 6px; }

    .idc-fields { font-size: 10.5px; color: #303546; width: 100%; }
    .idc-field-row td { padding: 1.5px 0; border-bottom: 1px dashed #e5e7eb; }
    .idc-field-label { display: inline-block; width: 66px; color: #8a8f9c; font-weight: 600; }
    .idc-field-value { font-weight: 600; }

    .idc-footer-table { width: 100%; padding: 8px 14px 10px 14px; border-top: 1px solid #eceef2; }
    .idc-card-number { font-size: 9.5px; color: #6a7180; }
    .idc-valid-for { font-size: 9.5px; color: #6a7180; margin-top: 2px; }
    .idc-qr { width: 46px; height: 46px; }

    .idc-back-header { background: #f3f5f8; padding: 8px 14px; text-align: center; border-bottom: 1px solid #e5e7eb; }
    .idc-back-header b { font-size: 11.5px; color: #181c32; }
    .idc-back-body { padding: 10px 16px; font-size: 9.8px; color: #4b5060; line-height: 1.45; }
    .idc-terms { margin: 0 0 8px 0; }
    .idc-signature-table { width: 100%; margin-top: 18px; }
    .idc-signature-cell { width: 46%; text-align: center; font-size: 9.5px; border-top: 1px solid #9aa0ad; padding-top: 4px; }
    .idc-back-footer { padding: 6px 16px 12px 16px; font-size: 9px; color: #8a8f9c; text-align: center; border-top: 1px solid #eceef2; }
</style>

<div class="idc-wrap">
    {{-- Front --}}
    <div class="idc-card">
        <div class="idc-header">
            <table class="idc-header-table"><tr>
                <td style="width: 34px;">
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
                <td class="idc-school-name">{{ $school->title ?? get_phrase('School') }}</td>
                <td class="idc-card-type">{{ get_phrase($staffCard ? 'STAFF ID' : 'STUDENT ID') }}</td>
            </tr></table>
        </div>

        <table class="idc-body-table"><tr>
            <td class="idc-photo-cell">
                @if(!empty($studentDetails['photo']))<img class="idc-photo" src="{{ $studentDetails['photo'] }}" alt="">@endif
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
                    @unless($staffCard)
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Blood') }}</span><span class="idc-field-value">{{ null_checker(strtoupper((string) $studentDetails['blood_group'])) }}</span></td></tr>
                    @endunless
                    <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Contact') }}</span><span class="idc-field-value">{{ \App\Support\IdCard::INSTITUTION_PHONE }}</span></td></tr>
                </table>
            </td>
        </tr></table>

        <table class="idc-footer-table"><tr>
            <td style="text-align: left;">
                <div class="idc-card-number">{{ $cardNumber }}</div>
                @if($validFor)
                    <div class="idc-valid-for">{{ get_phrase('Valid for') }} {{ $validFor }}</div>
                @endif
            </td>
            <td style="text-align: right; width: 50px;">
                <img class="idc-qr" src="{{ $qrDataUri }}" alt="{{ get_phrase('Scan to verify') }}">
            </td>
        </tr></table>
    </div>

    {{-- Back --}}
    <div class="idc-card">
        <div class="idc-back-header"><b>{{ get_phrase('Terms & Emergency Information') }}</b></div>
        <div class="idc-back-body">
            <p class="idc-terms">
                {{ str_replace(':school', $school->title ?? get_phrase('the school'), get_phrase('This card is the property of :school and must be carried at all times on campus. It is non-transferable. If found, please return it to the school administration office.')) }}
            </p>
            <table class="idc-fields">
                <tr class="idc-field-row"><td><span class="idc-field-label" style="width: 76px;">{{ get_phrase('Institution') }}</span><span class="idc-field-value">{{ \App\Support\IdCard::INSTITUTION_PHONE }}</span></td></tr>
            </table>

            <table class="idc-signature-table"><tr>
                <td class="idc-signature-cell">{{ get_phrase($staffCard ? 'Staff Signature' : 'Student Signature') }}</td>
                <td style="width: 8%;"></td>
                <td class="idc-signature-cell">{{ get_phrase('Authorized Signature') }}</td>
            </tr></table>
        </div>
        <div class="idc-back-footer">
            {{ $school->address ?? '' }}
            &middot; {{ \App\Support\IdCard::INSTITUTION_PHONE }}
            @if(!empty($school?->email)) · {{ $school->email }} @endif
        </div>
    </div>
</div>
