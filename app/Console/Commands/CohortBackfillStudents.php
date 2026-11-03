<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Models\ProgrammeCohort;
use App\Models\ProgrammeCohortMembership;
use App\Models\User;
use App\Support\ProgrammeCohorts\ProgrammeCohortService;
use Illuminate\Console\Command;

class CohortBackfillStudents extends Command
{
    protected $signature = 'cohort:backfill-students {--apply : Actually assign; default is dry-run} {--school= : Limit to a school id}';

    protected $description = 'Report/assign existing students to Programme Cohorts based on admission records (dry-run by default).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $schoolFilter = $this->option('school');

        $students = User::query()->where('role_id', 7);
        if ($schoolFilter) {
            $students->where('school_id', (int) $schoolFilter);
        }
        $students = $students->get();

        $assigned = 0;
        $pending = 0;
        $unresolved = 0;
        $already = 0;

        foreach ($students as $student) {
            $membership = ProgrammeCohortMembership::query()
                ->where('school_id', $student->school_id)
                ->where('student_id', $student->id)
                ->whereNull('ended_at')
                ->first();

            if ($membership) {
                $already++;
                continue;
            }

            $admission = Admission::query()
                ->where('school_id', $student->school_id)
                ->where('email', $student->email)
                ->latest('id')
                ->first();

            if (! $admission || ! $admission->programme_id || ! $admission->intake_session_id) {
                $unresolved++;
                $this->line("UNRESOLVED: student #{$student->id} ({$student->email}) school {$student->school_id} — no matching admission/programme/intake");
                continue;
            }

            $cohorts = ProgrammeCohort::query()
                ->where('school_id', $student->school_id)
                ->where('programme_id', $admission->programme_id)
                ->where('intake_session_id', $admission->intake_session_id)
                ->where('status', 'active')
                ->get();

            if ($cohorts->count() !== 1) {
                $unresolved++;
                $this->line("UNRESOLVED: student #{$student->id} ({$student->email}) — " . $cohorts->count() . " matching active cohort(s)");
                continue;
            }

            $pending++;
            $this->line(($apply ? 'ASSIGNING' : 'WOULD ASSIGN') . ": student #{$student->id} ({$student->email}) -> cohort #{$cohorts->first()->id} ({$cohorts->first()->code})");

            if ($apply) {
                $actor = User::query()->where('school_id', $student->school_id)->whereIn('role_id', [1, 2])->orderBy('id')->first();
                if (! $actor) {
                    $unresolved++;
                    $this->error("NO ADMIN for school {$student->school_id}; cannot assign student #{$student->id}");
                    continue;
                }
                try {
                    app(ProgrammeCohortService::class)->assignFromAdmission(
                        $actor,
                        $cohorts->first()->id,
                        $student->id,
                        $admission->id
                    );
                    $assigned++;
                } catch (\Throwable $e) {
                    $unresolved++;
                    $this->error("FAILED student #{$student->id}: " . $e->getMessage());
                }
            }
        }

        $this->info("Summary: already={$already}, pending={$pending}, assigned={$assigned}, unresolved={$unresolved}, total={$students->count()}");

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to execute assignments. Review unresolved students manually.');
        }

        return 0;
    }
}