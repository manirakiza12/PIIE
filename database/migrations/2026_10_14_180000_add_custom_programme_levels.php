<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Pretend mode records the query but intentionally returns no rows.
            $column = DB::select("SHOW COLUMNS FROM `programmes` WHERE Field = 'level'")[0] ?? null;
            if ($column === null || str_starts_with(strtolower($column->Type), 'enum(')) {
                DB::statement("ALTER TABLE `programmes` MODIFY `level` VARCHAR(100) NOT NULL DEFAULT 'Degree'");
            }
        }
        if (! Schema::hasTable('programme_levels')) {
            Schema::create('programme_levels', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id');
                $table->string('name', 100);
                $table->string('name_key', 64);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['school_id', 'name_key']);
            });
        }
    }

    public function down(): void
    {
        // Retain custom levels and the widened column on code rollback.
        // Narrowing to the old enum would destroy newly saved programme data.
    }
};
