<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Token Manager Utility (TokenManager)
 *
 * Offers secure functions for generating and validating CSRF tokens or other cryptographic keys.
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class TokenManager
{
    /**
     * Generates a cryptographic CSRF token and saves it in the session.
     *
     * @return string The generated token.
     */
    public static function generateCsrf(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = $_SESSION['csrf_token'] ?? null;
        if (! is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
            $authData = $_SESSION['auth.user'] ?? null;
            $_SESSION['csrf_token_user'] = is_array($authData) ? ($authData['id'] ?? 0) : 0;
        }

        return $token;
    }

    /**
     * Verifies the submitted CSRF token by securely comparing it with the session token.
     *
     * @param  string|null  $token  The token received for validation.
     * @return bool True if the token is valid.
     */
    public static function verifyCsrf(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (! is_string($sessionToken) || ! is_string($token)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Consumes the webhook token, verifying it statelessly.
     * Checks signature against the Core's secret key and validates expiration.
     *
     * @param  string|null  $token  The token received from the webhook header.
     * @return bool True if valid, false otherwise.
     */
    public static function consumeWebhookToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $decoded = base64_decode($token, true);
            if ($decoded === false || strlen($decoded) < 80) {
                return false;
            }

            $iv = substr($decoded, 0, 16);
            $mac = substr($decoded, -64);
            $encrypted = substr($decoded, 16, -64);

            $key = function_exists('config') ? config('app.key') : null;
            if (! is_string($key) || $key === '') {
                $key = 'logmonitor-secret-key-32-chars!!';
            }

            $calculatedMac = hash_hmac('sha256', $iv.$encrypted, $key);
            if (! hash_equals($calculatedMac, $mac)) {
                return false;
            }

            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
            if ($decrypted === false) {
                return false;
            }

            $payload = json_decode($decrypted, true);
            if (! is_array($payload) || ! isset($payload['expires_at'])) {
                return false;
            }

            // Guest/admin API tokens share this format and key: only accept webhook tokens.
            if (($payload['type'] ?? null) !== 'webhook') {
                return false;
            }

            $expiresAt = is_numeric($payload['expires_at']) ? (int) $payload['expires_at'] : 0;
            if (time() > $expiresAt) {
                return false;
            }

            return true;
            // @codeCoverageIgnoreStart
        } catch (\Throwable) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }
}
