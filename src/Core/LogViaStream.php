<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * LogViaStream Class
 *
 * Centralizes and secures logs via native PHP streams.
 * Includes protection against internal DDoS and infinite loops (Local and Global Rate Limiting).
 */
final class LogViaStream
{
    private static bool $isLogging = false;

    private static ?string $requestId = null;

    private static string $apiKey = '';

    private static string $url = '';

    private static int $logCountInRequest = 0;

    public static bool $allowTestLogging = false;

    private const int MAX_LOGS_PER_REQUEST = 30;

    private const int MAX_LOGS_PER_MINUTE = 300;

    private static function initialize(): void
    {
        if (self::$apiKey === '' || self::$url === '') {
            $apiKey = $_ENV['LOG_API_KEY'] ?? '';
            self::$apiKey = is_string($apiKey) ? $apiKey : '';
            $url = $_ENV['LOG_SERVER_URL'] ?? '';
            self::$url = is_string($url) ? $url : '';

            $inContainer = isset($_ENV['LOG_MONITOR_CONTAINER']) || isset($_ENV['AEGIS_CONTAINER']);
            if (str_contains(self::$url, 'log-monitor-app') && ! $inContainer) {
                self::$url = str_replace(
                    ['log-monitor-app:8080', 'log-monitor-app:80', 'log-monitor-app'],
                    ['localhost:8082', 'localhost:8082', 'localhost:8082'],
                    self::$url
                );
            }
        }
    }

