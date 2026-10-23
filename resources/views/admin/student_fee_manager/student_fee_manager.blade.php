@extends('admin.navigation')
   
@section('content')
@include('shared.invoice_email_settings')
@include('shared.student_fee_overview', ['feeArea' => 'admin'])
<div class="mainSection-title">
    <div class="row">
	    <div class="col-12">
	        <div
	          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
	        >
				<div class="d-flex flex-column">
					<h4>{{ get_phrase('Student Fee Manager') }}</h4>
					<ul class="d-flex align-items-center eBreadcrumb-2">
						<li><a href="#">{{ get_phrase('Home') }}</a></li>
						<li><a href="#">{{ get_phrase('Accounting') }}</a></li>
						<li><a href="#">{{ get_phrase('Student Fee Manager') }}</a></li>
					</ul>
				</div>
	          	<div class="row mb-3">
	                <div class="expense_add">
	                    <a href="javascript:;" class="btn btn-outline-primary float-end m-1" data-bs-toggle="tooltip" onclick="rightModal('{{ route('admin.fee_manager.open_modal', ['value' => 'single']) }}', '{{ get_phrase('Add Single Invoice') }}')"><i class="bi bi-plus"></i>{{ get_phrase('Add Single Invoice') }}</a>
	                    <a href="javascript:;" class="btn btn-outline-success float-end m-1" data-bs-toggle="tooltip" onclick="rightModal('{{ route('admin.fee_manager.open_modal', ['value' => 'mass']) }}', '{{ get_phrase('Add Mass Invoice') }}')"><i class="bi bi-plus"></i>{{ get_phrase('Add Mass Invoice') }}</a>
	                </div>
	            </div>
	        </div>
	    </div>
    </div>
</div>
<div class="row">
    <div class="col-12">
        <div class="eSection-wrap-2">
            <form method="GET" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.fee_manager.list') }}">
                <h5>Invoice report by creation date</h5>
                <p>This report counts invoices, not students. Date, class, programme and status filters apply only to this report. Paid Amount includes verified prior payments; see the student summary above for separate prior cash, new collections and awards.</p>
                @if($feeSessions->contains('id', $feeSessionId))<input type="hidden" name="fee_session_id" value="{{ $feeSessionId }}">@endif
                <input type="hidden" name="fee_search" value="{{ request('fee_search') }}">
            	<div class="row">
                    <div class="row justify-content-md-center">
                    	@if($date_from != "" && $date_to !="")
                    		<div class="col-xl-3 mb-3">
                                <input type="text" class="form-control eForm-control" name="eDateRange"
                                    value="{{ date('m/d/Y', $date_from).' - '.date('m/d/Y', $date_to) }}" />
                            </div>
                        @else
	            			<div class="col-xl-3 mb-3">
                                <input type="text" class="form-control eForm-control" name="eDateRange"
                                    value="{{ date('m/d/Y', strtotime(' -30 day')).' - '.date('m/d/Y') }}" />
                            </div>
		                @endif
						<div class="col-xl-2 mb-3">
							@if(isset($selected_class) && $selected_class != "")
					            <div class="form-group">
									<select name="class" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove">
										<option value="all">{{ get_phrase('All class') }}</option>
										@foreach($classes as $class)
											<option value="{{ $class['id'] }}" {{ $class['id'] == $selected_class ?  'selected':'' }}>{{ $class['name'] }}</option>
										@endforeach
									</select>
					            </div>
					        @else
					        	<div class="form-group">
									<select name="class" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove">
										<option value="all">{{ get_phrase('All class') }}</option>
										<?php foreach($classes as $class){
										  ?>
										  <option value="{{ $class['id'] }}">{{ $class['name'] }}</option>
										<?php } ?>
									</select>
					            </div>
					        @endif
				        </div>
				@if($programmes->count() > 0)
				<div class="col-xl-2 mb-3">
					<div class="form-group">
						<select name="programme" id="programme_id" class="form-select eForm-select eChoice-multiple-with-remove">
							<option value="all">{{ get_phrase('All programmes') }}</option>
							@foreach($programmes as $programme)
								<option value="{{ $programme->id }}" {{ (isset($selected_programme) && (string) $programme->id === (string) $selected_programme) ? 'selected' : '' }}>{{ $programme->name }}</option>
							@endforeach
						</select>
					</div>
				</div>
				@endif
				        <div class="col-xl-2 mb-3">
				        	@if(isset($selected_status) && $selected_status != "")
					            <div class="form-group">
					              <select name="status" id="status" class="form-select eForm-select eChoice-multiple-with-remove">
					                <option value="all" {{ $selected_status == "all" ?  'selected':'' }}>{{ get_phrase('All status') }}</option>
					                <option value="paid" {{ $selected_status == "paid" ?  'selected':'' }}>{{ get_phrase('Paid') }}</option>
					                <option value="unpaid" {{ $selected_status == "unpaid" ?  'selected':'' }}>{{ get_phrase('Unpaid') }}</option>
					              </select>
					            </div>
					        @else
						        <div class="form-group">
					              <select name="status" id="status" class="form-select eForm-select eChoice-multiple-with-remove">
					                <option value="all">{{ get_phrase('All status') }}</option>
					                <option value="paid">{{ get_phrase('Paid') }}</option>
					                <option value="unpaid">{{ get_phrase('Unpaid') }}</option>
					              </select>
					            </div>
					        @endif
				        </div>
				        <div class="col-xl-2 mb-3">
				            <button type="submit" class="eBtn eBtn btn-secondary form-control">{{ get_phrase('Filter') }}</button>
				        </div>
				        <?php 
		            	if($selected_class == ""){
						    $sel_class = 'all';
						} else {
						    $sel_class = $selected_class;
						}

						if($date_from == "") {
						    $date_from = strtotime(date('d-M-Y', strtotime(' -30 day')));
						} else {
						    $date_from = strtotime(date('d-M-Y', $date_from));
						}

						if($date_to == "") {
						    $date_to = strtotime(date('d-M-Y'));
						} else {
						    $date_to = strtotime(date('d-M-Y', $date_to));
						}

						if($selected_status == ""){
						    $sel_status = 'all';
						} else {
						    $sel_status = $selected_status;
						}
		            	?>
				        @if(count($invoices) > 0)
				        <div class="col-md-2 ">
		                    <a class="eBtn-3 float-end" id="download-button" href="{{ route('admin.fee_manager.export', ['date_from' => $date_from, 'date_to' => $date_to, 'selected_class' => $sel_class, 'selected_status' => $sel_status, 'fee_session_id' => $feeSessionId]) }}">
		                        <span class="pr-10">
		                          <svg xmlns="http://www.w3.org/2000/svg" width="12.31" height="10.77" viewBox="0 0 10.771 12.31">
		                            <path id="arrow-right-from-bracket-solid" d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z" transform="translate(0 12.31) rotate(-90)" fill="#F15F23"></path>
		                          </svg>
		                        </span>
		                        {{ get_phrase('Export CSV') }}
		                    </a>
		                </div>
	                    @endif
            		</div>
            	</div>
            </form>
            
            <div class="invoice_content" id="student_fee_manager">
	            @include('admin.student_fee_manager.list')
	        </div>
		</div>
	</div>
</div>

@endsection
