{{--
    Application fee: what is owed, how to pay it, and what has been submitted
    so far. Shared by the wizard's Payment step and the standalone Fee page.
    Expects: $admission, $amount, $methods, $payments.
--}}

@php
    use App\Support\Admissions\ApplicationFee;

    $settled  = $admission->isFeeSettled();
    $pending  = $admission->fee_status === \App\Models\Admission::FEE_PENDING;
    $gateways = collect($methods);
    $pendingPesaPal = $payments->contains(fn ($payment) => $payment->method === 'pesapal' && $payment->status === 'pending');
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

@if(ApplicationFee::isRequired($admission))
<div class="ap-card">
    <div class="ap-card-head">
        <h2 class="ap-card-title"><i class="bi bi-upc-scan"></i> Payment Registration Number (PRN)</h2>
    </div>
    <p class="ap-hint mb-2">This identifies your application fee and is attached to your PesaPal checkout automatically. For automatic confirmation, start payment here and choose an option offered by PesaPal; a bank transfer made outside PesaPal cannot be automatically matched.</p>
    <code class="d-inline-block p-2 rounded bg-light" style="font-size:16px; user-select:all;">{{ ApplicationFee::registrationNumber($admission) }}</code>
</div>
@endif

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
            {{ get_phrase('Payment confirmation is outstanding. PesaPal will verify your payment directly.') }}
        </div>
    </div>
@endif

@unless($settled)
    <div class="row g-4 mt-1">
        {{-- PesaPal hosted payment --}}
        @forelse($gateways as $gateway)
            <div class="col-12">
                <div class="ap-card h-100">
                    <div class="ap-card-head">
                        <h2 class="ap-card-title"><i class="bi bi-lightning-charge"></i> {{ get_phrase('Pay Online') }}</h2>
                    </div>

                    <p class="ap-hint mb-2">Choose an available option on PesaPal's secure checkout, such as MTN Mobile Money, Airtel Money, a card or bank transfer. Options depend on your currency and merchant account.</p>
                    <p class="ap-hint mb-3">Approve Mobile Money on your phone when prompted. Enter card details only on PesaPal's hosted page. A prompt or a return to PIIE does not confirm payment; PIIE checks the result with PesaPal.</p>
                    @if(!($gateway['configured'] ?? false))
                        <div class="alert alert-warning" role="status">PesaPal is not ready to accept payments yet. Admissions must finish the secure PesaPal configuration and HTTPS notification setup.</div>
                    @elseif($sandboxCheckoutBlocked)
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

                    <form action="{{ route('applicant.payment.gateway.start', $gateway['key']) }}" method="POST" class="mb-2">
                        @csrf
                            <label class="form-label" for="payer-phone">Payer mobile number <span class="text-muted">(optional; enter another person's number for the mobile money prompt)</span></label>
                            <input class="form-control mb-2" type="tel" inputmode="tel" id="payer-phone" name="payer_phone" maxlength="20" autocomplete="tel" placeholder="Leave blank to use the applicant's number" value="{{ old('payer_phone') }}" @disabled($pendingPesaPal)>
                            <button type="submit" class="ap-btn ap-btn-accent w-100" @disabled(!($gateway['configured'] ?? false) || $sandboxCheckoutBlocked || !\App\Support\Payments\ApplicantPesaPalPayment::eligible($admission))>
                            <i class="bi {{ $gateway['icon'] }}"></i> {{ \App\Support\Payments\PesaPalPaymentExperience::checkoutLabel($payments) }}
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-warning">Application fee payment is temporarily unavailable. Please contact Admissions.</div></div>
        @endforelse
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
                                    @if($payment->status === 'pending' && filled($payment->gateway_txn_id))
                                        <form method="post" action="{{ route('applicant.payment.pesapal.status', $payment->id) }}" class="mt-2">@csrf
                                            <label class="form-label" for="pesapal-prn-{{ $payment->id }}">PesaPal confirmation code <span class="text-muted">(optional)</span></label>
                                            <div class="d-flex flex-wrap gap-2"><input id="pesapal-prn-{{ $payment->id }}" name="confirmation_code" class="form-control" maxlength="100" autocomplete="off" placeholder="Enter PesaPal confirmation code"><button type="submit" class="ap-btn" @disabled(config('sandbox.connectivity') !== null && !config('sandbox.connectivity.transactions', false))>Verify payment with PesaPal</button></div>
                                            <p class="ap-hint mt-1 mb-0">PIIE verifies this code against PesaPal’s completed transaction. A typed code alone never marks a fee as paid.</p>
                                        </form>
                                    @endif
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