    /**
     * Dispatches the log to the centralized server in a controlled and secure manner.
     *
     * @param  array<string, mixed>  $context
     */
    public static function send(string $level, string $message, array $context = []): bool
    {
        self::initialize();

        $rawScriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $currentScript = basename(is_string($rawScriptName) ? $rawScriptName : '');
        if ($currentScript === 'log.php') {
            error_log(sprintf('[%s] Internal Log: %s | Context: %s', strtoupper($level), $message, json_encode($context)));

            return true;
        }

        if (self::$isLogging) {
            return false;
        }

        if (self::$logCountInRequest >= self::MAX_LOGS_PER_REQUEST) {
            error_log('LogViaStream Alert: Maximum logs per request limit reached ('.self::MAX_LOGS_PER_REQUEST.').');

            return false;
        }

        if (! self::checkGlobalRateLimit()) {
            error_log('LogViaStream Alert: Global rate limit exceeded ('.self::MAX_LOGS_PER_MINUTE.'/min). Log preventively blocked.');

            return false;
        }

        if (self::$url === '') {
            if (! defined('PHPUNIT_RUNNING') || self::$allowTestLogging) {
                error_log(sprintf('[%s] LogViaStream Fallback (Not Initialized): %s', strtoupper($level), $message));
            }

            return false;
        }

        self::$isLogging = true;
        // @codeCoverageIgnoreStart
        if (! defined('PHPUNIT_RUNNING')) {
            self::$logCountInRequest++;
        }
        // @codeCoverageIgnoreEnd

        try {
            if (self::$requestId === null) {
                self::$requestId = bin2hex(random_bytes(6));
            }

            $networkTimeout = self::calculateDynamicTimeout();

            $rawIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
            $maskedIp = str_contains($rawIp, ':')
                ? (preg_replace('/^([0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}):.*$/', '$1::', $rawIp) ?? $rawIp)
                : (preg_replace('/(\d+)\.(\d+)\.(\d+)\.\d+/', '$1.$2.$3.0', $rawIp) ?? '0.0.0.0');

            $extendedContext = array_merge([
                'request_id' => self::$requestId,
                'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'uri' => $_SERVER['REQUEST_URI'] ?? 'N/A',
                'ip' => $maskedIp,
                'referrer' => $_SERVER['HTTP_REFERER'] ?? 'DIRECT',
                'query_params' => count($_GET) > 0 ? self::sanitizeData($_GET) : null,
                'post_data' => count($_POST) > 0 ? self::sanitizeData($_POST) : null,
                'memory_usage' => self::formatBytes(memory_get_usage(true)),
                'peak_memory' => self::formatBytes(memory_get_peak_usage(true)),
            ], self::sanitizeData($context));

            $payload = json_encode([
                'level' => strtoupper($level),
                'message' => self::sanitizeMessage($message),
                'context' => $extendedContext,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $headers = [
                'Content-Type: application/json',
                'X-API-KEY: '.self::$apiKey,
                'User-Agent: LogMonitor-Internal/1.0',
            ];

            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => $headers,
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

            try {
                set_error_handler(static function () {
                    return true;
                });
                try {
                    $fp = fopen(self::$url, 'r', false, $streamContext);
                } finally {
                    restore_error_handler();
                }

                if ($fp === false) {
                    if (! defined('PHPUNIT_RUNNING') || self::$allowTestLogging) {
                        error_log(sprintf('[%s] LogMonitor Alert: Log server at %s is unavailable.', strtoupper($level), self::$url));
                    }

                    return false;
                }

                $meta = stream_get_meta_data($fp);
                $respHeaders = $meta['wrapper_data'] ?? [];

                if (defined('PHPUNIT_RUNNING') && isset($GLOBALS['mock_http_response_header'])) {
                    $respHeaders = $GLOBALS['mock_http_response_header'];
                }

                if (! is_array($respHeaders)) {
                    $respHeaders = [];
                }

                stream_get_contents($fp);
                fclose($fp);
            } catch (Throwable $e) {
                error_log('LogMonitor Alert: Log server at '.self::$url.' is unavailable. Error: '.$e->getMessage());

                return false;
            }

            if (count($respHeaders) === 0) {
                return false;
            }

            $headerZero = isset($respHeaders[0]) && is_string($respHeaders[0]) ? $respHeaders[0] : '';

            return preg_match('#^HTTP/\S+\s+2\d\d\b#', $headerZero) === 1;
        } catch (Throwable $e) {
            $logMsg = json_encode([
                'error' => 'Critical error in log transmission via Stream',
                'exception_message' => $e->getMessage(),
                'identifier' => 'LogViaStream_Transmission_Failure',
            ], JSON_UNESCAPED_SLASHES);
            $logMsgStr = is_string($logMsg) ? $logMsg : 'Critical error in log transmission via Stream';
            error_log($logMsgStr);

            return false;
        } finally {
            self::$isLogging = false;
        }
    }

    private static function checkGlobalRateLimit(): bool
    {
        $limitFile = sys_get_temp_dir().'/log_rate_limit.json';
        $now = time();
        $minuteWindow = $now - ($now % 60);

        try {
            if (! file_exists($limitFile)) {
                @file_put_contents($limitFile, json_encode(['window' => $minuteWindow, 'count' => 0]));
            }

            $fp = fopen($limitFile, 'c+');
            // @codeCoverageIgnoreStart
            if ($fp === false) {
                return true;
            }
            // @codeCoverageIgnoreEnd
            flock($fp, LOCK_EX);

            try {
                $content = stream_get_contents($fp);
                $contentStr = is_string($content) ? $content : '{}';
                $data = json_decode($contentStr, true);

                if (! is_array($data) || ($data['window'] ?? 0) !== $minuteWindow) {
                    $data = ['window' => $minuteWindow, 'count' => 1];
                } else {
                    $count = $data['count'] ?? 0;
                    $data['count'] = (is_int($count) ? $count : 0) + 1;
                }

                if ($data['count'] > self::MAX_LOGS_PER_MINUTE) {
                    return false;
                }

                ftruncate($fp, 0);
                rewind($fp);
                $encodedData = json_encode($data);
                fwrite($fp, is_string($encodedData) ? $encodedData : '{}');
                fflush($fp);
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            error_log('LogViaStream Alert: Error processing global rate limiter ('.$e->getMessage().'). Falling back to fail-open.');

            return true;
        }
        // @codeCoverageIgnoreEnd

        return true;
    }

    private static function calculateDynamicTimeout(): float
    {
        $maxPhpTime = (int) ini_get('max_execution_time');
        $networkTimeout = 2.5;

        $rawRequestTime = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $startTime = (is_float($rawRequestTime) || is_numeric($rawRequestTime)) ? (float) $rawRequestTime : (defined('APP_START_TIME') && is_numeric(APP_START_TIME) ? (float) APP_START_TIME : null);

        if ($maxPhpTime > 0 && $startTime !== null) {
            $elapsedTime = microtime(true) - $startTime;
            $timeLeft = $maxPhpTime - $elapsedTime;

            if ($timeLeft < 2.0) {
                $networkTimeout = max(0.5, $timeLeft - 0.2);
            }
        }

        return $networkTimeout;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sanitizeData(array $data): array
    {
        $sensitiveFragments = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'private_key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeData($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string) $key);

                $isSensitiveKey = false;
                foreach ($sensitiveFragments as $fragment) {
                    if (str_contains($lowerKey, $fragment)) {
                        $isSensitiveKey = true;
                        break;
                    }
                }

                if ($isSensitiveKey) {
                    $data[$key] = '******';
                } elseif (json_validate($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $data[$key] = json_encode(self::sanitizeData($decoded), JSON_UNESCAPED_SLASHES);
                        }
                        // @codeCoverageIgnoreStart
                    } catch (Throwable $e) {
                        error_log('LogViaStream Alert: Data sanitization JSON decode failed: '.$e->getMessage());
                    }
                    // @codeCoverageIgnoreEnd
                } else {
                    $data[$key] = self::sanitizeContent($value);
                }
            }
        }

        return $data;
    }

    private static function sanitizeContent(string $value): string
    {
        $emailRegex = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        $bearerRegex = '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i';

        $cleaned = preg_replace($emailRegex, '[REDACTED_EMAIL]', $value);
        $cleanedStr = is_string($cleaned) ? $cleaned : $value;
        $cleanedToken = preg_replace($bearerRegex, 'Bearer [REDACTED_TOKEN]', $cleanedStr);

        return is_string($cleanedToken) ? $cleanedToken : $cleanedStr;
    }

    private static function sanitizeMessage(string $message): string
    {
        $result = preg_replace('/(password|pass|pwd|token)\s*=\s*[^\s&]+/ims', '$1=******', $message);
        $resultStr = is_string($result) ? $result : $message;

        return self::sanitizeContent($resultStr);
    }

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
