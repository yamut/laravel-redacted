<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Yamut\Redacted\Drivers\AkvDriver;

class AkvDriverTest extends TestCase
{
    private array $baseConfig = [
        'vault_url'     => 'https://my-vault.vault.azure.net',
        'tenant_id'     => 'tenant-123',
        'client_id'     => 'client-456',
        'client_secret' => 'secret-789',
    ];

    private function makeDriver(array $config = []): AkvDriver
    {
        return new AkvDriver(array_merge($this->baseConfig, $config));
    }

    /**
     * @throws ReflectionException
     */
    private function injectGuzzle(AkvDriver $driver, array $responses): void
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $prop  = new ReflectionProperty($driver, 'httpClient');
        $prop->setValue($driver, new Client(['handler' => $stack]));
    }

    private function authResponse(int $expiresIn = 3600): string
    {
        return json_encode(['access_token' => 'Bearer-token-xyz', 'expires_in' => $expiresIn]);
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_authenticates_and_returns_secret(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(200, [], json_encode(['value' => 'my-secret-value'])),
        ]);

        $this->assertSame('my-secret-value', $driver->get('stripe-key'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_null_on_404(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(404, [], json_encode(['error' => ['code' => 'SecretNotFound']])),
        ]);

        $this->assertNull($driver->get('missing-secret'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_throws_on_non_404_error(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(403, [], json_encode(['error' => ['code' => 'Forbidden']])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->get('forbidden-secret');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function token_is_reused_on_second_call(): void
    {
        $driver = $this->makeDriver();
        // Only 1 auth response queued + 2 secret responses
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(200, [], json_encode(['value' => 'secret1'])),
            new Response(200, [], json_encode(['value' => 'secret2'])),
        ]);

        $this->assertSame('secret1', $driver->get('key1'));
        $this->assertSame('secret2', $driver->get('key2'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function vault_url_must_use_https(): void
    {
        $driver = $this->makeDriver(['vault_url' => 'http://my-vault.vault.azure.net']);
        $this->injectGuzzle($driver, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('https://');
        $driver->get('key');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function empty_vault_url_throws(): void
    {
        $driver = $this->makeDriver(['vault_url' => '']);
        $this->injectGuzzle($driver, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('vault_url is required');
        $driver->get('key');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function auth_response_missing_access_token_throws(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['token_type' => 'Bearer'])), // no access_token
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected auth response');
        $driver->get('key');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function flush_clears_token_and_client(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(200, [], json_encode(['value' => 'val'])),
        ]);
        $driver->get('key'); // populates token

        $driver->flush();

        $tokenProp  = new ReflectionProperty($driver, 'accessToken');
        $clientProp = new ReflectionProperty($driver, 'httpClient');
        $this->assertNull($tokenProp->getValue($driver));
        $this->assertNull($clientProp->getValue($driver));
    }
}
