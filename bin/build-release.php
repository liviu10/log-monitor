<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

/**
 * Script de Mentenanta: Automatizarea copierii si transpilarii proiectului pentru diferite versiuni de PHP.
 * Executie: php bin/build-release.php [7.4|8.0]
 *
 * @category Maintenance
 * @package  Bin
 * @version  1.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

// Validarea argumentelor din linia de comanda si aplicarea filozofiei Fail Fast
$version = $argv[1] ?? null;
if (!in_array($version, ['7.4', '8.0'], true)) {
    echo "Usage: php bin/build-release.php [7.4|8.0]\n";
    exit(1);
}

$versionDirName = 'php' . str_replace('.', '', $version);
$targetDir = realpath(__DIR__ . '/..') . '/dist/' . $versionDirName;

echo "Building release for PHP {$version} in dist/{$versionDirName}...\n";

// 1. Crearea directorului tinta si curatarea build-ului anterior
if (file_exists($targetDir)) {
    echo "Cleaning existing target directory...\n";
    exec('rm -rf ' . escapeshellarg($targetDir));
}
mkdir($targetDir, 0755, true);

// 2. Definirea fisierelor si directoarelor ce trebuie copiate
$itemsToCopy = [
    'src',
    'tests',
    'bin',
    'db',
    'lang',
    'views',
    'bootstrap.php',
    'composer.json',
    'index.php',
    'app-settings.php',
    'apps.php',
    'change-lang.php',
    'log.php',
    'login.php',
    'users.php',
    'phinx.php'
];

foreach ($itemsToCopy as $item) {
    $source = realpath(__DIR__ . '/..') . '/' . $item;
    $destination = $targetDir . '/' . $item;

    if (!file_exists($source)) {
        continue;
    }

    if (is_dir($source)) {
        echo "Copying directory: {$item}...\n";
        exec('cp -r ' . escapeshellarg($source) . ' ' . escapeshellarg($destination));
    } else {
        echo "Copying file: {$item}...\n";
        copy($source, $destination);
    }
}

// 3. Stergerea scriptului de build-release din directorul final pentru curatenie
$targetBuildScript = $targetDir . '/bin/build-release.php';
if (file_exists($targetBuildScript)) {
    unlink($targetBuildScript);
}

// 4. Executarea Rector pe directorul tinta
echo "Running Rector on target directory...\n";
$envVar = 'TARGET_PHP=' . str_replace('.', '', $version);
$command = "{$envVar} vendor/bin/rector process " . escapeshellarg($targetDir);
passthru($command, $exitCode);

if ($exitCode === 0) {
    echo "\n[SUCCESS] Release for PHP {$version} successfully generated in dist/{$versionDirName}!\n";
} else {
    echo "\n[ERROR] Rector failed with exit code {$exitCode}.\n";
    exit($exitCode);
}
