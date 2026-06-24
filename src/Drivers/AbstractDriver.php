<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

use Yamut\Redacted\Contracts\DriverInterface;

abstract class AbstractDriver implements DriverInterface
{
    public function __construct(protected array $config)
    {
    }

    public function prefetch(array $paths): array
    {
        $results = [];
        foreach ($paths as $path) {
            $results[$path] = $this->get($path);
        }
        return $results;
    }

    public function flush(): void
    {
    }
}
