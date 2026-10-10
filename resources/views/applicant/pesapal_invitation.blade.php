<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>PIIE application payment</title></head>
<body><main style="max-width:640px;margin:3rem auto;padding:1rem;font-family:system-ui">
    <h1>Application fee payment</h1>
    <p>Application reference: {{ $admission->app_number }}</p>
    <p>Fee status: {{ ucfirst($admission->fee_status) }}</p>
    <p>Outstanding: {{ \App\Support\Admissions\ApplicationFee::currencyFor($admission) }} {{ $amount }}</p>
    @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if($payment)
    @php($experience=\App\Support\Payments\PesaPalPaymentExperience::forPayment($payment))
    <p>Latest PesaPal attempt: <strong>{{ $experience['label'] }}</strong></p>
    <p role="status">{{ $experience['message'] }}</p>
    @if($experience['reason'])<p>{{ $experience['reason'] }}</p>@endif
    <form method="post">@csrf<input type="hidden" name="action" value="check"><button>Check payment status</button></form>@endif
    @if(!$admission->isFeeSettled() && \App\Support\Payments\ApplicantPesaPalPayment::eligible($admission))
    @if(\App\Support\Admissions\ApplicationFee::gatewayIsConfigured('pesapal', $admission->school_id))
    <p>Choose MTN Mobile Money, Airtel Money or a bank card on PesaPal's secure checkout. Available methods depend on your currency and merchant account. PIIE confirms payment only after checking with PesaPal.</p>
    <form method="post">@csrf<button>{{ $payment && $payment->status === 'pending' ? 'Resume PesaPal payment' : 'Pay with PesaPal' }}</button></form>
    @else
    <p role="alert">Online payment by mobile money or card is not available at the moment. Please pay by bank deposit or mobile money and send the reference to the admissions office, or contact the finance office for help.</p>
    @endif
    @endif
    <p><a href="{{ route('applicant.login') }}">Sign in to your applicant portal</a></p>
</main></body></html>
