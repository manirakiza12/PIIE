<?php

namespace App\Support\Payments;

use App\Models\ApplicationPayment;
use Illuminate\Http\UploadedFile;

/** Private proof storage; historical filenames remain unchanged in the ledger. */
final class ApplicationPaymentProof
{
    public static function path(ApplicationPayment $payment): string
    {
        abort_unless(is_string($payment->proof_file)
            && preg_match('/\A[a-zA-Z0-9_-]+\.(?:pdf|jpg|jpeg|png)\z/i', $payment->proof_file), 404);
        return storage_path('app/application-payment-proofs/' . $payment->proof_file);
    }

    public static function store(UploadedFile $file, int $admissionId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        abort_unless(in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true), 422);
        $name = 'pay' . $admissionId . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = storage_path('app/application-payment-proofs');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Private payment proof storage is unavailable.');
        }
        abort_if(is_link($directory), 503);
        self::assertPrivateDirectory($directory);
        $file->move($directory, $name);
        chmod($directory . '/' . $name, 0600);
        return $name;
    }

    public static function download(ApplicationPayment $payment)
    {
        $private = self::path($payment);
        if (is_dir(dirname($private))) {
            self::assertPrivateDirectory(dirname($private));
        }
        // Authenticated compatibility only; static access must be denied at the web server.
        $legacy = public_path(ApplicationPayment::PROOF_DIR . '/' . $payment->proof_file);
        $path = is_file($private) ? $private : $legacy;
        $directory = realpath(dirname($path));
        $resolved = realpath($path);
        abort_unless($directory && $resolved && is_file($resolved) && ! is_link($path)
            && ! is_link(dirname($path)) && dirname($resolved) === $directory, 404);
        return response()->download($resolved, 'payment-proof.' . pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function assertPrivateDirectory(string $directory): void
    {
        $resolved = realpath($directory);
        $public = realpath(public_path());
        $storage = realpath(storage_path('app'));
        abort_unless($resolved && $storage && $public
            && str_starts_with(strtolower($resolved), strtolower($storage . DIRECTORY_SEPARATOR))
            && ! str_starts_with(strtolower($resolved . DIRECTORY_SEPARATOR), strtolower($public . DIRECTORY_SEPARATOR)), 503);
    }
}
