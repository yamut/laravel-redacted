<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Drivers\EnvDriver;

class EnvDriverTest extends TestCase
{
    private const VAR = 'REDACTED_ENV_DRIVER_TEST_VAR';

    protected function tearDown(): void
    {
        putenv(self::VAR);
        parent::tearDown();
    }

    #[Test]
    public function it_reads_a_set_environment_variable(): void
    {
        putenv(self::VAR . '=some_value');

        $driver = new EnvDriver([]);
        $this->assertSame('some_value', $driver->get(self::VAR));
    }

    #[Test]
    public function it_returns_null_for_an_unset_variable(): void
    {
        $driver = new EnvDriver([]);
        $this->assertNull($driver->get(self::VAR));
    }

    #[Test]
    public function it_treats_an_empty_string_value_as_unset(): void
    {
        putenv(self::VAR . '=');

        $driver = new EnvDriver([]);
        $this->assertNull($driver->get(self::VAR));
    }
}
