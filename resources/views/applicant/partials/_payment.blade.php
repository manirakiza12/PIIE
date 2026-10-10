{{--
    Application fee: what is owed, how to pay it, and what has been submitted
    so far. Shared by the wizard's Payment step and the standalone Fee page.
    Expects: $admission, $amount, $methods, $bankDetails, $payments.
--}}

@php
    use App\Support\Admissions\ApplicationFee;

    $settled  = $admission->isFeeSettled();
    $pending  = $admission->fee_status === \App\Models\Admission::FEE_PENDING;
    $gateways = collect($methods)->where('key', '!=', 'offline');
    $sandboxCheckoutBlocked = config('sandbox.connectivity') !== null
        && !(class_exists(\PiieSandbox\ControlledCheckout::class)
            && \PiieSandbox\ControlledCheckout::buttonEnabled($admission));
@endphp

<div class="ap-card">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="ap-icon-tile {{ $settled ? 'ap-tile-green' : 'ap-tile-amber' }}">
                <i class="bi {{ $settled ? 'bi-check2-circle' : 'bi-credit-card' }}"></i>
            </div>
            <div>
                <h2 class="ap-card-title mb-1">{{ get_phrase('Application Fee') }}</h2>
                <div style="color:var(--ap-muted); font-size:14px;">
                    {{ optional($admission->intakeSession)->name ?: get_phrase('Selected intake') }}
                </div>
            </div>
        </div>

        <div class="text-end">
            <div style="font-size:24px; font-weight:700;">{{ ApplicationFee::format((float) $amount, $admission) }}</div>
            <span class="ap-pill bg-{{ $settled ? 'success' : ($pending ? 'primary' : 'warning') }} bg-opacity-10 text-{{ $settled ? 'success' : ($pending ? 'primary' : 'warning') }}">
                {{ get_phrase(ucfirst($admission->fee_status)) }}
            </span>
        </div>
    </div>
</div>

@if($settled)
    <div class="ap-card">
        <div class="d-flex align-items-start gap-3">
            <i class="bi bi-check-circle-fill" style="color:var(--ap-accent); font-size:24px;"></i>
            <div>
                <strong>{{ get_phrase('Your application fee is settled.') }}</strong>
                <p class="mb-0 ap-hint">{{ get_phrase('Nothing further is needed on this step.') }}</p>
            </div>
        </div>
    </div>
@elseif($pending)
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-hourglass-split mt-1"></i>
        <div>
            <strong>{{ get_phrase('Your payment is being verified.') }}</strong><br>
            {{ get_phrase('Payment confirmation is outstanding. Online payments are verified with the provider; deposit proof is reviewed by finance.') }}
        </div>
    </div>
@endif

