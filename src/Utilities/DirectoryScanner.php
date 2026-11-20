<?php

declare(strict_types=1);

namespace App\Utilities;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Directory Scanner Utility
 *
 * Provides a standardized way to recursively scan directories for specific files.
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
final class DirectoryScanner
{
    /**
     * Recursively scans a directory and returns an array of file paths matching the extension.
     *
     * @param  string  $dirPath  The root directory to scan.
     * @param  string  $extension  The file extension to match (e.g. 'php').
     * @return array<int, string> List of matched file paths.
     */
    public static function scan(string $dirPath, string $extension = 'php'): array
    {
        $matchedFiles = [];

        if (! is_dir($dirPath)) {
            return $matchedFiles;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $matchedFiles[] = $file->getPathname();
            }
        }

        return $matchedFiles;
    }
}
