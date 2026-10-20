<?php

namespace App\Support\ProgrammeCatalogue;

use App\Models\Programme;

class ProgrammeLevels
{
    public static function options(int $schoolId): array
    {
        return Programme::LEVELS;
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
