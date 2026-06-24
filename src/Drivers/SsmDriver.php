<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use Aws\Exception\AwsException;
use Aws\Ssm\SsmClient;

class SsmDriver extends AbstractDriver
{
    private ?SsmClient $client = null;

    public function __construct(array $config)
    {
        if (!class_exists(SsmClient::class)) {
            throw new \RuntimeException(
                'The aws/aws-sdk-php package is required to use the SSM driver: composer require aws/aws-sdk-php'
            );
        }
        parent::__construct($config);
    }

    private function client(): SsmClient
    {
        if ($this->client === null) {
            $args = [
                'version' => 'latest',
                'region'  => $this->config['region'] ?? 'us-east-1',
            ];

            // Only pass explicit credentials when configured; otherwise the SDK
            // falls through its credential provider chain (IAM role, ECS task role, etc.)
            if (isset($this->config['key'], $this->config['secret'])) {
                $args['credentials'] = [
                    'key'    => $this->config['key'],
                    'secret' => $this->config['secret'],
                ];
            }

            $this->client = new SsmClient($args);
        }

        return $this->client;
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->client()->getParameter([
                'Name'           => $path,
                'WithDecryption' => true,
            ]);

            return $result['Parameter']['Value'] ?? null;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ParameterNotFound') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * SSM supports max 10 names per getParameters call.
     * Missing names are silently omitted from the response (not thrown),
     * so we pre-fill with null and overwrite only found values.
     */
    public function prefetch(array $paths): array
    {
        $results = array_fill_keys($paths, null);

        foreach (array_chunk($paths, 10) as $chunk) {
            try {
                $result = $this->client()->getParameters([
                    'Names'          => $chunk,
                    'WithDecryption' => true,
                ]);

                foreach ($result['Parameters'] as $param) {
                    $results[$param['Name']] = $param['Value'];
                }
            } catch (AwsException) {
                // Degrade to per-item gets for this chunk
                foreach ($chunk as $path) {
                    $results[$path] = $this->get($path);
                }
            }
        }

        return $results;
    }

    public function flush(): void
    {
        $this->client = null;
    }
}
