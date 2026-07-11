<?php

declare(strict_types=1);

namespace App\Utilities;

use ErrorException;
use RuntimeException;
use Throwable;

/**
 * LogViaStream Class
 *
 * Centralizes and secures logs via native PHP streams.
 * Includes protection against internal DDoS and infinite loops (Local and Global Rate Limiting).
 *
 * @category Utilities
 *
 * @version  5.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class LogViaStream
{
    private static bool $isLogging = false;

    private static ?string $requestId = null;

    private static ?string $memoryReserve = null;

    private static ?float $startTime = null;

    private static string $apiKey = '';

    private static string $url = '';

    // Internal counter for the current request (Infinite Loop / Foreach Protection)
    private static int $logCountInRequest = 0;

    // Strict safety limits (Architecturally configurable)
    private const int MAX_LOGS_PER_REQUEST = 30;  // Maximum logs transmitted by a single script/call

    private const int MAX_LOGS_PER_MINUTE = 300;  // Maximum logs accepted globally from the entire server in one minute

    /**
     * Private constructor to prevent instantiation of a purely static class.
     */
    private function __construct() {}

    /**
     * Registers the global handler and validates config presence (Fail-Fast).
     *
     * @throws RuntimeException If essential environment variables are missing or invalid.
     */
    public static function registerHandlers(): void
    {
        $rawRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        self::$startTime = (is_float($rawRequestTime) || is_numeric($rawRequestTime)) ? (float) $rawRequestTime : microtime(true);

        // Allocate memory reserve (500 KB) for emergency situations (OOM)
        self::$memoryReserve = str_repeat('x', 1024 * 500);

        $envApiKey = $_ENV['LOG_API_KEY'] ?? null;
        $envUrl = $_ENV['LOG_SERVER_URL'] ?? null;

        if (! is_string($envApiKey) || trim($envApiKey) === '') {
            throw new RuntimeException('Invalid configuration: LOG_API_KEY is missing or empty.');
        }

        if (! is_string($envUrl) || filter_var($envUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Invalid configuration: LOG_SERVER_URL is missing or not a valid URL.');
        }

        self::$apiKey = $envApiKey;
        self::$url = $envUrl;

        // 1. Intercept native PHP errors by converting them to ErrorException
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (! (error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // 2. Intercept unhandled exceptions
        set_exception_handler(static function (Throwable $exception): void {
            self::handleException($exception);
        });

        // 3. Intercept fatal errors (Shutdown Function) with buffer protection
        register_shutdown_function(static function (): void {
            $reserved = self::$memoryReserve;
            self::$memoryReserve = null; // Immediate release of RAM space
            unset($reserved);

            $error = error_get_last();
            $bufferContent = '';

            // Defensive clearing of buffers, avoiding infinite locks
            try {
                while (ob_get_level() > 0) {
                    $status = ob_get_status(true);
                    $currentBuffer = end($status);

                    if (is_array($currentBuffer) && isset($currentBuffer['flags'])) {
                        $flags = $currentBuffer['flags'];
                        if ((is_int($flags) || is_string($flags)) && ! ((int) $flags & PHP_OUTPUT_HANDLER_REMOVABLE)) {
                            ob_end_flush();
                            break;
                        }
                    }

                    $content = ob_get_clean();
                    if ($content) {
                        $bufferContent = $content.$bufferContent;
                    }
                }
            } catch (Throwable) {
                // Ignore buffer failure in the terminal phase to avoid masking the main error
            }

            $hasFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true));
            $hasOrphanedBuffer = (trim($bufferContent) !== '');

            if ($hasFatalError || $hasOrphanedBuffer) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                if ($hasFatalError) {
                    $message = $error['message'];
                    $type = match (true) {
                        str_contains($message, 'Allowed memory size') => 'Fatal Out Of Memory (RAM Exceeded)',
                        str_contains($message, 'Maximum execution time') => 'Fatal Execution Timeout',
                        default => 'PHP Fatal Shutdown'
                    };

                    self::send('CRITICAL', 'Fatal Error: '.$message, [
                        'file' => $error['file'],
                        'line' => $error['line'],
                        'type' => $type,
                        'captured_output_buffer' => substr($bufferContent, 0, 4000),
                    ]);
                } else {
                    self::send('WARNING', 'Script terminated unexpectedly with unrendered output buffer.', [
                        'type' => 'Orphaned Buffer',
                        'captured_output_buffer' => substr($bufferContent, 0, 4000),
                    ]);
                }
            }
        });
    }

    /**
     * Processes and formats intercepted exceptions.
     */
    private static function handleException(Throwable $exception): void
    {
        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => self::formatTrace($exception),
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
            $message = 'Exception: '.$message;
        }

        self::send($level, $message, $context);
    }

    /**
     * Dispatches the log to the centralized server in a controlled and secure manner.
     *
     * @param  array<string, mixed>  $context
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        // Prevent recursion / circular loop on the API log.php endpoint
        $rawScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $currentScript = basename(is_string($rawScriptName) ? $rawScriptName : '');
        if ($currentScript === 'log.php') {
            error_log(sprintf('[%s] Internal Log: %s | Context: %s', strtoupper($level), $message, json_encode($context)));

            return true;
        }

        // Prevent recursion loops
        if (self::$isLogging) {
            return false;
        }

        // 1. Local Rate Limit: Stops the current script if it generated too many logs (e.g. error in foreach)
        if (self::$logCountInRequest >= self::MAX_LOGS_PER_REQUEST) {
            error_log('LogViaStream Alert: S-a atins limita maxima de loguri per request ('.self::MAX_LOGS_PER_REQUEST.').');

            return false;
        }

        // 2. Global Rate Limit: Stops flooding at the server level per minute (Multi-Process Safe)
        if (! self::checkGlobalRateLimit()) {
            error_log('LogViaStream Alert: Rate limit-ul global a fost depasit ('.self::MAX_LOGS_PER_MINUTE.'/min). Log blocat preventiv.');

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
                'request_id' => self::$requestId,
                'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'referrer' => $_SERVER['HTTP_REFERER'] ?? 'DIRECT',
                'query_params' => ! empty($_GET) ? self::sanitizeData($_GET) : null,
                'post_data' => ! empty($_POST) ? self::sanitizeData($_POST) : null,
                'memory_usage' => self::formatBytes(memory_get_usage(true)),
                'peak_memory' => self::formatBytes(memory_get_peak_usage(true)),
            ], $context);

            $payload = json_encode([
                'level' => strtoupper($level),
                'message' => self::sanitizeMessage($message),
                'context' => $extendedContext,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/json',
                        'X-API-KEY: '.self::$apiKey,
                        'User-Agent: LogMonitor-Internal/1.0',
                    ],
                    'content' => $payload,
                    'ignore_errors' => true,
                    'timeout' => $networkTimeout,
                ],
            ];

            if (($_ENV['APP_ENV'] ?? '') === 'development') {
                $options['ssl'] = [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ];
            }

            $streamContext = stream_context_create($options);

            $oldErrorReporting = error_reporting(0);
            try {
                $result = file_get_contents(self::$url, false, $streamContext);
                $headers = $http_response_header; // Capturam variabila nativa imediat local
            } finally {
                error_reporting($oldErrorReporting);
            }

            if ($result === false || empty($headers)) {
                error_log('LogMonitor Alert: Serverul de loguri la '.self::$url.' este indisponibil.');

                return false;
            }

            return str_contains($headers[0], '200');

        } catch (Throwable $e) {
            $logMsg = json_encode([
                'error' => 'Critical error in log transmission via Stream',
                'exception_message' => $e->getMessage(),
                'identifier' => 'LogViaStream_Transmission_Failure',
            ], JSON_UNESCAPED_SLASHES);
            if (is_string($logMsg)) {
                error_log($logMsg);
            }

            return false;
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Concurrently checks if the allowed log limit per minute has been reached at the web server level.
     */
    private static function checkGlobalRateLimit(): bool
    {
        $limitFile = sys_get_temp_dir().'/log_rate_limit.json';
        $now = time();
        $minuteWindow = $now - ($now % 60); // Unique identifier for the current minute

        if (! file_exists($limitFile)) {
            @file_put_contents($limitFile, json_encode(['window' => $minuteWindow, 'count' => 0]));
        }

        $fp = @fopen($limitFile, 'c+');
        if (! $fp) {
            return true; // Fail-open principle: If we cannot read the limiter, let the log pass
        }

        // Exclusive lock to prevent race conditions between parallel FPM processes
        if (flock($fp, LOCK_EX)) {
            $content = stream_get_contents($fp);
            $data = json_decode(is_string($content) ? $content : '', true);

            if (! is_array($data) || ($data['window'] ?? 0) !== $minuteWindow) {
                // The minute has changed or the structure is invalid -> reset the time window
                $data = ['window' => $minuteWindow, 'count' => 1];
            } else {
                $count = $data['count'] ?? 0;
                $data['count'] = (is_int($count) ? $count : 0) + 1;
            }

            if ($data['count'] > self::MAX_LOGS_PER_MINUTE) {
                flock($fp, LOCK_UN);
                fclose($fp);

                return false; // Limita globala pe server a fost atinsa!
            }

            // Actualizam fisierul
            ftruncate($fp, 0);
            rewind($fp);
            $encodedData = json_encode($data);
            if (is_string($encodedData)) {
                fwrite($fp, $encodedData);
            }
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);

        return true;
    }

    /**
     * Dynamically calculates the remaining network timeout available.
     */
    private static function calculateDynamicTimeout(): float
    {
        $maxPhpTime = (int) ini_get('max_execution_time');
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
     * Hides sensitive data in database connection strings (Multiline Safe).
     */
    private static function maskDatabaseSecrets(string $message): string
    {
        if (str_contains(strtolower($message), 'ora-01017') || str_contains(strtolower($message), 'logon denied')) {
            $message = (string) preg_replace('/for user\s+[\'"][^\'"]+[\'"]/ims', "for user '******'", $message);
            $message = 'Database Failure (Oracle Auth): '.$message;
        }

        $patterns = [
            '/(user|username|uid|pwd|password|pass|host|port|sid|service_name)\s*=\s*[^\s;()"\']+/ims',
        ];
        $message = (string) preg_replace($patterns, '$1=******', $message);
        $message = (string) preg_replace('/(:?\/\/)[^:]+:[^@]+@/ims', '$1******:******@', $message);

        if (! str_contains($message, 'Database Failure')) {
            $message = 'Database Failure: '.$message;
        }

        return $message;
    }

    /**
     * Recursively sanitizes data structures received as parameters (Including hidden JSON).
     *
     * @param  array<array-key, mixed>  $data  Data to sanitize.
     * @return array<array-key, mixed> Sanitized data.
     */
    private static function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'api_key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeData($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string) $key);

                if (in_array($lowerKey, $sensitiveKeys, true)) {
                    $data[$key] = '******';
                } else {
                    // Native PHP 8.4 json_validate implementation for nested text payloads
                    if (json_validate($value)) {
                        try {
                            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded)) {
                                $data[$key] = json_encode(self::sanitizeData($decoded), JSON_UNESCAPED_SLASHES);
                            }
                        } catch (Throwable) {
                            // Ignore forced decoding errors
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Rapid masking for simple text messages.
     */
    private static function sanitizeMessage(string $message): string
    {
        return (string) preg_replace('/(password|pass|pwd|token)\s*=\s*[^\s&]+/ims', '$1=******', $message);
    }

    /**
     * Formats the exception stack trace in a readable format.
     *
     * @return array<int, string> Formatted trace.
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
                '#%d %s(%d): %s%s%s()',
                $counter,
                $step['file'] ?? 'unknown_file',
                $step['line'] ?? 0,
                $step['class'] ?? '',
                $step['type'] ?? '',
                $step['function']
            );
        }

        return $trace;
    }

    /**
     * Formats bytes into a human-readable format.
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);

        return round($bytes, 2).' '.$units[$pow];
    }
}
