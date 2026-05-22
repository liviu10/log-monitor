<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Abordare Fail Fast: Oprirea imediata a executiei daca fisierul .env lipseste
if (!file_exists(__DIR__ . '/.env')) {
    http_response_code(500);
    
    $isApi = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'log.php');
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

// Determinarea scriptului curent solicitat
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Sesiunea se porneste DOAR daca nu suntem pe endpoint-ul de logare prin cURL (API)
if ($currentScript !== 'log.php' && session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
}

// Includere utilitare globale
require_once __DIR__ . '/src/Utilities/helpers.php';

// Configurare aplicatie securizata contra atacurilor de tip Injection
define('APP_NAME', htmlspecialchars($_ENV['APP_NAME'] ?? 'LogMonitor', ENT_QUOTES, 'UTF-8'));
define('APP_URL', constructUrl());

/**
 * Genereaza un camp input ascuns pentru protectia CSRF in formularele HTML.
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}