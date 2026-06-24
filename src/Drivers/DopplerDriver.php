<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class DopplerDriver extends AbstractDriver
{
    private const BASE_URL = 'https://api.doppler.com';

    private ?Client $httpClient = null;

    private function httpClient(): Client
    {
        return $this->httpClient ??= new Client(['connect_timeout' => 5, 'timeout' => 10]);
    }

    /**
     * Path is the secret name (e.g. DATABASE_URL).
     * Project and config (environment) come from driver config.
     */
    public function get(string $path): ?string
    {
        $token   = $this->config['token']   ?? throw new RuntimeException('DopplerDriver: token required');
        $project = $this->config['project'] ?? throw new RuntimeException('DopplerDriver: project required');
        $config  = $this->config['config']  ?? throw new RuntimeException('DopplerDriver: config required');

        try {
            $response = $this->httpClient()->get(
                self::BASE_URL . '/v3/configs/config/secrets',
                [
                    'query'   => ['project' => $project, 'config' => $config, 'name' => $path],
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            // 'computed' is the interpolated value (references resolved)
            // 'raw' is the un-interpolated template string
            return $body['secret']['value']['computed'] ?? $body['secret']['value']['raw'] ?? null;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                return null;
            }
            throw new RuntimeException(
                'DopplerDriver: failed to fetch secret: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'DopplerDriver: failed to fetch secret: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Doppler's bulk download endpoint is more efficient than N single-secret calls.
     * Returns flat object of all secrets with computed (interpolated) values.
     */
    public function prefetch(array $paths): array
    {
        $results = array_fill_keys($paths, null);

        $token   = $this->config['token']   ?? throw new RuntimeException('DopplerDriver: token required');
        $project = $this->config['project'] ?? throw new RuntimeException('DopplerDriver: project required');
        $config  = $this->config['config']  ?? throw new RuntimeException('DopplerDriver: config required');

        try {
            $response = $this->httpClient()->get(
                self::BASE_URL . '/v3/configs/config/secrets/download',
                [
                    'query'   => ['project' => $project, 'config' => $config, 'format' => 'json'],
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                ]
            );

            $all = json_decode((string) $response->getBody(), true) ?? [];

            foreach ($paths as $path) {
                $results[$path] = isset($all[$path]) ? (string) $all[$path] : null;
            }
        } catch (ClientException $e) {
            // Auth failures (401/403) will also fail every sequential fallback call —
            // re-throw immediately rather than firing N more requests that all fail.
            if (in_array($e->getResponse()->getStatusCode(), [401, 403], true)) {
                throw new RuntimeException(
                    'DopplerDriver: authentication failed during bulk download: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            foreach ($paths as $path) {
                $results[$path] = $this->get($path);
            }
        } catch (GuzzleException) {
            foreach ($paths as $path) {
                $results[$path] = $this->get($path);
            }
        }

        return $results;
    }
}
