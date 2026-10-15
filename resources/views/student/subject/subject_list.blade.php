<?php use App\Models\Classes; ?>

@extends('student.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Subjects') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                        <li><a href="#">{{ get_phrase('Subjects') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-7 col-md-7 col-sm-12 col-12 offset-md-2">
        <div class="eSection-wrap pb-2">
        	@if(count($subjects) > 0)
        		<table id="basic-datatable" class="table eTable">
        			<thead>
						<tr>
							<th>{{ get_phrase('Name') }}</th>
                            <th>{{ academic_term('class', auth()->user()->school_id) }}</th>
						</tr>
					</thead>
					<tbody>
						@foreach($subjects as $subject)
						<tr>
							<td>{{ $subject['name'] }}</td>
                            <td>
                                <?php $class_details = Classes::find($subject['class_id']); ?>
                                {{ $class_details['name'] }}
                            </td>
						</tr>
						@endforeach
					</tbody>
        		</table>
                {!! $subjects->appends(request()->all())->links() !!}
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
@endsection