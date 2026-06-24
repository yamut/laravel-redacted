<?php

declare(strict_types=1);

use Yamut\Redacted\Resolution\Resolver;

if (! function_exists('redacted')) {
    /**
     * Resolve a secret store URI to its value.
     *
     * Works at any boot stage, including during config file evaluation
     * (before service providers are registered).
     *
     * @param  string  $uri       A redacted URI, e.g. 'ssm:///prod/myapp/app_key'
     * @param  mixed   $fallback  Returned (or called if Closure) when resolution fails.
     */
    function redacted(string $uri, mixed $fallback = null): mixed
    {
        return Resolver::resolve($uri, $fallback);
    }
}
