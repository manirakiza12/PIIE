<?php

namespace App\Support\CourseContent;

use App\Models\CourseOfferingModule;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * THE MODULE LIFECYCLE, in one place.
 *
 * ── WHY THIS EXISTS WHEN `updateModule()` ALREADY ACCEPTS A STATUS ──────────
 *
 * It does accept one. `updateModule()` fills `status` and `released_at` from a
 * request and saves. So the module lifecycle was never MISSING - it was
 * unreachable. The only control was a `<select>` plus an optional datetime, buried
 * inside a collapsed "Module settings" panel:
 *
 *     Visibility   [ Draft | Published | Archived ]
 *     Release on   [ datetime-local          ]
 *                 [ Save module ]
 *
 * A lecturer looking at a module marked "Scheduled" was therefore asked to change a
 * dropdown, and the answer depended on knowing that "Published" and "Scheduled" are
 * the SAME stored state with a different release date. That is not a control, it is
 * an internal detail, and it is why the reported symptom was "the lecturer has no
 * clear action".
 *
 * So the states a lecturer can move between are stated here, and the page offers one
 * button per legal move. Same table, same columns, same service - no parallel
 * workflow and no migration.
 *
 * ── "SCHEDULED" IS NOT A FOURTH STATE ──────────────────────────────────────
 *
 * It is `status = published` with `released_at` in the future, which is already how
 * `displayStatusLabel()` renders it and how `isReleasedToStudents()` decides
 * visibility. Adding a `scheduled` status column value would mean three answers to
 * "may a student see this" - the model, the service and the student page - and they
 * would drift. So scheduling is a RELEASE INSTANT on a published module, and the
 * label is derived, exactly as it is today.
 *
 *   draft      -> lecturer/admin only. A student cannot see, discover or reach it
 *                 by id, however old it is.
 *   published  -> released immediately when `released_at` is null, and at that
 *                 instant when it is not. Labelled "Scheduled" until it passes.
 *   archived   -> withdrawn. Kept for the record; released to nobody.
 *
 * Mirrors `AssignmentLifecycle::TRANSITIONS`, which is the pattern this codebase
 * already uses for exactly this question, and for the same reason: "may a student
 * see it" and "what does the lecturer's list call it" must never be two answers.
 */
class CourseOfferingModuleLifecycle
{
    /**
     * The states a module may be moved to from its current state.
     *
     * `published => published` IS present, and that looks like a mistake until you
     * remember what "Scheduled" is: it is `published` with a future `released_at`, so
     * a scheduled module's STATUS IS ALREADY `published`. Without the self-transition
     * the two moves a scheduled module most needs - release it now, and change the
     * date - were both refused as "cannot be changed to Published".
     *
     * That is not a hypothetical: it was the reported symptom reproduced inside my own
     * fix, and the test for it failed with exactly that DomainException. The move is
     * legal because it changes the RELEASE INSTANT, not the status.
     */
    public const TRANSITIONS = [
        CourseOfferingModule::STATUS_DRAFT => [
            CourseOfferingModule::STATUS_PUBLISHED,
            CourseOfferingModule::STATUS_ARCHIVED,
        ],
        CourseOfferingModule::STATUS_PUBLISHED => [
            // The self-transition: re-publishing a published module is how "release
            // it now" and "reschedule" are expressed.
            CourseOfferingModule::STATUS_PUBLISHED,
            CourseOfferingModule::STATUS_DRAFT,
            CourseOfferingModule::STATUS_ARCHIVED,
        ],
        CourseOfferingModule::STATUS_ARCHIVED => [
            CourseOfferingModule::STATUS_DRAFT,
        ],
    ];

