<?php

declare(strict_types=1);

/**
 * Global Helper Functions File
 *
 * Contains global utility functions and core configurations for session and security.
 * All translation keys use English for consistency.
 *
 * @category Utilities
 *
 * @version  2.5
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */

use Symfony\Component\VarDumper\VarDumper;

/**
 * Injects security headers at PHP runtime level.
 * Ensures protection on classic servers (where configuration access is missing)
 * and provides a second layer of protection (Defense in Depth) in containers.
 *
 * @return void
 */
if (! function_exists('injectRuntimeSecurityHeaders')) {
    function injectRuntimeSecurityHeaders(): void
    {
        // Prevents Clickjacking attacks by blocking iframe embedding
        header('X-Frame-Options: DENY', true);

        // Prevents MIME sniffing attacks
        header('X-Content-Type-Options: nosniff', true);

        // Sets the Referrer-Policy header policy
        header('Referrer-Policy: strict-origin-when-cross-origin', true);

        // Strict policy for executing Vanilla JS scripts and resources
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; style-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self';", true);

        // Activate HSTS if request is secure
        $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (($_SERVER['SERVER_PORT'] ?? '') === '443') ||
                   (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload', true);
        }
    }
}

/** @const string ROLE_ADMIN The identifier for the administrator role. */
define('ROLE_ADMIN', 'admin');

/**
 * Dumps the received variables and terminates script execution (Dump and Die).
 * Uses Symfony's VarDumper component for a clean view.
 *
 * @param  mixed  ...$args  One or more variables to be inspected.
 * @return never Definitive termination of program execution.
 */
if (! function_exists('dd')) {
    function dd(mixed ...$args): never
    {
        foreach ($args as $x) {
            VarDumper::dump($x);
        }
        exit(1);
    }
}

/**
 * Dynamically builds the base URL of the application.
 * Automatically detects the secure protocol, server, and installation subdirectory.
 * Prevents Host Header Injection attacks through strict validation.
 *
 * @return string Complete base URL of the application.
 */
if (! function_exists('constructUrl')) {
    function constructUrl(): string
    {
        $rawHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Strict validation of the host to prevent injection
        $host = filter_var($rawHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($host === false) {
            $host = 'localhost';
        }

        $rawScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = str_replace('\\', '/', dirname(is_string($rawScriptName) ? $rawScriptName : ''));
        $basePath = rtrim($scriptDir, '/\\');

        $isHttps = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (($_SERVER['SERVER_PORT'] ?? '') === 443) ||
                   (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $protocol = $isHttps ? 'https' : 'http';

        return "{$protocol}://{$host}{$basePath}";
    }
}

/**
 * Verifies authentication state and handles the logout process.
 * Applies the Fail Fast principle and regenerates the session to prevent Session Fixation.
 *
 * @return bool Returns true if the user has a valid session.
 */
if (! function_exists('checkAuthUser')) {
    function checkAuthUser(): bool
    {
        // Handle logout action
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            unset($_SESSION['auth.user']);
            session_destroy();

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);

            header('Location: login.php');
            exit;
        }

        $rawPhpSelf = $_SERVER['PHP_SELF'] ?? '';
        $currentPage = basename(is_string($rawPhpSelf) ? $rawPhpSelf : '');

        if ($currentPage === 'login.php') {
            return true;
        }

        if (isset($_SESSION['auth.user']) && is_array($_SESSION['auth.user'])) {
            return true;
        }

        // Set flash message with exclusively English keys
        setFlash(
            'warning',
            __('Authentication Warning'),
            __('You must log in to access this page.')
        );

        header('Location: login.php');
        exit;
    }
}

if (! function_exists('getCurrentAuthUser')) {
    /**
     * Returns the authenticated user's data from the current session.
     *
     * @return array<string, mixed>|null Array of user data or null if not authenticated.
     */
    function getCurrentAuthUser(): ?array
    {
        $user = $_SESSION['auth.user'] ?? null;

        if (is_array($user)) {
            /** @var array<string, mixed> $user */
            return $user;
        }

        return null;
    }
}

