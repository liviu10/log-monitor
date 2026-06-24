<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Includere utilitare globale mai devreme pentru a avea acces la functia __()
require_once __DIR__ . '/src/Utilities/helpers.php';

// Determinarea scriptului curent solicitat
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Abordare Fail Fast: Oprirea imediata a executiei daca fisierul .env lipseste
if (!file_exists(__DIR__ . '/.env')) {
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

// Incarcare securizata a variabilelor de mediu
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Inregistrare error handler global pentru stream logging
\App\Utilities\LogViaStream::registerHandlers();

// Sesiunea se porneste DOAR daca nu suntem pe endpoint-ul de logare prin cURL (API)
if ($currentScript !== 'log.php') {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', '86400');
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    }
    
    // Injectam headerele de securitate runtime specifice aplicatiei web/dashboard
    injectRuntimeSecurityHeaders();
}

// Configurare aplicatie securizata contra atacurilor de tip Injection
define('APP_NAME', htmlspecialchars($_ENV['APP_NAME'] ?? 'LogMonitor', ENT_QUOTES, 'UTF-8'));
define('APP_URL', constructUrl());

// Generare Nonce criptografic securizat pentru blocuri de script si stil inline (Protectie CSP)
if (!defined('APP_NONCE')) {
    define('APP_NONCE', bin2hex(random_bytes(16)));
}

// Trimitere header Content Security Policy optimizat pentru CDN-urile utilizate si AlpineJS
header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' https://cdn.jsdelivr.net 'unsafe-eval' 'nonce-" . APP_NONCE . "'; " .
    "style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; " .
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self' https://cdn.jsdelivr.net; " .
    "frame-ancestors 'none';"
);

/**
 * Genereaza un camp input ascuns pentru protectia CSRF in formularele HTML.
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}