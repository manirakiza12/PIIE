<?php

namespace App\Support\Academic;

use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Programme;
use App\Support\Curriculum\CurriculumFoundationService;
use App\Support\ProgrammeCatalogue\ProgrammeCoverImage;
use App\Support\ProgrammeCatalogue\ProgrammeGalleryImageService;
use App\Support\ProgrammeCatalogue\ProgrammePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ProgrammeDeletionService
{
    public function __construct(private CurriculumFoundationService $curricula, private ProgrammePublisher $publisher) {}

    public function delete(int $schoolId, int $programmeId, int $actorId, string $confirmation): void
    {
        DB::transaction(function () use ($schoolId, $programmeId, $actorId, $confirmation): void {
            $programme = Programme::where('school_id', $schoolId)->whereKey($programmeId)->lockForUpdate()->firstOrFail();
            $reference = trim((string) ($programme->code ?: $programme->name));
            if (! in_array(trim($confirmation), ['DELETE', $reference], true)) {
                throw ValidationException::withMessages(['confirm_deletion' => 'Type DELETE or the exact Programme name or code to confirm.']);
            }
            $coverPath = Schema::hasColumn('programmes', 'cover_image_path') ? $programme->cover_image_path : null;
            $gallery = app(ProgrammeGalleryImageService::class);
            $galleryPaths = $gallery->records($programme)->pluck('path')->all();
            DB::afterCommit(function () use ($coverPath, $galleryPaths): void {
                app(ProgrammeCoverImage::class)->deleteFile($coverPath);
                $gallery = app(ProgrammeGalleryImageService::class);
                foreach ($galleryPaths as $path) $gallery->deleteFile($path);
            });
            // A Curriculum version is owned by this Programme; delete its
            // registrations and other version-specific workflow rows first.
            if (Schema::hasTable('curricula')) {
                foreach (Curriculum::where('school_id', $schoolId)->where('programme_id', $programmeId)->lockForUpdate()->get() as $curriculum) {
                    $this->curricula->deleteDraft($curriculum, 'DELETE', $actorId);
                }
            }
            $this->publisher->detachDeletedProgramme($programme);

            $protected = ['programmes','curricula','subjects','student_profiles','users','schools','departments','academic_units'];
            if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'programme_id')) {
                DB::table('subjects')->where('school_id', $schoolId)->where('programme_id', $programmeId)->update(['programme_id' => null]);
            }
            if (Schema::hasTable('student_profiles') && Schema::hasColumn('student_profiles', 'programme_id')) {
                DB::table('student_profiles')->where('school_id', $schoolId)->where('programme_id', $programmeId)->update(['programme_id' => null]);
            }
            if (Schema::hasTable('admissions') && Schema::hasColumn('admissions', 'second_choice_programme_id')) {
                DB::table('admissions')->where('school_id', $schoolId)->where('second_choice_programme_id', $programmeId)->update(['second_choice_programme_id' => null]);
            }
            $this->deleteProgrammeGraph($programmeId, $schoolId, $protected);
            $actor = Schema::hasTable('users') ? \App\Models\User::find($actorId) : null;
            AuditLog::create([
                'school_id' => $schoolId, 'user_id' => $actorId, 'user_name' => $actor?->name,
                'role_id' => $actor?->role_id, 'role_name' => AuditLog::roleName($actor?->role_id),
                'action' => 'PROGRAMME_DELETED', 'event_type' => 'DATA', 'module' => 'Programmes',
                'description' => 'Programme and its owned records deleted.', 'record_type' => Programme::class,
                'record_id' => $programmeId, 'created_at' => now(),
            ]);
            DB::table('programmes')->where('school_id', $schoolId)->where('id', $programmeId)->delete();
        });
    }

    private function deleteProgrammeGraph(int $programmeId, int $schoolId, array $protected): void
    {
        $tables = array_map(static fn (string $name): string => substr(strrchr('.'.$name, '.'), 1), Schema::getTableListing());
        $rows = ['programmes' => [DB::table('programmes')->where('school_id', $schoolId)->where('id', $programmeId)->first()]];
        $edges = [];
        foreach ($tables as $table) {
            if (in_array($table, $protected, true) || ! Schema::hasColumn($table, 'programme_id')) continue;
            $query = DB::table($table)->where('programme_id', $programmeId);
            if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
            $found = $query->lockForUpdate()->get(); if ($found->isNotEmpty()) { $rows[$table] = $found->all(); $edges['programmes'][] = $table; }
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($tables as $child) {
                if (in_array($child, $protected, true)) continue;
                foreach (Schema::getForeignKeys($child) as $fk) {
                    $parent = $fk['foreign_table'] ?? null; $local = $fk['columns'] ?? []; $foreign = $fk['foreign_columns'] ?? [];
                    if (! $parent || ! isset($rows[$parent]) || ! $local || count($local) !== count($foreign)) continue;
                    $query = DB::table($child)->where(function ($or) use ($rows, $parent, $local, $foreign): void {
                        foreach ($rows[$parent] as $parentRow) $or->orWhere(function ($and) use ($parentRow, $local, $foreign): void { foreach ($local as $i => $column) $and->where($column, $parentRow->{$foreign[$i]}); });
                    });
                    if (Schema::hasColumn($child, 'school_id')) $query->where('school_id', $schoolId);
                    $found = $query->lockForUpdate()->get(); if ($found->isEmpty()) continue;
                    $edges[$parent][] = $child;
                    $fingerprint = static fn ($row): string => isset($row->id) ? 'id:'.$row->id : 'row:'.hash('sha256', serialize($row));
                    $known = collect($rows[$child] ?? [])->keyBy($fingerprint);
                    foreach ($found as $row) { $key = $fingerprint($row); if (! $known->has($key)) { $known->put($key, $row); $changed = true; } }
                    $rows[$child] = $known->values()->all();
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
            if ($table === 'programmes') continue;
            $this->deleteCollectedRows($table, $rows[$table], $schoolId);
        }
    }

    /** Delete only collected rows; pivot tables may have only a composite key. */
    private function deleteCollectedRows(string $table, array $rows, int $schoolId): void
    {
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
        if (Schema::hasColumn($table, 'id')) {
            $query->whereIn('id', collect($rows)->pluck('id')->filter()->unique()->values());
        } else {
            $columns = Schema::getColumnListing($table);
            $query->where(function ($or) use ($rows, $columns): void {
                foreach ($rows as $row) $or->orWhere(function ($and) use ($row, $columns): void {
                    foreach ($columns as $column) {
                        $value = $row->{$column} ?? null;
                        $value === null ? $and->whereNull($column) : $and->where($column, $value);
                    }
                });
            });
        }
        $query->delete();
    }
}
