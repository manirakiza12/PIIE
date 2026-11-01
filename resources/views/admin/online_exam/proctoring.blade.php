@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Proctoring Review') }}: {{ optional($submission->student)->name }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('admin.online_exams.index') }}">{{ get_phrase('Online Exams') }}</a></li>
                <li><a href="{{ route('admin.online_exams.submissions', $exam->id) }}">{{ $exam->title }}</a></li>
                <li><a href="#">{{ get_phrase('Proctoring') }}</a></li>
            </ul>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if($exam->webcam_required && $submission->status === 'in_progress')
            <button type="button" class="eBtn eBtn-warning" id="requestSnapshotBtn" data-url="{{ route('admin.online_exams.proctoring.request_snapshot', ['id' => $exam->id, 'submission' => $submission->id]) }}">
                <i class="bi bi-camera"></i> {{ get_phrase('Request Snapshot') }}
            </button>
            @endif
            <a class="export_btn bg-secondary" href="{{ route('admin.online_exams.submissions', $exam->id) }}">{{ get_phrase('Back to Submissions') }}</a>
        </div>
    </div>
</div></div></div>

<div class="row"><div class="col-12"><div class="eSection-wrap mb-3">
    <div class="row">
        <div class="col-md-3"><strong>{{ get_phrase('Attempt') }}:</strong> {{ $submission->attempt_no }}</div>
        <div class="col-md-3"><strong>{{ get_phrase('Status') }}:</strong> {{ ucwords(str_replace('_',' ',$submission->status)) }}</div>
        <div class="col-md-3"><strong>{{ get_phrase('Webcam Consent') }}:</strong> {{ $submission->camera_consent_at ? $submission->camera_consent_at->format('d M Y H:i') : get_phrase('Not given') }}</div>
        <div class="col-md-3"><strong>{{ get_phrase('Fullscreen Started') }}:</strong> {{ $submission->fullscreen_started_at ? $submission->fullscreen_started_at->format('d M Y H:i') : '—' }}</div>
    </div>
    <div class="row mt-2">
        <div class="col-md-3"><strong>{{ get_phrase('IP Address') }}:</strong> {{ $submission->ip_address ?: '—' }}</div>
        <div class="col-md-9"><strong>{{ get_phrase('Browser') }}:</strong> {{ \Illuminate\Support\Str::limit($submission->user_agent, 120) ?: '—' }}</div>
    </div>
</div></div></div>

@if($exam->webcam_required)
<div class="row"><div class="col-12"><div class="eSection-wrap mb-3">
    <h5 class="mb-3">{{ get_phrase('Camera Monitoring Summary') }}</h5>
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="fs-4 fw-bold text-{{ $submission->camera_permission_granted ? 'success' : 'danger' }}">
                        {{ $submission->camera_permission_granted ? get_phrase('Granted') : get_phrase('Denied') }}
                    </div>
                    <div class="text-muted small">{{ get_phrase('Camera Permission') }}</div>
                    @if($submission->camera_ready_at)
                    <div class="text-muted small mt-1">{{ $submission->camera_ready_at->format('d M Y H:i') }}</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="fs-4 fw-bold">{{ $snapshotsCount ?? 0 }}</div>
                    <div class="text-muted small">{{ get_phrase('Snapshots Captured') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body text-center">
                    <div class="fs-4 fw-bold text-{{ $flagsCount > 0 ? 'danger' : 'success' }}">{{ $flagsCount ?? 0 }}</div>
                    <div class="text-muted small">{{ get_phrase('Flagged Events') }}</div>
                </div>
            </div>
        </div>
    </div>
    @if($lastSnapshot)
    <div class="mt-3">
        <strong>{{ get_phrase('Most Recent Snapshot') }}:</strong>
        <div class="mt-2">
            <img src="{{ route('admin.online_exams.snapshot_image', ['submission' => $submission->id, 'snapshot' => $lastSnapshot->id]) }}" alt="Snapshot" class="img-thumbnail" style="max-height: 200px;">
        </div>
    </div>
    @endif
</div></div></div>
@endif

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <h5 class="mb-3">{{ get_phrase('Event Timeline') }}</h5>
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr><th>#</th><th>{{ get_phrase('Event') }}</th><th>{{ get_phrase('Time') }}</th><th>{{ get_phrase('Details') }}</th></tr></thead>
            <tbody>
            @forelse($events as $i => $event)
                @php
                    $flagged = in_array($event->event_type, ['tab_hidden', 'fullscreen_exited', 'camera_permission_denied', 'connection_lost', 'snapshot_failed'], true);
                    $details = [];
                    if (!empty($event->metadata)) {
                        foreach ($event->metadata as $key => $value) {
                            if (is_array($value)) {
                                $value = json_encode($value);
                            }
                            $details[] = "<strong>" . e($key) . ":</strong> " . e($value);
                        }
                    }
                @endphp
                <tr class="{{ $flagged ? 'table-warning' : '' }}">
                    <td>{{ $events->firstItem() + $i }}</td>
                    <td>
                        <span class="badge bg-{{ $flagged ? 'danger' : 'secondary' }}">{{ $event->event_type ? ucwords(str_replace('_',' ', $event->event_type)) : get_phrase('Event type not recorded') }}</span>
                    </td>
                    <td>{{ $event->event_time?->format('d M Y H:i:s') }}</td>
                    <td>
                        @if(!empty($details))
                            <div class="small">{!! implode('<br>', $details) !!}</div>
                        @else
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">{{ get_phrase('No proctoring events recorded for this attempt') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $events->links() }}
</div></div></div>

@if($exam->webcam_required && $snapshots->count() > 0)
<div class="row"><div class="col-12"><div class="eSection-wrap mt-4">
    <h5 class="mb-3">{{ get_phrase('Snapshots') }}</h5>
    <div class="row g-2">
        @foreach($snapshots as $snapshot)
        <div class="col-md-3 col-6">
            <div class="card">
                <img src="{{ route('admin.online_exams.snapshot_image', ['submission' => $submission->id, 'snapshot' => $snapshot->id]) }}" alt="Snapshot" class="card-img-top" style="height: 150px; object-fit: cover;">
                <div class="card-body p-2">
                    <div class="small text-muted">{{ $snapshot->captured_at->format('d M Y H:i:s') }}</div>
                    <div class="small">
                        <span class="badge bg-{{ $snapshot->capture_type === 'automatic' ? 'info' : ($snapshot->capture_type === 'invigilator_requested' ? 'warning' : 'primary') }}">
                            {{ ucfirst($snapshot->capture_type) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div></div></div>
@endif

@push('scripts')
@if($exam->webcam_required && $submission->status === 'in_progress')
<script>
(function () {
    var btn = document.getElementById('requestSnapshotBtn');
    if (!btn) { return; }

    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url');
        if (!url) { return; }

        btn.disabled = true;
        var originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>{{ get_phrase('Requesting...') }}';

        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                alert('{{ get_phrase('Snapshot requested successfully. The student will capture it shortly.') }}');
            } else {
                alert(data.message || '{{ get_phrase('Failed to request snapshot.') }}');
            }
        })
        .catch(function () {
            alert('{{ get_phrase('A connection problem occurred. Please try again.') }}');
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });
})();
</script>
@endif
@endpush
@endsection
