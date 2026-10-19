<?php

namespace App\Support\Academic;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Human-facing calendar dates use day/month/year; storage keeps ISO dates. */
final class AcademicDate
{
    public static function input($value): string
    {
        if ($value instanceof DateTimeInterface) return $value->format('d/m/y');
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date && $date->format('Y-m-d') === $value) return $date->format('d/m/y');
        }
        return is_string($value) ? $value : '';
    }

    public static function normalize(Request $request): void
    {
        $dates = [];
        foreach (['start_date', 'end_date'] as $field) {
            $value = $request->input($field);
            if ($value === null || $value === '') continue;
            if (! is_string($value)) {
                throw ValidationException::withMessages([$field => 'Enter a valid date as DD/MM/YY, for example 01/10/26.']);
            }
            $value = trim($value);
            $format = preg_match('/^\d{2}\/\d{2}\/\d{2}$/D', $value) ? 'd/m/y'
                : (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? 'Y-m-d' : null);
            if ($format === null) {
                throw ValidationException::withMessages([$field => 'Enter a valid date as DD/MM/YY, for example 01/10/26.']);
            }
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if (! $date || $date->format($format) !== $value) {
                throw ValidationException::withMessages([$field => 'Enter a valid date as DD/MM/YY, for example 01/10/26.']);
            }
            $dates[$field] = $date->format('Y-m-d');
        }
        $request->merge($dates);
    }
}
