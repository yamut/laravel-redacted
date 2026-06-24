<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

class SsmDriverTest extends IntegrationTestCase
{
    protected function requiredEnv(): array
    {
        return ['REDACTED_TEST_SSM_PATH'];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = [
            'driver' => 'ssm',
            'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
        ];

        // Pass explicit credentials if present (long-lived IAM user keys).
        // Otherwise the SDK credential chain handles it (IAM roles, AWS_PROFILE, etc.).
        if (getenv('AWS_ACCESS_KEY_ID') && getenv('AWS_SECRET_ACCESS_KEY')) {
            $config['key']    = getenv('AWS_ACCESS_KEY_ID');
            $config['secret'] = getenv('AWS_SECRET_ACCESS_KEY');
        }

        $app['config']->set('redacted.default', 'ssm');
        $app['config']->set('redacted.drivers.ssm', $config);
    }

    #[Test]
    public function it_resolves_a_parameter_from_ssm(): void
    {
        $path  = getenv('REDACTED_TEST_SSM_PATH');
        $expectedValue = getenv('REDACTED_TEST_SSM_VALUE');
        $value = redacted("ssm://{$path}");

        if ($expectedValue) {
            $this->assertSame($expectedValue, $value);
        }
        $this->assertNotNull($value, "Expected SSM parameter '{$path}' to exist but got null.");
        $this->assertNotEmpty($value);
    }

    #[Test]
    public function it_returns_null_for_a_missing_parameter(): void
    {
        $value = redacted('ssm:///redacted-integration-test/this-param-does-not-exist-xyz');

        $this->assertNull($value);
    }

    #[Test]
    public function it_returns_fallback_for_a_missing_parameter(): void
    {
        $value = redacted('ssm:///redacted-integration-test/this-param-does-not-exist-xyz', 'fallback');

        $this->assertSame('fallback', $value);
    }
}
