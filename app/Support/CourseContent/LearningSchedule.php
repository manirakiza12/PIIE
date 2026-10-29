<?php

namespace App\Support\CourseContent;

use App\Models\User;
use App\Support\TenantTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Interpret local form inputs in the institution clock; preserve stored instants. */
class LearningSchedule
{
    public function parse($value, User $actor): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $moment = $value instanceof CarbonInterface
            ? Carbon::instance($value)->copy()
            : Carbon::parse((string) $value, app(TenantTimezone::class)->resolve($actor));

        return $moment->setTimezone(config('app.timezone', 'UTC'));
    }

    public function local(?CarbonInterface $value, User $actor, string $format = 'Y-m-d\TH:i'): ?string
    {
        return $value?->copy()->setTimezone(app(TenantTimezone::class)->resolve($actor))->format($format);
    }
}
