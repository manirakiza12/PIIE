<?php

namespace Tests\Feature\Support;

use Illuminate\Database\QueryException;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/** Test fixtures only: no production public paths or exception handling. */
final class FrameworkCompatibility
{
    public static function useTemporaryPublicPath($app, string $path): void
    {
        $resolved = realpath($path);
        $temporary = realpath(sys_get_temp_dir());
        $public = realpath($app->basePath('public'));
        $normalize = static function (string $value): string {
            $value = str_replace('\\', '/', rtrim($value, '/\\'));
            return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        };
        if ($resolved === false || $temporary === false
            || ! str_starts_with($normalize($resolved), $normalize($temporary).'/')
            || ($public !== false && ($normalize($resolved) === $normalize($public)
                || str_starts_with($normalize($resolved), $normalize($public).'/')))) {
            throw new RuntimeException('Public fixtures must use an existing isolated temporary directory.');
        }
        if (method_exists($app, 'usePublicPath')) {
            $app->usePublicPath($path);
        } else {
            // Laravel 9 has no usePublicPath setter; its public_path() helper
            // resolves this binding. Laravel 10+ uses the supported setter.
            $app->instance('path.public', $path);
        }
    }

    public static function queryException(string $sql, array $bindings, Throwable $previous, string $connection): QueryException
    {
        return new QueryException(...self::queryExceptionArguments(QueryException::class, $sql, $bindings, $previous, $connection));
    }

    public static function queryExceptionArguments(string $class, string $sql, array $bindings, Throwable $previous, string $connection): array
    {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();
        if (count($parameters) === 3 && $parameters[0]->getName() === 'sql') {
            return [$sql, $bindings, $previous];
        }
        if (count($parameters) === 4 && $parameters[0]->getName() === 'connectionName') {
            return [$connection, $sql, $bindings, $previous];
        }
        throw new RuntimeException('Unrecognized QueryException constructor.');
    }
}
