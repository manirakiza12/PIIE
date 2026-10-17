<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table): void {
            $table->foreignId('legacy_department_id')->nullable()->after('department_id')
                ->constrained('departments')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            DB::table('programmes')
                ->join('departments', 'departments.id', '=', 'programmes.department_id')
                ->join('academic_units', 'academic_units.legacy_department_id', '=', 'departments.id')
                ->whereColumn('programmes.school_id', 'departments.school_id')
                ->select('programmes.id as id', 'departments.id as legacy_department_id')
                ->orderBy('programmes.id')
                ->chunkById(500, function ($programmes): void {
                    foreach ($programmes as $programme) {
                        DB::table('programmes')->where('id', $programme->id)->update([
                            'department_id' => null,
                            'legacy_department_id' => $programme->legacy_department_id,
                        ]);
                    }
                }, 'programmes.id', 'id');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('programmes')->whereNull('department_id')->whereNotNull('legacy_department_id')
                ->update(['department_id' => DB::raw('legacy_department_id')]);
        });

        Schema::table('programmes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_department_id');
        });
    }
};
