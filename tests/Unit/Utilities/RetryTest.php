<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Retry;
use DomainException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

final class RetryTest extends TestCase
{
    public function test_returns_first_successful_result(): void
    {
        $attempts = [];

        $result = Retry::run(static function (int $attempt) use (&$attempts): string {
            $attempts[] = $attempt;
            if ($attempt < 3) {
                throw new RuntimeException("esec {$attempt}");
            }

            return 'ok';
        }, times: 5, delayMs: 1);

        $this->assertSame('ok', $result);
        $this->assertSame([1, 2, 3], $attempts);
    }

    public function test_success_on_first_attempt_does_not_wait(): void
    {
        $start = microtime(true);

        $this->assertSame(42, Retry::run(static fn (): int => 42, delayMs: 5000));
        $this->assertLessThan(1, microtime(true) - $start);
    }

    public function test_rethrows_original_exception_after_last_attempt(): void
    {
        $calls = 0;

        try {
            Retry::run(static function (int $attempt) use (&$calls): never {
                $calls++;
                throw new RuntimeException("esec {$attempt}", 7);
            }, times: 3, delayMs: 1);
        } catch (RuntimeException $e) {
            $this->assertSame('esec 3', $e->getMessage());
            $this->assertSame(7, $e->getCode());
        }

        $this->assertSame(3, $calls);
    }

    public function test_only_listed_exceptions_are_retried(): void
    {
        $calls = 0;

        try {
            Retry::run(static function () use (&$calls): never {
                $calls++;
                throw new LogicException('greseala de programare');
            }, times: 5, delayMs: 1, retryOn: [RuntimeException::class]);
        } catch (LogicException) {
            // asteptat
        }

        $this->assertSame(1, $calls);
    }

    public function test_subclasses_of_listed_exceptions_are_retried(): void
    {
        $calls = 0;

        $result = Retry::run(static function () use (&$calls): string {
            if (++$calls === 1) {
                throw new \UnexpectedValueException('subclasa de RuntimeException');
            }

            return 'ok';
        }, delayMs: 1, retryOn: [RuntimeException::class]);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function test_errors_are_retried_only_if_listed(): void
    {
        $calls = 0;

        try {
            Retry::run(static function () use (&$calls): never {
                $calls++;
                throw new TypeError('tip gresit');
            }, delayMs: 1, retryOn: [RuntimeException::class]);
        } catch (TypeError) {
            // asteptat
        }

        $this->assertSame(1, $calls);
    }

    public function test_when_filter(): void
    {
        $calls = 0;
        $seen = [];

        try {
            Retry::run(
                static function () use (&$calls): never {
                    $calls++;
                    throw new RuntimeException($calls === 1 ? 'Deadlock found' : 'Duplicate entry');
                },
                times: 5,
                delayMs: 1,
                when: static function (Throwable $e, int $attempt) use (&$seen): bool {
                    $seen[] = $attempt;

                    return str_contains($e->getMessage(), 'Deadlock');
                },
            );
        } catch (RuntimeException $e) {
            $this->assertSame('Duplicate entry', $e->getMessage());
        }

        $this->assertSame(2, $calls);
        $this->assertSame([1, 2], $seen);
    }

    public function test_waits_between_attempts(): void
    {
        $start = microtime(true);

        try {
            Retry::run(static fn (): never => throw new DomainException('x'), times: 3, delayMs: 50, jitter: false);
        } catch (DomainException) {
            // asteptat
        }

        // 50ms + 100ms
        $this->assertGreaterThanOrEqual(0.14, microtime(true) - $start);
    }

    public function test_delays(): void
    {
        $this->assertSame([100, 200, 400], Retry::delays(4, 100));
        $this->assertSame([100, 100, 100], Retry::delays(4, 100, multiplier: 1));
        $this->assertSame([500, 1000, 1000], Retry::delays(4, 500, maxDelayMs: 1000));
        $this->assertSame([], Retry::delays(1));
    }

    public function test_invalid_parameters(): void
    {
        $cases = [
            static fn (): mixed => Retry::run(static fn (): int => 1, times: 0),
            static fn (): mixed => Retry::run(static fn (): int => 1, delayMs: -1),
            static fn (): mixed => Retry::run(static fn (): int => 1, multiplier: 0.5),
            static fn (): mixed => Retry::run(static fn (): int => 1, retryOn: []),
            // Clasele care nu sunt Throwable sunt prinse de PHPStan la apelant.
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
