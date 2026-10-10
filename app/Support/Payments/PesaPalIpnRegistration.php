<?php

namespace App\Support\Payments;

use App\Models\PaymentMethods;
use Illuminate\Support\Facades\DB;

/** Shared provider operation and configuration persistence for web and private CLI. */
final class PesaPalIpnRegistration
{
    public function register(PesaPalConfiguration $configuration, string $url): array
    {
        return (new PesaPalService($configuration))->registerIpn($url, 'GET');
    }

    public function persist(PesaPalConfiguration $configuration, array $entry, string $url, bool $encryptId = false): void
    {
        if (!is_string($entry['ipn_id'] ?? null) || !PesaPalService::isGuid($entry['ipn_id'])
            || ($entry['url'] ?? null) !== $url || ($entry['method'] ?? null) !== 'GET'
            || ($entry['active'] ?? null) !== true || ($entry['status'] ?? null) !== 200) {
            throw new PesaPalException();
        }
        DB::transaction(function () use ($configuration, $entry, $encryptId) {
            $row = PaymentMethods::whereKey($configuration->configurationId)
                ->where('school_id', $configuration->schoolId)->where('name', 'pesapal')->lockForUpdate()->firstOrFail();
            $current = PesaPalConfiguration::forSchool($configuration->schoolId);
            if ($current->cacheKey() !== $configuration->cacheKey()
                || ($current->notificationId !== $configuration->notificationId && $current->notificationId !== $entry['ipn_id'])) {
                throw new PesaPalException();
            }
            // Preserve the existing credential ciphertext; never rewrite secrets.
            $keys = json_decode((string) $row->payment_keys, true, 512, JSON_THROW_ON_ERROR);
            if ($encryptId) {
                $keys['encrypted_notification_id'] = \Illuminate\Support\Facades\Crypt::encryptString(
                    json_encode(['school_id' => $configuration->schoolId, 'notification_id' => $entry['ipn_id']], JSON_THROW_ON_ERROR));
                unset($keys['notification_id']);
            } else {
                $keys['notification_id'] = $entry['ipn_id'];
                unset($keys['encrypted_notification_id']);
            }
            $row->update(['payment_keys' => json_encode($keys, JSON_THROW_ON_ERROR)]);
        });
    }
}
