@php($studyFeeStatus = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->summary(auth()->user()))
<div class="alert alert-{{ $studyFeeStatus['eligible'] ? 'success' : 'warning' }}" role="status">
<strong>Study eligibility: {{ $studyFeeStatus['eligible'] ? 'Eligible' : '20% payment required' }}</strong>
@if($studyFeeStatus['waived'])
<p class="mb-0">The 20% requirement is waived for your current fee session. Your school fee balance remains payable.</p>
@elseif($studyFeeStatus['total_minor'] > 0)
<p class="mb-0">Paid {{ number_format($studyFeeStatus['paid_minor'] / 100, 2) }} of {{ number_format($studyFeeStatus['total_minor'] / 100, 2) }} in current-session fees. Minimum for studying: {{ number_format($studyFeeStatus['required_minor'] / 100, 2) }}.
@if(!$studyFeeStatus['eligible']) Pay another {{ number_format($studyFeeStatus['shortfall_minor'] / 100, 2) }} through PesaPal, or contact the administrator about a waiver.@endif</p>
@else
<p class="mb-0">Contact Finance to confirm your current fee session and school fee invoices.</p>
@endif
</div>
