<?php

declare(strict_types=1);

namespace App\Core {
    // Globals for mocking cURL functions
    $GLOBALS['MOCK_CURL_OPTS'] = [];
    $GLOBALS['MOCK_CURL_EXEC_RESULT'] = '{"status":"ok"}';
    $GLOBALS['MOCK_CURL_EXEC_CALLED'] = false;
    $GLOBALS['MOCK_CURL_INFO_CODE'] = 200;
    $GLOBALS['MOCK_CURL_ERROR_MSG'] = '';
    $GLOBALS['MOCK_CURL_INIT_FAIL'] = false;
    $GLOBALS['MOCK_CURL_CLOSED'] = false;

    function curl_init(): mixed
    {
        if ($GLOBALS['MOCK_CURL_INIT_FAIL'] === true) {
            return false;
        }

        return 'mock_resource';
    }

    function curl_setopt(mixed $ch, int $option, mixed $value): bool
    {
        if (! is_array($GLOBALS['MOCK_CURL_OPTS'])) {
            $GLOBALS['MOCK_CURL_OPTS'] = [];
        }
        $GLOBALS['MOCK_CURL_OPTS'][$option] = $value;

        return true;
    }

    function curl_exec(mixed $ch): string|bool
    {
        $GLOBALS['MOCK_CURL_EXEC_CALLED'] = true;
        $res = $GLOBALS['MOCK_CURL_EXEC_RESULT'] ?? false;

        return is_string($res) || is_bool($res) ? $res : false;
    }

    function curl_getinfo(mixed $ch, ?int $option = null): mixed
    {
        if ($option === \CURLINFO_HTTP_CODE) {
            return $GLOBALS['MOCK_CURL_INFO_CODE'];
        }

        return null;
    }

    function curl_error(mixed $ch): string
    {
        $msg = $GLOBALS['MOCK_CURL_ERROR_MSG'] ?? '';

        return is_string($msg) ? $msg : '';
    }

    function curl_close(mixed $ch): void
    {
        $GLOBALS['MOCK_CURL_CLOSED'] = true;
    }
}

namespace Tests\Unit\Core {
    use App\Core\HttpRequest;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    // Fallback for translation function if it's not loaded
    if (! function_exists('__')) {
        /**
         * @param  array<string, string|int|float>  $replace
         */
        function __(string $key, array $replace = []): string
        {
            $keys = array_map(function ($k) {
                return ':'.$k;
            }, array_keys($replace));
            $values = array_map('strval', array_values($replace));

            return str_replace($keys, $values, $key);
        }
    }

    /**
     * Class HttpRequestTest
     *
     * @category Tests
     */
    class HttpRequestTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['MOCK_CURL_OPTS'] = [];
            $GLOBALS['MOCK_CURL_EXEC_RESULT'] = '{"status":"ok"}';
            $GLOBALS['MOCK_CURL_EXEC_CALLED'] = false;
            $GLOBALS['MOCK_CURL_INFO_CODE'] = 200;
            $GLOBALS['MOCK_CURL_ERROR_MSG'] = '';
            $GLOBALS['MOCK_CURL_INIT_FAIL'] = false;
            $GLOBALS['MOCK_CURL_CLOSED'] = false;
        }

        public function test_get_appends_query_string(): void
        {
            $result = HttpRequest::get('https://example.com/api', ['payload' => ['id' => 1]]);
            self::assertEquals('{"status":"ok"}', $result);
            self::assertTrue($GLOBALS['MOCK_CURL_EXEC_CALLED']);

            $opts = is_array($GLOBALS['MOCK_CURL_OPTS'] ?? null) ? $GLOBALS['MOCK_CURL_OPTS'] : [];
            $url = $opts[\CURLOPT_URL] ?? '';
            self::assertEquals('https://example.com/api?id=1', $url);

            $method = $opts[\CURLOPT_CUSTOMREQUEST] ?? '';
            self::assertEquals('GET', $method);
        }

        public function test_post_sends_payload_as_form_data(): void
        {
            $result = HttpRequest::post('https://example.com/api', ['payload' => ['name' => 'John']]);
            self::assertEquals('{"status":"ok"}', $result);

            $opts = is_array($GLOBALS['MOCK_CURL_OPTS'] ?? null) ? $GLOBALS['MOCK_CURL_OPTS'] : [];
            $method = $opts[\CURLOPT_CUSTOMREQUEST] ?? '';
            self::assertEquals('POST', $method);

            $postFields = $opts[\CURLOPT_POSTFIELDS] ?? '';
            self::assertEquals('name=John', $postFields);
        }

        public function test_post_sends_payload_as_json_if_header_set(): void
        {
            $result = HttpRequest::post('https://example.com/api', [
                'payload' => ['name' => 'John'],
                'headers' => ['Content-Type' => 'application/json'],
            ]);
            self::assertEquals('{"status":"ok"}', $result);

            $opts = is_array($GLOBALS['MOCK_CURL_OPTS'] ?? null) ? $GLOBALS['MOCK_CURL_OPTS'] : [];
            $postFields = $opts[\CURLOPT_POSTFIELDS] ?? '';
            self::assertEquals('{"name":"John"}', $postFields);

            $headers = $opts[\CURLOPT_HTTPHEADER] ?? [];
            $headersArray = is_iterable($headers) ? $headers : [];
            self::assertContains('Content-Type: application/json', $headersArray);
        }

        public function test_put_method_works(): void
        {
            $result = HttpRequest::put('https://example.com/api', []);
            self::assertEquals('{"status":"ok"}', $result);
            $opts = is_array($GLOBALS['MOCK_CURL_OPTS'] ?? null) ? $GLOBALS['MOCK_CURL_OPTS'] : [];
            self::assertEquals('PUT', $opts[\CURLOPT_CUSTOMREQUEST] ?? '');
        }

        public function test_delete_method_works(): void
        {
            $result = HttpRequest::delete('https://example.com/api', []);
            self::assertEquals('{"status":"ok"}', $result);
            $opts = is_array($GLOBALS['MOCK_CURL_OPTS'] ?? null) ? $GLOBALS['MOCK_CURL_OPTS'] : [];
            self::assertEquals('DELETE', $opts[\CURLOPT_CUSTOMREQUEST] ?? '');
        }

        public function test_throws_exception_if_curl_init_fails(): void
        {
            $GLOBALS['MOCK_CURL_INIT_FAIL'] = true;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Failed to initialize cURL.'); // It gets wrapped in the try/catch into a generic message.

            HttpRequest::get('https://example.com');
        }

        public function test_throws_exception_if_curl_exec_returns_false(): void
        {
            $GLOBALS['MOCK_CURL_EXEC_RESULT'] = false;
            $GLOBALS['MOCK_CURL_ERROR_MSG'] = 'Connection timed out';

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('cURL Error: Connection timed out');

            HttpRequest::get('https://example.com');
        }

        public function test_throws_exception_if_status_code_is_404(): void
        {
            $GLOBALS['MOCK_CURL_INFO_CODE'] = 404;
            $GLOBALS['MOCK_CURL_EXEC_RESULT'] = 'Not Found';

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Received status code 404');

            HttpRequest::get('https://example.com');
        }

        public function test_throws_exception_if_endpoint_is_empty(): void
        {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Endpoint cannot be empty.');

            HttpRequest::get('');
        }

        public function test_throws_exception_if_method_is_empty(): void
        {
            $reflection = new \ReflectionClass(HttpRequest::class);
            $method = $reflection->getMethod('execute');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Method cannot be empty.');

            $method->invoke(null, '', 'https://example.com', []);
        }
    }
}
