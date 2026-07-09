<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Contracts\DriverInterface;
use Yamut\Redacted\DriverFactory;
use Yamut\Redacted\Drivers\AkvDriver;
use Yamut\Redacted\Drivers\ArrayDriver;
use Yamut\Redacted\Drivers\AsmDriver;
use Yamut\Redacted\Drivers\DopplerDriver;
use Yamut\Redacted\Drivers\EnvDriver;
use Yamut\Redacted\Drivers\GcpDriver;
use Yamut\Redacted\Drivers\InfisicalDriver;
use Yamut\Redacted\Drivers\SsmDriver;
use Yamut\Redacted\Drivers\VaultDriver;

class DriverFactoryTest extends TestCase
{
    #[Test]
    #[DataProvider('driverNameProvider')]
    public function it_builds_the_expected_driver_class(string $driverName, string $expectedClass): void
    {
        $driver = DriverFactory::make($driverName, []);

        $this->assertInstanceOf($expectedClass, $driver);
        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    public static function driverNameProvider(): array
    {
        return [
            'ssm'       => ['ssm', SsmDriver::class],
            'asm'       => ['asm', AsmDriver::class],
            'akv'       => ['akv', AkvDriver::class],
            'gcp'       => ['gcp', GcpDriver::class],
            'vault'     => ['vault', VaultDriver::class],
            'infisical' => ['infisical', InfisicalDriver::class],
            'doppler'   => ['doppler', DopplerDriver::class],
            'env'       => ['env', EnvDriver::class],
            'array'     => ['array', ArrayDriver::class],
        ];
    }

    #[Test]
    public function it_passes_config_through_to_the_driver(): void
    {
        $driver = DriverFactory::make('array', ['values' => ['foo' => 'bar']]);

        $this->assertSame('bar', $driver->get('foo'));
    }

    #[Test]
    public function it_throws_on_unknown_driver_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redacted driver [bogus] is not supported.');

        DriverFactory::make('bogus', []);
    }

    #[Test]
    public function it_is_case_sensitive_about_driver_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DriverFactory::make('SSM', []);
    }
}
