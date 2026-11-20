<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Common;

use App\Controllers\Common\NotificationController;
use App\Core\MySQLWrapper;
use App\Utilities\SendNotification;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class NotificationControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    private mixed $originalAppEnv;

    private mixed $originalSmtpFrom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->originalSmtpFrom = $_ENV['SMTP_FROM'] ?? null;
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['SMTP_FROM'] = 'noreply@logmonitor.local';

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        if ($this->originalAppEnv !== null) {
            $_ENV['APP_ENV'] = $this->originalAppEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }
        if ($this->originalSmtpFrom !== null) {
            $_ENV['SMTP_FROM'] = $this->originalSmtpFrom;
        } else {
            unset($_ENV['SMTP_FROM']);
        }

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_send_alert_ignores_non_matching_level(): void
    {
        $this->dbMock->expects($this->never())->method('create');

        $controller = new NotificationController;
        $controller->sendAlert(
            ['id' => 1, 'name' => 'BillingApp'],
            ['level' => 'INFO', 'message' => 'Normal operation', 'context' => []],
            [
                'notification_channel' => 'email',
                'notification_levels' => 'error,critical',
                'notification_email' => 'ops@logmonitor.local',
            ]
        );
    }

    public function test_send_alert_dispatches_via_direct_notifier_when_configured(): void
    {
        $mailer = $this->createMock(PHPMailer::class);
        $mailer->expects($this->once())->method('send')->willReturn(true);

        $notifier = new SendNotification;
        $ref = new ReflectionClass(SendNotification::class);
        $ref->getProperty('customMailer')->setValue($notifier, $mailer);

        $controller = new NotificationController($notifier);
        $controller->sendAlert(
            ['id' => 1, 'name' => 'BillingApp'],
            [
                'level' => 'CRITICAL',
                'message' => 'Database pool exhausted',
                'context' => ['node' => 'db-01'],
            ],
            [
                'notification_channel' => 'email',
                'notification_levels' => 'error,critical',
                'notification_email' => 'alerts@logmonitor.local',
            ]
        );
    }

    public function test_send_alert_pushes_to_queue_when_direct_notifier_is_null(): void
    {
        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('queue_jobs', self::callback(function (array $data): bool {
                if (($data['queue'] ?? '') !== 'alerts' || ($data['status'] ?? '') !== 'pending') {
                    return false;
                }
                $rawPayload = $data['payload'] ?? '';
                if (! is_string($rawPayload)) {
                    return false;
                }
                $decoded = json_decode($rawPayload, true);
                if (! is_array($decoded) || ($decoded['action'] ?? '') !== 'email') {
                    return false;
                }
                $innerPayload = $decoded['payload'] ?? null;
                if (! is_array($innerPayload) || ($innerPayload['template_event'] ?? '') !== 'log_alert') {
                    return false;
                }
                $innerData = $innerPayload['data'] ?? null;

                return is_array($innerData) && ($innerData['to'] ?? '') === 'queue@logmonitor.local';
            }))
            ->willReturn(101);

        $controller = new NotificationController;
        $controller->sendAlert(
            ['id' => 2, 'name' => 'AuthAPI'],
            ['level' => 'ERROR', 'message' => 'Token validation exception', 'context' => []],
            [
                'notification_channel' => 'email',
                'notification_levels' => 'error',
                'notification_email' => 'queue@logmonitor.local',
            ]
        );
    }

    public function test_send_alert_loads_settings_from_model_when_null(): void
    {
        $this->dbMock->method('read')
            ->with('app_settings', ['app_id' => 55])
            ->willReturn([
                ['key' => 'notification_channel', 'value' => 'email'],
                ['key' => 'notification_levels', 'value' => 'warning'],
                ['key' => 'notification_email', 'value' => 'team@logmonitor.local'],
            ]);

        $this->dbMock->expects($this->once())->method('create')->willReturn(202);

        $controller = new NotificationController;
        $controller->sendAlert(
            ['id' => 55, 'name' => 'WorkerService'],
            ['level' => 'WARNING', 'message' => 'Slow query detected', 'context' => []],
            null
        );
    }
}
