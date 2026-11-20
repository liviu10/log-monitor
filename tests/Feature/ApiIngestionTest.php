<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\MySQLWrapper;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ApiIngestionTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    /** @var array<int|string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    /**
     * @return array{status: int|bool, body: string}
     */
    private function invokeApiEndpoint(): array
    {
        ob_start();
        include dirname(__DIR__, 2).'/public/api/log.php';
        $output = ob_get_clean();

        return [
            'status' => http_response_code(),
            'body' => is_string($output) ? $output : '',
        ];
    }

    public function test_api_rejects_get_with_405(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $res = $this->invokeApiEndpoint();

        self::assertSame(405, $res['status']);
        $decoded = json_decode($res['body'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
    }

    public function test_api_handles_options_preflight_with_204(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $res = $this->invokeApiEndpoint();

        self::assertSame(204, $res['status']);
        self::assertSame('', $res['body']);
    }

    public function test_api_rejects_missing_api_key_with_400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['X_API_KEY']);

        $res = $this->invokeApiEndpoint();

        self::assertSame(400, $res['status']);
        self::assertStringContainsString('X-API-KEY', $res['body']);
    }

    public function test_api_rejects_unknown_api_key_with_403(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_API_KEY'] = 'invalid-key-999';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with('SELECT id FROM apps WHERE api_key = ? LIMIT 1', ['invalid-key-999'])
            ->willReturn($stmt);

        $res = $this->invokeApiEndpoint();

        self::assertSame(403, $res['status']);
        $decoded = json_decode($res['body'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('error', $decoded);
    }
}
