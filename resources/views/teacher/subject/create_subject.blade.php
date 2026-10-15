@extends('teacher.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Create Subject') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('teacher.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                        <li><a href="{{ route('teacher.subject_list') }}">{{ get_phrase('Subjects') }}</a></li>
                        <li><a href="#">{{ get_phrase('Create') }}</a></li>
                    </ul>
                </div>
                <a href="{{ route('teacher.subject_list') }}" class="eBtn eBtn-dark">{{ get_phrase('Back') }}</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6 col-md-8 col-sm-12 col-12">
        <div class="eSection-wrap">
            <form method="POST" action="{{ route('teacher.subject.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="class_id" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                    <select name="class_id" id="class_id" class="form-select eForm-select" required>
                        <option value="">{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id) }}</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ old('class_id') == $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                    @error('class_id')
                        <small class="text-danger">{{ $message }}</small>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="name" class="eForm-label">{{ get_phrase('Subject name') }}</label>
                    <input type="text" class="form-control eForm-control" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')
                        <small class="text-danger">{{ $message }}</small>
                    @enderror
                </div>

                <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Create subject') }}</button>
            </form>
        </div>
    </div>
</div>
@endsection
