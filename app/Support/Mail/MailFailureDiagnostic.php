<?php

namespace App\Support\Mail;

use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/** Never persist provider text, debug transcripts, previous exceptions or message data. */
final class MailFailureDiagnostic
{
    public static function describe(\Throwable $exception): array
    {
        $message = $exception->getMessage();
        $category = 'UNKNOWN';
        $stage = 'UNKNOWN';
        $reason = 'Mail delivery failed; the provider detail was withheld.';
        $code = null;

        // Only typed SMTP replies are codes; an arbitrary exception code is not a reply.
        if ($exception instanceof UnexpectedResponseException
            && $exception->getCode() >= 400 && $exception->getCode() <= 599) {
            $code = (int) $exception->getCode();
            $reason = 'The SMTP server returned an unsuccessful response; the command is unknown.';
            // A 250 reply is expected at several stages, so never infer MAIL FROM/RCPT TO.
        }

        if (str_starts_with($message, 'Failed to authenticate on SMTP server')
            || str_starts_with($message, 'Failed to find an authenticator supported by the SMTP server')) {
            [$category, $stage, $reason] = ['SMTP_AUTHENTICATION_FAILURE', 'SMTP authentication', 'SMTP authentication failed.'];
            // Extract only a numeric actual reply, never the surrounding AUTH exchange.
            if (preg_match('/but got code "([45][0-9]{2})"/', $message, $matches)) {
                $code = (int) $matches[1];
            }
        } elseif (str_starts_with($message, 'Unable to connect with STARTTLS')) {
            $certificate = str_contains(strtolower($message), 'certificate verify failed');
            [$category, $stage, $reason] = $certificate
                ? ['CERTIFICATE_FAILURE', 'TLS negotiation', 'TLS certificate verification failed.']
                : ['TLS_NEGOTIATION_FAILURE', 'TLS negotiation', 'STARTTLS negotiation failed.'];
        } elseif (str_starts_with($message, 'Connection could not be established with host')) {
            $lower = strtolower($message);
            if (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'php_network_getaddresses')) {
                [$category, $stage, $reason] = ['DNS_FAILURE', 'DNS resolution', 'The SMTP hostname could not be resolved.'];
            } elseif (str_contains($lower, 'refused')) {
                [$category, $stage, $reason] = ['CONNECTION_REFUSED', 'TCP connection', 'The SMTP connection was refused.'];
            } elseif (str_contains($lower, 'timed out')) {
                [$category, $stage, $reason] = ['CONNECTION_TIMEOUT', 'TCP connection', 'The SMTP connection timed out.'];
            } else {
                $reason = 'The SMTP connection could not be established.';
            }
        } elseif ($message === 'An email must have a "From" or a "Sender" header.') {
            [$category, $stage, $reason] = ['CONFIGURATION_MAPPING_FAILURE', 'Message preparation', 'No sender identity is configured.'];
        }

        return ['failure_class' => $category, 'failure_stage' => $stage,
            'sanitized_reason' => $reason, 'smtp_response_code' => $code];
    }
}
