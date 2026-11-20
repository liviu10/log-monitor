<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\LogQueueController;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LogQueueControllerTest extends TestCase
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

    public function test_index_renders_queue_view(): void
    {
        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(1);

        $stmtRows = $this->createMock(PDOStatement::class);
        $stmtRows->method('fetchAll')->willReturn([
            ['id' => 11, 'app_id' => 1, 'app_name' => 'CoreAPI', 'payload_raw' => '{"message":"Queued test"}', 'created_at' => '2026-11-20 12:00:00'],
        ]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtCount, $stmtRows);

        $controller = new LogQueueController;
        $html = $controller->index();
        self::assertStringContainsString('Raw Log Ingestion Queue', $html);
        self::assertStringContainsString('Queued test', $html);
    }

    public function test_delete_redirects_with_flash(): void
    {
        $this->dbMock->expects($this->once())
            ->method('delete')
            ->with('log_queue', ['id' => 7])
            ->willReturn(1);

        $controller = new LogQueueController;
        try {
            $controller->delete('7');
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('success', $flash['type'] ?? '');
        }
    }

    public function test_purge_truncates_and_redirects(): void
    {
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->expects($this->once())->method('exec')->with('TRUNCATE TABLE log_queue')->willReturn(0);
        $this->dbMock->method('getConnection')->willReturn($pdoMock);

        $controller = new LogQueueController;
        try {
            $controller->purge();
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('success', $flash['type'] ?? '');
        }
    }

    public function test_is_log_worker_running_checks_heartbeat_freshness(): void
    {
        $heartbeat = \storage_path('worker.heartbeat');
        $dir = dirname($heartbeat);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $controller = new LogQueueController;

        @unlink($heartbeat);
        self::assertFalse($controller->isLogWorkerRunning());

        file_put_contents($heartbeat, (string) time());
        self::assertTrue($controller->isLogWorkerRunning());

        file_put_contents($heartbeat, (string) (time() - 120));
        self::assertFalse($controller->isLogWorkerRunning());

        @unlink($heartbeat);
    }
}
