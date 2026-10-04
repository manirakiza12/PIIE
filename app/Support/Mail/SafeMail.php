<?php

namespace App\Support\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;

/**
 * Sends a notification e-mail that follows an already-completed business action
 * (account created, invoice issued, school approved…).
 *
 * If the mail provider fails — wrong SMTP credentials, server down, network — the
 * completed action must not turn into an HTTP 500 (the user would believe it failed
 * and retry, creating duplicates). Only mail-TRANSPORT failures are caught:
 * programming errors (e.g. a broken Mailable) still surface normally. The failure
 * is logged server-side without the recipient address, the message contents or
 * any credential; existing "resend" actions remain the retry path.
 */
final class SafeMail
{
    /** @return bool true when handed to the mailer, false when delivery failed (and was logged). */
    public static function send($to, Mailable $mailable, string $purpose = 'notification'): bool
    {
        try {
            Mail::to($to)->send($mailable);

            return true;
        } catch (TransportExceptionInterface $e) {
            self::logFailure($mailable, $purpose, $e);

            return false;
        } catch (MimeLogicException $e) {
            // Symfony throws this before transport when neither the mailable nor
            // mail.from supplies a sender. Treat precisely this known mail-config
            // failure as undelivered; other MIME logic errors are defects and must
            // continue through the normal exception handling path.
            if ($e->getMessage() !== 'An email must have a "From" or a "Sender" header.') {
                throw $e;
            }

            self::logFailure($mailable, $purpose, $e);

            return false;
        }
    }

    private static function logFailure(Mailable $mailable, string $purpose, \Throwable $exception): void
    {
        $driver = config('mail.default');
        $smtp = config('mail.mailers.'.$driver.'.transport') === 'smtp';
        $host = config('mail.mailers.'.$driver.'.host');
        $port = config('mail.mailers.'.$driver.'.port');
        $encryption = config('mail.mailers.'.$driver.'.encryption');
        // Read the already-built sender. Do not rebuild token-bearing mailables here.
        $sender = $mailable->from[0]['address'] ?? config('mail.from.address');

        Log::error('Mail delivery failed; the completed action was kept', [
            'purpose' => $purpose,
            'mailable' => get_class($mailable),
            'exception' => get_class($exception),
            'transport' => $smtp ? 'smtp' : 'other',
            'smtp_host' => $smtp && is_string($host) && preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $host) ? $host : null,
            'smtp_port' => $smtp && filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ? (int) $port : null,
            'encryption' => $smtp && in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
            'sender_address' => is_string($sender) && filter_var($sender, FILTER_VALIDATE_EMAIL) ? $sender : null,
            'user_id' => auth()->id(),
            'school_id' => auth()->user()->school_id ?? null,
        ] + MailFailureDiagnostic::describe($exception));
    }
}
