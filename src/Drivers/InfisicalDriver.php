<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class InfisicalDriver extends AbstractDriver
{
    private const DEFAULT_BASE_URL = 'https://us.infisical.com';

    private ?string $accessToken = null;
    private int $tokenExpiry = 0;
    private ?Client $httpClient = null;

    private function httpClient(): Client
    {
        return $this->httpClient ??= new Client(['connect_timeout' => 5, 'timeout' => 10]);
    }

    /**
     * Fetch and cache a Universal Auth access token.
     * Tokens are reused until 60 seconds before expiry.
     * @throws GuzzleException
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }

        $baseUrl      = rtrim($this->config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        if (!str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('InfisicalDriver: base_url must use https://');
        }
        $clientId     = $this->config['client_id']     ?? throw new RuntimeException('InfisicalDriver: client_id required');
        $clientSecret = $this->config['client_secret'] ?? throw new RuntimeException('InfisicalDriver: client_secret required');

        $response = $this->httpClient()->post(
            "$baseUrl/api/v1/auth/universal-auth/login",
            ['json' => ['clientId' => $clientId, 'clientSecret' => $clientSecret]]
        );

        $body = json_decode((string) $response->getBody(), true);

        $this->accessToken = $body['accessToken']
            ?? throw new RuntimeException('InfisicalDriver: unexpected auth response (no accessToken)');

        $expiresIn = (int) ($body['expiresIn'] ?? 7200);
        // Some Infisical versions return expiresIn in milliseconds. Anything over
        // one year expressed as seconds is almost certainly milliseconds — convert.
        if ($expiresIn > 31_536_000) {
            $expiresIn = intdiv($expiresIn, 1000);
        }
        $this->tokenExpiry = time() + $expiresIn;

        return $this->accessToken;
    }

    /**
     * Path is the secret name as it appears in Infisical (e.g. DATABASE_URL).
     * Workspace and environment are taken from driver config, not the URI path.
     */
    public function get(string $path): ?string
    {
        return $this->doGet($path, false);
    }

    private function doGet(string $path, bool $retried): ?string
    {
        $baseUrl     = rtrim($this->config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $environment = $this->config['environment'] ?? throw new RuntimeException('InfisicalDriver: environment required');
        $secretPath  = $this->config['secret_path'] ?? '/';

        $query = ['environment' => $environment, 'secretPath' => $secretPath];

        if (isset($this->config['workspace_id'])) {
            $query['workspaceId'] = $this->config['workspace_id'];
        } elseif (isset($this->config['workspace_slug'])) {
            $query['workspaceSlug'] = $this->config['workspace_slug'];
        } else {
            throw new RuntimeException('InfisicalDriver: workspace_id or workspace_slug required');
        }

        try {
            $response = $this->httpClient()->get(
                "$baseUrl/api/v3/secrets/raw/" . rawurlencode($path),
                [
                    'query'   => $query,
                    'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            return $body['secret']['secretValue'] ?? null;
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status === 404) {
                return null;
            }

            // Token may have expired (clock skew, short TTL). Clear it and retry once.
            if ($status === 401 && !$retried) {
                $this->accessToken = null;
                $this->tokenExpiry = 0;
                return $this->doGet($path, true);
            }

            throw new RuntimeException(
                'InfisicalDriver: failed to fetch secret: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'InfisicalDriver: failed to fetch secret: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function flush(): void
    {
        $this->accessToken = null;
        $this->tokenExpiry = 0;
        $this->httpClient  = null;
    }
}
