<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\MySQLWrapper;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\UnauthorizedException;
use App\Middleware\PermissionMiddleware;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class PermissionMiddlewareTest
 *
 * Unit tests for PermissionMiddleware.
 *
 * @category Tests
 */
class PermissionMiddlewareTest extends TestCase
{
    private PermissionMiddleware $middleware;

    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new PermissionMiddleware;

        $_SESSION = [];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->dbMock = $this->createMock(MySQLWrapper::class);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        parent::tearDown();
    }

    public function test_handle_throws_unauthorized_if_no_user(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->middleware->handle([], function () {});
    }

    public function test_handle_bypasses_if_admin(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 1,
            'role_id' => 1,
            'role_name' => 'ADMIN',
        ];

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['handler' => 'Admin\Settings\UsersController@index'], $next);

        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_bypasses_for_guest_and_common_controllers(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 2,
            'role_id' => 2,
            'role_name' => 'EDITOR',
        ];

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['handler' => 'Guest\CmsController@index'], $next);
        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_throws_access_denied_if_no_permission(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 2,
            'role_id' => 2,
            'role_name' => 'EDITOR',
        ];

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('fetchAll')->willReturn([]);
        $this->dbMock->method('query')->willReturn($stmtMock); // No permission found

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Access Denied');

        $this->middleware->handle(['handler' => 'Admin\Settings\UsersController@index'], function () {});
    }

    public function test_handle_passes_if_permission_granted(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 2,
            'role_id' => 2,
            'role_name' => 'EDITOR',
        ];

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('fetchAll')->willReturn([['permission_key' => 'admin.settings.users.index']]);
        $this->dbMock->method('query')->willReturn($stmtMock);

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['handler' => 'Admin\Settings\UsersController@index'], $next);

        self::assertTrue($called);
        self::assertEquals('passed', $result);
    }

    public function test_handle_starts_session_if_none_exists(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::assertEquals(PHP_SESSION_NONE, session_status());

        try {
            $this->middleware->handle([], function () {});
        } catch (UnauthorizedException $e) {
            // Expected
        }

        self::assertEquals(PHP_SESSION_ACTIVE, session_status());
    }
}
