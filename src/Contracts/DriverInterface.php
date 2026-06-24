<?php

declare(strict_types=1);

namespace Yamut\Redacted\Contracts;

interface DriverInterface
{
    /**
     * Fetch a single secret by its path.
     *
     * Returns null if the path is not found — callers handle fallback.
     */
    public function get(string $path): ?string;

    /**
     * Batch-fetch multiple paths. Returns [path => value|null].
     *
     * Drivers that support native batching (SSM getParameters, ASM
     * batchGetSecretValue) override this for efficiency. The default
     * implementation in AbstractDriver loops over get().
     *
     * @param  string[]  $paths
     * @return array<string, string|null>
     */
    public function prefetch(array $paths): array;

    /**
     * Flush any internal driver-level caches (e.g. cached OAuth tokens).
     */
    public function flush(): void;
}
