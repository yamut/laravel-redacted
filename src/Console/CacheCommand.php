<?php

declare(strict_types=1);

namespace Yamut\Redacted\Console;

use Illuminate\Console\Command;
use Throwable;
use Yamut\Redacted\RedactedManager;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Resolution\UriParser;
use Yamut\Redacted\Support\ConfigScanner;

class CacheCommand extends Command
{
    protected $signature = 'redacted:cache
                            {--dry-run : List what would be fetched without fetching}';

    protected $description = 'Pre-fetch and cache all secrets referenced in config files';

    public function handle(RedactedManager $manager, ConfigScanner $scanner): int
    {
        $configPath = $this->laravel->configPath();
        $entries    = $scanner->scan($configPath);

        if (empty($entries)) {
            $this->info('No redacted() calls found in config files.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['URI', 'File', 'Line'],
                array_map(fn($e) => [$e['uri'], basename($e['file']), $e['line']], $entries)
            );
            $this->info(count($entries) . ' URI(s) would be fetched (dry run).');
            return self::SUCCESS;
        }

        // Group unique paths by scheme for batch prefetching
        $byScheme = [];
        foreach ($entries as $entry) {
            $parsed = UriParser::parse($entry['uri']);
            $byScheme[$parsed['scheme']][] = $parsed['path'];
        }

        $cacheConfig = config('redacted.cache', []);
        $store       = $cacheConfig['store'] ?? null;
        $ttl         = (int) ($cacheConfig['ttl'] ?? 3600);
        $prefix      = $cacheConfig['prefix'] ?? 'redacted:';
        $cache       = $this->laravel['cache']->store($store);

        $rows    = [];
        $fetched = 0;
        $failed  = 0;

        foreach ($byScheme as $scheme => $paths) {
            $paths = array_values(array_unique($paths));

            try {
                $driver  = $manager->driver($scheme);
                $results = $driver->prefetch($paths);
            } catch (Throwable $e) {
                $this->error("Driver [$scheme] failed: " . get_class($e));
                foreach ($paths as $path) {
                    $rows[] = [$scheme . ':' . $path, 'FAILED'];
                    $failed++;
                }
                continue;
            }

            foreach ($results as $path => $value) {
                $cacheKey = $scheme . ':' . $path;

                if ($value !== null) {
                    $cache->put($prefix . $cacheKey, $value, $ttl);
                    Resolver::warm([$cacheKey => $value]);
                    $rows[] = [$cacheKey, 'OK'];
                    $fetched++;
                } else {
                    $rows[] = [$cacheKey, 'NOT FOUND'];
                    $failed++;
                }
            }
        }

        $this->table(['Key', 'Status'], $rows);
        $this->info("Cached $fetched secret(s). $failed failed or not found.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
