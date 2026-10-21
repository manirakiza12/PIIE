<section class="border rounded p-3 my-3" aria-label="Prior payments">
<h2 class="h5">Payments made before joining the system</h2>
<p>Previously paid: {{ number_format($fee->opening_paid_amount ?? 0, 2) }}<br>
New payments received: {{ number_format($fee->collected_amount, 2) }}<br>
Balance payable: {{ number_format(max(0, $fee->total_amount - $fee->paid_amount), 2) }}</p>
@forelse($openingPayments as $entry)
<article class="border-top pt-2 mt-2">
<p>Receipt: {{ $entry->reference }} · {{ $entry->paid_on->format('d M Y') }} · {{ number_format($entry->amount, 2) }}
<strong>{{ $entry->reversed_at ? 'Reversed' : 'Verified prior payment' }}</strong></p>
@if($area === 'admin' && $canManageOpeningPayments)
<p>Verified {{ $entry->created_at }} by {{ $entry->approved_by_name }}. {{ $entry->reason }}</p>
@if($entry->reversed_at)
<p>Reversed {{ $entry->reversed_at }} by {{ $entry->reversed_by_name }}. {{ $entry->reversal_reason }}</p>
@else
<details><summary>Reverse incorrect prior payment</summary>
<form method="post" action="{{ route('admin.tuition_payments.opening_payment', $fee->id) }}">@csrf
<input type="hidden" name="action" value="reverse"><input type="hidden" name="opening_payment_id" value="{{ $entry->id }}">
<label for="reversal_{{ $entry->id }}">Reason for reversal</label>
<textarea id="reversal_{{ $entry->id }}" name="reason" class="form-control mb-2" minlength="10" maxlength="500" required></textarea>
<button class="btn btn-warning">Reverse and restore balance</button></form></details>
@endif
@endif
</article>
@empty<p>No prior payments have been recorded for this invoice.</p>@endforelse
@if($area === 'admin' && $canManageOpeningPayments)
<details class="mt-3"><summary>Record a verified prior payment</summary>
<p>Use only for money received before the student joined this system. Check the original receipt and allocate it to the correct invoice and academic session. Do not enter money already recorded here or through PesaPal. This records an opening payment, not a new collection.</p>
<form method="post" action="{{ route('admin.tuition_payments.opening_payment', $fee->id) }}">@csrf
<input type="hidden" name="action" value="record">
<label for="opening_amount" class="form-label">Amount already paid</label>
<input id="opening_amount" name="amount" class="form-control mb-2" type="number" min="0.01" step="0.01" max="{{ max(0, $fee->total_amount - $fee->paid_amount) }}" value="{{ old('amount') }}" required>
<label for="opening_paid_on" class="form-label">Original payment date</label>
<input id="opening_paid_on" name="paid_on" class="form-control mb-2" type="date" max="{{ now()->toDateString() }}" value="{{ old('paid_on') }}" required>
<label for="opening_reference" class="form-label">Original receipt / payment reference</label>
<input id="opening_reference" name="reference" class="form-control mb-2" maxlength="150" value="{{ old('reference') }}" required>
<label for="opening_reason" class="form-label">Verification reason / supporting record</label>
<textarea id="opening_reason" name="reason" class="form-control mb-2" minlength="10" maxlength="500" required>{{ old('reason') }}</textarea>
<button class="btn btn-primary">Verify and apply prior payment</button>
</form></details>
@endif
</section>
