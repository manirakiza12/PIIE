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
@php
    $staffCard = $staffCard ?? false;
    $details = $studentDetails ?? $student_details ?? [];
    if ($details instanceof \Illuminate\Contracts\Support\Arrayable) {
        $details = $details->toArray();
    } elseif ($details instanceof \ArrayAccess) {
        $details = (array) $details;
    } elseif (is_object($details)) {
        $details = (array) $details;
    }
    $studentDetails = is_array($details) ? $details : [];
    $displayName = $studentDetails['name'] ?? '';
    $displayCode = $studentDetails['code'] ?? '';
    $displayPhoto = $studentDetails['photo'] ?? '';
    $displayClass = $studentDetails['class_name'] ?? '';
    $displaySection = $studentDetails['section_name'] ?? '';

    // Signature mapping: left = cardholder, right = institution authorized signatory.
    // Personal cardholder signatures are not configured in existing application data.
    $holderLabel = $holderSignatureLabel ?? ($staffCard ? get_phrase('Staff Signature') : get_phrase('Student Signature'));
    $authorizedLabel = $authorizedSignatureLabel ?? get_phrase('Authorized Signature');
    $holderSrc = $holderSignatureSrc ?? null;
    $authorizedSrc = $authorizedSignatureSrc ?? null;
@endphp
<style>
    .idc-wrap { display: table; margin: 0 auto; }
    .idc-card {
        display: inline-block;
        vertical-align: top;
        width: 380px;
        height: 240px;
        border-radius: 14px;
        overflow: hidden;
        background: #fff;
        border: 1px solid #d8dce3;
        box-shadow: 0 8px 24px rgba(17, 24, 39, 0.08);
        margin: 0 10px 20px 10px;
        page-break-inside: avoid;
        box-sizing: border-box;
    }
    @media print {
        @page { size: 85.60mm 53.98mm; margin: 0; }
        html, body { margin: 0 !important; padding: 0 !important; }
        .idc-wrap { display: block; margin: 0; }
        .idc-card { width: 3.37in; height: 2.125in; margin: 0; box-shadow: none; border: none; border-radius: 0; display: block; }
        .idc-card:first-child { page-break-after: always; }
    }
    .idc-header {
        background: #0f6e6e;
        background-image: linear-gradient(135deg, #0f6e6e, #114f52);
        padding: 7px 12px;
        color: #fff;
    }
    .idc-header-table { width: 100%; border-collapse: collapse; }
    .idc-logo {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: #fff;
        text-align: center;
        overflow: hidden;
    }
    .idc-logo img { width: 24px; height: 24px; margin-top: 3px; }
    .idc-school-name { font-size: 11.5px; font-weight: 700; letter-spacing: .02em; padding-left: 7px; line-height: 1.2; }
    .idc-card-type { font-size: 8.5px; letter-spacing: .1em; opacity: .95; text-align: right; white-space: nowrap; }

    .idc-body-table { width: 100%; padding: 10px 12px 2px 12px; border-collapse: collapse; }
    .idc-photo-cell { width: 78px; vertical-align: top; padding-top: 1px; }
    .idc-photo {
        width: 70px;
        height: 88px;
        border-radius: 8px;
        border: 1px solid #d8dce3;
        object-fit: cover;
        background: #eceef2;
        display: block;
    }
    .idc-details-cell { vertical-align: top; padding-left: 10px; }
    .idc-name { font-size: 13px; font-weight: 700; color: #111827; margin: 0 0 1px 0; line-height: 1.2; }
    .idc-code { font-size: 10px; color: #374151; margin-bottom: 5px; font-weight: 600; letter-spacing: .02em; }

    .idc-fields { font-size: 9.5px; color: #1f2937; width: 100%; border-collapse: collapse; }
    .idc-field-row td { padding: 1px 0 2px 0; border-bottom: 1px dashed #e5e7eb; vertical-align: top; }
    .idc-field-label { display: inline-block; width: 62px; color: #4b5563; font-weight: 600; }
    .idc-field-value { font-weight: 600; color: #111827; }

    .idc-footer-table { width: 100%; padding: 6px 12px 8px 12px; border-top: 1px solid #eceef2; border-collapse: collapse; }
    .idc-card-number { font-size: 8.8px; color: #374151; font-weight: 600; }
    .idc-valid-for { font-size: 8.5px; color: #4b5563; margin-top: 1px; }
    .idc-qr { width: 42px; height: 42px; }

    .idc-back-header { background: #f3f5f8; padding: 7px 12px; text-align: center; border-bottom: 1px solid #e5e7eb; }
    .idc-back-header b { font-size: 10.5px; color: #111827; }
    .idc-back-body { padding: 8px 14px; font-size: 9px; color: #374151; line-height: 1.35; }
    .idc-terms { margin: 0 0 6px 0; }
    .idc-signature-table { width: 100%; margin-top: 12px; border-collapse: collapse; table-layout: fixed; }
    .idc-signature-cell { width: 46%; text-align: center; vertical-align: top; font-size: 8.5px; color: #374151; padding: 0; }
    .idc-signature-area { height: 24px; border-bottom: 1px solid #6b7280; text-align: center; line-height: 24px; font-size: 0; overflow: hidden; }
    .idc-signature-img { display: inline-block; vertical-align: bottom; max-width: 100%; max-height: 23px; width: auto; height: auto; }
    .idc-signature-label { margin-top: 3px; font-size: 8.5px; line-height: 1.2; color: #374151; }
    .idc-back-footer { padding: 5px 14px 8px 14px; font-size: 8.2px; color: #4b5563; text-align: center; border-top: 1px solid #eceef2; }
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
                @if(!empty($displayPhoto))<img class="idc-photo" src="{{ $displayPhoto }}" alt="">@endif
            </td>
            <td class="idc-details-cell">
                <div class="idc-name">{{ $displayName }}</div>
                <div class="idc-code">{{ null_checker($displayCode) }}</div>

                <table class="idc-fields">
                    @if($staffCard)
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Position') }}</span><span class="idc-field-value">{{ $staffPosition ?? '' }}</span></td></tr>
                        @if(!empty($staffDepartment))
                            <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Department') }}</span><span class="idc-field-value">{{ $staffDepartment }}</span></td></tr>
                        @endif
                    @elseif($programme)
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Programme') }}</span><span class="idc-field-value">{{ $programme->name }}</span></td></tr>
                    @else
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Class') }}</span><span class="idc-field-value">{{ empty($displayClass) ? get_phrase('Not assigned') : $displayClass }}</span></td></tr>
                        <tr class="idc-field-row"><td><span class="idc-field-label">{{ get_phrase('Section') }}</span><span class="idc-field-value">{{ empty($displaySection) ? get_phrase('Not assigned') : $displaySection }}</span></td></tr>
                    @endif
                    @unless($staffCard)

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
    <div class="idc-card" style="page-break-before: always;">
        <div class="idc-back-header"><b>{{ get_phrase('Terms & Emergency Information') }}</b></div>
        <div class="idc-back-body">
            <p class="idc-terms">
                {{ str_replace(':school', $school->title ?? get_phrase('the school'), get_phrase('This card is the property of :school and must be carried at all times on campus. It is non-transferable. If found, please return it to the school administration office.')) }}
            </p>
            <table class="idc-fields">
                <tr class="idc-field-row"><td><span class="idc-field-label" style="width: 76px;">{{ get_phrase('Institution') }}</span><span class="idc-field-value">{{ \App\Support\IdCard::INSTITUTION_PHONE }}</span></td></tr>
            </table>

            <table class="idc-signature-table"><tr>
                <td class="idc-signature-cell">
                    <div class="idc-signature-area">@if(!empty($holderSrc))<img class="idc-signature-img" src="{{ $holderSrc }}" alt="{{ $holderLabel }}">@endif</div>
                    <div class="idc-signature-label">{{ $holderLabel }}</div>
                </td>
                <td style="width: 8%;"></td>
                <td class="idc-signature-cell">
                    <div class="idc-signature-area">@if(!empty($authorizedSrc))<img class="idc-signature-img" src="{{ $authorizedSrc }}" alt="{{ $authorizedLabel }}">@endif</div>
                    <div class="idc-signature-label">{{ $authorizedLabel }}</div>
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
