@php
    // The wording comes from LiveClass::displayStatusLabel(), so the badge, the
    // lecturer detail page, the index and the student list can never show four
    // different names for one class. It maps the STORED value "ended" to the word
    // "Completed" for people; the stored value, every filter, dedup key and audit
    // record keep saying "ended" and mean exactly what they did before.
    $state = $liveClass->computed_status;
    $label = $state === \App\Models\LiveClass::STATUS_SCHEDULED && !empty($startingSoon)
        ? get_phrase('Starting Soon')
        : $liveClass->displayStatusLabel();
    $badgeClass = match ($state) {
        \App\Models\LiveClass::STATUS_DRAFT => 'bg-secondary',
        \App\Models\LiveClass::STATUS_SCHEDULED => 'bg-primary',
        \App\Models\LiveClass::STATUS_LIVE => 'bg-success',
        \App\Models\LiveClass::STATUS_ENDED => 'bg-light text-dark',
        // Never a success colour: nothing about this class was confirmed.
        \App\Models\LiveClass::STATUS_NOT_CONCLUDED => 'bg-secondary',
        \App\Models\LiveClass::STATUS_CANCELLED => 'bg-danger',
        default => 'bg-secondary',
    };
@endphp
<span class="badge {{ $badgeClass }}">{{ $label }}</span>
