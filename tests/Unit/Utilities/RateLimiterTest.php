<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\RateLimiter;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimiterTest extends TestCase
{
    private string $dir;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-limit-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->limiter = new RateLimiter($this->dir);
    }

    protected function tearDown(): void
    {
        self::deleteDir($this->dir);
    }

    /**
     * Muta inceputul ferestrei in trecut, ca si cum ar fi trecut timpul.
     */
    private function expire(string $key): void
    {
        $path = $this->dir.'/'.hash('sha256', $key).'.limit.json';
        $state = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($state);
        $state['resetAt'] = time() - 1;
        file_put_contents($path, json_encode($state));
    }

    public function test_attempt_until_limit(): void
    {
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->limiter->attempt('login:1.2.3.4', 3, 60);
        }

        $this->assertSame([true, true, true, false, false], $results);
        $this->assertSame(3, $this->limiter->attempts('login:1.2.3.4'), 'incercarile respinse nu sunt numarate');
    }

    public function test_keys_are_independent(): void
    {
        $this->limiter->attempt('a', 1, 60);

        $this->assertFalse($this->limiter->attempt('a', 1, 60));
        $this->assertTrue($this->limiter->attempt('b', 1, 60));
    }

    public function test_remaining_too_many_and_available_in(): void
    {
        $this->assertSame(0, $this->limiter->availableIn('x'));
        $this->assertSame(3, $this->limiter->remaining('x', 3));

        $this->limiter->attempt('x', 3, 60);
        $this->limiter->attempt('x', 3, 60);

        $this->assertSame(1, $this->limiter->remaining('x', 3));
        $this->assertFalse($this->limiter->tooManyAttempts('x', 3));
        $this->assertGreaterThanOrEqual(59, $this->limiter->availableIn('x'));
        $this->assertLessThanOrEqual(60, $this->limiter->availableIn('x'));

        $this->limiter->attempt('x', 3, 60);
        $this->assertTrue($this->limiter->tooManyAttempts('x', 3));
    }

    public function test_window_resets_after_decay(): void
    {
        $this->limiter->attempt('x', 1, 60);
        $this->assertFalse($this->limiter->attempt('x', 1, 60));

        $this->expire('x');

        $this->assertTrue($this->limiter->attempt('x', 1, 60));
        $this->assertSame(1, $this->limiter->attempts('x'));
    }

    public function test_window_is_fixed_from_first_attempt(): void
    {
        $this->limiter->attempt('x', 5, 60);
        $first = $this->limiter->availableIn('x');
        $this->limiter->attempt('x', 5, 3600);

        $this->assertLessThanOrEqual($first, $this->limiter->availableIn('x'), 'incercarile urmatoare nu prelungesc fereastra');
    }

    public function test_enforce(): void
    {
        $this->limiter->enforce('form', 2, 60);
        $this->limiter->enforce('form', 2, 60);

        $this->expectException(OverflowException::class);
        $this->expectExceptionMessageMatches('/too many attempts; retry in (59|60) seconds/');

        $this->limiter->enforce('form', 2, 60);
    }

    public function test_hit_counts_without_limit(): void
    {
        $this->assertSame(1, $this->limiter->hit('parola-gresita', 300));
        $this->assertSame(2, $this->limiter->hit('parola-gresita', 300));
        $this->assertTrue($this->limiter->tooManyAttempts('parola-gresita', 2));
    }

    public function test_clear(): void
    {
        $this->limiter->attempt('x', 1, 60);

        $this->limiter->clear('x');
        $this->limiter->clear('x'); // fara eroare daca nu exista

        $this->assertTrue($this->limiter->attempt('x', 1, 60));
    }

    public function test_corrupted_state_counts_as_empty(): void
    {
        $this->limiter->attempt('x', 5, 60);
        file_put_contents($this->dir.'/'.hash('sha256', 'x').'.limit.json', 'corupt');

        $this->assertSame(0, $this->limiter->attempts('x'));
    }

    public function test_concurrent_processes_do_not_lose_counts(): void
    {
        $script = sprintf(
            'require %s; $l = new App\Utilities\RateLimiter(%s); for ($i = 0; $i < 25; $i++) { $l->hit("paralel", 60); }',
            var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true),
            var_export($this->dir, true),
        );

        $processes = [];
        for ($p = 0; $p < 4; $p++) {
            $process = proc_open([PHP_BINARY, '-n', '-r', $script], [], $pipes);
            $this->assertIsResource($process);
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            proc_close($process);
        }

        $this->assertSame(100, $this->limiter->attempts('paralel'));
    }

    public function test_invalid_parameters(): void
    {
        foreach ([[0, 60], [5, 0]] as [$max, $decay]) {
            try {
                $this->limiter->attempt('x', $max, $decay);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);

        $this->limiter->attempt('', 5, 60);
    }

    public function test_directory_must_exist(): void
    {
        $this->expectException(RuntimeException::class);

        new RateLimiter($this->dir.'/lipsa');
    }

    private static function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? self::deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
