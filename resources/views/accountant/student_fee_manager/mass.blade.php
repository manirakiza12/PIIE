<form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('accountant.create.fee_manager', ['value' => 'mass']) }}">
	@csrf
@include('shared.invoice_email_option')
	<div class="form-row">
		<div class="fpb-7">
			<label for="class_id_on_create" class="eForm-label">{{ get_phrase('Class') }}</label>
			<select name="class_id" id="class_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove"  required onchange="classWiseSectionOnCreate(this.value)">
				<option value="">{{ get_phrase('Select a class') }}</option>
				@foreach($classes as $class)
			  		<option value="{{ $class['id'] }}">{{ $class['name'] }}</option>
				@endforeach
			</select>
		</div>

		<div class="fpb-7">
            <label for="section_id_on_create" class="eForm-label">{{ get_phrase('Section') }}</label>
            <select name="section_id" id = "section_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove" required>
                <option value="">{{ get_phrase('Select section') }}</option>
            </select>
        </div>

		<div class="fpb-7">
			<label for="title" class="eForm-label">{{ get_phrase('Invoice title') }}</label>
			<input type="text" class="form-control eForm-control" id="title" name="title" required>
		</div>

		<div class="fpb-7">
			<label for="total_amount" class="eForm-label">{{ get_phrase('Total amount').'('.school_currency().')' }}</label>
			<input type="number" class="form-control eForm-control" id="total_amount" name="total_amount" required>
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
	<div class="fpb-7 pt-2">
		<button class="btn-form" type="submit">{{ get_phrase('Create invoice') }}</button>
	</div>
</form>

<script type="text/javascript">

  "use strict";

	
	function classWiseSectionOnCreate(classId) {
		let url = "{{ route('class_wise_sections', ['id' => ":classId"]) }}";
    	url = url.replace(":classId", classId);
		$.ajax({
			url: url,
			success: function(response){
	            $('#section_id_on_create').html(response);
	        }
		});
	}

	$(document).ready(function () {
    $(".eChoice-multiple-with-remove").select2();
  });

</script>