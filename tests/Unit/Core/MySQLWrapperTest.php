<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\MySQLWrapper;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

// Fallback for translation function if it's not loaded in the current isolation scope
if (! function_exists('__')) {
    /**
     * @param  array<string, string|int|float>  $replace
     */
    function __(string $key, array $replace = []): string
    {
        return strtr($key, $replace);
    }
}

/**
 * Class MySQLWrapperTest
 *
 * @category Tests
 */
class MySQLWrapperTest extends TestCase
{
    private function resetSingleton(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetSingleton();
        parent::tearDown();
    }

    /**
     * Helper to inject a PDO mock into the wrapper instance.
     */
    private function injectPdoMock(MySQLWrapper $wrapper): PDO&MockObject
    {
        $pdoMock = $this->createMock(PDO::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $connProp = $reflection->getProperty('connection');
        $connProp->setValue($wrapper, $pdoMock);

        return $pdoMock;
    }

    /**
     * Helper to get an instance without triggering the PDO constructor.
     */
    private function getWrapperWithoutConstructor(): MySQLWrapper
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $wrapper = $reflection->newInstanceWithoutConstructor();

        // Also set it as the singleton instance so getInstance() returns it
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $wrapper);

        return $wrapper;
    }

    public function test_get_instance_returns_singleton(): void
    {
        // For this test, we just set a dummy instance
        $wrapper = $this->getWrapperWithoutConstructor();

        $instance1 = MySQLWrapper::getInstance();
        $instance2 = MySQLWrapper::getInstance();

        self::assertSame($wrapper, $instance1);
        self::assertSame($instance1, $instance2);
    }

    public function test_get_connection_throws_when_disconnected(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $wrapper->disconnect();

        $this->expectException(RuntimeException::class);

        $wrapper->getConnection();
    }

    public function test_connect_throws_runtime_exception_on_failure(): void
    {
        $this->expectException(RuntimeException::class);

        // This will attempt to connect to a non-existent port on localhost, which should fail instantly with "Connection refused"
        new MySQLWrapper('127.0.0.1', 'invalid_db', 'user', 'pass', '65535');
    }

    public function test_query_success_returns_statement(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $sql = 'SELECT * FROM test';
        $params = ['id' => 1];

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);

        $result = $wrapper->query($sql, $params);
        self::assertSame($stmtMock, $result);
    }

    public function test_query_throws_on_failure(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $sql = 'SELECT * FROM test';

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Syntax error'));

        $this->expectException(RuntimeException::class);

        $wrapper->query($sql);
    }

    public function test_create_returns_last_insert_id(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with('INSERT INTO `users` (`name`, `email`) VALUES (?, ?)')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with(['John', 'john@example.com'])
            ->willReturn(true);

        $pdoMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('15');

        $result = $wrapper->create('users', ['name' => 'John', 'email' => 'john@example.com']);
        self::assertEquals('15', $result);
    }

    public function test_create_returns_row_count_if_no_insert_id(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $pdoMock->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('0');

        $stmtMock->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $wrapper->create('settings', ['key' => 'theme', 'val' => 'dark']);
        self::assertEquals(1, $result);
    }

    public function test_create_throws_on_empty_data(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $wrapper->create('users', []);
    }

    public function test_read_returns_results(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with('SELECT `id`, `name` FROM `users` WHERE `status` = ? AND `role` = ?')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with(['active', 'admin'])
            ->willReturn(true);

        $expectedResult = [['id' => 1, 'name' => 'Admin']];
        $stmtMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn($expectedResult);

        $result = $wrapper->read('users', ['status' => 'active', 'role' => 'admin'], ['id', 'name']);
        self::assertEquals($expectedResult, $result);
    }

    public function test_read_returns_results_with_or_logic(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with('SELECT * FROM `users` WHERE `status` = ? OR `role` = ?')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with(['active', 'admin'])
            ->willReturn(true);

        $stmtMock->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $result = $wrapper->read('users', ['status' => 'active', 'role' => 'admin'], ['*'], 'OR');
        self::assertEquals([], $result);
    }

    public function test_update_returns_row_count(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with('UPDATE `users` SET `status` = ? WHERE `id` = ?')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with(['inactive', 5])
            ->willReturn(true);

        $stmtMock->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $wrapper->update('users', ['status' => 'inactive'], ['id' => 5]);
        self::assertEquals(1, $result);
    }

    public function test_update_throws_on_empty_data(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $wrapper->update('users', [], ['id' => 1]);
    }

    public function test_update_throws_on_empty_conditions(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $wrapper->update('users', ['status' => 'inactive'], []);
    }

    public function test_delete_returns_row_count(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $pdoMock = $this->injectPdoMock($wrapper);

        $stmtMock = $this->createMock(PDOStatement::class);

        $pdoMock->expects($this->once())
            ->method('prepare')
            ->with('DELETE FROM `users` WHERE `id` = ?')
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with([10])
            ->willReturn(true);

        $stmtMock->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $wrapper->delete('users', ['id' => 10]);
        self::assertEquals(1, $result);
    }

    public function test_delete_throws_on_empty_conditions(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $wrapper->delete('users', []);
    }

    public function test_log_emergency_writes_file_successfully(): void
    {
        $this->expectNotToPerformAssertions();
        $wrapper = $this->getWrapperWithoutConstructor();

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $method = $reflection->getMethod('logEmergency');
        // This will write an emergency log entry to storage/logs
        // Since we are running the test locally and storage/logs is standard, it should complete without exception.

        $oldErrorLog = ini_get('error_log');
        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        ini_set('error_log', $tempLog);

        try {
            $method->invoke($wrapper, 'Test emergency log output', ['mock_key' => 'mock_value']);
        } finally {
            ini_set('error_log', $oldErrorLog);
            unlink($tempLog);
        }
    }

    public function test_log_emergency_catches_file_exists_as_directory_error(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $method = $reflection->getMethod('logEmergency');
        $realLogsDir = dirname(__DIR__, 3).'/storage/logs';
        $backupLogsDir = dirname(__DIR__, 3).'/storage/logs_backup_test_1';

        if (file_exists($realLogsDir)) {
            rename($realLogsDir, $backupLogsDir);
        }

        $oldErrorLog = ini_get('error_log');
        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        ini_set('error_log', $tempLog);

        try {
            // Create a FILE where the directory should be
            file_put_contents($realLogsDir, 'this is a file');

            $method->invoke($wrapper, 'Test message', []);

            $logContent = file_get_contents($tempLog);
            $logContent = is_string($logContent) ? $logContent : '';
            self::assertStringContainsString('exists but is not a directory', $logContent);
        } finally {
            if (file_exists($realLogsDir) && ! is_dir($realLogsDir)) {
                unlink($realLogsDir);
            }
            if (file_exists($backupLogsDir)) {
                rename($backupLogsDir, $realLogsDir);
            }
            ini_set('error_log', $oldErrorLog);
            if (file_exists($tempLog)) {
                unlink($tempLog);
            }
        }
    }

    public function test_log_emergency_catches_not_writable_error(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $method = $reflection->getMethod('logEmergency');
        $realLogsDir = dirname(__DIR__, 3).'/storage/logs';
        $backupLogsDir = dirname(__DIR__, 3).'/storage/logs_backup_test_3';

        if (file_exists($realLogsDir) || is_link($realLogsDir)) {
            rename($realLogsDir, $backupLogsDir);
        }

        $oldErrorLog = ini_get('error_log');
        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        ini_set('error_log', $tempLog);

        try {
            // Symlink to a strictly read-only directory (/proc) to make is_writable fail even for root
            symlink('/proc', $realLogsDir);

            $method->invoke($wrapper, 'Test message', []);

            $logContent = file_get_contents($tempLog);
            $logContent = is_string($logContent) ? $logContent : '';
            self::assertStringContainsString('is not writable', $logContent);
        } finally {
            if (is_link($realLogsDir)) {
                unlink($realLogsDir);
            }
            if (file_exists($backupLogsDir)) {
                rename($backupLogsDir, $realLogsDir);
            }
            ini_set('error_log', $oldErrorLog);
            if (file_exists($tempLog)) {
                unlink($tempLog);
            }
        }
    }

    public function test_log_emergency_creates_missing_directory(): void
    {
        $wrapper = $this->getWrapperWithoutConstructor();
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $method = $reflection->getMethod('logEmergency');
        $realLogsDir = dirname(__DIR__, 3).'/storage/logs';
        $backupLogsDir = dirname(__DIR__, 3).'/storage/logs_backup_test_missing';

        if (file_exists($realLogsDir)) {
            rename($realLogsDir, $backupLogsDir);
        }

        try {
            $method->invoke($wrapper, 'Test missing directory message', ['key' => 'val']);
            self::assertDirectoryExists($realLogsDir);
        } finally {
            if (file_exists($realLogsDir)) {
                $files = glob($realLogsDir.'/*');
                if (is_array($files)) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                        }
                    }
                }
                rmdir($realLogsDir);
            }
            if (file_exists($backupLogsDir)) {
                rename($backupLogsDir, $realLogsDir);
            }
        }
    }
}
