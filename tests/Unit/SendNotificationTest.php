<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\SendNotification;
use RuntimeException;
use Tests\TestCase;

class SendNotificationTest extends TestCase
{
    public function test_send_notification_is_disabled_in_development_mode(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $notifier = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notification system is disabled in this runtime environment.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello World',
        ]);
    }

    public function test_recipient_is_required_when_enabled(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recipient field is required.');

        $notifier->handle([
            'message' => 'Hello World',
        ]);
    }

    public function test_message_body_is_required_when_enabled(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The message body field is required.');

        $notifier->handle([
            'to' => 'test@example.com',
        ]);
    }

    public function test_handle_throws_exception_on_inaccessible_attachment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The provided attachment file is inaccessible or invalid.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
            'attachmentPath' => '/nonexistent/file/path.txt',
        ]);
    }

    public function test_handle_throws_exception_on_directory_attachment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The provided attachment file is inaccessible or invalid.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
            'attachmentPath' => __DIR__, // directory path
        ]);
    }
}
