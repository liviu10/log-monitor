<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * LogViaCurl Class
 *
 * Provides utility functionality for sending logs to the log.php API endpoint via cURL.
 * Can be called globally within the project.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class LogViaCurl
{
    /** @var bool Flag to prevent infinite recursion when logging DB errors. */
    private static bool $isLogging = false;

    /**
     * Sends a log to log.php via cURL.
     *
     * @param string $level   Log level (e.g., 'INFO', 'ERROR', 'DEBUG').
     * @param string $message Log message.
     * @param array  $context Additional context information (optional).
     * @return bool True if the log was sent and recorded successfully, false otherwise.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        // Infinite recursion protection
        if (self::$isLogging) {
            return false;
        }

        self::$isLogging = true;

        try {
            $apiKey = self::getApiKey();
            if (empty($apiKey)) {
                return false;
            }

            // Determine logging server URL from env or local fallback
            $url = $_ENV['LOG_SERVER_URL'] ?? 'http://127.0.0.1/log.php';

            $payload = json_encode([
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context
            ]);

            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $apiKey,
                'User-Agent: LogMonitor-Internal/1.0'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Gets a valid API key from the database for use in the cURL request.
     *
     * @return string The API key or an empty string if an error occurs.
     */
    private static function getApiKey(): string
    {
        try {
            $db = MySQLWrapper::getInstance();
            $apps = $db->read('apps', []);
            return !empty($apps) ? (string)$apps[0]['api_key'] : '';
        } catch (\Throwable $e) {
            // Fallback to system error_log if the database is completely down
            error_log(json_encode([
                'error' => 'Error retrieving API key for cURL',
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'LogViaCurl_DB_Access'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return '';
        }
    }
}
