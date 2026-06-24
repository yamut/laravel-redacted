<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Vault\Exceptions\RequestException;
use Vault\ResponseModels\Response as VaultResponse;
use Yamut\Redacted\Drivers\VaultDriver;

class VaultDriverTest extends TestCase
{
    private function makeDriver(array $config = []): VaultDriver
    {
        return new VaultDriver(array_merge([
            'token'      => 'test-token',
            'kv_version' => 2,
        ], $config));
    }

    private function injectMockVaultClient(VaultDriver $driver, object $mock): void
    {
        $prop = new ReflectionProperty($driver, 'client');
        $prop->setValue($driver, $mock);
    }

    private function makeVaultResponse(?array $data): VaultResponse
    {
        $response = new VaultResponse();
        $dataProp = new ReflectionProperty($response, 'data');
        $dataProp->setValue($response, $data);
        return $response;
    }

    #[Test]
    public function get_kv_v2_reads_nested_data_key(): void
    {
        $driver = $this->makeDriver(['kv_version' => 2]);

        $response = $this->makeVaultResponse(['data' => ['stripe_key' => 'sk_live_abc']]);

        $mock = $this->createMock(\Vault\Client::class);
        $mock->expects($this->once())
            ->method('read')
            ->with('/secret/data/myapp/stripe')
            ->willReturn($response);

        $this->injectMockVaultClient($driver, $mock);

        $result = $driver->get('secret/myapp/stripe');

        $this->assertSame(json_encode(['stripe_key' => 'sk_live_abc']), $result);
    }

    #[Test]
    public function get_kv_v1_returns_data_directly(): void
    {
        $driver = $this->makeDriver(['kv_version' => 1]);

        $response = $this->makeVaultResponse(['stripe_key' => 'sk_live_v1']);

        $mock = $this->createMock(\Vault\Client::class);
        $mock->expects($this->once())
            ->method('read')
            ->with('/secret/myapp/stripe')
            ->willReturn($response);

        $this->injectMockVaultClient($driver, $mock);

        $result = $driver->get('secret/myapp/stripe');

        $this->assertSame(json_encode(['stripe_key' => 'sk_live_v1']), $result);
    }

    #[Test]
    public function get_returns_null_on_404_request_exception(): void
    {
        $driver = $this->makeDriver();

        $mock = $this->createMock(\Vault\Client::class);
        $mock->method('read')
            ->willThrowException(new RequestException('Not Found', 404));

        $this->injectMockVaultClient($driver, $mock);

        $result = $driver->get('secret/myapp/missing');

        $this->assertNull($result);
    }

    #[Test]
    public function get_rethrows_non_404_request_exception(): void
    {
        $driver = $this->makeDriver();

        $mock = $this->createMock(\Vault\Client::class);
        $mock->method('read')
            ->willThrowException(new RequestException('Internal Server Error', 500));

        $this->injectMockVaultClient($driver, $mock);

        $this->expectException(RequestException::class);
        $driver->get('secret/myapp/stripe');
    }

    #[Test]
    public function get_returns_null_when_data_is_null(): void
    {
        $driver = $this->makeDriver();

        $response = $this->makeVaultResponse(null);

        $mock = $this->createMock(\Vault\Client::class);
        $mock->method('read')->willReturn($response);

        $this->injectMockVaultClient($driver, $mock);

        $this->assertNull($driver->get('secret/myapp/stripe'));
    }

    #[Test]
    public function get_returns_null_when_kv_v2_data_key_missing(): void
    {
        $driver = $this->makeDriver(['kv_version' => 2]);

        // KV v2 response without nested 'data' key
        $response = $this->makeVaultResponse(['unexpected' => 'shape']);

        $mock = $this->createMock(\Vault\Client::class);
        $mock->method('read')->willReturn($response);

        $this->injectMockVaultClient($driver, $mock);

        $this->assertNull($driver->get('secret/myapp/stripe'));
    }

    #[Test]
    public function build_kv_v2_path_throws_on_mount_only_path(): void
    {
        $driver = $this->makeDriver(['kv_version' => 2]);

        $mock = $this->createMock(\Vault\Client::class);
        $this->injectMockVaultClient($driver, $mock);

        $this->expectException(\InvalidArgumentException::class);
        $driver->get('secret');
    }

    #[Test]
    public function flush_resets_client(): void
    {
        $driver = $this->makeDriver();

        $mock = $this->createMock(\Vault\Client::class);
        $this->injectMockVaultClient($driver, $mock);

        $driver->flush();

        $prop = new ReflectionProperty($driver, 'client');
        $this->assertNull($prop->getValue($driver));
    }
}
