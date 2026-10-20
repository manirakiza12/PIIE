<?php 

use App\Models\Package;
use App\Models\School;

?>

@extends('superadmin.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Expired Subscription') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Subscriptions') }}</a></li>
                        <li><a href="#">{{ get_phrase('Expired Subscription') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
        	<div class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15 pb-4">
                <form action="{{ route('superadmin.subscription.expired_subcription') }}">
                    <div
                      class="search-input d-flex justify-content-start align-items-center"
                    >
                      <span>
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width="16"
                          height="16"
                          viewBox="0 0 16 16"
                        >
                          <path
                            id="Search_icon"
                            data-name="Search icon"
                            d="M2,7A4.951,4.951,0,0,1,7,2a4.951,4.951,0,0,1,5,5,4.951,4.951,0,0,1-5,5A4.951,4.951,0,0,1,2,7Zm12.3,8.7a.99.99,0,0,0,1.4-1.4l-3.1-3.1A6.847,6.847,0,0,0,14,7,6.957,6.957,0,0,0,7,0,6.957,6.957,0,0,0,0,7a6.957,6.957,0,0,0,7,7,6.847,6.847,0,0,0,4.2-1.4Z"
                            fill="#797c8b"
                          />
                        </svg>
                      </span>
                      <input
                        type="text"
                        id="search"
                        name="search"
                        value="{{ $search }}"
                        placeholder="Search Expired Subscription"
                        class="form-control"
                      />
                    </div>
                  </form>
              <!-- Export Button -->
              @if(count($subscriptions) > 0)
              <div class="position-relative">
                <button
                  class="eBtn-3 dropdown-toggle"
                  type="button"
                  id="defaultDropdown"
                  data-bs-toggle="dropdown"
                  data-bs-auto-close="true"
                  aria-expanded="false"
                >
                  <span class="pr-10">
                    <svg
                      xmlns="http://www.w3.org/2000/svg"
                      width="12.31"
                      height="10.77"
                      viewBox="0 0 10.771 12.31"
                    >
                      <path
                        id="arrow-right-from-bracket-solid"
                        d="M3.847,1.539H2.308a.769.769,0,0,0-.769.769V8.463a.769.769,0,0,0,.769.769H3.847a.769.769,0,0,1,0,1.539H2.308A2.308,2.308,0,0,1,0,8.463V2.308A2.308,2.308,0,0,1,2.308,0H3.847a.769.769,0,1,1,0,1.539Zm8.237,4.39L9.007,9.007A.769.769,0,0,1,7.919,7.919L9.685,6.155H4.616a.769.769,0,0,1,0-1.539H9.685L7.92,2.852A.769.769,0,0,1,9.008,1.764l3.078,3.078A.77.77,0,0,1,12.084,5.929Z"
                        transform="translate(0 12.31) rotate(-90)"
                        fill="#00a3ff"
                      />
                    </svg>
                  </span>
                  {{ get_phrase('Export') }}
                </button>
                <ul
                  class="dropdown-menu dropdown-menu-end eDropdown-menu-2"
                >
	                <li>
	                 	<a class="dropdown-item" id="pdf" href="javascript:;" onclick="download_pdf()">{{ get_phrase('PDF') }}</a>
	                </li>
	                <li>
	                	<a class="dropdown-item" id="print" href="javascript:;" onclick="report_print('subscription_report')">{{ get_phrase('Print') }}</a>
	                </li>
                </ul>
              </div>
              @endif
            </div>
        	@if(count($subscriptions) > 0)
        	@php $amount = 0; @endphp
        	<?php $amount = 0; ?>
			<?php foreach($subscriptions as $subscription):
				$amount = $amount + $subscription->paid_amount;
			endforeach; ?>
        	<div id="subscription_report">
	        	<div class="att-report-banner d-flex justify-content-center justify-content-md-between align-items-center flex-wrap">
		          <div class="att-report-summary order-1">
		            <h4 class="title">{{ get_phrase('Expired Subscription Report') }}</h4>
		            <p class="summary-item">{{ get_phrase('From') }}: <span>{{ date('m/d/Y', $date_from) }}</span></p>
		            <p class="summary-item">{{ get_phrase('To') }}: <span>{{ date('m/d/Y', $date_to) }}</span></p>
		            <p class="summary-item">{{ get_phrase('Total amount') }}: <span>{{ currency($amount) }}</span></p>
		          </div>
		          <div class="att-banner-img order-0 order-md-1">
		            <img
		              src="{{ asset('assets/images/attendance-report-banner.png') }}"
		              alt=""
		            />
		          </div>
		        </div>
	        	<div class="table-responsive pb-4">
					<table class="table eTable">
						<thead>
		                    <th>#</th>
		                    <th>{{ get_phrase('School Name') }}</th>
		                    <th>{{ get_phrase('Package') }}</th>
		                    <th>{{ get_phrase('Purchase Date') }}</th>
		                    <th>{{ get_phrase('Expire Date') }}</th>
		                </thead>
		                <tbody>
		                	@foreach($subscriptions as $key => $subscription)
		                		<?php 
		                		$package = Package::find($subscription->package_id);
		                		$school = School::find($subscription->school_id);
		                		$offline_tr_keys = json_decode($subscription->transaction_keys);
		                		?>
		                		<tr>
		                			<td>{{ $subscriptions->firstItem() + $key }}</td>
		                			@if(empty($school->title))
									        <td><strong>School Removed</strong></td>
									        @else
		                			<td><strong>{{ $school->title }}</strong></td>
									        @endif
		                			<td><strong>{{ $package->name }}</strong></td>
		                			<td>{{ date('d-M-Y', $subscription->date_added) }}</td>
		                			<td>{{ date('d-M-Y', $subscription->expire_date) }}</td>
		                		</tr>
		                	@endforeach
		                </tbody>
					</table>
					{!! $subscriptions->appends(request()->all())->links() !!}
				</div>
			</div>
			@else
			<div class="empty_box center">
                <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
                <br>
                {{ get_phrase('No date found') }}
            </div>
			@endif
		</div>
	</div>
</div>

<script type="text/javascript">

  	"use strict";

	function download_pdf() {

	    const element = document.getElementById("subscription_report");

	    // clone the element
	    var clonedElement = element.cloneNode(true);

	    // change display of cloned element 
	    $(clonedElement).css("display", "block");

	    // Choose the clonedElement and save the PDF for our user.
		var opt = {
		  margin:       1,
		  filename:     'subscription_report-{{ date('d-M-Y', $date_from).'-'.date('d-M-Y', $date_to) }}.pdf',
		  image:        { type: 'jpeg', quality: 0.98 },
		  html2canvas:  { scale: 2 },
		};

		// New Promise-based usage:
		window.exportReportPdf(clonedElement, opt.filename);

	    // remove cloned element
	    clonedElement.remove();
	}

	function report_print(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

</script>

@endsection