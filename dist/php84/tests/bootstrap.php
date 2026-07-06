<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Utilities/helpers.php';

// Salveaza variabilele de mediu existente setate de PHPUnit pentru a evita suprascrierea lor
$phpunitEnvBackup = [];
foreach (['DB_HOST', 'DB_DATABASE', 'DB_NAME', 'DB_USERNAME', 'DB_USER', 'DB_PASSWORD', 'DB_PASS', 'DB_PORT'] as $key) {
    if (isset($_ENV[$key])) {
        $phpunitEnvBackup[$key] = $_ENV[$key];
    } elseif (isset($_SERVER[$key])) {
        $phpunitEnvBackup[$key] = $_SERVER[$key];
    }
}

// Incarcam variabilele din fisierul .env
if (file_exists(__DIR__ . '/../.env.test')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.test');
    $dotenv->load();
} else if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

// Restauram variabilele specifice PHPUnit si le setam si in putenv pentru compatibilitate cu Phinx si PDO
foreach ($phpunitEnvBackup as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

// Define core constants for test execution context
if (!defined('APP_NAME')) {
    define('APP_NAME', 'LogMonitorTest');
}
if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost');
}
if (!defined('APP_NONCE')) {
    define('APP_NONCE', 'test_nonce_value');
}
