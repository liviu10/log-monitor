<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DashboardController;
use App\Models\App;
use App\Models\Log;
use App\Utilities\Cache;
use Tests\TestCase;

/**
 * Subclassed dashboard controller for capturing render calls.
 */
class TestableDashboardController extends DashboardController
{
    public string $renderedView = '';

    public array $renderedData = [];

    protected function redirect(string $url): never
    {
        throw new HttpRedirectException($url);
    }

    protected function render(string $view, array $data = []): void
    {
        $this->renderedView = $view;
        $this->renderedData = $data;
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
}
