<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Support\ConfigScanner;
use Yamut\Redacted\Tests\TestCase;

class CacheCommandTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    private function bindMockScanner(array $entries): void
    {
        $this->app->bind(ConfigScanner::class, function () use ($entries) {
            $mock = $this->createMock(ConfigScanner::class);
            $mock->method('scan')->willReturn($entries);
            return $mock;
        });
    }

    #[Test]
    public function cache_command_exits_successfully_with_no_redacted_calls(): void
    {
        $this->artisan('redacted:cache')
             ->expectsOutputToContain('No redacted() calls found')
             ->assertExitCode(0);
    }

    #[Test]
    public function dry_run_flag_is_accepted(): void
    {
        $this->artisan('redacted:cache', ['--dry-run' => true])
             ->assertExitCode(0);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Test]
    public function dry_run_does_not_populate_cache(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'secret']);
        Resolver::clearStaticCache();

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache', ['--dry-run' => true])->assertExitCode(0);

        $cached = $this->app['cache']->store('array')->get('redacted:array:prod/key');
        $this->assertNull($cached, '--dry-run should not write to cache');
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Test]
    public function it_fetches_and_caches_secrets(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'secret-value']);
        Resolver::clearStaticCache();

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache')
             ->expectsOutputToContain('Cached 1 secret(s). 0 failed or not found.')
             ->assertExitCode(0);

        $cached = $this->app['cache']->store('array')->get('redacted:array:prod/key');
        $this->assertSame('secret-value', $cached);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_reports_not_found_when_driver_returns_null(): void
    {
        // Driver has no value for this key
        $this->app['config']->set('redacted.drivers.array.values', []);

        $this->bindMockScanner([
            ['uri' => 'array://prod/missing', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache')
             ->expectsOutputToContain('1 failed or not found')
             ->assertExitCode(1);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_warms_static_cache_after_fetching(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'warm-value']);
        Resolver::clearStaticCache();

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache')->assertExitCode(0);

        // Now clear the driver so only static cache can serve the value
        $this->app['config']->set('redacted.drivers.array.values', []);
        $this->app->forgetInstance('redacted');
        // Also flush L2 so only L1 (static) can serve
        $this->app['cache']->store('array')->flush();

        $result = Resolver::resolve('array://prod/key');
        $this->assertSame('warm-value', $result);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function warm_and_cache_keys_use_same_format(): void
    {
        // Verifies CacheCommand's $cacheKey and Resolver::warm() key format are consistent.
        // CacheCommand: $cacheKey = $scheme . ':' . $path → warm([$cacheKey => $value])
        // Resolver: $cacheKey = $scheme . ':' . $parsed['path']
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'value']);
        Resolver::clearStaticCache();

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache')->assertExitCode(0);

        // flush L2, keep L1 (static cache warm'd by the command)
        $this->app['cache']->store('array')->flush();

        $this->assertSame('value', Resolver::resolve('array://prod/key'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_skips_invalid_uris_without_crashing(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'secret-value']);
        Resolver::clearStaticCache();

        $this->bindMockScanner([
            ['uri' => 'not-a-uri',        'file' => '/fake/config/app.php', 'line' => 3],
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:cache')
             ->expectsOutputToContain("Skipping invalid URI 'not-a-uri'")
             ->expectsOutputToContain('Cached 1 secret(s). 1 failed or not found.')
             ->assertExitCode(1);
    }
}
