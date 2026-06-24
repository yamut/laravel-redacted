<?php

declare(strict_types=1);

namespace Yamut\Redacted\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scans PHP config files for redacted() calls using PHP's tokenizer.
 * This is static analysis — config files are never executed.
 */
class ConfigScanner
{
    /**
     * Scan all PHP files in the given directory.
     *
     * @param  string  $configPath  Absolute path to the config directory.
     * @return array<int, array{uri: string, file: string, line: int}>
     */
    public function scan(string $configPath): array
    {
        $found = [];

        if (!is_dir($configPath)) {
            return $found;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($configPath)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $found = array_merge($found, $this->scanFile($file->getRealPath()));
        }

        return $found;
    }

    /**
     * Scan a single PHP file for redacted() calls.
     *
     * Matched token sequence:
     *   T_STRING('redacted')  →  [T_WHITESPACE*]  →  '('  →  [T_WHITESPACE*]  →  T_CONSTANT_ENCAPSED_STRING
     *
     * Only simple string literal URIs are captured; dynamic expressions are skipped.
     *
     * @return array<int, array{uri: string, file: string, line: int}>
     */
    public function scanFile(string $filePath): array
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $count  = count($tokens);
        $found  = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'redacted') {
                continue;
            }

            $line = $token[2];
            $j    = $i + 1;

            // Skip whitespace
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            // Expect '('
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') {
                continue;
            }
            $j++;

            // Skip whitespace
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            // Expect a string literal (the URI)
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            // Strip surrounding single or double quotes
            $raw = $tokens[$j][1];
            $uri = substr($raw, 1, -1);

            $found[] = [
                'uri'  => $uri,
                'file' => $filePath,
                'line' => $line,
            ];
        }

        return $found;
    }
}
