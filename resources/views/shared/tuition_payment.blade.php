<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>School fee payment | PIIE</title>
<link rel="stylesheet" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/fee-payment.css') }}">
</head>
<body class="fee-workspace"><main class="fee-shell">
@php
    $balance = max(0, (float)$fee->total_amount - (float)$fee->paid_amount);
    $awardReduction = (float)$awards->whereNull('revoked_at')->sum('amount');
    $discount = (float)($fee->discounted_price ?? 0);
    $baseFee = (float)($fee->amount ?? ($fee->total_amount + $awardReduction + $discount));
    $pendingSetupCount = $financialSetupReview ? collect(['prior_payment_status', 'award_status', 'study_waiver_status'])->filter(fn ($field) => $financialSetupReview->{$field} === 'pending')->count() : 0;
    $pendingPaymentCount = $payments->where('status', 'pending')->count();
    $canManageWaiver = $canManageWaiver ?? false;
@endphp
<a class="fee-back" href="{{ route($area . '.fee_manager.list') }}">← Back to student fees</a>
<header class="fee-header">
    <div><p class="fee-eyebrow">PIIE · Student finance</p><h1>School fee payment</h1><h2>{{ $fee->title }}</h2></div>
    <span class="fee-pill {{ $fee->status === 'paid' ? 'fee-pill--success' : 'fee-pill--warning' }}">Invoice #{{ $fee->id }} · {{ ucfirst($fee->status) }}</span>
</header>
<div class="fee-meta" aria-label="Student and invoice context">
    <span>Student <strong>{{ $student->name }}</strong></span><span>Registration <strong>{{ $student->code ?: 'Not recorded' }}</strong></span>
    <span>Invoice session <strong>{{ $sessionLabels->get($fee->session_id) ?: ('Session #' . $fee->session_id) }}</strong></span>
    @if($currencyLabel)<span>Currency <strong>{{ $currencyLabel }}</strong></span>@endif
</div>
@foreach(['success', 'error'] as $message) @if(session($message))<div role="status" class="alert alert-{{ $message === 'error' ? 'danger' : 'success' }}">{{ session($message) }}</div>@endif @endforeach
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<section class="fee-stats" aria-label="Invoice summary">
    <div class="fee-stat fee-stat--balance"><div class="fee-stat-label">Balance payable</div><div class="fee-stat-value" data-fee-value="balance">{{ number_format($balance, 2) }}</div><p class="fee-stat-note">{{ $balance > 0 ? 'Still owing on this invoice' : 'This invoice is fully settled' }}</p></div>
    <div class="fee-stat"><div class="fee-stat-label">Verified prior payments</div><div class="fee-stat-value" data-fee-value="prior">{{ number_format($fee->opening_paid_amount ?? 0, 2) }}</div><p class="fee-stat-note">Pre-PIIE cash / original receipts</p></div>
    <div class="fee-stat"><div class="fee-stat-label">New payments received</div><div class="fee-stat-value" data-fee-value="new">{{ number_format($fee->collected_amount, 2) }}</div><p class="fee-stat-note">Confirmed collections in PIIE</p></div>
    <div class="fee-stat"><div class="fee-stat-label">Approved awards</div><div class="fee-stat-value" data-fee-value="awards">{{ number_format($awardReduction, 2) }}</div><p class="fee-stat-note">Fee reduction · not cash received</p></div>
</section>
@if($pendingSetupCount || $pendingPaymentCount)
<div class="fee-notice" role="status"><strong>{{ $pendingSetupCount + $pendingPaymentCount }} item(s) awaiting confirmation.</strong> Pending amounts are not included in payments received and do not reduce the balance.
    @if($pendingSetupCount)<a href="#financial-setup">Review captured setup</a>@endif
    @if($pendingPaymentCount)<a href="#payment-tracking">Track pending payments</a>@endif
</div>
@endif
<nav class="fee-section-nav" aria-label="Payment page sections"><a href="#fee-breakdown">Fee breakdown</a><a href="#prior-payments">Prior payments</a><a href="#fee-awards">Awards &amp; history</a><a href="#payment-tracking">Payment tracking</a></nav>
<div class="fee-grid">
<section class="fee-panel" id="fee-breakdown" aria-label="Fee breakdown">
    <h2>Fee breakdown</h2><p class="fee-caption">How the agreed fee becomes the amount still payable.</p>
    <dl class="fee-ledger">
        <div><dt>Agreed fee before reductions</dt><dd data-fee-value="base">{{ number_format($baseFee, 2) }}</dd></div>
        <div><dt>Existing discount</dt><dd class="fee-negative">− {{ number_format($discount, 2) }}</dd></div>
        <div><dt>Approved bursary / scholarship</dt><dd class="fee-negative">− {{ number_format($awardReduction, 2) }}</dd></div>
        <div class="fee-ledger-subtotal"><dt>Net invoice total</dt><dd data-fee-value="net">{{ number_format($fee->total_amount, 2) }}</dd></div>
        <div><dt>Verified prior cash</dt><dd class="fee-negative">− {{ number_format($fee->opening_paid_amount ?? 0, 2) }}</dd></div>
        <div><dt>New payments received</dt><dd class="fee-negative">− {{ number_format($fee->collected_amount, 2) }}</dd></div>
        <div class="fee-ledger-total"><dt>Balance payable</dt><dd>{{ number_format($balance, 2) }}</dd></div>
    </dl>
    @if(abs($baseFee - $discount - $awardReduction - (float)$fee->total_amount) > 0.01 || (float)($fee->opening_paid_amount ?? 0) > (float)$fee->paid_amount)<p class="small text-warning mt-3 mb-0">Amount fields need reconciliation; balance uses the stored invoice total. Review the invoice history before making changes.</p>@endif
