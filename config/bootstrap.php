<?php

declare(strict_types=1);

use App\Core\AppErrorHandler;

// Ignore deprecation notices in external libraries
error_reporting(E_ALL & ~E_DEPRECATED);

/**
 * Bootstrap Initialization File
 *
 * Loads dependencies, environment variables, and configures sessions and security.
 */
$rawRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
define('APP_START_TIME', (is_float($rawRequestTime) || is_numeric($rawRequestTime)) ? (float) $rawRequestTime : microtime(true));

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../src/Utilities/helpers.php';

$rawScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$currentScript = basename(is_string($rawScriptName) ? $rawScriptName : '');
$rawRequestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestUri = is_string($rawRequestUri) ? $rawRequestUri : '';
$isApiIngestion = ($currentScript === 'log.php') || str_starts_with($requestUri, '/api/log.php');

$envFile = defined('PHPUNIT_RUNNING') && file_exists(__DIR__.'/../.env.testing') ? '.env.testing' : '.env';

// Fail Fast Approach: Immediate termination if the .env file is missing
if (! file_exists(__DIR__.'/../'.$envFile)) {
    http_response_code(500);
    if ($isApiIngestion) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Critical: Configuration file ('.$envFile.') is missing. Application cannot initialize.']);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Critical: Configuration file ('.$envFile.') is missing. Application cannot initialize.';
    }
    exit;
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../', $envFile);
$dotenv->load();

// Set application default timezone
$appTimezone = $_ENV['TZ'] ?? 'Europe/Bucharest';
if (is_string($appTimezone) && $appTimezone !== '') {
    date_default_timezone_set($appTimezone);
}

// --- Dynamic Database Switching based on Git Branch ---
$currentDbBranch = 'unknown';
$gitHeadFile = __DIR__.'/../.git/HEAD';
if (is_file($gitHeadFile)) {
    $headContent = file_get_contents($gitHeadFile);
    if (is_string($headContent)) {
        $parts = explode('/', trim($headContent));
        $end = end($parts);
        $currentDbBranch = ($end !== '') ? $end : 'unknown';
    }
}
$envDb = $_ENV['DB_DATABASE'] ?? 'log_monitor';
$baseDbName = is_scalar($envDb) ? (string) $envDb : 'log_monitor';
$baseDbName = str_replace('_dev', '', $baseDbName);

$rawAppEnv = $_ENV['APP_ENV'] ?? null;
$isProduction = is_string($rawAppEnv) && in_array(strtolower($rawAppEnv), ['production', 'prod'], true);

if ($isProduction || $currentDbBranch === 'master') {
    $_ENV['DB_DATABASE'] = $baseDbName;
} else {
    $_ENV['DB_DATABASE'] = $baseDbName.'_dev';
}
// ------------------------------------------------------

if (! defined('PHPUNIT_RUNNING') && ! $isApiIngestion) {
    AppErrorHandler::register();
}

$envApiKey = $_ENV['LOG_API_KEY'] ?? null;
$envUrl = $_ENV['LOG_SERVER_URL'] ?? null;

if (! is_string($envApiKey) || trim($envApiKey) === '') {
    throw new RuntimeException('Invalid configuration: LOG_API_KEY is missing or empty.');
}

if (! is_string($envUrl) || filter_var($envUrl, FILTER_VALIDATE_URL) === false) {
    throw new RuntimeException('Invalid configuration: LOG_SERVER_URL is missing or not a valid URL.');
}

$envAppKey = $_ENV['APP_KEY'] ?? null;
if (! defined('PHPUNIT_RUNNING')
    && (! is_string($envAppKey)
        || strlen($envAppKey) < 32
        || in_array($envAppKey, ['aegis-secret-key-32-chars-length!', 'logmonitor-secret-key-32-chars!!'], true))) {
    throw new RuntimeException('Invalid configuration: APP_KEY must be a random secret of at least 32 characters.');
}

$urlScheme = strtolower((string) parse_url($envUrl, PHP_URL_SCHEME));
$urlHost = strtolower((string) parse_url($envUrl, PHP_URL_HOST));
$isLoopbackLogUrl = in_array($urlHost, ['127.0.0.1', 'localhost', '::1'], true);
if ($isProduction && $urlScheme !== 'https' && ! $isLoopbackLogUrl) {
    throw new RuntimeException('Invalid configuration: LOG_SERVER_URL must use https:// (or local loopback) in production.');
}

$rawAppName = $_ENV['APP_NAME'] ?? 'LogMonitor';
$appName = is_scalar($rawAppName) ? (string) $rawAppName : 'LogMonitor';
if (! defined('APP_NAME')) {
    define('APP_NAME', htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'));
}
if (! defined('APP_URL')) {
    define('APP_URL', constructUrl());
}

// Sessions and HTTP security (skip in CLI and stateless API ingestion endpoint)
if (PHP_SAPI !== 'cli' && ! $isApiIngestion) {
    $https = $_SERVER['HTTPS'] ?? '';
    $isHttps = ($https !== '' && is_scalar($https) && strtolower((string) $https) !== 'off') ||
               ((config('server.server_port') ?? '') === 443) ||
               ((config('server.http_x_forwarded_proto') ?? '') === 'https');

    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', '86400');
        session_cache_limiter('');
        session_name('LOGMONITORSESSID');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        header('Cache-Control: private, no-cache');
    }

    $rawNonce = defined('APP_NONCE') ? constant('APP_NONCE') : null;
    $nonce = is_string($rawNonce) ? $rawNonce : bin2hex(random_bytes(16));
    if (! defined('APP_NONCE')) {
        define('APP_NONCE', $nonce);
    }
} else {
    if (! defined('APP_NONCE')) {
        define('APP_NONCE', '');
    }
}
