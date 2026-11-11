<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Models\App;
use App\Models\Log;
use App\Utilities\Cache;
use Tests\TestCase;

if (! class_exists('Tests\Feature\HttpRedirectException')) {
    class HttpRedirectException extends \Exception {}
}

if (! class_exists('Tests\Feature\HttpResponseException')) {
    class HttpResponseException extends \Exception
    {
        public array $data;

        public int $statusCode;

        public function __construct(array $data = [], int $statusCode = 200)
        {
            parent::__construct('HTTP Response sent', $statusCode);
            $this->data = $data;
            $this->statusCode = $statusCode;
        }
    }
}

/**
 * Subclassed dashboard controller for capturing render calls.
 */
class TestableDashboardController extends DashboardController
{
    public string $renderedView = '';

    public array $renderedData = [];

    public ?array $jsonOutput = null;

    protected function redirect(string $url): never
    {
        throw new HttpRedirectException($url);
    }

    protected function render(string $view, array $data = []): void
    {
        $this->renderedView = $view;
        $this->renderedData = $data;
    }

    protected function jsonResponse(array $data, int $status = 200): never
    {
        $this->jsonOutput = $data;
        throw new HttpResponseException($data, $status);
    }
}

class DashboardControllerTest extends TestCase
{
    private App $appModel;

    private Log $logModel;

    private TestableDashboardController $controller;

    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App;
        $this->logModel = new Log;
        $this->controller = new TestableDashboardController;

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];
        $this->appId = $this->appModel->create('Dashboard App', 'dash-key');

        $cacheDir = dirname(__DIR__, 2).'/storage/framework/cache';
        if (is_dir($cacheDir)) {
            (new Cache($cacheDir))->clear();
        }
    }

    public function test_index_renders_dashboard_with_correct_data(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Error on dashboard');
        $this->logModel->create($this->appId, 'INFO', 'Info on dashboard');

        $this->controller->index();

        $this->assertEquals('dashboard/index', $this->controller->renderedView);
        $this->assertEquals(2, $this->controller->renderedData['totalLogs']);
        $this->assertEquals(1, $this->controller->renderedData['errorLogs']);
        $this->assertEquals(0, $this->controller->renderedData['warningLogs']);
        $this->assertEquals(1, $this->controller->renderedData['infoLogs']);
        $this->assertEquals(50.0, $this->controller->renderedData['successRate']);
        $this->assertEquals(1, $this->controller->renderedData['activeApps']);
        $this->assertCount(2, $this->controller->renderedData['latestLogs']);
        $this->assertCount(1, $this->controller->renderedData['topApps']);
    }

    public function test_index_calculates_percentages_correctly(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Error on dashboard');
        $this->logModel->create($this->appId, 'INFO', 'Info on dashboard');

        $this->controller->index();

        $this->assertEquals(50.0, $this->controller->renderedData['errorPct']);
        $this->assertEquals(0.0, $this->controller->renderedData['warningPct']);
        $this->assertEquals(50.0, $this->controller->renderedData['infoPct']);
    }

    public function test_get_analytics_data_structure_and_timeframes(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Error log for charts');
        $this->logModel->create($this->appId, 'WARNING', 'Warning log for charts');
        $this->logModel->create($this->appId, 'INFO', 'Info log for charts');

        // Test day period
        $dayData = $this->controller->getAnalyticsData('day');
        $this->assertEquals('day', $dayData['period']);
        $this->assertArrayHasKey('lineChart', $dayData);
        $this->assertArrayHasKey('severityPie', $dayData);
        $this->assertArrayHasKey('appsBar', $dayData);
        $this->assertCount(24, $dayData['lineChart']['labels']);
        $this->assertEquals(3, $dayData['summary']['total']);
        $this->assertEquals(1, $dayData['summary']['errors']);

        // Test month period
        $monthData = $this->controller->getAnalyticsData('month');
        $this->assertEquals('month', $monthData['period']);
        $this->assertCount(30, $monthData['lineChart']['labels']);
        $this->assertEquals(3, $monthData['summary']['total']);

        // Test year period
        $yearData = $this->controller->getAnalyticsData('year');
        $this->assertEquals('year', $yearData['period']);
        $this->assertCount(12, $yearData['lineChart']['labels']);
        $this->assertEquals(3, $yearData['summary']['total']);
    }

    public function test_get_analytics_data_custom_range(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Custom error');
        $this->logModel->create($this->appId, 'INFO', 'Custom info');

        $startDate = date('Y-m-d', strtotime('-2 days'));
        $endDate = date('Y-m-d');

        $customData = $this->controller->getAnalyticsData('custom', $startDate, $endDate);
        $this->assertEquals('custom', $customData['period']);
        $this->assertNotEmpty($customData['lineChart']['labels']);
        $this->assertEquals(2, $customData['summary']['total']);
        $this->assertEquals(1, $customData['summary']['errors']);
        $this->assertContains('ERROR', $customData['severityPie']['labels']);
        $this->assertContains('INFO', $customData['severityPie']['labels']);
    }

    public function test_chart_data_endpoint_returns_json(): void
    {
        $this->logModel->create($this->appId, 'INFO', 'Endpoint test info');

        try {
            $this->controller->chartData(['period' => 'day']);
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $e) {
            $this->assertEquals(200, $e->getCode());
            $this->assertIsArray($this->controller->jsonOutput);
            $this->assertEquals('day', $this->controller->jsonOutput['period']);
            $this->assertArrayHasKey('lineChart', $this->controller->jsonOutput);
        }
    }
}
