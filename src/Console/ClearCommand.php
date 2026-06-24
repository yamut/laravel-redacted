<?php

declare(strict_types=1);

namespace Yamut\Redacted\Console;

use Illuminate\Console\Command;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Resolution\UriParser;
use Yamut\Redacted\Support\ConfigScanner;

class ClearCommand extends Command
{
    protected $signature = 'redacted:clear
                            {--static : Also clear the in-process static cache}';

    protected $description = 'Clear all cached redacted secrets from the cache store';

    public function handle(ConfigScanner $scanner): int
    {
        $configPath  = $this->laravel->configPath();
        $entries     = $scanner->scan($configPath);
        $cacheConfig = config('redacted.cache', []);
        $store       = $cacheConfig['store'] ?? null;
        $prefix      = $cacheConfig['prefix'] ?? 'redacted:';
        $cache       = $this->laravel['cache']->store($store);

        $cleared = 0;

        foreach ($entries as $entry) {
            $parsed   = UriParser::parse($entry['uri']);
            $cacheKey = $prefix . $parsed['scheme'] . ':' . $parsed['path'];

            if ($cache->forget($cacheKey)) {
                $cleared++;
            }
        }

        if ($this->option('static')) {
            Resolver::clearStaticCache();
            $this->info('In-process static cache cleared.');
        }

        $this->info("Cleared {$cleared} cache entries.");

        return self::SUCCESS;
    }
}
