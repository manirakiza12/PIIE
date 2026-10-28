<?php

namespace App\Console\Commands;

use App\Support\Automation\AcceptedStudentSetup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ListAutomationExceptions extends Command
{
    protected $signature = 'automation:exceptions {--school= : Required institution ID} {--history : Include resolved items}';
    protected $description = 'Read the institution-scoped automation exception queue (no business records changed)';

    public function handle(): int
    {
        if (! preg_match('/\A[1-9][0-9]*\z/', (string) $this->option('school'))) {
            $this->error('Provide --school=<institution> to read its exception queue.');
            return self::INVALID;
        }
        if (! AcceptedStudentSetup::available()) {
            $this->error('Safe automation migration is required.');
            return self::FAILURE;
        }
        $rows = DB::table('automation_exceptions')->where('school_id', (int) $this->option('school'))
            ->when(! $this->option('history'), fn ($q) => $q->whereNull('resolved_at'))
            ->orderBy('workflow')->orderBy('subject_id')->get();
        $this->table(['Workflow', 'Application', 'Code', 'Required action', 'Owner', 'Occurrences', 'Resolved'],
            $rows->map(fn ($row) => [$row->workflow, $row->subject_id ?: 'Policy', $row->code,
                $row->reason, $row->owner, $row->occurrences, $row->resolved_at ?? 'Open'])->all());
        return self::SUCCESS;
    }
}
