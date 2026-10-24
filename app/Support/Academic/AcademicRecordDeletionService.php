<?php

namespace App\Support\Academic;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Support\CourseOffering\CourseOfferingService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AcademicRecordDeletionService
{
    public function __construct(private CourseOfferingService $offerings) {}

    public function deleteYear(int $schoolId, int $yearId, int $actorId, string $confirmation): void
    {
        DB::transaction(function () use ($schoolId, $yearId, $actorId, $confirmation): void {
            $year = AcademicYear::where('school_id', $schoolId)->whereKey($yearId)->lockForUpdate()->firstOrFail();
            $this->confirm($year->label, $confirmation);
            $this->removeOfferings($schoolId, $actorId, DB::table('course_offerings')->where('school_id', $schoolId)->where('academic_year_id', $yearId)->pluck('id'));
            if (Schema::hasTable('curricula') && Schema::hasColumn('curricula', 'effective_academic_year_id')) {
                DB::table('curricula')->where('school_id', $schoolId)->where('effective_academic_year_id', $yearId)->update(['effective_academic_year_id' => null, 'updated_at' => now()]);
            }
            if (Schema::hasTable('student_profiles') && Schema::hasColumn('student_profiles', 'academic_year_id')) {
                DB::table('student_profiles')->where('school_id', $schoolId)->where('academic_year_id', $yearId)->update(['academic_year_id' => null]);
            }
            DB::table('schools')->where('id', $schoolId)->where('current_academic_year_id', $yearId)->update(['current_academic_year_id' => null, 'current_academic_period_id' => null]);
            $this->deleteOwnedGraph('academic_years', $yearId, $schoolId);
            $this->audit($schoolId, $actorId, 'ACADEMIC_YEAR_DELETED', AcademicYear::class, $yearId);
        });
    }

    public function deletePeriod(int $schoolId, int $periodId, int $actorId, string $confirmation): void
    {
        DB::transaction(function () use ($schoolId, $periodId, $actorId, $confirmation): void {
            $period = AcademicPeriod::where('school_id', $schoolId)->whereKey($periodId)->lockForUpdate()->firstOrFail();
            $this->confirm($period->label, $confirmation);
            $this->removeOfferings($schoolId, $actorId, DB::table('course_offerings')->where('school_id', $schoolId)->where('academic_period_id', $periodId)->pluck('id'));
            DB::table('schools')->where('id', $schoolId)->where('current_academic_period_id', $periodId)->update(['current_academic_period_id' => null]);
            $this->deleteOwnedGraph('academic_periods', $periodId, $schoolId);
            $this->audit($schoolId, $actorId, 'ACADEMIC_PERIOD_DELETED', AcademicPeriod::class, $periodId);
        });
    }

    private function confirm(string $reference, string $confirmation): void
    {
        if (! in_array(trim($confirmation), ['DELETE', trim($reference)], true)) {
            throw ValidationException::withMessages(['confirm_deletion' => 'Type DELETE or the exact record label to confirm.']);
        }
    }

    private function removeOfferings(int $schoolId, int $actorId, iterable $ids): void
    {
        foreach ($ids as $id) $this->offerings->deleteAllocation($schoolId, (int) $id, $actorId, 'DELETE');
    }

