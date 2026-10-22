@extends('student.navigation')
@section('content')

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Student Requests') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li>{{ get_phrase('Requests & Support') }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

@if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

<div class="row">
    <div class="col-md-5">
        <div class="eSection-wrap">
            <div class="title mb-3 pb-0 border-0">
                <h3 class="mb-1">{{ get_phrase('New Request') }}</h3>
                <p class="text-muted small">{{ get_phrase('For transfer applications, use the structured') }} <a href="{{ route('student.transfers.index') }}">{{ get_phrase('My Transfers') }}</a> {{ get_phrase('form.') }}</p>
            </div>
            <form method="POST" action="{{ route('student.requests.store') }}">
                @csrf
                <div class="mb-3">
                    <label class="eForm-label" for="request-type">{{ get_phrase('Request type') }} *</label>
                    <select id="request-type" name="type" class="form-select eForm-select" required>
                        <option value="">{{ get_phrase('Select type') }}</option>
                        @foreach($submissionTypes as $value => $label)
                            <option value="{{ $value }}" {{ old('type') === $value ? 'selected' : '' }}>{{ get_phrase($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="eForm-label">{{ get_phrase('Subject') }} *</label>
                    <input type="text" class="form-control eForm-control" name="subject" value="{{ old('subject') }}" required>
                </div>
                <div class="mb-3">
                    <label class="eForm-label">{{ get_phrase('Details') }} *</label>
                    <textarea class="form-control eForm-control" name="details" rows="5" required>{{ old('details') }}</textarea>
                </div>
                <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Submit Request') }}</button>
            </form>
        </div>
    </div>

    <div class="col-md-7">
        <div class="eSection-wrap">
            <div class="title mb-3 pb-0 border-0">
                <h3 class="mb-0">{{ get_phrase('My Requests') }}</h3>
            </div>
            @forelse($requests as $req)
                <div class="d-flex justify-content-between align-items-start py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div class="pe-3">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-secondary">{{ get_phrase($types[$req->type] ?? $req->type) }}</span>
                            <span class="badge bg-{{ $req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'danger' : 'warning') }}">
                                {{ ucfirst($req->status) }}
                            </span>
                        </div>
                        <strong>{{ $req->subject }}</strong>
                        <p class="mb-1 text-muted">{{ $req->details }}</p>
                        @if($req->admin_response)
                            <div class="alert alert-info py-2 px-3 mb-1" style="font-size:13px;">
                                <strong>{{ get_phrase('Response') }}:</strong> {{ $req->admin_response }}
                            </div>
                        @endif
                        @if($req->type === \App\Models\StudentRequest::TYPE_TRANSFER)
                            <p class="small text-muted mb-1">
                                @if($req->transfer_type){{ $transferTypes[$req->transfer_type] ?? $req->transfer_type }}@endif
                                @if($req->transferToProgramme) — {{ get_phrase('To') }} {{ $req->transferToProgramme->name }}@endif
                                @if($req->transfer_reason) · {{ $transferReasons[$req->transfer_reason] ?? $req->transfer_reason }}@endif
                            </p>
                        @endif
                        <time class="text-muted small" datetime="{{ $req->created_at->toIso8601String() }}">{{ $req->created_at->format('d M Y, H:i') }}</time>
                    </div>
                </div>
            @empty
                <div class="text-muted text-center py-4" role="status">{{ get_phrase('No requests submitted yet. You can submit a complaint or fee appeal here, or start a structured transfer application.') }}</div>
            @endforelse
        </div>
    </div>
</div>
@endsection
