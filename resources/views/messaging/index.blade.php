@extends(\App\Support\Permissions\RoleNavigationLayout::name(auth()->user()))
@section('content')
<link rel="stylesheet" href="{{ asset('css/portal-messaging.css') }}">
<section class="portal-messaging" aria-labelledby="messages-title">
    <header class="msg-page-header">
        <div><p class="msg-eyebrow">PIIE communication</p><h1 id="messages-title">Messages</h1><p>Contact your institution’s staff and keep your conversations in one place.</p></div>
        @if(app(\App\Support\Permissions\PermissionService::class)->allows(auth()->user(), 'messaging.moderate'))
            <a class="btn btn-outline-secondary" href="{{ route('messaging.moderation') }}">Review reports</a>
        @endif
    </header>
    @include('messaging._feedback')
    <div class="msg-policy">Students can contact authorised staff. Student-to-student and student-to-parent messaging are disabled. Keep messages respectful; authorised staff can review reported conversations.</div>
    <form class="msg-search" method="get" action="{{ route('messaging.index') }}" role="search">
        <label for="msg-search">Search contacts or your message history</label>
        <div><input id="msg-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Name or words from a conversation"><button class="btn btn-primary">Search</button></div>
        @if($archive)<input type="hidden" name="archive" value="1">@endif
    </form>
    <div class="msg-columns">
        <section class="msg-card" aria-labelledby="inbox-title">
            <div class="msg-card-heading"><h2 id="inbox-title">{{ $archive ? 'Archived conversations' : 'Recent conversations' }}</h2>
                <a href="{{ route('messaging.index', ['archive' => $archive ? 0 : 1, 'q' => $search]) }}">{{ $archive ? 'Back to inbox' : 'View archive' }}</a></div>
            <p class="msg-hint">Archiving hides a conversation from your inbox. A new message returns it here. Messages are retained.</p>
            @forelse($conversations as $thread)
                <a class="msg-conversation" href="{{ route('messaging.show', $thread->id) }}">
                    <img class="msg-avatar" src="{{ get_user_image($thread->contact->id) }}" alt="" width="44" height="44" loading="lazy">
                    <span class="msg-contact-copy"><strong>{{ $thread->contact->name }}</strong><span>{{ app(\App\Support\Messaging\MessagingService::class)->label($thread->contact) }}</span><span class="msg-preview">{{ $thread->last_message ? \Illuminate\Support\Str::limit($thread->last_message->message, 90) : 'No messages yet. Say hello.' }}</span></span>
                    @if($thread->unread)<span class="badge bg-danger">{{ $thread->unread }} unread</span>@endif
                </a>
                @if($archive)<form class="msg-restore" method="post" action="{{ route('messaging.archive', $thread->id) }}">@csrf<input type="hidden" name="archived" value="0"><button class="btn btn-sm btn-outline-secondary">Restore conversation</button></form>@endif
            @empty
                <div class="msg-empty"><h3>{{ $archive ? 'Your archive is empty' : 'Start a conversation' }}</h3><p>Choose a contact below or search by name. Your messages will appear here.</p></div>
            @endforelse
            {{ $conversations->links() }}
        </section>
        <section class="msg-card" aria-labelledby="contacts-title">
            <div class="msg-card-heading"><h2 id="contacts-title">{{ (int) auth()->user()->role_id === 7 ? 'Staff contacts' : 'Available contacts' }}</h2></div>
            <p class="msg-hint">Showing up to 30 contacts. Search by name to narrow the list. Personal email addresses and phone numbers are not shared here.</p>
            @forelse($contacts as $contact)
                <form method="post" action="{{ route('messaging.start') }}" class="msg-contact-row">
                    @csrf<input type="hidden" name="recipient_id" value="{{ $contact->id }}">
                    <img class="msg-avatar" src="{{ get_user_image($contact->id) }}" alt="" width="44" height="44" loading="lazy">
                    <span class="msg-contact-copy"><strong>{{ $contact->name }}</strong><span>{{ app(\App\Support\Messaging\MessagingService::class)->label($contact) }}</span></span>
                    <button class="btn btn-sm btn-outline-primary" aria-label="Message {{ $contact->name }}">Message</button>
                </form>
            @empty
                <div class="msg-empty"><p>No available contacts match your search. Try another name or contact the institution directly.</p></div>
            @endforelse
        </section>
    </div>
</section>
@endsection
