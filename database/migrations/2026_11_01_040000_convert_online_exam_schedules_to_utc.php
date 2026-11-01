<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Convert existing online_exams start_datetime and end_datetime
     * from Africa/Kampala local time to UTC for consistent storage.
     *
     * Existing rows were stored as naive datetimes interpreted as
     * Kampala local time by the previous scheduledStartAt()/scheduledEndAt().
     * This migration converts them to UTC so that the new accessor methods
     * (which parse as UTC and render as Kampala) return the same instant.
     */
    public function up(): void
    {
        $kampala = 'Africa/Kampala';

        $exams = DB::table('online_exams')
            ->whereNotNull('start_datetime')
            ->orWhereNotNull('end_datetime')
            ->get();

        foreach ($exams as $exam) {
            $updates = [];

            if ($exam->start_datetime) {
                $utc = Carbon::parse($exam->start_datetime, $kampala)->setTimezone('UTC');
                $updates['start_datetime'] = $utc->format('Y-m-d H:i:s');
            }

            if ($exam->end_datetime) {
                $utc = Carbon::parse($exam->end_datetime, $kampala)->setTimezone('UTC');
                $updates['end_datetime'] = $utc->format('Y-m-d H:i:s');
            }

            if (!empty($updates)) {
                DB::table('online_exams')
                    ->where('id', $exam->id)
                    ->update($updates);
            }
        }
    }

    /**
     * Rollback: convert stored UTC values back to Africa/Kampala local time.
     */
    public function down(): void
    {
        $kampala = 'Africa/Kampala';

        $exams = DB::table('online_exams')
            ->whereNotNull('start_datetime')
            ->orWhereNotNull('end_datetime')
            ->get();

        foreach ($exams as $exam) {
            $updates = [];

            if ($exam->start_datetime) {
                $local = Carbon::parse($exam->start_datetime, 'UTC')->setTimezone($kampala);
                $updates['start_datetime'] = $local->format('Y-m-d H:i:s');
            }

            if ($exam->end_datetime) {
                $local = Carbon::parse($exam->end_datetime, 'UTC')->setTimezone($kampala);
                $updates['end_datetime'] = $local->format('Y-m-d H:i:s');
            }

            if (!empty($updates)) {
                DB::table('online_exams')
                    ->where('id', $exam->id)
                    ->update($updates);
            }
        }
    }
};
