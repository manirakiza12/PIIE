<?php

namespace App\Support\LiveClasses;

use App\Mail\LiveClassReminderEmail;
use App\Models\LiveClass;
use App\Models\LiveClassNotification;
use App\Models\Noticeboard;
use App\Models\Session;
use App\Models\User;
use App\Support\Notifications\NotificationService;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a single reminder pass (24h-before or 1h-before) for one class: one
 * in-app Noticeboard entry, plus one email per eligible student.
 *
 * Never called twice for the same (class, reminder type) — the caller
 * (App\Console\Commands\SendLiveClassReminders) checks LiveClassNotification
 * first and only calls this when nothing has been sent yet; recording that a
 * reminder went out is this class's last step, not a guard of its own, so
 * that a partial failure here is visible rather than silently marked "sent".
 */
class LiveClassNotifier
{
    public const WINDOW_LABELS = [
        'reminder_24h' => 'starts in 24 hours',
        'reminder_1h' => 'starts in 1 hour',
    ];

    /**
     * Lifecycle announcements for a Course-Offering-backed Live Class.
     *
     * Structure: an EVENT decides what happened and who must hear it, then
     * CHANNELS deliver it. Today two channels exist - in-app (via the shared
     * NotificationService, so the existing bell/inbox renders it) and email
     * (only when SMTP is genuinely configured). Adding SMS, WhatsApp or push
     * later means adding one delivery method to deliver(); no caller, event or
     * message changes, and Live Classes are not rewritten to accommodate them.
     *
     * Recipients always come from LiveClassEligibility, i.e. CONFIRMED
     * course_registrations for this exact Offering. Nothing here reads
     * Programme, Cohort, Study Plan or legacy Class/Section membership.
     *
     * Every announcement is deduplicated by a row in live_class_notifications,
     * which has a UNIQUE index on (live_class_id, type). Each method claims
     * its key with an insertOrIgnore FIRST: if the insert was ignored, the
     * event has already been announced and nothing is sent. That ordering
     * makes the database - not a read-then-write check - the authority, so two
     * concurrent publishes cannot both notify.
     */
    public static function announcePublished(LiveClass $liveClass): int
    {
        return self::announce(
            $liveClass,
            LiveClassNotification::TYPE_PUBLISHED,
            LiveClassEligibility::eligibleStudentUserIds($liveClass),
            'published',
            fn (array $context): array => [
                'title' => $context['unit'].' live class scheduled',
                'body' => $context['title'].' is scheduled for '.$context['when'].'.',
                'cta' => 'View Live Class',
            ]
        );
    }

    public static function announceRescheduled(LiveClass $liveClass): int
    {
        $key = LiveClassNotification::rescheduledKey($liveClass->scheduled_at);

        if ($key === null) {
            return 0;
        }

        return self::announce(
            $liveClass,
            $key,
            LiveClassEligibility::eligibleStudentUserIds($liveClass),
            'rescheduled',
            fn (array $context): array => [
                'title' => $context['unit'].' live class rescheduled',
                'body' => $context['title'].' has moved to '.$context['when'].'.',
                'cta' => 'View Live Class',
            ]
        );
    }

    public static function announceCancelled(LiveClass $liveClass): int
    {
        // Deliberately NOT eligibleStudentUserIds(): that returns nothing for a
        // cancelled class, so a cancellation would reach precisely the wrong
        // people - nobody. These are the students who were told the class was
        // scheduled and now need to hear that it is off.
        return self::announce(
            $liveClass,
            LiveClassNotification::TYPE_CANCELLED,
            LiveClassEligibility::confirmedOfferingStudentUserIds($liveClass),
            'cancelled',
            fn (array $context): array => [
                'title' => $context['unit'].' live class cancelled',
                'body' => $context['title'].', previously scheduled for '.$context['when'].', has been cancelled.',
                'cta' => 'View Live Class',
            ]
        );
    }

    public static function announceRecording(LiveClass $liveClass): int
    {
        return self::announceRecordingAvailable($liveClass);
    }

