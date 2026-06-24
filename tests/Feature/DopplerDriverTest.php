<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Yamut\Redacted\Facades\Redacted;
use Yamut\Redacted\Tests\TestCase;

class DopplerDriverTest extends TestCase
{
    #[Test]
    public function fake_resolves_doppler_uri(): void
    {
        Redacted::fake([
            'doppler://DATABASE_URL' => 'postgres://db.prod.internal/myapp',
        ]);

        $this->assertSame(
            'postgres://db.prod.internal/myapp',
            redacted('doppler://DATABASE_URL')
        );
    }

    #[Test]
    public function fake_falls_back_for_unknown_doppler_uri(): void
    {
        Redacted::fake([]);

        $this->assertSame('fallback', redacted('doppler://MISSING_SECRET', 'fallback'));
    }

    #[Test]
    public function fake_supports_multiple_doppler_secrets(): void
    {
        Redacted::fake([
            'doppler://API_KEY'      => 'sk_live_abc',
            'doppler://DATABASE_URL' => 'postgres://db/myapp',
        ]);

        $this->assertSame('sk_live_abc', redacted('doppler://API_KEY'));
        $this->assertSame('postgres://db/myapp', redacted('doppler://DATABASE_URL'));
    }
}
