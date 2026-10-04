<?php

namespace App\Support\LiveClasses;

use App\Models\LiveClass;

/**
 * What a meeting provider can ACTUALLY do, stated honestly.
 *
 * WHY THIS EXISTS
 *
 * The module grew a single "meeting URL" and a "Host" button, which quietly
 * claimed two things that are not true of every provider:
 *
 *   1. That PIIE can hand somebody the HOST link, and
 *   2. That because PIIE authorised them, they are a moderator in the
 *      provider's conference.
 *
 * For a public Jitsi room both claims are false. Anyone with the link may
 * join, and a Jitsi moderator is whoever was made one inside Jitsi itself - by
 * being the room creator, by Jitsi recognising them, or by an authenticated
 * Jitsi identity. PIIE knowing who a lecturer is grants PIIE nothing in the
 * Jitsi room. Telling a lecturer "you are the host" would be a claim the
 * product cannot keep, and a student who then cannot mute the room would have
 * been misled about who is responsible for what.
 *
 * So capability is described per platform, and the interface says only what the
 * provider supports. Where a provider issues a distinct host link, that link is
 * used and labelled. Where it does not, PIIE says plainly that moderation is
 * arranged inside the provider and does not pretend to have arranged it.
 *
 * Nothing here ever yields a password. `meeting_password` is deliberately
 * unreachable from this class: a moderator secret that appears in a page, a
 * notification, a log line or a test fixture is a disclosed secret, and PIIE has
 * no use for it here.
 */
class LiveClassPlatform
{
    /**
     * Per-platform facts. Read once, in one place, so the lecturer panel, the
     * student panel and the tests can never describe the same provider
     * differently.
     *
     * - separate_host_url: the provider issues a distinct moderator/host link
     * - moderation_by_provider: becoming a moderator needs action INSIDE the
     *   provider, which PIIE cannot perform
     * - limitations: the honest caveat shown to whoever is about to join
     *
     * @var array<string, array{label: string, separate_host_url: bool, moderation_by_provider: bool, auto_creates: bool, limitations: list<string>}>
     */
    private const PLATFORMS = [
        'jitsi' => [
            'label' => 'Jitsi Meet',
            'separate_host_url' => false,
            'moderation_by_provider' => true,
            'auto_creates' => true,
            'limitations' => [
                'This is a public Jitsi room: anyone holding the link may join it.',
                'Becoming a Jitsi moderator is arranged inside Jitsi itself, not by PIIE. Authorising a lecturer in PIIE does not make them a Jitsi moderator.',
                'PIIE has not recorded a moderator password for this room and does not display one.',
            ],
        ],
        'google_meet' => [
            'label' => 'Google Meet',
            'separate_host_url' => false,
            'moderation_by_provider' => true,
            'auto_creates' => true,
            'limitations' => [
                'Moderating a Google Meet call is done from inside the meeting, by the meeting owner.',
                'Authorising a lecturer in PIIE does not make them the Google Meet host.',
                'Meet links are issued by Google; a link pasted here is used as given.',
            ],
        ],
        'zoom' => [
            'label' => 'Zoom',
            'separate_host_url' => true,
            'moderation_by_provider' => false,
            'auto_creates' => false,
            'limitations' => [
                'Zoom issues a separate Start URL for the host and a Join URL for participants. The host link is shown only to an authorised lecturer or administrator.',
            ],
        ],
        'bigbluebutton' => [
            'label' => 'BigBlueButton',
            'separate_host_url' => true,
            'moderation_by_provider' => false,
            'auto_creates' => false,
            'limitations' => [
                'BigBlueButton issues a distinct moderator link. It is shown only to an authorised lecturer or administrator.',
            ],
        ],
        'custom' => [
            'label' => 'External meeting',
            'separate_host_url' => false,
            'moderation_by_provider' => true,
            'auto_creates' => false,
            'limitations' => [
                'PIIE holds a link to a meeting it does not control. Joining, and any moderation, happen entirely on the provider\'s own site.',
            ],
        ],
    ];

    /**
     * Everything a screen needs about one class's provider, for one audience.
     *
     * @return array{
     *   platform: string, label: string, known: bool,
     *   has_participant_url: bool, has_host_url: bool,
     *   separate_host_url: bool, moderation_by_provider: bool,
     *   limitations: list<string>, note: string
     * }
     */
    public function describe(LiveClass $class, bool $viewerMayHost): array
    {
        $key = (string) $class->platform;
        $known = isset(self::PLATFORMS[$key]);
        $facts = self::PLATFORMS[$key] ?? self::PLATFORMS['custom'];

        $participantUrl = $class->safe_meeting_url;
        $hasHost = $known && $facts['separate_host_url'] && $participantUrl !== null;

        return [
            'platform' => $key,
            'label' => $facts['label'],
            'known' => $known,
            'has_participant_url' => $participantUrl !== null,
            // A host link is only ever REPORTED as present. Resolving one is the
            // provider's job at meeting-creation time; PIIE stores the single
            // link it was given and must not invent a second.
            'has_host_url' => $hasHost,
            'separate_host_url' => $facts['separate_host_url'],
            'moderation_by_provider' => $facts['moderation_by_provider'],
            'limitations' => $facts['limitations'],
            'note' => $this->note($facts, $hasHost, $viewerMayHost),
        ];
    }

    /**
     * One sentence the interface can show without over-claiming. It names the
     * limitation when there is one, because the absence of a caveat is what
     * made the original wording misleading in the first place.
     *
     * @param array<string, mixed> $facts
     */
    private function note(array $facts, bool $hasHost, bool $viewerMayHost): string
    {
        if ($hasHost) {
            return $viewerMayHost
                ? get_phrase('As host you use the moderator link. Participants use the join link, and it is never shown to them.')
                : get_phrase('The moderator link is held by the lecturer or an administrator and is not shown here.');
        }

        if ($facts['moderation_by_provider']) {
            return get_phrase('This provider issues a single link and decides moderation itself. PIIE authorises who may open it, but it does not make anybody a moderator of the meeting.');
        }

        return get_phrase('Participants join using the link below.');
    }

    /** @return list<string> */
    public function platformOptions(): array
    {
        return array_map(fn (array $facts): string => $facts['label'], self::PLATFORMS);
    }

    public static function isKnown(string $platform): bool
    {
        return isset(self::PLATFORMS[$platform]);
    }
}
