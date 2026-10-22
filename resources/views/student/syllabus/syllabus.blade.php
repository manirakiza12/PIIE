@extends('student.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Syllabus & Learning Materials') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="{{ route('student.my_courses') }}">{{ get_phrase('My Learning') }}</a></li>
                        <li>{{ get_phrase('Syllabus & Learning Materials') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap pb-2">
            @if(count($syllabuses) > 0)
            <div class="table-responsive">
            <table id="basic-datatable" class="table eTable align-middle">
                <thead>
                    <tr>
                        <th scope="col">{{ get_phrase('Material') }}</th>
                        <th scope="col">{{ get_phrase('Course Unit') }}</th>
                        <th scope="col">{{ get_phrase('File') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($syllabuses as $syllabus)
                        <tr>
                            <td>{{ $syllabus->title }}</td>
                            <td>{{ $syllabus->subject_name }}</td>
                            <td>
                                @if($syllabus->file)
                                    <a href="{{ asset('assets/uploads/syllabus/'.basename($syllabus->file)) }}" class="btn btn-primary btn-sm" download>{{ get_phrase('Download') }}</a>
                                @else
                                    <span class="text-muted">{{ get_phrase('No file attached') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            {!! $syllabuses->appends(request()->all())->links() !!}
            @else
            <div class="empty_box center">
                <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
                <br>
                <span>{{ get_phrase('No syllabus materials are available for your class and section this academic session.') }}</span>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
