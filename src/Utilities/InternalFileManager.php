<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\LogViaStream;
use App\Enums\LogLevel;
use Throwable;

/**
 * Final utility for managing CMS files upload and deletion.
 * Saves files in the storage/cms/{content_id}/ directory structure.
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class InternalFileManager
{
    /**
     * Scans a storage location and returns the public URLs of the files.
     *
     * @param  string  $location  The relative storage path (e.g. 'cms/1').
     * @param  bool  $recursive  Recursive scan flag.
     * @return array<string> List of public URLs.
     */
    public static function read(string $location, bool $recursive = false): array
    {
        $baseDir = realpath(storage_path());
        // @codeCoverageIgnoreStart
        if ($baseDir === false) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $dir = realpath(storage_path($location));
        if ($dir === false || ! str_starts_with($dir, $baseDir)) {
            return [];
        }

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $rawAppUrl = config('app.url') ?? '';
        $appUrlStr = is_string($rawAppUrl) ? $rawAppUrl : '';
        $appUrl = rtrim($appUrlStr, '/');

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $rel = str_replace(storage_path(), '', $file->getPathname());
                    $files[] = $appUrl.'/storage/'.ltrim(str_replace('\\', '/', $rel), '/');
                }
            }
        } else {
            $items = scandir($dir);
            // @codeCoverageIgnoreStart
            if (! is_array($items)) {
                $items = [];
            }
            // @codeCoverageIgnoreEnd
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $filePath = $dir.'/'.$item;
                if (is_file($filePath)) {
                    $files[] = $appUrl.'/storage/'.ltrim($location, '/').'/'.$item;
                }
            }
        }

        return $files;
    }

    /**
     * Uploads a file from $_FILES to the server in storage/cms/{content_id}/.
     *
     * @param  array{name: string, tmp_name: string, type: string, error: int, size: int}  $file  File details from $_FILES.
     * @param  string  $contentId  The associated CMS content ID.
     * @param  string  $context  The context directory.
     * @return string|bool Relative path of uploaded file or false on failure.
     */
    public static function upload(array $file, string $contentId, string $context = 'cms'): string|bool
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        // $contentId and $context become path segments: digits only, known contexts only
        // (blocks "../../public"-style traversal out of storage/).
        if (! ctype_digit($contentId) || ! in_array($context, ['cms', 'profile'], true)) {
            return false;
        }

        try {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $whitelist = [
                // Images
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tiff',
                // Documents
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'rtf',
                // Video
                'mp4', 'webm', 'ogg', 'avi', 'mkv', 'mov', 'wmv',
                // Audio
                'mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac',
                // Archives
                'zip', 'rar', '7z', 'tar', 'gz',
            ];

            if (! in_array($ext, $whitelist, true)) {
                return false;
            }

            // Explicitly block dangerous/executable extensions
            $blocklist = [
                'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'pht',
                'html', 'htm', 'js', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'sh', 'bat', 'exe', 'htaccess',
            ];

            // Cross-verify MIME type of the file
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $realMime = $finfo->file($file['tmp_name']);

            if (! is_string($realMime)) {
                return false;
            }

            // Block execution types (e.g. PHP scripts uploaded with image/document extensions)
            $dangerousMimes = [
                'text/html', 'text/javascript', 'application/javascript', 'application/x-javascript',
                'application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
                'application/x-sh', 'application/x-executable',
            ];
            if (in_array($realMime, $dangerousMimes, true) && ! in_array($ext, ['txt', 'csv', 'svg'], true)) {
                return false;
            }

            // ZIP archive content scanning
            if ($ext === 'zip') {
                $zip = new \ZipArchive;
                if ($zip->open($file['tmp_name']) === true) {
                    $numFiles = $zip->numFiles;
                    for ($i = 0; $i < $numFiles; $i++) {
                        $stat = $zip->statIndex($i);
                        if (is_array($stat)) {
                            $innerName = $stat['name'];
                            $innerExt = strtolower(pathinfo($innerName, PATHINFO_EXTENSION));
                            if (in_array($innerExt, $blocklist, true)) {
                                $zip->close();

                                return false;
                            }
                        }
                    }
                    $zip->close();
                } else {
                    return false;
                }
            }

            $category = self::determineMediaType($realMime);
            $destDir = storage_path($context.'/'.$contentId.'/'.$category);
            if (! is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }

            $hashFilename = hash('sha256', $file['name'].microtime()).'.'.$ext;
            $destPath = $destDir.'/'.$hashFilename;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                return $context.'/'.$contentId.'/'.$category.'/'.$hashFilename;
            }

            return false;
        } catch (Throwable $exception) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'An exception occurred while uploading the file to the server.', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                    'exception_trace' => $exception->getTraceAsString(),
                    'identifier' => 'InternalFileManager_Upload_Failure',
                    'file_name' => $file['name'],
                    'content_id' => $contentId,
                ]);
            }

            return false;
        }
    }

    /**
     * Deletes a file permanently from storage based on its relative path.
     *
     * @param  string  $fileRelativePath  The relative file path.
     * @return bool True on success.
     */
    public static function remove(string $fileRelativePath): bool
    {
        $cleanPath = str_replace('storage/', '', $fileRelativePath);
        $baseDir = realpath(storage_path());
        // @codeCoverageIgnoreStart
        if ($baseDir === false) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $absPath = realpath(storage_path($cleanPath));
        if ($absPath === false || ! str_starts_with($absPath, $baseDir)) {
            return false;
        }

        if (is_file($absPath)) {
            unlink($absPath);

            return true;
        }

        return false;
    }

    /**
     * Returns the media category based on the MIME type.
     */
    public static function determineMediaType(string $mediaType): string
    {
        $categories = [
            'images' => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/svg+xml',
                'image/bmp',
                'image/tiff',
            ],
            'documents' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
            ],
            'video' => [
                'video/mp4',
                'video/webm',
                'video/ogg',
                'video/avi',
                'video/mkv',
            ],
            'audio' => [
                'audio/mpeg',
                'audio/wav',
                'audio/ogg',
                'audio/mp3',
                'audio/aac',
            ],
        ];

        foreach ($categories as $category => $types) {
            if (in_array($mediaType, $types, true)) {
                return $category;
            }
        }

        return 'others';
    }
}
