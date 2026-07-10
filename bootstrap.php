<?php

declare(strict_types=1);
use App\Utilities\LogViaStream;

require_once __DIR__.'/vendor/autoload.php';

// Include global helpers early to have access to the __() function
require_once __DIR__.'/src/Utilities/helpers.php';

// Determine the currently requested script
$scriptNameVal = $_SERVER['SCRIPT_NAME'] ?? '';
$currentScript = basename(is_string($scriptNameVal) ? $scriptNameVal : '');

// Fail Fast approach: Terminate execution immediately if the .env file is missing
if (! file_exists(__DIR__.'/.env')) {
    http_response_code(500);

    $isApi = ($currentScript === 'log.php');
    $errorMessage = __('Critical: Configuration file (.env) is missing. Application cannot initialize.');

    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $errorMessage]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $errorMessage;
    }
    exit;
}

// Secure loading of environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Register global error handler for stream logging
LogViaStream::registerHandlers();

// Session is started ONLY if we are not on the cURL logging endpoint (API)
if ($currentScript !== 'log.php') {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', '86400');
        session_name('LOGMONITORSESSID');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    // Inject runtime security headers specific to the web/dashboard application
    injectRuntimeSecurityHeaders();
}

// Configure application, secured against injection attacks
$appNameVal = $_ENV['APP_NAME'] ?? 'LogMonitor';
define('APP_NAME', htmlspecialchars(is_string($appNameVal) ? $appNameVal : 'LogMonitor', ENT_QUOTES, 'UTF-8'));
define('APP_URL', constructUrl());

// Generate a secure cryptographic nonce for inline scripts and styles (CSP protection)
if (! defined('APP_NONCE')) {
    define('APP_NONCE', bin2hex(random_bytes(16)));
}

// Send Content Security Policy header optimized for utilized CDNs and AlpineJS
header(
    "Content-Security-Policy: default-src 'self'; ".
    "script-src 'self' https://cdn.jsdelivr.net 'unsafe-eval' 'nonce-".APP_NONCE."'; ".
    "style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; ".
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; ".
    "img-src 'self' data:; ".
    "connect-src 'self' https://cdn.jsdelivr.net; ".
    "frame-ancestors 'none';"
);

/**
 * Generates a hidden input field for CSRF protection in HTML forms.
 */
if (! function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8').'">';
    }
}
