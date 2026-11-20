<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\LogViaStream;
use App\Enums\LogLevel;
use Closure;
use Throwable;

/**
 * Bot Detection Middleware (BotDetectionMiddleware)
 *
 * Protects forms against bots using honeypot, form submission time limits, and IP rate limiting.
 *
 * @category Middleware
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class BotDetectionMiddleware
{
    /**
     * Intercepts requests to detect bots via honeypot, form submission time limits, and rate limiting.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The next middleware closure.
     */
    public function handle(array $route, Closure $next): mixed
    {
        // The counters are read-modify-write files: serialise that section so
        // parallel requests cannot all read the same count and slip past the limit.
        $lock = $this->acquireRateLock();
        try {
            $this->enforceLimits();
        } finally {
            $this->releaseRateLock($lock);
        }

        return $next($route);
    }

    /**
     * Applies rate limits and bot checks (throws on violation).
     */
    private function enforceLimits(): void
    {
        $appEnv = config('app.env') ?? 'development';

        if ($appEnv === 'production') {
            $rawIp = config('server.remote_addr') ?? '127.0.0.1';
            $ip = is_string($rawIp) ? $rawIp : '127.0.0.1';
            $ipHashShort = hash('sha256', $ip.date('Y-m-d'));
            $rawUserAgent = config('server.http_user_agent') ?? '';
            $userAgent = is_string($rawUserAgent) ? $rawUserAgent : '';
            $rawUri = config('server.request_uri') ?? '';
            $requestUri = is_string($rawUri) ? $rawUri : '';

            $rawMethod = config('server.request_method');
            $method = is_string($rawMethod) ? strtoupper($rawMethod) : 'GET';

            if ($method === 'GET') {
                $secondKey = 'rate_sec_'.$ipHashShort;
                $hourKey = 'rate_hour_'.$ipHashShort.'_'.date('H');
                $dailyKey = 'rate_day_'.$ipHashShort;

                // 1. Limit per second (max 30)
                $secCount = $this->cacheGet($secondKey, 0) + 1;
                $this->cachePut($secondKey, $secCount, 1);
                if ($secCount > 30) {
                    $this->log('Rate limit per second exceeded', [
                        'ip_hash' => $ipHashShort,
                        'count' => $secCount,
                        'uri' => $requestUri,
                        'user_agent' => $userAgent,
                    ]);
                    throw new \RuntimeException(__('Too many requests'), 429);
                }

                // 2. Limit per hour (max 300)
                $hourCount = $this->cacheGet($hourKey, 0) + 1;
                $this->cachePut($hourKey, $hourCount, 3600);
                if ($hourCount > 300) {
                    $this->log('Rate limit per hour exceeded', [
                        'ip_hash' => $ipHashShort,
                        'count' => $hourCount,
                        'uri' => $requestUri,
                        'user_agent' => $userAgent,
                    ]);
                    throw new \RuntimeException(__('Too many requests'), 429);
                }

                // 3. Limit per day (max 1000)
                $dailyCount = $this->cacheGet($dailyKey, 0) + 1;
                $this->cachePut($dailyKey, $dailyCount, 86400);
                if ($dailyCount > 1000) {
                    $this->log('Rate limit per day exceeded', [
                        'ip_hash' => $ipHashShort,
                        'count' => $dailyCount,
                        'uri' => $requestUri,
                        'user_agent' => $userAgent,
                    ]);
                    throw new \RuntimeException(__('Too many requests'), 429);
                }
            } elseif (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                // Rate Limiting on State-Changing requests using Sliding Window
                $isLogin = str_contains(strtolower($requestUri), '/login');

                $maxRequests = $isLogin ? 5 : 30;
                $windowKey = 'rate_window_'.$ipHashShort.($isLogin ? '_login' : '_write');

                // Read existing timestamps from cache
                $timestamps = [];
                $cacheData = $this->cacheGetRaw($windowKey);
                if (is_array($cacheData)) {
                    $timestamps = $cacheData;
                }

                $currentTime = time();
                // Filter out timestamps older than 60 seconds
                $validTimestamps = array_filter($timestamps, fn ($ts) => is_scalar($ts) && (int) $ts > $currentTime - 60);
                $validTimestamps = array_values($validTimestamps); // Re-index array so json_encode creates a list, not an object
                $validTimestamps[] = $currentTime; // Add current request

                $requestCount = count($validTimestamps);

                if ($requestCount > $maxRequests) {
                    // Do not save the new timestamp if we are blocking, or we could keep them blocked forever?
                    // Actually, rate limiters usually count the blocked requests too.
                    $this->cachePutRaw($windowKey, $validTimestamps, 60);
                    $this->log('Rate limit exceeded (Sliding Window)', [
                        'ip_hash' => $ipHashShort,
                        'count' => $requestCount,
                        'uri' => $requestUri,
                        'user_agent' => $userAgent,
                        'type' => $isLogin ? 'Login' : 'Write',
                    ]);

                    if (defined('PHPUNIT_RUNNING')) {
                        throw new \RuntimeException(__('Too many requests'), 429);
                    }

                    // @codeCoverageIgnoreStart
                    // Directly send 429 and exit to avoid 302 redirects by framework exception handler on POST
                    \header('HTTP/1.1 429 Too Many Requests', true, 429);
                    echo 'Too many requests';
                    exit;
                    // @codeCoverageIgnoreEnd
                }

                // Save back to cache
                $this->cachePutRaw($windowKey, $validTimestamps, 60);

                // Honeypot test: field 'bd' should be empty
                if (isset($_POST['bd']) && $_POST['bd'] !== '') {
                    $this->log('Honeypot test failed (Bot detected)', [
                        'ip_hash' => $ipHashShort,
                        'payload' => is_scalar($_POST['bd']) ? (string) $_POST['bd'] : '',
                        'uri' => $requestUri,
                        'user_agent' => $userAgent,
                    ]);
                    throw new \RuntimeException(__('Forbidden'), 403);
                }

                // Test First Submission Time: fst submitted in form
                if (isset($_POST['fst']) && $_POST['fst'] !== '') {
                    $rawFst = $_POST['fst'];
                    $submitDuration = time() - (is_scalar($rawFst) ? (int) $rawFst : 0);
                    if ($submitDuration < 1) {
                        $this->log('Test submission time failed (Bot detected)', [
                            'ip_hash' => $ipHashShort,
                            'duration' => $submitDuration,
                            'uri' => $requestUri,
                            'user_agent' => $userAgent,
                        ]);
                        throw new \RuntimeException(__('Forbidden'), 403);
                    }
                }
            }
        }
    }

    /**
     * Opens and exclusively locks the rate-limit lock file.
     *
     * @return resource|null
     */
    private function acquireRateLock()
    {
        $dir = dirname(__DIR__, 2).'/storage/framework/cache';
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $fp = @fopen($dir.'/.ratelimit.lock', 'c');
        if ($fp === false) {
            return null;
        }
        flock($fp, LOCK_EX);

        return $fp;
    }

    /**
     * Releases the rate-limit lock.
     *
     * @param  resource|null  $fp
     */
    private function releaseRateLock($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Reads a value from the file-based cache.
     */
    private function cacheGet(string $key, int $default = 0): int
    {
        $file = dirname(__DIR__, 2).'/storage/framework/cache/'.hash('sha256', $key).'.cache';
        if (file_exists($file)) {
            try {
                $content = file_get_contents($file);
                $contentStr = is_string($content) ? $content : '{}';
                $data = json_decode($contentStr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_array($data) && isset($data['expires_at']) && is_scalar($data['expires_at']) && (int) $data['expires_at'] >= time()) {
                        $rawValue = $data['value'] ?? 0;

                        return is_scalar($rawValue) ? (int) $rawValue : 0;
                    }
                }
                // @codeCoverageIgnoreStart
            } catch (Throwable) {
                // Ignore read errors
            }
            // @codeCoverageIgnoreEnd
            try {
                unlink($file);
                // @codeCoverageIgnoreStart
            } catch (Throwable) {
                // Ignore unlink errors
            }
            // @codeCoverageIgnoreEnd
        }

        return $default;
    }

    /**
     * Reads raw decoded value from the file-based cache.
     */
    private function cacheGetRaw(string $key): mixed
    {
        $file = dirname(__DIR__, 2).'/storage/framework/cache/'.hash('sha256', $key).'.cache';
        if (file_exists($file)) {
            try {
                $content = file_get_contents($file);
                $contentStr = is_string($content) ? $content : '{}';
                $data = json_decode($contentStr, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_array($data) && isset($data['expires_at']) && is_scalar($data['expires_at']) && (int) $data['expires_at'] >= time()) {
                        return $data['value'] ?? null;
                    }
                }
                // @codeCoverageIgnoreStart
            } catch (Throwable) {
                // Ignore read errors
            }
            try {
                unlink($file);
            } catch (Throwable) {
                // Ignore unlink errors
            }
            // @codeCoverageIgnoreEnd
        }

        return null;
    }

    /**
     * Saves a value to the file-based cache.
     */
    private function cachePut(string $key, int $value, int $ttlSeconds): void
    {
        $dir = dirname(__DIR__, 2).'/storage/framework/cache';
        // @codeCoverageIgnoreStart
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // @codeCoverageIgnoreEnd
        $file = $dir.'/'.hash('sha256', $key).'.cache';
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);

        // @codeCoverageIgnoreStart
        // Probabilistic inline garbage collection (1% chance)
        try {
            if (random_int(1, 100) === 1) {
                $this->garbageCollect();
            }
        } catch (Throwable) {
            // Fallback if random_int fails
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Saves raw value to the file-based cache.
     */
    private function cachePutRaw(string $key, mixed $value, int $ttlSeconds): void
    {
        $dir = dirname(__DIR__, 2).'/storage/framework/cache';
        // @codeCoverageIgnoreStart
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // @codeCoverageIgnoreEnd
        $file = $dir.'/'.hash('sha256', $key).'.cache';
        $data = [
            'value' => $value,
            'expires_at' => time() + $ttlSeconds,
        ];
        file_put_contents($file, json_encode($data), LOCK_EX);

        // @codeCoverageIgnoreStart
        // Probabilistic inline garbage collection (1% chance)
        try {
            if (random_int(1, 100) === 1) {
                $this->garbageCollect();
            }
        } catch (Throwable) {
            // Fallback if random_int fails
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Deletes all expired cache files.
     */
    public function garbageCollect(): void
    {
        $dir = dirname(__DIR__, 2).'/storage/framework/cache';
        if (! is_dir($dir)) {
            return;
        }

        try {
            $files = glob($dir.'/*.cache');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        try {
                            $content = file_get_contents($file);
                            if (is_string($content) && json_validate($content)) {
                                $data = json_decode($content, true);
                                if (is_array($data) && isset($data['expires_at']) && is_scalar($data['expires_at']) && (int) $data['expires_at'] <= time()) {
                                    unlink($file);
                                }
                            } else {
                                unlink($file);
                            }
                            // @codeCoverageIgnoreStart
                        } catch (Throwable) {
                            // If file reading or unlinking fails, try to delete and continue to next file
                            try {
                                unlink($file);
                            } catch (Throwable) {
                                // Ignore unlink failure
                            }
                        }
                        // @codeCoverageIgnoreEnd
                    }
                }
            }
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            // Ignore errors during GC
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Logs a warning to the error stream.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $message, array $context): void
    {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            $context['identifier'] = 'Bot_Detection_Warning';
            LogViaStream::send(LogLevel::WARNING->value, $message, $context);
        }
    }
}
