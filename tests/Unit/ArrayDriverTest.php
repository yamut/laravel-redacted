<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Drivers\ArrayDriver;

class ArrayDriverTest extends TestCase
{
    #[Test]
    public function it_gets_a_value_seeded_via_the_constructor(): void
    {
        $driver = new ArrayDriver(['values' => ['prod/key' => 'secret_value']]);

        $this->assertSame('secret_value', $driver->get('prod/key'));
    }

    #[Test]
    public function it_returns_null_for_a_missing_path(): void
    {
        $driver = new ArrayDriver(['values' => ['prod/key' => 'secret_value']]);

        $this->assertNull($driver->get('prod/missing'));
    }

    #[Test]
    public function it_defaults_to_no_values_when_config_omits_the_key(): void
    {
        $driver = new ArrayDriver([]);

        $this->assertNull($driver->get('anything'));
        $this->assertSame([], $driver->getValues());
    }

    #[Test]
    public function it_casts_non_string_values_to_string_on_get(): void
    {
        $driver = new ArrayDriver(['values' => ['flag' => true, 'count' => 42]]);

        $this->assertSame('1', $driver->get('flag'));
        $this->assertSame('42', $driver->get('count'));
    }

    #[Test]
    public function set_values_replaces_all_stored_values(): void
    {
        $driver = new ArrayDriver(['values' => ['old' => 'value']]);

        $driver->setValues(['new' => 'value']);

        $this->assertNull($driver->get('old'));
        $this->assertSame('value', $driver->get('new'));
    }

    #[Test]
    public function get_values_returns_the_current_backing_array(): void
    {
        $driver = new ArrayDriver(['values' => ['prod/key' => 'secret_value']]);

        $this->assertSame(['prod/key' => 'secret_value'], $driver->getValues());

        $driver->setValues(['other/key' => 'other_value']);

        $this->assertSame(['other/key' => 'other_value'], $driver->getValues());
    }
}
