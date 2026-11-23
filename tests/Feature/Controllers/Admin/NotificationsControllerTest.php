<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\NotificationsController;
use App\Core\MySQLWrapper;
use App\Utilities\SessionManager;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature tests for NotificationsController (/admin/notifications).
 */
class NotificationsControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/notifications';
        $_SESSION = [];
        $_GET = [];

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        SessionManager::set('auth.user', [
            'id' => 1,
            'email' => 'admin@logmonitor.local',
            'role_name' => 'ADMIN',
            'role_id' => 1,
        ]);

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET = [];
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_renders_sent_notifications_timeline(): void
    {
        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 1]);

        $stmtItems = $this->createMock(PDOStatement::class);
        $stmtItems->method('fetchAll')->willReturn([
            [
                'id' => 42,
                'queue' => 'alerts',
                'status' => 'completed',
                'attempts' => 1,
                'payload' => '{"action":"email","payload":{"template_event":"log_alert","data":{"to":"ops@example.com","app_name":"BillingAPI","level":"CRITICAL","message":"Payment gateway down"}}}',
                'processed_at' => '2026-11-23 07:30:00',
                'created_at' => '2026-11-23 07:29:50',
            ],
        ]);

        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn([
            'total' => 1,
            'alerts' => 1,
            'emails' => 0,
            'today' => 1,
        ]);

        $stmtQueues = $this->createMock(PDOStatement::class);
        $stmtQueues->method('fetchAll')->willReturn([
            ['queue' => 'alerts'],
        ]);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($stmtCount, $stmtItems, $stmtStats, $stmtQueues);

        $controller = new NotificationsController;
        $html = $controller->index();

        self::assertStringContainsString('<title>Notifications - LogMonitor</title>', $html);
        self::assertStringContainsString('Sent Notifications', $html);
        self::assertStringContainsString('ops@example.com', $html);
        self::assertStringContainsString('[BillingAPI / CRITICAL] Payment gateway down', $html);
    }

    public function test_index_handles_empty_results_and_filters(): void
    {
        $_GET['search'] = 'nonexistent';
        $_GET['queue'] = 'emails';
        $_GET['sort_by'] = 'processed_at';
        $_GET['sort_dir'] = 'ASC';
        $_GET['limit'] = '25';

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 0]);

        $stmtItems = $this->createMock(PDOStatement::class);
        $stmtItems->method('fetchAll')->willReturn([]);

        $stmtStats = $this->createMock(PDOStatement::class);
        $stmtStats->method('fetch')->willReturn([
            'total' => 0,
            'alerts' => 0,
            'emails' => 0,
            'today' => 0,
        ]);

        $stmtQueues = $this->createMock(PDOStatement::class);
        $stmtQueues->method('fetchAll')->willReturn([]);

        $this->dbMock->method('query')
            ->willReturnOnConsecutiveCalls($stmtCount, $stmtItems, $stmtStats, $stmtQueues);

        $controller = new NotificationsController;
        $html = $controller->index();

        self::assertStringContainsString('No sent notifications found for the selected filters.', $html);
    }
}
