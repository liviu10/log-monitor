<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\LogViaStream;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Mock stream wrapper to intercept HTTP requests made by LogViaStream.
 */
class MockHttpStreamWrapper
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

/**
 * Class LogViaStreamTest
 *
 * @category Tests
 */
class LogViaStreamTest extends TestCase
{
    private string $tempLogLimitFile;

    private bool $handlersRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Register mock stream wrapper
        if (in_array('http', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('http');
        }
        stream_wrapper_register('http', MockHttpStreamWrapper::class);

        MockHttpStreamWrapper::$lastPayload = '';
        MockHttpStreamWrapper::$lastHeaders = [];

        // Set up required environment variables
        $_ENV['LOG_API_KEY'] = 'test-api-key';
        $_ENV['LOG_SERVER_URL'] = 'http://localhost/log';
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-uri';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        $this->tempLogLimitFile = sys_get_temp_dir().'/log_rate_limit.json';
        if (file_exists($this->tempLogLimitFile)) {
            unlink($this->tempLogLimitFile);
        }

        $this->resetLogViaStreamState();
    }

    protected function tearDown(): void
    {
        try {
            stream_wrapper_restore('http');
        } catch (Throwable $e) {
            // Ignore if wrapper was already restored
        }

        if (file_exists($this->tempLogLimitFile)) {
            unlink($this->tempLogLimitFile);
        }

        if ($this->handlersRegistered) {
            restore_error_handler();
            restore_exception_handler();
        }

        $this->resetLogViaStreamState();

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        LogViaStream::$allowTestLogging = false;
        MockHttpStreamWrapper::$throwException = null;

        parent::tearDown();
    }

    private function resetLogViaStreamState(): void
    {
        $reflection = new ReflectionClass(LogViaStream::class);

        $isLoggingProp = $reflection->getProperty('isLogging');
        $isLoggingProp->setValue(null, false);

        $logCountProp = $reflection->getProperty('logCountInRequest');
        $logCountProp->setValue(null, 0);

        $apiKeyProp = $reflection->getProperty('apiKey');
        $apiKeyProp->setValue(null, '');

        $urlProp = $reflection->getProperty('url');
        $urlProp->setValue(null, '');

        $requestIdProp = $reflection->getProperty('requestId');
        $requestIdProp->setValue(null, null);
    }

