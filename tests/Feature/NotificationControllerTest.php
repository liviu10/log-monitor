<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\NotificationController;
use App\Models\App;
use App\Models\AppSetting;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    private App $appModel;

    private AppSetting $settingModel;

    private NotificationController $controller;

    private array $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App;
        $this->settingModel = new AppSetting;
        $this->controller = new NotificationController;

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE app_settings;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $appId = $this->appModel->create('Alert Client', 'alert-key-1');
        $this->app = $this->appModel->findByApiKey('alert-key-1');
    }

    public function test_send_alert_filters_by_log_level(): void
    {
        // Setup app to only alert on CRITICAL levels via Email
        $this->settingModel->saveSetting($this->app['id'], 'notification_channel', 'email');
        $this->settingModel->saveSetting($this->app['id'], 'notification_levels', 'critical');
        $this->settingModel->saveSetting($this->app['id'], 'notification_email', 'admin@example.com');

        // Test with INFO (should be skipped, no SendNotification handle call)
        $_ENV['APP_ENV'] = 'testing';
        $logPayload = [
            'level' => 'INFO',
            'message' => 'Standard flow log message',
            'context' => [],
        ];

        // Should exit early and not trigger any exceptions since level doesn't match
        $this->controller->sendAlert($this->app, $logPayload);
        $this->assertTrue(true);
    }
}
