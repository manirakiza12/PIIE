@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h1 class="h4">Fee Payments</h1><p>PesaPal school fee payments and verification history</p></div>
<div class="eSection-wrap">
<form method="get" class="mb-3"><label for="payment_status">Payment status</label>
<select id="payment_status" name="status"><option value="">All</option>@foreach(['pending','paid','failed','reversed'] as $value)<option value="{{ $value }}" @selected($status === $value)>{{ ucfirst($value) }}</option>@endforeach</select><button class="btn btn-primary">Filter</button></form>
<div class="table-responsive"><table class="table"><thead><tr><th>PRN</th><th>Invoice / student</th><th>Amount</th><th>Status</th><th>Tracking ID</th><th>Confirmation</th><th>Action</th></tr></thead><tbody>
@forelse($payments as $payment)<tr><td>{{ $payment->reference }}</td><td>#{{ $payment->fee_id }} {{ $payment->fee?->title }}<br>{{ $payment->fee?->student?->name }}</td><td>{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td><td>{{ ucfirst($payment->status) }}</td><td>{{ $payment->gateway_txn_id }}</td><td>{{ $payment->gateway_payload['provider_confirmation_code'] ?? '' }}</td><td><a href="{{ route('admin.tuition_payments.show', $payment->fee_id) }}">View / verify</a></td></tr>
@empty<tr><td colspan="7">No PesaPal school fee payments found.</td></tr>@endforelse
</tbody></table></div>{{ $payments->links() }}
</div>
@endsection
