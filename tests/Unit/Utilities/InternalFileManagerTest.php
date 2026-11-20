<?php

declare(strict_types=1);

namespace App\Utilities {

    // Mock move_uploaded_file for CLI testing since it always returns false in CLI
    if (! function_exists('App\Utilities\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            if (str_contains($from, 'throw_exception')) {
                throw new \RuntimeException('Mocked exception for testing');
            }
            if (str_contains($from, 'fail_move')) {
                return false;
            }

            copy($from, $to);

            return true;
        }
    }

    if (! function_exists('App\Utilities\storage_path')) {
        function storage_path(string $path = ''): string
        {
            return sys_get_temp_dir().DIRECTORY_SEPARATOR.ltrim($path, '/');
        }
    }

    if (! function_exists('App\Utilities\config')) {
        function config(string $key, mixed $default = null): mixed
        {
            if ($key === 'app.url') {
                return 'http://localhost';
            }
            if (function_exists('\config')) {
                return \config($key, $default);
            }

            return $default;
        }
    }
}

namespace Tests\Unit\Utilities {

    use App\Utilities\InternalFileManager;
    use PHPUnit\Framework\TestCase;
    use ZipArchive;

    use function App\Utilities\storage_path;

    /**
     * Class InternalFileManagerTest
     *
     * Tests the utility class responsible for file management operations.
     *
     * @category Tests
     */
    class InternalFileManagerTest extends TestCase
    {
        private string $testStorageRoot;

        protected function setUp(): void
        {
            parent::setUp();
            $this->testStorageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cms_test_storage';
            if (! is_dir($this->testStorageRoot)) {
                mkdir($this->testStorageRoot, 0777, true);
            }
        }

        protected function tearDown(): void
        {
            $this->removeDirectory($this->testStorageRoot);
            parent::tearDown();
        }

        /**
         * Helper to clean up the temporary directory.
         */
        private function removeDirectory(string $dir): void
        {
            if (! is_dir($dir)) {
                return;
            }
            /** @var array<int, string> $scanned */
            $scanned = scandir($dir);
            $files = array_diff($scanned, ['.', '..']);
            foreach ($files as $file) {
                $path = $dir.DIRECTORY_SEPARATOR.$file;
                is_dir($path) ? $this->removeDirectory($path) : unlink($path);
            }
            rmdir($dir);
        }

        /**
         * Test the determineMediaType method with various MIME types.
         */
        public function test_determine_media_type_returns_correct_categories(): void
        {
            self::assertEquals('images', InternalFileManager::determineMediaType('image/jpeg'));
            self::assertEquals('images', InternalFileManager::determineMediaType('image/png'));
            self::assertEquals('documents', InternalFileManager::determineMediaType('application/pdf'));
            self::assertEquals('documents', InternalFileManager::determineMediaType('text/plain'));
            self::assertEquals('video', InternalFileManager::determineMediaType('video/mp4'));
            self::assertEquals('audio', InternalFileManager::determineMediaType('audio/mpeg'));
            self::assertEquals('others', InternalFileManager::determineMediaType('unknown/type'));
        }

        /**
         * Test the read method when the directory does not exist.
         */
        public function test_read_returns_empty_array_if_directory_does_not_exist(): void
        {
            $result = InternalFileManager::read('non_existent_dir_xyz');
            self::assertEmpty($result);
        }

        /**
         * Test the read method for a single level directory.
         */
        public function test_read_returns_urls_for_files_in_directory(): void
        {
            $subDir = 'cms_test/1';
            $realSubDir = storage_path($subDir);
            if (! is_dir($realSubDir)) {
                mkdir($realSubDir, 0777, true);
            }

            touch($realSubDir.'/file1.jpg');
            touch($realSubDir.'/file2.png');

            $result = InternalFileManager::read($subDir, false);

            self::assertCount(2, $result);
            self::assertStringContainsString('file1.jpg', $result[0]);
            self::assertStringContainsString('file2.png', $result[1]);
        }

        /**
         * Test the read method with recursive flag enabled.
         */
        public function test_read_returns_urls_recursively(): void
        {
            $subDir = 'cms_test/recursive';
            $realSubDir = storage_path($subDir);
            if (! is_dir($realSubDir.'/subdir')) {
                mkdir($realSubDir.'/subdir', 0777, true);
            }

            touch($realSubDir.'/root.jpg');
            touch($realSubDir.'/subdir/nested.jpg');

            $result = InternalFileManager::read($subDir, true);

            self::assertCount(2, $result);
            // Check that both files exist in the result set, regardless of iteration order
            $combinedResults = implode('', $result);
            self::assertStringContainsString('root.jpg', $combinedResults);
            self::assertStringContainsString('nested.jpg', $combinedResults);
        }

