<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Yamut\Redacted\Drivers\AbstractDriver;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Resolution\UriParser;
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

    // -------------------------------------------------------------------------
    // Type coercion (Laravel/dotenv rules)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_coerces_true_string_to_boolean(): void
    {
        foreach (['true', '(true)', 'TRUE', 'True'] as $value) {
            $this->app['config']->set('redacted.drivers.array.values', ['key' => $value]);
            Resolver::clearStaticCache();
            $this->assertTrue(Resolver::resolve('array://key'), "Expected true for '$value'");
        }
    }

    #[Test]
    public function it_coerces_false_string_to_boolean(): void
    {
        foreach (['false', '(false)', 'FALSE', 'False'] as $value) {
            $this->app['config']->set('redacted.drivers.array.values', ['key' => $value]);
            Resolver::clearStaticCache();
            $this->assertFalse(Resolver::resolve('array://key'), "Expected false for '$value'");
        }
    }

    #[Test]
    public function it_coerces_null_string_to_null(): void
    {
        foreach (['null', '(null)', 'NULL', 'Null'] as $value) {
            $this->app['config']->set('redacted.drivers.array.values', ['key' => $value]);
            Resolver::clearStaticCache();
            $this->assertNull(Resolver::resolve('array://key'), "Expected null for '$value'");
        }
    }

    #[Test]
    public function it_coerces_empty_string_to_empty_string(): void
    {
        foreach (['empty', '(empty)', 'EMPTY'] as $value) {
            $this->app['config']->set('redacted.drivers.array.values', ['key' => $value]);
            Resolver::clearStaticCache();
            $this->assertSame('', Resolver::resolve('array://key'), "Expected '' for '$value'");
        }
    }

    #[Test]
    public function it_strips_surrounding_quotes(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['key' => '"hello"']);
        Resolver::clearStaticCache();
        $this->assertSame('hello', Resolver::resolve('array://key'));

        $this->app['config']->set('redacted.drivers.array.values', ['key' => "'world'"]);
        $this->app['cache']->store('array')->flush();
        $this->app->forgetInstance('redacted');
        Resolver::clearStaticCache();
        $this->assertSame('world', Resolver::resolve('array://key'));
    }

    #[Test]
    public function it_passes_through_plain_strings_unchanged(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['key' => 'hunter2']);
        Resolver::clearStaticCache();
        $this->assertSame('hunter2', Resolver::resolve('array://key'));
    }

    #[Test]
    public function type_coercion_does_not_apply_to_json_fragment_values(): void
    {
        // JSON natively types values — a JSON "true" is already bool true before we touch it.
        // A JSON "null" is PHP null. These should NOT be re-cast by EnvCaster.
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/config' => '{"flag":true,"nothing":null,"label":"true"}',
        ]);
        Resolver::clearStaticCache();

        $this->assertTrue(Resolver::resolve('array://prod/config#flag'));
        $this->assertNull(Resolver::resolve('array://prod/config#nothing'));
        // "true" as a JSON string value stays as a PHP string "true"
        $this->assertSame('true', Resolver::resolve('array://prod/config#label'));
    }

    // -------------------------------------------------------------------------

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
        $parsed   = UriParser::parse('array://prod/key');
        $cacheKey = $parsed['scheme'] . ':' . $parsed['path'];

        Resolver::warm([$cacheKey => 'from-warm']);

        $this->assertSame('from-warm', Resolver::resolve('array://prod/key'));
    }

    #[Test]
    public function throwing_driver_returns_fallback(): void
    {
        $throwingDriver = new class (['driver' => 'throwing']) extends AbstractDriver {
            public function get(string $path): ?string
            {
                throw new RuntimeException('simulated driver failure');
            }
        };
        Resolver::setFakeDriver('throw', $throwingDriver);

        $result = Resolver::resolve('throw://some/path', 'fallback_value');

        $this->assertSame('fallback_value', $result);
    }

    #[Test]
    public function throwing_driver_returns_null_fallback_when_no_fallback_given(): void
    {
        $throwingDriver = new class (['driver' => 'throwing']) extends AbstractDriver {
            public function get(string $path): ?string
            {
                throw new RuntimeException('simulated driver failure');
            }
        };
        Resolver::setFakeDriver('throw', $throwingDriver);

        $this->assertNull(Resolver::resolve('throw://some/path'));
    }

    /**
     * @throws InvalidArgumentException
     */
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

    // -------------------------------------------------------------------------
    // Early boot: no container-bound Manager (resolveDriver's disk-config path)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_resolves_via_disk_config_when_manager_is_not_bound(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', [
            'prod/key' => 'disk_value',
        ]);
        Resolver::clearStaticCache();

        // Simulates redacted() being called before RedactedServiceProvider registers.
        unset($this->app['redacted']);

        $result = Resolver::resolve('array://prod/key');
        $this->assertSame('disk_value', $result);
    }

    #[Test]
    public function unknown_scheme_falls_back_to_default_driver_during_early_boot(): void
    {
        // Documented quirk: when the Manager isn't bound yet, an unrecognised
        // scheme silently resolves through the *default* driver's config instead
        // of throwing — unlike the container-bound Manager path, which throws
        // for an unknown scheme. See makeDriverFromDiskConfig().
        $this->app['config']->set('redacted.default', 'array');
        $this->app['config']->set('redacted.drivers.array.values', [
            'anything' => 'default_driver_value',
        ]);
        Resolver::clearStaticCache();

        unset($this->app['redacted']);

        $result = Resolver::resolve('unknownscheme://anything');
        $this->assertSame('default_driver_value', $result);
    }

    #[Test]
    public function get_config_falls_back_to_package_default_when_container_config_is_unavailable(): void
    {
        Resolver::clearStaticCache();

        $configInstance = $this->app->make('config');
        unset($this->app['config']);

        try {
            $method = new ReflectionMethod(Resolver::class, 'getConfig');
            $config = $method->invoke(null);
        } finally {
            $this->app->instance('config', $configInstance);
        }

        // config/redacted.php's own shipped defaults, read straight off disk.
        $this->assertSame('env', $config['default']);
        $this->assertArrayHasKey('ssm', $config['drivers']);
        $this->assertArrayHasKey('array', $config['drivers']);
    }

    // -------------------------------------------------------------------------
    // Lazy-persist to Laravel cache (values fetched before cache is bound)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lazy_persists_to_laravel_cache_on_a_later_static_cache_hit(): void
    {
        $this->app['config']->set('redacted.drivers.array.values', ['prod/key' => 'lazy_value']);
        Resolver::clearStaticCache();

        $cacheInstance = $this->app->make('cache');
        unset($this->app['cache']); // simulates the cache service provider not having registered yet

        $first = Resolver::resolve('array://prod/key');
        $this->assertSame('lazy_value', $first);

        $this->app->instance('cache', $cacheInstance); // cache becomes available later in boot

        // Hits the static cache (Layer 1), not the driver — but should now
        // lazily persist to the Laravel cache since it wasn't written the first time.
        $second = Resolver::resolve('array://prod/key');
        $this->assertSame('lazy_value', $second);

        $cached = $this->app['cache']->store('array')->get('redacted:array:prod/key');
        $this->assertSame('lazy_value', $cached);
    }

    // -------------------------------------------------------------------------
    // Failure logging
    // -------------------------------------------------------------------------

    #[Test]
    public function driver_exception_is_logged_when_log_is_bound(): void
    {
        Log::spy();

        $throwingDriver = new class (['driver' => 'throwing']) extends AbstractDriver {
            public function get(string $path): ?string
            {
                throw new RuntimeException('simulated driver failure');
            }
        };
        Resolver::setFakeDriver('throwlog', $throwingDriver);

        Resolver::resolve('throwlog://some/path', 'fallback');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'redacted: resolution failed, using fallback'
                    && $context['uri'] === 'throwlog://some/path'
                    && $context['exception'] === RuntimeException::class;
            });
    }
}
