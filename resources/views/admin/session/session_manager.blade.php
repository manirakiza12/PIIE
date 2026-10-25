@extends('admin.navigation')
   
@section('content')

<?php 

use App\Models\Session;

$current_session = get_school_settings(auth()->user()->school_id)->value('running_session');
$running_session = Session::where('school_id', auth()->user()->school_id)->find($current_session);

?>
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Finance and Class-based Sessions') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Settings') }}</a></li>
                        <li><a href="#">{{ get_phrase('Finance Sessions') }}</a></li>
                    </ul>
                </div>
                <div class="export-btn-area">
                    @if(app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'academic.structure.manage'))
                        <a href="{{ route('admin.academic_structure.index') }}" class="export_btn me-2">{{ get_phrase('Academic Years & Periods') }}</a>
                    @endif
                    <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.create.session') }}', '{{ get_phrase('Create Finance Session') }}')"><i class="bi bi-plus"></i>{{ get_phrase('Add Finance Session') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>

@if($sessions->isEmpty())
<div class="alert alert-warning mt-3" role="status">
    {{ get_phrase('No class-based sessions are configured yet. Add one here only if your school uses the existing class-based timetable and records.') }}
</div>
@endif

<div class="alert alert-info mt-3" role="note">
    <strong>{{ get_phrase('What this setting controls') }}</strong>
    {{ get_phrase('The active class-based session is used by existing class timetables and other class-based records. It is separate from the Academic Year and Term or Semester calendar used for course and programme planning. Activating a session here does not change that calendar selection; keep the dates aligned if your school uses both.') }}
    @if(app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'academic.structure.manage'))
        <a class="alert-link ms-1" href="{{ route('admin.academic_structure.index') }}">{{ get_phrase('Review Academic Years and Terms') }}</a>
    @endif
</div>
<div class="alert alert-light border mt-3" role="note">
    {{ get_phrase('Programme-fee invoices also require a Finance Session ID for invoice grouping, reporting, payment screens, and study-eligibility checks. Edit or create a session and link it to the Academic Year used for billing. A Fee Structure still determines the fee amount; Academic Year only supplies invoice context.') }}
    @if(app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'finance.invoices'))
        <a class="alert-link ms-1" href="{{ route('admin.academic_structure.index') }}">{{ get_phrase('Review Academic Years') }}</a>
    @endif
</div>

<div class="row">
	<div class="col-12">
		<div class="eSection-wrap">
			<div class="eMain">
				<div class="row">
					<div class="col-md-6 pb-3">
						<div class="eAlert eBtn-blueish" role="alert">
							{{ get_phrase('Active class-based session') }}
                            <span class="text-white eBtn-green p-1" id="active_session">{{ $running_session?->session_title ?? get_phrase('No active session selected') }}</span>
						</div>
						<div class="row">
							<div class="col-9">
								<select class="form-select eForm-select eChoice-multiple-with-remove" id="session_dropdown">
			                        <option value = "">{{ get_phrase('Select a session') }}</option>
			                        @foreach($sessions as $session)
			                        	<option value="{{ $session->id }}" {{ $session->status == 1 ? 'selected':'' }}>{{ $session->session_title }}</option>
			                    	@endforeach
			                	</select>
			                </div>
			                <div class="col-3">
		                		<button type="button" class="eBtn eBtn btn-secondary" onclick="makeSessionActive()"> <i class="mdi mdi-check"></i>{{ get_phrase('Activate') }}</button>
		                	</div>
		                </div>
					</div>
					<div class="col-md-6 pb-3">
						<table id="basic-table" class="table eTable">
			                <thead>
			                    <tr>
			                        <th>{{ get_phrase('Finance Session / billing period') }}</th>
			                        <th>{{ get_phrase('Invoice Academic Year') }}</th>
			                        <th>{{ get_phrase('Status') }}</th>
			                        <th class="text-center">{{ get_phrase('Options') }}</th>
			                    </tr>
			                </thead>
			                <tbody class="table_body">
			                    <?php
			                    foreach($sessions as $session):?>
			                    <tr>
			                        <td><strong>{{ $session['session_title'] }}</strong></td>
                                    <td>{{ $session->academicYear?->label ?? get_phrase('Not linked') }}</td>
			                        <td>
			                            <?php if($session['status'] == 1): ?>
			                                <span class="eBadge ebg-success">{{ get_phrase('Active') }}</span>
			                            <?php else: ?>
			                                <span class="eBadge ebg-danger">{{ get_phrase('Deactive') }}</span>
			                            <?php endif; ?>
			                        </td>
			                        <td class="text-start">
			                        	<div class="adminTable-action">
					                        <button
					                          type="button"
					                          class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
					                          data-bs-toggle="dropdown"
					                          aria-expanded="false"
					                        >
					                          {{ get_phrase('Actions') }}
					                        </button>
					                        <ul
					                          class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action"
					                        >
					                          <li>
					                            <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route('admin.edit.session', ['id' => $session->id]) }}', '{{ get_phrase('Edit Session') }}')">{{ get_phrase('Edit') }}</a>
					                          </li>
					                          <li>
					                            <a class="dropdown-item" href="javascript:;" onclick="confirmModal('{{ route('admin.session.delete', ['id' => $session->id]) }}', 'undefined');">{{ get_phrase('Delete') }}</a>
					                          </li>
					                        </ul>
					                    </div>
			                        </td>
			                    </tr>
				                <?php endforeach; ?>
				            </tbody>
				        </table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">

  "use strict";

	function makeSessionActive() {
	    var session_id = $('#session_dropdown').val();
	    let url = "{{ route('admin.session_manager.active_session', ['id' => ":session_id"]) }}";
    	url = url.replace(":session_id", session_id);
	    $.ajax({
	        type : 'GET',
	        url: url,
	        processData: false,
	        contentType: false,
	        dataType: 'json',
	        success : function(response) {
	            (response.status === true) ? toastr.success(response.notification) : toastr.error(response.notification);
	            location.reload();
	        }
	    });
	}
</script>

@endsection
