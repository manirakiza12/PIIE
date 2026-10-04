<?php

namespace App\Support\Google;

use App\Models\GoogleAccountConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creating and updating Google Calendar events that carry a Google Meet conference.
 *
 * ── WHY `conferenceDataVersion=1` IS NON-NEGOTIABLE ─────────────────────────
 *
 * Without it Google silently ignores the `conferenceData` block and returns a
 * perfectly ordinary event with no conference. No error, no warning — just a
 * class with no meeting. It is the single easiest way to ship a "Google Meet
 * integration" that does nothing, which is very likely why the earlier
 * `createGoogleMeetUrl()` in this codebase looked functional while producing
 * nothing in practice.
 *
 * ── WHY requestId IS STORED ─────────────────────────────────────────────────
 *
 * Google deduplicates conference creation on `conferenceData.createRequest.id`
 * for 24 hours. That is a feature here, not a quirk: retrying a class that failed
 * *after* Google accepted it reuses the id and returns the original conference
 * instead of leaving a second orphan conference on the lecturer's calendar. It
 * also means a double-submitted form cannot produce two Meet links for one class.
 *
 * Generated per scheduling attempt from a UUID, not per render.
 */
class GoogleCalendarService
{
    /**
     * The conference lifecycle as Google reports it in
     * `conferenceData.conferenceSolution` and `entryPoints`.
     */
    public const CONFERENCE_PENDING = 'pending';

    public const CONFERENCE_READY = 'ready';

    public const CONFERENCE_FAILED = 'failed';

    /** Every class in PIIE is scheduled in Kampala time unless a tenant says otherwise. */
    public const DEFAULT_TIMEZONE = 'Africa/Kampala';

    public function __construct(private readonly GoogleOAuthClient $oauth) {}

    /**
     * Create an event with a Meet conference.
     *
     * @param  array{title:string, description?:?string, starts_at:Carbon, ends_at:Carbon, timezone?:?string}  $class
     * @return array{event_id:string, meeting_url:string, conference_status:string, request_id:string, html_link:?string}
     *
     * @throws RuntimeException when Google refuses, or accepts the event but
     *                          returns no conference and no join link
     */
    public function createMeetingEvent(string $accessToken, string $calendarId, array $class): array
    {
        $timezone = $class['timezone'] ?: self::DEFAULT_TIMEZONE;
        $requestId = (string) Str::uuid();

        $payload = [
            'summary' => $class['title'],
            'description' => $this->description($class),
            'start' => [
                'dateTime' => $this->isoIn($class['starts_at'], $timezone),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $this->isoIn($class['ends_at'], $timezone),
                'timeZone' => $timezone,
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => $requestId,
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
            // Guests are added by the caller, which knows the school's guest list.
            'attendees' => $class['attendees'] ?? [],
        ];

        /**
         * The query is part of the URL rather than an option on the request
         * builder. `withOptions()` is a PendingRequest method; calling it on the
         * Response after `post()` is a fatal, and `conferenceDataVersion` is the
         * one parameter this integration cannot work without — so it belongs in
         * the URL where it is visible on the call and impossible to drop.
         */
        $url = $this->oauth->calendarBase()
            .'/calendars/'.rawurlencode($calendarId).'/events'
            .'?'.http_build_query(['conferenceDataVersion' => 1, 'sendUpdates' => 'all']);

        $response = $this->oauth->http()
            ->withToken($accessToken)
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->refusal($response));
        }

        $eventId = trim((string) $response->json('id'));
        $url = $this->joinUrl($response);

        if ($eventId === '') {
            throw new RuntimeException('Google created a calendar entry but returned no event id.');
        }

        /**
         * A conference that has not materialised yet is NOT a failure.
         *
         * Google creates Meet conferences asynchronously. On a busy calendar the
         * event can come back created with an empty `conferenceData`, and the
         * link appears moments later. Reporting that as an error would delete a
         * class that is going to work; inventing a URL would be a lie a student
         * would click.
         *
         * So this is reported as `pending` with no URL, stored on the class, and
         * surfaced as "scheduled — join link not ready yet". Phase 5 reconciles
         * it; until then it stays honestly pending.
         */
        if ($url === null) {
            return [
                'event_id' => $eventId,
                'meeting_url' => '',
                'conference_status' => self::CONFERENCE_PENDING,
                'request_id' => $requestId,
                'html_link' => $response->json('htmlLink'),
            ];
        }

