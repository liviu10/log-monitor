<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\DirectoryScanner;
use PHPUnit\Framework\TestCase;

/**
 * Class DirectoryScannerTest
 *
 * @category Tests
 */
class DirectoryScannerTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aegis_dir_scanner_test_'.bin2hex(random_bytes(8));
        if (! is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
        parent::tearDown();
    }

    /**
     * Helper to recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        $scanned = is_array($scanned) ? $scanned : [];
        $files = array_diff($scanned, ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * Tests that scan() returns an empty array for a non-existent directory.
     */
    public function test_scan_returns_empty_array_for_non_existent_directory(): void
    {
        $result = DirectoryScanner::scan('/path/that/does/not/exist/ever/12345');

        self::assertEmpty($result);
    }

    /**
     * Tests that scan() returns an empty array when the directory is empty.
     */
    public function test_scan_returns_empty_array_for_empty_directory(): void
    {
        $result = DirectoryScanner::scan($this->testDir);

        self::assertEmpty($result);
    }

    /**
     * Tests that scan() correctly finds files recursively with the default extension (php).
     */
    public function test_scan_finds_php_files_recursively(): void
    {
        $subDir = $this->testDir.DIRECTORY_SEPARATOR.'subdir';
        mkdir($subDir, 0777, true);

        // Create some .php files
        touch($this->testDir.DIRECTORY_SEPARATOR.'root.php');
        touch($subDir.DIRECTORY_SEPARATOR.'nested.php');

        // Create some files with other extensions (should be ignored)
        touch($this->testDir.DIRECTORY_SEPARATOR.'root.txt');
        touch($subDir.DIRECTORY_SEPARATOR.'nested.md');

        $result = DirectoryScanner::scan($this->testDir);

        self::assertCount(2, $result, 'Scan should return exactly two .php files.');

        // Convert to a flat string for easy searching, as order is not guaranteed
        $combinedResult = implode('|', $result);

        self::assertStringContainsString('root.php', $combinedResult);
        self::assertStringContainsString('nested.php', $combinedResult);
        self::assertStringNotContainsString('root.txt', $combinedResult);
    }

    /**
     * Tests that scan() correctly finds files with a custom extension.
     */
    public function test_scan_finds_files_with_custom_extension(): void
    {
        touch($this->testDir.DIRECTORY_SEPARATOR.'data1.json');
        touch($this->testDir.DIRECTORY_SEPARATOR.'data2.json');
        touch($this->testDir.DIRECTORY_SEPARATOR.'script.php');

        $result = DirectoryScanner::scan($this->testDir, 'json');

        self::assertCount(2, $result);

        $combinedResult = implode('|', $result);
        self::assertStringContainsString('data1.json', $combinedResult);
        self::assertStringContainsString('data2.json', $combinedResult);
        self::assertStringNotContainsString('script.php', $combinedResult);
    }
}
