<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\AccessSecretVersionResponse;
use Google\Cloud\SecretManager\V1\SecretPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Yamut\Redacted\Contracts\SecretManagerClientInterface;
use Yamut\Redacted\Drivers\GcpDriver;

class GcpDriverTest extends TestCase
{
    private function makeDriver(array $config = []): GcpDriver
    {
        return new GcpDriver(array_merge(['project' => 'my-project'], $config));
    }

    /**
     * @param GcpDriver $driver
     * @param SecretManagerClientInterface $mockClient
     * @return void
     * @throws ReflectionException
     */
    private function injectClient(GcpDriver $driver, SecretManagerClientInterface $mockClient): void
    {
        $prop = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $mockClient);
    }

    private function mockClientReturning(string $data): SecretManagerClientInterface
    {
        return new class ($data) implements SecretManagerClientInterface {
            public string $capturedResourceName = '';
            public function __construct(private readonly string $data)
            {
            }
            public function accessSecretVersion(AccessSecretVersionRequest $request, array $callOptions = []): AccessSecretVersionResponse
            {
                $this->capturedResourceName = $request->getName();
                $payload = (new SecretPayload())->setData($this->data);
                return (new AccessSecretVersionResponse())->setPayload($payload);
            }
            public function close(): void
            {
            }
        };
    }

    private function mockClientThrowingNotFound(): SecretManagerClientInterface
    {
        return new class implements SecretManagerClientInterface {
            public function accessSecretVersion(AccessSecretVersionRequest $request, array $callOptions = []): never
            {
                throw new ApiException('Secret not found', 5, 'NOT_FOUND');
            }
            public function close(): void
            {
            }
        };
    }

    private function mockClientThrowingPermissionDenied(): SecretManagerClientInterface
    {
        return new class implements SecretManagerClientInterface {
            public function accessSecretVersion(AccessSecretVersionRequest $request, array $callOptions = []): never
            {
                throw new ApiException('Permission denied', 7, 'PERMISSION_DENIED');
            }
            public function close(): void
            {
            }
        };
    }

    /**
     * @throws ReflectionException
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_expands_simple_name_to_full_resource_path(): void
    {
        $driver = $this->makeDriver();
        $mock   = $this->mockClientReturning('secret-data');
        $this->injectClient($driver, $mock);

        $driver->get('my-secret');

        $expected = 'projects/my-project/secrets/my-secret/versions/latest';
        $this->assertSame($expected, $mock->capturedResourceName);
    }

    /**
     * @throws ReflectionException
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_passes_full_resource_name_through_unchanged(): void
    {
        $driver = $this->makeDriver();
        $mock   = $this->mockClientReturning('secret-data');
        $this->injectClient($driver, $mock);

        $fullName = 'projects/my-project/secrets/my-secret/versions/3';
        $driver->get($fullName);

        $this->assertSame($fullName, $mock->capturedResourceName);
    }

    /**
     * @throws ReflectionException
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_strips_leading_slash_before_expansion(): void
    {
        $driver = $this->makeDriver();
        $mock   = $this->mockClientReturning('secret-data');
        $this->injectClient($driver, $mock);

        $driver->get('/my-secret');

        $expected = 'projects/my-project/secrets/my-secret/versions/latest';
        $this->assertSame($expected, $mock->capturedResourceName);
    }

    /**
     * @throws ReflectionException
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_returns_secret_data(): void
    {
        $driver = $this->makeDriver();
        $this->injectClient($driver, $this->mockClientReturning('the-secret-value'));

        $this->assertSame('the-secret-value', $driver->get('my-secret'));
    }

    /**
     * @throws ReflectionException
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_returns_null_on_not_found(): void
    {
        $driver = $this->makeDriver();
        $this->injectClient($driver, $this->mockClientThrowingNotFound());

        $this->assertNull($driver->get('missing-secret'));
    }

    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    #[Test]
    public function get_rethrows_non_not_found_exception(): void
    {
        $driver = $this->makeDriver();
        $this->injectClient($driver, $this->mockClientThrowingPermissionDenied());

        $this->expectException(ApiException::class);
        $driver->get('forbidden-secret');
    }

    /**
     * @throws ApiException
     * @throws ValidationException
     */
    #[Test]
    public function get_throws_when_project_is_missing(): void
    {
        $driver = new GcpDriver([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project is required');
        $driver->get('my-secret');
    }
}
