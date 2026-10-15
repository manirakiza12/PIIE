@extends(\App\Support\Permissions\RoleNavigationLayout::name(auth()->user()))
@section('content')
<link rel="stylesheet" href="{{ asset('css/portal-messaging.css') }}">
<section class="portal-messaging">
    <a href="{{ route('messaging.index') }}">← Messages</a><h1>Message reports</h1>
    <p>Access is limited to authorised reviewers in this institution. Opening a reported conversation is audited. Original messages are retained.</p>
    @include('messaging._feedback')
    @if($report)
        <section class="msg-card"><h2>Report #{{ $report->id }}</h2><p><strong>Status:</strong> {{ $report->status }}</p><p>{{ $report->reason }}</p>
            <p><strong>Reported by:</strong> {{ $participants->get($report->reporter_id)?->name ?? 'Unavailable participant' }}</p>
            <p class="msg-hint">Reported message: {{ $report->chat_id ?: 'Entire conversation' }}. Use the original message numbers below when documenting follow-up.</p>
            @foreach($rows as $chat)<article class="msg-review-message"><strong>Message #{{ $chat->id }} · {{ $participants->get($chat->sender_id)?->name ?? 'Unavailable participant' }}</strong><p>{{ $chat->message }}</p><time>{{ $chat->created_at->format('d M Y H:i') }}</time></article>@endforeach
            {{ $rows->links() }}
            @if($report->review_note)<p><strong>Previous review:</strong> {{ $report->review_note }}</p>@endif
            <form action="{{ route('messaging.review', $report->id) }}" method="post">@csrf
                <label for="review-status">Review status</label><select name="status" id="review-status"><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option><option value="open">Keep open</option></select>
                <label for="review-note">Action taken / reason</label><textarea id="review-note" name="review_note" rows="3" minlength="10" maxlength="2000" required></textarea><button class="btn btn-primary">Record review</button>
            </form>
        </section>
    @endif
    <section class="msg-card"><h2>Institution reports</h2>
        @forelse($reports as $entry)<a class="msg-conversation" href="{{ route('messaging.moderation.show', $entry->id) }}">Report #{{ $entry->id }} · {{ $entry->status }} · {{ $entry->created_at }}</a>@empty<p>No reports have been submitted.</p>@endforelse
        {{ $reports->links() }}
    </section>
</section>
@endsection
