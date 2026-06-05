<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\DashboardController;
use App\Models\App;
use App\Models\Log;

/**
 * Subclassed dashboard controller for capturing render calls.
 */
class TestableDashboardController extends DashboardController
{
    public string $renderedView = '';

    public array $renderedData = [];

    /**
     * @return never
     */
    protected function redirect(string $url)
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
        $this->appModel = new App();
        $this->logModel = new Log();
        $this->controller = new TestableDashboardController();

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_SESSION['auth.user'] = ['id' => 1, 'username' => 'admin'];
        $this->appId = $this->appModel->create('Dashboard App', 'dash-key');
    }

    public function testIndexRendersDashboardWithCorrectData(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Error on dashboard');
        $this->logModel->create($this->appId, 'INFO', 'Info on dashboard');

        $this->controller->index();

        $this->assertEquals('dashboard/index', $this->controller->renderedView);
        $this->assertCount(2, $this->controller->renderedData['logs']);
        $this->assertCount(1, $this->controller->renderedData['apps']);
        $this->assertEquals(1, $this->controller->renderedData['page']);
        $this->assertEquals(1, $this->controller->renderedData['totalPages']);
    }

    public function testIndexAppliesFiltersCorrectly(): void
    {
        $this->logModel->create($this->appId, 'ERROR', 'Error on dashboard');
        $this->logModel->create($this->appId, 'INFO', 'Info on dashboard');

        // Filter by Level
        $this->controller->index(['level' => 'ERROR']);
        $this->assertCount(1, $this->controller->renderedData['logs']);
        $this->assertEquals('ERROR', $this->controller->renderedData['logs'][0]['level']);
    }
}
