<?php

declare(strict_types=1);

namespace App\Utilities;

use Throwable;
use ErrorException;
use RuntimeException;

/**
 * Clasa LogViaStream
 *
 * Centralizeaza si securizeaza logurile prin stream-uri native PHP.
 * Include protectie impotriva DDoS-ului intern si a buclelor infinite (Rate Limiting Local si Global).
 *
 * @category Utilities
 * @package  App\Utilities
 * @version  5.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
final class LogViaStream
{
    private static bool $isLogging = false;
    private static ?string $requestId = null;
    private static ?string $memoryReserve = null;
    private static ?float $startTime = null;
    private static string $apiKey = '';
    private static string $url = '';
    
    // Contor intern pentru request-ul curent (Protectie Bucla Infinite / Foreach)
    private static int $logCountInRequest = 0;
    
    // Limite stricte de siguranta (Configurabile architectural)
    private const int MAX_LOGS_PER_REQUEST = 30;  // Maxim loguri transmise de un singur script/apel
    private const int MAX_LOGS_PER_MINUTE = 300;  // Maxim loguri acceptate global de pe tot serverul intr-un minut

    /**
     * Constructor privat pentru a preveni instantierea unei clase pur statice.
     */
    private function __construct()
    {
    }

    /**
     * Inregistreaza handlerul global si valideaza existenta configuratiilor (Fail-Fast).
     *
     * @throws RuntimeException Daca variabilele de mediu esentiale lipsesc sau sunt invalide.
     */
    public static function registerHandlers(): void
    {
        self::$startTime = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        // Alocare rezerva de memorie (500 KB) pentru situatii de urgenta (OOM)
        self::$memoryReserve = str_repeat('x', 1024 * 500);

        $envApiKey = $_ENV['LOG_API_KEY'] ?? null;
        $envUrl = $_ENV['LOG_SERVER_URL'] ?? null;

        if (!is_string($envApiKey) || trim($envApiKey) === '') {
            throw new RuntimeException('Configuratie invalida: LOG_API_KEY lipseste sau este lipsa.');
        }

        if (!is_string($envUrl) || filter_var($envUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Configuratie invalida: LOG_SERVER_URL lipseste sau nu este un URL valid.');
        }

        self::$apiKey = $envApiKey;
        self::$url = $envUrl;

        // 1. Interceptare erori native PHP prin transformare in ErrorException
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // 2. Interceptare exceptii netratate
        set_exception_handler(static function (Throwable $exception): void {
            self::handleException($exception);
        });

        // 3. Interceptare erori fatale (Shutdown Function) cu protectie de buffer
        register_shutdown_function(static function (): void {
            self::$memoryReserve = null; // Eliberare imediata spatiu RAM

            $error = error_get_last();
            $bufferContent = '';

            // Golire defensiva a bufferelor evitand blocajele infinite
            try {
                while (ob_get_level() > 0) {
                    $status = ob_get_status(true);
                    $currentBuffer = end($status);
                    
                    if (isset($currentBuffer['flags']) && !($currentBuffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
                        ob_end_flush();
                        break;
                    }

                    $content = ob_get_clean();
                    if (is_string($content)) {
                        $bufferContent = $content . $bufferContent;
                    }
                }
            } catch (Throwable) {
                // Ignoram esecul bufferului in faza terminala pentru a nu masca eroarea principala
            }

            $hasFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true));
            $hasOrphanedBuffer = (trim($bufferContent) !== '');

            if ($hasFatalError || $hasOrphanedBuffer) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                if ($hasFatalError) {
                    $message = $error['message'] ?? 'Unknown Fatal Error';
                    $type = match (true) {
                        str_contains($message, 'Allowed memory size') => 'Fatal Out Of Memory (RAM Exceeded)',
                        str_contains($message, 'Maximum execution time') => 'Fatal Execution Timeout',
                        default => 'PHP Fatal Shutdown'
                    };

                    self::send('CRITICAL', "Fatal Error: " . $message, [
                        'file' => $error['file'] ?? 'unknown',
                        'line' => $error['line'] ?? 0,
                        'type' => $type,
                        'captured_output_buffer' => substr($bufferContent, 0, 4000)
                    ]);
                } else {
                    self::send('WARNING', "Script terminat neasteptat cu output buffer nirendat.", [
                        'type' => 'Orphaned Buffer',
                        'captured_output_buffer' => substr($bufferContent, 0, 4000)
                    ]);
                }
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
            if (str_contains(strtolower($message), 'permission denied')) {
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
     * Expediaza logul catre serverul centralizat in mod controlat si securizat.
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        // Preventie recursivitate / bucla circulara pe endpoint-ul API log.php
        $currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($currentScript === 'log.php') {
            error_log(sprintf("[%s] Internal Log: %s | Context: %s", strtoupper($level), $message, json_encode($context)));
            return true;
        }

        // Preventie bucle de recursivitate
        if (self::$isLogging) {
            return false;
        }

        // 1. Rate Limit Local: Opreste scriptul curent daca a generat prea multe loguri (ex: eroare in foreach)
        if (self::$logCountInRequest >= self::MAX_LOGS_PER_REQUEST) {
            error_log("LogViaStream Alert: S-a atins limita maxima de loguri per request (" . self::MAX_LOGS_PER_REQUEST . ").");
            return false;
        }

        // 2. Rate Limit Global: Opreste flood-ul la nivel de server pe minut (Multi-Process Safe)
        if (!self::checkGlobalRateLimit()) {
            error_log("LogViaStream Alert: Rate limit-ul global a fost depasit (" . self::MAX_LOGS_PER_MINUTE . "/min). Log blocat preventiv.");
            return false;
        }

        self::$isLogging = true;
        self::$logCountInRequest++;

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
                $headers = $http_response_header ?? []; // Capturam variabila nativa imediat local
            } finally {
                error_reporting($oldErrorReporting);
            }

            if ($result === false || empty($headers)) {
                error_log("LogMonitor Alert: Serverul de loguri la " . self::$url . " este indisponibil.");
                return false;
            }

            return isset($headers[0]) && str_contains($headers[0], '200');

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
     * Verifica in mod concurent daca s-a atins limita de loguri admisa pe minut la nivel de server web.
     */
    private static function checkGlobalRateLimit(): bool
    {
        $limitFile = sys_get_temp_dir() . '/log_rate_limit.json';
        $now = time();
        $minuteWindow = $now - ($now % 60); // Identificator unic pentru minutul curent

        if (!file_exists($limitFile)) {
            @file_put_contents($limitFile, json_encode(['window' => $minuteWindow, 'count' => 0]));
        }

        $fp = @fopen($limitFile, 'c+');
        if (!$fp) {
            return true; // Fail-open principle: Daca nu putem citi limitatorul, lasam logul sa treaca
        }

        // Lock exclusiv pentru a impiedica race conditions intre procesele FPM paralele
        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $data = json_decode(is_string($content) ? $content : '', true);

            if (!is_array($data) || ($data['window'] ?? 0) !== $minuteWindow) {
                // Minutul s-a schimbat sau structura e invalida -> resetam fereastra de timp
                $data = ['window' => $minuteWindow, 'count' => 1];
            } else {
                $data['count']++;
            }

            if ($data['count'] > self::MAX_LOGS_PER_MINUTE) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false; // Limita globala pe server a fost atinsa!
            }

            // Actualizam fisierul
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
        return true;
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
     * Ascunde datele sensibile din string-urile de conexiune baze de date (Multiline Safe).
     */
    private static function maskDatabaseSecrets(string $message): string
    {
        if (str_contains(strtolower($message), 'ora-01017') || str_contains(strtolower($message), 'logon denied')) {
            $message = (string)preg_replace('/for user\s+[\'"][^\'"]+[\'"]/ims', "for user '******'", $message);
            $message = "Database Failure (Oracle Auth): " . $message;
        } 
        
        $patterns = [
            '/(user|username|uid|pwd|password|pass|host|port|sid|service_name)\s*=\s*[^\s;()"\']+/ims'
        ];
        $message = (string)preg_replace($patterns, '$1=******', $message);
        $message = (string)preg_replace('/(:?\/\/)[^:]+:[^@]+@/ims', '$1******:******@', $message);

        if (!str_contains($message, 'Database Failure')) {
            $message = "Database Failure: " . $message;
        }

        return $message;
    }

    /**
     * Igienizeaza recursiv structurile de date primite ca parametru (Inclusiv JSON ascuns).
     */
    private static function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'api_key'];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeData($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string)$key);
                
                if (in_array($lowerKey, $sensitiveKeys, true)) {
                    $data[$key] = '******';
                } else {
                    // Implementare nativa PHP 8.4 json_validate pentru payload-uri imbricate sub forma de text
                    if (json_validate($value)) {
                        try {
                            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $data[$key] = json_encode(self::sanitizeData($decoded), JSON_UNESCAPED_SLASHES);
                            }
                        } catch (Throwable) {
                            // Ignoram erorile de decodare fortata
                        }
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Masking rapid pentru mesaje text simple.
     */
    private static function sanitizeMessage(string $message): string
    {
        return (string)preg_replace('/(password|pass|pwd|token)\s*=\s*[^\s&]+/ims', '$1=******', $message);
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
