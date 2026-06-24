<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Support\ConfigScanner;
use Yamut\Redacted\Tests\TestCase;

class ClearCommandTest extends TestCase
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

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_exits_successfully_with_no_entries(): void
    {
        $this->bindMockScanner([]);

        $this->artisan('redacted:clear')
             ->expectsOutputToContain('Cleared 0 cache entries.')
             ->assertExitCode(0);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    #[Test]
    public function it_clears_matching_cache_entries(): void
    {
        $prefix = 'redacted:';
        $this->app['cache']->store('array')->put($prefix . 'array:prod/key', 'cached-value', 60);

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:clear')
             ->expectsOutputToContain('Cleared 1 cache entries.')
             ->assertExitCode(0);

        $this->assertNull($this->app['cache']->store('array')->get($prefix . 'array:prod/key'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_reports_zero_cleared_when_entries_not_in_cache(): void
    {
        // Scanner finds a URI but it's not in the cache store
        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:clear')
             ->expectsOutputToContain('Cleared 0 cache entries.')
             ->assertExitCode(0);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function static_flag_clears_static_cache(): void
    {
        // Pre-warm static cache
        Resolver::warm(['array:prod/key' => 'static-value']);

        $this->bindMockScanner([]);

        $this->artisan('redacted:clear', ['--static' => true])
             ->expectsOutputToContain('In-process static cache cleared.')
             ->assertExitCode(0);

        // Static cache should now be empty → resolve returns null (no driver config for key)
        $result = Resolver::resolve('array://prod/key');
        $this->assertNull($result);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function without_static_flag_static_cache_is_preserved(): void
    {
        Resolver::warm(['array:prod/key' => 'static-value']);

        $this->bindMockScanner([]);

        $this->artisan('redacted:clear')
             ->assertExitCode(0);

        // Static cache NOT cleared → value still resolves
        $result = Resolver::resolve('array://prod/key');
        $this->assertSame('static-value', $result);
    }
}
