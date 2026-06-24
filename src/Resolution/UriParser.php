<?php

declare(strict_types=1);

namespace Yamut\Redacted\Resolution;

use InvalidArgumentException;

class UriParser
{
    /**
     * Parse a redacted URI into its components.
     *
     * Supported formats:
     *   ssm:///prod/myapp/app_key          → scheme=ssm, path=/prod/myapp/app_key,         json_key=null
     *   asm://prod/myapp/db#password       → scheme=asm, path=prod/myapp/db,               json_key=password
     *   akv://my-vault/secrets/stripe-key  → scheme=akv, path=my-vault/secrets/stripe-key, json_key=null
     *   gcp://my-secret#somekey            → scheme=gcp, path=my-secret,                   json_key=somekey
     *   env://DB_HOST                      → scheme=env, path=DB_HOST,                     json_key=null
     *
     * Triple-slash (ssm:///) is used when paths start with /. parse_url treats
     * the empty host as the "authority" separator, giving us host='' and the
     * full slash-prefixed path intact.
     *
     * @return array{scheme: string, path: string, json_key: string|null}
     */
    public static function parse(string $originalUri): array
    {
        $uri = $originalUri;

        // Strip the fragment (first #) before parsing to avoid ambiguity.
        $jsonKey = null;
        if (($hashPos = strpos($uri, '#')) !== false) {
            $fragment = substr($uri, $hashPos + 1);
            $jsonKey  = $fragment !== '' ? $fragment : null;
            $uri      = substr($uri, 0, $hashPos);
        }

        // Use a regex instead of parse_url for reliable cross-version handling
        // of triple-slash URIs (e.g. ssm:///path) which parse_url handles
        // inconsistently across PHP versions.
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+\-.]*):\/\/(.*)$/s', $uri, $matches)) {
            throw new InvalidArgumentException("Invalid redacted URI: '$originalUri'");
        }

        $scheme = strtolower($matches[1]);
        $rest   = $matches[2]; // everything after scheme://

        // $rest can be:
        //   /path...         → triple-slash (empty host, path has leading /)
        //   host/path...     → host + path segments
        //   host             → host only, no path
        if (str_starts_with($rest, '/')) {
            // Triple-slash: empty authority, $rest is the full path
            $combined = $rest;
        } else {
            $slashPos = strpos($rest, '/');
            if ($slashPos === false) {
                // No slash after host: entire $rest is the path
                $combined = $rest;
            } else {
                $host     = substr($rest, 0, $slashPos);
                $tail     = substr($rest, $slashPos + 1);
                $combined = $tail !== '' ? $host . '/' . $tail : $host;
            }
        }

        if ($combined === '' || $combined === '/') {
            throw new InvalidArgumentException("Redacted URI has empty path: '$originalUri'");
        }

        return [
            'scheme'   => $scheme,
            'path'     => $combined,
            'json_key' => $jsonKey,
        ];
    }
}
