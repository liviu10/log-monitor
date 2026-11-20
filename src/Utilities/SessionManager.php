<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * SessionManager Utility
 *
 * Encapsulates $_SESSION interactions to ensure predictable state management.
 *
 * @category Utilities
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class SessionManager
{
    /**
     * Get a value from the session.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a value in the session.
     */
    public static function set(string $key, mixed $value): void
    {
        if (in_array($key, ['csrf_token'], true)) {
            throw new \RuntimeException('Cannot overwrite critical system session keys.');
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Remove a value from the session.
     */
    public static function remove(string $key): void
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Check if a key exists in the session.
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Clear all session variables.
     */
    public static function clear(): void
    {
        $_SESSION = [];
    }
}
