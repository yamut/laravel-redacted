<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Support\ValueMasker;

class ValueMaskerTest extends TestCase
{
    #[Test]
    public function null_returns_null_placeholder(): void
    {
        $this->assertSame('(null)', (new ValueMasker(4))->mask(null));
    }

    #[Test]
    public function empty_string_returns_empty_placeholder(): void
    {
        $this->assertSame('(empty)', (new ValueMasker(4))->mask(''));
    }

    #[Test]
    public function value_shorter_than_show_length_is_fully_starred(): void
    {
        $this->assertSame('***', (new ValueMasker(4))->mask('abc'));
    }

    #[Test]
    public function value_exactly_show_length_is_fully_starred(): void
    {
        $this->assertSame('****', (new ValueMasker(4))->mask('abcd'));
    }

    #[Test]
    public function value_longer_than_show_length_reveals_last_chars(): void
    {
        // Always 4 stars prefix regardless of show_length, then last N chars
        $this->assertSame('****_key', (new ValueMasker(4))->mask('s3cr3t_key'));
    }

    #[Test]
    public function custom_show_length_reveals_different_tail(): void
    {
        // showLength=6: last 6 chars of 's3cr3t_key' = 't_key' → no, -6 = '3t_key'
        $this->assertSame('****3t_key', (new ValueMasker(6))->mask('s3cr3t_key'));
    }

    #[Test]
    public function token_with_fixed_prefix_reveals_tail_not_prefix(): void
    {
        // Vault token: hvs.XXXXsecret — tail reveals entropy, not 'hvs.' prefix
        $this->assertSame('****cret', (new ValueMasker(4))->mask('hvs.XXXXsecret'));
    }

    #[Test]
    public function single_char_value_is_fully_masked(): void
    {
        $this->assertSame('*', (new ValueMasker(4))->mask('x'));
    }
}
