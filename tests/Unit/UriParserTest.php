<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Resolution\UriParser;

class UriParserTest extends TestCase
{
    #[Test]
    #[DataProvider('validUriProvider')]
    public function it_parses_valid_uris(string $uri, string $scheme, string $path, ?string $jsonKey): void
    {
        $result = UriParser::parse($uri);

        $this->assertSame($scheme, $result['scheme'], "scheme mismatch for: {$uri}");
        $this->assertSame($path, $result['path'], "path mismatch for: {$uri}");
        $this->assertSame($jsonKey, $result['json_key'], "json_key mismatch for: {$uri}");
    }

    public static function validUriProvider(): array
    {
        return [
            'ssm triple slash'                => ['ssm:///prod/myapp/app_key',         'ssm', '/prod/myapp/app_key',         null],
            'ssm triple slash with fragment'  => ['ssm:///prod/myapp/db#host',          'ssm', '/prod/myapp/db',              'host'],
            'asm double slash'                => ['asm://prod/myapp/db',                'asm', 'prod/myapp/db',               null],
            'asm with fragment'               => ['asm://prod/myapp/db#password',       'asm', 'prod/myapp/db',               'password'],
            'akv with nested path'            => ['akv://my-vault/secrets/stripe-key', 'akv', 'my-vault/secrets/stripe-key', null],
            'gcp simple name'                 => ['gcp://my-secret',                   'gcp', 'my-secret',                   null],
            'gcp with fragment'               => ['gcp://my-secret#somekey',           'gcp', 'my-secret',                   'somekey'],
            'env variable'                    => ['env://DB_HOST',                      'env', 'DB_HOST',                     null],
            'array driver'                    => ['array://prod/key',                   'array', 'prod/key',                  null],
            'scheme uppercase normalised'     => ['SSM:///prod/key',                    'ssm', '/prod/key',                   null],
            'vault scheme'                    => ['vault://secret/myapp/stripe',        'vault', 'secret/myapp/stripe',       null],
            'vault with fragment'             => ['vault://secret/myapp/stripe#key',   'vault', 'secret/myapp/stripe',       'key'],
        ];
    }

    #[Test]
    public function it_throws_on_missing_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UriParser::parse('prod/myapp/db');
    }

    #[Test]
    public function it_throws_on_empty_path_after_scheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UriParser::parse('ssm://');
    }

    #[Test]
    public function it_uses_first_hash_as_fragment_delimiter(): void
    {
        // The URI fragment is everything after the FIRST #
        $result = UriParser::parse('asm://prod/db#host');
        $this->assertSame('host', $result['json_key']);
        $this->assertSame('prod/db', $result['path']);
    }

    #[Test]
    public function it_handles_fragment_with_no_value_as_null(): void
    {
        // A trailing # with nothing after it should yield null json_key
        $result = UriParser::parse('asm://prod/db#');
        $this->assertNull($result['json_key']);
    }

    #[Test]
    public function it_throws_on_triple_slash_trailing_slash_only(): void
    {
        // ssm:/// → path would be '/' which is empty — should throw
        $this->expectException(InvalidArgumentException::class);
        UriParser::parse('ssm:///');
    }

    #[Test]
    public function host_only_uri_is_valid(): void
    {
        // vault://myvault — no subpath, entire rest is treated as the path
        $result = UriParser::parse('vault://myvault');
        $this->assertSame('vault', $result['scheme']);
        $this->assertSame('myvault', $result['path']);
        $this->assertNull($result['json_key']);
    }

    #[Test]
    public function multi_hash_uses_only_first_hash_as_delimiter(): void
    {
        // asm://prod/db#host#extra → json_key = 'host#extra' (everything after first #)
        $result = UriParser::parse('asm://prod/db#host#extra');
        $this->assertSame('prod/db', $result['path']);
        $this->assertSame('host#extra', $result['json_key']);
    }

    #[Test]
    public function it_throws_on_scheme_starting_with_digit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UriParser::parse('1bad://path');
    }

    #[Test]
    public function special_chars_in_path_are_preserved(): void
    {
        $result = UriParser::parse('asm://prod/db%20path');
        $this->assertSame('prod/db%20path', $result['path']);
    }
}
