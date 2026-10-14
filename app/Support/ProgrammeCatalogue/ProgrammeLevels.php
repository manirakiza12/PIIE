<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProgrammeLevels
{
    public static function options(int $schoolId): array
    {
        $levels = array_merge(Programme::LEVELS, Programme::LEVELS_LEGACY);
        if (Schema::hasTable('programme_levels')) {
            $levels = array_merge($levels, DB::table('programme_levels')->where('school_id', $schoolId)->orderBy('name')->pluck('name')->all());
        }
        return array_values(array_unique($levels));
    }

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function key(string $name): string
    {
        return hash('sha256', mb_strtolower(self::normalize($name), 'UTF-8'));
    }
}
