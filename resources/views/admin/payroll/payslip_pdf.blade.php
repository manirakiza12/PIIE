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
.info-table, .salary-table, .payment-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
.info-table td { padding: 4px 8px; }
.salary-table th, .salary-table td, .payment-table th, .payment-table td { border: 1px solid #ccc; padding: 6px 10px; }
.salary-table th, .payment-table th { background: #f0f0f0; text-align: left; }
.total-row { font-weight: bold; background: #e8f5e9; }
.employer-note { margin-top: 8px; }
.footer { margin-top: 30px; display: flex; justify-content: space-between; }
.sig-line { border-top: 1px solid #333; width: 150px; text-align: center; padding-top: 4px; font-size: 10px; }
.status-badge { display: inline-block; padding: 2px 10px; border: 1px solid #333; border-radius: 3px; font-size: 11px; }
</style>
@include('shared.pdf_layout')
</head>
<body>
<div class="header">
    <h2>{{ $school?->title ?? 'Prime International Institute of Excellence' }}</h2>
    <p>{{ $school?->address ?? '' }} @if(!empty($school->phone)) · {{ $school->phone }}@endif</p>
    <h3 style="margin:10px 0 0">PAYSLIP — {{ $pay->pay_period?->format('F Y') }}</h3>
    <p>Payslip status: <span class="status-badge">
        {{ $pay->status === 'paid' ? 'PAID' : ($pay->isPartiallyPaid() ? 'PARTIALLY PAID' : ($pay->status === 'approved' ? 'APPROVED — AWAITING PAYMENT' : strtoupper($pay->status))) }}
    </span></p>
</div>

<table class="info-table">
    <tr><td><strong>Employee:</strong> {{ $pay->staff?->name ?? '—' }} ({{ $pay->staff?->code }})</td>
        <td><strong>Department:</strong> {{ $pay->staff?->department?->name ?? '—' }} · <strong>Role:</strong> {{ $pay->staff?->designationRecord?->name ?: $pay->staff?->role?->name ?? '—' }}</td></tr>
    <tr><td><strong>Pay Period:</strong> {{ $pay->pay_period?->format('F Y') }}</td><td><strong>Currency:</strong> {{ $pay->currency }}</td></tr>
</table>

<table class="salary-table">
    <thead><tr><th>Earnings</th><th>Amount</th><th>Deductions (employee)</th><th>Amount</th></tr></thead>
    <tbody>
        <tr><td>Basic Salary</td><td>{{ number_format((float) $pay->basic_salary, 2) }}</td><td>PAYE (income tax)</td><td>{{ number_format((float) $pay->tax, 2) }}</td></tr>
        <tr><td>Allowances</td><td>{{ number_format((float) $pay->allowances, 2) }}</td><td>NSSF (employee 5%)</td><td>{{ number_format((float) $pay->nssf, 2) }}</td></tr>
        <tr><td>Other earnings</td><td>{{ number_format((float) $pay->other_earnings, 2) }}</td><td>Other deductions</td><td>{{ number_format((float) $pay->deductions, 2) }}</td></tr>
        <tr class="total-row"><td>Gross Salary</td><td>{{ number_format($pay->grossPay(), 2) }}</td><td>Total Deductions</td><td>{{ number_format($pay->totalEmployeeDeductions(), 2) }}</td></tr>
        <tr class="total-row"><td colspan="2"></td><td>NET PAY</td><td>{{ number_format((float) $pay->net_pay, 2) }}</td></tr>
    </tbody>
</table>

{{-- Employer contributions are the school's own cost: never deducted from the employee, shown separately. --}}
<table class="salary-table employer-note">
    <thead><tr><th>Employer Contribution (school cost — not deducted from employee)</th><th>Amount</th></tr></thead>
    <tbody>
        <tr><td>NSSF (employer 10%)</td><td>{{ number_format((float) $pay->employer_nssf, 2) }}</td></tr>
    </tbody>
</table>

<table class="payment-table">
    <thead><tr><th>Payment</th><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th></tr></thead>
    <tbody>
        @forelse($payments as $payment)
            <tr><td>{{ $loop->index + 1 }}</td><td>{{ $payment->paid_on?->format('d M Y') }}</td><td>{{ ucfirst($payment->payment_method) }}</td><td>{{ $payment->payment_reference }}</td><td>{{ number_format((float) $payment->amount, 2) }}</td></tr>
        @empty
            <tr><td colspan="5">Not yet paid</td></tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <div class="sig-line">Prepared By</div>
    <div class="sig-line">Approved By</div>
    <div class="sig-line">Employee Signature</div>
</div>
</body>
</html>
