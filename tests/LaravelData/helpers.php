<?php

declare(strict_types=1);

/**
 * The global helpers `spatie/laravel-data` reaches for, which `illuminate/foundation` normally
 * defines: `app()` (35 call sites in the package), `resolve()` (41), `config()` (29).
 *
 * This package is framework-agnostic and depends on no Laravel skeleton, so the tests that drive
 * laravel-data build their own container (see `LaravelDataContainer`) and supply these four functions.
 * The alternative was `orchestra/testbench` — a whole framework installation as a dev dependency, to
 * reach a pipeline that turns out to need nothing more than what is below.
 *
 * Every declaration is guarded: in an application that DOES have illuminate/foundation, the real ones
 * are already there and must win.
 */

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;

if (!function_exists('app')) {
    /**
     * @param array<string, mixed> $parameters
     */
    function app(?string $abstract = null, array $parameters = []): mixed
    {
        $container = Container::getInstance();

        return $abstract === null ? $container : $container->make($abstract, $parameters);
    }
}

if (!function_exists('resolve')) {
    /**
     * @param array<string, mixed> $parameters
     */
    function resolve(string $name, array $parameters = []): mixed
    {
        return app($name, $parameters);
    }
}

if (!function_exists('config')) {
    /**
     * @param array<string, mixed>|string|null $key
     */
    function config(array|string|null $key = null, mixed $default = null): mixed
    {
        /** @var Repository $repository */
        $repository = Container::getInstance()->make('config');

        if ($key === null) {
            return $repository;
        }

        if (is_array($key)) {
            $repository->set($key);

            return null;
        }

        return $repository->get($key, $default);
    }
}

if (!function_exists('rescue')) {
    /**
     * Laravel's try/catch-as-an-expression. `DateTimeInterfaceCast` uses it to try each candidate date
     * format in turn, so a generated temporal property cannot be hydrated without it.
     *
     * @param callable(): mixed $callback
     */
    function rescue(callable $callback, mixed $rescue = null, bool $report = true): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            // The real helper reports to the exception handler; there is none here, and a swallowed
            // failure is the documented behaviour of the caller (the next format gets its turn).
            return $rescue instanceof Closure ? $rescue($exception) : $rescue;
        }
    }
}

if (!function_exists('request')) {
    /**
     * Reached only by laravel-data's `RouteParameterReference`, which a generated class never emits —
     * shimmed so that a rule referencing a route parameter fails as "no request bound" rather than as a
     * missing function.
     */
    function request(): mixed
    {
        $container = Container::getInstance();

        return $container->bound('request') ? $container->make('request') : null;
    }
}

if (!function_exists('app_path')) {
    /**
     * Only reached by laravel-data's structure-discovery config, which this harness disables.
     */
    function app_path(string $path = ''): string
    {
        return __DIR__ . ($path === '' ? '' : '/' . $path);
    }
}
