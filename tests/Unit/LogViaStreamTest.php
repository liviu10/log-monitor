<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\LogViaStream;
use RuntimeException;
use Tests\TestCase;

class LogViaStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Truncate apps table to force fallback to $_ENV
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        $_ENV['LOG_API_KEY'] = '';
        $_ENV['LOG_SERVER_URL'] = '';
    }

    public function test_register_handlers_throws_exception_when_no_api_key_is_present(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuratie invalida: LOG_API_KEY lipseste sau este visa.');
        LogViaStream::registerHandlers();
    }

    public function test_register_handlers_throws_exception_when_no_url_is_present(): void
    {
        $_ENV['LOG_API_KEY'] = 'test-key';
        $_ENV['LOG_SERVER_URL'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuratie invalida: LOG_SERVER_URL lipseste sau nu este un URL valid.');
        LogViaStream::registerHandlers();
    }

    public function test_send_handles_stream_failures_gracefully_and_returns_false(): void
    {
        $_ENV['LOG_API_KEY'] = 'test-fallback-key';
        $_ENV['LOG_SERVER_URL'] = 'http://localhost:54321/non-existent-endpoint'; // Should fail connection

        LogViaStream::registerHandlers();

        $result = LogViaStream::send('ERROR', 'Test connection failure');
        $this->assertFalse($result);
    }
}
