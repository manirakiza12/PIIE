@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h1 class="h4">Fee Payments</h1><p>PesaPal school fee payments and verification history</p></div>
<div class="eSection-wrap">
<form method="get" class="mb-3"><label for="payment_status">Payment status</label>
<select id="payment_status" name="status"><option value="">All</option>@foreach(['pending','paid','failed','reversed','archived'] as $value)<option value="{{ $value }}" @selected($status === $value)>{{ ucfirst($value) }}</option>@endforeach</select><button class="btn btn-primary">Filter</button></form>
<div class="table-responsive"><table class="table"><thead><tr><th>PRN</th><th>Invoice / student</th><th>Amount</th><th>Status</th><th>Tracking ID</th><th>Confirmation</th><th>Action</th></tr></thead><tbody>
@forelse($payments as $payment)<tr><td>{{ $payment->reference }}</td><td>#{{ $payment->fee_id }} {{ $payment->fee?->title }}<br>{{ $payment->fee?->student?->name }}</td><td>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td><td>{{ ucfirst($payment->status) }}@if($payment->isResolved()) <span class="badge bg-secondary">Resolved</span>@endif</td><td>{{ $payment->gateway_txn_id }}</td><td>{{ $payment->gateway_payload['provider_confirmation_code'] ?? '' }}</td><td>
<a href="{{ route('admin.tuition_payments.show', $payment->fee_id) }}">View / verify</a>
@if($payment->status === 'pending' && !$payment->isResolved() && app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.payments'))
<form method="POST" action="{{ route('admin.tuition_payments.resolve_test', $payment->id) }}" class="d-inline mt-1" onsubmit="return confirm('Resolve this test payment? Reason is required.');">
@csrf
<select name="provider_status" class="form-select form-select-sm mb-1" required>
    <option value="">-- Select provider status --</option>
    <option value="failed">Provider confirmed FAILED (requires reconcile)</option>
    <option value="expired">Provider confirmed EXPIRED (requires reconcile)</option>
    <option value="cancelled">Provider confirmed CANCELLED (requires reconcile)</option>
    <option value="review_only">Admin review only (no status change)</option>
</select>
<input type="text" name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (min 10 chars)" required minlength="10" maxlength="500">
<input type="text" name="provider_evidence" class="form-control form-control-sm mb-1" placeholder="Provider evidence (tracking ID, dashboard screenshot ref, etc.)">
<button type="submit" class="btn btn-sm btn-outline-warning">Resolve / Review</button>
</form>
@endif
</td></tr>
@empty<tr><td colspan="7">No PesaPal school fee payments found.</td></tr>@endforelse
</tbody></table></div>{{ $payments->links() }}
</div>
@endsection