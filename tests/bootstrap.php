<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Utilities/helpers.php';

// Load test environment variables
if (file_exists(__DIR__ . '/../.env.test')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.test');
    $dotenv->load();
} else if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
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
