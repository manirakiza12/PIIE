<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CourseRegistration\AutomaticCourseEnrolment;
use Illuminate\Console\Command;
use Throwable;

class ReconcileCourseEnrolment extends Command
{
    protected $signature = 'courses:reconcile-enrolment {--school= : Institution ID} {--student= : Student ID} {--apply : Create or confirm eligible current-period registrations} {--json : Emit auditable decisions as JSON lines}';
    protected $description = 'Preview (default) or apply automatic enrolment for the current active academic period only';

    public function handle(AutomaticCourseEnrolment $enrolment): int
    {
        foreach (['school', 'student'] as $option) {
            if ($this->option($option) !== null && (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1)) {
                $this->error("--{$option} must be a positive ID.");
                return self::FAILURE;
            }
        }
        $counts = [];
        $failed = false;
        User::where('role_id', 7)
            ->when($this->option('school'), fn ($q, $id) => $q->where('school_id', $id))
            ->when($this->option('student'), fn ($q, $id) => $q->whereKey($id))
            ->select(['id', 'school_id'])->chunkById(100, function ($students) use ($enrolment, &$counts, &$failed): void {
                foreach ($students as $student) {
                    try {
                        $decisions = $enrolment->reconcileStudent((int) $student->school_id, (int) $student->id, (bool) $this->option('apply'));
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed = true;
                        $decisions = [['result' => 'error', 'reason' => 'transaction_failed']];
                    }
                    foreach ($decisions as $decision) {
                        $key = $decision['result'] . ':' . $decision['reason'];
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                        if ($this->option('json')) {
                            $this->line(json_encode(['school_id' => $student->school_id, 'student_id' => $student->id] + $decision, JSON_THROW_ON_ERROR));
                        }
                    }
                }
            });
        if (! $this->option('json')) {
            $this->info($this->option('apply') ? 'Applied current-period enrolment.' : 'Preview only; no records changed.');
            foreach ($counts as $key => $count) { $this->line("{$key}: {$count}"); }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
