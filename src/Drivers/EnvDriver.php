<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

class EnvDriver extends AbstractDriver
{
    public function get(string $path): ?string
    {
        $value = getenv($path);
        // Empty-string env vars are treated as not-set and return null.
        // This matches Laravel's env() behaviour and prevents accidentally
        // propagating a blank string as a live secret value.
        return ($value !== false && $value !== '') ? $value : null;
    }
}
