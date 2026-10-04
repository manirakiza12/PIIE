<?php

namespace App\Support\LiveClasses;

use App\Models\LiveClass;
use App\Models\User;
use App\Support\TenantConfiguration;
use Illuminate\Support\Carbon;

/**
 * The Live Class experience, as a set of states a person can act on.
 *
 * WHY THIS EXISTS
 *
 * The stored lifecycle was already correct and is not being changed: a class is
 * published or it is not, and whether it is "live" is DERIVED from the clock by
 * LiveClass::getComputedStatusAttribute(). The confusion came from the interface,
 * not the data. A lecturer's page offered only "Unpublish" and "Cancel Class",
 * with no way to start, enter or end a class; a student's page showed a
 * "Join Live Class" button styled as active while the text underneath said
 * joining had not opened yet, so pressing it appeared to do nothing.
 *
 * So this class is presentation only. It adds no state, writes nothing, and
 * introduces no new rule of its own: every judgement below is delegated to the
 * existing authorities - LiveClassAccessService (who may join, when the window
 * is open), LiveClass::computed_status (whether it is live or finished) and the
 * Lecturer allocation (whether this lecturer may host it at all).
 *
 * Publishing and starting are deliberately different concepts. "Published"
 * means students can see the class and have been notified. "Started" means the
 * meeting is happening. There is no stored "started" flag and this class does
 * not invent one: a class becomes live because the clock reached its start time
 * while published, exactly as before.
 *
 * @phpstan-type LifecycleState = 'draft'|'upcoming'|'ready'|'live'|'completed'|'cancelled'
 */
class LiveClassLifecycle
{
    /** Minutes before the start that joining (and hosting) opens. */
    public const JOIN_LEAD_MINUTES = 15;

    public function __construct(private LiveClassAccessService $access) {}

    /**
     * Everything both the lecturer and student screens need, from one place.
     *
     * @return array<string, mixed>
     */
    public function for(LiveClass $class, ?User $viewer = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $class->loadMissing(['teacher', 'subject', 'courseOffering']);

        $startsAt = $class->scheduled_at?->copy();
        $endsAt = $class->ends_at?->copy();
        $opensAt = $startsAt?->copy()->subMinutes(self::JOIN_LEAD_MINUTES);
        $closesAt = $endsAt?->copy()->addMinutes(self::JOIN_LEAD_MINUTES);

        $state = $this->state($class, $startsAt, $opensAt, $endsAt, $now);
        $windowOpen = $this->access->withinJoinWindow($class, $now);

        $isStudent = $viewer !== null && (int) $viewer->role_id === 7;

        // Authorisation is never re-decided here: these are the same two calls
        // the join endpoint makes a moment later.
        $canHost = $viewer !== null
            && ! $isStudent
            && $this->access->canLecturerHost($viewer, $class, $now);
        $canJoin = $viewer !== null
            && $isStudent
            && $this->access->canStudentJoin($viewer, $class, $now);

        return [
            'state' => $state,
            'now' => $now,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'joinOpensAt' => $opensAt,
            'joinClosesAt' => $closesAt,
            'windowOpen' => $windowOpen,
            'canHost' => $canHost,
            'canJoin' => $canJoin,
            'isRunning' => $state === 'live',
            'isTerminal' => in_array($state, ['completed', 'cancelled'], true),
            // The class is past its scheduled end but nobody has said what
            // happened. Exposed as a flag so panels can offer the decision
            // rather than silently showing an empty history entry.
            'awaitingConclusion' => $state === 'not_concluded',
            'startsInMinutes' => $startsAt
                // Computed from the two timestamps rather than from
                // Carbon::diffInMinutes(), whose sign depends on argument order
                // and silently produced a negative value (and therefore a blank
                // countdown) for a class in the future.
                ? max(0, (int) round(($startsAt->getTimestamp() - $now->getTimestamp()) / 60))
                : null,
            'durationMinutes' => ($startsAt && $endsAt) ? (int) $startsAt->diffInMinutes($endsAt) : null,
            'label' => $this->label($state),
        ];
    }

    /**
     * The single state machine.
     *
     * Deliberately derived, never stored. Ordering matters: a cancelled or
     * lecturer-ended class is terminal whatever the clock says, and an
     * unpublished class is a draft even if its start time has passed.
     */
    public function state(LiveClass $class, ?Carbon $startsAt, ?Carbon $opensAt, ?Carbon $endsAt, Carbon $now): string
    {
        if ($class->status === LiveClass::STATUS_CANCELLED) {
            return 'cancelled';
        }

        // A lecturer who pressed "End Class" closed it early; that decision
        // outranks the clock, which would otherwise keep calling it live until
        // its scheduled end time.
        if ($class->status === LiveClass::STATUS_ENDED) {
            return 'completed';
        }

        if (! $class->is_published) {
            return 'draft';
        }

        if (! $startsAt || ! $endsAt) {
            return 'upcoming';
        }

        // Past the scheduled end and nobody has said what happened.
        //
        // This is deliberately NOT 'completed'. The clock can prove that a
        // scheduled window elapsed; it cannot prove anyone taught, joined or
        // listened, and a class that was quietly forgotten would otherwise be
        // filed in the academic record as though it had run. The class is
        // neither running nor finished - it is awaiting a decision, and the
        // lecturer is the only person who can supply it.
        if ($now->greaterThan($endsAt)) {
            return 'not_concluded';
        }

        // The join window has not opened yet: the class is simply upcoming,
        // however close it is.
        if ($now->lessThan($opensAt)) {
            return 'upcoming';
        }

        // Inside the window but before the nominal start: ready to go.
        return $now->lessThan($startsAt) ? 'ready' : 'live';
    }