    public static function canTransition(CourseOfferingModule $module, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$module->status] ?? [], true);
    }

    /**
     * @throws DomainException when the move is not permitted
     */
    public static function assertTransition(CourseOfferingModule $module, string $to): void
    {
        if (! in_array($to, CourseOfferingModule::STATUSES, true)) {
            throw new DomainException('That module status is not recognised.');
        }

        if (! self::canTransition($module, $to)) {
            throw new DomainException(
                'A module that is '.self::labelFor($module).' cannot be changed to '.self::labelForStatus($to).'.'
            );
        }
    }

    /**
     * THE MOVE ITSELF.
     *
     * `released_at` is set here rather than trusted from the request, because the
     * two states that matter are inseparable:
     *
     *   - PUBLISH NOW means released AT ONCE. Setting `released_at` to null is the
     *     honest encoding, and it is what `isReleasedToStudents()` treats as "no
     *     waiting". Writing `now()` instead would leave the module labelled
     *     "Scheduled" for as long as the render took, and would make a later
     *     comparison against the past ambiguous.
     *   - PUBLISH LATER means published with a future instant. A release date in the
     *     PAST is not a schedule, so it is normalised to "now" rather than stored,
     *     which stops a mistyped date from silently scheduling content for last week.
     *   - RETURNING TO DRAFT clears the instant, so re-publishing later is a choice
     *     rather than an accident of history.
     */
    public static function apply(CourseOfferingModule $module, string $to, ?string $releasedAt = null, ?int $actorId = null): CourseOfferingModule
    {
        self::assertTransition($module, $to);

        $module->status = $to;
        $module->released_at = null;

        if ($to === CourseOfferingModule::STATUS_PUBLISHED && $releasedAt !== null && trim($releasedAt) !== '') {
            $when = Carbon::parse($releasedAt);

            // A past date is not a schedule. Publish immediately instead of storing
            // an instant in the past, which would read as overdue on every page.
            $module->released_at = $when->isFuture() ? $when : null;
        }

        if ($actorId !== null) {
            $module->updated_by = $actorId;
        }

        $module->save();

        return $module->fresh();
    }

    /** What the lecturer's list calls a module in a given state. */
    public static function labelFor(CourseOfferingModule $module): string
    {
        return $module->displayStatusLabel();
    }

    public static function labelForStatus(string $status): string
    {
        return match ($status) {
            CourseOfferingModule::STATUS_PUBLISHED => 'Published',
            CourseOfferingModule::STATUS_ARCHIVED => 'Archived',
            default => 'Draft',
        };
    }

    /**
     * The actions to offer for a module, keyed by what they do.
     *
     * Returned rather than rendered by the view so the list of legal moves is stated
     * once. The view asks what is permitted instead of re-deriving it from the
     * status, which is how a control set and the rules behind it come to disagree.
     *
     * @return array<string, array{label: string, hint: string, style: string, schedule: bool}>
     */
    public static function actionsFor(CourseOfferingModule $module): array
    {
        $actions = [];

        if (self::canTransition($module, CourseOfferingModule::STATUS_PUBLISHED)) {
            $scheduled = $module->released_at !== null && $module->released_at->isFuture();

            $actions['publish_now'] = [
                'label' => $scheduled ? 'Publish now' : 'Publish now',
                'hint' => $scheduled
                    ? 'Release it straight away instead of waiting for '.($module->released_at->format('j M Y, H:i')).'.'
                    : 'Make it visible to students on this Course Offering now.',
                'style' => 'primary',
                'schedule' => false,
            ];

            $actions['schedule'] = [
                'label' => $scheduled ? 'Reschedule' : 'Schedule publication',
                'hint' => 'Set the moment it becomes visible to students.',
                'style' => 'outline-primary',
                'schedule' => true,
            ];
        }

        if (self::canTransition($module, CourseOfferingModule::STATUS_DRAFT)) {
            // A module already in draft has nothing to return TO, so the button is
            // not offered - offering "Return to Draft" on a draft reads as though it
            // does something.
            if ($module->status !== CourseOfferingModule::STATUS_DRAFT) {
                $actions['draft'] = [
                    'label' => $module->status === CourseOfferingModule::STATUS_PUBLISHED ? 'Unpublish' : 'Return to Draft',
                    'hint' => $module->status === CourseOfferingModule::STATUS_PUBLISHED
                        ? 'Take it back out of the students\' view. Nothing is deleted, and lesson progress is kept.'
                        : 'Withdraw it from the students\' view.',
                    'style' => 'outline-secondary',
                    'schedule' => false,
                ];
            }
        }

        return $actions;
    }
}