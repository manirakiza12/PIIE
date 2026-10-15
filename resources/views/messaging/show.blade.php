@extends(\App\Support\Permissions\RoleNavigationLayout::name(auth()->user()))
@section('content')
<link rel="stylesheet" href="{{ asset('css/portal-messaging.css') }}">
<section class="portal-messaging msg-thread-page" aria-labelledby="conversation-title" id="portal-chat"
    data-poll="{{ route('messaging.poll', $thread->id) }}" data-read="{{ route('messaging.read', $thread->id) }}"
    data-typing="{{ route('messaging.typing', $thread->id) }}" data-send="{{ route('messaging.send', $thread->id) }}"
    data-timezone="{{ app(\App\Support\TenantTimezone::class)->effective(auth()->user()) }}">
    <a href="{{ route('messaging.index') }}" class="msg-back">← All messages</a>
    <header class="msg-thread-heading">
        <img class="msg-avatar" src="{{ get_user_image($contact->id) }}" alt="" width="48" height="48">
        <div><h1 id="conversation-title">{{ $contact->name }}</h1><p>{{ app(\App\Support\Messaging\MessagingService::class)->label($contact) }}</p></div>
        <form method="post" action="{{ route('messaging.archive', $thread->id) }}">@csrf<input type="hidden" name="archived" value="1"><button class="btn btn-sm btn-outline-secondary">Archive</button></form>
    </header>
    @include('messaging._feedback')
    <p class="msg-hint">Messages refresh every 8 seconds while this page is open. Sending stores your message; “Read” means the recipient has opened it. Times: {{ app(\App\Support\TenantTimezone::class)->effective(auth()->user()) }}.</p>
    <button class="btn btn-sm btn-outline-secondary" id="msg-older" type="button" @if($rows->count() < 50) hidden @endif>Load earlier messages</button>
    <ol id="msg-history" class="msg-history" aria-label="Conversation history" tabindex="0">
        @foreach($rows as $chat)
            <li class="msg-bubble {{ (int) $chat->sender_id === (int) auth()->id() ? 'msg-mine' : '' }}" data-id="{{ $chat->id }}" data-mine="{{ (int) $chat->sender_id === (int) auth()->id() ? '1' : '0' }}">
                <span class="visually-hidden">{{ (int) $chat->sender_id === (int) auth()->id() ? 'You' : $contact->name }}:</span>
                <p>{{ $chat->message }}</p><small><time datetime="{{ $chat->created_at->toIso8601String() }}">{{ $chat->created_at->copy()->setTimezone(app(\App\Support\TenantTimezone::class)->effective(auth()->user()))->format('d M Y, H:i') }}</time> · #{{ $chat->id }}<span class="msg-receipt">{{ (int) $chat->sender_id === (int) auth()->id() ? ($chat->read_status ? ' · Read' : ' · Sent') : '' }}</span></small>
            </li>
        @endforeach
    </ol>
    @if($rows->isEmpty())<p id="msg-empty" class="msg-empty">No messages yet. Write your first message below.</p>@endif
    <div class="msg-status" role="status" aria-live="polite" id="msg-status"></div>
    <p class="msg-hint" id="msg-typing" hidden>{{ $contact->name }} is typing…</p>
    @if(!$writable)<div class="alert alert-warning">Sending is unavailable because of the contact policy, an inactive account or a block. Your conversation history remains available.</div>@endif
    <form id="msg-compose" action="{{ route('messaging.send', $thread->id) }}" method="post">
        @csrf<input type="hidden" name="client_message_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        <label for="msg-text">Your message</label>
        <textarea id="msg-text" name="message" rows="3" maxlength="5000" required @disabled(!$writable) placeholder="Write a respectful message…">{{ old('message') }}</textarea>
        <div class="msg-compose-footer"><small>Text only · up to 5,000 characters. Messages cannot be edited or deleted.</small><button class="btn btn-primary" type="submit" @disabled(!$writable)>Send message</button></div>
    </form>
    <details class="msg-safety"><summary>Report or block this contact</summary>
        <p>Blocking stops messages in both directions. It does not delete history or prevent authorised review. Use the institution’s usual channels for urgent help.</p>
        <form method="post" action="{{ route('messaging.block', $thread->id) }}">@csrf<input type="hidden" name="blocked" value="{{ $blockedByMe ? 0 : 1 }}"><button class="btn btn-outline-danger">{{ $blockedByMe ? 'Remove my block' : 'Block contact' }}</button></form>
        <form method="post" action="{{ route('messaging.report', $thread->id) }}">@csrf
            <label for="report-reason">Tell the reviewer what happened</label><textarea id="report-reason" name="reason" minlength="10" maxlength="2000" rows="3" required></textarea>
            <label for="report-message">Message number (optional; shown in the message details)</label><input id="report-message" type="number" name="chat_id" min="1">
            <button class="btn btn-outline-danger">Submit report for staff review</button>
        </form>
    </details>
</section>
<script src="{{ asset('js/portal-messaging.js') }}" defer></script>
@endsection
