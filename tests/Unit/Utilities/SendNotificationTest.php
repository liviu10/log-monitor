<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\SendNotification;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

if (! function_exists('__')) {
    /**
     * @param  array<string, string>  $replace
     */
    function __(string $key, array $replace = []): string
    {
        return strtr($key, $replace);
    }
}

/**
 * Class SendNotificationTest
 *
 * @category Tests
 */
class SendNotificationTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = $_ENV;

        // Default to production so it tries to send emails
        $_ENV['APP_ENV'] = 'production';

        // Prevent LogViaStream from hanging if it tries to send an error log
        $_ENV['LOG_SERVER_URL'] = 'invalid-url';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    public function test_constructor_disables_sending_in_dev_env(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notification system is disabled in this runtime environment.');

        $sender->handle(['to' => 'test@example.com', 'message' => 'Hello']);
    }

    public function test_constructor_disables_sending_in_development_env(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notification system is disabled in this runtime environment.');

        $sender->handle(['to' => 'test@example.com', 'message' => 'Hello']);
    }

    public function test_handle_throws_if_recipient_missing(): void
    {
        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recipient field is required.');

        $sender->handle(['message' => 'Hello']);
    }

    public function test_handle_throws_if_message_missing(): void
    {
        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The message body field is required.');

        $sender->handle(['to' => 'test@example.com']);
    }

    public function test_handle_throws_if_attachment_is_invalid(): void
    {
        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The provided attachment file is inaccessible or invalid.');

        $sender->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
            'attachmentPath' => '/non/existent/file_that_does_not_exist_123.txt',
        ]);
    }

    public function test_handle_configures_phpmailer_and_throws_on_network_failure(): void
    {
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = 65535; // Closed port to trigger instant failure
        $_ENV['SMTP_USERNAME'] = 'user';
        $_ENV['SMTP_PASSWORD'] = 'pass';
        $_ENV['SMTP_FROM'] = 'noreply@test.com';
        $_ENV['SMTP_CC'] = 'cc1@test.com, cc2@test.com';
        $_ENV['SMTP_BCC'] = 'bcc@test.com';
        $_ENV['APP_NAME'] = 'Test App';
        $_ENV['SMTP_SUBJECT'] = 'Test Subject';

        // Proxy settings
        $_ENV['PROXY_HOST'] = '127.0.0.1';
        $_ENV['PROXY_PORT'] = '8080';

        $sender = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A critical error occurred while sending the notification.');

        // This will fail when it calls $mail->send() because of the fake SMTP port (connection refused)
        $sender->handle([
            'to' => 'recipient1@example.com, recipient2@example.com',
            'message' => 'Test message',
            'priority' => 1,
        ]);
    }

    public function test_handle_with_valid_attachment_throws_on_network_failure(): void
    {
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = 65535; // Closed port to trigger instant failure

        $tempFile = tempnam(sys_get_temp_dir(), 'test_att');
        file_put_contents($tempFile, 'test attachment content');

        try {
            $sender = new SendNotification;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('A critical error occurred while sending the notification.');

            $sender->handle([
                'to' => 'test@example.com',
                'message' => 'Test message',
                'attachmentPath' => $tempFile,
            ]);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_handle_succeeds_and_returns_mime_message(): void
    {
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
                return 'success_mime';
            }
        };

        $sender = new SendNotification($dummyMailer);
        $result = $sender->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
        ]);

        self::assertEquals('success_mime', $result);
    }

    public function test_handle_succeeds_with_html_mode(): void
    {
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
                return 'success_mime_html';
            }
        };

        $sender = new SendNotification($dummyMailer);
        $result = $sender->handle([
            'to' => 'test@example.com',
            'message' => '<p>Hello</p>',
            'isHtml' => true,
        ]);

        self::assertEquals('success_mime_html', $result);
        self::assertTrue($dummyMailer->ContentType === 'text/html');
    }

    public function test_handle_catches_attachment_exception_and_continues(): void
    {
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
                return 'success_mime_with_attachment_error';
            }

            public function addAttachment($path, $name = '', $encoding = 'base64', $type = '', $disposition = 'attachment'): bool
            {
                throw new \Exception('Mock attachment exception');
            }
        };

        $tempFile = tempnam(sys_get_temp_dir(), 'test_att');
        file_put_contents($tempFile, 'content');

        try {
            $sender = new SendNotification($dummyMailer);
            $result = $sender->handle([
                'to' => 'test@example.com',
                'message' => 'Hello',
                'attachmentPath' => $tempFile,
            ]);

            self::assertEquals('success_mime_with_attachment_error', $result);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
