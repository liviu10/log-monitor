<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load Environment Variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Global Helpers
require_once __DIR__ . '/src/Utilities/helpers.php';

// App Configuration
define('APP_NAME', $_ENV['APP_NAME'] ?? 'LogMonitor');
define('APP_URL', constructUrl());

/**
 * Generează un câmp de input ascuns pentru protecție CSRF în formulare HTML.
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
    }
}
