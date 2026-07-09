<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;
use Yamut\Redacted\Drivers\AsmDriver;

class AsmDriverTest extends TestCase
{
    private function makeClientWithMock(array $responses): SecretsManagerClient
    {
        $mock = new MockHandler();
        foreach ($responses as $response) {
            $mock->append($response);
        }
        return new SecretsManagerClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
    }

    /**
     * @throws ReflectionException
     */
    private function injectClient(AsmDriver $driver, SecretsManagerClient $client): void
    {
        $prop = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_secret_string(): void
    {
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $this->injectClient($driver, $this->makeClientWithMock([
            new Result(['SecretString' => '{"host":"db.prod.internal"}', 'ARN' => 'arn:aws:...']),
        ]));

        $this->assertSame('{"host":"db.prod.internal"}', $driver->get('prod/db'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_secret_binary_bytes(): void
    {
        // The SDK's response parser base64-decodes blob shapes before the driver
        // sees them, so a mocked Result must carry the already-decoded bytes.
        // Use bytes that are NOT valid base64 to catch accidental double-decoding.
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $binaryValue = "\x00\xff binary-secret \xfe\x01";
        $this->injectClient($driver, $this->makeClientWithMock([
            new Result(['SecretBinary' => $binaryValue]),
        ]));

        $this->assertSame($binaryValue, $driver->get('prod/binary-secret'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_null_on_resource_not_found(): void
    {
        $mock = new MockHandler();
        $mock->append(function ($cmd) {
            throw new AwsException('ResourceNotFoundException', $cmd, ['code' => 'ResourceNotFoundException']);
        });
        $client = new SecretsManagerClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $this->injectClient($driver, $client);

        $this->assertNull($driver->get('prod/missing'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_rethrows_invalid_request_exception(): void
    {
        $mock = new MockHandler();
        $mock->append(function ($cmd) {
            throw new AwsException('InvalidRequestException', $cmd, ['code' => 'InvalidRequestException']);
        });
        $client = new SecretsManagerClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $this->injectClient($driver, $client);

        $this->expectException(AwsException::class);
        $driver->get('prod/pending-deletion');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_uses_batch_get_secret_value(): void
    {
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $this->injectClient($driver, $this->makeClientWithMock([
            new Result([
                'SecretValues' => [
                    ['Name' => 'prod/key1', 'SecretString' => 'val1', 'ARN' => 'arn:1'],
                    ['Name' => 'prod/key2', 'SecretString' => 'val2', 'ARN' => 'arn:2'],
                ],
                'Errors' => [],
            ]),
        ]));

        $result = $driver->prefetch(['prod/key1', 'prod/key2']);

        $this->assertSame('val1', $result['prod/key1']);
        $this->assertSame('val2', $result['prod/key2']);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_resolves_arn_keyed_paths(): void
    {
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $arn = 'arn:aws:secretsmanager:us-east-1:123456789012:secret:prod/key1-abc';
        $this->injectClient($driver, $this->makeClientWithMock([
            new Result([
                'SecretValues' => [
                    ['Name' => 'prod/key1', 'SecretString' => 'arn-resolved-val', 'ARN' => $arn],
                ],
                'Errors' => [],
            ]),
        ]));

        // Path is ARN: should resolve via ARN key in $fetched
        $result = $driver->prefetch([$arn]);

        $this->assertSame('arn-resolved-val', $result[$arn]);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_falls_back_to_sequential_gets_on_batch_failure(): void
    {
        $mock = new MockHandler();
        // Batch fails
        $mock->append(function ($cmd) {
            throw new AwsException('UnsupportedOperation', $cmd, ['code' => 'UnsupportedOperation']);
        });
        // Individual get succeeds
        $mock->append(new Result(['SecretString' => 'individual-val']));

        $client = new SecretsManagerClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new AsmDriver(['region' => 'us-east-1']);
        $this->injectClient($driver, $client);

        $result = $driver->prefetch(['prod/key1']);

        $this->assertSame('individual-val', $result['prod/key1']);
    }
}
