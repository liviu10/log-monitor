<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\NotificationQueueController;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use App\Utilities\SessionManager;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature tests for NotificationQueueController.
 */
class NotificationQueueControllerTest extends TestCase
{
    private NotificationQueueController $controller;

    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/notification-queue';
        $_SESSION = [];
        $_POST = [];
        $_GET = [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        SessionManager::set('auth.user', [
            'id' => 1,
            'email' => 'admin@local',
            'first_name' => 'Admin',
            'last_name' => 'System',
            'role_name' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $this->dbMock);

        $this->controller = new NotificationQueueController;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $_GET = [];

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        parent::tearDown();
    }

    public function test_index_renders_successfully(): void
    {
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetch')->willReturn(['total' => 2]);

        $itemsStmt = $this->createMock(PDOStatement::class);
        $itemsStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'queue' => 'default', 'status' => 'pending', 'attempts' => 0, 'payload' => '{"action":"email","payload":{"template_event":"verify_email","data":{"to":"user@example.com"}}}', 'error_message' => null, 'available_at' => '2026-10-20 12:00:00', 'created_at' => '2026-10-20 12:00:00'],
            ['id' => 2, 'queue' => 'critical', 'status' => 'failed', 'attempts' => 1, 'payload' => '{"task":"alert"}', 'error_message' => 'Connection timed out', 'available_at' => '2026-10-20 12:05:00', 'created_at' => '2026-10-20 12:01:00'],
        ]);

        $statsStmt = $this->createMock(PDOStatement::class);
        $statsStmt->method('fetch')->willReturn([
            'total' => 2,
            'pending' => 1,
            'processing' => 0,
            'completed' => 0,
            'failed' => 1,
            'expired' => 0,
        ]);

        $queuesStmt = $this->createMock(PDOStatement::class);
        $queuesStmt->method('fetchAll')->willReturn([
            ['queue' => 'critical'],
            ['queue' => 'default'],
        ]);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($countStmt, $itemsStmt, $statsStmt, $queuesStmt);

        $html = $this->controller->index();
        self::assertStringContainsString('<title>Notification Queue - LogMonitor</title>', $html);
        self::assertStringContainsString('breadcrumb', $html);
        self::assertStringContainsString('Notification Queue', $html);
        self::assertStringContainsString('Run Worker Now', $html);
        self::assertStringContainsString('user@example.com', $html);
    }

    public function test_index_with_custom_filters_and_pagination(): void
    {
        $_GET['page'] = '2';
        $_GET['limit'] = '25';
        $_GET['sort_by'] = 'attempts';
        $_GET['sort_dir'] = 'ASC';
        $_GET['search'] = 'alert';
        $_GET['filter_status'] = 'failed';
        $_GET['filter_queue'] = 'critical';

        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetch')->willReturn(['total' => 50]);

        $itemsStmt = $this->createMock(PDOStatement::class);
        $itemsStmt->method('fetchAll')->willReturn([]);

        $statsStmt = $this->createMock(PDOStatement::class);
        $statsStmt->method('fetch')->willReturn([
            'total' => 50,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 50,
            'expired' => 0,
        ]);

        $queuesStmt = $this->createMock(PDOStatement::class);
        $queuesStmt->method('fetchAll')->willReturn([['queue' => 'critical']]);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($countStmt, $itemsStmt, $statsStmt, $queuesStmt);

        $html = $this->controller->index();
        self::assertStringContainsString('Notification Queue', $html);
    }

    public function test_index_permission_denied_for_unauthorized_user(): void
    {
        SessionManager::set('auth.user', [
            'id' => 2,
            'email' => 'user@local',
            'first_name' => 'Test',
            'last_name' => 'User',
            'role_name' => 'GUEST',
            'status' => 'ACTIVE',
        ]);

        $this->expectException(RedirectException::class);
        $this->controller->index();
    }

    public function test_retry_single_job_success(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('WHERE id = ?'), [7])
            ->willReturn($stmt);

        try {
            $this->controller->retry('7');
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_retry_single_job_failure(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        try {
            $this->controller->retry('99');
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('error', $flash['type']);
        }
    }

    public function test_retry_all_success_when_smtp_healthy(): void
    {
        unset($_ENV['MAIL_HOST']);
        $_POST['queue'] = 'critical';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(5);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains("WHERE status = 'failed' AND queue = ?"), ['critical'])
            ->willReturn($stmt);

        try {
            $this->controller->retryAll();
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_retry_all_warning_when_smtp_unreachable(): void
    {
        $_ENV['MAIL_HOST'] = '127.0.0.1:65534';
        $_POST['queue'] = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(3);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        try {
            $this->controller->retryAll();
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('warning', $flash['type']);
        }
    }

    public function test_delete_job_success(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('DELETE FROM queue_jobs WHERE id = ?', [14])
            ->willReturn($stmt);

        try {
            $this->controller->delete('14');
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_delete_job_failure(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->with('DELETE FROM queue_jobs WHERE id = ?', [999])
            ->willReturn($stmt);

        try {
            $this->controller->delete('999');
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('error', $flash['type']);
        }
    }

    public function test_run_worker_triggers_cycle(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);

        $this->dbMock->method('query')->willReturn($stmt);

        try {
            $this->controller->runWorker();
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_purge_with_custom_days(): void
    {
        $_POST['days'] = '7';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(12);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        try {
            $this->controller->purge();
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_purge_with_default_days(): void
    {
        unset($_POST['days']);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(0);

        $this->dbMock->expects(self::once())
            ->method('query')
            ->willReturn($stmt);

        try {
            $this->controller->purge();
        } catch (RedirectException $e) {
            self::assertSame('/admin/notification-queue', $e->getUrl());
        }
    }

    public function test_is_worker_active_when_unlocked(): void
    {
        $heartbeatFile = \storage_path('queue_worker.heartbeat');
        if (file_exists($heartbeatFile)) {
            @unlink($heartbeatFile);
        }

        $lockFile = dirname(__DIR__, 4).'/storage/views/worker.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        self::assertFalse($this->controller->isWorkerActive());

        touch($lockFile);
        self::assertFalse($this->controller->isWorkerActive());

        $fp = fopen($lockFile, 'r+');
        self::assertNotFalse($fp);
        flock($fp, LOCK_EX | LOCK_NB);

        self::assertTrue($this->controller->isWorkerActive());

        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lockFile);

        // Fresh heartbeat indicates active daemon polling between lock runs
        file_put_contents($heartbeatFile, (string) time());
        self::assertTrue($this->controller->isWorkerActive());

        file_put_contents($heartbeatFile, (string) (time() - 120));
        self::assertFalse($this->controller->isWorkerActive());
        @unlink($heartbeatFile);
    }
}
