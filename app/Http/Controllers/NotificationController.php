<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One controller behind every role's "My Notifications" page and bell
 * dropdown (student/teacher/parent/admin, see routes/web.php) — every
 * method here is scoped purely by auth()->user()->id, never by role, since
 * a notification inbox is a per-user concept, not a per-role one. Each role
 * gets its own route prefix (student/notifications, teacher/notifications,
 * ...) only so the existing per-role middleware groups and navigation
 * layouts keep working the way every other module in this app already
 * does — the behavior underneath is identical for all of them.
 */
class NotificationController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $notifications = UserNotification::forUser($user->id)
            ->latest('id')
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    /**
     * AJAX partial for the bell dropdown: the most recent few + unread count.
     *
     * ── WHY ONLINE EXAM NOTIFICATIONS ARE MERGED IN HERE ────────────────────
     *
     * The student, teacher and admin headers each included TWO bells:
     *
     *     @include('notifications._bell')
     *     @include('online_exam.notifications')
     *
     * Both worked, and a reader with a pending submission or a pending review saw
     * two badges with two different numbers and no way to tell which was which.
     * That is the reported defect, and the wrong fix is to delete one of them -
     * that would throw away a working notification surface.
     *
     * So the bell in the header is now the ONE notification centre, and it shows
     * both sources. Neither is lost:
     *
     *  - `UserNotification` remains the general institutional inbox;
     *  - `OnlineExamUserNotification` remains the assessment lifecycle stream, and
     *    is merged in here with its own unread count folded into the badge.
     *
     * The dropdown links for exam rows go through
     * `online_exam.notifications.read`, which marks that row read and then
     * redirects - so "mark as read on click" is unchanged for both kinds.
     */
    public function dropdown()
    {
        $user = Auth::user();

        $notifications = UserNotification::forUser($user->id)
            ->latest('id')
            ->take(8)
            ->get();

        $examNotifications = $this->examNotifications($user);
        $unreadCount = UserNotification::forUser($user->id)->unread()->count()
            + $examNotifications->whereNull('read_at')->count();

        return view('notifications._dropdown', compact('notifications', 'unreadCount', 'examNotifications'));
    }

    public function unreadCount()
    {
        $user = Auth::user();

        return response()->json([
            // BOTH sources, so the single badge is an honest total rather than an
            // undercount that disagrees with the dropdown below it.
            'count' => UserNotification::forUser($user->id)->unread()->count()
                + $this->examNotifications($user)->whereNull('read_at')->count(),
        ]);
    }

    /**
     * The assessment lifecycle stream for one user, most recent first.
     *
     * Fails CLOSED on a schema without the table, so a deployment that predates
     * the online exam engine still renders the bell.
     */
    private function examNotifications($user)
    {
        if (! $user || ! \Illuminate\Support\Facades\Schema::hasTable('online_exam_user_notifications')) {
            return collect();
        }

        return \App\Models\OnlineExamUserNotification::forUser((int) $user->school_id, (int) $user->id)
            ->latest('id')
            ->take(8)
            ->get();
    }

    public function markRead(Request $request, $id)
    {
        $user = Auth::user();

        $notification = UserNotification::forUser($user->id)->findOrFail((int) $id);
        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        if ($notification->url) {
            return redirect()->to($notification->url);
        }

        return redirect()->back();
    }

    public function markAllRead()
    {
        $user = Auth::user();

        UserNotification::forUser($user->id)->unread()->update(['read_at' => now()]);

        if ($user && \Illuminate\Support\Facades\Schema::hasTable('online_exam_user_notifications')) {
            \App\Models\OnlineExamUserNotification::forUser((int) $user->school_id, (int) $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return redirect()->back()->with('message', get_phrase('All notifications marked as read.'));
    }
}
