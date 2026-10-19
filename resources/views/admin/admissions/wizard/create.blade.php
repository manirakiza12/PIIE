@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h4>{{ get_phrase('Add a Student') }}</h4></div>
<div class="row g-3">
    @if(auth()->user()->hasPermission('students.create'))
    <div class="col-md-6"><div class="eSection-wrap h-100">
        <h5>{{ get_phrase('Existing Student') }}</h5>
        <p>{{ get_phrase('Already admitted or studying at PIIE? Add the student directly using their existing registration number, name and email. No new application, application fee or application documents are required.') }}</p>
        <p>{{ get_phrase('The account appears under Students. Programme placement and course registration are handled from the academic workspace.') }}</p>
        <a href="{{ route('admin.student.open_modal') }}" class="eBtn eBtn-primary">{{ get_phrase('Add Existing Student') }}</a>
        <a href="{{ route('admin.student.import.index') }}" class="eBtn">{{ get_phrase('Import Existing Students') }}</a>
    </div></div>
    @endif
    <div class="col-md-6"><div class="eSection-wrap h-100">
        <h5>{{ get_phrase('New Applicant') }}</h5>
        <p>{{ get_phrase('Applying to PIIE for the first time? Complete the application details and required documents, submit for review, then accept and enrol the applicant.') }}</p>
        <form method="POST" action="{{ route('admin.hei_admissions.wizard.start') }}">
            @csrf
            <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Start New Application') }}</button>
        </form>
    </div></div>
</div>
@endsection