    /** Human wording for a state, in the application's own terms. */
    public function label(string $state): string
    {
        return match ($state) {
            'draft' => get_phrase('Draft'),
            'upcoming' => get_phrase('Upcoming'),
            'ready' => get_phrase('Ready to start'),
            'live' => get_phrase('Live Now'),
            'completed' => get_phrase('Class Completed'),
            'cancelled' => get_phrase('Class Cancelled'),
            'not_concluded' => get_phrase('Ended without confirmation'),
            default => get_phrase('Scheduled'),
        };
    }

    /**
     * "Starts in 48 minutes" and friends, counted from the application's own
     * clock so it can never disagree with the join-window maths.
     */
    public function countdown(array $lifecycle): string
    {
        if ($lifecycle['state'] !== 'upcoming' && $lifecycle['state'] !== 'ready') {
            return '';
        }

        $minutes = (int) $lifecycle['startsInMinutes'];

        if ($minutes <= 0) {
            return '';
        }

        if ($minutes < 60) {
            // get_phrase() is a language-table lookup and does not interpolate
            // :placeholders, so the convention here is get_phrase + str_replace.
            return str_replace(':count', (string) $minutes, $minutes === 1
                ? get_phrase('Starts in :count minute.')
                : get_phrase('Starts in :count minutes.'));
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0
            ? str_replace([':h', ':m'], [$hours, $rest], get_phrase('Starts in :h hr :m min.'))
            : str_replace(':h', $hours, get_phrase('Starts in :h hr.'));
    }

    /**
     * The single sentence that tells a student what to do and when. Used for
     * every non-joinable state so the wording lives in exactly one place.
     */
    public function studentMessage(array $lifecycle, LiveClass $class): string
    {
        $time = fn ($value) => $value ? $value->format('g:i A') : '';

        return match ($lifecycle['state']) {
            'cancelled' => get_phrase('This Live Class was cancelled.'),
            'draft' => get_phrase('This Live Class is not available.'),
            'upcoming' => str_replace(
                [':open', ':start'],
                [$time($lifecycle['joinOpensAt']), $time($lifecycle['startsAt'])],
                get_phrase('Join opens at :open. This Live Class begins at :start.')
            ),
            'ready' => str_replace(
                ':start',
                $time($lifecycle['startsAt']),
                get_phrase('This Live Class begins at :start. You can join now.')
            ),
            'live' => get_phrase('This Live Class is live. You can join now.'),
            'completed' => str_replace(
                ':time',
                $time($lifecycle['startsAt']),
                get_phrase('This Live Class finished. It began at :time.')
            ),
            // Never say it finished, and never blame the student. The truth is
            // that nobody closed it out, and that is the lecturer's to resolve.
            'not_concluded' => get_phrase('The scheduled time for this Live Class has passed. Your lecturer has not yet confirmed whether it took place, and joining is closed.'),
            default => get_phrase('This Live Class is scheduled.'),
        };
    }

    /**
     * The lecturer's primary next step. Exactly one, always, so the page cannot
     * present "Publish" and "Start" as interchangeable.
     *
     * @return array{key: string, label: string, help: string}|null
     */
    public function lecturerPrimaryAction(array $lifecycle): ?array
    {
        return match ($lifecycle['state']) {
            'draft' => [
                'key' => 'publish',
                'label' => get_phrase('Publish Class'),
                'help' => get_phrase('Publish this class so registered students can see it and are notified.'),
            ],
            'upcoming' => [
                'key' => 'wait',
                'label' => get_phrase('Upcoming Live Class'),
                'help' => str_replace(
                    ':time',
                    $lifecycle['joinOpensAt'] ? $lifecycle['joinOpensAt']->format('g:i A') : '—',
                    get_phrase('You can start the classroom at :time.')
                ),
            ],
            'ready' => [
                'key' => 'start',
                'label' => get_phrase('Start Live Class'),
                'help' => get_phrase('Open the classroom. Students can already join.'),
            ],
            'live' => [
                'key' => 'enter',
                'label' => get_phrase('Enter Classroom'),
                'help' => get_phrase('This class is live. End it when you have finished teaching.'),
            ],
            'completed' => [
                'key' => 'view',
                'label' => get_phrase('View Summary'),
                'help' => get_phrase('This class has finished. Its record and any recording are kept.'),
            ],
            // The one place the clock is allowed to prompt a person. It offers
            // both truthful answers rather than a single "Mark Completed", so
            // the record can never come to assert teaching that did not happen.
            'not_concluded' => [
                'key' => 'conclude',
                'label' => get_phrase('Confirm what happened'),
                'help' => get_phrase('The scheduled end time has passed but this class was never closed. If you taught it, mark it completed. If it did not take place, cancel it. PIIE will not record it as completed on its own.'),
            ],
            'cancelled' => [
                'key' => 'none',
                'label' => get_phrase('Class Cancelled'),
                'help' => get_phrase('This class was cancelled and is kept for the record.'),
            ],
            default => null,
        };
    }
}
