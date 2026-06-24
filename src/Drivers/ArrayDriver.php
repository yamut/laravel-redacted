<?php

declare(strict_types=1);

namespace Yamut\Redacted\Drivers;

class ArrayDriver extends AbstractDriver
{
    /** @var array<string, string> */
    private array $values;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->values = $config['values'] ?? [];
    }

    public function get(string $path): ?string
    {
        return isset($this->values[$path]) ? (string) $this->values[$path] : null;
    }

    /** Replace all stored values — used by RedactedManager::fake(). */
    public function setValues(array $values): void
    {
        $this->values = $values;
    }

    public function getValues(): array
    {
        return $this->values;
    }
}
