@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h1 class="h4">Fee Payments</h1><p>PesaPal school fee payments and verification history</p></div>
<div class="eSection-wrap">
<form method="get" class="mb-3 d-flex gap-2 align-items-end flex-wrap">
    <div>
        <label for="payment_status" class="form-label mb-1">Payment status</label>
        <select id="payment_status" name="status" class="form-select">
            <option value="">All</option>
            @foreach(['pending','paid','failed','reversed','archived'] as $value)
                <option value="{{ $value }}" @selected($status === $value)>{{ ucfirst($value) }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn btn-primary">Filter</button>
</form>

<div class="table-responsive">
    <table class="table table-sm">
        <thead>
            <tr>
                <th>Payment ID</th>
                <th>Invoice / Student</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Tracking ID</th>
                <th>Environment</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $payment)
                @php
                    $fee = $payment->fee;
                    $studentName = $fee?->student?->name ?? '—';
                    $invoiceTitle = $fee?->title ?? null;
                    $environment = $payment->gateway_payload['environment'] ?? '—';
                    $canManage = app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.payments');
                    $isEligibleForArchive = $payment->status === 'failed'
                        && ! $payment->isResolved()
                        && ($payment->gateway_payload['classification'] ?? null) === 'FAILED'
                        && ! empty($payment->gateway_payload['last_checked_at'] ?? null)
                        && $environment === 'sandbox';
                @endphp
                <tr>
                    <td class="text-nowrap">#{{ $payment->id }}<br><small class="text-muted">{{ $payment->reference }}</small></td>
                    <td>
                        @if($fee)
                            <strong>#{{ $payment->fee_id }}</strong>
                            @if($invoiceTitle)<br><small class="text-muted">{{ $invoiceTitle }}</small>@endif
                            <br><small class="text-muted">{{ $studentName }}</small>
                        @else
                            <span class="text-danger">Invoice record unavailable</span>
                            <br><small class="text-muted">Fee #{{ $payment->fee_id }} not found</small>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                    <td>
                        <span class="badge bg-{{ $payment->status === 'paid' ? 'success' : ($payment->status === 'pending' ? 'warning text-dark' : ($payment->status === 'failed' ? 'danger' : 'secondary')) }}">
                            {{ ucfirst($payment->status) }}
                        </span>
                        @if($payment->isResolved())
                            <br><small class="text-muted">Resolved</small>
                        @endif
                    </td>
                    <td class="text-truncate" style="max-width: 120px;" title="{{ $payment->gateway_txn_id }}">
                        {{ $payment->gateway_txn_id ?: '—' }}
                    </td>
                    <td>
                        <span class="badge bg-{{ $environment === 'sandbox' ? 'info' : 'dark' }}">{{ ucfirst($environment) }}</span>
                    </td>
                    <td class="text-nowrap">
                        @if($fee)
                            <a href="{{ route('admin.tuition_payments.show', $payment->fee_id) }}" class="btn btn-sm btn-outline-primary">View</a>
                        @else
                            <span class="text-muted small">—</span>
                        @endif

                        @if(in_array($payment->status, ['pending', 'failed']) && ! $payment->isResolved() && $canManage)
                            <div class="mt-1">
                                {{-- Review Payment (modal) --}}
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reviewModal{{ $payment->id }}">
                                    Review Payment
                                </button>

                                {{-- Verify with Pesapal --}}
                                @if($payment->gateway_txn_id && $environment === 'sandbox')
                                    <form method="POST" action="{{ route('admin.tuition_payments.verify_pesapal', $payment->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-info" onclick="return confirm('Verify this payment with PesaPal Sandbox? This will query the provider using the original Sandbox credentials for the definitive status. Reconciliation may update financial records (invoice paid amount and status) based on the provider result.');">
                                            Verify with Pesapal
                                        </button>
                                    </form>
                                @endif

                                {{-- Archive Verified Test Payment --}}
                                @if($isEligibleForArchive)
                                    <form method="POST" action="{{ route('admin.tuition_payments.archive_test', $payment->id) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="Archiving verified Sandbox test payment">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this verified test payment? This will mark it as archived and it will no longer block configuration changes. The payment snapshot and verification evidence will be preserved.');">
                                            Archive Verified Test Payment
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>

                {{-- Review Payment Modal --}}
                @if(in_array($payment->status, ['pending', 'failed']) && ! $payment->isResolved() && $canManage)
                <div class="modal fade" id="reviewModal{{ $payment->id }}" tabindex="-1" aria-labelledby="reviewModalLabel{{ $payment->id }}" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="reviewModalLabel{{ $payment->id }}">Review Payment #{{ $payment->id }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <strong>Payment Details:</strong>
                                    <ul class="list-unstyled small">
                                        <li>Payment ID: #{{ $payment->id }}</li>
                                        <li>Invoice: {{ $fee ? '#' . $payment->fee_id . ' — ' . $invoiceTitle : 'Invoice record unavailable' }}</li>
                                        <li>Student: {{ $studentName }}</li>
                                        <li>Amount: {{ $payment->currency }} {{ number_format($payment->amount, 2) }}</li>
                                        <li>Status: {{ ucfirst($payment->status) }}</li>
                                        <li>Tracking ID: {{ $payment->gateway_txn_id ?: 'None' }}</li>
                                        <li>Environment: {{ ucfirst($environment) }}</li>
                                    </ul>
                                </div>
                                <div class="alert alert-info small">
                                    <strong>What this action does:</strong> Records an administrator review note and audit trail. The payment status remains unchanged.<br>
                                    <strong>What this action does NOT do:</strong> Does not change payment status, does not credit or debit the invoice, does not modify student balances, does not resolve the payment.
                                </div>
                                <form method="POST" action="{{ route('admin.tuition_payments.resolve_test', $payment->id) }}">
                                    @csrf
                                    <input type="hidden" name="provider_status" value="review_only">
                                    <div class="mb-3">
                                        <label for="reason{{ $payment->id }}" class="form-label">Reason for review (min 10 characters)</label>
                                        <textarea id="reason{{ $payment->id }}" name="reason" class="form-control" rows="3" required minlength="10" maxlength="500" placeholder="Explain why this payment is being reviewed..."></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="evidence{{ $payment->id }}" class="form-label">Provider evidence (optional)</label>
                                        <input type="text" id="evidence{{ $payment->id }}" name="provider_evidence" class="form-control" maxlength="1000" placeholder="Tracking ID, dashboard screenshot reference, etc.">
                                    </div>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-warning">Record Review</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            @empty
                <tr><td colspan="7" class="text-center">No PesaPal school fee payments found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $payments->links() }}
</div>
@endsection