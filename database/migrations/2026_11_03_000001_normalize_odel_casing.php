<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Update existing programme modes from 'ODEL' to 'ODeL'
        if (Schema::hasTable('programmes') && Schema::hasColumn('programmes', 'mode')) {
            DB::table('programmes')->where('mode', 'ODEL')->update(['mode' => 'ODeL']);
            DB::table('programmes')->where('mode', 'ODEL/Blended')->update(['mode' => 'ODeL/Blended']);
        }
        // Update requested programme modes in admissions
        if (Schema::hasTable('admissions')) {
            $columns = ['requested_programme_mode', 'programme_mode'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('admissions', $col)) {
                    DB::table('admissions')->where($col, 'ODEL')->update([$col => 'ODeL']);
                    DB::table('admissions')->where($col, 'ODEL/Blended')->update([$col => 'ODeL/Blended']);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('programmes') && Schema::hasColumn('programmes', 'mode')) {
            DB::table('programmes')->where('mode', 'ODeL')->update(['mode' => 'ODEL']);
            DB::table('programmes')->where('mode', 'ODeL/Blended')->update(['mode' => 'ODEL/Blended']);
        }
    }
};
