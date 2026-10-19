<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>School fee payment | PIIE</title>
<link rel="stylesheet" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}"></head>
<body class="bg-light"><main class="container py-5" style="max-width:960px">
<a href="{{ $area === 'admin' ? route('admin.fee_manager.list') : route($area . '.fee_manager.list') }}">Back to student fees</a>
<div class="card my-3"><div class="card-body">
<h1 class="h3">School fee payment</h1><h2 class="h5">{{ $fee->title }}</h2>
@foreach(['success', 'error'] as $message) @if(session($message))<div role="status" class="alert alert-{{ $message === 'error' ? 'danger' : 'success' }}">{{ session($message) }}</div>@endif @endforeach
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<p>Invoice #{{ $fee->id }} · Status: <strong>{{ $fee->status }}</strong></p>
<p>Total: {{ number_format($fee->total_amount, 2) }} · Paid: {{ number_format($fee->paid_amount, 2) }} · Balance: {{ number_format(max(0, $fee->total_amount - $fee->paid_amount), 2) }}</p>
@php
    $eligibility = $eligibility ?? app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->summary(\App\Models\User::where('school_id', $fee->school_id)->findOrFail($fee->student_id));
    $canManageWaiver = $canManageWaiver ?? false;
@endphp
<section class="alert alert-{{ $eligibility['eligible'] ? 'success' : 'warning' }}" aria-label="Study eligibility">
<h2 class="h5">Study eligibility: {{ $eligibility['eligible'] ? 'Eligible' : 'Payment required' }}</h2>
<p class="mb-1">The requirement is 20% of all school fees billed for your current academic session.</p>
<p class="mb-1">Session fees: {{ number_format($eligibility['total_minor'] / 100, 2) }} · Minimum required: {{ number_format($eligibility['required_minor'] / 100, 2) }} · Paid: {{ number_format($eligibility['paid_minor'] / 100, 2) }}</p>
@if($eligibility['waived'])<p class="mb-0">20% requirement waived for this session. The school fee balance remains payable.</p>
@elseif($eligibility['total_minor'] <= 0)<p class="mb-0">Finance must configure the current session and issue school fee invoices before eligibility can be assessed.</p>
@elseif(!$eligibility['eligible'])<p class="mb-0">Pay another {{ number_format($eligibility['shortfall_minor'] / 100, 2) }} to meet the study requirement. If this invoice's balance is smaller, continue with your other current-session invoices.</p>@endif
</section>
@if($area === 'admin' && $canManageWaiver && (!$eligibility['eligible'] || $eligibility['waived']))
<details class="border rounded p-3 mb-3"><summary>{{ $eligibility['waived'] ? 'Revoke study fee waiver' : 'Waive 20% study requirement for this student' }}</summary>
<p>A waiver applies to this student for the current fee session. It does not forgive fees or bypass academic requirements.</p>
<form method="post" action="{{ route('admin.tuition_payments.waiver', $fee->id) }}">@csrf
<input type="hidden" name="action" value="{{ $eligibility['waived'] ? 'revoke' : 'grant' }}">
<label for="waiver_reason">Reason (required)</label><textarea id="waiver_reason" name="reason" class="form-control mb-2" required minlength="10" maxlength="500"></textarea>
<button class="btn btn-warning">{{ $eligibility['waived'] ? 'Revoke waiver' : 'Grant waiver' }}</button></form></details>
@endif
@if($area !== 'admin' && $fee->status !== 'paid')
<p>Pay securely through PesaPal. Your invoice updates after PesaPal confirms payment. If a payment is pending, check its status before trying again.</p>
<form method="post" action="{{ route($area . '.payment.pesapal.start', $fee->id) }}">@csrf
<label class="form-label" for="payment_option">Payment option</label><select class="form-select mb-2" id="payment_option" name="payment_option">
@if(!$eligibility['eligible'] && $eligibility['shortfall_minor'] > 0 && (int)$fee->session_id === $eligibility['session_id'])<option value="minimum">Pay toward the 20% study minimum ({{ number_format(min($eligibility['shortfall_minor'] / 100, max(0, $fee->total_amount - $fee->paid_amount)), 2) }})</option>@endif
<option value="balance">Pay full invoice balance</option><option value="custom">Pay a custom amount</option></select>
<label class="form-label" for="payment_amount">Amount (only for custom payment)</label><input class="form-control mb-2" id="payment_amount" name="payment_amount" type="number" min="0.01" step="0.01" max="{{ max(0, $fee->total_amount - $fee->paid_amount) }}" value="{{ old('payment_amount') }}">
<label for="payer_phone" class="form-label">Payer phone number</label><input id="payer_phone" type="tel" name="payer_phone" class="form-control mb-3" maxlength="20" value="{{ old('payer_phone') }}">
<button class="btn btn-primary">Pay with PesaPal / resume checkout</button></form>
@endif
</div></div>
<h2 class="h4">Payment tracking</h2>
@forelse($payments as $payment)
<article class="card mb-3"><div class="card-body">
<h3 class="h6">PRN: {{ $payment->reference }}</h3>
<p>{{ $payment->currency }} {{ number_format($payment->amount, 2) }} · {{ $payment->status }} · {{ $payment->created_at }}</p>
<p>Tracking ID: {{ $payment->gateway_txn_id ?: 'Awaiting provider response' }}<br>Confirmation: {{ $payment->gateway_payload['provider_confirmation_code'] ?? 'Awaiting confirmation' }}</p>
<form method="post" action="{{ route($area . '.payment.pesapal.check', ['id' => $fee->id, 'payment' => $payment->id]) }}">@csrf
@if($area === 'admin')<label class="form-label">PesaPal order tracking ID (for recovery)</label><input name="order_tracking_id" class="form-control mb-2" value="{{ $payment->gateway_txn_id }}">@endif
<label class="form-label" for="confirmation_{{ $payment->id }}">PesaPal confirmation code (optional)</label>
<input id="confirmation_{{ $payment->id }}" name="confirmation_code" class="form-control mb-2" maxlength="100">
<button class="btn btn-outline-primary">Check payment status</button></form>
</div></article>
@empty<p>No PesaPal payments have been started for this invoice.</p>@endforelse
</main></body></html>
