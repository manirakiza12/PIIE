{{-- Wizard header: reference, progress and the clickable step sequence. --}}
<div class="ap-card mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <div style="color:var(--ap-muted); font-size:13px; text-transform:uppercase; letter-spacing:.06em; font-weight:600;">
                {{ get_phrase('Application Number') }}
            </div>
            <div style="font-size:17px; font-weight:700;">{{ $admission->app_number }}</div>
        </div>

        <div class="text-end">
            <span class="ap-pill bg-{{ $admission->statusColor() }} bg-opacity-10 text-{{ $admission->statusColor() }}">
                {{ $admission->statusLabel() }}
            </span>
            <div style="color:var(--ap-muted); font-size:13px; margin-top:5px;">{{ $percent }}% {{ get_phrase('complete') }}</div>
        </div>
    </div>

    <div class="ap-progress mb-4">
        <div class="ap-progress-bar" style="width: {{ $percent }}%"></div>
    </div>

    <div class="ap-stepper">
        @foreach($steps as $item)
            <a href="{{ $item['url'] }}"
               class="ap-step {{ $item['key'] === $step ? 'current' : ($item['complete'] ? 'done' : '') }}">
                <span class="ap-step-num">
                    @if($item['complete'] && $item['key'] !== $step)
                        <i class="bi bi-check"></i>
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</div>

@if($readOnly)
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-lock-fill mt-1"></i>
        <div>{{ get_phrase('Your submitted application details are read-only. You can still complete any outstanding application fee payment. Contact admissions for corrections.') }}</div>
    </div>
@endif
