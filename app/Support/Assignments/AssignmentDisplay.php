<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\School;
use App\Models\User;
use App\Support\TenantTimezone;
use Illuminate\Support\Carbon;

/**
 * How assignment instants are shown to one viewer.
 *
 * THE POLICY IS PIIE'S ESTABLISHED ONE, NOT A NEW ONE
 *
 *   viewer's valid timezone -> institution timezone -> application timezone
 *
 * That resolution is `TenantTimezone`, which Live Classes and Course Content
 * already use. This class adds no policy of its own: it asks TenantTimezone which
 * zone applies to THIS viewer and renders the assignment's stored instants in it.
 *
 * WHY THE SEPARATE CLASS
 *
 * `live_classes.timezone` is the zone a class was SCHEDULED in - a record of how it
 * was entered, not a viewer preference, and it is never used to display a time.
 * Assignments have no equivalent column at all: the instants stored on
 * `released_at`, `due_date` and `closes_at` are the truth, full stop. This class
 * exists to make it impossible to reach for the wrong one, and so a lecturer in
 * London and a student in Kampala are both shown their own correct clock for the
 * same instant.
 *
 * A stored instant is never re-stored or duplicated per viewer. Two people are
 * told about the same moment and each reads it where they are.
 */
class AssignmentDisplay
{
    public function __construct(private readonly TenantTimezone $tz) {}

    public function for(Assignment $assignment, ?User $viewer = null): self
    {
        $assignment->setRelation('__display_viewer', $viewer);
        $assignment->setRelation('__display_school', $this->schoolFor($assignment));

        return $this;
    }

    public static function make(Assignment $assignment, ?User $viewer = null): self
    {
        return app(self::class)->for($assignment, $viewer);
    }

    /** The zone this viewer's times are rendered in. */
    public function zone(Assignment $assignment, ?User $viewer = null): string
    {
        return $this->tz->effective(
            $viewer ?? $this->viewer($assignment),
            $this->school($assignment)
        );
    }

    /** The zone the institution reads academic times in. */
    public function institutionZone(Assignment $assignment): string
    {
        return $this->tz->resolve($this->school($assignment));
    }

    public function dueAt(Assignment $assignment, ?User $viewer = null): ?Carbon
    {
        return $this->localise($assignment->due_date, $assignment, $viewer);
    }

    public function releasedAt(Assignment $assignment, ?User $viewer = null): ?Carbon
    {
        return $this->localise($assignment->released_at, $assignment, $viewer);
    }

    public function closesAt(Assignment $assignment, ?User $viewer = null): ?Carbon
    {
        return $this->localise($assignment->closes_at, $assignment, $viewer);
    }

    public function submittedAt(Assignment $assignment, ?User $viewer = null, ?Carbon $instant = null): ?Carbon
    {
        return $this->localise($instant, $assignment, $viewer);
    }

    /**
     * A due date in the viewer's own words: "15 October 2026 at 23:59".
     */
    public function due(Assignment $assignment, ?User $viewer = null): string
    {
        return $this->stamp($this->dueAt($assignment, $viewer));
    }

    public function released(Assignment $assignment, ?User $viewer = null): string
    {
        return $this->stamp($this->releasedAt($assignment, $viewer));
    }

    public function closes(Assignment $assignment, ?User $viewer = null): string
    {
        return $this->stamp($this->closesAt($assignment, $viewer));
    }

    /**
     * The zone label, so a printed time is never ambiguous between Kampala and
     * London. Returns null when the viewer is already on the institution's clock,
     * which keeps the common case quiet.
     */
    public function zoneNote(Assignment $assignment, ?User $viewer = null): ?string
    {
        $viewer ??= $this->viewer($assignment);
        $zone = $this->zone($assignment, $viewer);

        if ($zone === $this->institutionZone($assignment)) {
            return null;
        }

        return 'Times shown in '.$this->tz->humanize($zone).'.';
    }

    /**
     * The institution's own reading of the same instant, for an academically
     * sensitive deadline. Only shown when it differs from the viewer's.
     *
     * @return array{date: string, time: string, label: string}|null
     */
    public function institutionDue(Assignment $assignment): ?array
    {
        if (! $assignment->due_date) {
            return null;
        }

        $local = $this->tz->inTenantTime($assignment->due_date, $this->school($assignment));

        if (! $local) {
            return null;
        }

        return [
            'date' => $local->format('j M Y'),
            'time' => $local->format('H:i'),
            'label' => $this->tz->humanizeFor($this->school($assignment)),
        ];
    }

    private function localise(?Carbon $moment, Assignment $assignment, ?User $viewer): ?Carbon
    {
        if (! $moment) {
            return null;
        }

        return $this->tz->inZone($moment, $this->zone($assignment, $viewer));
    }

    private function stamp(?Carbon $moment): string
    {
        return $moment?->format('j M Y \a\t H:i') ?? 'Not set';
    }

    private function viewer(Assignment $assignment): ?User
    {
        $viewer = $assignment->getRelation('__display_viewer');

        return $viewer instanceof User ? $viewer : null;
    }

    private function school(Assignment $assignment): ?School
    {
        $school = $assignment->getRelation('__display_school');

        if ($school instanceof School) {
            return $school;
        }

        return $assignment->school_id ? School::query()->find($assignment->school_id) : null;
    }

    private function schoolFor(Assignment $assignment): ?School
    {
        $offering = $assignment->courseOffering;

        return $offering?->school_id
            ? School::query()->find($offering->school_id)
            : ($assignment->school_id ? School::query()->find($assignment->school_id) : null);
    }
}
