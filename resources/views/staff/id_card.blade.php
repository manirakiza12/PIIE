@extends(\App\Support\Permissions\RoleNavigationLayout::name(auth()->user()))
@section('hide_page_toolbar')@endsection
@section('content')
<div class="mainSection-title print-hidden">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <h4>{{ get_phrase('My ID Card') }}</h4>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-danger" href="{{ route('staff.id_card.pdf') }}"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> {{ get_phrase('Download PDF') }}</a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.printReport('staff-id-card')"><i class="bi bi-printer" aria-hidden="true"></i> {{ get_phrase('Print') }}</button>
        </div>
    </div>
</div>
<div class="eSection-wrap">
    <p class="text-muted print-hidden">{{ get_phrase('Scan the QR code to verify this staff ID and its current status.') }}</p>
    <div id="staff-id-card" data-print-root>
        @include('partials.id_card')
    </div>
</div>
@endsection
