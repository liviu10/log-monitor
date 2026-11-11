<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\HttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Testele pornesc serverul integrat PHP (php -S) pe un port liber, cu tests/fixtures/http-router.php.
 */
final class HttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $base;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('Cannot find a free port.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];

        $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/fixtures/http-router.php'], $descriptors, $pipes);
        if (! is_resource($process)) {
            self::fail('Cannot start the PHP built-in server.');
        }
        self::$server = $process;
        self::$base = "http://127.0.0.1:{$port}";

        // Asteapta pana serverul accepta conexiuni.
        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail('The PHP built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     * @return array<string, mixed>
     */
    private function echoed(array $response): array
    {
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_get_with_query_and_headers(): void
    {
        $response = HttpClient::get(self::$base.'/echo?a=1', ['query' => ['b' => 'x y', 'c' => [1, 2]], 'headers' => ['X-Test' => 'valoare']]);
        $echo = $this->echoed($response);

        $this->assertSame(200, $response['status']);
        $this->assertSame('GET', $echo['method']);
        $this->assertSame(['a' => '1', 'b' => 'x y', 'c' => ['1', '2']], $echo['query']);
        $this->assertIsArray($echo['headers']);
        $this->assertSame('valoare', $echo['headers']['x-test']);
    }

    public function test_response_headers_are_lowercase_and_combined(): void
    {
        $response = HttpClient::get(self::$base.'/echo');

        $this->assertSame('application/json', $response['headers']['content-type']);
        $this->assertSame('a, b', $response['headers']['x-multi']);
    }

    public function test_post_json(): void
    {
        $echo = $this->echoed(HttpClient::post(self::$base.'/echo', ['json' => ['oras' => "Ia\u{0219}i", 'suma' => 150.5]]));

        $this->assertSame('POST', $echo['method']);
        $this->assertSame("{\"oras\":\"Ia\u{0219}i\",\"suma\":150.5}", $echo['body']);
        $this->assertIsArray($echo['headers']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
    }

    public function test_post_form_and_raw_body(): void
    {
        $form = $this->echoed(HttpClient::post(self::$base.'/echo', ['form' => ['a' => '1', 'b' => 'x&y']]));
        $this->assertSame('a=1&b=x%26y', $form['body']);

        $raw = $this->echoed(HttpClient::put(self::$base.'/echo', ['body' => '<xml/>', 'headers' => ['Content-Type' => 'application/xml']]));
        $this->assertSame('PUT', $raw['method']);
        $this->assertSame('<xml/>', $raw['body']);
    }

    public function test_patch_and_delete(): void
    {
        $this->assertSame('PATCH', $this->echoed(HttpClient::patch(self::$base.'/echo'))['method']);
        $this->assertSame('DELETE', $this->echoed(HttpClient::delete(self::$base.'/echo'))['method']);
    }

    public function test_bearer_and_basic_auth(): void
    {
        $bearer = $this->echoed(HttpClient::get(self::$base.'/echo', ['bearer' => 'abc']));
        $this->assertIsArray($bearer['headers']);
        $this->assertSame('Bearer abc', $bearer['headers']['authorization']);

        $basic = $this->echoed(HttpClient::get(self::$base.'/echo', ['auth' => ['ion', 'parola']]));
        $this->assertIsArray($basic['headers']);
        $this->assertSame('Basic '.base64_encode('ion:parola'), $basic['headers']['authorization']);
    }

    public function test_json_helper(): void
    {
        $data = HttpClient::json('GET', self::$base.'/echo', ['query' => ['x' => '1']]);

        $this->assertIsArray($data);
        $this->assertSame(['x' => '1'], $data['query']);
        $this->assertIsArray($data['headers']);
        $this->assertSame('application/json', $data['headers']['accept']);
    }

    public function test_json_helper_rejects_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned invalid JSON');

        HttpClient::json('GET', self::$base.'/invalid-json');
    }

    public function test_non_2xx_throws_without_body_or_query_in_message(): void
    {
        try {
            HttpClient::get(self::$base.'/status/500?token=secret');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $bytes = strlen('{"error":"date personale in raspuns: 1800101123456"}');
            $this->assertSame('HttpClient: GET '.self::$base."/status/500?... returned HTTP 500 (application/json, {$bytes} bytes).", $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('1800101123456', $e->getMessage());
        }
    }

    public function test_throw_on_error_false_returns_response(): void
    {
        $response = HttpClient::get(self::$base.'/status/422', ['throwOnError' => false]);

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('error', $response['body']);
    }

    public function test_redirects_are_not_followed_by_default(): void
    {
        $response = HttpClient::get(self::$base.'/redirect', ['throwOnError' => false]);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/echo?redirected=1', $response['headers']['location']);
    }

    public function test_follow_redirects(): void
    {
        $echo = $this->echoed(HttpClient::get(self::$base.'/redirect', ['followRedirects' => 3]));

        $this->assertSame(['redirected' => '1'], $echo['query']);
    }

    public function test_redirect_to_file_protocol_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed');

        HttpClient::get(self::$base.'/redirect-file', ['followRedirects' => 3]);
    }

    public function test_timeout(): void
    {
        $start = microtime(true);

        try {
            HttpClient::get(self::$base.'/slow', ['timeout' => 0.5]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('curl error 28', $e->getMessage());
        }

        $this->assertLessThan(2.5, microtime(true) - $start);
    }

    public function test_connection_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HttpClient: GET http://127.0.0.1:1/ failed');

        HttpClient::get('http://127.0.0.1:1/', ['connectTimeout' => 1]);
    }

    public function test_credentials_in_url_are_hidden_in_messages(): void
    {
        try {
            HttpClient::get('http://ion:parola@127.0.0.1:1/x', ['connectTimeout' => 1]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('parola', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function invalidRequestProvider(): iterable
    {
        yield 'metoda' => ['TRACE', 'http://x.ro', [], "unsupported HTTP method 'TRACE'"];
        yield 'url relativ' => ['GET', '/api', [], 'invalid URL'];
        yield 'protocol file' => ['GET', 'file:///etc/passwd', [], 'invalid URL'];
        yield 'optiune necunoscuta' => ['GET', 'http://x.ro', ['timout' => 5], "unknown option 'timout'"];
        yield 'tip gresit' => ['GET', 'http://x.ro', ['timeout' => '5'], "option 'timeout' must be of type number"];
        yield 'timeout zero' => ['GET', 'http://x.ro', ['timeout' => 0], "option 'timeout' must be of type number greater than 0"];
        yield 'doua corpuri' => ['POST', 'http://x.ro', ['json' => [], 'form' => []], 'cannot be combined'];
        yield 'auth gresit' => ['GET', 'http://x.ro', ['auth' => ['doar-user']], "option 'auth' must be [username, password]"];
        yield 'auth si bearer' => ['GET', 'http://x.ro', ['auth' => ['a', 'b'], 'bearer' => 'c'], "'auth' and 'bearer' cannot be combined"];
        yield 'header injection' => ['GET', 'http://x.ro', ['headers' => ['X' => "a\r\nHost: evil"]], 'without new lines'];
        yield 'ca lipsa' => ['GET', 'http://x.ro', ['caInfo' => '/nu/exista.pem'], 'CA bundle'];
        yield 'json invalid' => ['POST', 'http://x.ro', ['json' => NAN], 'option json cannot be encoded'];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidRequestProvider')]
    public function test_invalid_requests(string $method, string $url, array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        HttpClient::request($method, $url, $options);
    }
}