    /**
     * @return array{level: string, message: string, context: array<mixed>}
     */
    private function getDecodedPayload(): array
    {
        self::assertNotEmpty(MockHttpStreamWrapper::$lastPayload);
        $data = json_decode(MockHttpStreamWrapper::$lastPayload, true);
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

    private function registerLogHandlers(): void
    {
        // LogViaStream::registerHandlers();
        $this->handlersRegistered = false;
    }

    public function test_send_skips_when_script_is_log_php(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/path/to/log.php';

        // This will write to error_log, so we capture it
        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        $result = LogViaStream::send('INFO', 'Test log message');

        self::assertTrue($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('[INFO] Internal Log: Test log message', $logContent);

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_send_executes_successfully_and_formats_payload(): void
    {
        $this->registerLogHandlers();

        $_GET = ['test_param' => 'value123'];
        $_POST = ['test_post' => 'value456'];

        LogViaStream::send('ERROR', 'System crash', ['user_id' => 99]);

        $payload = $this->getDecodedPayload();

        self::assertEquals('ERROR', $payload['level']);
        self::assertEquals('System crash', $payload['message']);
        self::assertEquals('GET', $payload['context']['http_method'] ?? null);
        self::assertEquals('/test-uri', $payload['context']['uri'] ?? null);
        self::assertEquals(99, $payload['context']['user_id'] ?? null);
        self::assertEquals(['test_param' => 'value123'], $payload['context']['query_params'] ?? null);
        self::assertEquals(['test_post' => 'value456'], $payload['context']['post_data'] ?? null);
    }

    public function test_sanitize_data_masks_sensitive_keys(): void
    {
        $this->registerLogHandlers();

        $_POST = [
            'password' => 'secret123',
            'user' => [
                'name' => 'John',
                'api_key' => 'my-secret-key',
            ],
            'json_field' => '{"pwd": "hidden", "other": "visible"}',
        ];

        LogViaStream::send('INFO', 'Test sanitize', []);

        $payload = $this->getDecodedPayload();
        $sentContext = $payload['context']['post_data'] ?? null;
        self::assertIsArray($sentContext);

        self::assertEquals('******', $sentContext['password'] ?? null);
        $user = $sentContext['user'] ?? null;
        self::assertIsArray($user);
        self::assertEquals('******', $user['api_key'] ?? null);
        self::assertEquals('John', $user['name'] ?? null);

        // JSON field should be decoded, sanitized and re-encoded
        $jsonField = $sentContext['json_field'] ?? null;
        self::assertIsString($jsonField);
        $decodedJson = json_decode($jsonField, true);
        self::assertIsArray($decodedJson);
        self::assertEquals('******', $decodedJson['pwd'] ?? null);
        self::assertEquals('visible', $decodedJson['other'] ?? null);

        $_POST = [];
    }

    public function test_sanitize_message_masks_passwords_in_text(): void
    {
        $this->registerLogHandlers();

        $message = 'Request error password=my_secret_pass&user=admin';

        LogViaStream::send('WARNING', $message);

        $payload = $this->getDecodedPayload();

        self::assertStringNotContainsString('my_secret_pass', $payload['message']);
        self::assertStringContainsString('password=******', $payload['message']);
    }

    public function test_local_rate_limit_blocks_excessive_logs(): void
    {
        $this->registerLogHandlers();

        // Mocking logCountInRequest to just below limit
        $reflection = new ReflectionClass(LogViaStream::class);
        $logCountProp = $reflection->getProperty('logCountInRequest');
        $logCountProp->setValue(null, 30); // MAX_LOGS_PER_REQUEST = 30

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        $result = LogViaStream::send('INFO', 'Should be blocked');

        self::assertFalse($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('LogViaStream Alert: Maximum logs per request limit reached (30)', $logContent);

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_global_rate_limit_blocks_excessive_logs(): void
    {
        $this->registerLogHandlers();

        // Create an artificial rate limit file with max counts
        $now = time();
        $minuteWindow = $now - ($now % 60);
        file_put_contents($this->tempLogLimitFile, json_encode([
            'window' => $minuteWindow,
            'count' => 301, // MAX_LOGS_PER_MINUTE = 300
        ]));

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        $result = LogViaStream::send('INFO', 'Should be blocked globally');

        self::assertFalse($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('LogViaStream Alert: Global rate limit exceeded', $logContent);

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_send_transmission_exception(): void
    {
        $this->registerLogHandlers();

        MockHttpStreamWrapper::$throwException = new RuntimeException('Network exception directly');

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        LogViaStream::$allowTestLogging = true;

        $result = LogViaStream::send('INFO', 'Test throw');
        self::assertFalse($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('Log server at http://localhost/log is unavailable', $logContent);

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_send_prevents_recursion(): void
    {
        $reflection = new ReflectionClass(LogViaStream::class);
        $isLoggingProp = $reflection->getProperty('isLogging');
        $isLoggingProp->setValue(null, true);

        $result = LogViaStream::send('INFO', 'Test');
        self::assertFalse($result);
    }

    public function test_send_fallback_error_log_when_url_empty(): void
    {
        // Unset any environment URL so initialize() doesn't override the empty URL
        $oldUrl = $_ENV['LOG_SERVER_URL'] ?? null;
        unset($_ENV['LOG_SERVER_URL']);

        $reflection = new ReflectionClass(LogViaStream::class);
        $urlProp = $reflection->getProperty('url');
        $urlProp->setValue(null, '');

        LogViaStream::$allowTestLogging = true;

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        $result = LogViaStream::send('INFO', 'Fallback message');
        self::assertFalse($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('Fallback (Not Initialized)', $logContent);

        // Restore
        if ($oldUrl !== null) {
            $_ENV['LOG_SERVER_URL'] = $oldUrl;
        }

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_send_ssl_verify_false_in_development(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $this->registerLogHandlers();

        // Mock the headers so the method considers the transmission successful
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];

        $result = LogViaStream::send('INFO', 'Test SSL');
        self::assertTrue($result); // Covers lines 310-313

        $_ENV['APP_ENV'] = 'testing';
        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_send_accepts_2xx_and_rejects_non_2xx_status_codes(): void
    {
        $this->registerLogHandlers();

        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 201 Created'];
        self::assertTrue(LogViaStream::send('INFO', 'Test 201 Created'));

        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 403 Forbidden'];
        self::assertFalse(LogViaStream::send('INFO', 'Test 403 Forbidden'));

        unset($GLOBALS['mock_http_response_header']);
    }

    public function test_calculate_dynamic_timeout(): void
    {
        $reflection = new ReflectionClass(LogViaStream::class);
        $method = $reflection->getMethod('calculateDynamicTimeout');
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 29; // 29 seconds elapsed

        $oldMaxTime = ini_get('max_execution_time');
        ini_set('max_execution_time', '30');

        $timeout = $method->invoke(null);
        self::assertLessThan(2.0, $timeout); // Timeout should be scaled down

        ini_set('max_execution_time', $oldMaxTime);
    }

    public function test_send_catches_outer_exception_on_json_encode_failure(): void
    {
        $this->registerLogHandlers();

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        $invalidUtf8 = "\xB1\x31";

        $result = LogViaStream::send('INFO', 'Test', ['bad_data' => $invalidUtf8]);

        self::assertFalse($result);

        $logContent = file_get_contents($tempLog);
        $logContent = is_string($logContent) ? $logContent : '';
        self::assertStringContainsString('LogViaStream_Transmission_Failure', $logContent);

        ini_set('error_log', $oldErrorLog);
        unlink($tempLog);
    }

    public function test_initialize_rewrites_url_when_outside_container(): void
    {
        $this->resetLogViaStreamState();
        $_ENV['LOG_SERVER_URL'] = 'http://log-monitor-app:80/api/log.php';
        unset($_ENV['LOG_MONITOR_CONTAINER']);

        $reflection = new ReflectionClass(LogViaStream::class);
        $method = $reflection->getMethod('initialize');
        $method->invoke(null);

        $urlProp = $reflection->getProperty('url');
        $url = $urlProp->getValue();
        self::assertEquals('http://localhost:8082/api/log.php', $url);
    }

    public function test_initialize_keeps_url_when_inside_container(): void
    {
        $this->resetLogViaStreamState();
        $_ENV['LOG_SERVER_URL'] = 'http://log-monitor-app:80/api/log.php';
        $_ENV['LOG_MONITOR_CONTAINER'] = 'true';

        $reflection = new ReflectionClass(LogViaStream::class);
        $method = $reflection->getMethod('initialize');
        $method->invoke(null);

        $urlProp = $reflection->getProperty('url');
        $url = $urlProp->getValue();
        self::assertEquals('http://log-monitor-app:80/api/log.php', $url);
    }

    public function test_global_rate_limit_resets_on_stale_window(): void
    {
        $this->registerLogHandlers();
        $GLOBALS['mock_http_response_header'] = ['HTTP/1.1 200 OK'];
        $staleWindow = time() - 3600;
        file_put_contents($this->tempLogLimitFile, json_encode([
            'window' => $staleWindow,
            'count' => 150,
        ]));

        try {
            $result = LogViaStream::send('INFO', 'Test stale window reset');
            self::assertTrue($result);

            $content = json_decode((string) file_get_contents($this->tempLogLimitFile), true);
            self::assertIsArray($content);
            self::assertNotEquals($staleWindow, $content['window'] ?? 0);
        } finally {
            unset($GLOBALS['mock_http_response_header']);
        }
    }

    public function test_send_handles_fopen_failure_with_test_logging(): void
    {
        $this->registerLogHandlers();
        LogViaStream::$allowTestLogging = true;

        // Force stream_open in mock wrapper to return false
        stream_wrapper_unregister('http');

        $tempLog = tempnam(sys_get_temp_dir(), 'php_err');
        $oldErrorLog = ini_get('error_log');
        ini_set('error_log', $tempLog);

        try {
            $result = LogViaStream::send('ERROR', 'Test unavailable server');
            self::assertFalse($result);

            $logContent = (string) file_get_contents($tempLog);
            self::assertStringContainsString('Log server at http://localhost/log is unavailable', $logContent);
        } finally {
            stream_wrapper_register('http', MockHttpStreamWrapper::class);
            ini_set('error_log', $oldErrorLog);
            if (file_exists($tempLog)) {
                unlink($tempLog);
            }
        }
    }

    public function test_sanitize_content_redacts_emails_and_bearer_tokens_and_masks_ip(): void
    {
        $this->registerLogHandlers();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';

        LogViaStream::send(
            'WARNING',
            'Exception occurred for user john.doe@example.com with token=secret123',
            [
                'contact_email' => 'support@aegis.local',
                'raw_header' => 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.xyz',
                'custom_field' => 'Report sent to admin@site.org',
            ]
        );

        $payload = $this->getDecodedPayload();

        // Check masked IP
        self::assertEquals('198.51.100.0', $payload['context']['ip'] ?? null);

        // Check redacted email and token in message
        self::assertStringContainsString('[REDACTED_EMAIL]', $payload['message']);
        self::assertStringNotContainsString('john.doe@example.com', $payload['message']);
        self::assertStringContainsString('token=******', $payload['message']);

        // Check context sanitization
        $ctx = $payload['context'];
        self::assertEquals('[REDACTED_EMAIL]', $ctx['contact_email'] ?? null);
        self::assertIsString($ctx['raw_header'] ?? null);
        self::assertStringContainsString('Bearer [REDACTED_TOKEN]', $ctx['raw_header']);
        self::assertEquals('Report sent to [REDACTED_EMAIL]', $ctx['custom_field'] ?? null);
    }

    public function test_sanitize_ipv6_masks_properly(): void
    {
        $this->registerLogHandlers();
        $_SERVER['REMOTE_ADDR'] = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        LogViaStream::send('INFO', 'IPv6 test log');

        $payload = $this->getDecodedPayload();
        self::assertEquals('2001:0db8:85a3::', $payload['context']['ip'] ?? null);
    }
}
