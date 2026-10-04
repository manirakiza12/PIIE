<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolves the platform sender from existing settings without inventing an address. */
final class PlatformMailIdentity
{
    public static function fromAddress(): ?string
    {
        foreach ([
            self::setting('system_email'),
            config('mail.from.address'),
            self::setting('smtp_user'),
        ] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            // Ignore Laravel's illustrative default: it is not a configured sender.
            if (strcasecmp($candidate, 'hello@example.com') === 0) {
                continue;
            }
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function fromName(): string
    {
        $name = self::setting('system_title') ?: config('mail.from.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'PIIE';
    }

    private static function setting(string $key): ?string
    {
        if (!Schema::hasTable('global_settings')) {
            return null;
        }

        $value = DB::table('global_settings')->where('key', $key)->value('value');

        return is_string($value) ? $value : null;
    }
}
