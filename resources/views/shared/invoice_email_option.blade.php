<div class="fpb-7">
    @if(\App\Support\Billing\InvoiceEmail::automatic((int) auth()->user()->school_id))
    <p class="text-muted mb-0">{{ get_phrase('Automatic invoice emailing is enabled for this school. This invoice will be emailed when saved.') }}</p>
    @else
    <input type="hidden" name="email_invoice" value="0">
    <label class="d-flex gap-2 align-items-start">
        <input class="form-check-input mt-1" type="checkbox" name="email_invoice" value="1">
        <span>{{ get_phrase('Email invoice to the student automatically when saved') }}</span>
    </label>
    @endif
</div>
