@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Payroll preview') }} — {{ \Carbon\Carbon::parse($data['period'])->format('F Y') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('admin.payroll.index') }}">{{ get_phrase('Payroll') }}</a></li><li><a href="#">{{ get_phrase('Preview') }}</a></li></ul>
        </div>
        <form method="POST" action="{{ route('admin.payroll.generate') }}" onsubmit="return confirm('{{ get_phrase('Generate payroll from this preview?') }}')">
            @csrf
            <input type="hidden" name="month" value="{{ \Carbon\Carbon::parse($data['period'])->format('Y-m') }}">
            @if($departmentId)<input type="hidden" name="department_id" value="{{ $departmentId }}">@endif
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-stars"></i> {{ get_phrase('Generate payroll') }}</button>
        </form>
    </div>
</div></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('info'))<div class="alert alert-info">{{ session('info') }}</div>@endif

<div class="row mb-3">
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Eligible employees') }}</h6><h4>{{ count($data['rows']) }}</h4></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('To be generated') }}</h6><h4>{{ count(array_filter($data['rows'], fn ($r) => $r['computed'])) }}</h4></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Projected net total') }}</h6><h4>{{ number_format($data['totals']['new_net'], 2) }}</h4><small class="text-muted">{{ get_phrase('Employer NSSF') }}: {{ number_format($data['totals']['new_employer'], 2) }}</small></div></div>
    <div class="col-md-3"><div class="eSection-wrap text-center"><h6>{{ get_phrase('Existing / no structure') }}</h6><h4>{{ $data['existing_count'] }} / {{ $data['missing_structure_count'] }}</h4></div></div>
</div>

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr>
                <th>#</th>
                <th>{{ get_phrase('Employee') }}</th>
                <th>{{ get_phrase('Department') }}</th>
                <th>{{ get_phrase('Role') }}</th>
                <th class="text-end">{{ get_phrase('Gross') }}</th>
                <th class="text-end">{{ get_phrase('PAYE') }}</th>
                <th class="text-end">{{ get_phrase('NSSF (employee)') }}</th>
                <th class="text-end">{{ get_phrase('NSSF (employer)') }}</th>
                <th class="text-end">{{ get_phrase('Net') }}</th>
                <th>{{ get_phrase('Run state') }}</th>
            </tr></thead>
            <tbody>
            @forelse($data['rows'] as $row)
                <tr>
                    <td>{{ $loop->index + 1 }}</td>
                    <td><strong class="text-dark">{{ $row['staff']->name }}</strong><br><small class="text-muted">{{ $row['staff']->code }}</small></td>
                    <td>{{ $row['department'] ?? '—' }}</td>
                    <td>{{ $row['designation'] ?? ($row['staff']->role?->name ?? '—') }}</td>
                    @if($row['existing'])
                        <td colspan="6" class="text-muted">{{ get_phrase('Already in this payroll run (kept — approved or paid records are never regenerated)') }}</td>
                    @elseif(!$row['structure'])
                        <td colspan="6" class="text-warning">{{ get_phrase('No salary structure effective for this period — skipped') }}</td>
                    @else
                        <td class="text-end">{{ number_format($row['computed']['gross'], 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($row['computed']['paye'], 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($row['computed']['nssf_employee'], 2) }}</td>
                        <td class="text-end text-muted">{{ number_format($row['computed']['employer_nssf'], 2) }}</td>
                        <td class="text-end"><strong>{{ number_format($row['computed']['net'], 2) }}</strong></td>
                    @endif
                    <td>
                        @if($row['existing'])
                            <span class="badge bg-secondary">{{ get_phrase('existing') }}</span>
                        @elseif($row['structure'])
                            <span class="badge bg-warning text-dark">{{ get_phrase('new (draft)') }}</span>
                        @else
                            <span class="badge bg-danger">{{ get_phrase('not payable') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-4">{{ get_phrase('No eligible employees found for this filter.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div></div></div>
@endsection
