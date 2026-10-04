<?php

namespace App\Support\LiveClasses;

use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\TenantConfiguration;

/**
 * Everything the lecturer-facing "Schedule Live Class" screen needs to know
 * about the institution it is being rendered for.
 *
 * This exists so the HEI (Course Offering) form and the K12 form can share one
 * view without either of them hardcoding institution assumptions. It reads
 * configuration that already exists - it creates no new structure and no new
 * authority:
 *
 *  - Higher-ed vs K12 comes from TenantConfiguration::institution_type, which
 *    is a first-class tenant setting (k12 / higher_ed / mixed).
 *  - The timezone comes from TenantConfiguration::timezone, i.e. the
 *    institution's own configured value, falling back to the application
 *    default exactly as the rest of the platform does. It is never hardcoded to
 *    any particular city: a tenant that sets its timezone gets its own.
 *  - Manageable Course Offerings come from LecturerCourseOfferingAccess, the
 *    same authority the "My Course Offerings" list uses, so the selector can
 *    only ever offer what the lecturer may actually manage - including the
 *    governed pre-start tester case and excluding a normal lecturer whose
 *    allocation has not begun.
 *  - Platforms are limited to what the provider architecture genuinely
 *    supports, and each one declares whether its meeting link is created
 *    automatically or must be supplied by hand.
 */
class LiveClassSchedulingContext
{
    /**
     * True when the lecturer's institution schedules Live Classes through a
     * Course Offering. 'mixed' counts as higher-ed: an institution that also
     * teaches K12 must still let a lecturer pick an Offering, so Offering stays
     * the primary path and the legacy Class/Section form remains reachable for
     * genuine K12 classes rather than disappearing.
     */
    /**
     * The institution's clock - the OFFICIAL academic reference. Unchanged by
     * any personal preference.
     */
    public function timezone(User $user): string
    {
        return app(\App\Support\TenantTimezone::class)->resolve($user);
    }

    /**
     * The clock this particular person reads and types in: their own if they
     * chose one, otherwise their institution's.
     *
     * This is what a scheduling form must interpret the lecturer's typed time
     * against. "08:00" typed by a lecturer in London means 08:00 London, and
     * becomes 10:00 Kampala - one instant, not two schedules.
     */
    public function effectiveTimezone(User $user): string
    {
        return app(\App\Support\TenantTimezone::class)->effective($user);
    }

    /** Both clocks plus whether they differ, for the scheduling form. */
    public function timezoneDescription(User $user): array
    {
        return app(\App\Support\TenantTimezone::class)->describe($user);
    }

    public function isHigherEducation(User $user): bool
    {
        $type = strtolower((string) ($this->tenant($user)['institution_type'] ?? ''));

        return in_array($type, ['higher_ed', 'mixed'], true);
    }

    /**
     * Whether the institution has actually set its own timezone.
     *
     * This must read the tenant's OWN column, not the resolved value:
     * TenantConfiguration::resolve() substitutes the application default when
     * the column is empty, so the resolved value is never blank and asking it
     * would always answer "yes". The form uses this to tell the lecturer the
     * truth about which clock they are looking at.
     */
    public function hasConfiguredTimezone(User $user): bool
    {
        return app(\App\Support\TenantTimezone::class)->isConfigured($user);
    }

    /** "Kampala (UTC+3) - Africa", for the scheduling form. */
    public function timezoneLabel(User $user): string
    {
        return app(\App\Support\TenantTimezone::class)->humanizeFor($user);
    }

    /**
     * The Course Offerings this lecturer may schedule into, with the academic
     * context the chooser displays.
     */
    public function manageableOfferings(User $user)
    {
        return app(LecturerCourseOfferingAccess::class)->offerings($user);
    }

    /**
     * The same academic context, for one Offering.
     *
     * The context a scheduling form shows (programme, stage, study plan,
     * lecturer role, confirmed student count) is already derived by
     * LecturerCourseOfferingAccess for the "My Course Offerings" workspace.
     * Routing through the same method means the schedule form and the workspace
     * can never disagree about what an Offering is, and the view never has to
     * build the academic context itself.
     *
     * Returns the original model untouched when the caller is not a lecturer of
     * that Offering (a tenant admin, for example), so the admin form still
     * renders.
     */
    public function offeringContext(User $user, \App\Models\CourseOffering $offering): \App\Models\CourseOffering
    {
        $decorated = $this->manageableOfferings($user)->firstWhere('id', $offering->id);

        return $decorated instanceof \App\Models\CourseOffering ? $decorated : $offering;
    }

    /** Human one-liner of the lecturer's own role on this Offering. */
    public function roleLabel(\App\Models\CourseOffering $offering): ?string
    {
        $label = $offering->getAttribute('my_role_label');

        return $label ? (string) $label : null;
    }

    /** Programme names derived for this Offering, if any. */
    public function programmes(\App\Models\CourseOffering $offering): array
    {
        return collect($offering->getAttribute('my_programmes') ?? [])
            ->map(fn ($programme) => is_object($programme) ? ($programme->name ?? null) : $programme)
            ->filter()
            ->values()
            ->all();
    }

    /** Stage / year-of-study labels derived for this Offering, if any. */
    public function stages(\App\Models\CourseOffering $offering): array
    {
        return collect($offering->getAttribute('my_stages') ?? [])
            ->map(fn ($stage) => is_object($stage) ? ($stage->label ?? $stage->name ?? null) : $stage)
            ->filter()
            ->values()
            ->all();
    }

