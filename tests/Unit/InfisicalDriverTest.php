<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Yamut\Redacted\Drivers\InfisicalDriver;

class InfisicalDriverTest extends TestCase
{
    private array $baseConfig = [
        'base_url'      => 'https://us.infisical.com',
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'workspace_id'  => 'ws-123',
        'environment'   => 'production',
    ];

    private function makeDriver(array $config = []): InfisicalDriver
    {
        return new InfisicalDriver(array_merge($this->baseConfig, $config));
    }

    private function injectGuzzle(InfisicalDriver $driver, array $responses): void
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $prop  = new ReflectionProperty($driver, 'httpClient');
        $prop->setValue($driver, new Client(['handler' => $stack]));
    }

    private function authResponse(int $expiresIn = 7200): string
    {
        return json_encode(['accessToken' => 'test-access-token', 'expiresIn' => $expiresIn]);
    }

    private function secretResponse(string $value): string
    {
        return json_encode(['secret' => ['secretValue' => $value]]);
    }

    #[Test]
    public function get_authenticates_and_returns_secret(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(200, [], $this->secretResponse('my-db-password')),
        ]);

        $result = $driver->get('DATABASE_PASSWORD');

        $this->assertSame('my-db-password', $result);
    }

    #[Test]
    public function get_reuses_token_on_second_call(): void
    {
        $driver = $this->makeDriver();
        // Only 1 auth call, then 2 secret calls
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(200, [], $this->secretResponse('value1')),
            new Response(200, [], $this->secretResponse('value2')),
        ]);

        $this->assertSame('value1', $driver->get('KEY1'));
        $this->assertSame('value2', $driver->get('KEY2'));
    }

    #[Test]
    public function get_returns_null_on_404(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(404, [], json_encode(['message' => 'not found'])),
        ]);

        $this->assertNull($driver->get('MISSING'));
    }

    #[Test]
    public function get_retries_once_on_401_by_clearing_token(): void
    {
        $driver = $this->makeDriver();
        // First auth, 401 on secret, second auth, success on retry
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(401, [], json_encode(['message' => 'unauthorized'])),
            new Response(200, [], $this->authResponse()),
            new Response(200, [], $this->secretResponse('retry-value')),
        ]);

        $result = $driver->get('KEY');

        $this->assertSame('retry-value', $result);
    }

    #[Test]
    public function get_throws_on_repeated_401(): void
    {
        $driver = $this->makeDriver();
        // First auth, 401 on secret, second auth, 401 again → throws
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
            new Response(401, [], json_encode(['message' => 'unauthorized'])),
            new Response(200, [], $this->authResponse()),
            new Response(401, [], json_encode(['message' => 'unauthorized'])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->get('KEY');
    }

    #[Test]
    public function millisecond_expires_in_is_converted_to_seconds(): void
    {
        $driver = $this->makeDriver();
        // expiresIn=86_400_000 ms (24 h) → driver detects >31_536_000 and divides by 1000 → 86_400 s
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse(86_400_000)),
            new Response(200, [], $this->secretResponse('value1')),
            new Response(200, [], $this->secretResponse('value2')),
        ]);

        $this->assertSame('value1', $driver->get('KEY1'));
        $this->assertSame('value2', $driver->get('KEY2'));

        // tokenExpiry should be ~time() + 86_400 (24 h), NOT time() + 86_400_000 (~1000 days)
        $prop   = new ReflectionProperty($driver, 'tokenExpiry');
        $expiry = $prop->getValue($driver);
        $this->assertLessThan(time() + 200_000, $expiry, 'expiresIn should have been divided by 1000');
        $this->assertGreaterThan(time() + 80_000, $expiry, 'token should still last ~24 h after conversion');
    }

    #[Test]
    public function base_url_must_use_https(): void
    {
        $driver = $this->makeDriver(['base_url' => 'http://attacker.example.com']);
        $this->injectGuzzle($driver, []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('https://');
        $driver->get('KEY');
    }

    #[Test]
    public function missing_workspace_throws(): void
    {
        $driver = new InfisicalDriver([
            'base_url'      => 'https://us.infisical.com',
            'client_id'     => 'id',
            'client_secret' => 'secret',
            'environment'   => 'production',
            // no workspace_id or workspace_slug
        ]);
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->authResponse()),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workspace_id or workspace_slug');
        $driver->get('KEY');
    }
}
