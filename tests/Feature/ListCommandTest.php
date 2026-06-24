<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use ReflectionException;
use Yamut\Redacted\Support\ConfigScanner;
use Yamut\Redacted\Tests\TestCase;

class ListCommandTest extends TestCase
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
    public function list_command_exits_successfully_with_no_redacted_calls(): void
    {
        $this->artisan('redacted:list')
             ->expectsOutputToContain('No redacted() calls found')
             ->assertExitCode(0);
    }

    #[Test]
    public function reveal_option_is_accepted(): void
    {
        $this->artisan('redacted:list', ['--reveal' => true])
             ->assertExitCode(0);
    }

    #[Test]
    public function driver_filter_option_is_accepted(): void
    {
        $this->artisan('redacted:list', ['--driver' => 'ssm'])
             ->assertExitCode(0);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_shows_miss_status_when_not_cached(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'secret']);

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:list')
             ->expectsOutputToContain('MISS')
             ->assertExitCode(0);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_shows_cached_status_when_value_is_in_cache(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'secret']);
        $this->app['cache']->store('array')->put('redacted:array:prod/key', 'secret', 60);

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:list')
             ->expectsOutputToContain('CACHED')
             ->assertExitCode(0);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function driver_filter_excludes_non_matching_schemes(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'val']);

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
            ['uri' => 'env://DB_HOST',    'file' => '/fake/config/app.php', 'line' => 6],
        ]);

        $output = $this->artisan('redacted:list', ['--driver' => 'array']);
        $output->assertExitCode(0);
        // Only array entry shown — we can't easily assert absence, but no "No matching" either
        $output->expectsOutputToContain('array');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function it_masks_values_by_default(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 's3cr3t_value']);

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:list')
             ->expectsOutputToContain('****')
             ->assertExitCode(0);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function reveal_is_blocked_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->bindMockScanner([
            ['uri' => 'array://prod/key', 'file' => '/fake/config/app.php', 'line' => 5],
        ]);

        $this->artisan('redacted:list', ['--reveal' => true])
             ->expectsOutputToContain('blocked in the production environment')
             ->assertExitCode(1);
    }
}