</section>
<section class="fee-panel" aria-label="Study eligibility">
    <div class="fee-eligibility-title"><h2>Study eligibility</h2><span class="fee-pill {{ $eligibility['eligible'] ? 'fee-pill--success' : 'fee-pill--warning' }}">{{ $eligibility['eligible'] ? 'Eligible' : 'Payment required' }}</span></div>
    <p class="fee-caption">20% of all school fees billed for the current academic session: <strong>{{ $sessionLabels->get($eligibility['session_id']) ?: ('Session #' . $eligibility['session_id']) }}</strong>.</p>
    <div class="fee-mini-stats"><div><span>Session fees</span><strong>{{ number_format($eligibility['total_minor'] / 100, 2) }}</strong></div><div><span>Minimum required</span><strong>{{ number_format($eligibility['required_minor'] / 100, 2) }}</strong></div><div><span>Verified paid</span><strong>{{ number_format($eligibility['paid_minor'] / 100, 2) }}</strong></div></div>
    @if($eligibility['required_minor'] > 0 && !$eligibility['waived'])
        <progress class="fee-progress" aria-label="Verified payments toward the 20% study requirement" value="{{ min($eligibility['paid_minor'], $eligibility['required_minor']) }}" max="{{ $eligibility['required_minor'] }}"></progress>
        <p class="fee-progress-label">{{ number_format(min(100, $eligibility['paid_minor'] / $eligibility['required_minor'] * 100), 0) }}% of the study payment minimum met</p>
    @endif
    @if($eligibility['waived'])<div class="fee-callout">20% requirement waived for this session. The school fee balance remains payable.</div>
    @elseif($eligibility['fully_covered'] ?? false)<div class="fee-callout">Your current-session fees are fully covered by an approved bursary or scholarship. No payment is required.</div>
    @elseif($eligibility['total_minor'] <= 0)<div class="fee-callout">Finance must configure the current session and issue school fee invoices before eligibility can be assessed.</div>
    @elseif(!$eligibility['eligible'])<div class="fee-callout">Pay another <strong>{{ number_format($eligibility['shortfall_minor'] / 100, 2) }}</strong> to meet the study requirement. If this invoice's balance is smaller, continue with your other current-session invoices.</div>
    @else<div class="fee-callout">The session payment minimum has been met. Any remaining invoice balance is still payable.</div>@endif
    @if((int)$fee->session_id !== (int)$eligibility['session_id'])<p class="small text-muted">This invoice is for a different session. Its balance and the current-session study requirement are shown separately.</p>@endif
    @if($area === 'admin' && $canManageWaiver && (!$eligibility['eligible'] || $eligibility['waived']))
    <details class="fee-action"><summary>{{ $eligibility['waived'] ? 'Revoke study fee waiver' : 'Waive 20% study requirement for this student' }}</summary>
        <p class="fee-caption">A waiver applies to this student for the current fee session. It does not forgive fees or bypass academic requirements.</p>
        <form method="post" action="{{ route('admin.tuition_payments.waiver', $fee->id) }}">@csrf
            <input type="hidden" name="action" value="{{ $eligibility['waived'] ? 'revoke' : 'grant' }}">
            <label for="waiver_reason">Reason (required)</label><textarea id="waiver_reason" name="reason" class="form-control mb-2" required minlength="10" maxlength="500"></textarea>
            <button class="btn btn-warning">{{ $eligibility['waived'] ? 'Revoke waiver' : 'Grant waiver' }}</button>
        </form>
    </details>
    @endif
</section>
</div>
@if(in_array($area, ['admin', 'accountant'], true))<div id="financial-setup">@include('shared.existing_student_financial_setup_review')</div>@endif
<div class="{{ ($isAdminAddedExisting ?? false) || in_array($area, ['admin', 'accountant'], true) ? 'fee-grid fee-grid--equal' : 'mb-4' }}">
<div id="prior-payments">
    @if($isAdminAddedExisting ?? false) @include('shared.opening_payments')
    @elseif(in_array($area, ['admin', 'accountant'], true))<section class="fee-panel" aria-label="Prior payment entry eligibility"><h2>Prior payment entry unavailable</h2><p class="fee-caption mb-0">This invoice belongs to a student who was not marked as added through Add Existing Student. Prior payments can only be entered for eligible continuing students; contact the registrar if this student was incorrectly classified.</p></section>@endif
