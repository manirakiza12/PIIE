@if(\Illuminate\Support\Facades\Schema::hasTable('invoice_email_deliveries') && app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.invoices'))
    @php
        $emailPortal = str_starts_with(request()->route()?->getName() ?? '', 'bursar.') ? 'bursar' : ((int) auth()->user()->role_id === 4 ? 'accountant' : 'admin');
        $delivery = \Illuminate\Support\Facades\DB::table('invoice_email_deliveries')->where('school_id', auth()->user()->school_id)->where('invoice_id', $invoice_details['id'])->first();
    @endphp
    <form method="POST" action="{{ route($emailPortal.'.invoice_email.send', $invoice_details['id']) }}" class="d-inline-block ms-2 mt-2" data-workspace-continuous>
        @csrf
        <button type="submit" class="btn btn-primary">{{ get_phrase($delivery?->status === 'sent' ? 'Resend invoice email' : 'Email invoice') }}</button>
    </form>
    @if($delivery)
        <p class="text-muted small mt-2">{{ get_phrase('Invoice email') }}: {{ get_phrase(ucfirst($delivery->status)) }}@if($delivery->sent_at) · {{ $delivery->sent_at }}@endif</p>
    @endif
@endif
