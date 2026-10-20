@extends('student.navigation')
@section('content')

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('My ID Card') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('My ID Card') }}</a></li>
                    </ul>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('student.id_card.pdf') }}" class="eBtn eBtn-primary">
                        <i class="bi bi-download"></i> {{ get_phrase('Download PDF') }}
                    </a>
                    <button type="button" class="eBtn eBtn-secondary" onclick="printableDiv('printableArea')">
                        <i class="bi bi-printer"></i> {{ get_phrase('Print') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            <p class="text-muted small mb-3">{{ get_phrase('Scan the QR code on your card with any phone camera to verify it — it opens a page confirming your name, photo, and enrollment status.') }}</p>
            <div id="printableArea">
                @include('partials.id_card', ['studentDetails' => $student_details])
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
    "use strict";

    function printableDiv(printableAreaDivId) {
        window.printReport(printableAreaDivId);
    }
</script>
@endsection
