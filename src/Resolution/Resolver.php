<?php

declare(strict_types=1);

namespace Yamut\Redacted\Resolution;

use Closure;
use Throwable;
use Yamut\Redacted\Contracts\DriverInterface;
use Yamut\Redacted\DriverFactory;
use Yamut\Redacted\Support\EnvCaster;

/**
 * Static resolution engine for the redacted() helper.
 *
 * This class is intentionally static so that it works at any boot stage,
 * including during LoadConfiguration bootstrapper (before service providers
 * are registered). The container-bound Manager is used when available;
 * otherwise drivers are instantiated directly from disk config.
 *
 * Three-layer cache:
 *   1. self::$cache (in-process, process-lifetime)
 *   2. Laravel cache store (survives worker recycling, configurable TTL)
 *   3. Remote driver fetch
 *
 * Cache key: "{scheme}:{path}" — the #json_key fragment is excluded so that
 * asm://prod/db#host and asm://prod/db#password share one cached raw blob
 * (one API call), with key extraction applied on every read.
 */
class Resolver
{
    /**
     * Fallback cache-key prefix, used only when config omits cache.prefix.
     * Every consumer of the Laravel cache layer (Resolver, RedactedManager,
     * console commands) must use this same default so they address the same keys.
     */
    public const DEFAULT_CACHE_PREFIX = 'redacted:';

    /**
     * In-process static cache. Key: "{scheme}:{path}", value: raw string from driver.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    /**
     * Tracks which cache keys have been persisted to the Laravel cache this process.
     * Used to lazy-persist values that were fetched during early boot (LoadConfiguration),
     * before the cache service provider registered and writeToLaravelCache was a no-op.
     *
     * @var array<string, true>
     */
    private static array $persisted = [];

    /**
     * Config loaded from disk during early boot (before container config is populated).
     */
    private static ?array $diskConfig = null;

    /**
     * Fake drivers registered via fake() for test isolation.
     * Checked before the Manager and before disk-config instantiation so that
     * fake() works even when redacted() is called during LoadConfiguration
     * (before service providers register and before app('redacted') is bound).
     *
     * @var array<string, DriverInterface>
     */
    private static array $fakeDrivers = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public static function resolve(string $uri, mixed $fallback = null): mixed
    {
        try {
            $parsed   = UriParser::parse($uri);
            $cacheKey = $parsed['scheme'] . ':' . $parsed['path'];

            // Layer 1: static cache
            if (array_key_exists($cacheKey, self::$cache)) {
                // Lazy-persist to the Laravel cache if this value was fetched during
                // early boot (LoadConfiguration), before the cache service provider
                // registered and writeToLaravelCache was a no-op.
                if (self::$cache[$cacheKey] !== null && !isset(self::$persisted[$cacheKey])) {
                    if (self::writeToLaravelCache($cacheKey, self::$cache[$cacheKey])) {
                        self::$persisted[$cacheKey] = true;
                    }
                }
                return self::extractValue(self::$cache[$cacheKey], $parsed['json_key'], $fallback);
            }

            // Layer 2: Laravel cache store (only when app cache is available)
            $stored = self::readFromLaravelCache($cacheKey);
            if ($stored !== null) {
                self::$cache[$cacheKey]     = $stored;
                self::$persisted[$cacheKey] = true;
                return self::extractValue($stored, $parsed['json_key'], $fallback);
            }

            // Layer 3: fetch from driver
            $driver = self::resolveDriver($parsed['scheme']);
            $raw    = $driver->get($parsed['path']);

            // Cache the raw value regardless — including null, so a missing secret
            // doesn't re-hit the remote store on every subsequent call.
            self::$cache[$cacheKey] = $raw;

            if ($raw !== null) {
                if (self::writeToLaravelCache($cacheKey, $raw)) {
                    self::$persisted[$cacheKey] = true;
                }
                return self::extractValue($raw, $parsed['json_key'], $fallback);
            }
        } catch (Throwable $e) {
            // Log at error level so infrastructure failures are observable.
            // Logger may not be available during early boot — fail silently if so.
            try {
                if (function_exists('app') && app()->bound('log')) {
                    app('log')->error('redacted: resolution failed, using fallback', [
                        'uri'       => $uri,
                        'exception' => get_class($e),
                        'message'   => $e->getMessage(),
                    ]);
                }
            } catch (Throwable) {
            }
        }

        return self::evaluateFallback($fallback);
    }

    /**
     * Warm the static cache from an array of pre-fetched values.
     * Used by the redacted:cache command after batch-fetching from drivers.
     *
     * @internal  Not part of the public API. Call only from CacheCommand.
     * @param array<string, string|null> $values  ["{scheme}:{path}" => raw_value]
     */
    public static function warm(array $values): void
    {
        foreach ($values as $key => $value) {
            self::$cache[$key]     = $value;
            self::$persisted[$key] = true;
        }
    }

    /**
     * Register a fake driver for a scheme. Checked before the Manager and before
     * disk-config instantiation, making fake() effective during early-boot resolution.
     * Called by RedactedManager::fake() after clearing static cache.
     */
    public static function setFakeDriver(string $scheme, DriverInterface $driver): void
    {
        self::$fakeDrivers[$scheme] = $driver;
    }

