<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Support\Automation\{AcceptedStudentSetup, AutomationExceptions};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PrepareAcceptedStudents extends Command
{
    protected $signature = 'admissions:prepare-accepted-students {--school= : Limit to one institution}';
    protected $description = 'Complete accepted student accounts and retry safe mandatory invoice preparation';

    public function handle(AcceptedStudentSetup $setup): int
    {
        if (! AcceptedStudentSetup::available()) {
            $this->error('Safe automation migration is required. No account or invoice changed.');
            return self::FAILURE;
        }
        if ($this->option('school') !== null && ! preg_match('/\A[1-9][0-9]*\z/', $this->option('school'))) {
            $this->error('Provide a positive institution ID.');
            return self::INVALID;
        }
        $errors = 0;
        $processed = 0;
        $schools = DB::table('schools')->when($this->option('school'), fn ($q, $id) => $q->where('id', $id))->orderBy('id')->pluck('id');
        foreach ($schools as $schoolId) {
            // Missing institutional authority is visible, never treated as a template.
            foreach ([
                'calendar_drafts' => ['Academic Registrar', 'approved calendar templates with explicit dates'],
                'timetable_drafts' => ['Academic Registrar', 'approved timetable templates with dates, rooms and conflict rules'],
                'live_class_drafts' => ['Academic Registrar / ICT', 'approved Live Class templates and dated lecturer authorization'],
                'attendance_drafts' => ['Academic Registrar', 'approved attendance-session templates; attendance must still be recorded by authorized people'],
                'lecturer_carry_forward' => ['Academic Registrar / HOD', 'explicit approval to reuse a lecturer appointment for the target delivery and its full date range'],
                'result_publication' => ['Examinations Officer / Academic Board', 'an approved release policy defining complete objective results, integrity flags, release timing and the authorized approver'],
            ] as $workflow => [$owner, $requirement]) {
                AutomationExceptions::block((int) $schoolId, $workflow, 0, 'approved_policy_missing',
                    'Human action required: ' . $requirement . '. No automatic changes are authorized by the current records.', $owner);
            }
            Admission::where('school_id', $schoolId)
                ->where(fn ($q) => $q->where('status', Admission::STATUS_ACCEPTED)
                    ->orWhere(fn ($q) => $q->where('status', Admission::STATUS_ENROLLED)->whereNotNull('automation_student_id')))
                ->select('id')->chunkById(100, function ($admissions) use ($setup, $schoolId, &$errors, &$processed) {
                    foreach ($admissions as $admission) {
                        try {
                            $setup->run((int) $schoolId, (int) $admission->id);
                            ++$processed;
                        } catch (\Throwable $exception) {
                            // The per-application transaction rolled back. Continue other tenants.
                            report($exception);
                            ++$errors;
                            AutomationExceptions::block((int) $schoolId, 'accepted_student', (int) $admission->id,
                                'preparation_failed', 'ICT must inspect the application log and retry account preparation. No partial preparation was committed.', 'ICT / Admissions');
                        }
                    }
                });
        }
        $this->info("Checked {$processed} accepted student records; unexpected failures: {$errors}. Review automation:exceptions --school=<institution>.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
