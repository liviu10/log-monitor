<?php

declare(strict_types=1);

use App\Core\LogViaStream;
use App\Enums\LogLevel;

if (php_sapi_name() !== 'cli') {
    exit('This script can only be run from the command line.');
}

/**
 * Audit Script: Scans the application and synchronizes missing translation keys in en.json / ro.json.
 * Execution: php logmonitor audit:translations
 */
$root = dirname(__DIR__, 2);
$enPath = $root.'/lang/en.json';

if (! file_exists($enPath)) {
    echo "Error: lang/en.json does not exist.\n";
    exit(1);
}

$directories = [
    $root.'/resources/views',
    $root.'/src',
    $root.'/public',
];

/** @var array<string, array<int, string>> $foundKeys */
$foundKeys = [];

/**
 * @param  array<string, array<int, string>>  $foundKeys
 */
function scanDirRecursive(string $dir, array &$foundKeys, string $root): void
{
    try {
        $files = scandir($dir);
    } catch (Throwable $e) {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::ERROR->value, 'Command execution failure event', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'identifier' => 'Audit_Translations_Failure',
            ]);
        }

        return;
    }

    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $path = $dir.'/'.$file;
        if (is_dir($path)) {
            scanDirRecursive($path, $foundKeys, $root);
        } elseif (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $content = @file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $relativePath = str_replace($root.'/', '', $path);

            preg_match_all('/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*[,)]/s', $content, $matches1);
            preg_match_all('/__\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*[,)]/s', $content, $matches2);

            $allMatches = array_merge($matches1[1], $matches2[1]);
            foreach ($allMatches as $key) {
                $key = stripslashes($key);
                $trimmedKey = trim($key);
                if ($trimmedKey !== '') {
                    $foundKeys[$trimmedKey][] = $relativePath;
                }
            }
        }
    }
}

foreach ($directories as $dir) {
    if (is_dir($dir)) {
        scanDirRecursive($dir, $foundKeys, $root);
    }
}

ksort($foundKeys);

$langDir = $root.'/lang';
$langFiles = glob($langDir.'/*.json');

$report = "=== LogMonitor Translation Audit Report ===\n";
$report .= 'Date: '.date('Y-m-d H:i:s')."\n";
$report .= 'Scanned Directories: '.implode(', ', array_map(static fn (string $d): string => str_replace($root.'/', '', $d), $directories))."\n";
$report .= 'Total Unique Keys Found in Codebase: '.count($foundKeys)."\n\n";

echo "=== LogMonitor Translation Audit ===\n";
echo 'Total unique translation keys in codebase: '.count($foundKeys)."\n";

if ($langFiles === false || count($langFiles) === 0) {
    $report .= "Error: No language files found in {$langDir}.\n";
    echo "Error: No language files found in {$langDir}.\n";
} else {
    foreach ($langFiles as $langPath) {
        $langName = basename($langPath);
        /** @var array<string, string> $langData */
        $langData = [];

        $content = @file_get_contents($langPath);
        if (is_string($content) && json_validate($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                /** @var array<string, string> $langData */
                $langData = $decoded;
            }
        }

        $localAdded = 0;
        /** @var array<string, array<int, string>> $newKeys */
        $newKeys = [];

        foreach ($foundKeys as $key => $paths) {
            if (! isset($langData[$key])) {
                $langData[$key] = ($langName === 'en.json') ? $key : '';
                $localAdded++;
                $newKeys[$key] = array_unique($paths);
            }
        }

        if ($localAdded > 0) {
            ksort($langData);
            file_put_contents(
                $langPath,
                (string) json_encode($langData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            );

            $actionNote = ($langName === 'en.json') ? 'synchronized' : 'added as empty placeholders';
            $report .= "Found {$localAdded} new keys and {$actionNote} in lang/{$langName}:\n";
            foreach ($newKeys as $key => $paths) {
                $report .= "- \"{$key}\" (found in: ".implode(', ', $paths).")\n";
            }
            $report .= "\n";
            echo "lang/{$langName}: Synced {$localAdded} new keys".($langName === 'en.json' ? ".\n" : " (empty placeholders awaiting translation).\n");
        } else {
            $report .= "All translation keys are up to date for lang/{$langName} (".count($langData)." keys).\n";
            echo "lang/{$langName}: Up to date (".count($langData)." keys).\n";
        }

        if ($langName !== 'en.json') {
            $untranslatedCount = 0;
            foreach ($langData as $val) {
                if ($val === '') {
                    $untranslatedCount++;
                }
            }
            if ($untranslatedCount > 0) {
                $report .= "Notice: {$untranslatedCount} keys in lang/{$langName} are currently empty and require translation.\n\n";
                echo "lang/{$langName}: {$untranslatedCount} keys awaiting translation (empty string).\n";
            }
        }
    }
}

$storageDir = $root.'/storage/commands';
if (! is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}

@file_put_contents($storageDir.'/audit-report.txt', $report);
echo "Report saved to: storage/commands/audit-report.txt\n";
