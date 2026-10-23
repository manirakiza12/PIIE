<section class="fee-panel" aria-label="Prior payments">
<h2 class="h5">Prior payments already received</h2>
<p class="fee-caption">Verified pre-PIIE cash and original receipts. These reduce the balance and remain separate from new collections.</p>
@forelse($openingPayments as $entry)
<article class="fee-entry">
<p>Method: {{ str_replace('_', ' ', ucfirst($entry->method ?? 'not recorded')) }}</p>
<p>Receipt: {{ $entry->reference }} · {{ $entry->paid_on->format('d M Y') }} · {{ number_format($entry->amount, 2) }}
<strong>{{ $entry->reversed_at ? 'Reversed' : 'Verified prior payment' }}</strong></p>
@if(in_array($area, ['admin', 'accountant'], true) && $canManageOpeningPayments)
<p>Verified {{ $entry->created_at }} by {{ $entry->approved_by_name }}. {{ $entry->reason }}</p>
@if($entry->reversed_at)
<p>Reversed {{ $entry->reversed_at }} by {{ $entry->reversed_by_name }}. {{ $entry->reversal_reason }}</p>
@else
<details><summary>Reverse incorrect prior payment</summary>
<form method="post" action="{{ route(($area === 'accountant' ? 'accountant' : 'admin') . '.tuition_payments.opening_payment', $fee->id) }}">@csrf
<input type="hidden" name="action" value="reverse"><input type="hidden" name="opening_payment_id" value="{{ $entry->id }}">
<label for="reversal_{{ $entry->id }}">Reason for reversal</label>
<textarea id="reversal_{{ $entry->id }}" name="reason" class="form-control mb-2" minlength="10" maxlength="500" required></textarea>
<button class="btn btn-warning">Reverse and restore balance</button></form></details>
@endif
@endif
</article>
@empty<div class="fee-empty">No prior payments have been recorded for this invoice.</div>@endforelse
@if(in_array($area, ['admin', 'accountant'], true) && $canManageOpeningPayments)
<details class="fee-action"><summary>Record prior payment already received</summary>
<p>Use only for money received before the student joined this system. Check the original receipt and allocate it to the correct invoice and academic session. Do not enter money already recorded here or through PesaPal. This updates the opening balance; it is not a new cash collection.</p>
<form method="post" action="{{ route(($area === 'accountant' ? 'accountant' : 'admin') . '.tuition_payments.opening_payment', $fee->id) }}">@csrf
<input type="hidden" name="action" value="record">
<label for="opening_amount" class="form-label">Amount already paid</label>
<input id="opening_amount" name="amount" class="form-control mb-2" type="number" min="0.01" step="0.01" max="{{ max(0, $fee->total_amount - $fee->paid_amount) }}" value="{{ old('amount') }}" required>
<label for="opening_paid_on" class="form-label">Original payment date</label>
<input id="opening_paid_on" name="paid_on" class="form-control mb-2" type="date" max="{{ now()->toDateString() }}" value="{{ old('paid_on') }}" required>
<label for="opening_method" class="form-label">Payment method</label>
<select id="opening_method" name="method" class="form-select mb-2" required>
<option value="">Select payment method</option>
@foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>@endforeach
</select>
<label for="opening_reference" class="form-label">Original receipt / payment reference</label>
<input id="opening_reference" name="reference" class="form-control mb-2" maxlength="150" value="{{ old('reference') }}" required>
<label for="opening_reason" class="form-label">Verification reason / supporting record</label>
<textarea id="opening_reason" name="reason" class="form-control mb-2" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>
<button class="btn btn-primary">Verify and apply prior payment</button>
</form></details>
@elseif(in_array($area, ['admin', 'accountant'], true))
<p class="alert alert-info mt-3 mb-0" role="status">This student is eligible for prior-payment entry, but your account does not have the finance payment-approval permission. Ask your finance administrator to grant the appropriate role permission.</p>
@endif
</section>
