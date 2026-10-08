<?php

namespace Tests\Feature;

use ErrorException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Tests\TestCase;

class HandlerLifecycleCompatibilityTest extends TestCase
{
    private $initialErrorHandler;
    private $initialExceptionHandler;

    protected function setUp(): void
    {
        $this->initialErrorHandler = get_error_handler();
        $this->initialExceptionHandler = get_exception_handler();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->assertSame($this->initialErrorHandler, get_error_handler(), 'Restore the runner-owned error handler.');
        $this->assertSame($this->initialExceptionHandler, get_exception_handler(), 'Restore the pre-test exception handler.');
    }

    public function test_laravel_error_conversion_remains_enabled(): void
    {
        $this->assertNotSame($this->initialErrorHandler, get_error_handler());
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Synthetic handler lifecycle probe');
        trigger_error('Synthetic handler lifecycle probe', E_USER_WARNING);
    }

    public function test_application_exception_handler_remains_registered(): void
    {
        $this->assertInstanceOf(\App\Exceptions\Handler::class, $this->app->make(ExceptionHandler::class));
        $this->assertNotNull(get_exception_handler());
        $this->assertNotSame($this->initialExceptionHandler, get_exception_handler());
    }
}
