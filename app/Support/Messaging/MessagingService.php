<?php

namespace App\Support\Messaging;

use App\Models\{AuditLog, Chat, MessageThrade, School, User, UserNotification};
use App\Models\{CourseOfferingLecturerAllocation, CourseRegistration};
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\Permissions\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{Cache, DB, Schema};

final class MessagingService
{
    public const STAFF_ROLES = [2, 3, 4, 5, 10];

    public function active(User $user): bool
    {
        return (int) $user->school_id > 0
            && !in_array(strtolower((string) $user->account_status), ['disable', 'disabled', 'suspended', 'inactive'], true)
            && !$user->isStaffPortalBlocked();
    }

    public function staff(User $user): bool
    {
        if (in_array((int) $user->role_id, [1, 6, 7, 8, 9, 11, 12], true)) return false;
        return in_array((int) $user->role_id, self::STAFF_ROLES, true)
            || app(PermissionService::class)->allows($user, 'messaging.use');
    }

    public function actor(): User
    {
        $user = auth()->user();
        abort_unless($user && $this->active($user) && !$user->force_password_change
            && (in_array((int) $user->role_id, [6, 7], true) || $this->staff($user)), 403);
        return $user;
    }

    public function pairAllowed(User $actor, User $other): bool
    {
        return (int) $actor->id !== (int) $other->id
            && (int) $actor->school_id === (int) $other->school_id
            && $this->active($actor) && $this->active($other)
            && $this->coursePairAllowed($actor, $other)
            && ($this->staff($actor) || $this->staff($other))
            && (in_array((int) $actor->role_id, [6, 7], true) || $this->staff($actor))
            && (in_array((int) $other->role_id, [6, 7], true) || $this->staff($other));
    }

    /** Ordinary HEI lecturer/student contact follows confirmed course participation. */
    private function coursePairAllowed(User $actor, User $other): bool
    {
        $roles = [(int) $actor->role_id, (int) $other->role_id];
        sort($roles);
        if ($roles !== [3, 7]) return true;
        $school = School::find($actor->school_id);
        if (!$school || $school->academicStructure() === 'class_based') return true;

        $lecturer = (int) $actor->role_id === 3 ? $actor : $other;
        $student = (int) $actor->role_id === 7 ? $actor : $other;
        if (!Schema::hasColumn('course_registrations', 'course_offering_id')) return false;
        $registered = CourseRegistration::where('school_id', $actor->school_id)
            ->where('student_id', $student->id)->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->pluck('course_offering_id');
        $access = app(LecturerCourseOfferingAccess::class);
        foreach ($registered->filter()->unique() as $id) {
            $offering = $access->resolveForLecturer($lecturer, (int) $id);
            if ($offering && in_array($offering->my_role, [CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER, CourseOfferingLecturerAllocation::ROLE_CO_LECTURER], true)
                && $access->teachingActionsAllowed($offering)) return true;
        }
        return false;
    }

    public function owned(User $actor, int $id): MessageThrade
    {
        $thread = MessageThrade::where('school_id', $actor->school_id)
            ->where(fn ($q) => $q->where('sender_id', $actor->id)->orWhere('reciver_id', $actor->id))
            ->findOrFail($id);
        $this->other($actor, $thread); // Reject corrupt cross-school participant references.
        return $thread;
    }

    public function other(User $actor, MessageThrade $thread): User
    {
        $id = (int) $thread->sender_id === (int) $actor->id ? $thread->reciver_id : $thread->sender_id;
        return User::where('school_id', $actor->school_id)->findOrFail($id);
    }

