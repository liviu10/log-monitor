<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\App;
use App\Models\Log;
use InvalidArgumentException;

class LogModelTest extends TestCase
{
    private App $appModel;
    private Log $logModel;
    private int $appId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->logModel = new Log();

        // Ensure database state is reset for testing
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Create a mock application to link logs
        $this->appId = $this->appModel->create('Test Logger Client', 'logger-key-client');
    }

    public function testCanCreateAndRetrieveLog(): void
    {
        $level = 'ERROR';
        $message = 'Something went wrong inside the billing module.';
        $context = ['billing_id' => 505];

        $logId = $this->logModel->create($this->appId, $level, $message, $context);
        $this->assertGreaterThan(0, $logId);

        $logs = $this->logModel->getPaginated(['app_id' => $this->appId]);
        $this->assertCount(1, $logs);
        
        $log = $logs[0];
        $this->assertEquals($logId, $log['id']);
        $this->assertEquals($level, $log['level']);
        $this->assertEquals($message, $log['message']);
        $this->assertEquals(json_encode($context), $log['context']);
    }

    public function testCannotCreateLogWithInvalidParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logModel->create($this->appId, '', 'Message');
    }

    public function testGetPaginatedFiltersByLogLevel(): void
    {
        // Insert INFO and WARNING logs
        $this->logModel->create($this->appId, 'INFO', 'Info message');
        $this->logModel->create($this->appId, 'WARNING', 'Warning message');

        // Total
        $this->assertEquals(2, $this->logModel->count());

        // Only INFO
        $infoLogs = $this->logModel->getPaginated(['level' => 'INFO']);
        $this->assertCount(1, $infoLogs);
        $this->assertEquals('INFO', $infoLogs[0]['level']);

        // Only WARNING count
        $this->assertEquals(1, $this->logModel->count(['level' => 'WARNING']));
    }
}