        /**
         * Test the upload method with an invalid extension.
         */
        public function test_upload_fails_on_invalid_extension(): void
        {
            $file = [
                'name' => 'malicious.exe',
                'tmp_name' => '/tmp/phpabc123',
                'type' => 'application/octet-stream',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ];

            $result = InternalFileManager::upload($file, '123');
            self::assertFalse($result);
        }

        /**
         * Test the upload method with a dangerous MIME type.
         */
        public function test_upload_fails_on_dangerous_mime_type(): void
        {
            // Create a dummy file that looks like HTML
            $tmpFile = tempnam(sys_get_temp_dir(), 'test');
            $tmpFile = is_string($tmpFile) ? $tmpFile : '';
            file_put_contents($tmpFile, '<html></html>');

            $file = [
                'name' => 'test.jpg', // Extension not in blocklist
                'tmp_name' => $tmpFile,
                'type' => 'text/html',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ];

            $result = InternalFileManager::upload($file, '123');
            self::assertFalse($result);

            unlink($tmpFile);
        }

        /**
         * Test upload fails when mime type cannot be determined (finfo returns false).
         */
        public function test_upload_fails_when_mime_type_cannot_be_determined(): void
        {
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => '/non/existent/path/for/test.jpg',
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ];

            // finfo_file will trigger a warning for non-existent file, so we suppress it in the test
            set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
                return true;
            }, E_WARNING);
            $result = InternalFileManager::upload($file, '123');
            restore_error_handler();

            self::assertFalse($result);
        }

        /**
         * Test the upload method when a ZIP contains a blocked extension.
         */
        public function test_upload_fails_on_malicious_zip_content(): void
        {
            $zipPath = tempnam(sys_get_temp_dir(), 'test_zip');
            $zipPath = is_string($zipPath) ? $zipPath : '';
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('malicious.php', '<?php echo "hacked"; ?>');
            $zip->close();

            $file = [
                'name' => 'archive.zip',
                'tmp_name' => $zipPath,
                'type' => 'application/zip',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ];

            $result = InternalFileManager::upload($file, '123');
            self::assertFalse($result);

            unlink($zipPath);
        }

        /**
         * Test the upload method when the PHP upload error is not OK.
         */
        public function test_upload_fails_on_upload_error(): void
        {
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => '',
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_INI_SIZE,
                'size' => 0,
            ];

            $result = InternalFileManager::upload($file, '123');
            self::assertFalse($result);
        }

        /**
         * Test that path segments cannot escape the storage directory.
         */
        public function test_upload_rejects_path_traversal_in_content_id_and_context(): void
        {
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => '/tmp/does-not-matter',
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => 10,
            ];

            self::assertFalse(InternalFileManager::upload($file, '../../public'));
            self::assertFalse(InternalFileManager::upload($file, '1/../../x'));
            self::assertFalse(InternalFileManager::upload($file, ''));
            self::assertFalse(InternalFileManager::upload($file, '1', '../config'));
        }

        /**
         * Test successful upload of a standard file.
         */
        public function test_upload_succeeds_for_valid_file(): void
        {
            $tmpFile = tempnam(sys_get_temp_dir(), 'test');
            $tmpFile = is_string($tmpFile) ? $tmpFile : '';
            // Create valid dummy JPEG
            file_put_contents($tmpFile, "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46\x00\x01");

            $size = filesize($tmpFile);
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => $tmpFile,
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ];

            $uniqueContentId = (string) random_int(1000, 999999);
            $result = InternalFileManager::upload($file, $uniqueContentId, 'cms');
            self::assertIsString($result);
            self::assertStringContainsString('cms/'.$uniqueContentId.'/images/', $result);

            unlink($tmpFile);
        }

        /**
         * Test successful upload of a clean ZIP file.
         */
        public function test_upload_succeeds_for_clean_zip(): void
        {
            $zipPath = tempnam(sys_get_temp_dir(), 'clean_zip');
            $zipPath = is_string($zipPath) ? $zipPath : '';
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('safe.txt', 'Safe content');
            $zip->close();

            $size = filesize($zipPath);
            $file = [
                'name' => 'archive.zip',
                'tmp_name' => $zipPath,
                'type' => 'application/zip',
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ];

            $result = InternalFileManager::upload($file, '123');
            self::assertIsString($result);

            unlink($zipPath);
        }

        /**
         * Test upload failure when ZIP file cannot be opened.
         */
        public function test_upload_fails_when_zip_cannot_be_opened(): void
        {
            $tmpFile = tempnam(sys_get_temp_dir(), 'bad_zip');
            $tmpFile = is_string($tmpFile) ? $tmpFile : '';
            // Not a real zip file
            file_put_contents($tmpFile, 'Not a zip file');

            $size = filesize($tmpFile);
            $file = [
                'name' => 'bad.zip',
                'tmp_name' => $tmpFile,
                'type' => 'application/zip',
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ];

            $result = InternalFileManager::upload($file, 'content_123');
            self::assertFalse($result);

            unlink($tmpFile);
        }

        /**
         * Test upload failure when move_uploaded_file fails.
         */
        public function test_upload_returns_false_when_move_uploaded_file_fails(): void
        {
            $tmpFile = tempnam(sys_get_temp_dir(), 'fail_move');
            $tmpFile = is_string($tmpFile) ? $tmpFile : '';
            $clonedPath = clone_string_for_test($tmpFile, 'fail_move');
            file_put_contents($clonedPath, "\xFF\xD8\xFF\xE0");

            $size = filesize($tmpFile);
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => $clonedPath,
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ];

            $result = InternalFileManager::upload($file, 'content_123');
            self::assertFalse($result);

            unlink($tmpFile);
            if (file_exists($clonedPath)) {
                unlink($clonedPath);
            }
        }

        /**
         * Test upload catches exception and returns false.
         */
        public function test_upload_catches_exception_and_logs_error(): void
        {
            $tmpFile = tempnam(sys_get_temp_dir(), 'throw_exception');
            $tmpFile = is_string($tmpFile) ? $tmpFile : '';
            $clonedPath = clone_string_for_test($tmpFile, 'throw_exception');
            file_put_contents($clonedPath, "\xFF\xD8\xFF\xE0");

            $size = filesize($tmpFile);
            $file = [
                'name' => 'test.jpg',
                'tmp_name' => $clonedPath,
                'type' => 'image/jpeg',
                'error' => UPLOAD_ERR_OK,
                'size' => is_int($size) ? $size : 0,
            ];

            $result = InternalFileManager::upload($file, 'content_123');
            self::assertFalse($result);

            unlink($tmpFile);
            if (file_exists($clonedPath)) {
                unlink($clonedPath);
            }
        }

        /**
         * Test the remove method for a successful file deletion.
         */
        public function test_remove_deletes_file_successfully(): void
        {
            $relPath = 'cms_test/1/images/test_file.jpg';
            $absPath = storage_path($relPath);

            // Setup: Create directory and file
            $dir = dirname($absPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            touch($absPath);

            self::assertTrue(InternalFileManager::remove($relPath));
            self::assertFileDoesNotExist($absPath);
        }

        /**
         * Test the remove method when the file does not exist.
         */
        public function test_remove_returns_false_if_file_does_not_exist(): void
        {
            $result = InternalFileManager::remove('non_existent_file.jpg');
            self::assertFalse($result);
        }

        /**
         * Test the read method prevents path traversal (Security).
         */
        public function test_read_prevents_path_traversal(): void
        {
            $result = InternalFileManager::read('../../../etc');
            self::assertEmpty($result, 'Metoda read() ar trebui sa previna path traversal.');
        }

        /**
         * Test the remove method prevents path traversal (Security).
         */
        public function test_remove_prevents_path_traversal(): void
        {
            $result = InternalFileManager::remove('../../../etc/passwd');
            self::assertFalse($result, 'Metoda remove() ar trebui sa previna path traversal.');
        }

        public function test_read_returns_empty_when_path_is_a_file_not_dir(): void
        {
            $filePath = storage_path('cms_test/single_file.txt');
            touch($filePath);

            $result = InternalFileManager::read('cms_test/single_file.txt');
            self::assertEmpty($result);

            unlink($filePath);
        }

        public function test_remove_returns_false_when_path_is_directory(): void
        {
            $dirPath = storage_path('cms_test/a_directory');
            if (! is_dir($dirPath)) {
                mkdir($dirPath, 0777, true);
            }

            self::assertFalse(InternalFileManager::remove('cms_test/a_directory'));

            rmdir($dirPath);
        }
    }

    // Helper to ensure path contains specific string
    function clone_string_for_test(string $original, string $substring): string
    {
        return $original.'_'.$substring;
    }

}
