<?php
/**
 * Helper Functions File
 *
 * Contains global utility functions and basic application settings, including session management, 
 * debug functions, URL construction, authentication management, and flash toast messages.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

use Symfony\Component\VarDumper\VarDumper;

/**
 * Starts the session if not already started.
 * Sets the session lifetime and session cookie parameters to 24 hours.
 */
if (session_id() === '') {
    ini_set('session.gc_maxlifetime', '86400'); // Sets session lifetime to 24 hours
    session_set_cookie_params(86400); // Sets session cookie lifetime to 24 hours
    session_start(); // Starts the session
}

/** @const string The default administrator role. */
define('ROLE_ADMIN', 'admin');

/**
 * Displays the given variables and stops script execution (Debug & Die).
 * Uses Symfony's VarDumper for better variable visualization.
 * 
 * @param mixed ...$args One or more variables to display.
 */
if (!function_exists('dd')) {
    function dd(mixed ...$args): never
    {
        foreach ($args as $x) {
            VarDumper::dump($x);
        }
        exit(1);
    }
}

/**
 * Constructs the base URL of the application dynamically.
 * Automatically detects the protocol, server, and subfolder where the project is installed.
 * 
 * @return string The base URL of the application.
 */
if (!function_exists('constructUrl')) {
    function constructUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim($scriptDir, '/\\');
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https" : "http";
        
        return "{$protocol}://{$host}{$basePath}";
    }
}

/**
 * Checks if the user is authenticated in the system.
 * Manages the logout process if the 'action=logout' parameter is present.
 * 
 * @return bool Returns true if the user has a valid session, otherwise redirects to login.
 */
if (!function_exists('checkAuthUser')) {
    function checkAuthUser(): bool
    {
        // Logout handling
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            unset($_SESSION['auth.user']);
            session_destroy();
            header('Location: login.php');
            exit;
        }

        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');

        if ($currentPage === 'login.php') {
            return true;
        }

        if (isset($_SESSION['auth.user']) && is_array($_SESSION['auth.user'])) {
            return true;
        }

        if (!isset($_SESSION['auth.user']) || empty($_SESSION['auth.user'])) {
            setFlash(
                'warning', 
                __('Atentionare autentificare'), 
                __('Trebuie sa te autentifici pentru a accesa aceasta pagina.')
            );

            header('Location: login.php');
            exit;
        }
        
        return true;
    }
}

/**
 * Returns the authenticated user's data from the session.
 * 
 * @return array|null Array with user data or null if not logged in.
 */
if (!function_exists('getCurrentAuthUser')) {
    function getCurrentAuthUser(): ?array
    {
        $user = $_SESSION['auth.user'] ?? null;
        return is_array($user) ? $user : null;
    }
}

/**
 * Formats a date from SQL format (YYYY-MM-DD) to European format (d.m.Y).
 */
if (!function_exists('formatDate')) {
    function formatDate(string $dateString): string
    {
        return date('d.m.Y', strtotime($dateString) ?: time());
    }
}

/**
 * Checks if the current user has a specific permission (Mockup).
 */
if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return true; 
    }
}

/**
 * Generates a unique CSRF token for the current session.
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

/**
 * Verifies if a received CSRF token matches the one in the session.
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        if (!$token || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}

/**
 * Sets a flash message (temporary notification) in the session to be displayed as a toast.
 */
if (!function_exists('setFlash')) {
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

/**
 * Retrieves the flash message from the session and deletes it immediately (single consumption).
 */
if (!function_exists('getFlash')) {
    function getFlash(): ?array
    {
        if (isset($_SESSION['app_flash'])) {
            $flash = $_SESSION['app_flash'];
            unset($_SESSION['app_flash']);

            return is_array($flash) ? $flash : null;
        }

        return null;
    }
}

/**
 * Returns the current language code (e.g., 'ro', 'en').
 */
if (!function_exists('getLang')) {
    function getLang(): string
    {
        if (!isset($_SESSION['app_lang'])) {
            $_SESSION['app_lang'] = 'ro';
        }
        return $_SESSION['app_lang'];
    }
}

/**
 * Translates a key into the current language.
 */
if (!function_exists('__')) {
    function __(string $key, array $replacements = []): string
    {
        static $translations = null;

        if ($translations === null) {
            $lang = getLang();
            $path = __DIR__ . "/../../lang/{$lang}.json";

            if (file_exists($path)) {
                $translations = json_decode(file_get_contents($path), true) ?: [];
            } else {
                $translations = [];
            }
        }

        $text = $translations[$key] ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $text = str_replace(':' . $placeholder, $value, $text);
        }

        return $text;
    }
}
