<?php

namespace App\Http\Controllers;

use App\Models\{Chat, MessageThrade, User};
use App\Support\Messaging\MessagingService;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Support\Str;

final class MessagingController extends Controller
{
    public function __construct(private MessagingService $messages)
    {
        $this->middleware(function ($request, $next) {
            $this->messages->actor();
            return $next($request)->header('Cache-Control', 'private, no-store');
        });
    }

    public function index(Request $request)
    {
        $actor = $this->messages->actor();
        $this->messages->presence($actor);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'archive' => 'nullable|boolean', 'contact' => 'nullable|integer']);
        $search = trim($data['q'] ?? '');
        $archive = $request->boolean('archive');
        $query = MessageThrade::where('school_id', $actor->school_id)
            ->where(fn ($q) => $q->where('sender_id', $actor->id)->orWhere('reciver_id', $actor->id))
            ->whereExists(function ($q) use ($actor) {
                $q->selectRaw('1')->from('users')->where('school_id', $actor->school_id)
                    ->whereRaw('users.id = CASE WHEN message_thrades.sender_id = ? THEN message_thrades.reciver_id ELSE message_thrades.sender_id END', [$actor->id]);
            });
        $preferences = DB::table('message_thread_preferences')->where('school_id', $actor->school_id)
            ->where('user_id', $actor->id)->whereNotNull('archived_at')->pluck('thread_id');
        $archive ? $query->whereIn('id', $preferences) : $query->whereNotIn('id', $preferences);
        if ($search !== '') {
            $query->where(function ($q) use ($search, $actor) {
                $q->whereExists(function ($u) use ($search, $actor) {
                    $u->selectRaw('1')->from('users')->where('users.school_id', $actor->school_id)->where('users.id', '!=', $actor->id)
                        ->where('users.name', 'like', '%'.$search.'%')->whereRaw('(users.id = message_thrades.sender_id OR users.id = message_thrades.reciver_id)');
                })->orWhereExists(function ($m) use ($search, $actor) {
                    $m->selectRaw('1')->from('chats')->whereColumn('message_thrade', 'message_thrades.id')
                        ->where('chats.school_id', $actor->school_id)->where('message', 'like', '%'.$search.'%')
                        ->whereRaw('((chats.sender_id = message_thrades.sender_id AND chats.reciver_id = message_thrades.reciver_id) OR (chats.sender_id = message_thrades.reciver_id AND chats.reciver_id = message_thrades.sender_id))');
                });
            });
        }
        $query->addSelect('message_thrades.*')->selectSub(Chat::selectRaw('MAX(id)')->whereColumn('message_thrade', 'message_thrades.id')
            ->where('school_id', $actor->school_id), 'latest_chat_id')->orderByDesc('latest_chat_id')->orderByDesc('id');
        $conversations = $query->paginate(30)->withQueryString();
        foreach ($conversations as $thread) {
            $thread->contact = $this->messages->other($actor, $thread);
            $thread->last_message = $this->messages->messages($actor, $thread)->latest('id')->first();
            $thread->unread = $this->messages->messages($actor, $thread)->where('reciver_id', $actor->id)->where('read_status', 0)->count();
        }
        $contacts = User::where('school_id', $actor->school_id)->where('id', '!=', $actor->id)
            ->when(!$this->messages->staff($actor), fn ($q) => $q->whereNotIn('role_id', [1, 6, 7, 8, 9, 11, 12]))
            ->where('name', 'like', '%'.$search.'%')->orderBy('name')->cursor()
            ->filter(fn ($user) => $this->messages->pairAllowed($actor, $user) && !$this->messages->blocked($actor, $user))->take(30)->collect();
        return view('messaging.index', compact('conversations', 'contacts', 'search', 'archive'));
    }

    public function start(Request $request)
    {
        $data = $request->validate(['recipient_id' => 'required|integer|min:1']);
        $thread = $this->messages->start($this->messages->actor(), $data['recipient_id']);
        return redirect()->route('messaging.show', $thread->id);
    }

    public function unread()
    {
        return response()->json(['count' => $this->messages->unreadCount($this->messages->actor())]);
    }

    public function show(Request $request, int $id)
    {
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        $this->messages->presence($actor);
        $contact = $this->messages->other($actor, $thread);
        $rows = $this->messages->messages($actor, $thread)->latest('id')->limit(50)->get()->reverse()->values();
        $writable = $this->messages->writable($actor, $thread);
        $blockedByMe = DB::table('messaging_blocks')->where('school_id', $actor->school_id)->where('user_id', $actor->id)->where('blocked_user_id', $contact->id)->exists();
        $this->messages->audit('view', 'Participant opened conversation', $id);
        return view('messaging.show', compact('thread', 'contact', 'rows', 'writable', 'blockedByMe'));
    }

    public function poll(Request $request, int $id)
    {
        $data = $request->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        $this->messages->presence($actor);
        $query = $this->messages->messages($actor, $thread);
        if (isset($data['before'])) {
            $rows = $query->where('id', '<', $data['before'])->latest('id')->limit(50)->get()->reverse()->values();
        } else {
            $rows = $query->where('id', '>', $data['after'] ?? 0)->oldest('id')->limit(50)->get();
        }
        $other = $this->messages->other($actor, $thread);
        return response()->json([
            'messages' => $rows->map(fn ($chat) => $this->payload($chat, $actor->id)),
            'read_through' => (int) $this->messages->messages($actor, $thread)->where('sender_id', $actor->id)->where('read_status', 1)->max('id'),
            'typing' => $this->messages->writable($actor, $thread) && Cache::has('messaging:typing:'.$actor->school_id.':'.$id.':'.$other->id),
            'writable' => $this->messages->writable($actor, $thread),
        ]);
    }

    public function send(Request $request, int $id)
    {
        $request->merge(['message' => is_string($request->input('message')) ? trim($request->input('message')) : $request->input('message')]);
        $data = $request->validate(['message' => 'required|string|max:5000', 'client_message_id' => 'required|uuid']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        $chat = $this->messages->send($actor, $thread, $data['message'], $data['client_message_id']);
        Cache::forget('messaging:typing:'.$actor->school_id.':'.$id.':'.$actor->id);
        return $request->expectsJson() ? response()->json(['message' => $this->payload($chat, $actor->id)], 201)
            : redirect()->route('messaging.show', $id)->with('message', 'Message sent.');
    }

    public function read(Request $request, int $id)
    {
        $data = $request->validate(['through' => 'required|integer|min:1']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        abort_unless($this->messages->messages($actor, $thread)->whereKey($data['through'])->exists(), 422);
        $this->messages->read($actor, $thread, $data['through']);
        return response()->json(['ok' => true]);
    }

    public function typing(Request $request, int $id)
    {
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        abort_unless($this->messages->writable($actor, $thread), 403);
        Cache::put('messaging:typing:'.$actor->school_id.':'.$id.':'.$actor->id, true, 8);
        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, int $id)
    {
        $data = $request->validate(['archived' => 'required|boolean']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        $this->messages->archive($actor, $thread, (bool) $data['archived']);
        $this->messages->audit('update', $data['archived'] ? 'Conversation archived by participant' : 'Conversation restored by participant', $id);
        return redirect()->route('messaging.index')->with('message', $data['archived'] ? 'Conversation archived. History is retained.' : 'Conversation restored.');
    }

    public function block(Request $request, int $id)
    {
        $data = $request->validate(['blocked' => 'required|boolean']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        $other = $this->messages->other($actor, $thread);
        $key = ['school_id' => $actor->school_id, 'user_id' => $actor->id, 'blocked_user_id' => $other->id];
        DB::transaction(function () use ($actor, $other, $key, $data) {
            // Wait for in-flight sends before confirming a block, including
            // historical duplicate threads between the same two participants.
            MessageThrade::where('school_id', $actor->school_id)
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('sender_id', $actor->id)->where('reciver_id', $other->id))
                    ->orWhere(fn ($x) => $x->where('sender_id', $other->id)->where('reciver_id', $actor->id)))
                ->orderBy('id')->lockForUpdate()->get();
            if ($data['blocked']) DB::table('messaging_blocks')->updateOrInsert($key, ['updated_at' => now(), 'created_at' => now()]);
            else DB::table('messaging_blocks')->where($key)->delete();
        });
        $this->messages->audit('update', $data['blocked'] ? 'Contact blocked by participant' : 'Contact unblocked by participant', $id);
        return redirect()->route('messaging.show', $id)->with('message', $data['blocked'] ? 'Contact blocked. Sending in either direction is stopped; history is retained.' : 'Your block was removed.');
    }

    public function report(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000', 'chat_id' => 'nullable|integer|min:1']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $id);
        if (!empty($data['chat_id'])) abort_unless($this->messages->messages($actor, $thread)->whereKey($data['chat_id'])->exists(), 404);
        DB::transaction(function () use ($actor, $id, $data) {
            $reportId = DB::table('messaging_reports')->insertGetId(['school_id' => $actor->school_id, 'thread_id' => $id, 'reporter_id' => $actor->id,
                'chat_id' => $data['chat_id'] ?? null, 'reason' => trim($data['reason']), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            foreach (User::where('school_id', $actor->school_id)->whereNotIn('role_id', [1, 6, 7, 8, 9, 11, 12])->get() as $reviewer) {
                if ($this->messages->active($reviewer) && app(PermissionService::class)->allows($reviewer, 'messaging.moderate')) {
                    \App\Support\Notifications\NotificationService::notify($reviewer->id, $actor->school_id,
                        'Portal conversation reported', 'A participant requested a conversation review.', route('messaging.moderation.show', $reportId), 'message_report');
                }
            }
            $this->messages->audit('create', 'Conversation reported for authorised review', $id);
        });
        return redirect()->route('messaging.show', $id)->with('message', 'Report submitted to your institution. If you are in immediate danger, contact staff directly.');
    }

    public function moderation(Request $request, ?int $id = null)
    {
        $actor = $this->messages->actor();
        abort_unless(app(PermissionService::class)->allows($actor, 'messaging.moderate'), 403);
        $reports = DB::table('messaging_reports')->where('school_id', $actor->school_id)->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->latest('id')->paginate(30);
        $report = $id ? DB::table('messaging_reports')->where('school_id', $actor->school_id)->where('id', $id)->first() : null;
        abort_if($id && !$report, 404);
        $rows = collect();
        $participants = collect();
        if ($report) {
            $thread = MessageThrade::where('school_id', $actor->school_id)->findOrFail($report->thread_id);
            $participants = User::where('school_id', $actor->school_id)->whereIn('id', [$thread->sender_id, $thread->reciver_id])->get(['id', 'name', 'role_id'])->keyBy('id');
            $people = $participants->keys();
            abort_unless($people->count() === 2, 404);
            $rows = Chat::where('school_id', $actor->school_id)->where('message_thrade', $thread->id)
                ->whereIn('sender_id', $people)->whereIn('reciver_id', $people)->whereColumn('sender_id', '!=', 'reciver_id')->orderBy('id')->paginate(50);
            $this->messages->audit('view', 'Authorised moderator accessed reported conversation', $thread->id);
        }
        return view('messaging.moderation', compact('reports', 'report', 'rows', 'participants'));
    }

    public function review(Request $request, int $id)
    {
        $actor = $this->messages->actor();
        abort_unless(app(PermissionService::class)->allows($actor, 'messaging.moderate'), 403);
        $data = $request->validate(['status' => 'required|in:resolved,dismissed,open', 'review_note' => 'required|string|min:10|max:2000']);
        $report = DB::table('messaging_reports')->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($report, 404);
        DB::table('messaging_reports')->where('id', $id)->update($data + ['reviewer_id' => $actor->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        $this->messages->audit('update', 'Moderator recorded report disposition', $report->thread_id);
        return redirect()->route('messaging.moderation.show', $id)->with('message', 'Review recorded. Original messages have been retained.');
    }

    // Legacy URLs retain portal guards but share all conversation authorization.
    public function legacyShow(Request $request, $id) { return $this->show($request, (int) $id); }
    public function legacyStart($id)
    {
        $actor = $this->messages->actor();
        $contact = User::where('school_id', $actor->school_id)->findOrFail((int) $id);
        abort_unless($this->messages->pairAllowed($actor, $contact) && !$this->messages->blocked($actor, $contact), 403);
        // Historical GET links open contact discovery; only a CSRF-protected POST creates a thread.
        return redirect()->route('messaging.index', ['q' => $contact->name]);
    }
    public function legacySend(Request $request)
    {
        $data = $request->validate(['message_thrade' => 'required|integer|min:1', 'reciver_id' => 'required|integer|min:1']);
        $actor = $this->messages->actor();
        $thread = $this->messages->owned($actor, $data['message_thrade']);
        abort_unless((int) $this->messages->other($actor, $thread)->id === (int) $data['reciver_id'], 422);
        $request->merge(['client_message_id' => $request->input('client_message_id', (string) Str::uuid())]);
        return $this->send($request, $thread->id);
    }

    private function payload(Chat $chat, int $actor): array
    {
        return ['id' => $chat->id, 'text' => $chat->message, 'mine' => (int) $chat->sender_id === $actor,
            'read' => (bool) $chat->read_status, 'time' => $chat->created_at->toIso8601String()];
    }
}
