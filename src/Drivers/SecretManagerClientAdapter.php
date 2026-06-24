<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use Google\ApiCore\ApiException;
use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\AccessSecretVersionResponse;
use Google\Cloud\SecretManager\V1\Client\SecretManagerServiceClient;
use Yamut\Redacted\Contracts\SecretManagerClientInterface;

readonly class SecretManagerClientAdapter implements SecretManagerClientInterface
{
    public function __construct(private SecretManagerServiceClient $inner)
    {
    }

    /**
     * @throws ApiException
     */
    public function accessSecretVersion(AccessSecretVersionRequest $request, array $callOptions = []): AccessSecretVersionResponse
    {
        return $this->inner->accessSecretVersion($request, $callOptions);
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
