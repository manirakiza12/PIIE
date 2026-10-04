@php($actions = $liveClass->workspace_actions)
{{-- The card's times come from the ONE stored instant, in the reader's own
     clock, via the single display helper. They used to be formatted with a raw
     ->timezone($liveClass->timezone) call, which pinned every reader to the
     SCHEDULER's zone and produced a different number for a student who was not
     in it. --}}
@php($cardDisplay = app(App\Support\LiveClasses\LiveClassDisplay::class)->for($liveClass, auth()->user()))
<article class="border rounded p-3 mb-2" aria-labelledby="live-class-title-{{ $liveClass->id }}">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
        <div class="flex-grow-1">
            <div class="d-flex align-items-start flex-wrap gap-2 mb-2">
                <h6 class="mb-0" id="live-class-title-{{ $liveClass->id }}">{{ $liveClass->title }}</h6>
                @include('admin.live_class._status_badge', ['liveClass' => $liveClass, 'startingSoon' => $actions['starting_soon']])
                @if(!$liveClass->is_published && $liveClass->status !== \App\Models\LiveClass::STATUS_CANCELLED)
                    <span class="badge bg-light text-dark">{{ get_phrase('Not published') }}</span>
                @endif
            </div>
            <div class="d-flex flex-wrap gap-x-3 gap-y-1 text-muted small">
                <span>{{ $cardDisplay->localStart() ? $cardDisplay->localStart()->format('D, M j, Y') : get_phrase('Time to be confirmed') }}</span>
                @if($liveClass->scheduled_at)
                    <span>{{ $cardDisplay->timeRange() }} ({{ $cardDisplay->zoneNote() }})</span>
                @endif
                <span>{{ get_phrase('Facilitator') }}: {{ $liveClass->teacher->name ?? '—' }}</span>
                @if((int)$liveClass->resource_count > 0)<span>{{ $liveClass->resource_count }} {{ get_phrase('resources') }}</span>@endif
                @if($liveClass->is_published)<span>{{ get_phrase('Published') }}</span>@endif
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-start gap-2">
            @if($actions['join'])
                <a class="btn btn-sm btn-primary" href="{{ route($actions['route_prefix'].'.live_classes.join', $liveClass->id) }}">{{ $actions['host'] ? get_phrase('Start Class') : get_phrase('Join Class') }}</a>
            @endif
            @if($actions['view'])
                <a class="btn btn-sm btn-outline-secondary" href="{{ route($actions['route_prefix'].'.live_classes.show', $liveClass->id) }}">{{ get_phrase('View') }}</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route($actions['route_prefix'].'.live_classes.materials', $liveClass->id) }}">{{ get_phrase('Resources') }}</a>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route($actions['route_prefix'].'.live_classes.attendance', $liveClass->id) }}">{{ get_phrase('Join Evidence') }}</a>
            @endif
            @if($actions['manage'])
                <a class="btn btn-sm btn-outline-primary" href="{{ route($actions['route_prefix'].'.live_classes.edit', $liveClass->id) }}">{{ get_phrase('Edit') }}</a>
                @if(!$liveClass->is_published)
                    <form method="POST" action="{{ route($actions['route_prefix'].'.live_classes.publish', $liveClass->id) }}">@csrf<button class="btn btn-sm btn-outline-success" type="submit">{{ get_phrase('Publish') }}</button></form>
                @endif
                @if($liveClass->status !== \App\Models\LiveClass::STATUS_CANCELLED)
                    <form method="POST" action="{{ route($actions['route_prefix'].'.live_classes.cancel', $liveClass->id) }}" onsubmit="return confirm('{{ get_phrase('Cancel this class?') }}')">@csrf<button class="btn btn-sm btn-outline-danger" type="submit">{{ get_phrase('Cancel') }}</button></form>
                @endif
            @endif
        </div>
    </div>
</article>
