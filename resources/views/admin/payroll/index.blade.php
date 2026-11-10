@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Payroll') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="#">{{ get_phrase('Finance') }}</a></li><li><a href="#">{{ get_phrase('Payroll') }}</a></li></ul>
        </div>
        <div class="export-btn-area d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.payroll.export', ['month' => $month]) }}" class="export_btn bg-secondary"><i class="bi bi-download"></i> {{ get_phrase('Export CSV') }}</a>
            <a href="{{ route('admin.payroll.reports') }}" class="export_btn bg-secondary"><i class="bi bi-bar-chart"></i> {{ get_phrase('Payroll Reports') }}</a>
            <a href="{{ route('admin.salary_structures.index') }}" class="export_btn bg-secondary">{{ get_phrase('Salary Structures') }}</a>
        </div>
    </div>
</div></div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('info'))<div class="alert alert-info">{{ session('info') }}</div>@endif
@if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif

<div class="eSection-wrap p-3 mb-3">
    <form method="GET" action="{{ route('admin.payroll.index') }}" class="row g-2 align-items-end">
        <div class="col-12 col-md-2">
            <label class="form-label small mb-1">{{ get_phrase('Month') }}</label>
            <input type="month" name="month" value="{{ $month }}" class="form-control form-control-sm">
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1">{{ get_phrase('Department') }}</label>
            <select name="department_id" class="form-select form-select-sm">
                <option value="">{{ get_phrase('All departments') }}</option>
                @foreach($departments as $department)
                    <option value="{{ $department->id }}" @selected($departmentId == $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label small mb-1">{{ get_phrase('Status') }}</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">{{ get_phrase('All statuses') }}</option>
                <option value="pending" @selected($status === 'pending')>{{ get_phrase('Pending (draft)') }}</option>
                <option value="approved" @selected($status === 'approved')>{{ get_phrase('Approved') }}</option>
                <option value="partially_paid" @selected($status === 'partially_paid')>{{ get_phrase('Partially paid') }}</option>
                <option value="paid" @selected($status === 'paid')>{{ get_phrase('Paid') }}</option>
                <option value="rejected" @selected($status === 'rejected')>{{ get_phrase('Rejected') }}</option>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1">{{ get_phrase('Search employee') }}</label>
            <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ get_phrase('Search employee…') }}">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-outline-secondary">{{ get_phrase('View') }}</button>
            <a href="{{ route('admin.payroll.preview', array_filter(['month' => $month], fn ($v) => $v !== null)) }}{{ $departmentId ? '&department_id='.$departmentId : '' }}" class="btn btn-sm btn-outline-primary">{{ get_phrase('Preview run') }}</a>
        </div>
    </form>
    <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
        <h6 class="m-0">{{ get_phrase('Payroll Period') }}: <span class="text-primary">{{ \Carbon\Carbon::parse($period)->format('F Y') }}</span></h6>
        <form method="POST" action="{{ route('admin.payroll.generate') }}" onsubmit="return confirm('{{ get_phrase('Generate payroll for the selected period? Existing records are kept; rejected drafts are replaced.') }}')">
            @csrf
            <input type="hidden" name="month" value="{{ $month }}">
            @if($departmentId)<input type="hidden" name="department_id" value="{{ $departmentId }}">@endif
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-stars"></i> {{ get_phrase('Generate Payroll') }}</button>
        </form>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Total employees') }}</h6><h4>{{ $headcount['employees'] }}</h4><small class="text-muted">{{ $headcount['teaching'] }} {{ get_phrase('teaching') }} · {{ $headcount['non_teaching'] }} {{ get_phrase('non-teaching') }}</small></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Teaching staff') }}</h6><h4>{{ $headcount['teaching'] }}</h4></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Non-teaching staff') }}</h6><h4>{{ $headcount['non_teaching'] }}</h4></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Gross payroll') }}</h6><h4>{{ number_format($stats['gross_total'], 2) }}</h4><small class="text-muted">{{ get_phrase('UGX') }}</small></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Net payroll') }}</h6><h4><strong>{{ number_format($stats['net_total'], 2) }}</strong></h4><small class="text-muted">{{ get_phrase('Paid') }}: {{ number_format($stats['paid_total'], 2) }}</small></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Pending approvals') }}</h6><h4 class="text-warning">{{ $stats['draft_count'] }}</h4></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Paid salaries') }}</h6><h4 class="text-success">{{ number_format($stats['paid_total'], 2) }}</h4></div></div>
    <div class="col-md-3 col-6 mb-2"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Outstanding salaries') }}</h6><h4 class="text-warning">{{ number_format($stats['net_total'] - $stats['paid_total'], 2) }}</h4></div></div>