/**
 * Formates a date from SQL format to standard European format.
 *
 * @param  string  $dateString  Date string (e.g., Y-m-d).
 * @return string Formatted date as day.month.year.
 */
if (! function_exists('formatDate')) {
    function formatDate(string $dateString): string
    {
        $timestamp = strtotime($dateString);

        return date('d.m.Y', $timestamp ?: time());
    }
}

/**
 * Checks if the current user has a specific permission (Mockup).
 *
 * @param  string  $permission  Checked permission identifier.
 * @return bool True if permission is granted.
 */
if (! function_exists('can')) {
    function can(string $permission): bool
    {
        return true;
    }
}

/**
 * Generates a cryptographic CSRF token and saves it in the session.
 *
 * @return string Generated token in hexadecimal format.
 */
if (! function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $token = $_SESSION['csrf_token'] ?? '';

        return is_string($token) ? $token : '';
    }
}

/**
 * Verifies validity of a CSRF token using a comparison immune to timing attacks.
 *
 * @param  string|null  $token  Received token for verification.
 * @return bool True if token matches the one in session.
 */
if (! function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        if (! $token || empty($_SESSION['csrf_token'])) {
            return false;
        }

        $sessionToken = $_SESSION['csrf_token'];

        return hash_equals(is_string($sessionToken) ? $sessionToken : '', $token);
    }
}

/**
 * Registers a temporary flash message in the application session.
 *
 * @param  string  $type  Message type (e.g. success, error, warning).
 * @param  string  $title  Notification title.
 * @param  string  $message  Descriptive notification text.
 * @param  int  $toastDelay  Display time in milliseconds.
 * @param  string  $redirectUrl  Optional URL for redirection.
 * @return void
 */
if (! function_exists('setFlash')) {
    function setFlash(string $type, string $title, string $message, int $toastDelay = 10000, string $redirectUrl = ''): void
    {
        $_SESSION['app_flash'] = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'toastDelay' => $toastDelay,
            'redirectUrl' => $redirectUrl,
        ];
    }
}

if (! function_exists('getFlash')) {
    /**
     * Extracts and deletes the existing flash message in the session (single consumption).
     *
     * @return array<string, mixed>|null Flash message data or null if not present.
     */
    function getFlash(): ?array
    {
        if (isset($_SESSION['app_flash'])) {
            $flash = $_SESSION['app_flash'];
            unset($_SESSION['app_flash']);

            if (is_array($flash)) {
                /** @var array<string, mixed> $flash */
                return $flash;
            }
        }

        return null;
    }
}

/**
 * Returns the code for the active language in the application.
 *
 * @return string Language code (default 'en').
 */
if (! function_exists('getLang')) {
    function getLang(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 'en';
        }
        if (! isset($_SESSION['app_lang'])) {
            $_SESSION['app_lang'] = 'en';
        }

        $lang = $_SESSION['app_lang'];

        return is_string($lang) ? $lang : 'en';
    }
}

if (! function_exists('__')) {
    /**
     * Translates a text key and safely replaces dynamic parameters to prevent XSS.
     * Uses native PHP 8.4 json_validate for enhanced safety.
     *
     * @param  string  $key  Translation frame key.
     * @param  array<string, string|int|float|bool>  $replacements  Associative array of replacement parameters.
     * @return string Final translated and sanitized text.
     */
    function __(string $key, array $replacements = []): string
    {
        static $translations = null;

        if ($translations === null) {
            $lang = getLang();
            $path = __DIR__."/../../lang/{$lang}.json";

            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false && json_validate($content)) {
                    $translations = json_decode($content, true) ?: [];
                } else {
                    $translations = [];
                }
            } else {
                $translations = [];
            }
        }

        if (! is_array($translations)) {
            $translations = [];
        }

        $text = $translations[$key] ?? $key;
        $text = is_string($text) ? $text : $key;

        foreach ($replacements as $placeholder => $value) {
            $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $text = str_replace(':'.$placeholder, $safeValue, $text);
        }

        return $text;
    }
}
