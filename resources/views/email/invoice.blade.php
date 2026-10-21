<!doctype html>
<html><body style="margin:0;padding:24px;background:#f3f6fb;font-family:Arial,sans-serif;color:#172b4d">
<div style="max-width:640px;margin:auto;background:#fff;padding:28px;border-radius:12px">
    <h2>{{ $school->title }}</h2>
    <h3>Invoice {{ sprintf('%08d', $invoice['id']) }}</h3>
    <p>Dear {{ $billing['name'] }},</p>
    <p>Your invoice is available. Please review the details below.</p>
    <p><strong>Bill to</strong><br>{{ $billing['name'] }}<br>
        @if($billing['registration_number']){{ $billing['registration_number'] }}<br>@endif
        {{ $billing['address'] ?: 'Address not provided' }}<br>
        {{ $billing['email'] }}<br>{{ $billing['phone'] ?: 'Phone not provided' }}</p>
    @include('shared.invoice_opening_payments', ['invoice_details' => $invoice])
    @php($currency = $school->school_currency ?: get_active_currency())
    @include('shared.invoice_award', ['invoice_details' => $invoice])
    <table style="width:100%;border-collapse:collapse" cellpadding="10">
        <tr><td>Invoice</td><td>{{ $invoice['title'] }}</td></tr>
        <tr><td>Issued</td><td>{{ \Carbon\Carbon::parse($invoice['created_at'])->format('d F Y') }}</td></tr>
        <tr><td>Total</td><td>{{ $currency }} {{ number_format($invoice['total_amount'], 2) }}</td></tr>
        <tr><td>Paid</td><td>{{ $currency }} {{ number_format($invoice['paid_amount'], 2) }}</td></tr>
        <tr><td><strong>Balance due</strong></td><td><strong>{{ $currency }} {{ number_format(max(0, $invoice['total_amount'] - $invoice['paid_amount']), 2) }}</strong></td></tr>
        <tr><td>Status</td><td>{{ ucfirst($invoice['status']) }}</td></tr>
    </table>
    <p><a href="{{ route('student.studentFeeinvoice', $invoice['id']) }}" style="display:inline-block;background:#2457a7;color:#fff;padding:12px 20px;text-decoration:none;border-radius:6px">View and print invoice</a></p>
    <p style="font-size:13px;color:#596780">Sign in with your student account to view the invoice and available payment options.</p>
</div></body></html>
