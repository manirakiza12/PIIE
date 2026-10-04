<?php

namespace App\Support\LiveClasses;

use App\Models\LiveClass;
use App\Models\User;
use App\Models\School;
use App\Support\TenantTimezone;
use Illuminate\Support\Carbon;

/**
 * THE ONLY place a Live Class time may be turned into text.
 *
 * WHY A SINGLE FORMATTER
 *
 * A Live Class has always stored the same moment twice: as UTC instants
 * (`scheduled_at`, `ends_at`) and as the wall-clock columns the author typed
 * (`start_time`, `end_time`). Those are the same instant only when the reader
 * happens to share the scheduler's timezone, and the module was reading both:
 *
 *     the header said "10:46 - 11:00"   (the raw start_time/end_time columns)
 *     the join panel said "begins at 7:46 AM"  (scheduled_at, in the viewer's zone)
 *
 * For class #45 in the real database those are 07:46 UTC stored against a typed
 * 10:46 - a three hour disagreement inside one page, for a single meeting.
 * A student cannot tell which number is the meeting, and a student who arrives
 * an hour late or an hour early is entirely forgiven by the product.
 *
 * So: the instant is the fact, and this class renders it. It resolves the
 * viewer's own timezone through TenantTimezone, exactly as notifications and
 * email do, so a lecturer in London, a student in Kampala and the official
 * institutional record all describe one moment in three correct clocks.
 *
 * The wall-clock columns are never read here. They remain in the database as a
 * record of what was typed, and are still written on save, but nothing renders
 * them - a value that is wrong for almost every reader has no business on a
 * screen.
 *
 * JOINING IS NOT AFFECTED. Nothing in this class decides whether a join is
 * allowed; `withinJoinWindow()` compares the stored instants directly and is
 * deliberately timezone-blind. Presentation changes; authorization does not.
 */
class LiveClassDisplay
{
    public function __construct(private TenantTimezone $tz) {}

    /**
     * A formatter bound to one class and one viewer.
     *
     * Returns a fresh instance rather than mutating shared state, so two
     * viewers of the same class (a lecturer and a student on the same page
     * request in a test, two tenants in a report) can never read each other's
     * clock.
     */
    public function for(LiveClass $class, ?User $viewer = null): self
    {
        $tenant = $class->school_id ? School::query()->find($class->school_id) : null;

        $display = new self($this->tz);
        $display->class = $class;
        $display->zone = $this->tz->effective($viewer, $tenant);
        $display->institutionZone = $this->tz->resolve($tenant);

        return $display;
    }

    private ?LiveClass $class = null;

    private string $zone = 'UTC';

    private string $institutionZone = 'UTC';

    /** Date only, in the viewer's clock. */
    public function date(): string
    {
        $local = $this->localStart();

        return $local ? $local->format('j M Y') : (string) get_phrase('To be confirmed');
    }

    public function startTime(): string
    {
        return $this->localStart()?->format('g:i A') ?? '';
    }

    public function endTime(): string
    {
        $local = $this->tz->inZone($this->class?->ends_at, $this->zone);

        return $local ? $local->format('g:i A') : '';
    }

    /**
     * The full span, in one string, in one clock. Returns an honest placeholder
     * rather than a half-range like "10:46 - " when only the start is known.
     */
    public function timeRange(): string
    {
        $start = $this->startTime();
        $end = $this->endTime();

        if ($start === '' && $end === '') {
            return (string) get_phrase('To be confirmed');
        }

        if ($start === '' || $end === '') {
            return $start !== '' ? $start : $end;
        }

        return $start.' - '.$end;
    }

    /** Machine-friendly, for tables and for anything a test asserts on. */
    public function timeRange24(): string
    {
        $start = $this->localStart();
        $end = $this->tz->inZone($this->class?->ends_at, $this->zone);

        if (! $start || ! $end) {
            return '';
        }

        return $start->format('H:i').' - '.$end->format('H:i');
    }

    public function localStart(): ?Carbon
    {
        return $this->tz->inZone($this->class?->scheduled_at, $this->zone);
    }

    public function localEnd(): ?Carbon
    {
        return $this->tz->inZone($this->class?->ends_at, $this->zone);
    }

    /**
     * Names the zone the printed times are in, and gives the institution's
     * reading too when the two differ.
     *
     * A bare "10:00" is genuinely ambiguous between Kampala and London, and the
     * reader is the only one who knows where they are. When their own clock is
     * not the institution's, both are shown so nobody has to guess which one the
     * academic record will hold.
     */
    public function zoneNote(): string
    {
        $viewerLabel = $this->tz->humanize($this->zone);

        if ($this->zone === $this->institutionZone) {
            return $viewerLabel;
        }

        $institution = $this->tz->resolve(
            $this->class?->school_id ? School::query()->find($this->class->school_id) : null
        );

        return str_replace(
            ':institution',
            $this->tz->humanize($institution),
            get_phrase('Shown in your timezone. Institution time is in :institution.')
        );
    }

    /** When the classroom was first opened, in the viewer's clock, or null. */
    public function startedAt(): ?string
    {
        return $this->stamp($this->class?->started_at);
    }

    /** When the class was concluded, in the viewer's clock, or null. */
    public function endedAt(): ?string
    {
        return $this->stamp($this->class?->ended_at);
    }

    private function stamp(?Carbon $moment): ?string
    {
        $local = $this->tz->inZone($moment, $this->zone);

        return $local ? $local->format('j M Y, g:i A') : null;
    }

    /**
     * When the class was cancelled, in the viewer's clock, or null when it was
     * never recorded.
     *
     * Null is a real answer, not a formatting gap. Classes that predate the
     * cancellation-evidence columns have none, and the interface says so rather
     * than substituting the class's scheduled time - which would be a different
     * moment entirely.
     */
    public function cancelledAt(): ?string
    {
        $local = $this->tz->inZone($this->class?->cancelled_at, $this->zone);

        return $local ? $local->format('j M Y, g:i A') : null;
    }

    /** The institution's own reading of the same instant, for a dual-clock line. */
    public function institutionTimeRange(): string
    {
        $start = $this->tz->inZone($this->class?->scheduled_at, $this->institutionZone);
        $end = $this->tz->inZone($this->class?->ends_at, $this->institutionZone);

        if (! $start || ! $end) {
            return '';
        }

        return $start->format('g:i A').' - '.$end->format('g:i A');
    }
}