</div>
<div id="fee-awards">@include('shared.fee_awards')</div>
</div>
@if(in_array($area, ['student', 'parent'], true) && $fee->status !== 'paid' && $fee->total_amount > $fee->paid_amount)
<section class="fee-panel mb-4" aria-label="Make a payment"><h2>Make a payment</h2>
    <p class="fee-caption">Pay securely through PesaPal. Your invoice updates after PesaPal confirms payment. If a payment is pending, check its status before trying again.</p>
    <form method="post" action="{{ route($area . '.payment.pesapal.start', $fee->id) }}">@csrf
        <p class="fee-caption">Choose how much to pay here. On PesaPal, choose an available payment method, such as MTN Mobile Money, Airtel Money, or Visa. Availability depends on the configured PesaPal checkout. The phone number is contact information and does not select a payment method.</p>
        <label class="form-label" for="payment_option">Amount to pay</label><select class="form-select mb-2" id="payment_option" name="payment_option">
        @if(!$eligibility['eligible'] && $eligibility['shortfall_minor'] > 0 && (int)$fee->session_id === $eligibility['session_id'])<option value="minimum">Pay toward the 20% study minimum ({{ number_format(min($eligibility['shortfall_minor'] / 100, $balance), 2) }})</option>@endif
        <option value="balance">Pay full invoice balance</option><option value="custom">Pay a custom amount</option></select>
        <label class="form-label" for="payment_amount">Amount (only for custom payment)</label><input class="form-control mb-2" id="payment_amount" name="payment_amount" type="number" min="0.01" step="0.01" max="{{ $balance }}" value="{{ old('payment_amount') }}">
        <label for="payer_phone" class="form-label">Payer phone number</label><input id="payer_phone" type="tel" name="payer_phone" class="form-control mb-3" maxlength="20" value="{{ old('payer_phone') }}">
        <button class="btn btn-primary">Pay with PesaPal / resume checkout</button>
    </form>
</section>
@endif
<section class="fee-panel" id="payment-tracking" aria-label="Payment tracking">
    <h2>Payment tracking</h2><p class="fee-caption">New PesaPal payments for this invoice. Prior receipts and fee awards are tracked separately above.</p>
    @forelse($payments as $payment)
    <article class="fee-payment">
        <div class="fee-entry-head"><div><h3 class="fee-payment-reference mb-1">PRN: {{ $payment->reference }}</h3><span class="fee-entry-amount">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</span> <span class="small text-muted">· {{ $payment->gateway_payload['payment_channel'] ?? ($payment->status === 'paid' ? $payment->method : 'Payment channel pending confirmation') }}</span></div>
            <span class="fee-pill {{ $payment->status === 'paid' ? 'fee-pill--success' : ($payment->status === 'failed' ? 'fee-pill--danger' : 'fee-pill--warning') }}">{{ ucfirst($payment->status) }}</span>
        </div>
        <div class="fee-payment-details"><span>Started {{ $payment->created_at }}</span>@if($payment->paid_at)<span>Verified paid {{ $payment->paid_at }}</span>@endif</div>
        <details class="fee-action"><summary>Check payment status / view provider details</summary>
            <p class="small">Tracking ID: {{ $payment->gateway_txn_id ?: 'Awaiting provider response' }}<br>Confirmation: {{ $payment->gateway_payload['provider_confirmation_code'] ?? 'Awaiting confirmation' }}</p>
            <form method="post" action="{{ route($area . '.payment.pesapal.check', ['id' => $fee->id, 'payment' => $payment->id]) }}">@csrf
                @if($area === 'admin')<label class="form-label" for="tracking_{{ $payment->id }}">PesaPal order tracking ID (for recovery)</label><input id="tracking_{{ $payment->id }}" name="order_tracking_id" class="form-control mb-2" value="{{ $payment->gateway_txn_id }}">@endif
                <label class="form-label" for="confirmation_{{ $payment->id }}">PesaPal confirmation code (optional)</label><input id="confirmation_{{ $payment->id }}" name="confirmation_code" class="form-control mb-2" maxlength="100">
                <button class="btn btn-outline-primary">Check payment status</button>
            </form>
        </details>
    </article>
    @empty<div class="fee-empty"><p><strong>No new online payments yet.</strong></p><p>No PesaPal payments have been started for this invoice.</p></div>@endforelse
</section>
<p class="fee-footer">Verified receipts, provider confirmations and approval history remain attached to this invoice.</p>
</main></body></html>
