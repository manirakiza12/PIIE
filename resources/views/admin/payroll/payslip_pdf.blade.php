<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payslip — {{ $pay->staff->name ?? '' }}</title>
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
.header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
.header h2 { margin: 0; font-size: 18px; }
.header p { margin: 2px 0; font-size: 11px; }
.info-table, .salary-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
.info-table td { padding: 4px 8px; }
.salary-table th, .salary-table td { border: 1px solid #ccc; padding: 6px 10px; }
.salary-table th { background: #f0f0f0; text-align: left; }
.total-row { font-weight: bold; background: #e8f5e9; }
.footer { margin-top: 30px; display: flex; justify-content: space-between; }
.sig-line { border-top: 1px solid #333; width: 150px; text-align: center; padding-top: 4px; font-size: 10px; }
</style>
@include('shared.pdf_layout')
</head>
<body>
<div class="header">
    <h2>{{ $school->school_name ?? 'School Name' }}</h2>
    <p>{{ $school->address ?? '' }}</p>
    <h3 style="margin:10px 0 0">PAYSLIP — {{ $pay->pay_period?->format('F Y') }}</h3>
</div>
<table class="info-table">
    <tr><td><strong>Employee:</strong> {{ $pay->staff->name ?? '—' }}</td><td><strong>Pay Period:</strong> {{ $pay->pay_period?->format('F Y') }}</td></tr>
    <tr><td><strong>Role:</strong> {{ optional($pay->staff->role)->name ?? '—' }}</td><td><strong>Payment Date:</strong> {{ $pay->paid_at?->format('d M Y') ?? 'Pending' }}</td></tr>
</table>
<table class="salary-table">
    <thead><tr><th>Earnings</th><th>Amount</th><th>Deductions</th><th>Amount</th></tr></thead>
    <tbody>
        <tr><td>Basic Salary</td><td>{{ number_format((float) $pay->basic_salary, 2) }}</td><td>NSSF</td><td>{{ number_format((float) $pay->nssf, 2) }}</td></tr>
        <tr><td>Allowances</td><td>{{ number_format((float) $pay->allowances, 2) }}</td><td>Tax</td><td>{{ number_format((float) $pay->tax, 2) }}</td></tr>
        <tr><td>Gross Salary</td><td>{{ number_format((float) $pay->basic_salary + (float) $pay->allowances, 2) }}</td><td>Total Deductions</td><td>{{ number_format((float) $pay->deductions + (float) $pay->tax + (float) $pay->nssf, 2) }}</td></tr>
        <tr class="total-row"><td colspan="2"></td><td>Net Pay</td><td>{{ number_format((float) $pay->net_pay, 2) }}</td></tr>
    </tbody>
</table>
<div class="footer">
    <div class="sig-line">Prepared By</div>
    <div class="sig-line">Approved By</div>
    <div class="sig-line">Employee Signature</div>
</div>
</body>
</html>
