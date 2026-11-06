@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Payroll Reports') }} — {{ $year }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.payroll.index') }}">{{ get_phrase('Payroll') }}</a></li><li><a href="#">{{ get_phrase('Reports') }}</a></li></ul>
        </div>
        <form method="GET" action="{{ route('admin.payroll.reports') }}" class="d-flex gap-2 align-items-center">
            <select name="year" class="form-select form-select-sm" style="max-width: 8rem;" onchange="this.form.submit()">
                @for($y = now()->year; $y >= now()->year - 5; $y--)
                    <option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>
                @endfor
            </select>
            <a href="{{ route('admin.payroll.index') }}" class="export_btn bg-secondary">{{ get_phrase('Payroll dashboard') }}</a>
        </form>
    </div>
</div></div></div>

<div class="row mb-3">
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Gross expenditure') }}</h6><h4>{{ number_format($yearTotals->gross, 2) }}</h4></div></div>
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('PAYE') }}</h6><h4>{{ number_format($yearTotals->paye, 2) }}</h4></div></div>
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('NSSF (employee)') }}</h6><h4>{{ number_format($yearTotals->nssf, 2) }}</h4></div></div>
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('NSSF (employer cost)') }}</h6><h4>{{ number_format($yearTotals->employer_cost, 2) }}</h4></div></div>
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Net payroll') }}</h6><h4>{{ number_format($yearTotals->net, 2) }}</h4></div></div>
    <div class="col-md-2 col-6"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Outstanding obligations') }}</h6><h4 class="text-warning">{{ number_format($yearTotals->outstanding, 2) }}</h4></div></div>
</div>

<div class="row">
    <div class="col-12"><div class="eSection-wrap mb-3">
        <div class="p-3"><h6>{{ get_phrase('Monthly salary expenditure') }}</h6></div>
        <div class="table-responsive">
            <table class="table eTable">
                <thead><tr><th>{{ get_phrase('Period') }}</th><th class="text-end">{{ get_phrase('Gross') }}</th><th class="text-end">{{ get_phrase('Employee deductions') }}</th><th class="text-end">{{ get_phrase('Employer cost') }}</th><th class="text-end">{{ get_phrase('Net') }}</th><th class="text-end">{{ get_phrase('Paid out') }}</th></tr></thead>
                <tbody>
                @forelse($periodKeys as $key)
                    @php($row = $monthly->get(sprintf('%s-%02d', $year, $key)))
                    @continue(!$row)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse(sprintf('%s-%02d', $year, $key))->format('F Y') }}</td>
                        <td class="text-end">{{ number_format($row->gross, 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($row->employee_deductions, 2) }}</td>
                        <td class="text-end text-muted">{{ number_format($row->employer_cost, 2) }}</td>
                        <td class="text-end"><strong>{{ number_format($row->net, 2) }}</strong></td>
                        <td class="text-end text-success">{{ number_format($row->paid, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ get_phrase('No payroll for') }} {{ $year }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div></div>

    <div class="col-12"><div class="eSection-wrap">
        <div class="p-3"><h6>{{ get_phrase('Departmental payroll') }}</h6></div>
        <div class="table-responsive">
            <table class="table eTable">
                <thead><tr><th>{{ get_phrase('Department') }}</th><th class="text-end">{{ get_phrase('Employees') }}</th><th class="text-end">{{ get_phrase('Gross') }}</th><th class="text-end">{{ get_phrase('Net') }}</th><th class="text-end">{{ get_phrase('Paid') }}</th></tr></thead>
                <tbody>
                @forelse($departmental as $row)
                    <tr>
                        <td>{{ $row->department }}</td>
                        <td class="text-end">{{ $row->employees }}</td>
                        <td class="text-end">{{ number_format($row->gross, 2) }}</td>
                        <td class="text-end"><strong>{{ number_format($row->net, 2) }}</strong></td>
                        <td class="text-end text-success">{{ number_format($row->paid, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ get_phrase('No departmental payroll data for') }} {{ $year }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div></div>
</div>
@endsection
