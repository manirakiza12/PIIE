<?php

namespace Tests\Feature;

use App\Mail\GenericStaffPasswordSetupMail;
use App\Mail\PlatformMailTestMessage;
use App\Support\Mail\MailFailureDiagnostic;
use App\Support\Mail\PlatformMailIdentity;
use App\Support\Mail\SafeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class SafeMailDiagnosticsTest extends TestCase
{
    use AdmissionsTestHelper;

    public function test_error_threshold_records_safe_diagnostics_for_both_mailables_without_secrets(): void
    {
        $this->bootAdmissionsTestSchema();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('global_settings')->insert([
            ['key' => 'system_email', 'value' => 'configured@example.test'],
            ['key' => 'system_title', 'value' => 'Configured Platform'],
        ]);
        $password = 'Synthetic-Password-Only-987!';
        $token = 'Synthetic-Setup-Token-456';
        $url = 'https://example.test/password/reset/'.$token.'?email=private@example.test';
        $auth = base64_encode("\0private@example.test\0".$password);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
            'transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
            'encryption' => 'tls', 'username' => 'private@example.test', 'password' => $password,
        ]]);
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('diagnostic-test');
        $logger->pushHandler(new StreamHandler($stream, Logger::ERROR));
        Log::swap(new \Illuminate\Log\Logger($logger));
        $exception = new TransportException('Failed to authenticate on SMTP server with username "private@example.test". Expected response code "235" but got code "535". '.$password.' '.$token.' '.$url.' AUTH '.$auth);
        $exception->appendDebug($url.' '.$password);
        Mail::shouldReceive('to')->twice()->andThrow($exception);
        foreach ([new PlatformMailTestMessage(), new GenericStaffPasswordSetupMail('Private Name', $url)] as $mail) {
            $mail->build();
            $this->assertSame(PlatformMailIdentity::fromAddress(), $mail->from[0]['address']);
            $this->assertSame('configured@example.test', $mail->from[0]['address']);
            $this->assertFalse(SafeMail::send('private@example.test', $mail, 'platform-smtp-test'));
        }
        rewind($stream);
        $output = stream_get_contents($stream);
        $this->assertSame(2, substr_count($output, 'Mail delivery failed'));
        foreach ([$password, $token, $url, $auth, 'private@example.test', 'Private Name', 'Expected response code', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        foreach (['TransportException', 'SMTP authentication failed.', 'smtp.example.test', 'configured@example.test', '535', 'SMTP_AUTHENTICATION_FAILURE'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    public function test_known_failures_are_classified_but_unknown_or_ambiguous_stages_are_not_invented(): void
    {
        $cases = [
            ['Connection could not be established with host "smtp.test": php_network_getaddresses: getaddrinfo failed', 'DNS_FAILURE', 'DNS resolution'],
            ['Connection could not be established with host "smtp.test": Connection refused', 'CONNECTION_REFUSED', 'TCP connection'],
            ['Connection could not be established with host "smtp.test": Connection timed out', 'CONNECTION_TIMEOUT', 'TCP connection'],
            ['Unable to connect with STARTTLS: certificate verify failed', 'CERTIFICATE_FAILURE', 'TLS negotiation'],
            ['Unable to connect with STARTTLS.', 'TLS_NEGOTIATION_FAILURE', 'TLS negotiation'],
            ['private token https://example.test/setup/secret', 'UNKNOWN', 'UNKNOWN'],
        ];
        foreach ($cases as [$message, $category, $stage]) {
            $diagnostic = MailFailureDiagnostic::describe(new TransportException($message));
            $this->assertSame($category, $diagnostic['failure_class']);
            $this->assertSame($stage, $diagnostic['failure_stage']);
            $this->assertNull($diagnostic['smtp_response_code']);
            $this->assertStringNotContainsString($message, json_encode($diagnostic));
        }
        $diagnostic = MailFailureDiagnostic::describe(new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 private details".', 550));
        $this->assertSame(550, $diagnostic['smtp_response_code']);
        $this->assertSame('UNKNOWN', $diagnostic['failure_stage']);
        $this->assertStringNotContainsString('private details', json_encode($diagnostic));
        $this->assertNull(MailFailureDiagnostic::describe(new TransportException('opaque', 535))['smtp_response_code']);
    }

    public function test_success_still_returns_true_without_a_failure_log(): void
    {
        Mail::fake();
        Log::spy();
        $this->assertTrue(SafeMail::send('synthetic@example.test', new PlatformMailTestMessage(), 'platform-smtp-test'));
        Mail::assertSent(PlatformMailTestMessage::class);
        Log::shouldNotHaveReceived('error');
    }
}