    /** Study Plan versions derived for this Offering, if any. */
    public function studyPlans(\App\Models\CourseOffering $offering): array
    {
        return collect($offering->getAttribute('my_study_plans') ?? [])
            ->map(fn ($plan) => is_object($plan) ? ($plan->version ?? null) : $plan)
            ->filter()
            ->values()
            ->all();
    }

    /** Confirmed-registration count, preferring the derived value. */
    public function confirmedCount(\App\Models\CourseOffering $offering): int
    {
        $derived = $offering->getAttribute('my_confirmed_students');

        return $derived !== null ? (int) $derived : 0;
    }

    /**
     * Meeting platforms, keyed by the stored value, each with a human label and
     * whether its link is created for the lecturer.
     *
     * Only platforms the existing architecture supports appear. Microsoft Teams
     * is deliberately absent: there is no Teams provider anywhere in this
     * application, and offering it would be a dead end.
     *
     * @return array<string, array{label: string, auto_creates: bool, note: string}>
     */
    public function platformOptions(User $user): array
    {
        $enabled = $this->enabledPlatforms();
        $configured = $this->platformConfigurationStatus();

        $candidates = [
            'google_meet' => [
                'label' => 'Google Meet',
                'configured' => $configured['google_meet'] ?? false,
            ],
            'zoom' => [
                'label' => 'Zoom',
                'configured' => $configured['zoom'] ?? false,
            ],
            'jitsi' => [
                'label' => 'Jitsi',
                'configured' => true,
            ],
            'bigbluebutton' => [
                'label' => 'BigBlueButton',
                'configured' => true,
            ],
            'custom' => [
                'label' => 'Other / External Link',
                'configured' => true,
            ],
        ];

        $options = [];
        foreach ($candidates as $key => $candidate) {
            if (! in_array($key, $enabled, true)) {
                continue;
            }

            // Google Meet and Zoom are only usable when this institution's
            // API credentials exist. Showing an unconfigured provider would
            // offer a choice that is guaranteed to fail on save.
            if (! $candidate['configured']) {
                continue;
            }

            // resolveMeetingUrl() creates the link for jitsi, google_meet and
            // zoom. BigBlueButton and an external link must be supplied.
            $autoCreates = in_array($key, ['jitsi', 'google_meet', 'zoom'], true);

            $options[$key] = [
                'label' => $candidate['label'],
                'auto_creates' => $autoCreates,
                'note' => $autoCreates
                    ? 'The meeting link is created automatically when you save.'
                    : 'You must paste the meeting link for this provider.',
            ];
        }

        // Never hand the lecturer an empty list; Jitsi needs no credentials.
        if ($options === []) {
            $options['jitsi'] = [
                'label' => 'Jitsi',
                'auto_creates' => true,
                'note' => 'The meeting link is created automatically when you save.',
            ];
        }

        return $options;
    }

    public function defaultPlatform(User $user): string
    {
        $options = $this->platformOptions($user);

        return array_key_first($options) ?: 'jitsi';
    }

    /** True when the platform needs a link typed in by hand. */
    public function requiresManualLink(User $user, string $platform): bool
    {
        $options = $this->platformOptions($user);

        return (bool) ($options[$platform]['auto_creates'] ?? true) === false;
    }

    /** The platform values actually persisted by this application. */
    public function supportedPlatforms(): array
    {
        return ['jitsi', 'google_meet', 'zoom', 'bigbluebutton', 'custom'];
    }

    /** The lifecycle a lecturer is allowed to request, expressed as actions. */
    public function publishingActions(): array
    {
        return [
            [
                'value' => 'draft',
                'label' => 'Save as Draft',
                'help' => 'Only you can see this class. Registered students are not notified.',
            ],
            [
                'value' => 'publish',
                'label' => 'Schedule & Notify Students',
                'help' => 'Makes the class available and notifies the students registered for this Course Offering.',
            ],
        ];
    }

    private function tenant(User $user): array
    {
        return app(TenantConfiguration::class)->resolve(null, $user);
    }

    /**
     * Mirrors LiveClassController::getEnabledPlatforms(). Kept as a local
     * copy of the same settings keys so this support class has no dependency on
     * a controller; the values are read from the identical settings.
     */
    private function enabledPlatforms(): array
    {
        $map = [
            'jitsi' => get_settings('live_class_platform_jitsi') !== '0',
            'google_meet' => get_settings('live_class_platform_google_meet') !== '0',
            'zoom' => get_settings('live_class_platform_zoom') !== '0',
            'bigbluebutton' => get_settings('live_class_platform_bigbluebutton') === '1',
            'custom' => get_settings('live_class_platform_custom') === '1',
        ];

        $enabled = [];
        foreach ($map as $platform => $isEnabled) {
            if ($isEnabled) {
                $enabled[] = $platform;
            }
        }

        return $enabled ?: ['jitsi', 'google_meet', 'zoom'];
    }

    private function platformConfigurationStatus(): array
    {
        return [
            'jitsi' => true,
            'google_meet' => (string) config('services.google_meet.client_id') !== ''
                && (string) config('services.google_meet.client_secret') !== ''
                && (string) config('services.google_meet.refresh_token') !== '',
            'zoom' => (string) config('services.zoom.account_id') !== ''
                && (string) config('services.zoom.client_id') !== ''
                && (string) config('services.zoom.client_secret') !== '',
            'bigbluebutton' => true,
            'custom' => true,
        ];
    }

    /** The status a freshly saved Offering-backed class gets, from the action. */
    public function statusForAction(string $action): string
    {
        return $action === 'publish' ? LiveClass::STATUS_SCHEDULED : LiveClass::STATUS_DRAFT;
    }

    public function isPublishAction(?string $action): bool
    {
        return $action === 'publish';
    }
}
