@php
    $invoiceAward = \Illuminate\Support\Facades\Schema::hasTable('student_fee_awards')
        ? \App\Models\StudentFeeAward::where('school_id', $invoice_details['school_id'])
            ->where('fee_id', $invoice_details['id'])->whereNull('revoked_at')->first() : null;
@endphp
@if($invoiceAward)
@php($awardCurrency = $currency ?? \Illuminate\Support\Facades\DB::table('schools')->where('id', $invoice_details['school_id'])->value('school_currency'))
<div class="item pt-3">
    <strong>{{ ucfirst($invoiceAward->type) }}</strong>
    <p>Fee before award: {{ $awardCurrency }} {{ number_format($invoice_details['total_amount'] + $invoiceAward->amount, 2) }}<br>
    Amount covered: {{ $awardCurrency }} {{ number_format($invoiceAward->amount, 2) }}<br>
    Net fee: {{ $awardCurrency }} {{ number_format($invoice_details['total_amount'], 2) }}<br>
    @if($invoiceAward->reference)Approval reference: {{ $invoiceAward->reference }}@endif</p>
</div>
@endif
