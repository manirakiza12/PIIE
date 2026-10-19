<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>PIIE application payment</title>@include('shared.typography')
</head>
<body><main style="max-width:640px;margin:3rem auto;padding:1rem;font-family:system-ui">
    <h1>Application fee payment</h1>
    <p>Application reference: {{ $admission->app_number }}</p>
    @if(\App\Support\Admissions\ApplicationFee::isRequired($admission))<p>Payment Registration Number (PRN): <strong>{{ \App\Support\Admissions\ApplicationFee::registrationNumber($admission) }}</strong></p>@endif
    <p>This PRN is linked to your PesaPal checkout automatically. For automatic confirmation, pay through an option shown by PesaPal; a bank transfer made outside PesaPal cannot be automatically matched.</p>
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
    <p>Choose an available option on PesaPal's secure checkout, such as MTN Mobile Money, Airtel Money, a card or bank transfer. Options depend on your currency and merchant account. PIIE confirms payment only after checking with PesaPal.</p>
    <form method="post">@csrf<label for="payer-phone">Payer mobile number (optional; enter another person's number for the mobile money prompt)</label><input type="tel" inputmode="tel" id="payer-phone" name="payer_phone" maxlength="20" autocomplete="tel" placeholder="Leave blank to use the applicant's number" @disabled($payment && $payment->status === 'pending')><button>{{ $payment && $payment->status === 'pending' ? 'Resume PesaPal payment' : 'Pay with PesaPal' }}</button></form>
    @else
    <p role="alert">Online payment is not available at the moment. Please contact admissions for help; do not make an unverified payment to a bank account.</p>
    @endif
    @endif
    <p><a href="{{ route('applicant.login') }}">Sign in to your applicant portal</a></p>
</main></body></html>
