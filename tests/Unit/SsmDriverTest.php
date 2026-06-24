<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;
use Yamut\Redacted\Drivers\SsmDriver;

class SsmDriverTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    private function makeDriverWithMock(array ...$resultSets): array
    {
        $mock = new MockHandler();
        foreach ($resultSets as $result) {
            $mock->append($result instanceof Exception ? $result : new Result($result));
        }
        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);
        return [$driver, $mock];
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_parameter_value(): void
    {
        [$driver] = $this->makeDriverWithMock(
            ['Parameter' => ['Name' => '/prod/key', 'Value' => 'secret-value', 'Type' => 'SecureString']]
        );

        $this->assertSame('secret-value', $driver->get('/prod/key'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_null_on_parameter_not_found(): void
    {
        $mock = new MockHandler();
        $mock->append(function ($cmd) {
            throw new AwsException('ParameterNotFound', $cmd, ['code' => 'ParameterNotFound']);
        });
        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $this->assertNull($driver->get('/prod/missing'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_rethrows_non_parameter_not_found_exception(): void
    {
        $mock = new MockHandler();
        $mock->append(function ($cmd) {
            throw new AwsException('AccessDenied', $cmd, ['code' => 'AccessDenied']);
        });
        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $this->expectException(AwsException::class);
        $driver->get('/prod/key');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_returns_all_found_values(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'Parameters' => [
                ['Name' => '/prod/key1', 'Value' => 'val1'],
                ['Name' => '/prod/key2', 'Value' => 'val2'],
            ],
            'InvalidParameters' => [],
        ]));
        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $result = $driver->prefetch(['/prod/key1', '/prod/key2']);

        $this->assertSame('val1', $result['/prod/key1']);
        $this->assertSame('val2', $result['/prod/key2']);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_returns_null_for_missing_parameters(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'Parameters'        => [['Name' => '/prod/key1', 'Value' => 'val1']],
            'InvalidParameters' => ['/prod/missing'],
        ]));
        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $result = $driver->prefetch(['/prod/key1', '/prod/missing']);

        $this->assertSame('val1', $result['/prod/key1']);
        $this->assertNull($result['/prod/missing']);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_chunks_into_batches_of_ten(): void
    {
        // Build 11 paths — should trigger 2 getParameters calls
        $paths = array_map(fn($i) => "/prod/key$i", range(1, 11));

        $mock = new MockHandler();
        // First chunk: paths 1-10 → all found
        $mock->append(new Result([
            'Parameters' => array_map(fn($p) => ['Name' => $p, 'Value' => "v$p"], array_slice($paths, 0, 10)),
            'InvalidParameters' => [],
        ]));
        // Second chunk: path 11 → found
        $mock->append(new Result([
            'Parameters'       => [['Name' => '/prod/key11', 'Value' => 'v11']],
            'InvalidParameters' => [],
        ]));

        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $result = $driver->prefetch($paths);

        $this->assertCount(11, $result);
        $this->assertSame('v11', $result['/prod/key11']);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_degrades_to_individual_gets_on_batch_failure(): void
    {
        $mock = new MockHandler();
        // Batch call fails
        $mock->append(function ($cmd) {
            throw new AwsException('ThrottlingException', $cmd, ['code' => 'ThrottlingException']);
        });
        // Individual get succeeds
        $mock->append(new Result([
            'Parameter' => ['Name' => '/prod/key1', 'Value' => 'individual-val'],
        ]));

        $client = new SsmClient([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $mock,
            'retries'     => 0, // prevent retry middleware from swallowing ThrottlingException
        ]);
        $driver = new SsmDriver(['region' => 'us-east-1']);
        $prop   = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $client);

        $result = $driver->prefetch(['/prod/key1']);

        $this->assertSame('individual-val', $result['/prod/key1']);
    }
}
