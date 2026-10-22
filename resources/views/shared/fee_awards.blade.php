@php($activeAward = $awards->firstWhere('revoked_at', null))
<section class="border rounded p-3 my-3" aria-label="Bursaries and scholarships">
<h2 class="h5">Scholarship or bursary award</h2>
<p>A fee award records approved sponsorship or fee coverage. It is not money received from the student and does not create a cash receipt.</p>
@if($activeAward)
<p>Fee before award: {{ number_format($fee->total_amount + $activeAward->amount, 2) }}<br>
{{ ucfirst($activeAward->type) }} reduction: {{ number_format($activeAward->amount, 2) }}<br>
Fee after award: {{ number_format($fee->total_amount, 2) }}<br>
Payments received: {{ number_format($fee->paid_amount, 2) }}<br>
Balance payable: {{ number_format(max(0, $fee->total_amount - $fee->paid_amount), 2) }}</p>
@if($activeAward->reference)<p>Approval reference: {{ $activeAward->reference }}</p>@endif
@else<p>No active bursary or scholarship on this invoice.</p>@endif
@if(in_array($area, ['admin', 'accountant'], true) && $canManageAwards)
<p>An approved award reduces this invoice only. For a percentage award, enter its monetary value. Other invoices and future sessions require their own awards. External sponsor payments must be verified through the payment process.</p>
<form method="post" action="{{ route(($area === 'accountant' ? 'accountant' : 'admin') . '.tuition_payments.award', $fee->id) }}">@csrf
<input type="hidden" name="action" value="{{ $activeAward ? 'revoke' : 'grant' }}">
@unless($activeAward)
<label for="award_type" class="form-label">Award type</label>
<select id="award_type" name="type" class="form-select mb-2"><option value="bursary">Bursary</option><option value="scholarship">Scholarship</option></select>
<label for="award_amount" class="form-label">Amount covered</label>
<input id="award_amount" name="amount" type="number" min="0.01" max="{{ max(0, $fee->total_amount - $fee->paid_amount) }}" step="0.01" class="form-control mb-2" value="{{ old('amount') }}" required>
<label for="award_reference" class="form-label">Approval reference (optional)</label>
<input id="award_reference" name="reference" maxlength="150" class="form-control mb-2" value="{{ old('reference') }}">
@endunless
<label for="award_reason" class="form-label">{{ $activeAward ? 'Reason for revoking' : 'Reason for approval' }}</label>
<textarea id="award_reason" name="reason" minlength="10" maxlength="500" class="form-control mb-2" required>{{ old('reason') }}</textarea>
<button class="btn btn-{{ $activeAward ? 'warning' : 'primary' }}">{{ $activeAward ? 'Revoke award and restore fee' : 'Record fee award' }}</button>
</form>
@if($awards->isNotEmpty())
<details class="mt-3"><summary>Approval history</summary>
@foreach($awards as $award)
<p class="mt-2">{{ ucfirst($award->type) }}: {{ number_format($award->amount, 2) }} — {{ $award->revoked_at ? 'Revoked' : 'Active' }}<br>
Approved {{ $award->created_at }} by {{ $award->approved_by_name }}. {{ $award->reason }}
@if($award->revoked_at)<br>Revoked {{ $award->revoked_at }} by {{ $award->revoked_by_name }}. {{ $award->revocation_reason }}@endif</p>
@endforeach
</details>
@endif
@endif
</section>
