<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Utilities\SendNotification;
use RuntimeException;

class SendNotificationTest extends TestCase
{
    public function testSendNotificationIsDisabledInDevelopmentMode(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $notifier = new SendNotification();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notification system is disabled in this runtime environment.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello World'
        ]);
    }

    public function testRecipientIsRequiredWhenEnabled(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recipient field is required.');

        $notifier->handle([
            'message' => 'Hello World'
        ]);
    }

    public function testMessageBodyIsRequiredWhenEnabled(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The message body field is required.');

        $notifier->handle([
            'to' => 'test@example.com'
        ]);
    }

    public function testHandleThrowsExceptionOnInaccessibleAttachment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The provided attachment file is inaccessible or invalid.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
            'attachmentPath' => '/nonexistent/file/path.txt'
        ]);
    }

    public function testHandleThrowsExceptionOnDirectoryAttachment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $notifier = new SendNotification();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The provided attachment file is inaccessible or invalid.');

        $notifier->handle([
            'to' => 'test@example.com',
            'message' => 'Hello',
            'attachmentPath' => __DIR__ // directory path
        ]);
    }
}
