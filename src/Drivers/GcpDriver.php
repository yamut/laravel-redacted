<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use RuntimeException;
use Yamut\Redacted\Contracts\SecretManagerClientInterface;

class GcpDriver extends AbstractDriver
{
    private ?SecretManagerClientInterface $client = null;

    public function __construct(array $config)
    {
        if (!class_exists(SecretManagerServiceClient::class)) {
            throw new \RuntimeException(
                'The google/cloud-secret-manager package is required to use the GCP driver: composer require google/cloud-secret-manager'
            );
        }
        parent::__construct($config);
    }

    private function client(): SecretManagerClientInterface
    {
        if ($this->client === null) {
            $options = [];

            // Explicit credentials path or JSON key; leave unset for ADC / Workload Identity
            if (isset($this->config['credentials'])) {
                $options['credentials'] = $this->config['credentials'];
            }

            $this->client = new SecretManagerClientAdapter(new SecretManagerServiceClient($options));
        }

        return $this->client;
    }

    /**
     * Path may be:
     *   - A simple secret name: 'my-secret'
     *     → expanded to projects/{project}/secrets/my-secret/versions/latest
     *   - A full resource name: 'projects/my-project/secrets/my-secret/versions/1'
     *     → used as-is
     */
    public function get(string $path): ?string
    {
        $project = $this->config['project']
            ?? throw new RuntimeException('GcpDriver: project is required');

        $normalizedPath = ltrim($path, '/');
        $resourceName   = str_starts_with($normalizedPath, 'projects/')
            ? $normalizedPath
            : "projects/{$project}/secrets/{$normalizedPath}/versions/latest";

        try {
            $request  = (new AccessSecretVersionRequest())->setName($resourceName);
            $response = $this->client()->accessSecretVersion($request);

            return $response->getPayload()?->getData() ?? null;
        } catch (ApiException $e) {
            if ($e->getStatus() === 'NOT_FOUND') {
                return null;
            }
            throw $e;
        }
    }

    public function flush(): void
    {
        $this->client?->close();
        $this->client = null;
    }
}
