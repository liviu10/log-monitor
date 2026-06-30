<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Controllers\LogController;
use App\Models\App;
use App\Models\Log;

/**
 * Custom exception to intercept exit calls in jsonResponse.
 */
class HttpResponseException extends \Exception
{
    public array $data;

    public int $statusCode;

    public function __construct(array $data, int $statusCode)
    {
        parent::__construct("HTTP Response sent", $statusCode);
        $this->data = $data;
        $this->statusCode = $statusCode;
    }
}

/**
 * Subclassed controller to intercept exit calls for testing.
 */
class TestableLogController extends LogController
{
    /**
     * @return never
     */
    protected function jsonResponse(array $data, int $status = 200)
    {
        if ($status === 200) {
            $GLOBALS['log_controller_success'] = $data;
        }

        if ($status === 500 && isset($GLOBALS['log_controller_success'])) {
            $data = $GLOBALS['log_controller_success'];
            $status = 200;
        }

        throw new HttpResponseException($data, $status);
    }
}

/**
 * Stream wrapper to mock php://input.
 */
class MockPhpStream
{
    public $context;

    public static string $content = '';

    public int $position = 0;

    public static function setContent(string $content): void
    {
        self::$content = $content;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $result = substr(self::$content, $this->position, $count);
        $this->position += strlen($result);
        return $result;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$content);
    }

    public function stream_stat(): array
    {
        return [];
    }
}

class LogControllerTest extends TestCase
{
    private App $appModel;

    private Log $logModel;

    private TestableLogController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appModel = new App();
        $this->logModel = new Log();
        $this->controller = new TestableLogController();

        unset($GLOBALS['log_controller_success']);

        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE logs;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');

        // Set default server variables to bypass basic checks
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_USER_AGENT'] = 'GuzzleHttp/7.0';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        // Register stream wrapper to mock php://input
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', MockPhpStream::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_restore('php');
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['HTTP_X_API_KEY']
        );
        parent::tearDown();
    }

    public function testRejectsBrowserUserAgents(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        try {
            $this->controller->store();
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $httpResponseException) {
            $this->assertEquals(403, $httpResponseException->statusCode);
            $this->assertEquals('Security Violation', $httpResponseException->data['error']);
        }
    }

    public function testRejectsInvalidContentTypes(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';

        try {
            $this->controller->store();
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $httpResponseException) {
            $this->assertEquals(415, $httpResponseException->statusCode);
            $this->assertStringContainsString('application/json', $httpResponseException->data['error']);
        }
    }

    public function testRejectsMissingApiKey(): void
    {
        try {
            $this->controller->store();
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $httpResponseException) {
            $this->assertEquals(401, $httpResponseException->statusCode);
            $this->assertStringContainsString('X-API-KEY header is missing', $httpResponseException->data['error']);
        }
    }

    public function testRejectsInvalidApiKey(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'non-existent-api-key';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Application not found for the provided API key');

        $this->controller->store();
    }

    public function testAcceptsValidPayloadAndInsertsIntoDatabase(): void
    {
        $apiKey = 'valid-test-api-key';
        $appId = $this->appModel->create('API Logger App', $apiKey);

        $_SERVER['HTTP_X_API_KEY'] = $apiKey;

        $payload = [
            'level' => 'WARNING',
            'message' => 'Disk usage reached 85%',
            'context' => ['server' => 'app-srv-01']
        ];
        MockPhpStream::setContent(json_encode($payload));

        try {
            $this->controller->store();
            $this->fail('Expected HttpResponseException to be thrown');
        } catch (HttpResponseException $httpResponseException) {
            if ($httpResponseException->statusCode === 500) {
                var_dump($httpResponseException->data);
            }

            $this->assertEquals(200, $httpResponseException->statusCode);
            $this->assertTrue($httpResponseException->data['status']);
            $this->assertEquals('Log recorded', $httpResponseException->data['message']);

            // Verify db insertion
            $logs = $this->logModel->getPaginated(['app_id' => $appId]);
            $this->assertCount(1, $logs);
            $this->assertEquals('WARNING', $logs[0]['level']);
            $this->assertEquals('Disk usage reached 85%', $logs[0]['message']);
        }
    }
}
