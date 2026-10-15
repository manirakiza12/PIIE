@php
    $messagingService = app(\App\Support\Messaging\MessagingService::class);
    $messagingUser = auth()->user();
@endphp
@if($messagingUser && $messagingService->active($messagingUser) && (in_array((int) $messagingUser->role_id, [6, 7], true) || $messagingService->staff($messagingUser)))
<a href="{{ route('messaging.index') }}" class="btn btn-light position-relative mx-1" aria-label="Messages" title="Messages">
    <i class="bi bi-chat-dots" aria-hidden="true"></i>
    <span class="visually-hidden">Messages</span>
    @if(\Illuminate\Support\Facades\Schema::hasColumns('chats', ['school_id', 'sender_id']) && \Illuminate\Support\Facades\Schema::hasColumn('message_thrades', 'school_id'))
        @php($messagingUnread = $messagingService->unreadCount($messagingUser))
        <span class="badge bg-danger" data-message-unread @if(!$messagingUnread) hidden @endif aria-label="Unread messages">{{ $messagingUnread > 99 ? '99+' : $messagingUnread }}</span>
    @endif
</a>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const badge = document.querySelector('[data-message-unread]');
    if (!badge) return;
    async function updateMessageBadge() {
        if (document.hidden) return;
        try {
            const response = await fetch(@json(route('messaging.unread')), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            badge.hidden = !data.count;
            badge.textContent = data.count > 99 ? '99+' : String(data.count);
        } catch (_) {}
    }
    setInterval(updateMessageBadge, 30000);
});
</script>
@endif
