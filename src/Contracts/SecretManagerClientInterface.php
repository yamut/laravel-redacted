<?php

declare(strict_types=1);

namespace Yamut\Redacted\Contracts;

use Google\Cloud\SecretManager\V1\AccessSecretVersionRequest;
use Google\Cloud\SecretManager\V1\AccessSecretVersionResponse;

interface SecretManagerClientInterface
{
    public function accessSecretVersion(AccessSecretVersionRequest $request, array $callOptions = []): AccessSecretVersionResponse;

    public function close(): void;
}
