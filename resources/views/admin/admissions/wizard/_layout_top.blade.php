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
    </div>
@endif
