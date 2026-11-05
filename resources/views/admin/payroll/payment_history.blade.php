@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Payment history') }} — {{ optional($pay->staff)->name }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.payroll.index') }}">{{ get_phrase('Payroll') }}</a></li><li><a href="#">{{ get_phrase('Payments') }}</a></li></ul>
        </div>
        <div class="export-btn-area d-flex gap-2">
            <a href="{{ route('admin.payroll.print', $pay->id) }}" target="_blank" class="export_btn bg-secondary"><i class="bi bi-file-earmark-pdf"></i> {{ get_phrase('Payslip PDF') }}</a>
            <a href="{{ route('admin.payroll.index', ['month' => $pay->pay_period->format('Y-m')]) }}" class="export_btn bg-secondary">{{ get_phrase('Back to period') }}</a>
        </div>
    </div>
</div></div></div>

<div class="row mb-3">
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Net salary') }}</h6><h4>{{ number_format($pay->net_pay, 2) }}</h4></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Paid to date') }}</h6><h4 class="text-success">{{ number_format($pay->paid_amount, 2) }}</h4></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Outstanding') }}</h6><h4 class="text-warning">{{ number_format($pay->net_pay - $pay->paid_amount, 2) }}</h4></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Status') }}</h6><span class="badge bg-{{ $pay->status=='paid' ? 'success' : 'primary' }}">{{ ucfirst($pay->status) }}</span></div></div>
</div>

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr>
                <th>#</th>
                <th>{{ get_phrase('Paid on') }}</th>
                <th class="text-end">{{ get_phrase('Amount') }}</th>
                <th>{{ get_phrase('Method') }}</th>
                <th>{{ get_phrase('Transaction reference') }}</th>
                <th>{{ get_phrase('Recorded by') }}</th>
            </tr></thead>
            <tbody>
            @forelse($payments as $payment)
                <tr>
                    <td>{{ $loop->index + 1 }}</td>
                    <td>{{ $payment->paid_on?->format('d M Y') }}</td>
                    <td class="text-end"><strong>{{ number_format($payment->amount, 2) }}</strong></td>
                    <td>{{ ucfirst($payment->payment_method) }}</td>
                    <td><code>{{ $payment->payment_reference }}</code></td>
                    <td>{{ optional($payment->recordedBy)->name ?? '#' . $payment->created_by }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ get_phrase('No payments recorded yet. A payment can be recorded from the payroll dashboard once the payslip is approved.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div></div>
@endsection
