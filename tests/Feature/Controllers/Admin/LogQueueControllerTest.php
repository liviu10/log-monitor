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
        $_POST = [];

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_GET = [];
        $_POST = [];
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_renders_queue_view(): void
    {
        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(2);

        $stmtRows = $this->createMock(PDOStatement::class);
        $stmtRows->method('fetchAll')->willReturn([
            ['id' => 11, 'app_id' => 1, 'app_name' => 'CoreAPI', 'payload_raw' => '{"message":"Queued test"}', 'status' => 'pending', 'attempts' => 0, 'available_at' => '2026-11-20 12:00:00', 'error_message' => null, 'created_at' => '2026-11-20 12:00:00'],
            ['id' => 12, 'app_id' => 1, 'app_name' => 'CoreAPI', 'payload_raw' => 'bad', 'status' => 'failed', 'attempts' => 2, 'available_at' => '2026-11-20 12:05:00', 'error_message' => 'Job payload is invalid JSON.', 'created_at' => '2026-11-20 12:01:00'],
        ]);

        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn([
            'total' => 2,
            'pending' => 1,
            'processing' => 0,
            'completed' => 0,
            'failed' => 1,
            'expired' => 0,
        ]);

        $stmtApps = $this->createMock(PDOStatement::class);
        $stmtApps->method('fetchAll')->willReturn([
            ['id' => 1, 'name' => 'CoreAPI'],
        ]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtCount, $stmtRows, $stmtStats, $stmtApps);

        $controller = new LogQueueController;
        $html = $controller->index();
        self::assertStringContainsString('<title>Log Queue - LogMonitor</title>', $html);
        self::assertStringContainsString('Raw Log Ingestion Queue', $html);
        self::assertStringContainsString('Run Worker Now', $html);
        self::assertStringContainsString('Retry All Failed', $html);
        self::assertStringContainsString('Purge Processed', $html);
        self::assertStringContainsString('Queued test', $html);
    }

    public function test_index_with_search_status_and_app_filter(): void
    {
        $_GET['search'] = 'CoreAPI';
        $_GET['filter_status'] = 'failed';
        $_GET['filter_app_id'] = '1';

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetchColumn')->willReturn(0);

        $stmtRows = $this->createMock(PDOStatement::class);
        $stmtRows->method('fetchAll')->willReturn([]);

        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn([
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'expired' => 0,
        ]);

        $stmtApps = $this->createMock(PDOStatement::class);
        $stmtApps->method('fetchAll')->willReturn([]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtCount, $stmtRows, $stmtStats, $stmtApps);

        $controller = new LogQueueController;
        $html = $controller->index();
        self::assertStringContainsString('No jobs currently in the queue.', $html);
    }

    public function test_run_worker_processes_empty_queue_and_redirects(): void
    {
        $pdoMock = $this->createMock(PDO::class);
        $pdoMock->method('beginTransaction')->willReturn(true);
        $pdoMock->method('commit')->willReturn(true);
        $this->dbMock->method('getConnection')->willReturn($pdoMock);

        $stmtExpired = $this->createMock(PDOStatement::class);
        $stmtExpired->method('rowCount')->willReturn(0);

        $stmtEmpty = $this->createMock(PDOStatement::class);
        $stmtEmpty->method('fetchAll')->willReturn([]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtExpired, $stmtEmpty);

        $controller = new LogQueueController;
        try {
            $controller->runWorker();
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('info', $flash['type'] ?? '');
        }
    }

    public function test_retry_single_job_success(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(self::stringContains('WHERE id = ?'), [7])
            ->willReturn($stmt);

        $controller = new LogQueueController;
        try {
            $controller->retry('7');
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('success', $flash['type'] ?? '');
        }
    }

    public function test_retry_single_job_failure(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->willReturn($stmt);

        $controller = new LogQueueController;
        try {
            $controller->retry('99');
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('error', $flash['type'] ?? '');
        }
    }

    public function test_retry_all_failed_jobs(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(4);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'failed'"))
            ->willReturn($stmt);

        $controller = new LogQueueController;
        try {
            $controller->retryAll();
        } catch (RedirectException $e) {
            self::assertSame('/admin/log-queue', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('success', $flash['type'] ?? '');
        }
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

    public function test_purge_processed_deletes_and_redirects(): void
    {
        $_POST['days'] = '7';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(15);

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(self::stringContains('DELETE FROM log_queue'))
            ->willReturn($stmt);

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
