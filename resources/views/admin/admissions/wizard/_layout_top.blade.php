@php
    $blank = '—';
@endphp

<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div class="d-flex flex-column">
                <h4>{{ get_phrase('New Student Admission') }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="{{ route('admin.hei_admissions.index') }}">{{ get_phrase('Admissions') }}</a></li>
                    <li><a href="#">{{ $admission->app_number }}</a></li>
                    <li><a href="#">{{ $admission->full_name ?: get_phrase('New Application') }}</a></li>
                </ul>
            </div>
            <div class="export-btn-area d-flex gap-2">
                <a href="{{ route('admin.hei_admissions.index') }}" class="export_btn export_btn-outline">
                    <i class="bi bi-arrow-left"></i> {{ get_phrase('Back to Applications') }}
                </a>
            </div>
        </div>
    </div></div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="eSection-wrap mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gr-10">
        <div class="d-flex flex-wrap gap-3">
            @foreach($steps as $s)
                <a href="{{ $s['url'] }}" class="d-flex align-items-center gap-1 {{ $s['key'] === $step ? 'fw-bold text-primary' : 'text-muted' }}" style="font-size:13.5px; text-decoration:none;">
                    <i class="bi {{ $s['complete'] ? 'bi-check-circle-fill text-success' : 'bi-circle' }}"></i>
                    {{ $loop->iteration }}. {{ $s['label'] }}
                </a>
            @endforeach
        </div>
        <div class="text-muted" style="font-size:13px;">{{ $percent }}% {{ get_phrase('complete') }}</div>
    </div>
    <div class="progress" style="height:6px;">
        <div class="progress-bar bg-success" role="progressbar" style="width: {{ $percent }}%;"></div>
    </div>
</div>

@if($readOnly)
    <div class="alert alert-warning">
        {{ get_phrase('This application has already been decided and can no longer be edited here.') }}
        <a href="{{ route('admin.hei_admissions.review', $admission->id) }}">{{ get_phrase('Go to the review screen') }}</a>
    </div>
@endif

@if(! $readOnly && $admission->status === \App\Models\Admission::STATUS_DRAFT)
    <div class="alert alert-info" role="status">
        {{ get_phrase('This application is a draft while you complete the details and required documents. Use Review & Submit to send it to the admissions queue.') }}
        @if(auth()->user()->hasPermission('students.create'))
            <div class="mt-2">{{ get_phrase('Already an existing student? Use the existing-student form with their current registration number instead of completing a new application.') }}
                <a href="{{ route('admin.student.open_modal') }}">{{ get_phrase('Add Existing Student') }}</a>
            </div>
        @endif
    </div>
@endif

@if(! $readOnly)
    <div class="eSection-wrap mb-3">
        <h5>{{ get_phrase('Required before enrollment') }}</h5>
        <p>{{ get_phrase('Complete and submit the application, then accept it before enrolling the student.') }}</p>
        <ul class="mb-0">
            @foreach($steps as $requiredStep)
                @if(in_array($requiredStep['key'], ['personal', 'programme', 'education'], true))
                    <li><a href="{{ $requiredStep['url'] }}">{{ $requiredStep['label'] }}</a>
                        @if($requiredStep['complete'])<span class="text-success">({{ get_phrase('Complete') }})</span>@endif
                    </li>
                @endif
            @endforeach
            @foreach(\App\Support\Admissions\ApplicationDocuments::checklist($admission) as $documentItem)
                @if($documentItem['requirement']->is_required)
                    <li>
                        <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'documents']) }}">{{ $documentItem['requirement']->label }}</a>
                        @if(in_array($documentItem['state'], ['pending', 'verified'], true))
                            <span class="text-success">({{ get_phrase('Uploaded') }})</span>
                        @else
                            <span class="text-danger">({{ get_phrase('Required') }})</span>
                        @endif
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
@endif
