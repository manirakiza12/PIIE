{{--
    THE ONE NOTIFICATION CENTRE.

    Two streams are shown, neither of them optional:

      - `UserNotification`, the general institutional inbox (announcements,
        attendance, fees, timetable);
      - `OnlineExamUserNotification`, the assessment lifecycle (exam awaiting
        review, marking returned, a student's submission, a published result).

    They were previously two SEPARATE bells in the same header - `@include
    ('notifications._bell')` next to `@include('online_exam.notifications')` -
    so a user with a pending exam review saw two badges carrying two different
    numbers and could not tell which was which. The header now has one control,
    and it is this one.

    Both streams are counted in the badge by `NotificationController::dropdown()`
    and `unreadCount()`, so the number on the bell agrees with the list under it.

    Links are deliberately left as the controller built them: an exam row goes
    through `online_exam.notifications.read`, which marks that row read and then
    redirects to the exam's own screen. That is the existing "mark as read on
    click" behaviour, unchanged.
--}}
@php
    $examItems = $examNotifications ?? collect();
@endphp

@forelse($notifications as $notification)
    <li>
        <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="notification-dropdown-item">
            @csrf
            <button type="submit" class="dropdown-item d-flex align-items-start gap-2 {{ $notification->read_at ? '' : 'fw-semibold' }}" style="white-space:normal;">
                @if(!$notification->read_at)
                    <span style="width:7px;height:7px;min-width:7px;border-radius:50%;background:#3a86ff;margin-top:6px;"></span>
                @else
                    <span style="width:7px;height:7px;min-width:7px;"></span>
                @endif
                <span>
                    <span class="d-block">{{ \Illuminate\Support\Str::limit($notification->title, 60) }}</span>
                    <span class="d-block text-muted small fw-normal">{{ $notification->created_at->diffForHumans() }}</span>
                </span>
            </button>
        </form>
    </li>
@empty
    @if($examItems->isEmpty())
        <li><span class="dropdown-item text-muted">{{ get_phrase('No notifications yet') }}</span></li>
    @endif
@endforelse

@if($examItems->isNotEmpty())
    @if($notifications->isNotEmpty())
        <li><hr class="dropdown-divider"></li>
    @endif
    <li class="px-3 py-1 text-uppercase small fw-semibold text-muted">{{ get_phrase('Online Exams') }}</li>
    @foreach($examItems as $examNotification)
        <li>
            <a class="dropdown-item d-flex align-items-start gap-2 {{ $examNotification->read_at ? '' : 'fw-semibold' }}"
               style="white-space:normal;"
               href="{{ route('online_exam.notifications.read', $examNotification->id) }}">
                @if(!$examNotification->read_at)
                    <span style="width:7px;height:7px;min-width:7px;border-radius:50%;background:#e5484d;margin-top:6px;"></span>
                @else
                    <span style="width:7px;height:7px;min-width:7px;"></span>
                @endif
                <span>
                    <span class="d-block">{{ \Illuminate\Support\Str::limit($examNotification->title, 60) }}</span>
                    <span class="d-block text-muted small fw-normal">{{ \Illuminate\Support\Str::limit($examNotification->message, 90) }}</span>
                    <span class="d-block text-muted small fw-normal">{{ optional($examNotification->created_at)->diffForHumans() }}</span>
                </span>
            </a>
        </li>
    @endforeach
@endif

<li><hr class="dropdown-divider"></li>
<li><a class="dropdown-item text-center" href="{{ route('notifications.index') }}">{{ get_phrase('See all notifications') }}</a></li>