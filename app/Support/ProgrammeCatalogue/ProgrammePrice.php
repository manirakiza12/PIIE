<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;

/**
 * How to READ a programme's tuition figure, so the public catalogue never has to
 * guess and never implies a price it was not given.
 *
 * ── WHY A BASIS IS NEEDED AT ALL ───────────────────────────────────────────
 *
 * `programmes.tuition_fee` is a bare DECIMAL. On its own that number is not a
 * price a reader can act on: UGX 4,500,000 is a plausible whole-programme
 * tuition, a plausible annual fee, and a plausible per-term fee. The platform
 * carries two other amounts with different meanings again — the application fee
 * is per INTAKE, and an invoiced balance is per STUDENT — so the reader cannot
 * infer the basis from context either.
 *
 * Guessing is not an option here. Showing "per programme" against a figure that
 * is actually per year is worse than showing nothing: it is a specific,
 * checkable, wrong claim about what a candidate will pay, made by the
 * institution, on the page the candidate reads before applying.
 *
 * ── WHY null IS A FIRST-CLASS STATE ────────────────────────────────────────
 *
 * There is no 'unknown' basis and no zero basis. `null` means the administrator
 * has not stated it, and it renders as "Contact us" — never as 0, never as a
 * bare number with no unit. A missing price must never read as a price of zero,
 * because zero is a claim that the programme is free.
 */
final class ProgrammePrice
{
    /**
     * Display labels. Kept as a plain map rather than an enum because this is
     * presentation vocabulary an administrator may need to extend, and a new enum
     * case would be a code change plus a migration.
     */
    public const BASIS_LABELS = [
        'per_programme'   => 'for the whole programme',
        'per_year'        => 'per academic year',
        'per_semester'    => 'per semester',
        'per_term'        => 'per term',
        'per_month'       => 'per month',
        'contact'         => null,   // deliberately renders no amount at all
    ];

    /** Bases an administrator may choose. `contact` is a real, honest answer. */
    public const SELECTABLE_BASES = [
        'per_programme', 'per_year', 'per_semester', 'per_term', 'per_month', 'contact',
    ];

    /**
     * The tenant currency, used when a programme carries no override.
     *
     * Read through the schools table rather than assumed to be UGX. The platform
     * already stores a currency per school (`schools.school_currency`) and one
     * for the payment gateway (`currency` table); assuming Ugandan Shilling
     * because the institution is in Uganda would make every price a lie the
     * moment a second tenant or a different settlement currency is configured.
     */
    public static function tenantCurrency(?Programme $programme = null): ?string
    {
        // A programme knows its own school, which is the only reliable tenant
        // identity here: the public catalogue renders for an anonymous visitor
        // whose resolved tenant is a request-time guess, while the programme row
        // carries the school_id the fee actually belongs to.
        $schoolId = $programme?->school_id;

        if ($schoolId === null) {
            $schoolId = \App\Support\PublicTenantResolver::resolveSchoolId();
        }

        if (! $schoolId) {
            return null;
        }

        $code = \Illuminate\Support\Facades\DB::table('schools')
            ->where('id', $schoolId)
            ->value('school_currency');

        $code = is_string($code) ? trim($code) : '';

        return $code === '' ? null : $code;
    }

    /**
     * Whether this programme has a price the public catalogue may state.
     *
     * A price requires BOTH an amount and a basis. An amount with no basis is
     * withheld rather than shown bare, because a bare number in a catalogue card
     * reads as the price regardless of what it is missing.
     */
    public static function isPriced(?Programme $programme): bool
    {
        if ($programme === null) {
            return false;
        }

        $basis = self::basis($programme);

        if ($basis === 'contact' || $basis === null) {
            return false;
        }

        // A stored 0 is a real amount, but presenting "0" as a tuition figure
        // states that the programme is free. That is a business fact, not a
        // formatting choice, so it is withheld and the administrator is told to
        // use the "Contact us" basis if it genuinely is free.
        return self::amount($programme) !== null && self::amount($programme) > 0;
    }

    /** The stored amount, or null when absent/unusable. Never coerced to 0. */
    public static function amount(?Programme $programme): ?float
    {
        if ($programme === null || $programme->tuition_fee === null || $programme->tuition_fee === '') {
            return null;
        }

        if (! is_numeric($programme->tuition_fee)) {
            return null;
        }

        return (float) $programme->tuition_fee;
    }

    /** The normalised basis, or null when unstated. */
    public static function basis(?Programme $programme): ?string
    {
        if ($programme === null) {
            return null;
        }

        $basis = is_string($programme->tuition_fee_basis) ? trim($programme->tuition_fee_basis) : '';

        return $basis === '' ? null : $basis;
    }

    /** Effective currency: the programme's override, else the tenant's. */
    public static function currency(?Programme $programme): ?string
    {
        $override = $programme->tuition_currency ?? null;
        $override = is_string($override) ? trim($override) : '';

        if ($override !== '') {
            return $override;
        }

        return self::tenantCurrency($programme);
    }

    /**
     * The full price line for a catalogue card, or null when there is nothing
     * honest to print.
     *
     * Returns a structure rather than a preformatted string so the view decides
     * the typography and can style the amount and the basis differently, which
     * is what makes a price read as a price.
     *
     * @return array{amount:string,currency:?string,basis:string}|null
     */
    public static function forDisplay(?Programme $programme): ?array
    {
        if (! self::isPriced($programme)) {
            return null;
        }

        return [
            'amount'   => number_format((float) $programme->tuition_fee, 0),
            'currency' => self::currency($programme),
            'basis'    => self::BASIS_LABELS[self::basis($programme)] ?? self::basis($programme),
        ];
    }

    /**
     * The line to print when there is no price. Deliberately not a zero and not
     * an empty string: an applicant needs to be told the figure is available on
     * request rather than left to wonder whether it is free.
     */
    public static function contactLabel(): string
    {
        return 'Contact us for tuition fees';
    }

    /**
     * What the ADMIN screen shows, which is deliberately more than the public
     * one: an administrator needs to see that a figure exists but is unusable, so
     * the missing-basis problem is visible where it can be fixed.
     */
    public static function adminSummary(?Programme $programme): string
    {
        $amount = self::amount($programme);
        $basis  = self::basis($programme);

        if ($amount === null) {
            return 'No amount set';
        }

        if ($basis === null) {
            return number_format($amount, 0).' — basis not stated (hidden from the website)';
        }

        if ($basis === 'contact') {
            return 'Contact us (no amount published)';
        }

        if ($amount <= 0) {
            return number_format($amount, 0).' — withheld; use the "Contact us" basis if the programme is genuinely free';
        }

        return number_format($amount, 0).' '.self::basis($programme);
    }
}