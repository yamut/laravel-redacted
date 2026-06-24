<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class AkvDriver extends AbstractDriver
{
    private const API_VERSION = '7.4';

    private ?string $accessToken = null;
    private int $tokenExpiry = 0;
    private ?Client $httpClient = null;

    private function httpClient(): Client
    {
        return $this->httpClient ??= new Client(['connect_timeout' => 5, 'timeout' => 10]);
    }

    /**
     * Obtain an OAuth2 access token. Caches until 60 seconds before expiry.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }

        if ($this->config['use_managed_identity'] ?? false) {
            return $this->fetchManagedIdentityToken();
        }

        return $this->fetchClientCredentialsToken();
    }

    private function fetchClientCredentialsToken(): string
    {
        $tenantId     = $this->config['tenant_id']     ?? throw new RuntimeException('AkvDriver: tenant_id is required');
        $clientId     = $this->config['client_id']     ?? throw new RuntimeException('AkvDriver: client_id is required');
        $clientSecret = $this->config['client_secret'] ?? throw new RuntimeException('AkvDriver: client_secret is required');

        $response = $this->httpClient()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'scope'         => 'https://vault.azure.net/.default',
                ],
            ]
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->accessToken = $body['access_token']
            ?? throw new RuntimeException('AkvDriver: unexpected auth response (no access_token)');
        $this->tokenExpiry = time() + (int) ($body['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function fetchManagedIdentityToken(): string
    {
        $response = $this->httpClient()->get(
            'http://169.254.169.254/metadata/identity/oauth2/token',
            [
                'query'   => ['api-version' => '2018-02-01', 'resource' => 'https://vault.azure.net'],
                'headers' => ['Metadata' => 'true'],
                'timeout' => 5,
            ]
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->accessToken = $body['access_token']
            ?? throw new RuntimeException('AkvDriver: unexpected auth response (no access_token)');
        $this->tokenExpiry = time() + (int) ($body['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * Path is the secret name as it appears in Azure Key Vault.
     * AKV secret names may only contain alphanumerics and hyphens.
     */
    public function get(string $path): ?string
    {
        $vaultUrl = rtrim($this->config['vault_url'] ?? '', '/');
        if ($vaultUrl === '') {
            throw new RuntimeException('AkvDriver: vault_url is required');
        }
        if (!str_starts_with($vaultUrl, 'https://')) {
            throw new RuntimeException('AkvDriver: vault_url must use https://');
        }

        try {
            $response = $this->httpClient()->get(
                "{$vaultUrl}/secrets/" . ltrim($path, '/'),
                [
                    'query'   => ['api-version' => self::API_VERSION],
                    'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);
            return $body['value'] ?? null;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw new RuntimeException(
                'AkvDriver: failed to fetch secret: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'AkvDriver: failed to fetch secret: ' . $e->getMessage(),
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
