<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schoolId = DB::table('global_settings')->where('key', 'primary_school_id')->value('value');
        if (!$schoolId) {
            return;
        }

        // Only replace the known placeholder on the configured public institution.
        DB::table('schools')->where('id', $schoolId)
            ->whereRaw('LOWER(TRIM(title)) = ?', ['piie test school'])
            ->update(['title' => 'Prime International Institute of Excellence']);
    }

    public function down(): void
    {
        // Preserve the real name and any subsequent administrator edits.
    }
};
