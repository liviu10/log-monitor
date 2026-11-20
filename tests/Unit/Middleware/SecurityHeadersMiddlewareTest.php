<?php

declare(strict_types=1);

namespace App\Middleware {
    function headers_sent(): bool
    {
        return (bool) ($GLOBALS['MOCK_HEADERS_SENT'] ?? false);
    }

    function header_remove(string $name): void
    {
        if (! is_array($GLOBALS['MOCK_HEADERS_REMOVED'] ?? null)) {
            $GLOBALS['MOCK_HEADERS_REMOVED'] = [];
        }
        $GLOBALS['MOCK_HEADERS_REMOVED'][] = $name;
    }

    function header(string $header): void
    {
        if (! is_array($GLOBALS['MOCK_HEADERS_SET'] ?? null)) {
            $GLOBALS['MOCK_HEADERS_SET'] = [];
        }
        $GLOBALS['MOCK_HEADERS_SET'][] = $header;
    }
}

namespace Tests\Unit\Middleware {
    use App\Middleware\SecurityHeadersMiddleware;
    use PHPUnit\Framework\TestCase;

    /**
     * Class SecurityHeadersMiddlewareTest
     *
     * @category Tests
     */
    class SecurityHeadersMiddlewareTest extends TestCase
    {
        private SecurityHeadersMiddleware $middleware;

        protected function setUp(): void
        {
            parent::setUp();
            $this->middleware = new SecurityHeadersMiddleware;

            $GLOBALS['MOCK_HEADERS_SENT'] = false;
            $GLOBALS['MOCK_HEADERS_REMOVED'] = [];
            $GLOBALS['MOCK_HEADERS_SET'] = [];

            // Backup SERVER array
            $server = $_SERVER;
            /** @var array<string, mixed> $server */
            $this->backupServer = $server;
        }

        /** @var array<string, mixed> */
        private array $backupServer;

        protected function tearDown(): void
        {
            $GLOBALS['MOCK_HEADERS_SENT'] = false;
            $GLOBALS['MOCK_HEADERS_REMOVED'] = [];
            $GLOBALS['MOCK_HEADERS_SET'] = [];

            $_SERVER = $this->backupServer;
            parent::tearDown();
        }

        public function test_handle_does_nothing_if_headers_already_sent(): void
        {
            $GLOBALS['MOCK_HEADERS_SENT'] = true;

            $called = false;
            $next = function ($route) use (&$called) {
                $called = true;

                return 'passed';
            };

            $result = $this->middleware->handle(['path' => '/test'], $next);

            self::assertTrue($called);
            self::assertEquals('passed', $result);
            self::assertEmpty($GLOBALS['MOCK_HEADERS_REMOVED']);
            self::assertEmpty($GLOBALS['MOCK_HEADERS_SET']);
        }

        public function test_handle_removes_unwanted_headers_and_sets_security_headers_on_http(): void
        {
            $_SERVER['HTTPS'] = 'off';
            $_SERVER['HTTP_HOST'] = 'example.com';

            $called = false;
            $next = function ($route) use (&$called) {
                $called = true;

                return 'passed';
            };

            $result = $this->middleware->handle(['path' => '/test'], $next);

            self::assertTrue($called);
            self::assertEquals('passed', $result);

            // Check header removal
            $removed = is_iterable($GLOBALS['MOCK_HEADERS_REMOVED']) ? $GLOBALS['MOCK_HEADERS_REMOVED'] : [];
            self::assertContains('X-Powered-By', $removed);
            self::assertContains('Server', $removed);

            // Check standard headers
            $set = is_array($GLOBALS['MOCK_HEADERS_SET']) ? $GLOBALS['MOCK_HEADERS_SET'] : [];
            $setStr = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $set);
            $headersStr = implode('|', $setStr);
            self::assertStringContainsString('X-Frame-Options: DENY', $headersStr);
            self::assertStringContainsString('X-Content-Type-Options: nosniff', $headersStr);
            self::assertStringContainsString('Referrer-Policy: strict-origin-when-cross-origin', $headersStr);
            self::assertStringContainsString('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', $headersStr);
            self::assertStringContainsString('Permissions-Policy:', $headersStr);
            self::assertStringContainsString('Content-Security-Policy:', $headersStr);

            // Since it's not HTTPS or localhost, COOP shouldn't be set
            self::assertStringNotContainsString('Cross-Origin-Opener-Policy', $headersStr);
        }

        public function test_handle_sets_coop_header_on_https(): void
        {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['HTTP_HOST'] = 'example.com';

            $next = function ($route) {
                return 'passed';
            };
            $this->middleware->handle(['path' => '/test'], $next);

            $set = is_array($GLOBALS['MOCK_HEADERS_SET']) ? $GLOBALS['MOCK_HEADERS_SET'] : [];
            $setStr = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $set);
            $headersStr = implode('|', $setStr);
            self::assertStringContainsString('Cross-Origin-Opener-Policy: same-origin', $headersStr);
        }

        public function test_handle_sets_coop_header_on_localhost(): void
        {
            $_SERVER['HTTPS'] = 'off';
            $_SERVER['HTTP_HOST'] = 'localhost:8080';

            $next = function ($route) {
                return 'passed';
            };
            $this->middleware->handle(['path' => '/test'], $next);

            $set = is_array($GLOBALS['MOCK_HEADERS_SET']) ? $GLOBALS['MOCK_HEADERS_SET'] : [];
            $setStr = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $set);
            $headersStr = implode('|', $setStr);
            self::assertStringContainsString('Cross-Origin-Opener-Policy: same-origin', $headersStr);
        }
    }
}