        return [
            'event_id' => $eventId,
            'meeting_url' => $url,
            'conference_status' => self::CONFERENCE_READY,
            'request_id' => $requestId,
            'html_link' => $response->json('htmlLink'),
        ];
    }

    /**
     * Re-read an event to pick up a conference that was still pending.
     *
     * @return array{event_id:string, meeting_url:string, conference_status:string, request_id:?string, html_link:?string}
     */
    public function refreshEvent(string $accessToken, string $calendarId, string $eventId, ?string $requestId = null): array
    {
        $response = $this->oauth->http()
            ->withToken($accessToken)
            ->get($this->oauth->calendarBase().'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));

        if (! $response->successful()) {
            throw new RuntimeException($this->refusal($response));
        }

        $url = $this->joinUrl($response);

        return [
            'event_id' => (string) $response->json('id', $eventId),
            'meeting_url' => $url ?? '',
            'conference_status' => $url === null ? self::CONFERENCE_PENDING : self::CONFERENCE_READY,
            'request_id' => $requestId,
            'html_link' => $response->json('htmlLink'),
        ];
    }

    /**
     * Change an existing event's time or title.
     *
     * A PATCH, not a PUT: Google requires the whole resource for PUT and would
     * discard the conference and attendees, which would silently destroy the
     * join link students already hold.
     */
    public function updateEvent(string $accessToken, string $calendarId, string $eventId, array $changes): void
    {
        $patch = ['updateMask' => 'summary,description,start,end'];

        if (array_key_exists('title', $changes)) {
            $patch['summary'] = (string) $changes['title'];
        }
        if (array_key_exists('description', $changes)) {
            $patch['description'] = (string) $changes['description'];
        }
        if (isset($changes['starts_at'], $changes['ends_at'])) {
            $timezone = $changes['timezone'] ?: self::DEFAULT_TIMEZONE;
            $patch['start'] = ['dateTime' => $this->isoIn($changes['starts_at'], $timezone), 'timeZone' => $timezone];
            $patch['end'] = ['dateTime' => $this->isoIn($changes['ends_at'], $timezone), 'timeZone' => $timezone];
        }

        if (count($patch) === 1) {
            return;
        }

        $response = $this->oauth->http()
            ->withToken($accessToken)
            ->patch(
                $this->oauth->calendarBase().'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId)
                    .'?'.http_build_query(['conferenceDataVersion' => 1]),
                $patch
            );

        if (! $response->successful()) {
            throw new RuntimeException($this->refusal($response));
        }
    }

    /**
     * Delete an event, and with it the Meet conference.
     *
     * A 404 or 410 is treated as success. Someone deleting the class by hand in
     * Google — entirely reasonable — must not leave PIIE permanently unable to
     * cancel it. Google having already done the deletion is the outcome we wanted.
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $response = $this->oauth->http()
            ->withToken($accessToken)
            ->delete($this->oauth->calendarBase().'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));

        if ($response->successful() || in_array($response->status(), [404, 410], true)) {
            return;
        }

        throw new RuntimeException($this->refusal($response));
    }

    /**
     * Google's own calendar id for a connection's account, or null.
     */
    public function calendarIdFor(GoogleAccountConnection $connection): string
    {
        return filled($connection->calendar_id) ? $connection->calendar_id : 'primary';
    }

    /**
     * The Meet join URL, preferring `hangoutLink`.
     *
     * `entryPoints` is the fallback: it carries the same link with additional
     * detail (phone, pin) on some responses. Returning the first http(s) entry
     * point keeps a class joinable even when `hangoutLink` is omitted.
     */
    private function joinUrl($response): ?string
    {
        $hangout = trim((string) $response->json('hangoutLink'));

        if ($hangout !== '') {
            return $hangout;
        }

        foreach ((array) $response->json('conferenceData.entryPoints', []) as $entryPoint) {
            $uri = trim((string) ($entryPoint['uri'] ?? ''));

            // Only https. A conference entry point is something a student will be
            // told to click, and accepting anything else would let a malformed or
            // hostile response put an arbitrary scheme in front of them.
            if ($uri !== '' && Str::startsWith($uri, ['https://'])) {
                return $uri;
            }
        }

        return null;
    }

    /**
     * The event body.
     *
     * Reads `description` from the class rather than inventing one, and caps it:
     * Calendar truncates long descriptions silently, and an unbounded string that
     * differs between PIIE and Google is a support problem later.
     */
    private function description(array $class): string
    {
        $description = trim((string) ($class['description'] ?? ''));

        return Str::limit($description, 2000, '');
    }

    private function isoIn(Carbon $moment, string $timezone): string
    {
        return $moment->copy()->setTimezone($timezone)->toIso8601String();
    }

    /**
     * Google's message, without the response body.
     *
     * The body of a Calendar error can echo parts of the event payload. Only the
     * documented `error.message` is surfaced, which is what an academic office
     * needs and is not a secret.
     */
    private function refusal($response): string
    {
        $error = $response->json('error');

        if (is_array($error)) {
            $message = trim((string) ($error['message'] ?? ''));
            $reason = trim((string) ($error['errors'][0]['reason'] ?? ''));

            if ($message !== '') {
                return 'Google Calendar refused the request'.($reason !== '' ? ' ('.$reason.')' : '').': '.$message;
            }
            if ($reason !== '') {
                return 'Google Calendar refused the request: '.$reason.'.';
            }
        }

        if (is_string($error) && trim($error) !== '') {
            return 'Google Calendar refused the request: '.trim($error).'.';
        }

        return 'Google Calendar refused the request (HTTP '.$response->status().').';
    }
}