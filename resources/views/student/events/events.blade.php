@extends('student.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Events') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Back Office') }}</a></li>
                        <li><a href="#">{{ get_phrase('Events') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap pb-2">
        	<div class="search-filter-area d-flex justify-content-md-between justify-content-center align-items-center flex-wrap gr-15">
		        <form action="{{ route('student.events.list') }}">
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
		              placeholder="Search Events"
		              class="form-control"
		            />
		          </div>
		        </form>
		        <!-- Export Button -->
		        @if(count($events) > 0)
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
		                <a class="dropdown-item" id="pdf" href="javascript:;" onclick="Export()">{{ get_phrase('PDF') }}</a>
		            </li>
		            <li>
		                <a class="dropdown-item" id="print" href="javascript:;" onclick="printableDiv('event_list')">{{ get_phrase('Print') }}</a>
		            </li>
		          </ul>
		        </div>
		        @endif
		    </div>
		    @if(count($events) > 0)
		    	<table id="basic-datatable" class="table eTable">
		    		<thead>
		    			<tr>
		    				<th scope="col">#</th>
							<th>{{ get_phrase('Event title') }}</th>
							<th>{{ get_phrase('Date') }}</th>
							<th>{{ get_phrase('Status') }}</th>
						</tr>
		    		</thead>
		    		<tbody>
		    			@foreach($events as $key => $event)
		    				<tr>
		    					<td>{{ $events->firstItem() + $key }}</td>
								<td>{{ $event['title'] }}</td>
								<td>{{ date('D, d M Y', $event['timestamp']) }}</td>
								<td>
									<?php if ($event['status']): ?>
										<span class="eBadge ebg-success">{{ get_phrase('Active') }}</span>
									<?php else: ?>
										<span class="eBadge ebg-danger">{{ get_phrase('Inactive') }}</span>
									<?php endif; ?>
								</td>
							</tr>
		    			@endforeach
		    		</tbody>
		    	</table>
		    	{!! $events->appends(request()->all())->links() !!}
		    @else
		    	<div class="empty_box center">
			        <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
			        <br>
			        <span class="">{{ get_phrase('No data found') }}</span>
			    </div>
		    @endif
		</div>
	</div>
</div>

@if(count($events) > 0)
<div class="table-responsive event_list display-none-view" id="event_list">
	<h4 class="" style="font-size: 16px; font-weight: 600; line-height: 26px; color: #181c32; margin-left:45%; margin-bottom:15px; margin-top:17px;">{{ get_phrase('Event List') }}</h4>
	<table id="basic-datatable" class="table eTable">
		<thead>
			<tr>
				<th scope="col">#</th>
				<th>{{ get_phrase('Event title') }}</th>
				<th>{{ get_phrase('Date') }}</th>
				<th>{{ get_phrase('Status') }}</th>
			</tr>
		</thead>
		<tbody>
			@foreach($events as $key => $event)
				<tr>
					<td>{{ $events->firstItem() + $key }}</td>
					<td>{{ $event['title'] }}</td>
					<td>{{ date('D, d M Y', $event['timestamp']) }}</td>
					<td>
						<?php if ($event['status']): ?>
							<span class="eBadge ebg-success">{{ get_phrase('Active') }}</span>
						<?php else: ?>
							<span class="eBadge ebg-danger">{{ get_phrase('Inactive') }}</span>
						<?php endif; ?>
					</td>
				</tr>
			@endforeach
		</tbody>
	</table>
	{!! $events->appends(request()->all())->links() !!}
</div>
@endif

<script type="text/javascript">

  "use strict";

  function Export() {

      // Choose the element that our invoice is rendered in.
      const element = document.getElementById("event_list");

      // clone the element
      var clonedElement = element.cloneNode(true);

      // change display of cloned element
      $(clonedElement).css("display", "block");

      // Choose the clonedElement and save the PDF for our user.
    var opt = {
      margin:       1,
      filename:     'event_list_{{ date("y-m-d") }}.pdf',
      image:        { type: 'jpeg', quality: 0.98 },
      html2canvas:  { scale: 2 }
    };

    // New Promise-based usage:
    window.exportReportPdf(clonedElement, opt.filename);

      // remove cloned element
      clonedElement.remove();
  }

  function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }

</script>

@endsection