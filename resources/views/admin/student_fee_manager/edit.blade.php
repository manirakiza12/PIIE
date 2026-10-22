<form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.fee_manager.update', ['id' => $invoice_details->id]) }}">
	@csrf 
	<div class="form-row">
		<div class="fpb-7">
            <label class="eForm-label">{{ get_phrase('Student') }}</label>
            <p class="form-control-plaintext mb-0">{{ $student->name }}</p>
            <small class="text-muted">{{ get_phrase('This invoice remains linked to this student. Edit the student’s class or programme from the student record, not from a fee invoice.') }}</small>
        </div>

        <div class="fpb-7">
            <label for="title"  class="eForm-label">{{ get_phrase('Invoice title') }}</label>
            <input type="text" class="form-control eForm-control" id="title" name = "title" value="{{ $invoice_details['title'] }}" required>
        </div>

        <div class="fpb-7">
            <label for="amount" class="eForm-label">{{ get_phrase('Invoice amount before discount').'('.school_currency().')' }}</label>
            <input type="number" min="0" step="0.01" class="form-control eForm-control" id="amount" name="amount" value="{{ old('amount', (float) $invoice_details->amount > 0 ? $invoice_details->amount : $invoice_details->total_amount) }}" required>
        </div>
                            
        <div class="fpb-7">
			<label for="discounted_price" class="eForm-label">{{ get_phrase('Discount amount').'('.school_currency().')' }}</label>
			<input type="number" step="0.01" class="form-control eForm-control" id="discounted_price" name="discounted_price" oninput="calculateDiscountPercentage(this.value)" min="0" value="{{ old('discounted_price', $invoice_details->discounted_price ?? 0) }}">

            <small class="text-muted discount_price_text"><?php echo get_phrase('This student has'); ?> <span id = "discounted_percentage" class="text-danger">0%</span> <?php echo get_phrase('discount'); ?></small>
		</div>

        <div class="fpb-7">
            <label for="paid_amount" class="eForm-label">{{ get_phrase('Paid amount').'('.school_currency().')' }}</label>
            <p class="form-control-plaintext">Recorded automatically from verified payments</p>
        </div>

        <div class="fpb-7">
            <label for="status" class="eForm-label">{{ get_phrase('Status') }}</label>
            <p class="form-control-plaintext">Updated automatically after PesaPal verification</p>
        </div>

        <div class="fpb-7">
			<label for="payment_method" class="eForm-label">{{ get_phrase('Payment method') }}</label>
			<p class="form-control-plaintext">PesaPal (verified online payment)</p>
		</div>
		
	</div>

	<div class="form-group  col-md-12">
        <button class="btn-form" type="submit">{{ get_phrase('Update invoice') }}</button>
    </div>

</form>

<script type="text/javascript">

  "use strict";

  jQuery(document).ready(function() {
		calculateDiscountPercentage($('#discounted_price').val());
	});
	

	function calculateDiscountPercentage(discounted_price) {
    if (discounted_price > 0) {
        var actualPrice = jQuery('#amount').val();
        if (actualPrice > 0) {
            var discountedPercentage = (discounted_price / actualPrice) * 100;
            if (discountedPercentage > 0) {
                jQuery('#discounted_percentage').text(discountedPercentage.toFixed(2) + '%');
            } else {
                jQuery('#discounted_percentage').text('0%');
            }
        }
    }
}
	
</script>
