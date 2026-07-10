<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\LogViaStream;
use RuntimeException;
use Tests\TestCase;

class LogViaStreamTest extends TestCase
{
    private bool $handlersRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Truncate apps table to force fallback to $_ENV
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_ENV['LOG_API_KEY'] = '';
        $_ENV['LOG_SERVER_URL'] = '';
        $this->handlersRegistered = false;
    }

    protected function tearDown(): void
    {
        if ($this->handlersRegistered) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    public function test_register_handlers_throws_exception_when_no_api_key_is_present(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration: LOG_API_KEY is missing or empty.');
        LogViaStream::registerHandlers();
    }

    public function test_register_handlers_throws_exception_when_no_url_is_present(): void
    {
        $_ENV['LOG_API_KEY'] = 'test-key';
        $_ENV['LOG_SERVER_URL'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid configuration: LOG_SERVER_URL is missing or not a valid URL.');
        LogViaStream::registerHandlers();
    }

    public function test_send_handles_stream_failures_gracefully_and_returns_false(): void
    {
        $_ENV['LOG_API_KEY'] = 'test-fallback-key';
        $_ENV['LOG_SERVER_URL'] = 'http://localhost:54321/non-existent-endpoint'; // Should fail connection

        LogViaStream::registerHandlers();
        $this->handlersRegistered = true;

        $result = LogViaStream::send('ERROR', 'Test connection failure');
        $this->assertFalse($result);
    }
}
