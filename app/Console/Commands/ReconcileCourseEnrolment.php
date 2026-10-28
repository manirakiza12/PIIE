<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\CourseRegistration\AutomaticCourseEnrolment;
use App\Support\CourseOffering\SemesterCourseOfferings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Schema};
use Throwable;

class ReconcileCourseEnrolment extends Command
{
    protected $signature = 'courses:reconcile-enrolment {--school= : Institution ID} {--student= : Enrol only this student; offering preparation remains period-wide} {--apply : Prepare required offerings and enrol eligible current-period students} {--json : Emit auditable decisions as JSON lines}';
    protected $description = 'Preview (default) or apply automatic enrolment for the current active academic period only';

    public function handle(AutomaticCourseEnrolment $enrolment, SemesterCourseOfferings $semesters): int
    {
        foreach (['school', 'student'] as $option) {
            if ($this->option($option) !== null && (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1)) {
                $this->error("--{$option} must be a positive ID.");
                return self::FAILURE;
            }
        }
        $counts = [];
        $failed = false;
        // Provision academic delivery before looking at students or their finances.
        // This also covers opened semesters that currently have no enrolled students.
        if (Schema::hasColumn('schools', 'current_academic_period_id')) {
            DB::table('schools')->whereNotNull('current_academic_period_id')
                ->when($this->option('school'), fn ($q, $id) => $q->where('id', $id))
                ->when($this->option('student'), fn ($q, $id) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('users')
                    ->whereColumn('users.school_id', 'schools.id')->where('users.id', $id)->where('users.role_id', 7)))
                ->select(['id', 'current_academic_period_id'])->chunkById(100, function ($schools) use ($semesters, &$counts, &$failed): void {
                    foreach ($schools as $school) {
                        try {
                            $decisions = $semesters->reconcile((int) $school->id, (int) $school->current_academic_period_id, (bool) $this->option('apply'), true);
                        } catch (Throwable $exception) {
                            report($exception);
                            $failed = true;
                            $decisions = [['entity' => 'offering', 'result' => 'error', 'reason' => 'offering_transaction_failed']];
                        }
                        foreach ($decisions as $decision) {
                            $this->emitDecision(['school_id' => $school->id] + $decision, $counts);
                        }
                    }
                });
        }
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
                        $this->emitDecision(['school_id' => $student->school_id, 'student_id' => $student->id] + $decision, $counts);
                    }
                }
            });
        if (! $this->option('json')) {
            $this->info($this->option('apply') ? 'Applied current-period enrolment.' : 'Preview only; no records changed.');
            foreach ($counts as $key => $count) { $this->line("{$key}: {$count}"); }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function emitDecision(array $decision, array &$counts): void
    {
        $key = ($decision['entity'] ?? 'registration') . ':' . $decision['result'] . ':' . $decision['reason'];
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        if ($this->option('json')) {
            $this->line(json_encode($decision, JSON_THROW_ON_ERROR));
        }
        if (in_array('lecturer_missing', $decision['warnings'] ?? [], true)) {
            $counts['warning:lecturer_missing'] = ($counts['warning:lecturer_missing'] ?? 0) + 1;
        }
    }
}
