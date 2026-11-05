@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('My Payslips') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="#">{{ get_phrase('HR') }}</a></li><li><a href="#">{{ get_phrase('Payslips') }}</a></li></ul>
        </div>
    </div>
</div></div></div>
<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr><th>#</th><th>{{ get_phrase('Pay Period') }}</th><th>{{ get_phrase('Gross Salary') }}</th><th>{{ get_phrase('Deductions') }}</th><th>{{ get_phrase('Net Pay') }}</th><th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
            <tbody>
            @forelse($payrolls as $i => $p)
            <tr>
                <td>{{ $i+1 }}</td>
                <td>{{ $p->pay_period?->format('F Y') }}</td>
                <td>{{ number_format((float) $p->basic_salary + (float) $p->allowances, 2) }}</td>
                <td class="text-danger">{{ number_format((float) $p->deductions + (float) $p->tax + (float) $p->nssf, 2) }}</td>
                <td><strong>{{ number_format((float) $p->net_pay, 2) }}</strong></td>
                <td><span class="badge bg-{{ $p->status=='paid'?'success':($p->status=='approved'?'primary':'warning') }}">{{ ucfirst($p->status) }}</span></td>
                <td>
                    @if($p->status=='paid')
                        <a href="{{ route('teacher.payroll.print', $p->id) }}" target="_blank" class="eBtn eBtn-sm eBtn-primary"><i class="bi bi-printer"></i> {{ get_phrase('Download') }}</a>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">{{ get_phrase('No payslips available') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div></div>
@endsection
