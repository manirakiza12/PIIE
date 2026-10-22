@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Fee Collection Report') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('admin.reports.index') }}">{{ get_phrase('Reports') }}</a></li>
                <li><a href="#">{{ get_phrase('Finance') }}</a></li>
            </ul>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.export', 'finance') }}" class="eBtn eBtn-secondary"><i class="bi bi-download"></i> {{ get_phrase('Export CSV') }}</a>
            <a href="{{ route('admin.reports.index') }}" class="eBtn eBtn-secondary"><i class="bi bi-arrow-left"></i> {{ get_phrase('Back') }}</a>
        </div>
    </div>
</div></div></div>

<div class="row mb-3">
    <div class="col-md-3"><div class="eSection-wrap text-center p-3" style="border-top:3px solid #1a3a6b">
        <div style="font-size:22px;font-weight:800;color:#1a3a6b">UGX {{ number_format($totals->gross_invoiced ?? $totals->invoiced ?? 0) }}</div>
        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Gross billed') }}</div>
    </div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center p-3" style="border-top:3px solid #6c757d">
        <div style="font-size:22px;font-weight:800;color:#6c757d">UGX {{ number_format($totals->awards ?? 0) }}</div>
        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Bursaries and scholarships') }}</div>
    </div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center p-3" style="border-top:3px solid #198754">
        <div style="font-size:22px;font-weight:800;color:#198754">UGX {{ number_format($totals->collected ?? 0) }}</div>
        <div style="font-size:11px;color:#6c757d">{{ get_phrase('New Collections') }}</div>
        <div class="small text-muted">Prior payments: UGX {{ number_format(max(0, ($totals->paid ?? 0) - ($totals->collected ?? 0)), 2) }} (included in paid balances)</div>
    </div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center p-3" style="border-top:3px solid #dc3545">
        <div style="font-size:22px;font-weight:800;color:#dc3545">UGX {{ number_format(max(0,($totals->invoiced ?? 0)-($totals->paid ?? 0))) }}</div>
        <div style="font-size:11px;color:#6c757d">{{ get_phrase('Outstanding') }}</div>
    </div></div>
</div>

<div class="row mb-3"><div class="col-12">
    <form class="d-flex gap-3 align-items-end" method="GET">
        <div><label class="eForm-label">{{ get_phrase('From') }}</label><input type="date" class="form-control eForm-control" name="from" value="{{ $from }}"></div>
        <div><label class="eForm-label">{{ get_phrase('To') }}</label><input type="date" class="form-control eForm-control" name="to" value="{{ $to }}"></div>
        <button class="eBtn eBtn-primary" type="submit"><i class="bi bi-funnel"></i> {{ get_phrase('Filter') }}</button>
    </form>
</div></div>

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable" style="font-size:12px">
            <thead><tr><th>#</th><th>{{ get_phrase('Title') }}</th><th>{{ get_phrase('Gross billed') }}</th><th>{{ get_phrase('Award') }}</th><th>{{ get_phrase('Net payable') }}</th><th>{{ get_phrase('Paid') }}</th><th>{{ get_phrase('Balance') }}</th><th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Date') }}</th></tr></thead>
            <tbody>
            @forelse($invoices as $i => $inv)
            @php $bal = $inv->total_amount - $inv->paid_amount; @endphp
            <tr>
                <td>{{ $invoices->firstItem() + $i }}</td>
                <td>{{ $inv->title ?? '—' }}</td>
                <td>{{ number_format($inv->total_amount + $inv->award_amount) }}</td>
                <td>{{ number_format($inv->award_amount) }}</td>
                <td>{{ number_format($inv->total_amount) }}</td>
                <td style="color:#198754">{{ number_format($inv->paid_amount) }}</td>
                <td style="color:{{ $bal>0?'#dc3545':'#198754' }}">{{ number_format($bal) }}</td>
                <td><span class="badge bg-{{ $inv->status=='paid'?'success':($inv->status=='partial'?'warning':'danger') }}">{{ $inv->status }}</span></td>
                <td>{{ date('d M Y', $inv->timestamp ?? 0) }}</td>
            </tr>
            @empty
            <tr><td colspan="9" class="text-center text-muted py-4">{{ get_phrase('No records in this period') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
</div></div></div>
@endsection
