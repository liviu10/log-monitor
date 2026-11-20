<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Common;

use App\Controllers\Common\NotifyManagerController;
use App\Core\MySQLWrapper;
use App\Utilities\SendNotification;
use PDOStatement;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class NotifyManagerControllerTest
 *
 * Unit tests for the NotifyManagerController.
 *
 * @category Tests
 */
class NotifyManagerControllerTest extends TestCase
{
    private NotifyManagerController $controller;

    /**
     * @var array<mixed>
     */
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new NotifyManagerController;
        $this->originalEnv = $_ENV;

        // Prevent actual email sending by simulating dev environment where SendNotification throws
        $_ENV['APP_ENV'] = 'dev';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        // Reset the singleton instance of MySQLWrapper
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, null);

        parent::tearDown();
    }

    public function test_handle_missing_action(): void
    {
        $jobData = ['payload' => []];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_invalid_payload(): void
    {
        $jobData = ['action' => 'email', 'payload' => 'not an array'];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_unsupported_action(): void
    {
        $jobData = ['action' => 'sms', 'payload' => []];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_email_missing_template(): void
    {
        $jobData = ['action' => 'email', 'payload' => ['data' => []]];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_email_invalid_data(): void
    {
        $jobData = ['action' => 'email', 'payload' => ['template_event' => 'test', 'data' => 'invalid']];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_email_missing_to(): void
    {
        $jobData = ['action' => 'email', 'payload' => ['template_event' => 'test', 'data' => ['subject' => 'Hello']]];
        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_email_success(): void
    {
        $jobData = [
            'action' => 'email',
            'payload' => [
                'template_event' => 'test_event',
                'data' => [
                    'to' => 'test@local.dev',
                    'subject' => 'Test Subject',
                    'message' => 'Hello world',
                ],
            ],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            [
                'id' => 1,
                'event_name' => 'test_event',
                'channel' => 'email',
                'subject' => 'Test Subject',
                'body_html' => '<p>Test Body {{ $message }}</p>',
                'is_active' => 1,
            ],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $dummyMailer = new class(true) extends PHPMailer
        {
            public function send(): bool
            {
                return true;
            }

            public function preSend(): bool
            {
                return true;
            }

            public function getSentMIMEMessage(): string
            {
                return 'mime';
            }
        };

        $_ENV['APP_ENV'] = 'production';
        $this->controller->setNotifier(new SendNotification($dummyMailer));

        $result = $this->controller->handle($jobData);
        self::assertTrue($result);
    }

    public function test_handle_email_with_custom_body_text_success(): void
    {
        $jobData = [
            'action' => 'email',
            'payload' => [
                'template_event' => 'custom_text_event',
                'data' => [
                    'to' => 'user@domain.com',
                    'message' => 'Custom plain body',
                ],
            ],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            [
                'id' => 99,
                'event_name' => 'custom_text_event',
                'channel' => 'email',
                'language' => 'en',
                'subject' => 'Custom Subject',
                'body_html' => '<p>{{ $message }}</p>',
                'body_text' => 'Plain: {{ $message }}',
                'is_active' => 1,
            ],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->expects($this->once())
            ->method('query')
            ->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $dummyMailer = new class(true) extends PHPMailer
        {
            public function send(): bool
            {
                return true;
            }

            public function preSend(): bool
            {
                return true;
            }

            public function getSentMIMEMessage(): string
            {
                return 'mime';
            }
        };

        $_ENV['APP_ENV'] = 'production';
        $this->controller->setNotifier(new SendNotification($dummyMailer));

        $result = $this->controller->handle($jobData);
        self::assertTrue($result);
    }

    public function test_handle_email_template_not_found(): void
    {
        $jobData = [
            'action' => 'email',
            'payload' => [
                'template_event' => 'missing_event',
                'data' => [
                    'to' => 'test@local.dev',
                ],
            ],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $mockWrapper->method('query')->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }

    public function test_handle_email_blade_render_failure(): void
    {
        $jobData = [
            'action' => 'email',
            'payload' => [
                'template_event' => 'broken_blade',
                'data' => [
                    'to' => 'test@local.dev',
                ],
            ],
        ];

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            [
                'id' => 2,
                'event_name' => 'broken_blade',
                'channel' => 'email',
                'subject' => 'Subject',
                'body_html' => '@php throw new \RuntimeException("Blade failure"); @endphp',
                'is_active' => 1,
            ],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $mockWrapper->method('query')->willReturn($mockStatement);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        $result = $this->controller->handle($jobData);
        self::assertFalse($result);
    }
}
