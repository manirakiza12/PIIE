@extends('admin.navigation')
   
@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div
              class="d-flex justify-content-between align-items-center flex-wrap gr-15"
            >
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Documents') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="#">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Users') }}</a></li>
                        <li><a href="#">{{ get_phrase('Student') }}</a></li>
                        <li><a href="#">{{ get_phrase('Documents') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 mb-3"><div class="alert alert-info">
        <strong>{{ $user_details->name }} — {{ $user_details->code }}</strong><br>
        {{ get_phrase('Administration manages these documents. Upload the records already held for this student, or open an uploaded file to retrieve it. The student does not need to complete an application or upload admission documents.') }}
    </div></div>
    <div class="col-6">
        <div class="eSection-wrap">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-12 pb-3">
                        <p class="column-title">{{ get_phrase('Files') }}</p>
                        @if (!empty(json_decode($user_details->documents, true)))
                            @php $count = 1 @endphp
                            @foreach(json_decode($user_details->documents, true) as $key => $value)
                            <div class="d-flex justify-content-between documents mb-2">
                                <p>{{ $count }}. <a href="{{ asset('assets/uploads/user-docs/'.$user_details->id.'/'.$value) }}" target="_blank">{{ ucfirst($key) }}</a></p>
                                <a class="trash" href="{{ route('admin.documents.remove', ['id' => $user_details->id, 'file_name' => $key]) }}"><i class="bi bi-trash"></i></a>
                            </div>
                            @php $count++ @endphp
                            @endforeach
                        @else
                        <div class="empty_box center center2">
                            <img class="mb-3" width="100px" src="{{ asset('assets/images/empty_box.png') }}" />
                            <br>
                            <span class="d-flex w-100">{{ get_phrase('No data found') }}</span>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="eSection-wrap">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-12 pb-3">
                        <p class="column-title">{{ get_phrase('File upload') }}</p>
                        <div class="eForm-file">
                            <form action="{{route('admin.documents.upload', ['id' => $user_details['id']])}}" method="post" enctype="multipart/form-data">
                                @CSRF
                                <div class="mb-3">
                                    <label for="file_name" class="eForm-label">{{ get_phrase('File Name') }}</label>
                                    <input class="form-control eForm-control" id="file_name" type="text" name="file_name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="formFileSm" class="eForm-label">{{ get_phrase('File') }}</label>
                                    <input class="form-control eForm-control-file" id="formFileSm" type="file" name="file">
                                </div>
                                <button type="submit" class="btn-form float-end">{{ get_phrase('Upload') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
