@php($activeAward = $awards->firstWhere('revoked_at', null))
<section class="fee-panel" aria-label="Bursaries and scholarships">
<h2 class="h5">Scholarship or bursary award</h2>
<p class="fee-caption">Approved sponsorship or fee coverage. An award reduces the fee; it is not money received and does not create a cash receipt.</p>
@if($activeAward)
<div class="fee-active-award"><span>{{ ucfirst($activeAward->type) }} reduction</span><strong>{{ number_format($activeAward->amount, 2) }}</strong><span class="small">Applied to this invoice · not a cash payment</span></div>
@if($activeAward->reference)<p>Approval reference: {{ $activeAward->reference }}</p>@endif
@else<div class="fee-empty">No active bursary or scholarship on this invoice.</div>@endif
@if(in_array($area, ['admin', 'accountant'], true) && $canManageAwards)
<details class="fee-action" @if($errors->any() && in_array(old('action'), ['grant', 'revoke'], true)) open @endif><summary>{{ $activeAward ? 'Manage current award' : 'Record fee award' }}</summary>
<p class="fee-caption">An approved award reduces this invoice only. For a percentage award, enter its monetary value. Other invoices and future sessions require their own awards. External sponsor payments must be verified through the payment process.</p>
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
</details>
@if($awards->isNotEmpty())
<details class="fee-action"><summary>Approval history</summary>
@foreach($awards as $award)
<p class="mt-2">{{ ucfirst($award->type) }}: {{ number_format($award->amount, 2) }} — {{ $award->revoked_at ? 'Revoked' : 'Active' }}<br>
Approved {{ $award->created_at }} by {{ $award->approved_by_name }}. {{ $award->reason }}
@if($award->revoked_at)<br>Revoked {{ $award->revoked_at }} by {{ $award->revoked_by_name }}. {{ $award->revocation_reason }}@endif</p>
@endforeach
</details>
@endif
@endif
</section>