    public static function isMailConfigured(): bool
    {
        return !empty(get_settings('smtp_user'))
            && !empty(get_settings('smtp_pass'))
            && !empty(get_settings('smtp_host'))
            && !empty(get_settings('smtp_port'));
    }

    /** @return int number of students actually emailed */
    public static function sendReminder(LiveClass $liveClass, string $type): int
    {
        $liveClass->loadMissing(['subject', 'teacher', 'classRoom', 'academicSession']);

        $windowLabel = get_phrase(self::WINDOW_LABELS[$type] ?? 'is starting soon');
        $studentIds = LiveClassEligibility::eligibleStudentUserIds($liveClass);

        // Offering-backed reminders are recipient-scoped below. A shared
        // Noticeboard item would disclose the class to unrelated students.
        if ($liveClass->course_offering_id === null) {
            self::createNotice($liveClass, $windowLabel);
        }

        // A reminder is a "look at this" message, not an instruction to jump
        // into the meeting. It points at the student's detail page, which shows
        // the schedule and offers Join as a separate, window-gated action - the
        // same destination as every other Live Class notification, and the same
        // reason: a 1-hour reminder arrives an hour early, well outside the
        // 15-minute join window.
        $joinUrl = self::actionUrl($liveClass, 'published');

        NotificationService::notifyMany(
            $studentIds,
            $liveClass->school_id,
            get_phrase('Live Class Reminder') . ': ' . $liveClass->title,
            $liveClass->title . ' ' . $windowLabel . '.',
            $joinUrl,
            'live_class_reminder'
        );

        if ($studentIds->isEmpty() || !self::isMailConfigured()) {
            return 0;
        }
        $sent = 0;
        $tz = app(\App\Support\TenantTimezone::class);

        User::whereIn('id', $studentIds)
            ->where('school_id', $liveClass->school_id)
            ->where('role_id', 7)
            ->whereNotNull('email')
            ->chunkById(100, function ($students) use ($liveClass, $windowLabel, $joinUrl, $tz, &$sent) {
                foreach ($students as $student) {
                    // Derive everything BEFORE the guarded send. Only the
                    // transport call itself may fail silently; a data problem
                    // must still surface instead of being reported as a flaky
                    // mail provider. The times shown are the recipient's own
                    // clock, read from the single stored instant, so this can
                    // never drift from what they were already told and can
                    // never become a second, separate event.
                    $tenant = $liveClass->school_id
                        ? \App\Models\School::query()->find($liveClass->school_id)
                        : null;
                    $localStart = $tz->inEffectiveTime($liveClass->scheduled_at, $student, $tenant);
                    $institutionStart = $tz->inTenantTime($liveClass->scheduled_at, $tenant);
                    $institutionZone = $tz->resolve($tenant);

                    try {
                        Mail::to($student->email)->send(new LiveClassReminderEmail([
                            'student_name' => $student->name,
                            'class_title' => $liveClass->title,
                            'subject' => optional($liveClass->subject)->name,
                            'teacher_name' => optional($liveClass->teacher)->name,
                            'date' => $localStart
                                ? $localStart->format('d M Y')
                                : optional($liveClass->start_date)->format('d M Y'),
                            'time' => $localStart
                                ? $localStart->format('H:i')
                                : ($liveClass->start_time
                                    ? \Illuminate\Support\Carbon::parse($liveClass->start_time)->format('H:i')
                                    : ''),
                            'timezone_label' => $tz->humanize($tz->effective($student, $tenant)),
                            'institution_date' => $institutionStart?->format('d M Y'),
                            'institution_time' => $institutionStart?->format('H:i'),
                            'institution_timezone_label' => $tz->humanize($institutionZone),
                            // Only when the reader's own clock is not the
                            // institution's, so the common case stays quiet.
                            'shows_institution_time' => $institutionZone !== $tz->effective($student, $tenant),
                            'join_url' => $joinUrl,
                            'window_label' => $windowLabel,
                            'school_id' => $liveClass->school_id,
                        ]));

                        $sent++;
                    } catch (\Throwable $e) {
                        // One bad address must not stop the rest of the batch.
                        report($e);
                    }
                }
            });

        return $sent;
    }

