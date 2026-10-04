<?php

namespace App\Support\LiveClasses;

/**
 * The conference lifecycle, as reported by Google.
 *
 * Kept separate from `GoogleCalendarService` so the Live Classes module can
 * describe the state without depending on a Google-specific service class, and
 * so a screen never has to compare a raw provider string.
 *
 * `PENDING` is the state that matters. Google creates Meet conferences
 * asynchronously, so an event can be created and returned with no conference data
 * yet. It is a real, normal, transient state — not an error and not success.
 */
final class GoogleConferenceStatus
{
    /** Event created; Google has not issued the Meet link yet. */
    public const PENDING = 'pending';

    /** A join link exists. */
    public const READY = 'ready';

    /** Google accepted the event but the conference could not be created. */
    public const FAILED = 'failed';

    /**
     * Plain-language description for a lecturer or student looking at a card.
     *
     * "Not ready yet" rather than "Pending" on purpose: a student shown "Pending"
     * concludes something is broken. It is not — the link will appear.
     *
     * @return array{label:string, explanation:string, joinable:bool}|null
     */
    public static function describe(?string $status): ?array
    {
        return match ($status) {
            self::READY => [
                'label' => 'Ready to join',
                'explanation' => 'The Google Meet link is available.',
                'joinable' => true,
            ],
            self::PENDING => [
                'label' => 'Link not ready yet',
                'explanation' => 'This class is scheduled and the join link will appear here as soon as Google issues it. Nothing is wrong and nothing needs re-saving.',
                'joinable' => false,
            ],
            self::FAILED => [
                'label' => 'Link could not be created',
                'explanation' => 'Google did not create a Meet conference for this class. Cancel it and schedule it again, or ask your administrator to check the Google connection.',
                'joinable' => false,
            ],
            default => null,
        };
    }
}