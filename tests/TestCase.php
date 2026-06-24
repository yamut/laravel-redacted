<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Yamut\Redacted\Facades\Redacted;
use Yamut\Redacted\RedactedServiceProvider;
use Yamut\Redacted\Resolution\Resolver;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [RedactedServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Redacted' => Redacted::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('redacted.default', 'array');
        $app['config']->set('redacted.drivers.array', [
            'driver' => 'array',
            'values' => [],
        ]);
        $app['config']->set('redacted.cache.store', 'array');
        $app['config']->set('redacted.cache.ttl', 60);
        $app['config']->set('redacted.cache.prefix', 'redacted:');
        $app['config']->set('redacted.mask_length', 4);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Resolver::clearStaticCache();
    }

    protected function tearDown(): void
    {
        Resolver::clearStaticCache();
        $this->app->forgetInstance('redacted');
        parent::tearDown();
    }
}
