<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Yamut\Redacted\Facades\Redacted;
use Yamut\Redacted\Tests\TestCase;

class ArrayDriverTest extends TestCase
{
    #[Test]
    public function redacted_facade_fake_populates_values(): void
    {
        Redacted::fake([
            'array://prod/myapp/key' => 'my_secret',
        ]);

        $this->assertSame('my_secret', redacted('array://prod/myapp/key'));
    }

    #[Test]
    public function fake_supports_json_fragment_extraction(): void
    {
        Redacted::fake([
            'array://prod/db' => '{"host":"db.local","pass":"hunter2"}',
        ]);

        $this->assertSame('db.local', redacted('array://prod/db#host'));
        $this->assertSame('hunter2', redacted('array://prod/db#pass'));
    }

    #[Test]
    public function fake_returns_fallback_for_unknown_uri(): void
    {
        Redacted::fake([]);

        $result = redacted('array://prod/missing', 'fallback');
        $this->assertSame('fallback', $result);
    }

    #[Test]
    public function global_helper_function_is_available(): void
    {
        Redacted::fake(['array://key' => 'val']);
        $this->assertSame('val', redacted('array://key'));
    }

    #[Test]
    public function fake_replaces_a_previously_faked_driver(): void
    {
        Redacted::fake(['array://k' => 'first']);
        $this->assertSame('first', redacted('array://k'));

        Redacted::fake(['array://k' => 'second']);
        $this->assertSame('second', redacted('array://k'));
    }

    #[Test]
    public function fake_works_with_ssm_scheme(): void
    {
        Redacted::fake([
            'ssm:///prod/myapp/app_key' => 'test-app-key',
        ]);

        $this->assertSame('test-app-key', redacted('ssm:///prod/myapp/app_key'));
    }

    #[Test]
    public function fake_works_with_multiple_schemes(): void
    {
        Redacted::fake([
            'ssm:///prod/app/key'    => 'ssm-value',
            'asm://prod/db#password' => 'asm-password',
        ]);

        $this->assertSame('ssm-value', redacted('ssm:///prod/app/key'));
        $this->assertSame('asm-password', redacted('asm://prod/db#password'));
    }

    #[Test]
    public function fake_throws_when_plain_and_fragment_uris_target_same_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Redacted::fake([
            'asm://prod/db'       => 'raw-string',
            'asm://prod/db#host'  => 'db.prod.internal',
        ]);
    }
}
