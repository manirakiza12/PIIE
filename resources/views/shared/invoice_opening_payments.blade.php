@php
    $priorPayments = \Illuminate\Support\Facades\Schema::hasTable('student_opening_payments')
        ? \App\Models\StudentOpeningPayment::where('school_id', $invoice_details['school_id'])->where('fee_id', $invoice_details['id'])
            ->whereNull('reversed_at')->orderBy('paid_on')->get() : collect();
@endphp
@if($priorPayments->isNotEmpty())
<div class="item pt-3">
<strong>Previously paid before joining the system</strong>
@foreach($priorPayments as $prior)
<p>{{ $prior->paid_on->format('d M Y') }} · Receipt {{ $prior->reference }} · {{ number_format($prior->amount, 2) }}</p>
@endforeach
<p>These verified prior payments are included in the paid amount and deducted from the balance. They are not new collections.</p>
</div>
@endif
