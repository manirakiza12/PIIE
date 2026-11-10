@extends('admin.navigation')
@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-0">PesaPal Reconciliation</h1>
            <p class="text-muted mb-0 small">Verify pending payments and settle invoices.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.tuition_payments.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Fee payments
            </a>
            {{-- POST form: the route is POST-only. A window.location GET here used to
                 match admin/fee-payments/{id} with a non-numeric id and fatal as a 500. --}}
            <form method="POST" action="{{ route('admin.tuition_payments.run_reconciliation') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Query PesaPal for all pending payments now?')">
                    <i class="bi bi-arrow-repeat me-1"></i> Run Reconciliation
                </button>
            </form>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
@if(session('warning'))
    <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
@endif

{{-- Summary strip: one compact line, no duplicate cards --}}
<div class="eSection-wrap mb-3">
    <div class="d-flex flex-wrap align-items-center gap-4">
        <div class="d-flex align-items-baseline gap-2">
            <span class="h5 mb-0 text-warning">{{ $summary['total_pending'] }}</span>
            <span class="small text-muted">Pending</span>
            @if($summary['stale_pending'] > 0)
                <span class="badge bg-danger">{{ $summary['stale_pending'] }} stale</span>
            @endif
        </div>
        <div class="d-flex align-items-baseline gap-2">
            <span class="h5 mb-0 text-success">{{ $summary['total_paid'] }}</span>
            <span class="small text-muted">Paid</span>
        </div>
        <div class="d-flex align-items-baseline gap-2">
            <span class="h5 mb-0 text-danger">{{ $summary['total_failed'] }}</span>
            <span class="small text-muted">Failed</span>
            @if($summary['unverified_failed'] > 0)
                <span class="badge bg-warning text-dark">{{ $summary['unverified_failed'] }} unverified</span>
            @endif
        </div>
        <div class="d-flex align-items-baseline gap-2">
            <span class="h5 mb-0 text-secondary">{{ $summary['total_reversed'] }}</span>
            <span class="small text-muted">Reversed</span>
        </div>
        <div class="d-flex align-items-baseline gap-2">
            <span class="h5 mb-0 text-dark">{{ $summary['total_archived'] }}</span>
            <span class="small text-muted">Archived</span>
        </div>
    </div>
</div>

{{-- Toolbar: a single filter row; rare filters are tucked away --}}
<div class="eSection-wrap mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label for="status" class="form-label mb-1 small">Status</label>
            <select id="status" name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                @foreach(['pending','paid','failed','reversed','archived'] as $value)
                    <option value="{{ $value }}" @selected(($validated['status'] ?? null) === $value)>{{ ucfirst($value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label for="environment" class="form-label mb-1 small">Environment</label>
            <select id="environment" name="environment" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="sandbox" @selected(($validated['environment'] ?? null) === 'sandbox')>Sandbox</option>
                <option value="live" @selected(($validated['environment'] ?? null) === 'live')>Live</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="student_search" class="form-label mb-1 small">Student</label>
            <input type="text" id="student_search" name="student_search" class="form-control form-control-sm" placeholder="Name or code" value="{{ $validated['student_search'] ?? '' }}">
        </div>
        <div class="col-12 col-md-3">
            <label for="reference_search" class="form-label mb-1 small">Reference / tracking</label>
            <input type="text" id="reference_search" name="reference_search" class="form-control form-control-sm" placeholder="PRN or OrderTrackingId" value="{{ $validated['reference_search'] ?? '' }}">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="{{ route('admin.tuition_payments.reconciliation') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>

        <div class="col-12">
            <details @if(($validated['date_from'] ?? null) || ($validated['date_to'] ?? null) || ($validated['discrepancy'] ?? false)) open @endif>
                <summary class="small text-muted">More filters</summary>
                <div class="row g-2 align-items-end mt-1">
                    <div class="col-6 col-md-2">
                        <label for="date_from" class="form-label mb-1 small">Date from</label>
                        <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ $validated['date_from'] ?? '' }}">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="date_to" class="form-label mb-1 small">Date to</label>
                        <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ $validated['date_to'] ?? '' }}">
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="discrepancy" name="discrepancy" value="1" @checked($validated['discrepancy'] ?? false)>
                            <label class="form-check-label small" for="discrepancy">Show discrepancies only</label>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </form>
</div>

{{-- Discrepancy notice: one line, links to the filter instead of repeating the whole table --}}
@if($discrepancyInvoices->count() > 0)
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2 mb-3">
    <div class="small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>{{ $discrepancyInvoices->count() }}</strong> {{ \Illuminate\Support\Str::plural('invoice', $discrepancyInvoices->count()) }} with pending PesaPal payments and an outstanding balance.
    </div>
    <a href="{{ route('admin.tuition_payments.reconciliation', array_merge($validated, ['discrepancy' => true])) }}" class="btn btn-sm btn-outline-dark">Show only discrepancies</a>
</div>
@endif

