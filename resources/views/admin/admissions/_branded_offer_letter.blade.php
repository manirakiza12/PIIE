@php
    $letter = $letter ?? \App\Support\Admissions\AdmissionLetterDocument::forAdmission($admission);
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Admission Letter - {{ $letter['name'] }}</title>
<style>
@page { margin:108px 42px 64px; }
body { font-family:DejaVu Sans,sans-serif; font-size:10.5px; line-height:1.55; color:#253246; }
.letterhead { position:fixed; top:-85px; left:0; right:0; border-bottom:2px solid #1863a0; padding-bottom:10px; }
.letterhead table { width:100%; border-collapse:collapse; }
.logo { width:68px; height:68px; }
.institution { font-size:16px; line-height:1.25; color:#175d98; font-weight:bold; text-transform:uppercase; }
.contact { font-size:8px; color:#536176; line-height:1.5; margin-top:5px; }
.office { font-size:10px; letter-spacing:1px; color:#c9571a; font-weight:bold; margin-top:5px; }
.watermark { position:fixed; top:165px; left:75px; width:360px; opacity:0.055; z-index:-100; }
.footer { position:fixed; bottom:-45px; left:0; right:0; border-top:1px solid #cdd8e5; padding-top:8px; font-size:8px; color:#536176; text-align:center; }
.page-number:before { content:counter(page); }
.meta { width:100%; border-collapse:collapse; margin:8px 0 16px; }
.meta td { padding:5px 7px; border-bottom:1px solid #e0e7ef; }
.label { color:#536176; }
h1 { font-size:12px; text-transform:uppercase; color:#175d98; padding:9px 0; border-top:1px solid #cdd8e5; border-bottom:1px solid #cdd8e5; margin:12px 0; }
h2 { color:#175d98; font-size:11px; text-transform:uppercase; margin:18px 0 8px; }
p { margin:8px 0; } ul { padding-left:20px; margin:7px 0; } li { margin:4px 0; }
.notice { border-left:3px solid #ee712b; padding:8px 12px; background:#f4f7fb; }
.fees-page { page-break-before:always; }
.fees { width:100%; border-collapse:collapse; margin:10px 0; }
.fees th { background:#175d98; color:#fff; text-align:left; padding:8px; font-size:10px; }
.fees td { border-bottom:1px solid #dfe6ef; padding:7px 8px; font-size:10px; }
.fees tr { page-break-inside:avoid; } .amount { text-align:right; white-space:nowrap; }
.signature { margin-top:24px; } .small { font-size:9px; color:#536176; }
a { color:#175d98; text-decoration:none; }
</style></head><body>
<div class="letterhead"><table><tr>
@if($letter['logo'])<td style="width:80px"><img class="logo" src="{{ $letter['logo'] }}" alt="PIIE crest"></td>@endif
<td><div class="institution">{{ $letter['institution'] }}</div>
<div class="contact">@if($letter['address']){{ $letter['address'] }} | @endif{{ $letter['email'] }}@if($letter['website']) | {{ $letter['website'] }}@endif</div>
<div class="office">OFFICE OF THE ACADEMIC REGISTRAR</div></td>
</tr></table></div>
@if($letter['logo'])<img class="watermark" src="{{ $letter['logo'] }}" alt="">@endif
<div class="footer">{{ $letter['institution'] }}<br>@if($letter['phone']){{ $letter['phone'] }} | @endif{{ $letter['email'] }}@if($letter['website']) | {{ $letter['website'] }}@endif<br>{{ $letter['application_number'] }} | Page <span class="page-number"></span></div>
<table class="meta">
<tr><td class="label">Name</td><td><strong>{{ $letter['name'] }}</strong></td><td class="label">Date of issue</td><td>{{ $letter['issued_on'] }}</td></tr>
<tr><td class="label">Nationality</td><td>{{ $letter['nationality'] ?: 'As recorded in your application' }}</td><td class="label">Application no.</td><td>{{ $letter['application_number'] }}</td></tr>
<tr><td class="label">Registration no.</td><td colspan="3"><strong>{{ $letter['registration_number'] ?: 'Assigned upon enrolment' }}</strong></td></tr>
</table>
@if(!empty($letter['sample']))<p class="notice small">SAMPLE DOCUMENT. Student details, dates and fees are synthetic. Issued letters use the enrolled student's records.</p>@endif
<p>Dear {{ $letter['name'] }},</p>
<h1>Provisional Admission Offer - {{ $letter['programme'] }} @if($letter['programme_code'])({{ $letter['programme_code'] }})@endif</h1>
<p>We are pleased to inform you that, following consideration of your application, the Admissions Office at <strong>{{ $letter['institution'] }}</strong> offers you provisional admission to the <strong>{{ $letter['programme'] }}</strong> programme on a <strong>{{ $letter['mode'] }}</strong> basis.</p>
<p>@if($letter['duration'])The programme duration is <strong>{{ $letter['duration'] }}</strong>. @endif @if($letter['intake'])Your entry intake is <strong>{{ $letter['intake'] }}</strong>.@endif</p>
@if($letter['start_date'])<p>The entry semester commences on <strong>{{ $letter['start_date'] }}</strong>.</p>@endif
@if($letter['orientation_start_date'])<p>Orientation is scheduled for <strong>{{ $letter['orientation_start_date'] }}</strong>@if($letter['orientation_end_date']) to <strong>{{ $letter['orientation_end_date'] }}</strong>@endif.</p>@endif
@if($letter['registration_deadline'])<p>Please complete registration by <strong>{{ $letter['registration_deadline'] }}</strong>. Contact the Academic Registrar promptly if you require assistance.</p>@else<p>The Academic Registrar will confirm your registration deadline and any commencement or orientation dates not stated in this letter through the student portal.</p>@endif
<h2>Conditions of admission and registration</h2>
<p>This offer is provisional and based on the qualifications declared in your application. Admission remains subject to verification of your identity and original academic documents. Present the documents requested by the Admissions Office, including:</p>
<ul><li>Original academic certificates and transcripts relevant to your entry qualification, including UCE/UACE or equivalent where applicable, and certified copies as requested.</li><li>A valid national identity card or passport.</li><li>Recent passport-size photographs and any additional programme-specific documents requested by the Academic Registrar.</li></ul>
<p>Please ensure all information and documents supplied are complete and accurate. Impersonation, falsified documents or materially false information may lead to withdrawal of this offer under the Institute's applicable policies.</p>
@if($letter['decision_note'])<div class="notice"><strong>Admissions Office note:</strong> {{ $letter['decision_note'] }}</div>@endif
<p>Please complete the required registration for each semester and follow the programme's academic progression requirements.</p>
<div class="fees-page">
<h2>Fees payment and structure</h2>
<p>Use your student portal to view the invoices issued to you and the payment options made available by the Institute: <a href="{{ $letter['portal_url'] }}">{{ $letter['portal_url'] }}</a>. Your application fee is separate from tuition and other programme charges.</p>
@if(count($letter['fees']))
<table class="fees"><thead><tr><th>Fee or charge</th><th>Payment basis</th><th style="text-align:right">Amount</th></tr></thead><tbody>
@foreach($letter['fees'] as $fee)<tr><td>{{ $fee['name'] }}@unless($fee['mandatory']) <span class="small">(where applicable)</span>@endunless</td><td>{{ $fee['basis'] }}</td><td class="amount">{{ $fee['currency'] ?? $letter['currency'] }} {{ number_format((float) $fee['amount'], 2) }}</td></tr>@endforeach
</tbody></table><p class="small">The figures above reflect the fee structure recorded when this letter was issued. Refer to your student portal and the Finance Office for applicable invoices, payment instructions and current fee policies.</p>
@else<div class="notice">The Finance Office will provide your applicable tuition and other fee structure. Please consult your student portal before making a payment.</div>@endif
<h2>Preparing for your studies</h2>
@if(str_contains(strtolower($letter['mode']), 'online') || str_contains(strtolower($letter['mode']), 'blended'))<p>You will need a suitable smartphone, tablet or laptop and a reliable internet connection to participate in online learning. Use the student portal for your course access, academic updates and institutional communications.</p>@endif
<p>For enquiries about your admission, registration, documents or fees, contact the Academic Registrar through <a href="mailto:{{ $letter['email'] }}">{{ $letter['email'] }}</a>@if($letter['phone']) or {{ $letter['phone'] }}@endif.</p>
<p>On behalf of {{ $letter['institution'] }}, we congratulate you on your admission and wish you a rewarding and successful academic journey.</p>
<div class="signature"><p>Yours sincerely,</p>@if($letter['registrar_name'])<p><strong>{{ $letter['registrar_name'] }}</strong></p>@endif<p><strong>For the ACADEMIC REGISTRAR</strong></p></div>
<p class="small">This is a system-issued admission letter. Quote your application or registration number in correspondence with the Institute.</p>
</div></body></html>