    /**
     * Clear the in-process static cache, the cached disk config, and all fake drivers.
     * Call in tests (tearDown) and via redacted:clear --static.
     */
    public static function clearStaticCache(): void
    {
        self::$cache       = [];
        self::$persisted   = [];
        self::$diskConfig  = null;
        self::$fakeDrivers = [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function extractValue(?string $raw, ?string $jsonKey, mixed $fallback): mixed
    {
        if ($raw === null) {
            return self::evaluateFallback($fallback);
        }

        if ($jsonKey === null) {
            return EnvCaster::cast($raw);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::evaluateFallback($fallback);
        }

        return array_key_exists($jsonKey, $decoded)
            ? $decoded[$jsonKey]
            : self::evaluateFallback($fallback);
    }

    private static function evaluateFallback(mixed $fallback): mixed
    {
        return $fallback instanceof Closure ? ($fallback)() : $fallback;
    }

    private static function readFromLaravelCache(string $cacheKey): ?string
    {
        try {
            if (!function_exists('app') || !app()->bound('cache')) {
                return null;
            }

            $config = self::getConfig();
            $store  = $config['cache']['store'] ?? null;
            $prefix = $config['cache']['prefix'] ?? self::DEFAULT_CACHE_PREFIX;

            return app('cache')->store($store)->get($prefix . $cacheKey);
        } catch (Throwable) {
            return null;
        }
    }

    private static function writeToLaravelCache(string $cacheKey, string $value): bool
    {
        try {
            if (!function_exists('app') || !app()->bound('cache')) {
                return false;
            }

            $config = self::getConfig();
            $store  = $config['cache']['store'] ?? null;
            $ttl    = (int) ($config['cache']['ttl'] ?? 3600);
            $prefix = $config['cache']['prefix'] ?? self::DEFAULT_CACHE_PREFIX;

            app('cache')->store($store)->put($prefix . $cacheKey, $value, $ttl);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @throws Throwable
     */
    private static function resolveDriver(string $scheme): DriverInterface
    {
        // Fake drivers take absolute priority — effective even during early-boot
        // config loading before the service provider registers app('redacted').
        if (isset(self::$fakeDrivers[$scheme])) {
            return self::$fakeDrivers[$scheme];
        }

        // Prefer the container-bound Manager (post-service-provider registration)
        if (function_exists('app') && app()->bound('redacted')) {
            return app('redacted')->driver($scheme);
        }

        // Early-boot fallback: construct driver directly from disk config
        return self::makeDriverFromDiskConfig($scheme);
    }

    /**
     * @throws Throwable
     */
    private static function makeDriverFromDiskConfig(string $scheme): DriverInterface
    {
        $config       = self::getConfig();
        $drivers      = $config['drivers'] ?? [];
        $driverConfig = $drivers[$scheme] ?? null;

        if ($driverConfig === null) {
            $default      = $config['default'] ?? 'env';
            $driverConfig = $drivers[$default] ?? ['driver' => 'env'];
        }

        $driverName = $driverConfig['driver'] ?? $scheme;

        return DriverFactory::make($driverName, $driverConfig);
    }

    /**
     * Load the package configuration.
     *
     * Priority:
     *   1. Container config (fastest — works once redacted.php has been loaded)
     *   2. Cached disk read (self::$diskConfig)
     *   3. App's config/redacted.php from disk (published config)
     *   4. Package's own config/redacted.php (fallback before vendor:publish)
     *
     * config/redacted.php is loaded after config/app.php alphabetically, so
     * during early boot app('config')->get('redacted') returns null even though
     * the container is available. We read the file directly in that case.
     * @throws Throwable
     */
    private static function getConfig(): array
    {
        // Container config is available and populated
        if (function_exists('app') && app()->bound('config')) {
            $containerConfig = app('config')->get('redacted');
            if ($containerConfig !== null) {
                return $containerConfig;
            }
        }

        if (self::$diskConfig !== null) {
            return self::$diskConfig;
        }

        // Package's own default config (two levels up from src/Resolution/)
        $packageDefaultPath = dirname(__DIR__, 2) . '/config/redacted.php';
        $packageDefault     = file_exists($packageDefaultPath) ? require $packageDefaultPath : [];

        // App's published config/redacted.php — merge over the package default,
        // matching what mergeConfigFrom() does during register(), so both boot
        // phases produce the same effective config.
        $publishedPath = null;
        if (function_exists('base_path')) {
            try {
                $candidate = base_path('config/redacted.php');
                if (
                    $candidate && file_exists($candidate)
                    && realpath($candidate) !== realpath($packageDefaultPath)
                ) {
                    $publishedPath = $candidate;
                }
            } catch (Throwable) {
            }
        }

        self::$diskConfig = $publishedPath !== null
            ? array_replace_recursive($packageDefault, require $publishedPath)
            : $packageDefault;

        return self::$diskConfig;
    }
}
