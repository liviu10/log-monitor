<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Exceptions\RedirectException;
use App\Middleware\GuestMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Class GuestMiddlewareTest
 *
 * Unit tests for GuestMiddleware.
 *
 * @category Tests
 */
class GuestMiddlewareTest extends TestCase
{
    private GuestMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new GuestMiddleware;

        $_SESSION = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_handle_redirects_authenticated_user(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 1,
            'email' => 'admin@aegis.local',
        ];

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('/admin');

        $next = function ($route) {
            self::fail('The next closure should not be called if authenticated.');
        };

        $this->middleware->handle(['path' => '/login'], $next);
    }

    public function test_handle_allows_unauthenticated_user(): void
    {
        unset($_SESSION['auth.user']);

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['path' => '/login'], $next);

        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_starts_session_if_none_exists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::assertEquals(PHP_SESSION_NONE, session_status());

        $next = function ($route) {};
        $this->middleware->handle(['path' => '/login'], $next);

        self::assertEquals(PHP_SESSION_ACTIVE, session_status());
    }
}
