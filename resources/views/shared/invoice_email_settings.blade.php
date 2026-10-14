@if(\Illuminate\Support\Facades\Schema::hasTable('invoice_email_preferences') && app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.invoices'))
    @php
        $emailPortal = str_starts_with(request()->route()?->getName() ?? '', 'bursar.') ? 'bursar' : ((int) auth()->user()->role_id === 4 ? 'accountant' : 'admin');
        $autoEmailInvoices = \App\Support\Billing\InvoiceEmail::automatic((int) auth()->user()->school_id);
    @endphp
    <form method="POST" action="{{ route($emailPortal.'.invoice_email.preferences') }}" class="my-3 d-flex flex-wrap gap-3 align-items-center" data-workspace-continuous>
        @csrf
        <input type="hidden" name="automatic" value="0">
        <label class="d-flex gap-2 align-items-center mb-0">
            <input class="form-check-input" type="checkbox" name="automatic" value="1" @checked($autoEmailInvoices)>
            <span>{{ get_phrase('Automatically email all new invoices') }}</span>
        </label>
        <button type="submit" class="btn btn-sm btn-outline-primary">{{ get_phrase('Save email preference') }}</button>
        <small class="text-muted">{{ get_phrase('Includes invoices generated at enrolment. Applies to future invoices.') }}</small>
    </form>
@endif
