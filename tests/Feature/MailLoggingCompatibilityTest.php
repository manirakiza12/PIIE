<?php

namespace Tests\Feature;

use App\Exceptions\Handler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\TestCase;

class MailLoggingCompatibilityTest extends TestCase
{
    public function test_stream_handler_level_and_formatter_preserve_error_reporting(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('compatibility');
        $logger->pushHandler(new StreamHandler($stream, Logger::ERROR));
        $logger->warning('filtered fixture'); $logger->error('reported fixture', ['event' => 'compatibility']);
        rewind($stream); $text = stream_get_contents($stream); fclose($stream);
        $this->assertStringContainsString('reported fixture', $text);
        $this->assertStringContainsString('compatibility', $text);
        $this->assertStringNotContainsString('filtered fixture', $text);
        $this->assertSame(StreamHandler::class, config('logging.channels.stderr.handler'));
    }

    public function test_generic_exception_reporting_forwards_exception_without_suppressing_it(): void
    {
        Log::spy(); $exception = new \RuntimeException('Synthetic safe report fixture');
        app(Handler::class)->report($exception);
        Log::shouldHaveReceived('error')->once()->with('Synthetic safe report fixture', \Mockery::on(fn ($context) => ($context['exception'] ?? null) === $exception));
        // The general handler is not a global redaction layer. SafeMail's
        // separate diagnostics suites verify redaction before mail logging.
    }

    public function test_sensitive_validation_fields_are_never_flashed(): void
    {
        $property = new \ReflectionProperty(Handler::class, 'dontFlash');
        $property->setAccessible(true); $fields = $property->getValue(app(Handler::class));
        foreach (['current_password', 'password', 'password_confirmation', 'nin'] as $field) { $this->assertContains($field, $fields); }
    }
}