    public function blocked(User $actor, User $other): bool
    {
        return DB::table('messaging_blocks')->where('school_id', $actor->school_id)
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('user_id', $actor->id)->where('blocked_user_id', $other->id))
                ->orWhere(fn ($x) => $x->where('user_id', $other->id)->where('blocked_user_id', $actor->id)))->exists();
    }

    public function writable(User $actor, MessageThrade $thread): bool
    {
        $other = $this->other($actor, $thread);
        return $this->pairAllowed($actor, $other) && !$this->blocked($actor, $other);
    }

    public function messages(User $actor, MessageThrade $thread): Builder
    {
        $other = $this->other($actor, $thread);
        // Legacy/corrupt rows with forged recipients must never appear in a legitimate thread.
        return Chat::where('school_id', $actor->school_id)->where('message_thrade', $thread->id)
            ->where(fn ($q) => $q->where(fn ($x) => $x->where('sender_id', $actor->id)->where('reciver_id', $other->id))
                ->orWhere(fn ($x) => $x->where('sender_id', $other->id)->where('reciver_id', $actor->id)));
    }

    public function start(User $actor, int $recipient): MessageThrade
    {
        return DB::transaction(function () use ($actor, $recipient) {
            // One canonical conversation for either direction, including concurrent starts.
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            $other = User::where('school_id', $actor->school_id)->findOrFail($recipient);
            abort_unless($this->pairAllowed($actor, $other) && !$this->blocked($actor, $other), 403, 'This contact is not available for messaging.');
            $thread = MessageThrade::where('school_id', $actor->school_id)
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('sender_id', $actor->id)->where('reciver_id', $other->id))
                    ->orWhere(fn ($x) => $x->where('sender_id', $other->id)->where('reciver_id', $actor->id)))
                ->oldest('id')->first();
            if (!$thread) {
                $thread = MessageThrade::create(['school_id' => $actor->school_id, 'sender_id' => $actor->id, 'reciver_id' => $other->id]);
                $this->audit('create', 'Conversation started', $thread->id);
            }
            $this->archive($actor, $thread, false);
            return $thread;
        });
    }

    public function send(User $actor, MessageThrade $thread, string $text, string $clientId): Chat
    {
        return DB::transaction(function () use ($actor, $thread, $text, $clientId) {
            MessageThrade::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->writable($actor, $thread), 403, 'Messaging is unavailable for this conversation.');
            $existing = Chat::where('school_id', $actor->school_id)->where('sender_id', $actor->id)->where('client_message_id', $clientId)->first();
            if ($existing) {
                abort_unless((int) $existing->message_thrade === (int) $thread->id, 422);
                abort_unless($existing->message === $text, 422, 'This submission was already sent with different text. Reload the conversation before sending a new message.');
                return $existing;
            }
            $other = $this->other($actor, $thread);
            $chat = Chat::create(['school_id' => $actor->school_id, 'message_thrade' => $thread->id,
                'sender_id' => $actor->id, 'reciver_id' => $other->id, 'message' => $text,
                'read_status' => 0, 'client_message_id' => $clientId]);
            $url = route('messaging.show', $thread->id);
            $notification = UserNotification::where('school_id', $actor->school_id)->where('user_id', $other->id)
                ->where('type', 'portal_message')->where('url', $url)->whereNull('read_at')->first();
            $data = ['title' => 'New portal message', 'body' => 'Open Messages to read and reply.', 'updated_at' => now()];
            if ($notification) {
                $notification->update($data);
            } else {
                UserNotification::create($data + ['school_id' => $actor->school_id, 'user_id' => $other->id, 'type' => 'portal_message', 'url' => $url]);
            }
            $delivery = DB::table('messaging_email_deliveries')->where('school_id', $actor->school_id)
                ->where('user_id', $other->id)->where('thread_id', $thread->id)->first();
            if (!$delivery) {
                DB::table('messaging_email_deliveries')->insert(['school_id' => $actor->school_id, 'user_id' => $other->id,
                    'thread_id' => $thread->id, 'last_chat_id' => $chat->id, 'status' => 'pending', 'attempts' => 0,
                    'available_at' => now()->addMinutes(2), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $available = $delivery->status === 'pending' ? $delivery->available_at : now()->addMinutes(2);
                if ($delivery->last_sent_at && now()->parse($delivery->last_sent_at)->addMinutes(10)->gt($available)) {
                    $available = now()->parse($delivery->last_sent_at)->addMinutes(10);
                }
                DB::table('messaging_email_deliveries')->where('id', $delivery->id)->update([
                    'last_chat_id' => $chat->id, 'status' => 'pending', 'attempts' => 0, 'available_at' => $available, 'updated_at' => now()]);
            }
            // A new message returns the conversation to both participants' inboxes.
            DB::table('message_thread_preferences')->where('school_id', $actor->school_id)->where('thread_id', $thread->id)->update(['archived_at' => null, 'updated_at' => now()]);
            $this->audit('create', 'Message stored', $thread->id);
            return $chat;
        });
    }

    public function read(User $actor, MessageThrade $thread, int $through): void
    {
        $changed = $this->messages($actor, $thread)->where('reciver_id', $actor->id)
            ->where('id', '<=', $through)->where('read_status', 0)->update(['read_status' => 1]);
        if (!$this->messages($actor, $thread)->where('reciver_id', $actor->id)->where('read_status', 0)->exists()) {
            UserNotification::where('school_id', $actor->school_id)->where('user_id', $actor->id)->where('type', 'portal_message')
                ->where('url', route('messaging.show', $thread->id))->whereNull('read_at')->update(['read_at' => now()]);
        }
        if ($changed) $this->audit('update', 'Incoming messages marked read', $thread->id);
    }

    public function archive(User $actor, MessageThrade $thread, bool $archive): void
    {
        DB::table('message_thread_preferences')->updateOrInsert([
            'school_id' => $actor->school_id, 'user_id' => $actor->id, 'thread_id' => $thread->id,
        ], ['archived_at' => $archive ? now() : null, 'updated_at' => now()]);
    }

    public function presence(User $actor): void
    {
        Cache::put('messaging:presence:'.$actor->school_id.':'.$actor->id, true, 60);
    }

    public function unreadCount(User $actor): int
    {
        return DB::table('chats')->join('message_thrades as t', 't.id', '=', 'chats.message_thrade')
            ->join('users as sender', 'sender.id', '=', 'chats.sender_id')
            ->where('chats.school_id', $actor->school_id)->where('t.school_id', $actor->school_id)->where('sender.school_id', $actor->school_id)
            ->where('chats.reciver_id', $actor->id)->where('chats.read_status', 0)
            ->whereRaw('((t.sender_id = ? AND t.reciver_id = chats.sender_id) OR (t.reciver_id = ? AND t.sender_id = chats.sender_id))', [$actor->id, $actor->id])->count();
    }

    public function audit(string $action, string $description, int $thread): void
    {
        AuditLog::record($action, 'Messaging', $description.' (#'.$thread.').', [
            'record_type' => MessageThrade::class, 'record_id' => $thread,
        ]);
    }

    public function label(User $user): string
    {
        return match ((int) $user->role_id) {
            2 => 'Administrator', 3 => 'Lecturer', 4 => 'Finance', 5 => 'Library', 10 => 'Student welfare',
            6 => 'Parent / guardian', 7 => 'Student', default => 'Support staff',
        };
    }
}
