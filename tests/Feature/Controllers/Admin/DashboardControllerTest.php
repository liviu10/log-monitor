<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\DashboardController;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class DashboardControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    /** @var array<int|string, mixed> */
    private array $originalSession;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [
            'auth.user' => ['id' => 1, 'email' => 'admin@logmonitor.local', 'role_name' => 'ADMIN', 'role_id' => 1],
        ];
        $_GET = [];

        $this->cachePath = \storage_path('cache').'/dashboard_stats.json';
        if (file_exists($this->cachePath)) {
            @unlink($this->cachePath);
        }

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_GET = [];
        if (file_exists($this->cachePath)) {
            @unlink($this->cachePath);
        }
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_renders_dashboard_with_stats_and_charts(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['total' => 12, 'critical' => 2, 'warning' => 3]);
        $stmt->method('fetchColumn')->willReturn(4);
        $stmt->method('fetchAll')->willReturn([]);
        $this->dbMock->method('query')->willReturn($stmt);

        $controller = new DashboardController;
        $html = $controller->index();

        self::assertStringContainsString('Telemetry Dashboard', $html);
        self::assertStringContainsString('chartLogVolume', $html);
        self::assertStringContainsString('/assets/vendor/chartjs/chart.umd.min.js', $html);
    }

    public function test_index_with_refresh_param_redirects_to_admin(): void
    {
        $_GET['refresh'] = '1';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(['total' => 0]);
        $stmt->method('fetchColumn')->willReturn(0);
        $stmt->method('fetchAll')->willReturn([]);
        $this->dbMock->method('query')->willReturn($stmt);

        $controller = new DashboardController;
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('Redirecting to: /admin');
        $controller->index();
    }

    public function test_get_analytics_data_supports_day_month_year_and_custom_ranges(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $this->dbMock->method('query')->willReturn($stmt);

        $controller = new DashboardController;

        $day = $controller->getAnalyticsData('day');
        self::assertSame('day', $day['period']);
        $dayLine = is_array($day['lineChart'] ?? null) ? $day['lineChart'] : [];
        self::assertCount(24, is_array($dayLine['labels'] ?? null) ? $dayLine['labels'] : []);

        $month = $controller->getAnalyticsData('month');
        self::assertSame('month', $month['period']);
        $monthLine = is_array($month['lineChart'] ?? null) ? $month['lineChart'] : [];
        self::assertCount(30, is_array($monthLine['labels'] ?? null) ? $monthLine['labels'] : []);

        $year = $controller->getAnalyticsData('year');
        self::assertSame('year', $year['period']);
        $yearLine = is_array($year['lineChart'] ?? null) ? $year['lineChart'] : [];
        self::assertCount(12, is_array($yearLine['labels'] ?? null) ? $yearLine['labels'] : []);

        $custom = $controller->getAnalyticsData('custom', '2026-11-01', '2026-11-05');
        self::assertSame('custom', $custom['period']);
        $customLine = is_array($custom['lineChart'] ?? null) ? $custom['lineChart'] : [];
        self::assertCount(5, is_array($customLine['labels'] ?? null) ? $customLine['labels'] : []);
    }

    public function test_chart_data_returns_json_response(): void
    {
        $_GET['period'] = 'month';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $this->dbMock->method('query')->willReturn($stmt);

        $controller = new DashboardController;
        try {
            $controller->chartData();
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('JSON_RESPONSE:200:', $e->getMessage());
            $decoded = json_decode(substr($e->getMessage(), strlen('JSON_RESPONSE:200:')), true);
            self::assertIsArray($decoded);
            self::assertSame('month', $decoded['period'] ?? null);
        }
    }
}
