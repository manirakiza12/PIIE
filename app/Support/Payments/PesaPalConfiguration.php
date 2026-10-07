<?php

namespace App\Support\Payments;

use App\Models\PaymentMethods;

final class PesaPalConfiguration
{
    public function __construct(
        public readonly int $schoolId,
        public readonly int $configurationId,
        public readonly string $environment,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        public readonly ?string $notificationId = null,
    ) {
        if ($schoolId < 1 || $configurationId < 1 || ! in_array($environment, ['sandbox', 'live'], true)
            || trim($consumerKey) === '' || trim($consumerSecret) === ''
            || ($notificationId !== null && ! PesaPalService::isGuid($notificationId))) {
            throw new PesaPalException();
        }
    }

    public static function forSchool(int $schoolId): self
    {
        $rows = PaymentMethods::where('school_id', $schoolId)->where('name', 'pesapal')->where('status', 1)->get();
        if ($rows->count() !== 1) { throw new PesaPalException(); }
        $row = $rows->first();
        if ($row->name !== 'pesapal') { throw new PesaPalException(); }
        $keys = json_decode((string) $row->payment_keys, true);
        if (! is_array($keys) || isset($keys['base_url']) || ! is_string($keys['environment'] ?? null)
            || ! is_string($keys['consumer_key'] ?? null) || ! is_string($keys['consumer_secret'] ?? null)
            || (isset($keys['notification_id']) && ! is_string($keys['notification_id']))) {
            throw new PesaPalException();
        }
        return new self($schoolId, (int) $row->id, $keys['environment'], $keys['consumer_key'],
            $keys['consumer_secret'], $keys['notification_id'] ?? null);
    }

    public function credentials(): array
    {
        return ['consumer_key' => $this->consumerKey, 'consumer_secret' => $this->consumerSecret];
    }

    public function cacheKey(): string
    {
        $fingerprint = hash_hmac('sha256', json_encode($this->credentials()), (string) config('app.key'));
        return "pesapal:v1:token:{$this->schoolId}:{$this->configurationId}:{$this->environment}:$fingerprint";
    }

    public function __debugInfo(): array
    {
        return ['schoolId' => $this->schoolId, 'configurationId' => $this->configurationId,
            'environment' => $this->environment, 'credentials' => '[redacted]'];
    }
}
