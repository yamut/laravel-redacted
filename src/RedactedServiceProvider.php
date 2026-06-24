<?php

declare(strict_types=1);

namespace Yamut\Redacted;

use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Yamut\Redacted\Console\CacheCommand;
use Yamut\Redacted\Console\ClearCommand;
use Yamut\Redacted\Console\ListCommand;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Support\ValueMasker;

class RedactedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/redacted.php',
            'redacted'
        );

        $this->app->singleton('redacted', function ($app) {
            return new RedactedManager($app);
        });

        $this->app->alias('redacted', RedactedManager::class);

        $this->app->bind(ValueMasker::class, function ($app) {
            return new ValueMasker($app['config']->get('redacted.mask_length', 4));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/redacted.php' => config_path('redacted.php'),
            ], 'redacted-config');

            $this->commands([
                CacheCommand::class,
                ClearCommand::class,
                ListCommand::class,
            ]);
        }

        $this->registerOctaneListener();
    }

    private function registerOctaneListener(): void
    {
        // Under Octane (Swoole/RoadRunner/FrankenPHP) a single worker handles many
        // requests and the static cache never expires on its own. Clear it between
        // requests so that rotated secrets take effect within one TTL window rather
        // than requiring a worker restart. Layer 2 (Laravel cache) is still warm.
        if (!class_exists(RequestReceived::class)) {
            return;
        }

        $this->app['events']->listen(
            RequestReceived::class,
            static function (): void {
                Resolver::clearStaticCache();
            }
        );
    }
}