    /** Collect direct legacy links and FK descendants, then delete children before parents. */
    private function deleteOwnedGraph(string $root, int $id, int $schoolId): void
    {
        $tables = array_map(static fn (string $name): string => substr(strrchr('.'.$name, '.'), 1), Schema::getTableListing());
        $protected = ['schools','users','subjects','programmes','departments','curricula','course_offerings','student_profiles'];
        $rows = [$root => [DB::table($root)->where('school_id', $schoolId)->where('id', $id)->first()]];
        $rows[$root] = array_filter($rows[$root]); $edges = [];
        foreach ($tables as $table) {
            if ($table === $root || in_array($table, $protected, true)) continue;
            foreach (['academic_year_id', 'academic_period_id'] as $column) {
                if (! Schema::hasColumn($table, $column)) continue;
                $isTarget = ($root === 'academic_years' && $column === 'academic_year_id') || ($root === 'academic_periods' && $column === 'academic_period_id');
                if (! $isTarget) continue;
                $query = DB::table($table)->where($column, $id);
                if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
                $found = $query->get();
                if ($found->isNotEmpty()) {
                    $fingerprint = static fn ($row): string => isset($row->id) ? 'id:'.$row->id : 'row:'.hash('sha256', json_encode($row));
                    $rows[$table] = collect($rows[$table] ?? [])->merge($found)->unique($fingerprint)->values()->all();
                }
            }
        }
        if ($root === 'academic_years' && isset($rows['sessions'])) {
            $sessionIds = collect($rows['sessions'])->pluck('id')->filter()->all();
            foreach (['subjects', 'student_profiles'] as $shared) {
                if (Schema::hasTable($shared) && Schema::hasColumn($shared, 'session_id')) {
                    $query = DB::table($shared)->whereIn('session_id', $sessionIds);
                    if (Schema::hasColumn($shared, 'school_id')) $query->where('school_id', $schoolId);
                    $query->update(['session_id' => null]);
                }
            }
            foreach ($tables as $table) {
                if (in_array($table, array_merge($protected, ['sessions']), true) || ! Schema::hasColumn($table, 'session_id')) continue;
                $query = DB::table($table)->whereIn('session_id', $sessionIds);
                if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
                $found = $query->get(); if ($found->isEmpty()) continue;
                $fingerprint = static fn ($row): string => isset($row->id) ? 'id:'.$row->id : 'row:'.hash('sha256', json_encode($row));
                $rows[$table] = collect($rows[$table] ?? [])->merge($found)->unique($fingerprint)->values()->all();
            }
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($tables as $child) {
                if (in_array($child, $protected, true) || ! Schema::hasColumn($child, 'id')) continue;
                foreach (Schema::getForeignKeys($child) as $fk) {
                    $parent = $fk['foreign_table'] ?? null; $local = $fk['columns'] ?? []; $foreign = $fk['foreign_columns'] ?? [];
                    if (! $parent || ! isset($rows[$parent]) || ! $local || count($local) !== count($foreign)) continue;
                    $query = DB::table($child)->where(function ($or) use ($rows, $parent, $local, $foreign): void {
                        foreach ($rows[$parent] as $parentRow) $or->orWhere(function ($and) use ($parentRow, $local, $foreign): void { foreach ($local as $i => $column) $and->where($column, $parentRow->{$foreign[$i]}); });
                    });
                    if (Schema::hasColumn($child, 'school_id')) $query->where('school_id', $schoolId);
                    $found = $query->get(); if ($found->isEmpty()) continue;
                    $edges[$parent][] = $child;
                    $fingerprint = static fn ($row): string => isset($row->id) ? 'id:'.$row->id : 'row:'.hash('sha256', json_encode($row));
                    $known = collect($rows[$child] ?? [])->keyBy($fingerprint);
                    foreach ($found as $row) { $key = $fingerprint($row); if (! $known->has($key)) { $known->put($key, $row); $changed = true; } }
                    $rows[$child] = $known->values()->all(); break;
                }
            }
        }
        $order = []; $visited = [];
        $visit = function (string $table) use (&$visit, &$order, &$visited, $edges, $rows): void {
            if (isset($visited[$table])) return; $visited[$table] = true;
            foreach (array_unique($edges[$table] ?? []) as $child) if (isset($rows[$child])) $visit($child);
            $order[] = $table;
        };
        foreach (array_keys($rows) as $table) $visit($table);
        foreach ($order as $table) {
            if ($table === $root) continue;
            if (! Schema::hasColumn($table, 'id')) {
                if (Schema::hasColumn($table, $root === 'academic_years' ? 'academic_year_id' : 'academic_period_id')) {
                    $query = DB::table($table)->where($root === 'academic_years' ? 'academic_year_id' : 'academic_period_id', $id);
                    if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
                    $query->delete(); continue;
                }
                throw new DomainException("Cannot safely delete dependent academic records from {$table}; all changes were rolled back.");
            }
            $query = DB::table($table)->whereIn('id', collect($rows[$table])->pluck('id'));
            if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
            $query->delete();
        }
        DB::table($root)->where('school_id', $schoolId)->where('id', $id)->delete();
    }

    private function audit(int $schoolId, int $actorId, string $action, string $type, int $id): void
    {
        $actor = Schema::hasTable('users') ? \App\Models\User::find($actorId) : null;
        AuditLog::create([
            'school_id' => $schoolId, 'user_id' => $actorId, 'user_name' => $actor?->name,
            'role_id' => $actor?->role_id, 'role_name' => AuditLog::roleName($actor?->role_id),
            'action' => $action, 'event_type' => 'DATA', 'module' => 'Academic Setup',
            'description' => 'Academic record deletion.', 'record_type' => $type,
            'record_id' => $id, 'created_at' => now(),
        ]);
    }
}
