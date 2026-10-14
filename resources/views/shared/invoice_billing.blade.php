@php($billing = \App\Support\Billing\InvoiceBilling::details($invoice_details))
<div class="item pt-3 invoice-billing">
    <div class="title">{{ get_phrase('Bill to') }}</div>
    <p class="sub-title" style="overflow-wrap:anywhere;line-height:1.7">
        <strong>{{ $billing['name'] }}</strong><br>
        @if($billing['registration_number']){{ get_phrase('Registration number') }}: {{ $billing['registration_number'] }}<br>@endif
        {{ get_phrase('Billing Address') }}: {{ $billing['address'] ?: get_phrase('Not provided — update the student profile') }}<br>
        {{ get_phrase('Email') }}: {{ $billing['email'] ?: get_phrase('Not provided') }}<br>
        {{ get_phrase('Phone') }}: {{ $billing['phone'] ?: get_phrase('Not provided — update the student profile') }}
    </p>
</div>
<style>
@media print {
    body * { visibility: hidden; }
    body { background: #fff !important; }
    .sidebar, .home-content, .mainSection-title, footer { display: none !important; }
    .home-section, .sidebar.close ~ .home-section { position: static !important; left: 0 !important; width: 100% !important; }
    .main_content { padding: 0 !important; }
    #printableDiv, #printableDiv * { visibility: visible; }
    #printableDiv { position: absolute; left: 0; top: 0; width: 100%; }
}
</style>
