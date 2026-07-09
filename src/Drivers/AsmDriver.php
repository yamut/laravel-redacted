<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use RuntimeException;

class AsmDriver extends AbstractDriver
{
    private ?SecretsManagerClient $client = null;

    public function __construct(array $config)
    {
        if (!class_exists(SecretsManagerClient::class)) {
            throw new RuntimeException(
                'The aws/aws-sdk-php package is required to use the ASM driver: composer require aws/aws-sdk-php'
            );
        }
        parent::__construct($config);
    }

    private function client(): SecretsManagerClient
    {
        if ($this->client === null) {
            $args = [
                'version' => 'latest',
                'region'  => $this->config['region'] ?? 'us-east-1',
            ];

            if (isset($this->config['key'], $this->config['secret'])) {
                $args['credentials'] = [
                    'key'    => $this->config['key'],
                    'secret' => $this->config['secret'],
                ];
            }

            $this->client = new SecretsManagerClient($args);
        }

        return $this->client;
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->client()->getSecretValue(['SecretId' => $path]);

            if (isset($result['SecretString'])) {
                return $result['SecretString'];
            }

            // SecretBinary is a blob shape — the SDK's response parser has
            // already base64-decoded it, so this is the raw secret bytes.
            if (isset($result['SecretBinary'])) {
                return $result['SecretBinary'];
            }

            return null;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }
            // InvalidRequestException means malformed request or secret pending deletion —
            // not a not-found condition. Re-throw so the caller fails fast.
            throw $e;
        }
    }

    /**
     * batchGetSecretValue was added to the ASM API in 2023.
     * Falls back to sequential gets if the call fails (older SDK or restricted IAM).
     */
    public function prefetch(array $paths): array
    {
        $results = array_fill_keys($paths, null);

        try {
            $result = $this->client()->batchGetSecretValue(['SecretIdList' => $paths]);

            // batchGetSecretValue always returns Name (friendly name), never the ARN.
            // Build a lookup keyed by both Name and ARN so that paths supplied as
            // ARNs resolve correctly instead of silently staying null.
            $fetched = [];
            foreach ($result['SecretValues'] as $secret) {
                $value = $secret['SecretString'] ?? $secret['SecretBinary'] ?? null;

                if (isset($secret['Name'])) {
                    $fetched[$secret['Name']] = $value;
                }
                if (isset($secret['ARN'])) {
                    $fetched[$secret['ARN']] = $value;
                }
            }

            foreach ($paths as $path) {
                if (array_key_exists($path, $fetched)) {
                    $results[$path] = $fetched[$path];
                }
            }
        } catch (AwsException) {
            foreach ($paths as $path) {
                $results[$path] = $this->get($path);
            }
        }

        return $results;
    }

    public function flush(): void
    {
        $this->client = null;
    }
}
