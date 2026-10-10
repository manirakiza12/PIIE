<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reports whether a deployed instance is internally consistent.
 *
 * The /health endpoint used by the deployment pipeline is deliberately
 * database-independent, so a release whose schema is behind its code reports
 * healthy and then fails on real pages. This command surfaces exactly the drift
 * that /health cannot see, plus the application-level configuration that has
 * repeatedly been the difference between "works locally" and "500s live".
 *
 * Read-only. Safe to run against production at any time.
 */
class DeployDoctor extends Command
{
    protected $signature = 'piie:doctor
        {--json : Emit machine-readable JSON instead of a report}';

    protected $description = 'Diagnose deployment drift: schema, cache, payments, URL and trusted-proxy configuration.';

    private array $checks = [];

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->checkEnvironment();
        $this->checkSchema();
        $this->checkCachedConfiguration();
        $this->checkApplicationUrl();
        $this->checkTrustedProxies();
        $this->checkPesaPal();
        $this->checkStrandedPayments();
        $this->checkStorageWritable();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'failures' => $this->failures,
                'warnings' => $this->warnings,
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT));
        }

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function record(string $name, string $status, string $detail): void
    {
        $this->checks[] = ['check' => $name, 'status' => $status, 'detail' => $detail];
        if ($status === 'FAIL') { $this->failures++; }
        if ($status === 'WARN') { $this->warnings++; }
        if (! $this->option('json')) {
            $this->line(sprintf('  %-6s %-28s %s', "[{$status}]", $name, $detail));
        }
    }

    private function checkEnvironment(): void
    {
        $this->line('Environment');
        $debug = (bool) config('app.debug');
        $this->record('debug_mode', $debug && app()->environment('production') ? 'FAIL' : 'OK',
            'APP_DEBUG=' . var_export($debug, true) . ' (' . app()->environment() . ')'
            . ($debug && app()->environment('production') ? ' - must be false in production' : ''));
    }

    /**
     * The drift that silently breaks a release. Compared by column name, so it
     * does not depend on the order migrations happened to run in.
     */
    private function checkSchema(): void
    {
        $this->line('Schema');
        try {
            $pending = trim((string) Artisan::call('migrate:status'));
            $lines = array_values(array_filter(array_map('trim', explode("\n", $pending))));
            $waiting = array_values(array_filter($lines, fn ($l) => str_contains($l, 'Pending')));
        } catch (Throwable $e) {
            $this->record('migrate_status', 'FAIL', 'could not read migrate:status: ' . $e->getMessage());

            return;
        }

        if ($waiting !== []) {
            $this->record('pending_migrations', 'FAIL', count($waiting) . ' pending - run `php artisan migrate --force`'
                . PHP_EOL . '        ' . implode(PHP_EOL . '        ', array_slice($waiting, 0, 10)));
        } else {
            $this->record('pending_migrations', 'OK', 'none');
        }

        // Columns the admissions/payment code writes directly.
        $expected = [
            'admissions' => ['application_fee_amount', 'application_fee_currency'],
            'application_payments' => ['gateway_txn_id', 'gateway_payload', 'status'],
            'schools' => ['school_currency'],
        ];
        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->record("table:$table", 'FAIL', 'missing');

                continue;
            }
            $missing = array_values(array_filter($columns, fn ($c) => ! Schema::hasColumn($table, $c)));
            $this->record("table:$table", $missing ? 'FAIL' : 'OK',
                $missing ? 'missing columns: ' . implode(', ', $missing) : 'schema complete');
        }
    }

    private function checkCachedConfiguration(): void
    {
        $this->line('Cached configuration');
        if (app()->configurationIsCached()) {
            // A stale cache silently ignores .env edits, which is a recurring
            // cause of "I changed it and nothing happened".
            $this->record('config_cache', 'WARN', 'cached - .env changes are ignored until `php artisan config:clear`');
        } else {
            $this->record('config_cache', 'OK', 'not cached');
        }
        if (app()->routesAreCached()) {
            $this->record('route_cache', 'OK', 'cached');
        } else {
            $this->record('route_cache', 'WARN', 'not cached (slower, not incorrect)');
        }
    }

    private function checkApplicationUrl(): void
    {
        $this->line('URLs and proxy');
        $url = (string) config('app.url');
        $isHttps = str_starts_with($url, 'https://');
        $this->record('app_url_scheme', $isHttps ? 'OK' : 'WARN',
            "APP_URL=$url" . ($isHttps ? '' : ' - signed/reset links will be http and may be rejected'));

        // Every gateway URL the payment code builds must be absolute HTTPS.
        try {
            $callback = route('applicant.pesapal.callback');
            $ok = str_starts_with($callback, 'https://');
            $this->record('pesapal_callback', $ok ? 'OK' : 'FAIL',
                $callback . ($ok ? '' : ' - PesaPal refuses non-HTTPS callbacks; checkout will fail'));
        } catch (Throwable $e) {
            $this->record('pesapal_callback', 'WARN', 'could not generate: ' . $e->getMessage());
        }

        $secure = (bool) config('session.secure');
        $local = str_contains($url, '127.0.0.1') || str_contains($url, 'localhost');
        $this->record('session_secure_cookie', ($secure && ! $local) ? 'OK' : 'WARN',
            'SESSION_SECURE_COOKIE=' . var_export($secure, true)
            . ($local ? ' (loopback: browsers accept Secure cookies here)' : ''));
    }

    private function checkTrustedProxies(): void
    {
        $configured = env('TRUSTED_PROXIES');
        $this->record('trusted_proxies', $configured ? 'OK' : 'WARN',
            $configured ? 'TRUSTED_PROXIES=' . $configured
                : 'unset - if this app sits behind a proxy/TLS terminator, https URLs will be generated as http');
    }

    private function checkPesaPal(): void
    {
        $this->line('PesaPal');
        try {
            $rows = DB::table('payment_methods')->where('name', 'pesapal')->where('status', 1)->get();
        } catch (Throwable $e) {
            $this->record('pesapal_rows', 'FAIL', 'could not read: ' . $e->getMessage());

            return;
        }
        if ($rows->isEmpty()) {
            $this->record('pesapal_rows', 'WARN', 'no active PesaPal configuration - online fee payment is hidden');

            return;
        }
        foreach ($rows as $row) {
            $label = 'pesapal#' . $row->id . ' (school ' . ($row->school_id ?? 'null') . ')';
            try {
                $configuration = \App\Support\Payments\PesaPalConfiguration::forSchool((int) $row->school_id);
            } catch (Throwable $e) {
                $this->record($label, 'FAIL', 'credentials unreadable: ' . $e->getMessage());

                continue;
            }
            $this->record($label . ' environment', 'OK', $configuration->environment);

            $this->record($label . ' notification_id',
                $configuration->notificationId ? 'OK' : 'FAIL',
                $configuration->notificationId
                    ? 'registered - online checkout is offered'
                    : 'MISSING - PesaPal buttons are hidden and staff cannot send payment links; register the IPN');
        }
    }

    /**
     * Reservations with no provider order id can neither be resumed (no
     * checkout URL) nor reconciled (nothing to look up), which locks the
     * applicant out of paying.
     */
    private function checkStrandedPayments(): void
    {
        $this->line('Payment ledger');
        try {
            $stranded = DB::table('application_payments')
                ->where('method', 'pesapal')->where('status', 'pending')
                ->whereNull('gateway_txn_id')
                ->where(function ($q) {
                    $q->whereNull('gateway_payload')->orWhere('gateway_payload', 'not like', '%not-dispatched%');
                })
                ->count();
        } catch (Throwable $e) {
            $this->record('stranded_payments', 'FAIL', 'could not read: ' . $e->getMessage());

            return;
        }
        $this->record('stranded_payments', $stranded > 0 ? 'WARN' : 'OK',
            $stranded > 0
                ? "$stranded pending PesaPal row(s) with no order id - those applicants cannot complete or retry payment"
                : 'none');
    }

    private function checkStorageWritable(): void
    {
        $this->line('Storage');
        foreach (['storage/logs', 'bootstrap/cache'] as $path) {
            $full = base_path($path);
            $this->record($path, is_writable($full) ? 'OK' : 'FAIL',
                is_writable($full) ? 'writable' : 'NOT writable - logging or caching will fail');
        }
    }
}