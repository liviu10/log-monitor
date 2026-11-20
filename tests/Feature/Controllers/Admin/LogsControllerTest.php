<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\LogsController;
use App\Core\MySQLWrapper;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class LogsControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    /** @var array<int|string, mixed> */
    private array $originalSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [
            'auth.user' => ['id' => 1, 'email' => 'admin@logmonitor.local', 'role_name' => 'ADMIN', 'role_id' => 1],
        ];
        $_GET = [];

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_GET = [];
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_renders_logs_timeline_with_filters(): void
    {
        $_GET = [
            'page' => '1',
            'limit' => '25',
            'level' => 'ERROR',
            'search' => 'timeout',
            'sort_by' => 'created_at',
            'sort_dir' => 'ASC',
        ];

        $stmtLogs = $this->createMock(PDOStatement::class);
        $stmtLogs->method('fetchAll')->willReturn([
            [
                'id' => 101,
                'app_id' => 2,
                'app_name' => 'PaymentGateway',
                'level' => 'ERROR',
                'message' => 'Gateway timeout exception',
                'context' => '{"latency_ms":5012}',
                'created_at' => '2026-11-20 14:30:00',
            ],
        ]);

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(1);

        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn(['total' => 10, 'critical' => 2, 'warning' => 1]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtLogs, $stmtCount, $stmtStats);
        $this->dbMock->method('read')->with('apps', [], ['*'])->willReturn([
            ['id' => 2, 'name' => 'PaymentGateway', 'api_key' => 'k2'],
        ]);

        $controller = new LogsController;
        $html = $controller->index();

        self::assertStringContainsString('Logs Timeline', $html);
        self::assertStringContainsString('PaymentGateway', $html);
        self::assertStringContainsString('Gateway timeout exception', $html);
    }

    public function test_purge_validates_retention_and_archives_logs(): void
    {
        $tempDir = sys_get_temp_dir().'/logmonitor_purge_test_'.bin2hex(random_bytes(4));
        $tempFile = $tempDir.'/archive.log';

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(1);

        $stmtBatch = $this->createMock(PDOStatement::class);
        $stmtBatch->method('fetchAll')->willReturn([
            ['id' => 1, 'app_id' => 1, 'app_name' => 'OldApp', 'level' => 'INFO', 'message' => 'Old event', 'context' => null, 'created_at' => '2026-01-01 00:00:00'],
        ]);

        $stmtDelete = $this->createMock(PDOStatement::class);
        $stmtDelete->method('rowCount')->willReturn(1);

        $stmtOptimize = $this->createMock(PDOStatement::class);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtCount, $stmtBatch, $stmtDelete, $stmtOptimize);

        $controller = new LogsController;
        $result = $controller->purge(30, $tempFile);

        self::assertTrue($result['status'] ?? false);
        self::assertSame(1, $result['archived_count']);
        self::assertSame(1, $result['deleted_count']);
        self::assertFileExists($tempFile);

        @unlink($tempFile);
        @rmdir($tempDir);
    }

    public function test_purge_throws_when_backup_path_is_unwritable(): void
    {
        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(1);
        $this->dbMock->method('query')->willReturn($stmtCount);

        $controller = new LogsController;
        $this->expectException(RuntimeException::class);
        $controller->purge(30, sys_get_temp_dir());
    }
}
