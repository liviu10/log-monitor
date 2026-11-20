<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\AppErrorHandler;
use App\Core\LogViaStream;
use ErrorException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

if (! class_exists('Tests\Unit\Core\MockHttpStreamWrapperForErrorHandler')) {
    class MockHttpStreamWrapperForErrorHandler
    {
        /** @var resource|null */
        public mixed $context = null;

        public static string $lastPayload = '';

        /** @var array<int|string, mixed> */
        public static array $lastHeaders = [];

        public static ?Throwable $throwException = null;

        private int $position = 0;

        private string $data = 'Mock response';

        public function stream_open(string $path, string $mode, int $options, mixed &$opened_path): bool
        {
            if (self::$throwException !== null) {
                throw self::$throwException;
            }

            if (is_resource($this->context)) {
                $opts = stream_context_get_options($this->context);
                if (isset($opts['http']) && is_array($opts['http'])) {
                    self::$lastPayload = is_string($opts['http']['content'] ?? null) ? $opts['http']['content'] : '';
                    self::$lastHeaders = is_array($opts['http']['header'] ?? null) ? $opts['http']['header'] : [];
                }
            }

            return true;
        }

        public function stream_read(int $count): string
        {
            $ret = substr($this->data, $this->position, $count);
            $this->position += strlen($ret);

            return $ret;
        }

        public function stream_eof(): bool
        {
            return $this->position >= strlen($this->data);
        }

        /** @return array<string|int, mixed> */
        public function stream_stat(): array
        {
            return [];
        }

        /** @return array<string|int, mixed>|bool */
        public function url_stat(string $path, int $flags): array|bool
        {
            return [];
        }
    }
}

class AppErrorHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array('http', stream_get_wrappers(), true)) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', MockHttpStreamWrapperForErrorHandler::class);

        MockHttpStreamWrapperForErrorHandler::$lastPayload = '';
        MockHttpStreamWrapperForErrorHandler::$lastHeaders = [];
        MockHttpStreamWrapperForErrorHandler::$throwException = null;

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['LOG_SERVER_URL'] = 'http://localhost/log';
        $_ENV['LOG_API_KEY'] = 'test-api-key';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-uri';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($GLOBALS['mock_http_response_header']);

        $limitFile = sys_get_temp_dir().'/log_rate_limit.json';
        if (is_file($limitFile)) {
            @unlink($limitFile);
        }

        // Reset LogViaStream static state using reflection
        $reflection = new ReflectionClass(LogViaStream::class);
        $reflection->getProperty('url')->setValue(null, '');
        $reflection->getProperty('apiKey')->setValue(null, '');
        $reflection->getProperty('isLogging')->setValue(null, false);
        $reflection->getProperty('logCountInRequest')->setValue(null, 0);
        $reflection->getProperty('requestId')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        if (in_array('http', stream_get_wrappers(), true)) {
            @stream_wrapper_restore('http');
        }
        unset($GLOBALS['mock_http_response_header']);
        parent::tearDown();
    }

    /**
     * @return array{level: string, message: string, context: array<mixed>}
     */
    private function getDecodedPayload(): array
    {
        self::assertNotEmpty(MockHttpStreamWrapperForErrorHandler::$lastPayload, 'Payload is empty, send() might have failed or aborted');
        $data = json_decode(MockHttpStreamWrapperForErrorHandler::$lastPayload, true);
        self::assertIsArray($data);
        $context = $data['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        return [
            'level' => is_string($data['level'] ?? null) ? $data['level'] : '',
            'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
            'context' => $context,
        ];
    }

    public function test_register_sets_error_and_exception_handlers(): void
    {
        AppErrorHandler::register();

        try {
            $handler = set_error_handler(function () {
                return true;
            });
            restore_error_handler();
            self::assertNotNull($handler);

            $exceptionHandler = set_exception_handler(function () {});
            restore_exception_handler();
            self::assertNotNull($exceptionHandler);
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function test_error_handler_throws_error_exception(): void
    {
        AppErrorHandler::register();
        $handler = set_error_handler(function () {
            return true;
        });
        restore_error_handler();

        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Test custom error');

        try {
            if (is_callable($handler)) {
                $handler(E_USER_WARNING, 'Test custom error', 'test.php', 10);
            }
        } finally {
            error_reporting($oldReporting);
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function test_exception_handler_invokes_handle_exception(): void
    {
        AppErrorHandler::register();
        $handler = set_exception_handler(function () {});
        restore_exception_handler();

        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];

        try {
            if (is_callable($handler)) {
                $handler(new RuntimeException('Unhandled exception test'));
            }

            $payload = $this->getDecodedPayload();
            self::assertEquals('Exception: Unhandled exception test', $payload['message']);
        } finally {
            unset($GLOBALS['mock_http_response_header']);
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function test_exception_handler_integration(): void
    {
        $reflection = new ReflectionClass(AppErrorHandler::class);
        $method = $reflection->getMethod('handleException');
        $exception = new RuntimeException('Test exception message');

        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        $method->invoke(null, $exception);

        $payload = $this->getDecodedPayload();

        self::assertEquals('CRITICAL', $payload['level']);
        self::assertEquals('Exception: Test exception message', $payload['message']);
        self::assertEquals(RuntimeException::class, $payload['context']['type'] ?? null);
        self::assertIsArray($payload['context']['trace'] ?? null);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_error_exception_level_mapping(): void
    {
        $reflection = new ReflectionClass(AppErrorHandler::class);
        $method = $reflection->getMethod('handleException');
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];

        // Warning
        $exception = new ErrorException('Warning occurred', 0, E_WARNING, 'file.php', 10);
        $method->invoke(null, $exception);
        $payload = $this->getDecodedPayload();
        self::assertEquals('WARNING', $payload['level']);

        // Notice
        $exception = new ErrorException('Notice occurred', 0, E_NOTICE, 'file.php', 10);
        $method->invoke(null, $exception);
        $payload = $this->getDecodedPayload();
        self::assertEquals('INFO', $payload['level']);

        // Error
        $exception = new ErrorException('Fatal error', 0, E_ERROR, 'file.php', 10);
        $method->invoke(null, $exception);
        $payload = $this->getDecodedPayload();
        self::assertEquals('ERROR', $payload['level']);

        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_handle_exception_permission_denied(): void
    {
        $reflection = new ReflectionClass(AppErrorHandler::class);
        $method = $reflection->getMethod('handleException');
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];

        $exception = new ErrorException('Permission denied on file', 0, E_ERROR, 'file.php', 1);
        $method->invoke(null, $exception);

        $payload = $this->getDecodedPayload();
        self::assertEquals('File System Permission Error', $payload['context']['type'] ?? null);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_mask_database_secrets_hides_credentials(): void
    {
        $reflection = new ReflectionClass(AppErrorHandler::class);
        $method = $reflection->getMethod('handleException');
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];

        $message = 'Connection failed: mysql://user:super_secret@localhost/db';
        $exception = new \PDOException($message);

        $method->invoke(null, $exception);

        $payload = $this->getDecodedPayload();

        self::assertStringNotContainsString('super_secret', $payload['message']);
        self::assertStringContainsString('mysql://******:******@', $payload['message']);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_mask_database_secrets_oracle(): void
    {
        $reflection = new ReflectionClass(AppErrorHandler::class);
        $method = $reflection->getMethod('maskDatabaseSecrets');
        $msg = "ORA-01017: invalid username/password; logon denied for user 'admin'";
        $masked = $method->invoke(null, $msg);
        self::assertIsString($masked);
        self::assertStringContainsString("for user '******'", $masked);
    }

    public function test_handle_shutdown_with_mock_error(): void
    {
        $mockError = [
            'type' => E_ERROR,
            'message' => 'Allowed memory size exhausted',
            'file' => 'test.php',
            'line' => 10,
        ];
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        AppErrorHandler::handleShutdown($mockError);

        $payload = $this->getDecodedPayload();
        self::assertEquals('CRITICAL', $payload['level']);
        self::assertStringContainsString('Allowed memory size', $payload['message']);
        self::assertEquals('Fatal Out Of Memory (RAM Exceeded)', $payload['context']['type'] ?? null);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_handle_shutdown_with_timeout_error(): void
    {
        $mockError = [
            'type' => E_ERROR,
            'message' => 'Maximum execution time of 30 seconds exceeded',
            'file' => 'test.php',
            'line' => 10,
        ];
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        AppErrorHandler::handleShutdown($mockError);

        $payload = $this->getDecodedPayload();
        self::assertEquals('CRITICAL', $payload['level']);
        self::assertStringContainsString('Maximum execution time', $payload['message']);
        self::assertEquals('Fatal Execution Timeout', $payload['context']['type'] ?? null);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_handle_shutdown_with_orphaned_buffer(): void
    {
        // Start an extra buffer. handleShutdown should close it.
        ob_start();
        echo 'Orphaned buffer content';

        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        AppErrorHandler::handleShutdown();

        // Do NOT call ob_end_clean() here, because AppErrorHandler::handleShutdown already cleaned it.
        // Calling it again would close PHPUnit's output buffer, resulting in a risky test warning.

        $payload = $this->getDecodedPayload();
        self::assertEquals('WARNING', $payload['level']);
        self::assertEquals('Orphaned Buffer', $payload['context']['type'] ?? null);
        $buffer = $payload['context']['captured_output_buffer'] ?? null;
        self::assertIsString($buffer);
        self::assertStringContainsString('Orphaned buffer content', $buffer);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_handle_shutdown_with_default_error(): void
    {
        $mockError = [
            'type' => E_ERROR,
            'message' => 'Call to undefined function some_function()',
            'file' => 'test.php',
            'line' => 10,
        ];
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        AppErrorHandler::handleShutdown($mockError);

        $payload = $this->getDecodedPayload();
        self::assertEquals('CRITICAL', $payload['level']);
        self::assertStringContainsString('Call to undefined function', $payload['message']);
        self::assertEquals('PHP Fatal Shutdown', $payload['context']['type'] ?? null);
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_error_handler_returns_false_when_error_reporting_disabled(): void
    {
        AppErrorHandler::register();
        $handler = set_error_handler(function () {
            return true;
        });
        restore_error_handler();

        $oldReporting = error_reporting();
        error_reporting(0);

        try {
            if (is_callable($handler)) {
                $result = $handler(E_USER_WARNING, 'Test ignored error', 'test.php', 10);
                self::assertFalse($result);
            }
        } finally {
            error_reporting($oldReporting);
            restore_error_handler();
            restore_exception_handler();
        }
    }
}
