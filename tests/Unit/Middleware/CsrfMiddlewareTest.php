<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Class CsrfMiddlewareTest
 *
 * Unit tests for CsrfMiddleware.
 *
 * @category Tests
 */
class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new CsrfMiddleware;

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        unset($_SERVER['CONTENT_TYPE']);
        unset($_SERVER['HTTP_CONTENT_TYPE']);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        if (function_exists('config')) {
            config('::reset');
        }
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_SESSION = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        unset($_SERVER['CONTENT_TYPE']);
        unset($_SERVER['HTTP_CONTENT_TYPE']);

        if (function_exists('config')) {
            config('::reset');
        }

        parent::tearDown();
    }

    public function test_handle_bypasses_get_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
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

    public function test_handle_fails_post_without_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        if (function_exists('config')) {
            config('::reset');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $this->middleware->handle([], function () {});
    }

    public function test_handle_fails_post_with_invalid_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'invalid_token_here';
        if (function_exists('config')) {
            config('::reset');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid CSRF token.');

        $this->middleware->handle([], function () {});
    }

    public function test_handle_passes_post_with_valid_post_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $validToken = csrf_token();
        $_POST['csrf_token'] = $validToken;
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

    public function test_handle_passes_put_with_valid_header_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        $validToken = csrf_token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $validToken;
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

    public function test_handle_fails_when_csrf_token_user_mismatches_authenticated_user(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $validToken = csrf_token();
        $_POST['csrf_token'] = $validToken;
        $_SESSION['auth.user'] = ['id' => 42];
        $_SESSION['csrf_token_user'] = 99;

        if (function_exists('config')) {
            config('::reset');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid CSRF token for the current user context.');

        $this->middleware->handle([], function () {});
    }

    public function test_handle_passes_when_csrf_token_user_matches_authenticated_user(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $validToken = csrf_token();
        $_POST['csrf_token'] = $validToken;
        $_SESSION['auth.user'] = ['id' => 42];
        $_SESSION['csrf_token_user'] = 42;

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
}
