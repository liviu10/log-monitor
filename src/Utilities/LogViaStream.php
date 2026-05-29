<?php

declare(strict_types=1);

namespace App\Utilities;

use Throwable;
use ErrorException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Clasa LogViaStream
 *
 * Centralizeaza si securizeaza logurile prin stream-uri native PHP.
 * Include protectie avansata cu memorie de rezerva pentru crash-uri de tip Out of Memory si Timeout iminent.
 *
 * @category Utilities
 * @package  App\Utilities
 * @version  4.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class LogViaStream
{
    private static bool $isLogging = false;
    private static ?string $requestId = null;
    private static ?string $memoryReserve = null;
    private static ?float $startTime = null;
    private static string $apiKey = '';
    private static string $url = '';

    /**
     * Inregistreaza handlerul global si valideaza existenta configuratiilor (Fail-Fast).
     * * @throws RuntimeException Daca variabilele de mediu esentiale lipsesc.
     */
    public static function registerHandlers(): void
    {
        self::$startTime = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        // Alocare rezerva de memorie (500 KB) pentru situatii de urgenta (OOM)
        self::$memoryReserve = str_repeat('x', 1024 * 500);

        // Validare stricta a configuratiilor conform principiului Fail-Fast
        $envApiKey = $_ENV['LOG_API_KEY'] ?? null;
        $envUrl = $_ENV['LOG_SERVER_URL'] ?? null;

        if (!is_string($envApiKey) || trim($envApiKey) === '') {
            throw new RuntimeException('Configuratie invalida: LOG_API_KEY lipseste sau este vida.');
        }

        if (!is_string($envUrl) || filter_var($envUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Configuratie invalida: LOG_SERVER_URL lipseste sau nu este un URL valid.');
        }

        self::$apiKey = $envApiKey;
        self::$url = $envUrl;

        // 1. Interceptare erori native PHP prin transformare in ErrorException (Best Practice)
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            
            // Transformam eroarea intr-o exceptie pentru a unifica fluxul defensiv
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // 2. Interceptare exceptii netratate (Inclusiv ErrorException de mai sus)
        set_exception_handler(static function (Throwable $exception): void {
            self::handleException($exception);
        });

        // 3. Interceptare erori fatale (Shutdown Function)
        register_shutdown_function(static function (): void {
            self::$memoryReserve = null; // Eliberare imediata spatiu RAM pentru procesarea finala

            $error = error_get_last();
            $bufferContent = '';

            while (ob_get_level() > 0) {
                $content = ob_get_clean();
                if ($content !== false) {
                    $bufferContent = $content . $bufferContent;
                }
            }

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $type = match (true) {
                    str_contains($error['message'], 'Allowed memory size') => 'Fatal Out Of Memory (RAM Exceeded)',
                    str_contains($error['message'], 'Maximum execution time') => 'Fatal Execution Timeout',
                    default => 'PHP Fatal Shutdown'
                };

                self::send('CRITICAL', "Fatal Error: " . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $type,
                    'captured_output_buffer' => substr($bufferContent, 0, 4000)
                ]);
            } elseif ($bufferContent !== '') {
                self::send('WARNING', "Script terminat neasteptat cu output buffer nirendat.", [
                    'type' => 'Orphaned Output Buffer',
                    'captured_output_buffer' => substr($bufferContent, 0, 4000)
                ]);
            }
        });
    }

    /**
     * Proceseaza si formateaza exceptiile interceptate.
     */
    private static function handleException(Throwable $exception): void
    {
        $context = [
            'file'  => $exception->getFile(),
            'line'  => $exception->getLine(),
            'code'  => $exception->getCode(),
            'trace' => self::formatTrace($exception)
        ];

        $level = 'CRITICAL';
        $message = $exception->getMessage();

        if ($exception instanceof ErrorException) {
            $severity = $exception->getSeverity();
            $level = match ($severity) {
                E_WARNING, E_USER_WARNING => 'WARNING',
                E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'INFO',
                default => 'ERROR'
            };
            
            $context['type'] = 'PHP Native Error';
            if (str_contains($message, 'permission denied')) {
                $context['type'] = 'File System Permission Error';
            }
        } elseif ($exception instanceof \PDOException || str_contains($exception::class, 'OCI')) {
            $context['type'] = 'Database Infrastructure Error';
            $message = self::maskDatabaseSecrets($message);
        } else {
            $context['type'] = $exception::class;
            $message = "Exception: " . $message;
        }

        self::send($level, $message, $context);
    }

    /**
     * Expediaza logul catre serverul centralizat.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        if (self::$isLogging) {
            return false;
        }

        self::$isLogging = true;

        try {
            if (self::$requestId === null) {
                self::$requestId = bin2hex(random_bytes(6));
            }

            $networkTimeout = self::calculateDynamicTimeout();

            $extendedContext = array_merge([
                'request_id'   => self::$requestId,
                'http_method'  => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'uri'          => $_SERVER['REQUEST_URI'] ?? 'N/A',
                'ip'           => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'referrer'     => $_SERVER['HTTP_REFERER'] ?? 'DIRECT',
                'query_params' => !empty($_GET) ? self::sanitizeData($_GET) : null,
                'post_data'    => !empty($_POST) ? self::sanitizeData($_POST) : null,
                'memory_usage' => self::formatBytes(memory_get_usage(true)),
                'peak_memory'  => self::formatBytes(memory_get_peak_usage(true))
            ], $context);

            $payload = json_encode([
                'level'   => strtoupper($level),
                'message' => self::sanitizeMessage($message),
                'context' => $extendedContext
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $options = [
                'http' => [
                    'method'        => 'POST',
                    'header'        => [
                        'Content-Type: application/json',
                        'X-API-KEY: ' . self::$apiKey,
                        'User-Agent: LogMonitor-Internal/1.0'
                    ],
                    'content'       => $payload,
                    'ignore_errors' => true,
                    'timeout'       => $networkTimeout
                ]
            ];

            $streamContext = stream_context_create($options);
            
            $oldErrorReporting = error_reporting(0);
            try {
                $result = file_get_contents(self::$url, false, $streamContext);
            } finally {
                error_reporting($oldErrorReporting);
            }

            if ($result === false || !isset($http_response_header)) {
                error_log("LogMonitor Alert: Serverul de loguri la " . self::$url . " este indisponibil.");
                return false;
            }

            return str_contains($http_response_header[0], '200');

        } catch (Throwable $e) {
            error_log(json_encode([
                'error'             => 'Eroare critica in transmisia logului prin Stream',
                'exception_message' => $e->getMessage(),
                'identifier'        => 'LogViaStream_Transmission_Failure'
            ], JSON_UNESCAPED_SLASHES));

            return false;
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Calculeaza dinamic timeout-ul de retea ramas disponibil.
     */
    private static function calculateDynamicTimeout(): float
    {
        $maxPhpTime = (int)ini_get('max_execution_time');
        $networkTimeout = 2.5;

        if ($maxPhpTime > 0 && self::$startTime !== null) {
            $elapsedTime = microtime(true) - self::$startTime;
            $timeLeft = $maxPhpTime - $elapsedTime;

            if ($timeLeft < 2.0) {
                $networkTimeout = max(0.5, $timeLeft - 0.2);
            }
        }

        return $networkTimeout;
    }

    /**
     * Ascunde datele sensibile din string-urile de conexiune baze de date.
     */
    private static function maskDatabaseSecrets(string $message): string
    {
        if (str_contains($message, 'ORA-01017') || str_contains($message, 'logon denied')) {
            $message = preg_replace('/for user\s+[\'"][^\'"]+[\'"]/i', "for user '******'", $message);
            $message = "Database Failure (Oracle Auth): " . $message;
        } 
        
        $patterns = [
            '/(user|username|uid|pwd|password|pass|host|port|sid|service_name)=\s*[^\s;()"\']+/i'
        ];
        $message = (string)preg_replace($patterns, '$1=******', $message);
        $message = (string)preg_replace('/(:?\/\/)[^:]+:[^@]+@/i', '$1******:******@', $message);

        if (!str_contains($message, 'Database Failure')) {
            $message = "Database Failure: " . $message;
        }

        return $message;
    }

    /**
     * Igienizeaza recursiv structurile de date primite ca parametru.
     */
    private static function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'api_key'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeData($value);
            } elseif (in_array(strtolower((string)$key), $sensitiveKeys, true)) {
                $data[$key] = '******';
            }
        }
        return $data;
    }

    /**
     * Masking rapid pentru mesaje text simple.
     */
    private static function sanitizeMessage(string $message): string
    {
        return (string)preg_replace('/(password|pass|pwd|token)=\s*[^\s&]+/i', '$1=******', $message);
    }

    /**
     * Formateaza stack trace-ul exceptiei intr-un mod lizibil.
     */
    private static function formatTrace(Throwable $exception): array
    {
        $trace = [];
        $rawTrace = $exception->getTrace();
        $counter = 0;

        foreach ($rawTrace as $step) {
            if ($counter++ >= 10) {
                break; 
            }
            $trace[] = sprintf(
                "#%d %s(%d): %s%s%s()",
                $counter,
                $step['file'] ?? 'unknown_file',
                $step['line'] ?? 0,
                $step['class'] ?? '',
                $step['type'] ?? '',
                $step['function'] ?? 'unknown_function'
            );
        }
        return $trace;
    }

    /**
     * Transforma octetii intr-un format uman lizibil.
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? (int)floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}