{{-- Payments Table --}}
<div class="eSection-wrap">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Payments</h2>
        <span class="small text-muted">{{ $payments->total() }} result{{ $payments->total() === 1 ? '' : 's' }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
            <thead>
                <tr>
                    <th>Payment ID</th>
                    <th>Invoice / Student</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Provider Status</th>
                    <th>Tracking ID</th>
                    <th>Environment</th>
                    <th>Last Reconciled</th>
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
                        $providerClassification = $payment->gateway_payload['classification'] ?? null;
                        $lastCheckedAt = $payment->gateway_payload['last_checked_at'] ?? null;
                        $canManage = app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.payments');
                        $isEligibleForArchive = $payment->status === 'failed'
                            && ! $payment->isResolved()
                            && ($payment->gateway_payload['classification'] ?? null) === 'FAILED'
                            && ! empty($payment->gateway_payload['last_checked_at'] ?? null)
                            && $environment === 'sandbox';
                        $hasDiscrepancy = $payment->status === 'pending' && $fee && $fee->total_amount > $fee->paid_amount;
                    @endphp
                    <tr class="{{ $hasDiscrepancy ? 'table-warning' : '' }}">
                        <td class="text-nowrap">#{{ $payment->id }} <small class="text-muted">{{ $payment->reference }}</small></td>
                        <td>
                            @if($fee)
                                #{{ $payment->fee_id }} · {{ $studentName }}@if($invoiceTitle) <small class="text-muted">({{ $invoiceTitle }})</small>@endif
                            @else
                                <span class="text-danger">Invoice #{{ $payment->fee_id }} unavailable</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $payment->currency }} {{ number_format($payment->amount, 2) }}</td>
                        <td class="text-nowrap">
                            <span class="badge bg-{{ $payment->status === 'paid' ? 'success' : ($payment->status === 'pending' ? 'warning text-dark' : ($payment->status === 'failed' ? 'danger' : 'secondary')) }}">
                                {{ ucfirst($payment->status) }}
                            </span>
                            @if($payment->isResolved())
                                <small class="text-muted">resolved</small>
                            @endif
                            @if($hasDiscrepancy)
                                <span class="badge bg-warning text-dark">Discrepancy</span>
                            @endif
                        </td>
                        <td>
                            @if($providerClassification)
                                <span class="badge bg-{{ $providerClassification === 'COMPLETED' ? 'success' : ($providerClassification === 'FAILED' ? 'danger' : ($providerClassification === 'REVERSED' ? 'secondary' : 'info')) }}">
                                    {{ $providerClassification }}
                                </span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-truncate" style="max-width: 120px;" title="{{ $payment->gateway_txn_id }}">
                            {{ $payment->gateway_txn_id ?: '—' }}
                        </td>
                        <td>
                            <span class="badge bg-{{ $environment === 'sandbox' ? 'info' : 'dark' }}">{{ ucfirst($environment) }}</span>
                        </td>
                        <td class="text-nowrap small" title="{{ $lastCheckedAt }}">
                            {{ $lastCheckedAt ? \Carbon\Carbon::parse($lastCheckedAt)->diffForHumans() : 'Never' }}
                        </td>
                        <td class="text-nowrap">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-three-dots-vertical"></i> Actions
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    @if($fee)
                                        <li><a class="dropdown-item" href="{{ route('admin.tuition_payments.show', $payment->fee_id) }}"><i class="bi bi-eye me-2"></i>View invoice</a></li>
                                    @endif
                                    @if(in_array($payment->status, ['pending', 'failed']) && ! $payment->isResolved() && $canManage)
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#reviewModal{{ $payment->id }}"><i class="bi bi-pencil-square me-2"></i>Record review</button></li>
                                        @if($payment->gateway_txn_id && $environment === 'sandbox')
                                            <li>
                                                <form method="POST" action="{{ route('admin.tuition_payments.verify_pesapal', $payment->id) }}">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item" onclick="return confirm('Verify this payment with PesaPal now?');">
                                                        <i class="bi bi-check-circle me-2"></i>Verify with PesaPal
                                                    </button>
                                                </form>
                                            </li>
                                        @elseif($payment->gateway_txn_id && $environment === 'live')
                                            <li><span class="dropdown-item text-muted"><i class="bi bi-info-circle me-2"></i>Live payments reconcile automatically</span></li>
                                        @endif
                                        @if($isEligibleForArchive)
                                            <li>
                                                <form method="POST" action="{{ route('admin.tuition_payments.archive_test', $payment->id) }}">
                                                    @csrf
                                                    <input type="hidden" name="reason" value="Archiving verified Sandbox test payment">
                                                    <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Archive this verified test payment?');">
                                                        <i class="bi bi-archive me-2"></i>Archive test payment
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                    @endif
                                </ul>
                            </div>
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
                                    <div class="mb-3 small">
                                        <strong>Payment #{{ $payment->id }}</strong> · {{ $payment->currency }} {{ number_format($payment->amount, 2) }} · {{ ucfirst($payment->status) }} · {{ ucfirst($environment) }} @if($payment->gateway_txn_id) · {{ $payment->gateway_txn_id }} @endif
                                    </div>
                                    <div class="alert alert-info small mb-3">Records a review note and audit trail only — payment status and invoice balance are unchanged.</div>
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
                    <tr><td colspan="9" class="text-center">No PesaPal school fee payments found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $payments->links() }}
</div>

@endsection
