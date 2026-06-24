<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Yamut\Redacted\Drivers\DopplerDriver;

class DopplerDriverTest extends TestCase
{
    private array $baseConfig = [
        'token'   => 'dp.st.test.TOKEN',
        'project' => 'myapp',
        'config'  => 'production',
    ];

    private function makeDriver(array $config = []): DopplerDriver
    {
        return new DopplerDriver(array_merge($this->baseConfig, $config));
    }

    private function injectGuzzle(DopplerDriver $driver, array $responses): void
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $prop  = new ReflectionProperty($driver, 'httpClient');
        $prop->setValue($driver, new Client(['handler' => $stack]));
    }

    #[Test]
    public function get_returns_computed_value(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['secret' => ['value' => ['computed' => 'secret_value', 'raw' => 'secret_value']]])),
        ]);

        $this->assertSame('secret_value', $driver->get('DATABASE_URL'));
    }

    #[Test]
    public function get_falls_back_to_raw_when_computed_absent(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['secret' => ['value' => ['raw' => 'raw_value']]])),
        ]);

        $this->assertSame('raw_value', $driver->get('API_KEY'));
    }

    #[Test]
    public function get_returns_null_on_404(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(404, [], json_encode(['error' => 'not found'])),
        ]);

        $this->assertNull($driver->get('MISSING'));
    }

    #[Test]
    public function get_throws_runtime_exception_on_non_404_client_error(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(403, [], json_encode(['error' => 'forbidden'])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->get('FORBIDDEN');
    }

    #[Test]
    public function get_throws_when_token_missing(): void
    {
        $driver = new DopplerDriver(['project' => 'p', 'config' => 'c']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token required');
        $driver->get('KEY');
    }

    #[Test]
    public function prefetch_bulk_download_returns_all_requested_paths(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode([
                'DATABASE_URL' => 'postgres://db/app',
                'API_KEY'      => 'sk_live_abc',
                'UNUSED'       => 'ignored',
            ])),
        ]);

        $result = $driver->prefetch(['DATABASE_URL', 'API_KEY']);

        $this->assertSame('postgres://db/app', $result['DATABASE_URL']);
        $this->assertSame('sk_live_abc', $result['API_KEY']);
    }

    #[Test]
    public function prefetch_returns_null_for_paths_absent_from_bulk_response(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['DATABASE_URL' => 'postgres://db/app'])),
        ]);

        $result = $driver->prefetch(['DATABASE_URL', 'MISSING']);

        $this->assertSame('postgres://db/app', $result['DATABASE_URL']);
        $this->assertNull($result['MISSING']);
    }

    #[Test]
    public function prefetch_rethrows_on_401_auth_failure(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(401, [], json_encode(['error' => 'unauthorized'])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');
        $driver->prefetch(['KEY']);
    }

    #[Test]
    public function prefetch_rethrows_on_403_auth_failure(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(403, [], json_encode(['error' => 'forbidden'])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->prefetch(['KEY']);
    }

    #[Test]
    public function prefetch_falls_back_to_individual_gets_on_non_auth_failure(): void
    {
        $driver = $this->makeDriver();
        // Bulk endpoint returns 500 → fall back to individual GET (200)
        $this->injectGuzzle($driver, [
            new Response(500, [], 'server error'),
            new Response(200, [], json_encode(['secret' => ['value' => ['computed' => 'individual_value']]])),
        ]);

        $result = $driver->prefetch(['API_KEY']);

        $this->assertSame('individual_value', $result['API_KEY']);
    }
}
