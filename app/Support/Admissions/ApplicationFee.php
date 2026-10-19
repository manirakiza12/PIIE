<?php

namespace App\Support\Admissions;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Support\Payments\DecimalAmount;
use Illuminate\Support\Facades\DB;

/**
 * The application fee: how much, whether it is settled, and how it can be
 * paid.
 *
 * The amount comes from the chosen intake session, so an institution can run
 * a free "early bird" intake alongside a charged one without any code change.
 * An intake with no fee needs no payment checkout. The wizard retains its
 * final submission step and explains that no application fee is payable.
 */
class ApplicationFee
{
    /**
     * Stable, human-readable identifier for an application's fee obligation.
     * The application number is unique in the admissions table, so the PRN is
     * deterministic and does not require a second mutable identifier column.
     */
    public static function registrationNumber(Admission $admission): string
    {
        return 'PRN-' . strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $admission->app_number));
    }

    public static function amountFor(Admission $admission): float
    {
        if ($admission->application_fee_amount !== null) { return (float) $admission->application_fee_amount; }
        $intake = $admission->relationLoaded('intakeSession')
            ? $admission->intakeSession
            : $admission->intakeSession()->first();

        return (float) ($intake->application_fee ?? 0);
    }

    public static function isRequired(Admission $admission): bool
    {
        return self::amountFor($admission) > 0;
    }

    public static function isSettled(Admission $admission): bool
    {
        if (! self::isRequired($admission)) {
            return true;
        }

        return $admission->isFeeSettled();
    }

    public static function currency(?int $schoolId = null): string
    {
        $schoolId ??= \App\Support\PublicTenantResolver::resolveSchoolId();
        if ($schoolId && \Illuminate\Support\Facades\Schema::hasColumn('schools', 'school_currency')) {
            $configured = strtoupper(trim((string) DB::table('schools')->where('id', $schoolId)->value('school_currency')));
            if (preg_match('/\A[A-Z]{3}\z/', $configured)) {
                return $configured;
            }
        }

        // Compatibility for institutions that have not configured a currency.
        return get_active_currency();
    }

    public static function currencyFor(Admission $admission): string
    {
        return $admission->application_fee_currency ?: self::currency($admission->school_id);
    }

    /** Freeze new obligations on submission; existing rows are never mass-rewritten. */
    public static function freezeObligation(Admission $admission): void
    {
        if ($admission->application_fee_amount === null) {
            $admission->forceFill(['application_fee_amount' => self::amountFor($admission),
                'application_fee_currency' => self::currencyFor($admission)])->save();
        }
    }

    public static function format(float $amount, ?Admission $admission = null): string
    {
        return ($admission ? self::currencyFor($admission) : self::currency()) . ' ' . number_format($amount, 2);
    }

    /**
     * Recomputes `admissions.fee_status` from the payment rows.
     *
     * The column is a denormalised cache so the admissions queue can filter
     * and sort on fee state without joining; this is the one place allowed to
     * write it, and it is always derived, never set by hand.
     */
    public static function refreshStatus(Admission $admission): string
    {
        return DB::transaction(function () use ($admission) {
            $locked = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $required = DecimalAmount::minorUnits(self::amountFor($locked));
            if ($required !== null && $required <= 0) {
                $status = Admission::FEE_WAIVED;
            } else {
                $currency = strtoupper(self::currencyFor($locked));
                // Legacy rows without currency remain readable/countable. New
                // online settlement always requires explicit matching currency.
                $payments = $locked->payments()->where('school_id', $locked->school_id)->get()
                    ->filter(fn ($p) => blank($p->currency) || strtoupper($p->currency) === $currency);
                $paid = 0;
                $seen = [];
                foreach ($payments as $payment) {
                    $amount = DecimalAmount::minorUnits($payment->amount);
                    if ($payment->status !== ApplicationPayment::STATUS_PAID || $amount === null || $amount <= 0
                        || ($payment->gateway_payload['classification'] ?? null) === 'REVERSED') {
                        continue;
                    }
                    // Do not double-count historical online rows sharing a transaction.
                    if (isset(self::SUPPORTED_GATEWAYS[$payment->method]) && filled($payment->gateway_txn_id)) {
                        $key = $payment->method . ':' . $payment->gateway_txn_id;
                        if (isset($seen[$key])) {
                            continue;
                        }
                        $seen[$key] = true;
                    }
                    $paid += $amount;
                }

                if ($required !== null && $paid >= $required) {
                    $status = Admission::FEE_PAID;
                } elseif ($payments->contains(fn ($p) => $p->status === ApplicationPayment::STATUS_PENDING
                    && (DecimalAmount::minorUnits($p->amount) ?? 0) > 0)) {
                    $status = Admission::FEE_PENDING;
                } else {
                    $status = Admission::FEE_UNPAID;
                }
            }

            if ($locked->fee_status !== $status) {
                $locked->forceFill(['fee_status' => $status])->save();
            }
            $admission->fee_status = $status;

            return $status;
        });
    }

    /**
     * Payment options offered to applicants.
     *
     * Applicant fees use PesaPal only. Keep its option visible even before
     * configuration is complete so applicants get a clear readiness message
     * instead of being silently offered a manual payment path.
     *
     * Only gateways this deployment can actually take an applicant through
     * are listed — see App\Http\Controllers\Applicant\PaymentController.
     * Adding another means adding a branch there and an entry here, together;
     * advertising one without the other is a dead button on a payment page.
     */
    public const SUPPORTED_GATEWAYS = [
        'pesapal'     => ['label' => 'Card / Mobile Money (PesaPal)', 'icon' => 'bi-credit-card'],
        'marzpay'     => ['label' => 'Mobile Money / Card (MarzPay)', 'icon' => 'bi-phone'],
        'stripe'      => ['label' => 'Card Payment (Visa / Mastercard)', 'icon' => 'bi-credit-card'],
        'flutterwave' => ['label' => 'Card / Mobile Money (Flutterwave)', 'icon' => 'bi-phone'],
    ];

    /**
     * Which of SUPPORTED_GATEWAYS are actually offered. Stripe/Flutterwave
     * stay fully wired below (dormant) rather than deleted, in case this
     * institution ever wants them back. New applicant orders use PesaPal;
     * historical MarzPay polling and settlement remain available.
     */
    private const ENABLED_GATEWAYS = ['pesapal'];

    public static function availableMethods(int $schoolId): array
    {
        $methods = [];

        foreach (self::SUPPORTED_GATEWAYS as $name => $meta) {
            if (! in_array($name, self::ENABLED_GATEWAYS, true)) {
                continue;
            }

            $methods[] = [
                'key'         => $name,
                'label'       => get_phrase($meta['label']),
                'description' => get_phrase('Continue to PesaPal. Your fee is settled only after provider verification.'),
                'icon'        => $meta['icon'],
                'configured'  => self::gatewayIsConfigured($name, $schoolId),
            ];
        }

        return $methods;
    }

    public static function gatewayIsConfigured(string $gateway, int $schoolId): bool
    {
        if ($gateway === 'pesapal') {
            try {
                return filled(\App\Support\Payments\PesaPalConfiguration::forSchool($schoolId)->notificationId);
            } catch (\App\Support\Payments\PesaPalException $exception) {
                return false;
            }
        }
        if (! array_key_exists($gateway, self::SUPPORTED_GATEWAYS)) {
            return false;
        }

        if ($gateway === 'marzpay') {
            return \App\Support\Payments\MarzPayService::isConfigured($schoolId);
        }

        // Flutterwave is configured platform-wide (Super Admin > Payment
        // Settings, stored in global_settings), not per-school like
        // PaymentMethods below — this single-institution deployment only
        // ever needs one set of gateway credentials.
        if ($gateway === 'flutterwave') {
            return get_payment_keys('flutterwave', 'status') == 1
                && filled(self::flutterwaveSecretKey());
        }

        $configured = PaymentMethods::where('name', $gateway)
            ->where('status', 1)
            ->where(function ($query) use ($schoolId) {
                $query->where('school_id', $schoolId)->orWhereNull('school_id');
            })
            ->first();

        return $configured && ! empty($configured->payment_keys);
    }

    /**
     * Flutterwave's secret key for whichever mode (test/live) is currently
     * selected in Super Admin > Payment Settings — the one credential
     * PaymentController actually needs to call the Flutterwave API.
     */
    public static function flutterwaveSecretKey(): ?string
    {
        $mode = get_payment_keys('flutterwave', 'mode');

        return $mode === 'live'
            ? get_payment_keys('flutterwave', 'secret_live_key')
            : get_payment_keys('flutterwave', 'test_secret_key');
    }

}
