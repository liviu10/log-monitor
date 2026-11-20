<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Core\MySQLWrapper;
use App\Middleware\AuthMiddleware;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Class AuthMiddlewareTest
 *
 * Unit tests for AuthMiddleware.
 *
 * @category Tests
 */
class AuthMiddlewareTest extends TestCase
{
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new AuthMiddleware;

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

    public function test_handle_blocks_unauthenticated_user_with_404_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        // We don't assert the exact message because it might be localized using __()

        $next = function ($route) {
            self::fail('The next closure should not be called if unauthenticated.');
        };

        $this->middleware->handle(['path' => '/admin'], $next);
    }

    public function test_handle_allows_authenticated_user(): void
    {
        $_SESSION['auth.user'] = [
            'id' => 1,
            'email' => 'admin@aegis.local',
        ];
        $_SESSION['auth.verified_at'] = time();

        $called = false;
        $next = function ($route) use (&$called) {
            $called = true;

            return 'passed';
        };

        $result = $this->middleware->handle(['path' => '/admin'], $next);

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
            $next = function ($route) {};
            $this->middleware->handle(['path' => '/admin'], $next);
        } catch (RuntimeException $e) {
            // Expected 404 exception
        }

        self::assertEquals(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * Installs a MySQLWrapper mock whose query()->fetch() returns $row (or throws).
     */
    private function mockUserRow(mixed $row, bool $throw = false): void
    {
        $db = $this->createMock(MySQLWrapper::class);
        if ($throw) {
            $db->method('query')->willThrowException(new \PDOException('down'));
        } else {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('fetch')->willReturn($row);
            $db->method('query')->willReturn($stmt);
        }
        (new ReflectionClass(MySQLWrapper::class))->getProperty('instance')->setValue(null, $db);
    }

    private function resetDb(): void
    {
        (new ReflectionClass(MySQLWrapper::class))->getProperty('instance')->setValue(null, null);
    }

    public function test_handle_logs_out_deactivated_user_on_revalidation(): void
    {
        $_SESSION['auth.user'] = ['id' => 7, 'role_name' => 'ADMIN', 'role_id' => 1];
        $_SESSION['auth.verified_at'] = time() - 3600;
        $this->mockUserRow(['id' => 7, 'status' => 'INACTIVE', 'role_id' => 1, 'role_name' => 'ADMIN']);

        try {
            $this->middleware->handle(['path' => '/admin'], function () {
                self::fail('Deactivated users must not pass.');
            });
            self::fail('Expected a 404 exception.');
        } catch (RuntimeException $e) {
            self::assertSame(404, $e->getCode());
            self::assertArrayNotHasKey('auth.user', $_SESSION);
        } finally {
            $this->resetDb();
        }
    }

    public function test_handle_refreshes_role_from_database(): void
    {
        $_SESSION['auth.user'] = ['id' => 7, 'role_name' => 'ADMIN', 'role_id' => 1];
        $_SESSION['auth.verified_at'] = time() - 3600;
        $this->mockUserRow(['id' => 7, 'status' => 'ACTIVE', 'role_id' => 2, 'role_name' => 'USER']);

        try {
            $result = $this->middleware->handle(['path' => '/admin'], fn () => 'passed');
        } finally {
            $this->resetDb();
        }

        self::assertSame('passed', $result);
        $user = $_SESSION['auth.user'];
        self::assertIsArray($user);
        self::assertSame('USER', $user['role_name']);
        self::assertSame(2, $user['role_id']);
        self::assertIsInt($_SESSION['auth.verified_at']);
    }

    public function test_handle_keeps_session_when_database_is_unavailable(): void
    {
        $_SESSION['auth.user'] = ['id' => 7, 'role_name' => 'ADMIN', 'role_id' => 1];
        $this->mockUserRow(null, true);

        try {
            $result = $this->middleware->handle(['path' => '/admin'], fn () => 'passed');
        } finally {
            $this->resetDb();
        }

        self::assertSame('passed', $result);
    }
}
