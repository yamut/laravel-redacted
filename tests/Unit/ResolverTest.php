<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Tests\TestCase;

class ResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_a_value_via_array_driver(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/myapp/key' => 'secret_value',
        ]);
        Resolver::clearStaticCache();

        $result = Resolver::resolve('array://prod/myapp/key');
        $this->assertSame('secret_value', $result);
    }

    #[Test]
    public function it_returns_scalar_fallback_when_not_found(): void
    {
        $result = Resolver::resolve('array://nonexistent', 'fallback_value');
        $this->assertSame('fallback_value', $result);
    }

    #[Test]
    public function it_returns_null_fallback_when_not_found_and_no_fallback(): void
    {
        $result = Resolver::resolve('array://nonexistent');
        $this->assertNull($result);
    }

    #[Test]
    public function it_evaluates_closure_fallback_lazily(): void
    {
        $called = 0;
        $result = Resolver::resolve('array://nonexistent', function () use (&$called) {
            $called++;
            return 'computed';
        });

        $this->assertSame('computed', $result);
        $this->assertSame(1, $called);
    }

    #[Test]
    public function closure_fallback_is_not_called_on_hit(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/key' => 'real_value',
        ]);
        Resolver::clearStaticCache();

        $called = 0;
        $result = Resolver::resolve('array://prod/key', function () use (&$called) {
            $called++;
            return 'should_not_be_called';
        });

        $this->assertSame('real_value', $result);
        $this->assertSame(0, $called);
    }

    #[Test]
    public function it_extracts_a_json_key_from_a_blob_secret(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/myapp/db' => '{"host":"db.example.com","password":"s3cr3t","port":5432}',
        ]);
        Resolver::clearStaticCache();

        $this->assertSame('db.example.com', Resolver::resolve('array://prod/myapp/db#host'));
        $this->assertSame('s3cr3t', Resolver::resolve('array://prod/myapp/db#password'));
        $this->assertSame(5432, Resolver::resolve('array://prod/myapp/db#port'));
    }

    #[Test]
    public function missing_json_key_returns_fallback(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/db' => '{"host":"db.example.com"}',
        ]);
        Resolver::clearStaticCache();

        $result = Resolver::resolve('array://prod/db#nonexistent_key', 'default');
        $this->assertSame('default', $result);
    }

    #[Test]
    public function non_json_value_with_json_key_returns_fallback(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/key' => 'plain-string-not-json',
        ]);
        Resolver::clearStaticCache();

        $result = Resolver::resolve('array://prod/key#some_key', 'fallback');
        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function it_populates_static_cache_on_first_resolve(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/key' => 'value1',
        ]);
        Resolver::clearStaticCache();

        // First call hits the driver
        $first = Resolver::resolve('array://prod/key');
        $this->assertSame('value1', $first);

        // Mutate driver config — static cache should shield from re-fetch
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'value2']);
        // Note: the Manager singleton caches the driver instance too, so
        // we must also force a new manager for the config change to matter.
        $this->app->forgetInstance('redacted');

        $second = Resolver::resolve('array://prod/key');
        $this->assertSame('value1', $second);
    }

    #[Test]
    public function two_fragment_uris_share_one_driver_fetch(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/db' => '{"host":"db.local","pass":"hunter2"}',
        ]);
        Resolver::clearStaticCache();

        $host = Resolver::resolve('array://prod/db#host');
        $pass = Resolver::resolve('array://prod/db#pass');

        $this->assertSame('db.local', $host);
        $this->assertSame('hunter2', $pass);
    }

    #[Test]
    public function warm_populates_static_cache(): void
    {
        Resolver::warm(['array:prod/prefetched' => 'pre_value']);

        // Resolve should hit static cache without going to the driver
        $result = Resolver::resolve('array://prod/prefetched');
        $this->assertSame('pre_value', $result);
    }

    #[Test]
    public function warm_key_format_matches_resolve_expectation(): void
    {
        // warm() and resolve() must use the same "{scheme}:{path}" key format.
        // Parse a URI the same way CacheCommand would, then warm with that key.
        $parsed   = \Yamut\Redacted\Resolution\UriParser::parse('array://prod/key');
        $cacheKey = $parsed['scheme'] . ':' . $parsed['path'];

        Resolver::warm([$cacheKey => 'from-warm']);

        $this->assertSame('from-warm', Resolver::resolve('array://prod/key'));
    }

    #[Test]
    public function throwing_driver_returns_fallback(): void
    {
        $throwingDriver = new class (['driver' => 'throwing']) extends \Yamut\Redacted\Drivers\AbstractDriver {
            public function get(string $path): ?string
            {
                throw new \RuntimeException('simulated driver failure');
            }
        };
        Resolver::setFakeDriver('throw', $throwingDriver);

        $result = Resolver::resolve('throw://some/path', 'fallback_value');

        $this->assertSame('fallback_value', $result);
    }

    #[Test]
    public function throwing_driver_returns_null_fallback_when_no_fallback_given(): void
    {
        $throwingDriver = new class (['driver' => 'throwing']) extends \Yamut\Redacted\Drivers\AbstractDriver {
            public function get(string $path): ?string
            {
                throw new \RuntimeException('simulated driver failure');
            }
        };
        Resolver::setFakeDriver('throw', $throwingDriver);

        $this->assertNull(Resolver::resolve('throw://some/path'));
    }

    #[Test]
    public function layer2_cache_is_populated_after_first_resolve(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'cached-value']);
        Resolver::clearStaticCache();

        Resolver::resolve('array://prod/key');

        $prefix   = 'redacted:';
        $cached   = $this->app['cache']->store('array')->get($prefix . 'array:prod/key');
        $this->assertSame('cached-value', $cached);
    }

    #[Test]
    public function layer2_cache_serves_value_after_static_cache_is_cleared(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'original']);
        Resolver::clearStaticCache();

        // First call: populates L1 (static) and L2 (Laravel cache)
        Resolver::resolve('array://prod/key');

        // Clear L1 only
        Resolver::clearStaticCache();

        // Change the driver data so a driver hit would return 'modified'
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'modified']);
        $this->app->forgetInstance('redacted');

        // Second call should hit L2 and return 'original', not 'modified'
        $result = Resolver::resolve('array://prod/key');
        $this->assertSame('original', $result);
    }

    #[Test]
    public function static_cache_does_not_bleed_across_clear(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'value']);
        Resolver::clearStaticCache();
        Resolver::resolve('array://prod/key'); // populate static cache

        // Simulate what tearDown does
        Resolver::clearStaticCache();

        // Remove from driver too to isolate static-cache-only scenario
        $this->app['config']->set('redacted.drivers.array.values', []);
        $this->app->forgetInstance('redacted');

        // Flush the L2 (Laravel) cache as well
        $this->app['cache']->store('array')->flush();

        $this->assertNull(Resolver::resolve('array://prod/key'));
    }
}
