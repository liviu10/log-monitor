<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;

/**
 * RateLimiter Class
 *
 * Limits the number of attempts on a key within a given time window (e.g., 5 login attempts
 * per minute per IP). The counter is persisted in files with an exclusive lock (flock), ensuring
 * correctness even when multiple concurrent PHP requests execute simultaneously.
 *
 * The window is fixed: the first attempt initiates the $decaySeconds window; upon expiration,
 * the counter resets.
 *
 * Storage errors (missing directory, locked file) throw an exception: a limiter that permits
 * everything to pass through when unable to write provides no protection.
 *
 * Usage:
 *   use App\Utilities\RateLimiter;
 *
 *   $limiter = new RateLimiter(__DIR__.'/storage/rate-limits');
 *
 *   // Approach 1: Exception (caught globally and converted to HTTP 429)
 *   $limiter->enforce('login:'.$ip, maxAttempts: 5, decaySeconds: 60);
 *
 *   // Approach 2: Explicit check
 *   if (! $limiter->attempt('contact:'.$ip, 3, 3600)) {
 *       $wait = $limiter->availableIn('contact:'.$ip);   // seconds until reset
 *   }
 *
 *   $limiter->clear('login:'.$ip);                        // after successful authentication
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final readonly class RateLimiter
{
    /** @var string File extension for state files. */
    private const string EXTENSION = '.limit.json';

    private string $directory;

    /**
     * @param  string  $directory  Directory for state files; must exist and be writable.
     *
     * @throws RuntimeException If the directory does not exist or is not writable.
     */
    public function __construct(string $directory)
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("RateLimiter: directory '{$directory}' does not exist or is not writable.");
        }

        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * Records an attempt if the rate limit has not been reached.
     *
     * @return bool true if the attempt is allowed (and counted), false if the limit was reached.
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException On storage error.
     */
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $this->assertLimits($maxAttempts, $decaySeconds);

        return $this->update($key, static function (array $state) use ($maxAttempts, $decaySeconds): array {
            if ($state['count'] >= $maxAttempts) {
                return [$state, false];
            }

            return [self::increment($state, $decaySeconds), true];
        });
    }

    /**
     * Similar to attempt(), but throws an exception when the rate limit is reached.
     *
     * @throws OverflowException If the limit has been reached (message specifies seconds to wait).
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException On storage error.
     */
    public function enforce(string $key, int $maxAttempts, int $decaySeconds): void
    {
        if (! $this->attempt($key, $maxAttempts, $decaySeconds)) {
            throw new OverflowException(sprintf('RateLimiter: too many attempts; retry in %d seconds.', $this->availableIn($key)));
        }
    }

    /**
     * Increments the attempt counter without verifying limits (e.g., following an invalid password).
     *
     * @return int Number of attempts recorded in the current window.
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException On storage error.
     */
    public function hit(string $key, int $decaySeconds): int
    {
        $this->assertLimits(1, $decaySeconds);

        return $this->update($key, static function (array $state) use ($decaySeconds): array {
            $state = self::increment($state, $decaySeconds);

            return [$state, $state['count']];
        });
    }

    /**
     * Number of attempts recorded in the current window.
     */
    public function attempts(string $key): int
    {
        return $this->update($key, static fn (array $state): array => [$state, $state['count']]);
    }

    /**
     * Remaining attempts permitted within the current window.
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        $this->assertLimits($maxAttempts, 1);

        return max(0, $maxAttempts - $this->attempts($key));
    }

    /**
     * Checks whether the limit has been reached without incrementing the counter.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->remaining($key, $maxAttempts) === 0;
    }

    /**
     * Seconds remaining until the counter resets (0 if no attempts are active).
     */
    public function availableIn(string $key): int
    {
        return $this->update($key, static fn (array $state): array => [$state, $state['count'] === 0 ? 0 : max(0, $state['resetAt'] - time())]);
    }

    /**
     * Resets the counter (e.g., after successful authentication).
     *
     * @throws RuntimeException If the state file cannot be deleted.
     */
    public function clear(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            [$deleted, $warning] = $this->capture(static fn (): bool => unlink($path));
            // If unlink() fails because another process already deleted the file, this is not an error.
            if (! $deleted && file_exists($path)) {
                throw new RuntimeException("RateLimiter: cannot delete '{$path}'".($warning === null ? '.' : ": {$warning}."));
            }
        }
    }

    /**
     * Reads current state, applies $change, and writes it back, all under an exclusive lock.
     *
     * @template T
     *
     * @param  Closure(array{count: int, resetAt: int}): array{0: array{count: int, resetAt: int}, 1: T}  $change
     * @return T
     *
     * @throws RuntimeException On storage error.
     */
    private function update(string $key, Closure $change): mixed
    {
        $path = $this->path($key);

        [$handle, $warning] = $this->capture(static fn () => fopen($path, 'c+'));
        if ($handle === false) {
            throw new RuntimeException("RateLimiter: cannot open '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("RateLimiter: cannot lock '{$path}'.");
            }

            $content = (string) stream_get_contents($handle);
            $state = $this->decode($content);

            [$newState, $result] = $change($state);

            if ($newState !== $state || $content === '') {
                $json = json_encode($newState, JSON_THROW_ON_ERROR);
                if (! ftruncate($handle, 0) || ! rewind($handle) || fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new RuntimeException("RateLimiter: cannot write '{$path}'.");
                }
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Decodes state from file; an empty, invalid, or expired window returns count 0.
     *
     * @return array{count: int, resetAt: int}
     */
    private function decode(string $content): array
    {
        $data = json_decode($content, true);
        $count = is_array($data) ? ($data['count'] ?? null) : null;
        $resetAt = is_array($data) ? ($data['resetAt'] ?? null) : null;

        if (! is_int($count) || ! is_int($resetAt) || $resetAt <= time()) {
            return ['count' => 0, 'resetAt' => 0];
        }

        return ['count' => $count, 'resetAt' => $resetAt];
    }

    /**
     * @param  array{count: int, resetAt: int}  $state
     * @return array{count: int, resetAt: int}
     */
    private static function increment(array $state, int $decaySeconds): array
    {
        return [
            'count' => $state['count'] + 1,
            'resetAt' => $state['count'] === 0 ? time() + $decaySeconds : $state['resetAt'],
        ];
    }

    /**
     * @throws InvalidArgumentException If limits are not positive integers.
     */
    private function assertLimits(int $maxAttempts, int $decaySeconds): void
    {
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new InvalidArgumentException('RateLimiter: $maxAttempts and $decaySeconds must be at least 1.');
        }
    }

    /**
     * @throws InvalidArgumentException If the key is empty.
     */
    private function path(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('RateLimiter: the key cannot be empty.');
        }

        return $this->directory.'/'.hash('sha256', $key).self::EXTENSION;
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
