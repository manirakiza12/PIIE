<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Tests\Feature\Support\FrameworkCompatibility;
use Tests\TestCase;
use RuntimeException;

class FrameworkCompatibilityTest extends TestCase
{
    private string $temporary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'piie-l2-public-'.uniqid();
        mkdir($this->temporary);
    }

    protected function tearDown(): void
    {
        rmdir($this->temporary);
        parent::tearDown();
    }

    public function test_current_framework_fixture_public_path_is_isolated(): void
    {
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->temporary);
        $this->assertSame($this->temporary, public_path());
        $this->assertNotSame(base_path('public'), public_path());
        $this->assertSame($this->temporary.DIRECTORY_SEPARATOR.'uploads', public_path('uploads'));
    }

    public function test_supported_setter_is_preferred_when_available(): void
    {
        $app = new class(base_path()) {
            public bool $called = false;
            public string $publicPath = '';
            public function __construct(private string $root) {}
            public function basePath(string $path): string { return $this->root.'/'.$path; }
            public function usePublicPath(string $path): void { $this->called = true; $this->publicPath = $path; }
            public function instance(string $key, string $value): void { throw new RuntimeException('Must use supported setter.'); }
        };
        FrameworkCompatibility::useTemporaryPublicPath($app, $this->temporary);
        $this->assertTrue($app->called);
        $this->assertSame($this->temporary, $app->publicPath);
    }

    public function test_real_repository_public_directory_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, base_path('public'));
    }

    public function test_nonexistent_temporary_directory_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, $this->temporary.'/missing');
    }

    public function test_repository_storage_directory_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        FrameworkCompatibility::useTemporaryPublicPath($this->app, storage_path());
    }

    public function test_current_query_exception_preserves_sql_bindings_and_database_error(): void
    {
        $previous = new \PDOException('fixture failure');
        $previous->errorInfo = ['23000', 1062, 'duplicate fixture'];
        $exception = FrameworkCompatibility::queryException('UPDATE fixture SET id = ?', [1], $previous, 'sqlite');
        $this->assertInstanceOf(QueryException::class, $exception);
        $this->assertSame('UPDATE fixture SET id = ?', $exception->getSql());
        $this->assertSame([1], $exception->getBindings());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame($previous->errorInfo, $exception->errorInfo);
    }

    public function test_future_four_argument_constructor_includes_connection_name(): void
    {
        $previous = new \PDOException('fixture');
        $arguments = FrameworkCompatibility::queryExceptionArguments(FutureQueryExceptionFixture::class, 'SELECT ?', [1], $previous, 'mysql');
        $this->assertSame(['mysql', 'SELECT ?', [1], $previous], $arguments);
    }

    public function test_unrecognized_query_exception_constructor_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        FrameworkCompatibility::queryExceptionArguments(UnknownQueryExceptionFixture::class, 'SELECT 1', [], new \PDOException('fixture'), 'sqlite');
    }
}

class FutureQueryExceptionFixture
{
    public function __construct($connectionName, $sql, array $bindings, \Throwable $previous) {}
}

class UnknownQueryExceptionFixture
{
    public function __construct($unknown) {}
}
