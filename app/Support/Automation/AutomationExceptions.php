<?php

namespace App\Support\Automation;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/** Durable, tenant-scoped work queue. Resolution never authorizes a business action. */
final class AutomationExceptions
{
    public static function block(int $schoolId, string $workflow, int $subjectId, string $code, string $reason, string $owner): void
    {
        $key = ['school_id' => $schoolId, 'workflow' => $workflow, 'subject_id' => $subjectId];
        DB::table('automation_exceptions')->insertOrIgnore($key + [
            'code' => $code, 'reason' => $reason, 'owner' => $owner,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'occurrences' => 0,
        ]);
        $previous = DB::table('automation_exceptions')->where($key)->first();
        DB::table('automation_exceptions')->where($key)->update([
            'code' => $code, 'reason' => $reason, 'owner' => $owner, 'last_seen_at' => now(),
            'resolved_at' => null, 'occurrences' => DB::raw('occurrences + 1'),
        ]);
        if ((int) $previous->occurrences === 0 || $previous->code !== $code || $previous->resolved_at !== null) {
            AuditLog::record('update', 'Automation', $reason, ['school_id' => $schoolId,
                'record_type' => 'automation_exception', 'record_id' => $previous->id,
                'old_values' => (int) $previous->occurrences > 0 ? ['code' => $previous->code, 'reason' => $previous->reason, 'resolved_at' => $previous->resolved_at] : null,
                'new_values' => ['workflow' => $workflow, 'code' => $code, 'owner' => $owner]]);
        }
    }

    public static function resolve(int $schoolId, string $workflow, int $subjectId): void
    {
        $query = DB::table('automation_exceptions')->where([
            'school_id' => $schoolId, 'workflow' => $workflow, 'subject_id' => $subjectId,
        ])->whereNull('resolved_at');
        $id = (clone $query)->value('id');
        if ($query->update(['resolved_at' => now(), 'last_seen_at' => now()])) {
            AuditLog::record('update', 'Automation', 'Automation exception resolved after authoritative data revalidation.',
                ['school_id' => $schoolId, 'record_type' => 'automation_exception', 'record_id' => $id]);
        }
    }
}