@unless($settled)
    <div class="row g-4 mt-1">
        {{-- Online payment --}}
        @if($gateways->isNotEmpty())
            <div class="col-lg-5">
                <div class="ap-card h-100">
                    <div class="ap-card-head">
                        <h2 class="ap-card-title"><i class="bi bi-lightning-charge"></i> {{ get_phrase('Pay Online') }}</h2>
                    </div>

                    <p class="ap-hint mb-2">Choose MTN Mobile Money, Airtel Money or a bank card on PesaPal's secure checkout. Available methods depend on your currency and merchant account.</p>
                    <p class="ap-hint mb-3">Approve Mobile Money on your phone when prompted. Enter card details only on PesaPal's hosted page. A prompt or a return to PIIE does not confirm payment; PIIE checks the result with PesaPal.</p>
                    @if($sandboxCheckoutBlocked)
                        <div class="alert alert-info" role="status">
                            @if($payments->isNotEmpty())
                                The one-use sandbox checkout has already been used or closed. Do not start another payment. Your application fee is settled only after successful provider verification.
                            @else
                                Sandbox checkout is currently disabled. Your submitted application is saved; no payment attempt will be created. A separately approved, controlled PesaPal sandbox test is required before this button can be enabled.
                            @endif
                        </div>
                    @elseif(config('sandbox.connectivity') !== null)
                        <div class="alert alert-info" role="status">
                            One controlled sandbox checkout is authorized for this application only. Complete the payment yourself on PesaPal. The fee is marked paid only after provider verification.
                        </div>
                    @endif

                    @foreach($gateways as $gateway)
                        <form action="{{ route('applicant.payment.gateway.start', $gateway['key']) }}" method="POST" class="mb-2">
                            @csrf
                            @if($gateway['key'] === 'marzpay')
                                <label class="form-label" for="marzpay_phone_number">{{ get_phrase('Mobile Money Number') }}</label>
                                <input type="tel" class="form-control mb-2" id="marzpay_phone_number" name="phone_number" placeholder="e.g. 0712345678" required>
                            @endif
                            <button type="submit" class="ap-btn ap-btn-accent w-100" @disabled($sandboxCheckoutBlocked || !\App\Support\Payments\ApplicantPesaPalPayment::eligible($admission))>
                                <i class="bi {{ $gateway['icon'] }}"></i> {{ $gateway['key']==='pesapal' ? \App\Support\Payments\PesaPalPaymentExperience::checkoutLabel($payments) : $gateway['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Bank deposit --}}
        <div class="{{ $gateways->isNotEmpty() ? 'col-lg-7' : 'col-12' }}">
            <div class="ap-card h-100">
                <div class="ap-card-head">
                    <h2 class="ap-card-title"><i class="bi bi-bank"></i> {{ get_phrase('Bank Deposit / Mobile Money') }}</h2>
                </div>

                @if($bankDetails)
                    <div class="p-3 mb-3" style="background:#f9fafb; border:1px solid var(--ap-line); border-radius:9px; white-space:pre-line; font-size:14px;">{{ $bankDetails }}</div>
                @else
                    <div class="alert alert-warning py-2 px-3" style="font-size:14px;">
                        {{ get_phrase('Contact the admissions office for the institution bank details, then record your payment below.') }}
                    </div>
                @endif

                <form action="{{ route('applicant.payment.offline') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">{{ get_phrase('Transaction / Deposit Reference') }} <span class="req">*</span></label>
                            <input type="text" name="reference" class="form-control" value="{{ old('reference') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ get_phrase('Proof of Payment') }} <span class="req">*</span></label>
                            <input type="file" name="proof" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            <div class="ap-hint">{{ get_phrase('A photo of the deposit slip or a screenshot of the transaction message.') }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">{{ get_phrase('Note') }}</label>
                            <input type="text" name="note" class="form-control" value="{{ old('note') }}"
                                   placeholder="{{ get_phrase('Optional — anything the finance office should know.') }}">
                        </div>
                    </div>

                    <button type="submit" class="ap-btn ap-btn-primary mt-3">
                        <i class="bi bi-send"></i> {{ get_phrase('Submit Payment Details') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
@endunless

@if($payments->isNotEmpty())
    <div class="ap-card mt-4">
        <div class="ap-card-head">
            <h2 class="ap-card-title"><i class="bi bi-receipt"></i> {{ get_phrase('Payment History') }}</h2>
        </div>

        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:14px;">
                <thead>
                    <tr style="color:var(--ap-muted); font-size:12.5px; text-transform:uppercase;">
                        <th>{{ get_phrase('Date') }}</th>
                        <th>{{ get_phrase('Method') }}</th>
                        <th>{{ get_phrase('Reference') }}</th>
                        <th>{{ get_phrase('Amount') }}</th>
                        <th>{{ get_phrase('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payments as $payment)
                        <tr>
                            <td>{{ $payment->created_at->format('d M Y') }}</td>
                            <td>{{ ucfirst($payment->method) }}</td>
                            <td style="word-break:break-all;">{{ $payment->reference ?: '—' }}</td>
                            <td>{{ ApplicationFee::format((float) $payment->amount, $admission) }}</td>
                            <td>
                                @php
                                    $tone = ['paid' => 'success', 'waived' => 'success', 'pending' => 'primary', 'failed' => 'danger', 'rejected' => 'danger'][$payment->status] ?? 'secondary';
                                    $experience=$payment->method==='pesapal'?\App\Support\Payments\PesaPalPaymentExperience::forPayment($payment):null;
                                @endphp
                                <span class="ap-pill bg-{{ $experience['tone'] ?? $tone }} bg-opacity-10 text-{{ $experience['tone'] ?? $tone }}">{{ $experience['label'] ?? get_phrase(ucfirst($payment->status)) }}</span>
                                @if($payment->method === 'pesapal')
                                    <p class="ap-hint mb-1">{{ $experience['message'] }}</p>
                                    @if($experience['reason'])<p class="ap-hint mb-1">{{ $experience['reason'] }}</p>@endif
                                    @if($payment->gateway_payload['last_checked_at'] ?? null)<div class="ap-hint mb-1">Last provider check: {{ \Carbon\Carbon::parse($payment->gateway_payload['last_checked_at'])->format('d M Y H:i T') }}</div>@endif
                                    <form method="post" action="{{ route('applicant.payment.pesapal.status', $payment->id) }}">@csrf<button type="submit" class="ap-btn" @disabled(config('sandbox.connectivity') !== null && !config('sandbox.connectivity.transactions', false))>Check existing payment</button></form>
                                @endif
                                @if($payment->status === 'rejected' && $payment->note)
                                    <div class="ap-hint text-danger">{{ $payment->note }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
