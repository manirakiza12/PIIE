<?php

namespace App\Support;

use App\Models\IntakeSession;
use App\Models\Programme;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persistent counters prevent reuse after deletion; the prefix lock serializes allocations. */
final class RegistrationNumber
{
    public static function student(array $data): string
    {
        // Older class-only admissions have no HEI programme or intake.
        if (empty($data['programme_id'])) return student_code();

        return rtrim(self::next(self::studentPrefix($data)), '/');
    }

    public static function studentPrefix(array $data): string
    {

        $programme = Programme::where('school_id', $data['school_id'])->find($data['programme_id']);
        $intake = IntakeSession::where('school_id', $data['school_id'])->find($data['intake_session_id'] ?? 0);
        if (!$programme || !$intake) {
            throw ValidationException::withMessages(['intake_session_id' => 'Select a programme and intake belonging to this school to generate the registration number.']);
        }
        $abbreviation = strtoupper(trim((string) $programme->code));
        if (!preg_match('/\A[A-Z0-9][A-Z0-9-]{0,19}\z/', $abbreviation)) {
            throw ValidationException::withMessages(['programme_id' => 'Set a programme abbreviation using letters, digits or hyphens before enrolling students.']);
        }
        // Applications may open months before studies begin: never use open_date.
        $start = self::intakeStart($intake);
        if (!$start) {
            throw ValidationException::withMessages(['intake_session_id' => 'Set the intake semester start date before enrolling students so their registration year and month are correct.']);
        }
        return Carbon::parse($start)->format('Y/m').'/'.$abbreviation.'/';
    }

    public static function intakeStart(IntakeSession $intake): ?string
    {
        if ($intake->start_date) return (string) $intake->start_date;
        $months = '(January|February|March|April|May|June|July|August|September|October|November|December)';
        if (preg_match('/\b'.$months.'\s+(20\d{2})\b/i', $intake->name, $match)) {
            return Carbon::parse('1 '.$match[1].' '.$match[2])->toDateString();
        }
        if (preg_match('/\b(20\d{2})\s+'.$months.'\b/i', $intake->name, $match)) {
            return Carbon::parse('1 '.$match[2].' '.$match[1])->toDateString();
        }
        return null;
    }

    public static function staff(int $roleId): string
    {
        $role = [2 => 'ADM', 3 => 'TRS', 4 => 'ACC', 5 => 'LIB', 10 => 'WDN', 20 => 'STF'][$roleId] ?? 'STF';
        return self::next(now()->format('Y/m').'/'.$role.'/');
    }

    public static function next(string $prefix): string
    {
        return DB::transaction(function () use ($prefix) {
            DB::table('identity_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0]);
            $counter = DB::table('identity_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            $number = (int) $counter->last_number;
            // Seed from existing/imported identifiers and skip occupied values.
            foreach (DB::table('users')->where('code', 'like', $prefix.'%')->pluck('code') as $code) {
                if (preg_match('#^'.preg_quote($prefix, '#').'(\d+)/?$#', $code, $match)) $number = max($number, (int) $match[1]);
            }
            ++$number;
            DB::table('identity_sequences')->where('prefix', $prefix)->update(['last_number' => $number]);
            return $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT).'/';
        }, 3);
    }
}
