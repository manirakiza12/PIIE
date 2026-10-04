<?php

namespace App\Support\LiveClasses;

/**
 * What a meeting-provider call produced.
 *
 * ── WHY NOT JUST A STRING ──────────────────────────────────────────────────
 *
 * A Google Meet conference needs three things recorded, not one: the join URL,
 * the Google Calendar event id, and whether the conference has actually
 * materialised. Returning a bare URL — as the previous implementation did —
 * forces the caller to either lose the event id or go and re-query Google for it.
 * Losing it is not acceptable: without an event id, cancelling a class leaves an
 * orphan conference on the lecturer's real calendar, and a student's only route
 * into that class keeps working.
 *
 * Read-only, and deliberately boring. A mutable array in a controller method
 * invites "set it after the fact", which is how a half-populated result reaches
 * the database.
 */
final class MeetingResolution
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $eventId = null,
        public readonly ?string $conferenceStatus = null,
        public readonly ?string $htmlLink = null,
    ) {}

    /**
     * A provider that returned only a link, with no calendar identity behind it.
     * Jitsi's generated room and a hand-pasted Zoom link land here.
     */
    public static function urlOnly(string $url): self
    {
        return new self($url);
    }

    /**
     * Google accepted the event but has not produced the conference yet.
     *
     * `url` is empty rather than null-or-placeholder: a class in this state is
     * real and scheduled, and the honest presentation is "scheduled, join link
     * not ready yet" — not a broken card and not a missing row.
     */
    public static function googlePending(string $eventId, ?string $htmlLink = null): self
    {
        return new self('', $eventId, GoogleConferenceStatus::PENDING, $htmlLink);
    }

    public static function googleReady(string $eventId, string $url, ?string $htmlLink = null): self
    {
        return new self($url, $eventId, GoogleConferenceStatus::READY, $htmlLink);
    }

    /**
     * Is there a link a student can actually be given?
     */
    public function hasJoinableUrl(): bool
    {
        return trim($this->url) !== '';
    }

    public function isGooglePending(): bool
    {
        return $this->conferenceStatus === GoogleConferenceStatus::PENDING;
    }
}