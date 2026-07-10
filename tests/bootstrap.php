<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Utilities/helpers.php';

// Back up existing environment variables set by PHPUnit to avoid overwriting them
$phpunitEnvBackup = [];
foreach (['DB_HOST', 'DB_DATABASE', 'DB_NAME', 'DB_USERNAME', 'DB_USER', 'DB_PASSWORD', 'DB_PASS', 'DB_PORT'] as $key) {
    if (isset($_ENV[$key])) {
        $phpunitEnvBackup[$key] = $_ENV[$key];
    } elseif (isset($_SERVER[$key])) {
        $phpunitEnvBackup[$key] = $_SERVER[$key];
    }
}

// Load variables from the .env file
if (file_exists(__DIR__.'/../.env.test')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..', '.env.test');
    $dotenv->load();
} elseif (file_exists(__DIR__.'/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/..');
    $dotenv->safeLoad();
}

// Restore PHPUnit-specific variables and set them in putenv for Phinx and PDO compatibility
foreach ($phpunitEnvBackup as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

// Automatically create the test database and grant permissions if possible
try {
    $host = $_ENV['DB_HOST'] ?? 'db';
    $port = $_ENV['DB_PORT'] ?? '3306';
    $dbName = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'log_monitor_test';
    $user = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'user';

    // Try to connect as root to create database and grant privileges
    $rootPass = $_ENV['MYSQL_ROOT_PASSWORD'] ?? 'rootpassword';
    $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, 'root', $rootPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`;");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$user}'@'%';");
        $pdo->exec('FLUSH PRIVILEGES;');
    } catch (Throwable $rootException) {
        // Fallback: try connecting as normal user to create it
        $pass = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? 'password';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`;");
    }
} catch (Throwable $e) {
    // Fail silently, letting PHPUnit's connection handle the error report if it persists
}

// Define core constants for test execution context
if (! defined('APP_NAME')) {
    define('APP_NAME', 'LogMonitorTest');
}
if (! defined('APP_URL')) {
    define('APP_URL', 'http://localhost');
}
if (! defined('APP_NONCE')) {
    define('APP_NONCE', 'test_nonce_value');
}
