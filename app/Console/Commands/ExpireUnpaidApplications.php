<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Models\AdmissionStatusEvent;
use App\Support\Admissions\ApplicationFee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Closes applications whose intake application period ended while the fee was
 * still outstanding.
 *
 * Only applications the applicant actually submitted are considered; drafts are
 * never touched. A settled fee (paid, or explicitly waived) is never expired.
 * Each change writes the status, a visible timeline entry and an audit record
 * together, so the applicant can see why their application closed.
 */
class ExpireUnpaidApplications extends Command
{
    protected $signature = 'admissions:expire-unpaid
        {--dry-run : List what would be expired and change nothing}
        {--school= : Restrict to one school id}
        {--days=0 : Only consider intakes that closed more than N days ago}';

    protected $description = 'Expire submitted applications left unpaid after their intake closed.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $school  = $this->option('school') !== null ? (int) $this->option('school') : null;
        $grace   = max(0, (int) $this->option('days'));

        $query = Admission::query()
            ->whereNotNull('intake_session_id')
            ->whereIn('status', Admission::ACTIVE_REVIEW_STATUSES)
            ->whereNotNull('submitted_at');

        if ($school !== null) {
            $query->where('school_id', $school);
        }

        $candidates = $query->with('intakeSession')->get()->filter(function (Admission $admission) use ($grace) {
            $intake = $admission->intakeSession;
            if (! $intake || blank($intake->close_date)) {
                return false;
            }
            // End of the closing day, so an intake closing today is not swept.
            $closedAt = \Carbon\Carbon::parse($intake->close_date)->endOfDay();
            if ($grace > 0) {
                $closedAt = $closedAt->addDays($grace);
            }

            return $closedAt->isPast();
        });

        $expired = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($candidates as $admission) {
            // Settle first: fee_status is a cache and may be stale.
            try {
                ApplicationFee::refreshStatus($admission);
            } catch (Throwable $exception) {
                $this->warn("  #{$admission->id} {$admission->app_number}: could not refresh fee status, skipped.");
                $skipped++;

                continue;
            }

            if ($admission->fresh()->isFeeSettled()) {
                continue;
            }

            $line = sprintf('  #%d %s  %s  fee %s %s  (intake closed %s)',
                $admission->id, $admission->app_number, $admission->status,
                ApplicationFee::amountFor($admission), ApplicationFee::currencyFor($admission),
                $admission->intakeSession->close_date);

            if ($dryRun) {
                $this->line('[dry-run] would expire' . $line);
                $expired++;

                continue;
            }

            try {
                $this->expire($admission->fresh());
                $this->info('Expired' . $line);
                $expired++;
            } catch (Throwable $exception) {
                $this->error("  #{$admission->id} {$admission->app_number}: " . $exception->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d application(s); %d already settled and skipped; %d could not be evaluated.',
            $dryRun ? 'Would expire' : 'Expired', $expired, $skipped, $failed
        ));

        if ($dryRun) {
            $this->line('Nothing was changed. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Status, timeline entry and audit record move together, so an expired
     * application is never visible as closed without an explanation.
     */
    private function expire(Admission $admission): void
    {
        DB::transaction(function () use ($admission) {
            $locked = Admission::whereKey($admission->id)
                ->where('school_id', $admission->school_id)
                ->lockForUpdate()->firstOrFail();

            // Re-check under the lock: a payment may have landed since selection.
            if ($locked->isFeeSettled() || ! in_array($locked->status, Admission::ACTIVE_REVIEW_STATUSES, true)) {
                return;
            }

            $previous = $locked->status;
            $locked->forceFill(['status' => Admission::STATUS_EXPIRED])->save();

            AdmissionStatusEvent::create([
                'school_id'   => $locked->school_id,
                'admission_id' => $locked->id,
                'from_status' => $previous,
                'to_status'   => Admission::STATUS_EXPIRED,
                'title'       => get_phrase('Application closed — fee not paid'),
                'note'        => get_phrase('The application period closed while the application fee was still outstanding. Contact the admissions office if you believe this is wrong.'),
                'actor_type'  => 'system',
                'actor_id'    => null,
                'actor_name'  => 'Application period closed',
            ]);

            \App\Models\AuditLog::record('update', 'Admissions',
                "Application {$locked->app_number} expired after its intake closed with the fee outstanding.",
                ['event_type' => 'ACTION', 'record_type' => Admission::class,
                 'record_id' => $locked->id, 'school_id' => $locked->school_id]);
        });
    }
}