<?php

declare(strict_types=1);

namespace Yamut\Redacted\Facades;

use Illuminate\Support\Facades\Facade;
use Yamut\Redacted\Contracts\DriverInterface;
use Yamut\Redacted\RedactedManager;

/**
 * @method static DriverInterface driver(string|null $driver = null)
 * @method static void fake(array $uriToValueMap)
 * @method static string getDefaultDriver()
 *
 * @see RedactedManager
 */
class Redacted extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'redacted';
    }
}
