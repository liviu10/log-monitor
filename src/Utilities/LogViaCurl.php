<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Clasa LogViaCurl
 *
 * Oferă funcționalitate utilitară pentru trimiterea logurilor către endpoint-ul API log.php prin cURL.
 * Aceasta poate fi apelată global în cadrul proiectului.
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
    /** @var bool Flag pentru prevenirea recursivității infinite la logarea erorilor din fazele DB. */
    private static bool $isLogging = false;

    /**
     * Trimite un log către log.php prin cURL.
     *
     * @param string $level   Nivelul de logare (ex: 'INFO', 'ERROR', 'DEBUG').
     * @param string $message Mesajul de log.
     * @param array  $context Informații suplimentare de context (opțional).
     * @return bool True dacă logul a fost trimis și înregistrat cu succes, false altfel.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        // Protecție recursivitate infinită
        if (self::$isLogging) {
            return false;
        }

        self::$isLogging = true;

        try {
            $apiKey = self::getApiKey();
            if (empty($apiKey)) {
                return false;
            }

            // Determinăm URL-ul serverului de logare din env sau fallback local
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
     * Obține o cheie API validă din baza de date pentru utilizare în request-ul cURL.
     *
     * @return string Cheia API sau un șir gol în caz de eroare.
     */
    private static function getApiKey(): string
    {
        try {
            $db = MySQLWrapper::getInstance();
            $apps = $db->read('apps', [], ['limit' => 1]);
            return !empty($apps) ? (string)$apps[0]['api_key'] : '';
        } catch (\Throwable $e) {
            // Fallback în error_log-ul sistemului dacă baza de date este căzută total
            error_log(json_encode([
                'error' => 'Eroare la preluarea cheii API pentru cURL',
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
