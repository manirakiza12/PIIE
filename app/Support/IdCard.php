<?php

namespace App\Support;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Shared metadata every ID card (student, and — once this pattern is
 * extended — staff) needs beyond the raw student_details array: a
 * presentable card number, the academic session it's valid for, and a
 * scannable QR code pointing at a signed, unauthenticated verification
 * page. Centralized so the admin/parent/student card views (three
 * previously near-duplicate Blade files) compute these identically instead
 * of drifting.
 */
class IdCard
{
    public const INSTITUTION_PHONE = '+256772973574';

    public static function cardNumber(User $student): string
    {
        return (string) $student->code;
    }

    /**
     * The running session's title (e.g. "2026/2027"), or null if the
     * school has no running session configured yet.
     */
    public static function validFor(int $schoolId): ?string
    {
        $runningSessionId = get_school_settings($schoolId)->value('running_session');

        return $runningSessionId ? Session::where('id', $runningSessionId)->value('session_title') : null;
    }

    /**
     * A signed URL good for 2 years — long enough to cover a normal
     * academic-year card without needing to be regenerated each session,
     * short enough that a card reported lost/replaced eventually stops
     * verifying on its own instead of staying valid forever.
     */
    public static function verifyUrl(User $student): string
    {
        return URL::temporarySignedRoute('id_card.verify', now()->addYears(2), ['student' => $student->id]);
    }

    /**
     * A data: URI SVG, safe to embed directly in both a normal Blade view
     * and a dompdf-rendered PDF (dompdf cannot reliably fetch external
     * image URLs, but renders inline base64 data just fine). SVG rather
     * than PNG deliberately: this package's PNG backend hard-requires the
     * Imagick extension (no GD fallback), which this environment doesn't
     * have installed; SVG rendering is pure PHP and has no such dependency.
     */
    public static function qrDataUri(User $student): string
    {
        $svg = QrCode::format('svg')->size(240)->margin(1)->generate(self::verifyUrl($student));

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
