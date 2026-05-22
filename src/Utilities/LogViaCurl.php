<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Clasa LogViaCurl
 *
 * Permite trimiterea securizata a logurilor catre o componenta centralizata prin cURL.
 * Implementeaza protectie la bucle si urmarire avansata a exceptiilor in blocurile catch.
 *
 * @category Utilitare
 * @package  App\Utilities
 * @version  1.4
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class LogViaCurl
{
    /** @var bool $isLogging Indicator de stare pentru evitarea buclelor infinite la erori de DB. */
    private static bool $isLogging = false;

    /**
     * Expediaza o inregistrare de log catre endpoint-ul configurat.
     *
     * @param string $level Nivelul de severitate (ex: INFO, ERROR, DEBUG).
     * @param string $message Mesajul principal al logului.
     * @param array $context Informatii suplimentare de context.
     * @return bool True daca transmisia s-a finalizat cu status HTTP 200.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        if (self::$isLogging) {
            return false;
        }

        self::$isLogging = true;

        try {
            $apiKey = self::getApiKey();
            if ($apiKey === '') {
                return false;
            }

            $url = $_ENV['LOG_SERVER_URL'] ?? 'http://127.0.0.1/log.php';

            $payload = json_encode([
                'level' => strtoupper($level),
                'message' => $message,
                'context' => $context
            ], JSON_THROW_ON_ERROR);

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

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Throwable $e) {
            error_log(json_encode([
                'error' => 'Critical failure inside cURL logging transmission',
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'LogViaCurl_Transmission_Failure'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return false;
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Prelucreaza si returneaza cheia API din tabela aplicatiilor autorizate.
     * Daca citirea din baza de date esueaza, jurnalizeaza local eroarea si comuta pe .env.
     *
     * @return string Cheia API gasita, cheia din .env ca fallback, sau string gol.
     */
    private static function getApiKey(): string
    {
        try {
            $db = MySQLWrapper::getInstance();
            $apps = $db->read('apps', []);
            
            if ($apps === false || empty($apps)) {
                return (string)($_ENV['LOG_API_KEY'] ?? '');
            }
            
            return (string)($apps[0]['api_key'] ?? '');
        } catch (\Throwable $e) {
            // Jurnalizam local eroarea de baza de date cu toate detaliile tehnice (stil Laravel)
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
            
            // Returnam cheia de rezerva din .env pentru a lasa cURL sa trimita eroarea mai departe
            return (string)($_ENV['LOG_API_KEY'] ?? '');
        }
    }
}