    /**
     * Claim the dedup key, build the message, then deliver over every channel.
     *
     * The order is the whole point: the key is claimed BEFORE anything is
     * sent, so a duplicate request loses the race at the unique index and
     * returns 0 without sending. Nothing is claimed when there is nobody to
     * tell - a class with no confirmed students is not "already announced",
     * it is unannounced, and a real publish later still reaches a real
     * audience.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $recipients
     * @param  callable(array<string, string>): array{title: string, body: string, cta: string}  $message
     * @return int recipients told
     */
    private static function announce(
        LiveClass $liveClass,
        string $dedupKey,
        \Illuminate\Support\Collection $recipients,
        string $eventType,
        callable $message
    ): int {
        if ($liveClass->course_offering_id === null || $recipients->isEmpty()) {
            return 0;
        }

        $claimed = LiveClassNotification::query()->insertOrIgnore([
            'school_id' => $liveClass->school_id,
            'live_class_id' => $liveClass->id,
            'type' => $dedupKey,
            'recipient_count' => $recipients->count(),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return 0; // already announced; do not notify again
        }

        $liveClass->loadMissing(['subject', 'teacher', 'courseOffering']);

        // Everyone is told about ONE stored instant. Each person reads it in
        // their own clock (their personal timezone if they chose one, otherwise
        // their institution's), so recipients are grouped by the zone their
        // copy should be rendered in. Nothing is re-stored and nothing differs
        // except the wording of a time that is objectively the same moment.
        $tz = app(\App\Support\TenantTimezone::class);
        $schoolId = (int) $liveClass->school_id;
        $grouped = $tz->groupByEffectiveTimezone($recipients->all(), $schoolId);

        if ($grouped === []) {
            // Defensive: the group query returned nothing (every id already gone
            // from the users table). Fall back to the institution clock rather
            // than dropping the announcement, and still notify everyone.
            $grouped = [
                $tz->resolve($liveClass->school_id ? \App\Models\School::query()->find($schoolId) : null)
                    => $recipients->map(fn ($id) => (int) $id)->all(),
            ];
        }

        foreach ($grouped as $zone => $ids) {
            $copy = $message(self::context($liveClass, is_string($zone) ? $zone : null));

            // Channel 1: in-app. Goes to the existing bell/inbox with no new table.
            NotificationService::notifyMany(
                $ids,
                $schoolId,
                $copy['title'],
                $copy['body'],
                self::actionUrl($liveClass, $eventType),
                'live_class_'.$eventType
            );
        }

        $copy = $message(self::context($liveClass));

        // Channel 2: email. Optional, and never able to fail the request.
        self::emailStudents($liveClass, $recipients, $copy);

        return $recipients->count();
    }

    /**
     * The human facts an announcement is allowed to contain.
     *
     * Note what is absent: meeting_url, meeting_id, meeting_password and
     * recording_url. A notification is a mailbox, a bell and a browser history
     * entry, so a provider secret must never be rendered into one - the student
     * is meant to open the class through PIIE, where LiveClassAccessService
     * decides what they may see. The action URL is a PIIE route, not a meeting
     * link, so following it still performs normal access control.
     *
     * @return array<string, string>
     */
    private static function context(LiveClass $liveClass, ?string $inZone = null): array
    {
        $offering = $liveClass->courseOffering;
        $unit = optional($liveClass->subject)->name
            ?: ($offering?->reference ?: 'your course unit');

        // The time is rendered in the RECIPIENT's zone when one is supplied,
        // and in the institution's zone otherwise. A lecturer in London and a
        // student in Kampala are told about the same moment and each reads it
        // in their own clock. The stored instant is never altered: this only
        // decides which wall clock renders it.
        $tz = app(\App\Support\TenantTimezone::class);
        $tenant = $liveClass->school_id
            ? \App\Models\School::query()->find($liveClass->school_id)
            : null;

        $local = $inZone !== null
            ? $tz->inZone($liveClass->scheduled_at, $inZone)
            : $tz->inTenantTime($liveClass->scheduled_at, $tenant);

        $when = 'a time to be confirmed';
        if ($local) {
            $when = $local->format('j F Y').' at '.$local->format('g:i A');
        } elseif ($liveClass->start_date) {
            $when = $liveClass->start_date->format('j F Y');
            if ($liveClass->start_time) {
                $when .= ' at '.\Illuminate\Support\Carbon::parse($liveClass->start_time)->format('g:i A');
            }
        }

        return [
            'unit' => (string) $unit,
            'title' => (string) $liveClass->title,
            'when' => $when,
            'lecturer' => (string) (optional($liveClass->teacher)->name ?: 'your lecturer'),
            'timezone_label' => $inZone !== null ? $tz->humanize($inZone) : $tz->humanizeFor($tenant),
            'timezone_identifier' => $inZone ?? $tz->resolve($tenant),
        ];
    }

    /**
     * Where the CTA goes. Always a PIIE page that re-checks authorization, so
     * an old or forwarded notification cannot become a way in.
     *
     * This is the student's DETAIL page, never the join endpoint. A "View Live
     * Class" action that jumped straight to /join turned a perfectly valid
     * scheduled class into "Joining is not available for this meeting right
     * now" the moment a student read it before the class started, which reads
     * as a broken class. Viewing a class and entering its meeting are two
     * different intentions and now have two different destinations.
     *
     * ONE destination for every event, including cancellation. Each lifecycle
     * state used to risk pointing at something that state had just made
     * unreachable - most visibly, a cancellation notification linking to a page
     * that 404'd precisely because the class was cancelled, so the student who
     * most needed to read it was the only person who could not. The retained
     * detail page is readable for the whole life of a class: it authorises on
     * confirmed registration, renders whatever state the class is truly in, and
     * offers the join action only while joining is genuinely open. Sending
     * every event here turns "can this link 404?" into a question with one
     * answer rather than one per event type.
     */
    private static function actionUrl(LiveClass $liveClass, string $eventType): string
    {
        return route('student.live_classes.show', $liveClass->id);
    }

    /**
     * A recording has become available.
     *
     * Named for the STATE rather than the file, because the announcement is
     * about the transition to "available", not about a particular URL: a
     * recording link is later corrected more often than it is first published,
     * and keying the dedup ledger on the URL made every correction a brand new
     * "first" notification. The key is now the event, so a student is told once
     * that a recording exists, and the page they land on always shows the
     * current truth.
     */
    public static function announceRecordingAvailable(LiveClass $liveClass): int
    {
        return self::announce(
            $liveClass,
            LiveClassNotification::TYPE_RECORDING_AVAILABLE,
            LiveClassEligibility::eligibleStudentUserIds($liveClass),
            'recording',
            fn (array $context): array => [
                'title' => 'Recording available - '.$context['unit'],
                'body' => 'A recording of '.$context['title'].' is now available.',
                'cta' => 'View Recording',
            ]
        );
    }

    private static function emailStudents(LiveClass $liveClass, \Illuminate\Support\Collection $recipients, array $copy): void
    {
        if (! self::isMailConfigured()) {
            return;
        }

        $context = self::context($liveClass);
        $tz = app(\App\Support\TenantTimezone::class);

        User::whereIn('id', $recipients->all())
            ->where('school_id', $liveClass->school_id)
            ->where('role_id', 7)
            ->whereNotNull('email')
            ->chunkById(100, function ($students) use ($liveClass, $copy, $context, $tz): void {
                foreach ($students as $student) {
                    // The SAME stored instant, read in THIS recipient's own
                    // clock (their personal timezone if they chose one,
                    // otherwise the institution's). A student in New York must
                    // not be told "10:00" when their own wall clock says 03:00,
                    // and the underlying event must not be duplicated or
                    // re-stored to achieve that. Derived before the guarded
                    // send, so a data fault is never mistaken for a flaky
                    // mail provider.
                    $local = $liveClass->scheduled_at
                        ? $tz->inEffectiveTime($liveClass->scheduled_at, $student)
                        : null;

                    try {
                        Mail::to($student->email)->send(new LiveClassReminderEmail([
                            'student_name' => $student->name,
                            'class_title' => $liveClass->title,
                            'subject' => $context['unit'],
                            'teacher_name' => $context['lecturer'],
                            'date' => $local
                                ? $local->format('d M Y')
                                : optional($liveClass->start_date)->format('d M Y'),
                            'time' => $local
                                ? $local->format('H:i')
                                : (string) ($liveClass->start_time ?: ''),
                            // The zone the printed time is in, so "10:00" is
                            // never ambiguous between Kampala and London.
                            'timezone_label' => $tz->humanize($tz->effective($student)),
                            // The institution's own reading of the same instant,
                            // sent only when it differs, so an academically
                            // sensitive time is never ambiguous.
                            'institution_date' => $local ? $tz->inTenantTime($liveClass->scheduled_at, $liveClass->school_id ? \App\Models\School::query()->find($liveClass->school_id) : null)?->format('d M Y') : null,
                            'institution_time' => $local ? $tz->inTenantTime($liveClass->scheduled_at, $liveClass->school_id ? \App\Models\School::query()->find($liveClass->school_id) : null)?->format('H:i') : null,
                            'institution_timezone_label' => $tz->humanizeFor($liveClass->school_id ? \App\Models\School::query()->find($liveClass->school_id) : null),
                            'shows_institution_time' => $local !== null
                                && $tz->effective($student) !== $tz->resolve($liveClass->school_id ? \App\Models\School::query()->find($liveClass->school_id) : null),
                            'join_url' => self::actionUrl($liveClass, 'published'),
                            'window_label' => $copy['title'],
                            'school_id' => $liveClass->school_id,
                        ]));
                    } catch (\Throwable $e) {
                        // A dead address must not abort the batch, and the
                        // in-app notification has already been delivered.
                        report($e);
                    }
                }
            });
    }

    /**
     * Mirrors LiveClassController::createStudentLiveClassNotice() — same
     * school-wide Noticeboard entry style already used for "scheduled" and
     * "published" events, so a reminder reads as one more entry in the same
     * feed rather than a different kind of notification.
     */
    private static function createNotice(LiveClass $liveClass, string $windowLabel): void
    {
        $subjectName = optional($liveClass->subject)->name ?: get_phrase('All courses');
        $classInfo = $liveClass->class_id
            ? (get_phrase('Class') . ': ' . (optional($liveClass->classRoom)->name ?: ('ID ' . $liveClass->class_id)))
            : get_phrase('Class') . ': ' . get_phrase('All classes');

        $noticeTitle = get_phrase('Live Class Reminder') . ': ' . $liveClass->title . ' ' . $windowLabel;
        $noticeBody = get_phrase('This class') . " {$windowLabel}.\n"
            . get_phrase('Course') . ": {$subjectName}\n"
            . "{$classInfo}\n"
            . get_phrase('Date') . ': ' . optional($liveClass->start_date)->format('Y-m-d') . "\n"
            . get_phrase('Time') . ": {$liveClass->start_time} - {$liveClass->end_time}\n"
            . get_phrase('Join Link') . ': ' . ($liveClass->meeting_url ?: 'TBD');

        $sessionId = (int) get_school_settings($liveClass->school_id)->value('running_session');
        if ($sessionId === 0) {
            $sessionId = (int) Session::where('school_id', $liveClass->school_id)->max('id');
        }

        Noticeboard::create([
            'notice_title' => $noticeTitle,
            'notice' => $noticeBody,
            'start_date' => optional($liveClass->start_date)->format('Y-m-d') ?: now()->format('Y-m-d'),
            'start_time' => (string) ($liveClass->start_time ?: ''),
            'end_date' => optional($liveClass->start_date)->format('Y-m-d') ?: now()->format('Y-m-d'),
            'end_time' => (string) ($liveClass->end_time ?: ''),
            'status' => 1,
            'show_on_website' => 0,
            'image' => '',
            'school_id' => $liveClass->school_id,
            'session_id' => $sessionId > 0 ? $sessionId : 0,
        ]);
    }
}
