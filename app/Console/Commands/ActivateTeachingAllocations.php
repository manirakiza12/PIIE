<?php

namespace App\Console\Commands;

use App\Models\CourseOfferingLecturerAllocation;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ActivateTeachingAllocations extends Command
{
    protected $signature = 'academic:activate-teaching-allocations {--school= : Limit to one institution} {--dry-run : Report eligibility without changing records}';
    protected $description = 'Automatically activate eligible planned teaching assignments using existing validation and audit rules';

    public function handle(CourseOfferingLecturerAllocationService $service): int
    {
        if (! Schema::hasTable('course_offering_lecturer_allocations')) {
            $this->info('Teaching allocation tables are not installed.');
            return self::SUCCESS;
        }
        $school = $this->option('school');
        if ($school !== null && (! ctype_digit((string) $school) || (int) $school < 1)) {
            $this->error('School must be a positive integer.');
            return self::FAILURE;
        }
        $activated = $deferred = 0;
        CourseOfferingLecturerAllocation::query()->where('status', 'planned')
            ->when($school !== null, fn ($query) => $query->where('school_id', (int) $school))
            ->chunkById(100, function ($allocations) use ($service, &$activated, &$deferred) {
                foreach ($allocations as $allocation) {
                    if (! $service->canActivateAutomatically((int) $allocation->school_id, (int) $allocation->id)) {
                        $deferred++;
                        continue;
                    }
                    try {
                        if (! $this->option('dry-run')) {
                            $service->activate((int) $allocation->school_id, (int) $allocation->id);
                        }
                        $activated++;
                    } catch (DomainException) {
                        // Eligibility may change between preflight and the locked mutation.
                        $deferred++;
                    }
                }
            });
        $this->info(($this->option('dry-run') ? 'Eligible: ' : 'Activated: ').$activated.'; deferred: '.$deferred.'.');
        return self::SUCCESS;
    }
}
