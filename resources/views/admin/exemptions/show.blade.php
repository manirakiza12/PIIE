@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('Exemption Details') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li><a href="{{ route('admin.exemptions.index') }}">{{ get_phrase('Exemptions') }}</a></li>
                <li aria-current="page">#{{ $exemption->id }}</li>
            </ul>
        </div>
    </div>
</div>

<div class="eSection-wrap mt-3">
    <div class="row">
        <div class="col-md-6">
            <p><strong>{{ get_phrase('Student') }}:</strong> {{ $exemption->student?->name }}</p>
            <p><strong>{{ get_phrase('Programme') }}:</strong> {{ $exemption->programme?->name }}</p>
            <p><strong>{{ get_phrase('Curriculum') }}:</strong> {{ $exemption->curriculum?->name }}</p>
            <p><strong>{{ get_phrase('Type') }}:</strong> {{ $exemption->exemption_type === 'level' ? get_phrase('Entire Level') : get_phrase('Course Unit') }}</p>
            @if($exemption->targetStage)
                <p><strong>{{ get_phrase('Level') }}:</strong> {{ $exemption->targetStage->name }}</p>
            @endif
            <p><strong>{{ get_phrase('Status') }}:</strong> <span class="badge bg-{{ $exemption->status === 'approved' ? 'success' : ($exemption->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($exemption->status) }}</span></p>
        </div>
        <div class="col-md-6">
            <p><strong>{{ get_phrase('Previous Institution') }}:</strong> {{ $exemption->previous_institution ?? '—' }}</p>
            <p><strong>{{ get_phrase('Previous Qualification') }}:</strong> {{ $exemption->previous_qualification ?? '—' }}</p>
            <p><strong>{{ get_phrase('Reason') }}:</strong> {{ $exemption->reason ?? '—' }}</p>
            <p><strong>{{ get_phrase('Requested By') }}:</strong> {{ $exemption->requester?->name }}</p>
            @if($exemption->decider)
                <p><strong>{{ get_phrase('Decided By') }}:</strong> {{ $exemption->decider->name }} ({{ $exemption->decided_at?->format('Y-m-d H:i') }})</p>
                <p><strong>{{ get_phrase('Decision Notes') }}:</strong> {{ $exemption->decision_notes ?? '—' }}</p>
            @endif
        </div>
    </div>

    @if($exemption->items->count() > 0)
        <h6 class="mt-3">{{ get_phrase('Exempted Course Units') }}</h6>
        <ul>
            @foreach($exemption->items as $item)
                <li>{{ $item->curriculumMembership?->subject?->code ?? $item->subject?->code }} — {{ $item->curriculumMembership?->subject?->name ?? $item->subject?->name }} ({{ $item->status }})</li>
            @endforeach
        </ul>
    @endif

    @if($exemption->isPending())
        <div class="mt-3 d-flex gap-2">
            <form method="POST" action="{{ route('admin.exemptions.approve', $exemption->id) }}">
                @csrf
                <input type="hidden" name="decision_notes" value="">
                <button type="submit" class="eBtn eBtn-primary" onclick="return confirm('{{ get_phrase('Approve this exemption?') }}')">{{ get_phrase('Approve') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.exemptions.reject', $exemption->id) }}">
                @csrf
                <input type="hidden" name="decision_notes" value="">
                <button type="submit" class="eBtn eBtn-danger" onclick="return confirm('{{ get_phrase('Reject this exemption?') }}')">{{ get_phrase('Reject') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.exemptions.cancel', $exemption->id) }}">
                @csrf
                <button type="submit" class="eBtn eBtn-outline-secondary" onclick="return confirm('{{ get_phrase('Cancel this exemption?') }}')">{{ get_phrase('Cancel') }}</button>
            </form>
        </div>
    @endif

    <div class="mt-3">
        <a href="{{ route('admin.exemptions.index') }}" class="eBtn eBtn-outline-secondary">{{ get_phrase('Back to list') }}</a>
    </div>
</div>
@endsection