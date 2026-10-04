<?php

namespace App\Http\Controllers;

use App\Support\TenantTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Profile → Regional Settings: the one place a person chooses their own
 * timezone.
 *
 * WHY ONE SHARED ENDPOINT FOR EVERY ROLE
 *
 * Lecturers, students, parents and staff all need the same preference, and
 * PIIE has a separate profile controller for each portal. Putting the logic in
 * the smallest possible shared place - two routes and this class - means the
 * validation, the wording and the fallback behaviour cannot drift apart between
 * portals, and a rule like "NULL means follow your institution" is written down
 * exactly once.
 *
 * This changes a person's own display and input clock and NOTHING else. It
 * cannot alter the institution's official timezone, it cannot reach another
 * user's record, and it cannot reinterpret any stored instant.
 */
class RegionalPreferenceController extends Controller
{
    public function edit(Request $request)
    {
        $tz = app(TenantTimezone::class);
        $user = $request->user();

        return view('profile.regional_settings', [
            'user' => $user,
            'timezoneOptions' => $tz->groupedOptions(),
            'description' => $tz->describe($user),
            'institutionTimezone' => $tz->resolve($user),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            // Empty means "follow my institution", which is the default and is
            // stored as NULL rather than as a copy of the institution value:
            // a copy would freeze a preference that should follow the
            // institution if the institution later moves.
            'timezone' => ['nullable', 'timezone'],
        ], [
            'timezone.timezone' => get_phrase('That is not a recognised timezone.'),
        ]);

        $user->timezone = $validated['timezone'] ?? null;
        $user->save();

        return redirect()
            ->route('profile.regional.edit')
            ->with('message', get_phrase('Your timezone preference has been saved.'));
    }
}
