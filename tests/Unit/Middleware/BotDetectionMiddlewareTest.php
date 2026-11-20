<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\BotDetectionMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Class BotDetectionMiddlewareTest
 *
 * Unit tests for BotDetectionMiddleware.
 *
 * @category Tests
 */
class BotDetectionMiddlewareTest extends TestCase
{
    private BotDetectionMiddleware $middleware;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new BotDetectionMiddleware;
        $this->cacheDir = dirname(__DIR__, 3).'/storage/framework/cache';
        $this->clearCache();

        $_ENV['APP_ENV'] = 'production';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = [];

        if (function_exists('config')) {
            config('::reset');
        }
    }

    protected function tearDown(): void
    {
        $this->clearCache();

        $_ENV['APP_ENV'] = 'development';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_POST = [];

        if (function_exists('config')) {
            config('::reset');
        }

        parent::tearDown();
    }

    private function clearCache(): void
    {
        if (is_dir($this->cacheDir)) {
            $items = scandir($this->cacheDir);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if ($item !== '.' && $item !== '..') {
                        $path = $this->cacheDir.'/'.$item;
                        if (is_file($path)) {
                            @unlink($path);
                        }
                    }
                }
            }
        }
    }

    public function test_handle_bypasses_in_development(): void
    {
        $_ENV['APP_ENV'] = 'development';
        if (function_exists('config')) {
            config('::reset');
        }

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle([], $next);
        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_honeypot_fails_if_field_is_filled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if (function_exists('config')) {
            config('::reset');
        }

        $_POST['bd'] = 'bot-filled-this';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forbidden');

        $this->middleware->handle([], function () {});
    }

    public function test_handle_fst_fails_if_submitted_too_fast(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if (function_exists('config')) {
            config('::reset');
        }

        $_POST['bd'] = ''; // Honeypot passed
        $_POST['fst'] = time(); // Submitted in 0 seconds

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Forbidden');

        $this->middleware->handle([], function () {});
    }

    public function test_handle_fst_passes_if_submitted_after_delay(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if (function_exists('config')) {
            config('::reset');
        }

        $_POST['bd'] = '';
        $_POST['fst'] = time() - 3; // Submitted 3 seconds ago

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle([], $next);
        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_rate_limit_login(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/login';
        if (function_exists('config')) {
            config('::reset');
        }

        // Max 5 attempts for login
        for ($i = 0; $i < 5; $i++) {
            $this->middleware->handle([], function () {
                return 'ok';
            });
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->middleware->handle([], function () {
            return 'ok';
        });
    }

    public function test_handle_rate_limit_get_per_second(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (function_exists('config')) {
            config('::reset');
        }

        // Max 30 attempts per second
        for ($i = 0; $i < 30; $i++) {
            $this->middleware->handle([], function () {
                return 'ok';
            });
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->middleware->handle([], function () {
            return 'ok';
        });
    }

    public function test_handle_rate_limit_post_put_delete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/contact';
        if (function_exists('config')) {
            config('::reset');
        }

        $_POST['bd'] = ''; // Pass honeypot

        // Max 30 attempts per minute for non-login state-changing requests
        for ($i = 0; $i < 30; $i++) {
            $_POST['fst'] = time() - 3; // Pass fst check
            $this->middleware->handle([], function () {
                return 'ok';
            });
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->middleware->handle([], function () {
            return 'ok';
        });
    }

    public function test_garbage_collect_returns_if_no_dir(): void
    {
        $this->clearCache();
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }

        $this->middleware->garbageCollect();
        self::assertFalse(is_dir($this->cacheDir));
    }

    public function test_garbage_collect_unlinks_expired_files(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (function_exists('config')) {
            config('::reset');
        }

        // 1. Creeaza cache-uri (un request GET creeaza 3 fisiere: pe secunda, pe ora, pe zi)
        $this->middleware->handle([], function () {
            return 'ok';
        });

        $filesBefore = glob($this->cacheDir.'/*.cache');
        $filesBefore = is_array($filesBefore) ? $filesBefore : [];
        $countBefore = count($filesBefore);
        self::assertGreaterThanOrEqual(3, $countBefore);

        // 2. Asteptam 2 secunde natural pentru a expira DOAR cache-ul de pe secunda (TTL 1s)
        sleep(2);

        // 3. Executam garbage collect care va sterge DOAR fisierul expirat
        $this->middleware->garbageCollect();

        $filesAfter = glob($this->cacheDir.'/*.cache');
        $filesAfter = is_array($filesAfter) ? $filesAfter : [];
        $countAfter = count($filesAfter);

        // Ne asiguram ca s-a sters exact 1 fisier (cel expirat)
        self::assertEquals($countBefore - 1, $countAfter);
    }

    public function test_handle_rate_limit_get_per_hour(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (function_exists('config')) {
            config('::reset');
        }

        $ip = '127.0.0.1';
        $ipHashShort = hash('sha256', $ip.date('Y-m-d'));
        $hourKey = 'rate_hour_'.$ipHashShort.'_'.date('H');

        $dir = $this->cacheDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.'/'.hash('sha256', $hourKey).'.cache';
        file_put_contents($file, json_encode([
            'value' => 300,
            'expires_at' => time() + 3600,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->middleware->handle([], function () {
            return 'ok';
        });
    }

    public function test_handle_rate_limit_get_per_day(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (function_exists('config')) {
            config('::reset');
        }

        $ip = '127.0.0.1';
        $ipHashShort = hash('sha256', $ip.date('Y-m-d'));
        $dayKey = 'rate_day_'.$ipHashShort;

        $dir = $this->cacheDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.'/'.hash('sha256', $dayKey).'.cache';
        file_put_contents($file, json_encode([
            'value' => 1000,
            'expires_at' => time() + 86400,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many requests');

        $this->middleware->handle([], function () {
            return 'ok';
        });
    }

    public function test_garbage_collect_unlinks_invalid_json(): void
    {
        $dir = $this->cacheDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.'/invalid.cache';
        file_put_contents($file, '{invalid json');

        $this->middleware->garbageCollect();

        self::assertFalse(file_exists($file));
    }

    public function test_cache_get_unlinks_invalid_json(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (function_exists('config')) {
            config('::reset');
        }

        $ip = '127.0.0.1';
        $ipHashShort = hash('sha256', $ip.date('Y-m-d'));
        $secKey = 'rate_sec_'.$ipHashShort;

        $dir = $this->cacheDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $file = $dir.'/'.hash('sha256', $secKey).'.cache';
        file_put_contents($file, '{invalid json');

        $this->middleware->handle([], function () {
            return 'ok';
        });

        // In timpul cererii GET, cacheGet gaseste JSON invalid si ii da unlink().
        // Apoi, cachePut recreeaza fisierul cu un JSON valid continand count-ul actualizat.
        self::assertTrue(file_exists($file));
        $newContent = file_get_contents($file);
        $newContent = is_string($newContent) ? $newContent : '';
        self::assertNotEquals('{invalid json', $newContent);
        self::assertTrue(json_validate($newContent));
    }
}
