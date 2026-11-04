<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckRequiredTables extends Command
{
    protected $signature = 'db:check-required-tables';

    protected $description = 'Read-only preflight: verify required application tables exist';

    public function handle(): int
    {
        $required = [
            'schools', 'users', 'programmes', 'curricula',
            'curriculum_memberships', 'subjects', 'programme_cohort_memberships',
            'student_curriculum_assignments', 'academic_periods', 'programme_cohorts',
        ];

        $missing = [];
        foreach ($required as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            $this->error('MISSING TABLES: ' . implode(', ', $missing));
            return 1;
        }

        $this->info('OK: required tables present');
        return 0;
    }
}
