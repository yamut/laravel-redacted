<?php

declare(strict_types=1);

namespace Yamut\Redacted\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Yamut\Redacted\Support\ConfigScanner;

class ConfigScannerTest extends TestCase
{
    private string $tmpDir;
    private ConfigScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/redacted_scanner_' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->scanner = new ConfigScanner();
    }

    protected function tearDown(): void
    {
        $this->rmdir_recursive($this->tmpDir);
        parent::tearDown();
    }

    private function write(string $filename, string $content): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    private function rmdir_recursive(string $dir): void
    {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir_recursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    #[Test]
    public function non_existent_directory_returns_empty(): void
    {
        $result = $this->scanner->scan('/no/such/dir/exists');
        $this->assertSame([], $result);
    }

    #[Test]
    public function empty_directory_returns_empty(): void
    {
        $result = $this->scanner->scan($this->tmpDir);
        $this->assertSame([], $result);
    }

    #[Test]
    public function single_quoted_uri_is_captured(): void
    {
        $this->write('app.php', "<?php\nreturn ['key' => redacted('array://prod/key')];\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('array://prod/key', $result[0]['uri']);
    }

    #[Test]
    public function double_quoted_uri_is_captured(): void
    {
        $this->write('app.php', '<?php' . "\n" . 'return ["key" => redacted("array://prod/key")];' . "\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('array://prod/key', $result[0]['uri']);
    }

    #[Test]
    public function whitespace_before_paren_is_handled(): void
    {
        $this->write('app.php', "<?php\nreturn redacted  ('array://key');\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('array://key', $result[0]['uri']);
    }

    #[Test]
    public function variable_argument_is_not_captured(): void
    {
        $this->write('app.php', "<?php\nreturn redacted(\$variable);\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertSame([], $result);
    }

    #[Test]
    public function line_number_is_reported_correctly(): void
    {
        $this->write('app.php', "<?php\n// line 2\n// line 3\nreturn redacted('array://key');\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame(4, $result[0]['line']);
    }

    #[Test]
    public function multiple_calls_in_one_file_are_all_captured(): void
    {
        $this->write('db.php', "<?php\nreturn [\n    'host' => redacted('array://host'),\n    'pass' => redacted('array://pass'),\n];\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(2, $result);
        $uris = array_column($result, 'uri');
        $this->assertContains('array://host', $uris);
        $this->assertContains('array://pass', $uris);
    }

    #[Test]
    public function non_php_files_are_skipped(): void
    {
        $this->write('app.yml', "key: redacted('array://key')\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertSame([], $result);
    }

    #[Test]
    public function method_call_false_positive_is_captured(): void
    {
        // $this->redacted('uri') IS captured because the scanner only sees T_STRING('redacted')
        // followed by '(' — it does not look at preceding tokens. Documented known behavior.
        $this->write('app.php', "<?php\nreturn \$this->redacted('array://key');\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertCount(1, $result);
        $this->assertSame('array://key', $result[0]['uri']);
    }

    #[Test]
    public function file_path_and_name_are_included_in_result(): void
    {
        $filePath = $this->write('database.php', "<?php\nreturn ['url' => redacted('asm://prod/db')];\n");

        $result = $this->scanner->scan($this->tmpDir);

        $this->assertSame(realpath($filePath), $result[0]['file']);
    }
}
