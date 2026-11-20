<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\UnauthorizedException;
use App\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Class RoleMiddlewareTest
 *
 * Unit tests for RoleMiddleware.
 *
 * @category Tests
 */
class RoleMiddlewareTest extends TestCase
{
    private RoleMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new RoleMiddleware;

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

    public function test_handle_passes_if_role_matches(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 1,
            'role_name' => 'admin', // Case-insensitive check test
        ];

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['path' => '/admin/dashboard'], $next, 'ADMIN');

        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_throws_unauthorized_if_no_user_and_not_settings_route(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->middleware->handle(['path' => '/admin/dashboard'], function () {}, 'ADMIN');
    }

    public function test_handle_throws_unauthorized_if_wrong_role_and_not_settings_route(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 2,
            'role_name' => 'EDITOR',
        ];

        $this->expectException(UnauthorizedException::class);
        $this->middleware->handle(['path' => '/admin/dashboard'], function () {}, 'ADMIN');
    }

    public function test_handle_throws_access_denied_if_no_user_and_settings_route(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied');

        $this->middleware->handle(['path' => '/admin/settings/users'], function () {}, 'ADMIN');
    }

    public function test_handle_throws_access_denied_if_wrong_role_and_settings_route(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 2,
            'role_name' => 'EDITOR',
        ];

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied');

        $this->middleware->handle(['path' => '/admin/settings/roles'], function () {}, 'ADMIN');
    }

    public function test_handle_starts_session_if_none_exists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::assertEquals(PHP_SESSION_NONE, session_status());

        try {
            $this->middleware->handle(['path' => '/admin/dashboard'], function () {}, 'ADMIN');
        } catch (UnauthorizedException $e) {
            // Expected
        }

        self::assertEquals(PHP_SESSION_ACTIVE, session_status());
    }
}