</div>

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr>
                <th>#</th>
                <th>{{ get_phrase('Employee') }}</th>
                <th>{{ get_phrase('Department') }}</th>
                <th class="text-end">{{ get_phrase('Basic') }}</th>
                <th class="text-end">{{ get_phrase('Allowances') }} + {{ get_phrase('Other') }}</th>
                <th class="text-end">{{ get_phrase('Deductions') }}</th>
                <th class="text-end">{{ get_phrase('Net Salary') }}</th>
                <th class="text-end">{{ get_phrase('Paid') }}</th>
                <th>{{ get_phrase('Status') }}</th>
                <th class="text-end">{{ get_phrase('Actions') }}</th>
            </tr></thead>
            <tbody>
            @forelse($payrolls as $p)
                @php($statusLabel = $p->isRejected() ? get_phrase('Rejected') : ucfirst($p->status))
                <tr>
                    <td>{{ $payrolls->firstItem() + $loop->index }}</td>
                    <td><strong class="text-dark">{{ optional($p->staff)->name }}</strong> <small class="text-muted">{{ optional($p->staff)->code }}</small></td>
                    <td>{{ $p->staff?->department?->name ?? '—' }}</td>
                    <td class="text-end">{{ number_format($p->basic_salary, 2) }}</td>
                    <td class="text-end">{{ number_format($p->allowances + $p->other_earnings, 2) }}</td>
                    <td class="text-end text-danger">{{ number_format($p->totalEmployeeDeductions(), 2) }}</td>
                    <td class="text-end"><strong>{{ number_format($p->net_pay, 2) }}</strong></td>
                    <td class="text-end">{{ number_format($p->paid_amount, 2) }}</td>
                    <td><span class="badge bg-{{ $p->isRejected() ? 'secondary' : ($p->status=='paid' ? 'success' : ($p->status=='approved' ? 'primary' : 'warning')) }}" title="{{ $p->isRejected() ? $p->rejection_reason : '' }}">{{ $statusLabel }}</span>@if($p->isPartiallyPaid())<span class="badge bg-info">{{ get_phrase('partially paid') }}</span>@endif</td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false">{{ get_phrase('Actions') }}</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('admin.payroll.payment_history', $p->id) }}">{{ get_phrase('View details') }}</a></li>
                                @if($p->status === 'draft' && !$p->isRejected())
                                    <li><button type="button" class="dropdown-item" disabled>{{ get_phrase('Edit draft') }}</button></li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.payroll.approve', $p->id) }}" onsubmit="return confirm('{{ get_phrase('Approve this payslip?') }}')">
                                            @csrf
                                            <button type="submit" class="dropdown-item">{{ get_phrase('Approve') }}</button>
                                        </form>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $p->id }}">{{ get_phrase('Reject') }}</button>
                                    </li>
                                    <li>
                                        <form method="POST" action="{{ route('admin.payroll.delete', $p->id) }}" onsubmit="return confirm('{{ get_phrase('Delete this draft payslip?') }}')">
                                            @csrf
                                            <button type="submit" class="dropdown-item text-danger">{{ get_phrase('Delete draft') }}</button>
                                        </form>
                                    </li>
                                @endif
                                @if($p->status === 'approved')
                                    <li>
                                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#payModal{{ $p->id }}">{{ get_phrase('Record payment') }}</button>
                                    </li>
                                @endif
                                <li><a class="dropdown-item" href="{{ route('admin.payroll.payment_history', $p->id) }}">{{ get_phrase('Payment history') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.payroll.print', $p->id) }}" target="_blank">{{ get_phrase('View payslip') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.payroll.print', $p->id) }}" target="_blank" download>{{ get_phrase('Download PDF') }}</a></li>
                                <li><a class="dropdown-item" href="{{ route('admin.payroll.print', $p->id) }}" target="_blank">{{ get_phrase('Print payslip') }}</a></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">{{ get_phrase('No payroll records for this period. Use Preview run or Generate Payroll.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $payrolls->links() }}
