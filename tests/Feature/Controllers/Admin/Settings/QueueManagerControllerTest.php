<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin\Settings;

use App\Controllers\Admin\Settings\QueueManagerController;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use App\Utilities\SessionManager;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class QueueManagerControllerTest
 *
 * Feature tests for QueueManagerController.
 *
 * @category Tests
 */
class QueueManagerControllerTest extends TestCase
{
    private QueueManagerController $controller;

    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/settings/queue';
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

        $this->controller = new QueueManagerController;
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
        // 1. Mock count and items for getPaginatedFilteredJobs
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('fetch')->willReturn(['total' => 2]);

        $itemsStmt = $this->createMock(PDOStatement::class);
        $itemsStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'queue' => 'default', 'status' => 'pending', 'attempts' => 0, 'payload' => '{"task":"mail"}', 'error_message' => null, 'available_at' => null, 'created_at' => '2026-10-20 12:00:00'],
            ['id' => 2, 'queue' => 'critical', 'status' => 'failed', 'attempts' => 1, 'payload' => '{"task":"alert"}', 'error_message' => 'Connection timed out', 'available_at' => '2026-10-20 12:05:00', 'created_at' => '2026-10-20 12:01:00'],
        ]);

        // 2. Mock getStats
        $statsStmt = $this->createMock(PDOStatement::class);
        $statsStmt->method('fetch')->willReturn([
            'total' => 2,
            'pending' => 1,
            'processing' => 0,
            'completed' => 0,
            'failed' => 1,
            'expired' => 0,
        ]);

        // 3. Mock getDistinctQueues
        $queuesStmt = $this->createMock(PDOStatement::class);
        $queuesStmt->method('fetchAll')->willReturn([
            ['queue' => 'critical'],
            ['queue' => 'default'],
        ]);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($countStmt, $itemsStmt, $statsStmt, $queuesStmt);

        $html = $this->controller->index();
        self::assertStringContainsString('Queue Management', $html);
    }

    public function test_index_with_custom_filters_and_pagination(): void
    {
        $_GET['page'] = '2';
        $_GET['per_page'] = '25';
        $_GET['sort'] = 'attempts';
        $_GET['order'] = 'asc';
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
        self::assertStringContainsString('Queue Management', $html);
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
            $flash = getFlash();
            self::assertNotNull($flash);
            self::assertSame('success', $flash['type']);
        }
    }

    public function test_retry_all_warning_when_smtp_unreachable(): void
    {
        // Unreachable port
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
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
            self::assertSame('/admin/settings/queue', $e->getUrl());
        }
    }

    public function test_is_worker_active_when_unlocked(): void
    {
        $lockFile = dirname(__DIR__, 5).'/storage/views/worker.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        // When file does not exist
        self::assertFalse($this->controller->isWorkerActive());

        // When file exists and is not locked
        touch($lockFile);
        self::assertFalse($this->controller->isWorkerActive());

        // When file is locked by another process
        $fp = fopen($lockFile, 'r+');
        self::assertNotFalse($fp);
        flock($fp, LOCK_EX | LOCK_NB);

        self::assertTrue($this->controller->isWorkerActive());

        flock($fp, LOCK_UN);
        fclose($fp);

        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

        // When lock file cannot be opened (e.g. is a directory)
        mkdir($lockFile);
        self::assertFalse($this->controller->isWorkerActive());
        rmdir($lockFile);
    }
}
