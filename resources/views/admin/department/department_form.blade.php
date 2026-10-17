@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><h4>{{ $department ? 'Edit Department' : 'Add Department' }}</h4><p class="text-muted">Choose the School or Faculty responsible for this Department.</p></div>
<div class="eSection-wrap">
    @include('admin.department._department_fields')
</div>
@endsection
