<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Utilities\LogViaCurl;

class LogViaCurlTest extends TestCase
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

    public function testSendReturnsFalseWhenNoApiKeyIsPresent(): void
    {
        $result = LogViaCurl::send('INFO', 'Test message without key');
        $this->assertFalse($result);
    }

    public function testSendHandlesCurlFailuresGracefullyAndReturnsFalse(): void
    {
        $_ENV['LOG_API_KEY'] = 'test-fallback-key';
        $_ENV['LOG_SERVER_URL'] = 'http://localhost:54321/non-existent-endpoint'; // Should fail connection

        $result = LogViaCurl::send('ERROR', 'Test connection failure');
        $this->assertFalse($result);
    }
}
