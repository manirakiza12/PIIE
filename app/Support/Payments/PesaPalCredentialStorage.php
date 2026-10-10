<?php

namespace App\Support\Payments;

use Illuminate\Support\Facades\Crypt;

/** Only PesaPal secrets are encrypted; metadata and other gateways stay unchanged. */
final class PesaPalCredentialStorage
{
    public static function protect(string $json, int $school): string
    {
        $data = json_decode($json, true);
        if (! is_array($data)) { return $json; }
        if (array_key_exists('consumer_key', $data) || array_key_exists('consumer_secret', $data)) {
            $data['encrypted_credentials'] = Crypt::encryptString(json_encode([
                'school_id' => $school, 'consumer_key' => $data['consumer_key'] ?? null,
                'consumer_secret' => $data['consumer_secret'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $data['credential_format'] = 1;
            unset($data['consumer_key'], $data['consumer_secret']);
        }
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    public static function read(string $json, int $school): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) { throw new PesaPalException(); }
            if (isset($data['encrypted_credentials'])) {
                if (($data['credential_format'] ?? null) !== 1
                    || isset($data['consumer_key']) || isset($data['consumer_secret'])) { throw new PesaPalException(); }
                $secrets = json_decode(Crypt::decryptString($data['encrypted_credentials']), true, 512, JSON_THROW_ON_ERROR);
                if (($secrets['school_id'] ?? null) !== $school) { throw new PesaPalException(); }
                $data['consumer_key'] = $secrets['consumer_key'] ?? null;
                $data['consumer_secret'] = $secrets['consumer_secret'] ?? null;
            }
            // Legacy plaintext is read-only compatible until an explicitly approved conversion.
            if (isset($data['encrypted_notification_id'])) {
                if (isset($data['notification_id'])) { throw new PesaPalException(); }
                $notification = json_decode(Crypt::decryptString($data['encrypted_notification_id']), true, 512, JSON_THROW_ON_ERROR);
                if (($notification['school_id'] ?? null) !== $school
                    || !is_string($notification['notification_id'] ?? null)
                    || !PesaPalService::isGuid($notification['notification_id'])) { throw new PesaPalException(); }
                $data['notification_id'] = $notification['notification_id'];
            }
            return $data;
        } catch (\Throwable $exception) {
            throw new PesaPalException();
        }
    }
}
