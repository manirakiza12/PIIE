<?php

namespace App\Support\Payroll;

/**
 * Statutory payroll calculation engine for Ugandan payroll runs.
 *
 * IMPORTANT — rates live in configuration, not in this file. The defaults
 * below model the commonly-published URA monthly PAYE schedule and the
 * NSSF Act contributions (employee 5% of gross, employer 10% of gross,
 * chargeable income reduced by the employee's own NSSF contribution), but
 * the authoritative values are read from `payroll_settings` per school:
 *
 *   paye_bands               => [ ["up_to": 235000, "rate": 0.10], ... ]  (monthly thresholds, ascending, null = open top)
 *   nssf_employee_rate       => 0.05 (of gross)
 *   nssf_employer_rate       => 0.10 (of gross, employer cost — never deducted from the employee)
 *   paye_surcharge_threshold => 10000000 (monthly; null disables the surcharge)
 *   paye_surcharge_rate      => 0.10
 *
 * The institution's accountant is responsible for reviewing/updating these
 * settings when URA publishes new bands (effective dates are recorded in
 * the settings table). Nothing here invents a rate at calculation time:
 * if no settings row exists the documented defaults apply, and changing a
 * rate is one UPDATE away — no code change and no recalculation pipeline.
 *
 * Formula (documented contract of this module):
 *   gross       = basic + allowances + other earnings
 *   nssf        = employee statutory contribution (5% of gross by default)
 *   chargeable  = max(0, gross - nssf)        (employee NSSF is a PAYE-deductible statutory deduction)
 *   paye        = progressive bands on chargeable (+ surcharge above threshold)
 *   employer    = employer statutory contribution (10% of gross by default)
 *   net         = gross - nssf - paye         (net of EMPLOYEE deductions only)
 */
final class UgandaPayrollCalculator
{
    public const DEFAULT_PAYE_BANDS = [
        ['up_to' => 235000.0, 'rate' => 0.10],
        ['up_to' => 335000.0, 'rate' => 0.20],
        ['up_to' => 410000.0, 'rate' => 0.30],
        ['up_to' => null, 'rate' => 0.30],
    ];

    public const DEFAULT_NSSF_EMPLOYEE_RATE = 0.05;
    public const DEFAULT_NSSF_EMPLOYER_RATE = 0.10;
    public const DEFAULT_PAYE_SURCHARGE_THRESHOLD = 10000000.0;
    public const DEFAULT_PAYE_SURCHARGE_RATE = 0.10;

    /**
     * @param  array{basic: float, housing: float, transport: float, medical: float, other: float}  $earnings
     * @param  array{paye_bands?: array, nssf_employee_rate?: float, nssf_employer_rate?: float,
     *               paye_surcharge_threshold?: float|null, paye_surcharge_rate?: float}  $settings
     * @return array<string,float>
     */
    public static function compute(array $earnings, array $settings = []): array
    {
        $basic = (float) ($earnings['basic'] ?? 0);
        $allowances = max(0.0, (float) ($earnings['housing'] ?? 0))
            + max(0.0, (float) ($earnings['transport'] ?? 0))
            + max(0.0, (float) ($earnings['medical'] ?? 0));
        $other = max(0.0, (float) ($earnings['other'] ?? 0));

        $gross = round($basic + $allowances + $other, 2);

        $nssfEmployee = self::positiveRate($settings['nssf_employee_rate'] ?? null, self::DEFAULT_NSSF_EMPLOYEE_RATE) * $gross;
        $employerNssf = self::positiveRate($settings['nssf_employer_rate'] ?? null, self::DEFAULT_NSSF_EMPLOYER_RATE) * $gross;
        $nssfEmployee = round(min($nssfEmployee, $gross), 2);
        $employerNssf = round(max(0.0, $employerNssf), 2);

        $chargeable = round(max(0.0, $gross - $nssfEmployee), 2);
        $bands = self::normaliseBands($settings['paye_bands'] ?? null);
        $paye = self::progressiveTax($chargeable, $bands);

        $surchargeRate = (float) ($settings['paye_surcharge_rate'] ?? self::DEFAULT_PAYE_SURCHARGE_RATE);
        $surchargeThreshold = $settings['paye_surcharge_threshold'] ?? self::DEFAULT_PAYE_SURCHARGE_THRESHOLD;
        if ($surchargeThreshold !== null && $surchargeThreshold > 0 && $chargeable > (float) $surchargeThreshold) {
            $paye += ($chargeable - (float) $surchargeThreshold) * max(0.0, $surchargeRate);
        }

        $paye = round($paye, 2);
        $net = round($gross - $nssfEmployee - $paye, 2);

        return [
            'basic' => round($basic, 2),
            'allowances' => round($allowances, 2),
            'other_earnings' => round($other, 2),
            'gross' => $gross,
            'nssf_employee' => $nssfEmployee,
            'employer_nssf' => $employerNssf,
            'paye' => $paye,
            'net' => $net,
        ];
    }

    /** @param  array<int, array{up_to: float|null, rate: float}>|null  $bands */
    private static function progressiveTax(float $chargeable, ?array $bands): float
    {
        if ($chargeable <= 0) {
            return 0.0;
        }

        $tax = 0.0;
        $previous = 0.0;

        foreach ($bands as $band) {
            $ceiling = $band['up_to'] ?? null;
            $rate = (float) ($band['rate'] ?? 0);

            if ($ceiling === null) {
                $tax += max(0.0, $chargeable - $previous) * $rate;
                return $tax;
            }

            if ($chargeable <= $previous) {
                return $tax;
            }

            $tax += (min($chargeable, (float) $ceiling) - $previous) * $rate;
            $previous = (float) $ceiling;

            if ($chargeable <= $previous) {
                return $tax;
            }
        }

        return $tax;
    }

    /** @return array<int, array{up_to: float|null, rate: float}> */
    private static function normaliseBands(mixed $bands): array
    {
        if (!is_array($bands) || $bands === []) {
            $bands = self::DEFAULT_PAYE_BANDS;
        }

        $normalised = [];
        foreach ($bands as $band) {
            if (!is_array($band) || !array_key_exists('rate', $band)) {
                continue;
            }
            $normalised[] = [
                'up_to' => isset($band['up_to']) && $band['up_to'] !== null ? (float) $band['up_to'] : null,
                'rate' => (float) $band['rate'],
            ];
        }

        return $normalised === [] ? self::DEFAULT_PAYE_BANDS : $normalised;
    }

    private static function positiveRate(mixed $rate, float $default): float
    {
        return ($rate === null || (isset($rate) && (float) $rate < 0)) ? $default : max(0.0, (float) $rate);
    }
}
