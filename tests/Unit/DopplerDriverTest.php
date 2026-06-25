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
use ReflectionException;
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

    private function secretResponse(string $name, ?string $computed, ?string $raw = null): string
    {
        return json_encode(['name' => $name, 'value' => ['computed' => $computed, 'raw' => $raw ?? $computed], 'success' => true]);
    }

    private function makeDriver(array $config = []): DopplerDriver
    {
        return new DopplerDriver(array_merge($this->baseConfig, $config));
    }

    /**
     * @throws ReflectionException
     */
    private function injectGuzzle(DopplerDriver $driver, array $responses): void
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $prop  = new ReflectionProperty($driver, 'httpClient');
        $prop->setValue($driver, new Client(['handler' => $stack]));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_computed_value(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->secretResponse('DATABASE_URL', 'secret_value')),
        ]);

        $this->assertSame('secret_value', $driver->get('DATABASE_URL'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_falls_back_to_raw_when_computed_absent(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['name' => 'API_KEY', 'value' => ['computed' => null, 'raw' => 'raw_value'], 'success' => true])),
        ]);

        $this->assertSame('raw_value', $driver->get('API_KEY'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_returns_null_when_secret_value_is_null(): void
    {
        // Missing secrets return 200 with null computed/raw — not a 404.
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->secretResponse('MISSING', null)),
        ]);

        $this->assertNull($driver->get('MISSING'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_throws_on_client_error(): void
    {
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(403, [], json_encode(['error' => 'forbidden'])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->get('FORBIDDEN');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function get_throws_on_404_bad_project_or_config(): void
    {
        // 404 means the project/config does not exist, not a missing secret.
        $driver = $this->makeDriver();
        $this->injectGuzzle($driver, [
            new Response(404, [], json_encode(['error' => 'project not found'])),
        ]);

        $this->expectException(RuntimeException::class);
        $driver->get('KEY');
    }

    #[Test]
    public function get_throws_when_token_missing(): void
    {
        $driver = new DopplerDriver(['project' => 'p', 'config' => 'c']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token required');
        $driver->get('KEY');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function service_token_works_without_project_and_config(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.st.test.TOKEN']);
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->secretResponse('API_KEY', 'secret_value')),
        ]);

        $this->assertSame('secret_value', $driver->get('API_KEY'));
    }

    #[Test]
    public function personal_token_throws_when_project_missing(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.pt.personal.TOKEN', 'config' => 'prd']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project and config are required for personal tokens');
        $driver->get('KEY');
    }

    #[Test]
    public function personal_token_throws_when_config_missing(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.pt.personal.TOKEN', 'project' => 'myapp']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project and config are required for personal tokens');
        $driver->get('KEY');
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function personal_token_works_with_project_and_config(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.pt.personal.TOKEN', 'project' => 'myapp', 'config' => 'prd']);
        $this->injectGuzzle($driver, [
            new Response(200, [], $this->secretResponse('API_KEY', 'secret_value')),
        ]);

        $this->assertSame('secret_value', $driver->get('API_KEY'));
    }

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function service_token_prefetch_works_without_project_and_config(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.st.test.TOKEN']);
        $this->injectGuzzle($driver, [
            new Response(200, [], json_encode(['API_KEY' => 'sk_live_abc'])),
        ]);

        $result = $driver->prefetch(['API_KEY']);

        $this->assertSame('sk_live_abc', $result['API_KEY']);
    }

    #[Test]
    public function personal_token_prefetch_throws_when_project_missing(): void
    {
        $driver = new DopplerDriver(['token' => 'dp.pt.personal.TOKEN', 'config' => 'prd']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('project and config are required for personal tokens');
        $driver->prefetch(['KEY']);
    }

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
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

    /**
     * @throws ReflectionException
     */
    #[Test]
    public function prefetch_falls_back_to_individual_gets_on_non_auth_failure(): void
    {
        $driver = $this->makeDriver();
        // Bulk endpoint returns 500 → fall back to individual GET (200)
        $this->injectGuzzle($driver, [
            new Response(500, [], 'server error'),
            new Response(200, [], $this->secretResponse('API_KEY', 'individual_value')),
        ]);

        $result = $driver->prefetch(['API_KEY']);

        $this->assertSame('individual_value', $result['API_KEY']);
    }
}
