<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Vault\AuthenticationStrategies\AppRoleAuthenticationStrategy;
use Vault\AuthenticationStrategies\TokenAuthenticationStrategy;
use Vault\Client;
use Vault\Exceptions\RequestException;

class VaultDriver extends AbstractDriver
{
    private ?Client $client = null;

    public function __construct(array $config)
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException(
                'The csharpru/vault-php package is required to use the Vault driver: composer require csharpru/vault-php'
            );
        }
        parent::__construct($config);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws \Vault\Exceptions\RuntimeException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function client(): Client
    {
        if ($this->client === null) {
            $address     = rtrim($this->config['address'] ?? 'https://127.0.0.1:8200', '/');
            $httpFactory = new HttpFactory();

            $this->client = new Client(
                new Uri($address),
                new GuzzleClient(['connect_timeout' => 5, 'timeout' => 10]),
                $httpFactory,
                $httpFactory
            );

            $auth = $this->config['auth'] ?? 'token';

            $strategy = match ($auth) {
                'approle' => new AppRoleAuthenticationStrategy(
                    $this->config['role_id']   ?? throw new RuntimeException('VaultDriver: role_id required for AppRole auth'),
                    $this->config['secret_id'] ?? throw new RuntimeException('VaultDriver: secret_id required for AppRole auth'),
                    $this->config['approle_mount'] ?? 'approle'
                ),
                default => new TokenAuthenticationStrategy(
                    $this->config['token'] ?? throw new RuntimeException('VaultDriver: token required')
                ),
            };

            $this->client->setAuthenticationStrategy($strategy)->authenticate();
        }

        return $this->client;
    }

    /**
     * Read a secret from Vault and return its key-value pairs as a JSON string,
     * so the Resolver can extract individual keys via the URI #fragment.
     *
     * URI path format: {mount}/{subpath}
     *   vault://secret/myapp/config     → reads /v1/secret/myapp/config (KV v1)
     *   vault://secret/myapp/config     → reads /v1/secret/data/myapp/config (KV v2)
     *   vault://secret/myapp/config#key → returns $values['key']
     * @param string $path
     * @return string|null
     * @throws ClientExceptionInterface
     * @throws RequestException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Vault\Exceptions\RuntimeException
     */
    public function get(string $path): ?string
    {
        $kvVersion = (int) ($this->config['kv_version'] ?? 2);

        // KV v2 injects 'data/' after the mount name
        $vaultPath = $kvVersion === 2
            ? $this->buildKvV2Path($path)
            : $path;

        // vault-php read() expects a leading slash
        $vaultPath = '/' . ltrim($vaultPath, '/');

        try {
            $response = $this->client()->read($vaultPath);
            $data     = $response->getData();

            if ($data === null) {
                return null;
            }

            // KV v2 wraps actual key-value pairs in a nested 'data' key
            $values = $kvVersion === 2 ? ($data['data'] ?? null) : $data;

            if (!is_array($values) || empty($values)) {
                return null;
            }

            // Return JSON so the Resolver can extract individual keys via #fragment
            return json_encode($values) ?: null;
        } catch (RequestException $e) {
            // csharpru/vault-php maps the HTTP status to the PHP exception code.
            // If the library is swapped, this check may need updating.
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Inject '/data/' after the mount name for KV v2 paths.
     *
     * 'secret/myapp/stripe' → 'secret/data/myapp/stripe'
     */
    private function buildKvV2Path(string $path): string
    {
        $path  = ltrim($path, '/');
        $parts = explode('/', $path, 2);
        $mount = $parts[0];
        $sub   = $parts[1] ?? '';

        if ($sub === '') {
            throw new InvalidArgumentException(
                "VaultDriver: path \"$path\" contains only a KV mount name with no secret path. " .
                'Expected format: {mount}/{secret-path} (e.g. secret/myapp/db-password).'
            );
        }

        return $mount . '/data/' . $sub;
    }

    public function flush(): void
    {
        $this->client = null;
    }
}
