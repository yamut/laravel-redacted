<?php

declare(strict_types=1);

namespace Yamut\Redacted;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Yamut\Redacted\Contracts\DriverInterface;
use Yamut\Redacted\Drivers\ArrayDriver;
use Yamut\Redacted\Drivers\AsmDriver;
use Yamut\Redacted\Drivers\AkvDriver;
use Yamut\Redacted\Drivers\DopplerDriver;
use Yamut\Redacted\Drivers\EnvDriver;
use Yamut\Redacted\Drivers\GcpDriver;
use Yamut\Redacted\Drivers\InfisicalDriver;
use Yamut\Redacted\Drivers\SsmDriver;
use Yamut\Redacted\Drivers\VaultDriver;
use Yamut\Redacted\DriverFactory;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Resolution\UriParser;

/**
 * @mixin DriverInterface
 */
class RedactedManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('redacted.default', 'env');
    }

    /**
     * Resolve a driver by scheme name, returning a cached instance.
     */
    public function driver($driver = null): DriverInterface
    {
        return parent::driver($driver ?? $this->getDefaultDriver());
    }

    /**
     * Override to extract per-scheme config and pass it to the create method.
     * The base Manager::createDriver() passes no arguments; we inject config here.
     */
    protected function createDriver($driver): DriverInterface
    {
        $config = $this->config->get("redacted.drivers.{$driver}", ['driver' => $driver]);
        $method = 'create' . Str::studly($driver) . 'Driver';

        if (method_exists($this, $method)) {
            return $this->$method($config);
        }

        // Pass both $app and the resolved per-driver config so custom creators
        // don't have to call app('config') themselves (which fails during early boot).
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($this->container, $config);
        }

        return DriverFactory::make($driver, $config);
    }

    public function createSsmDriver(array $config): SsmDriver
    {
        return new SsmDriver($config);
    }

    public function createAsmDriver(array $config): AsmDriver
    {
        return new AsmDriver($config);
    }

    public function createAkvDriver(array $config): AkvDriver
    {
        return new AkvDriver($config);
    }

    public function createGcpDriver(array $config): GcpDriver
    {
        return new GcpDriver($config);
    }

    public function createVaultDriver(array $config): VaultDriver
    {
        return new VaultDriver($config);
    }

    public function createInfisicalDriver(array $config): InfisicalDriver
    {
        return new InfisicalDriver($config);
    }

    public function createDopplerDriver(array $config): DopplerDriver
    {
        return new DopplerDriver($config);
    }

    public function createEnvDriver(array $config): EnvDriver
    {
        return new EnvDriver($config);
    }

    public function createArrayDriver(array $config): ArrayDriver
    {
        return new ArrayDriver($config);
    }

    /**
     * Testing helper: swap all referenced schemes to an ArrayDriver populated
     * with the given URI → value map. Clears the static resolver cache.
     *
     * Usage:
     *   Redacted::fake([
     *       'ssm:///prod/app/key'    => 'test-app-key',
     *       'asm://prod/db#password' => 'test-password',
     *   ]);
     *
     * @param array<string, string> $uriToValueMap  Full URIs mapped to their resolved string values.
     */
    public function fake(array $uriToValueMap): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Redacted::fake() cannot be called in the production environment.');
        }

        // Group entries by path so that multiple fragment-keyed URIs targeting
        // the same blob (e.g. asm://prod/db#host and asm://prod/db#pass)
        // are merged into a single JSON blob stored under that path.
        $blobs         = [];  // path => ['keys' => [key => val], 'raw' => val|null]
        $schemesByPath = [];  // path => [scheme, ...]
        $schemes       = [];

        foreach ($uriToValueMap as $uri => $value) {
            $parsed  = UriParser::parse($uri);
            $path    = $parsed['path'];
            $scheme  = $parsed['scheme'];
            $jsonKey = $parsed['json_key'];

            $schemes[]                = $scheme;
            $schemesByPath[$path][]   = $scheme;

            if ($jsonKey !== null) {
                $blobs[$path]['keys'][$jsonKey] = $value;
            } else {
                $blobs[$path]['raw'] = $value;
            }
        }

        // Clear stale Laravel cache entries for all affected keys
        foreach ($schemesByPath as $path => $pathSchemes) {
            foreach (array_unique($pathSchemes) as $scheme) {
                $this->forgetLaravelCacheEntry($scheme, $path);
            }
        }

        // Build path => raw_value map:
        // - Fragment-keyed URIs are stored as a JSON blob so extractValue() can decode them
        // - Plain URIs are stored as-is
        // - Mixing both for the same path is a test-setup error — fail loudly.
        $pathValues = [];
        foreach ($blobs as $path => $blob) {
            if (!empty($blob['keys']) && isset($blob['raw'])) {
                throw new InvalidArgumentException(
                    "Redacted::fake() received both a plain URI and fragment-keyed URIs for \"{$path}\". " .
                    'Provide either a plain URI or fragment URIs for a given path, not both.'
                );
            }

            if (!empty($blob['keys'])) {
                $pathValues[$path] = json_encode($blob['keys']);
            } else {
                $pathValues[$path] = $blob['raw'] ?? null;
            }
        }

        $arrayDriver = new ArrayDriver(['driver' => 'array', 'values' => $pathValues]);

        // Clear stale static cache and any previously registered fake drivers first.
        Resolver::clearStaticCache();

        $this->drivers['array'] = $arrayDriver;

        foreach (array_unique($schemes) as $scheme) {
            $this->drivers[$scheme] = $arrayDriver;
            // Register in Resolver's static fake-driver registry so that early-boot
            // redacted() calls (before app('redacted') is bound) are also intercepted.
            Resolver::setFakeDriver($scheme, $arrayDriver);
        }
    }

    private function forgetLaravelCacheEntry(string $scheme, string $path): void
    {
        try {
            if (!function_exists('app') || !app()->bound('cache') || !app()->bound('config')) {
                return;
            }
            $cacheConfig = app('config')->get('redacted.cache', []);
            $store       = $cacheConfig['store'] ?? null;
            $prefix      = $cacheConfig['prefix'] ?? ('redacted:' . config('app.name', 'laravel') . ':');
            app('cache')->store($store)->forget($prefix . $scheme . ':' . $path);
        } catch (\Throwable) {
        }
    }
}
