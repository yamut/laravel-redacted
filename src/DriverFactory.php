<?php

declare(strict_types=1);

namespace Yamut\Redacted;

use InvalidArgumentException;
use Yamut\Redacted\Contracts\DriverInterface;
use Yamut\Redacted\Drivers\AkvDriver;
use Yamut\Redacted\Drivers\ArrayDriver;
use Yamut\Redacted\Drivers\AsmDriver;
use Yamut\Redacted\Drivers\DopplerDriver;
use Yamut\Redacted\Drivers\EnvDriver;
use Yamut\Redacted\Drivers\GcpDriver;
use Yamut\Redacted\Drivers\InfisicalDriver;
use Yamut\Redacted\Drivers\SsmDriver;
use Yamut\Redacted\Drivers\VaultDriver;

/**
 * Single source of truth for built-in driver instantiation.
 *
 * Both Resolver (early-boot, before service providers register) and
 * RedactedManager (container-bound) delegate here so that adding a new
 * built-in driver requires editing one place only.
 */
class DriverFactory
{
    /**
     * @throws InvalidArgumentException for unrecognised driver names.
     */
    public static function make(string $driverName, array $config): DriverInterface
    {
        return match ($driverName) {
            'ssm'       => new SsmDriver($config),
            'asm'       => new AsmDriver($config),
            'akv'       => new AkvDriver($config),
            'gcp'       => new GcpDriver($config),
            'vault'     => new VaultDriver($config),
            'infisical' => new InfisicalDriver($config),
            'doppler'   => new DopplerDriver($config),
            'env'       => new EnvDriver($config),
            'array'     => new ArrayDriver($config),
            default     => throw new InvalidArgumentException("Redacted driver [$driverName] is not supported."),
        };
    }
}
