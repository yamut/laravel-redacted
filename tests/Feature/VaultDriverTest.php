<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Yamut\Redacted\Facades\Redacted;
use Yamut\Redacted\Tests\TestCase;

class VaultDriverTest extends TestCase
{
    #[Test]
    public function fake_resolves_vault_uri(): void
    {
        Redacted::fake([
            'vault://secret/myapp/stripe' => '{"secret_key":"sk_test_abc","pub_key":"pk_test_abc"}',
        ]);

        $this->assertSame(
            '{"secret_key":"sk_test_abc","pub_key":"pk_test_abc"}',
            redacted('vault://secret/myapp/stripe')
        );
    }

    #[Test]
    public function fake_extracts_fragment_key_from_vault_uri(): void
    {
        Redacted::fake([
            'vault://secret/myapp/stripe#secret_key' => 'sk_test_abc',
        ]);

        $this->assertSame('sk_test_abc', redacted('vault://secret/myapp/stripe#secret_key'));
    }

    #[Test]
    public function fake_supports_multiple_vault_keys_from_same_path(): void
    {
        Redacted::fake([
            'vault://secret/myapp/db#host'     => 'db.prod.internal',
            'vault://secret/myapp/db#password' => 'hunter2',
        ]);

        $this->assertSame('db.prod.internal', redacted('vault://secret/myapp/db#host'));
        $this->assertSame('hunter2', redacted('vault://secret/myapp/db#password'));
    }

    #[Test]
    public function fake_falls_back_for_unknown_vault_uri(): void
    {
        Redacted::fake([]);

        $this->assertSame('fallback', redacted('vault://secret/missing', 'fallback'));
    }
}
