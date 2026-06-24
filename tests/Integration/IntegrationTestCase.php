<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Integration;

use Yamut\Redacted\Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    /**
     * Env vars that must all be present for this test class to run.
     * Override in subclasses to declare required credentials.
     *
     * @return string[]
     */
    protected function requiredEnv(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        foreach ($this->requiredEnv() as $var) {
            if (empty(getenv($var))) {
                $this->markTestSkipped("Integration test skipped: {$var} is not set.");
            }
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // When markTestSkipped() fires in setUp(), parent::setUp() never runs,
        // so $this->app is null. Guard before delegating to the base tearDown.
        if ($this->app !== null) {
            parent::tearDown();
        }
    }
}
