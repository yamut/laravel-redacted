<?php

declare(strict_types=1);

namespace Yamut\Redacted\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Yamut\Redacted\RedactedManager;
use Yamut\Redacted\Resolution\Resolver;
use Yamut\Redacted\Resolution\UriParser;
use Yamut\Redacted\Support\ConfigScanner;
use Yamut\Redacted\Support\ValueMasker;

class ListCommand extends Command
{
    protected $signature = 'redacted:list
                            {--reveal : Show unmasked secret values}
                            {--driver= : Filter by driver/scheme}';

    protected $description = 'List all secrets referenced in config files with their cache status';

    public function handle(
        RedactedManager $manager,
        ConfigScanner $scanner,
        ValueMasker $masker
    ): int {
        $configPath   = $this->laravel->configPath();
        $entries      = $scanner->scan($configPath);
        $cacheConfig  = config('redacted.cache', []);
        $store        = $cacheConfig['store'] ?? null;
        $prefix       = $cacheConfig['prefix'] ?? Resolver::DEFAULT_CACHE_PREFIX;
        $cache        = $this->laravel['cache']->store($store);
        $driverFilter = $this->option('driver');
        $reveal       = (bool) $this->option('reveal');

        if (empty($entries)) {
            $this->info('No redacted() calls found in config files.');
            return self::SUCCESS;
        }

        if ($reveal) {
            if ($this->laravel->environment('production')) {
                $this->error('--reveal is blocked in the production environment.');
                return self::FAILURE;
            }
            if ($this->input->isInteractive() && !$this->confirm('This will display secret values in plaintext. Continue?')) {
                return self::SUCCESS;
            }
        }

        $rows = [];

        foreach ($entries as $entry) {
            // Unparseable URI literals are tolerated at runtime (redacted()
            // returns the fallback) — surface them as a row instead of crashing.
            try {
                $parsed = UriParser::parse($entry['uri']);
            } catch (InvalidArgumentException) {
                $rows[] = [
                    $entry['uri'],
                    '—',
                    '(invalid uri)',
                    '—',
                    basename($entry['file']) . ':' . $entry['line'],
                ];
                continue;
            }

            $scheme = $parsed['scheme'];

            if ($driverFilter && $driverFilter !== $scheme) {
                continue;
            }

            $cacheKey    = $scheme . ':' . $parsed['path'];
            $cached      = $cache->get($prefix . $cacheKey);
            $cacheStatus = $cached !== null ? 'CACHED' : 'MISS';

            // Resolver::resolve() never throws — driver failures are logged
            // internally and surface here as null / '(not found)'.
            $value = Resolver::resolve($entry['uri']);

            $displayValue = match (true) {
                $value === null => '(not found)',
                $reveal         => (string) $value,
                default         => $masker->mask(is_string($value) ? $value : json_encode($value)),
            };

            $rows[] = [
                $entry['uri'],
                $scheme,
                $displayValue,
                $cacheStatus,
                basename($entry['file']) . ':' . $entry['line'],
            ];
        }

        if (empty($rows)) {
            $this->info('No matching entries found.');
            return self::SUCCESS;
        }

        $this->table(['URI', 'Driver', 'Value', 'Cache', 'Source'], $rows);

        return self::SUCCESS;
    }
}
