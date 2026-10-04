<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

/** Encodes platform SMTP passwords at rest while accepting legacy plain values. */
final class SmtpPasswordSecret
{
    private const PREFIX = 'piie-encrypted:v1:';

    public static function protect(string $password): string
    {
        return self::PREFIX . Crypt::encryptString($password);
    }

    public static function protectStored(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || self::isProtected($stored)) {
            return $stored;
        }

        return self::protect($stored);
    }

    public static function reveal(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        try {
            return Crypt::decryptString(substr($stored, strlen(self::PREFIX)));
        } catch (DecryptException $exception) {
            return null;
        }
    }

    public static function isProtected(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX);
    }
}
