@if($financialSetupReview)
@php
    $review = $financialSetupReview;
    $items = [
        'prior_payment' => ['label' => 'Prior cash payment', 'payload' => $review->prior_payment, 'status' => $review->prior_payment_status],
        'award' => ['label' => 'Scholarship / bursary', 'payload' => $review->award, 'status' => $review->award_status],
        'study_waiver' => ['label' => '20% study-requirement waiver', 'payload' => $review->study_waiver, 'status' => $review->study_waiver_status],
    ];
@endphp
<section class="border border-primary rounded p-3 my-3" aria-label="Existing student financial setup review">
    <h2 class="h5">Financial setup review</h2>
    <p>Captured {{ $review->created_at }} by staff account #{{ $review->captured_by }} for this invoice and study session. Review and confirm each item here; staff do not need to re-enter the captured details.</p>
    <p><strong>Current balance:</strong> {{ number_format(max(0, $fee->total_amount - $fee->paid_amount), 2) }}<br>
        <strong>Projected balance after pending payment and award confirmation:</strong> {{ number_format($projectedBalance, 2) }}
    </p>
    <p class="small text-muted">The projected figure is for review only. Pending items have not changed the invoice. The full agreed fee remains on the invoice; the study waiver changes only the 20% study-registration threshold and never reduces fees or replaces academic eligibility.</p>
    @foreach($items as $key => $item)
        @if($item['payload'])
            <div class="border-top pt-3 mt-3">
                <h3 class="h6">{{ $item['label'] }}</h3>
                <span class="badge {{ $item['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-success' }}">
                    {{ $item['status'] === 'pending' ? 'Awaiting confirmation — not yet applied' : 'Confirmed' }}
                </span>
                @if($key === 'prior_payment')
                    <p class="mb-1">Amount: {{ school_currency($item['payload']['amount']) }} · Original date: {{ $item['payload']['paid_on'] }} · Method: {{ str_replace('_', ' ', ucfirst($item['payload']['method'])) }}</p>
                    <p class="mb-1">Receipt/reference: {{ $item['payload']['reference'] }}</p>
                    <p class="mb-1">Verification note: {{ $item['payload']['reason'] }}</p>
                @elseif($key === 'award')
                    <p class="mb-1">{{ ucfirst($item['payload']['type']) }} · Amount covered: {{ school_currency($item['payload']['amount']) }}</p>
                    <p class="mb-1">Approval reference: {{ $item['payload']['reference'] }}</p>
                    <p class="mb-1">Reason: {{ $item['payload']['reason'] }}</p>
                @else
                    <p class="mb-1">Study session: {{ $fee->session_id }} · Reason: {{ $item['payload']['reason'] }}</p>
                    <p class="small text-muted">Confirmation waives the study payment threshold only. Academic requirements still apply.</p>
                @endif
                @if($item['status'] === 'confirmed')
                    <p class="small mb-0">Confirmed {{ $review->{$key . '_confirmed_at'} }} by staff account #{{ $review->{$key . '_confirmed_by'} }}. The corresponding ledger retains its full audit and reversal history.</p>
                @elseif($canConfirmFinancialSetup[$key] ?? false)
                    <form method="post" action="{{ route($area . '.tuition_payments.setup.confirm', ['id' => $fee->id, 'item' => $key]) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="btn btn-primary">Confirm and apply {{ strtolower($item['label']) }}</button>
                    </form>
                @else
                    <p class="small mb-0">An authorized reviewer must confirm this item. It is not yet applied.</p>
                @endif
            </div>
        @endif
    @endforeach
</section>
@endif