</div></div></div>

{{-- Modals rendered OUTSIDE the table (valid HTML) --}}
@foreach($payrolls as $p)
    @if($p->status === 'draft' && !$p->isRejected())
    <div class="modal fade" id="rejectModal{{ $p->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.payroll.reject', $p->id) }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">{{ get_phrase('Reject payslip') }} — {{ optional($p->staff)->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ get_phrase('Close') }}"></button></div>
                    <div class="modal-body">
                        <label class="form-label">{{ get_phrase('Reason') }}</label>
                        <textarea name="rejection_reason" class="form-control" rows="2" required minlength="5" maxlength="255" placeholder="{{ get_phrase('Explain why this payroll entry is rejected…') }}"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ get_phrase('Cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ get_phrase('Reject') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    @if($p->status === 'approved')
    <div class="modal fade" id="payModal{{ $p->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.payroll.pay', $p->id) }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">{{ get_phrase('Record payment') }} — {{ optional($p->staff)->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ get_phrase('Close') }}"></button></div>
                    <div class="modal-body">
                        <p class="mb-2">{{ get_phrase('Employee') }}: <strong>{{ optional($p->staff)->name }}</strong> · {{ get_phrase('Period') }}: <strong>{{ $p->pay_period?->format('F Y') }}</strong></p>
                        <p class="mb-2">{{ get_phrase('Net salary') }}: <strong>{{ number_format($p->net_pay, 2) }}</strong> · {{ get_phrase('Already paid') }}: <strong>{{ number_format($p->paid_amount, 2) }}</strong> · {{ get_phrase('Remaining') }}: <strong>{{ number_format($p->net_pay - $p->paid_amount, 2) }}</strong></p>
                        <div class="mb-2"><label class="form-label">{{ get_phrase('Amount (UGX)') }}</label><input type="number" name="payment_amount" class="form-control" step="0.01" min="0.01" max="{{ $p->net_pay - $p->paid_amount }}" value="{{ number_format($p->net_pay - $p->paid_amount, 2, '.', '') }}" required></div>
                        <div class="mb-2"><label class="form-label">{{ get_phrase('Method') }}</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="bank">{{ get_phrase('Bank transfer') }}</option>
                                <option value="cash">{{ get_phrase('Cash') }}</option>
                                <option value="mobile_money">{{ get_phrase('Mobile money') }}</option>
                            </select>
                        </div>
                        <div class="mb-2"><label class="form-label">{{ get_phrase('Transaction reference') }}</label><input type="text" name="payment_reference" class="form-control" maxlength="100" required placeholder="TRX / receipt no."></div>
                        <div><label class="form-label">{{ get_phrase('Payment date') }}</label><input type="date" name="paid_on" class="form-control" value="{{ now()->toDateString() }}"></div>
                        <small class="text-muted d-block mt-2">{{ get_phrase('This posts the amount as a school payroll expense in the Finance module. Student fee balances are never affected.') }}</small>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ get_phrase('Cancel') }}</button>
                        <button type="submit" class="btn btn-success">{{ get_phrase('Record payment') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
@endforeach

@endsection