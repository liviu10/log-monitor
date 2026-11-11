<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Retry Class
 *
 * Retries an operation that may fail transiently (network, external API, database deadlock),
 * with increasing delay between attempts (exponential backoff) and a random component (jitter),
 * so multiple concurrent processes do not all retry at the exact same moment.
 *
 * Only exceptions in $retryOn are retried (defaults to all). A programming error
 * (e.g. TypeError) should not be retried: restrict $retryOn to transient exceptions.
 * After the final attempt, the original exception is thrown (same type, same message).
 *
 * Usage:
 *   require_once 'Retry.php';
 *   use App\Utilities\Retry;
 *
 *   $curs = Retry::run(
 *       fn () => HttpClient::json('GET', $url),
 *       times: 3,
 *       delayMs: 200,                              // 200ms, then ~400ms
 *       retryOn: [RuntimeException::class],
 *   );
 *
 *   Retry::run(fn (int $attempt) => $db->transaction(...), times: 5, when: fn (Throwable $e) => str_contains($e->getMessage(), 'Deadlock'));
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
final class Retry
{
    private function __construct() {}

    /**
     * Executes $operation up to $times times until it succeeds.
     *
     * @template T
     *
     * @param  Closure(int): T  $operation  Receives the current attempt number (starting from 1).
     * @param  int  $times  Total number of attempts (minimum 1).
     * @param  int  $delayMs  Delay after the first failed attempt, in milliseconds.
     * @param  float  $multiplier  Factor by which delay increases after each failure (1 = constant).
     * @param  int  $maxDelayMs  Maximum delay cap, in milliseconds.
     * @param  bool  $jitter  True: actual sleep is randomized between 50% and 100% of calculated delay.
     * @param  list<class-string<Throwable>>  $retryOn  Exception classes to retry; all others are rethrown immediately.
     * @param  (Closure(Throwable, int): bool)|null  $when  Additional filter: returning false stops retrying.
     * @return T The return value of the first successful attempt.
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws Throwable The exception from the last attempt (or first non-retried exception).
     */
    public static function run(
        Closure $operation,
        int $times = 3,
        int $delayMs = 100,
        float $multiplier = 2.0,
        int $maxDelayMs = 10_000,
        bool $jitter = true,
        array $retryOn = [Throwable::class],
        ?Closure $when = null,
    ): mixed {
        self::assertParameters($times, $delayMs, $multiplier, $maxDelayMs, $retryOn);

        $delay = (float) $delayMs;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation($attempt);
            } catch (Throwable $e) {
                if ($attempt >= $times || ! self::shouldRetry($e, $attempt, $retryOn, $when)) {
                    throw $e;
                }
            }

            $sleep = (int) min($delay, $maxDelayMs);
            if ($jitter && $sleep > 0) {
                $sleep = random_int(intdiv($sleep, 2), $sleep);
            }
            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            $delay *= $multiplier;
        }
    }

    /**
     * The delays (in milliseconds, without jitter) that would be used between attempts; useful for inspecting
     * configuration: Retry::delays(4, 100) = [100, 200, 400].
     *
     * @return list<int>
     *
     * @throws InvalidArgumentException On invalid parameters.
     */
    public static function delays(int $times, int $delayMs = 100, float $multiplier = 2.0, int $maxDelayMs = 10_000): array
    {
        self::assertParameters($times, $delayMs, $multiplier, $maxDelayMs, [Throwable::class]);

        $delays = [];
        $delay = (float) $delayMs;
        for ($i = 1; $i < $times; $i++) {
            $delays[] = (int) min($delay, $maxDelayMs);
            $delay *= $multiplier;
        }

        return $delays;
    }

    /**
     * @param  list<class-string<Throwable>>  $retryOn
     * @param  (Closure(Throwable, int): bool)|null  $when
     */
    private static function shouldRetry(Throwable $e, int $attempt, array $retryOn, ?Closure $when): bool
    {
        foreach ($retryOn as $class) {
            if ($e instanceof $class) {
                return ! $when instanceof Closure || $when($e, $attempt);
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $retryOn
     *
     * @throws InvalidArgumentException On invalid parameters.
     */
    private static function assertParameters(int $times, int $delayMs, float $multiplier, int $maxDelayMs, array $retryOn): void
    {
        if ($times < 1) {
            throw new InvalidArgumentException('Retry: $times must be at least 1.');
        }

        if ($delayMs < 0 || $maxDelayMs < 0) {
            throw new InvalidArgumentException('Retry: delays cannot be negative.');
        }

        if ($multiplier < 1.0) {
            throw new InvalidArgumentException('Retry: $multiplier must be at least 1.');
        }

        if ($retryOn === []) {
            throw new InvalidArgumentException('Retry: $retryOn cannot be empty.');
        }

        foreach ($retryOn as $class) {
            if (! is_string($class) || ! is_a($class, Throwable::class, true)) {
                throw new InvalidArgumentException('Retry: $retryOn must contain only Throwable class names.');
            }
        }
    }
}
