@extends('student.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Class Routine') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="{{ route('student.my_courses') }}">{{ get_phrase('My Learning') }}</a></li>
                        <li>{{ get_phrase('Class Routine') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-12">
        <div class="eSection-wrap-2">
            @if(empty($class_id) || empty($section_id))
                <div class="text-center text-muted py-4" role="status">{{ get_phrase('Your current class and section timetable is not available yet. Contact the academic office if this is unexpected.') }}</div>
            @elseif(!$hasClassRoutine)
                <div class="text-center text-muted py-4" role="status">{{ get_phrase('No timetable has been published for your class and section this academic session.') }}</div>
            @else
                @include('student.routine.routine_list')
            @endif
        </div>
    </div>
</div>
@endsection
