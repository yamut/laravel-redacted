<?php

declare(strict_types=1);

namespace Yamut\Redacted\Support;

readonly class ValueMasker
{
    public function __construct(private int $showLength = 4)
    {
    }

    /**
     * Mask a secret value, revealing only the last $showLength characters.
     * Revealing the tail avoids leaking fixed-format prefixes (e.g. 'dp.st.', 'hvs.').
     *
     * Examples (showLength=4):
     *   's3cr3t_key'  → '****_key'
     *   'abc'         → '***'         (too short — fully masked)
     *   null          → '(null)'
     *   ''            → '(empty)'
     */
    public function mask(?string $value): string
    {
        if ($value === null) {
            return '(null)';
        }

        if ($value === '') {
            return '(empty)';
        }

        if (strlen($value) <= $this->showLength) {
            return str_repeat('*', strlen($value));
        }

        return '****' . substr($value, -$this->showLength);
    }
}
