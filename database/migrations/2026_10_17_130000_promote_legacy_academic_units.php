<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Department rows still referenced elsewhere are retained as compatibility records. */
    private const LEGACY_NAMES = [
        'FACULTY OF APPLIED SICNCE AND TECHNOLOGY',
        'FACULTY OF APPLIED SCIENCE AND TECHNOLOGY',
        'FACULTY OF EDUCATION',
        'FACULTY OF MANAGEMENT AND HUMANITIES',
        'SCHOOL OF GRADUATE STUDIES AND RESEARCH',
        'SCHOOL OF HEALTH SCIENCES',
    ];

    public function up(): void
    {
        Schema::table('academic_units', function (Blueprint $table): void {
            $table->foreignId('legacy_department_id')->nullable()->unique()
                ->after('parent_id')->constrained('departments')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $rows = DB::table('departments')->whereIn(DB::raw('UPPER(TRIM(name))'), self::LEGACY_NAMES)->orderBy('id')->get();

            foreach ($rows as $department) {
                $name = trim((string) $department->name);
                $normalizedName = mb_strtoupper($name);
                $type = str_starts_with($normalizedName, 'SCHOOL OF ') ? 'school' : 'faculty';
                $unit = DB::table('academic_units')->where('school_id', $department->school_id)
                    ->where('type', $type)->whereRaw('UPPER(TRIM(name)) = ?', [$normalizedName])->first();

                if ($unit) {
                    DB::table('academic_units')->where('id', $unit->id)->whereNull('legacy_department_id')->update([
                        'legacy_department_id' => $department->id,
                        'head_name' => $unit->head_name ?: ($department->hod_name ?? null),
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                $code = $department->code ?? null;
                if ($code !== null && DB::table('academic_units')->where('school_id', $department->school_id)->where('type', $type)->where('code', $code)->exists()) {
                    $code = null;
                }

                DB::table('academic_units')->insert([
                    'school_id' => $department->school_id, 'type' => $type, 'name' => $name,
                    'code' => $code, 'head_title' => null, 'head_name' => $department->hod_name ?? null,
                    'description' => null, 'status' => 'active', 'parent_id' => null,
                    'legacy_department_id' => $department->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve the promoted units and old department IDs on rollback.
        Schema::table('academic_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_department_id');
        });
    }
};
