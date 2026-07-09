<?php

declare(strict_types=1);

namespace Yamut\Redacted\Support;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface;
use Illuminate\Support\Env;

/**
 * Applies Laravel/dotenv type coercion to a raw string value by delegating
 * entirely to Illuminate\Support\Env's own casting logic (the same rules that
 * govern env() in every Laravel app), backed by an isolated in-memory
 * repository so the global process environment is never read or modified.
 *
 * Coercion table (case-insensitive):
 *   "true" / "(true)"   → true
 *   "false" / "(false)" → false
 *   "null" / "(null)"   → null
 *   "empty" / "(empty)" → ""
 *   "'foo'" / '"foo"'   → "foo"  (strip surrounding quotes)
 *   anything else       → string as-is
 *
 * @internal
 */
final class EnvCaster extends Env
{
    private const CAST_KEY = '_REDACTED_CAST';

    private static ?RepositoryInterface $castRepository = null;

    public static function getRepository(): RepositoryInterface
    {
        if (self::$castRepository === null) {
            self::$castRepository = RepositoryBuilder::createWithNoAdapters()
                ->addAdapter(ArrayAdapter::class)
                ->make();
        }

        return self::$castRepository;
    }

    public static function cast(string $raw): mixed
    {
        static::getRepository()->set(self::CAST_KEY, $raw);

        return static::get(self::CAST_KEY);
    }
}
