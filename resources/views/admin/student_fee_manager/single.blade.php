<form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.create.fee_manager', ['value' => 'single']) }}">
	@csrf
@include('shared.invoice_email_option')
	<div class="form-row">
		<div class="fpb-7">
            <label for="class_id_on_create" class="eForm-label">{{ get_phrase('Class') }}</label>
            <select name="class_id" id="class_id_on_create" class="form-select eForm-control" required onchange="classWiseStudentOnCreate(this.value)" required>
                <option value="">{{ get_phrase('Select a class') }}</option>
                @foreach($classes as $class)
                    <option value="{{ $class->id }}">{{ $class->name }}</option>
                @endforeach
            </select>
        </div>

		<div class="fpb-7">
            <label for="section_id_on_taking_attendance" class="eForm-label">{{ get_phrase('Section') }}</label>
            <select name="section_id" id="section_id_on_taking_attendance" class="form-select eForm-control" required required onchange="classWiseStudentOnList(this.value)">
                <option value="">{{ get_phrase('Select section') }}</option>
            </select>
        </div>

		<div class="fpb-7">
		  <label for="student_id_on_create" class="eForm-label">{{ get_phrase('Select student') }}</label>
		  <div id = "student_content">
		    <select name="student_id" id="student_id_on_create" class="form-select eForm-control" required >
		      	<option value="">{{ get_phrase('Select a student') }}</option>
		    </select>
		  </div>
		</div>

		<div class="fpb-7">
			<label for="title" class="eForm-label">{{ get_phrase('Invoice title') }}</label>
			<input type="text" class="form-control eForm-control" id="title" name = "title" required>
		</div>

		<div class="fpb-7">
			<label for="amount" class="eForm-label">{{ get_phrase('Total amount').'('.school_currency().')' }}</label>
			<input type="number" class="form-control eForm-control" id="amount" name = "amount" required>
		</div>

		<div class="fpb-7">
			<label for="discounted_price" class="eForm-label">{{ get_phrase('Discount amount').'('.school_currency().')' }}</label>
			<input type="number" class="form-control eForm-control" id="discounted_price" name = "discounted_price" onkeyup="calculateDiscountPercentage(this.value)" min="0">

            <small class="text-muted discount_price_text"><?php echo get_phrase('This student has'); ?> <span id = "discounted_percentage" class="text-danger">0%</span> <?php echo get_phrase('discount'); ?></small>
		</div>

		<div class="fpb-7">
			<label for="paid_amount" class="eForm-label">{{ get_phrase('Paid amount').'('.school_currency().')' }}</label>
			<input type="number" class="form-control eForm-control" id="paid_amount" name = "paid_amount" required>
		</div>

		<div class="fpb-7">
			<label for="status" class="eForm-label">{{ get_phrase('Status') }}</label>
			<select name="status" id="status" class="form-select eForm-control" required >
				<option value="">{{ get_phrase('Select a status') }}</option>
				<option value="paid">{{ get_phrase('Paid') }}</option>
				<option value="unpaid">{{ get_phrase('Unpaid') }}</option>
			</select>
		</div>

		<div class="fpb-7">
			<label for="payment_method" class="eForm-label">{{ get_phrase('Payment method') }}</label>
			<select name="payment_method" id="payment_method" class="form-select eForm-control">
				<option value="">{{ get_phrase('Select a payment method') }}</option>
				<option value="cash">{{ get_phrase('Cash') }}</option>
				<option value="paypal">{{ get_phrase('Paypal') }}</option>
				<option value="paytm">{{ get_phrase('Paytm') }}</option>
				<option value="razorpay">{{ get_phrase('Razorpay') }}</option>
				<option value="stripe">{{ get_phrase('Stripe') }}</option>
				<option value="flutterwave">{{ get_phrase('Flutterwave') }}</option>
				
			</select>
		</div>

	</div>
	<div class="form-group  col-md-12">
		<button class="btn-form" type="submit">{{ get_phrase('Create invoice') }}</button>
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
	function classWiseStudentOnCreate(classId) {
        let url = "{{ route('admin.class_wise_sections', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#section_id_on_taking_attendance').html(response);
            }
        });
    }

	function classWiseStudentOnList(sectionId, classId) {
        let url = "{{ route('admin.class_wise_student_invoice', ['id' => ":sectionId"],['id' => ":classId"]) }}";
        url = url.replace(":sectionId", sectionId, ":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#student_id_on_create').html(response);
            }
        });
    }
	
  $(document).ready(function () {
    $(".eChoice-multiple-with-remove").select2();
  });	

</script>
