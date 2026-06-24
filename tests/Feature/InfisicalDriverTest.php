<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Yamut\Redacted\Facades\Redacted;
use Yamut\Redacted\Tests\TestCase;

class InfisicalDriverTest extends TestCase
{
    #[Test]
    public function fake_resolves_infisical_uri(): void
    {
        Redacted::fake([
            'infisical://DATABASE_URL' => 'postgres://db.prod.internal/myapp',
        ]);

        $this->assertSame(
            'postgres://db.prod.internal/myapp',
            redacted('infisical://DATABASE_URL')
        );
    }

    #[Test]
    public function fake_falls_back_for_unknown_infisical_uri(): void
    {
        Redacted::fake([]);

        $this->assertSame('fallback', redacted('infisical://MISSING_SECRET', 'fallback'));
    }

    #[Test]
    public function fake_supports_json_blob_infisical_secret(): void
    {
        Redacted::fake([
            'infisical://DB_CONFIG#host'     => 'db.prod.internal',
            'infisical://DB_CONFIG#password' => 's3cr3t',
        ]);

        $this->assertSame('db.prod.internal', redacted('infisical://DB_CONFIG#host'));
        $this->assertSame('s3cr3t', redacted('infisical://DB_CONFIG#password'));
    }
}
