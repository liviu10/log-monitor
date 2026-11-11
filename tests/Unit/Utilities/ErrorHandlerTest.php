<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\ErrorHandler;
use ErrorException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utilities\Support\WebServer;
use Throwable;

/**
 * Conversia warning-urilor se testeaza in proces; exceptiile neprinse si erorile fatale opresc scriptul,
 * deci se testeaza in procese PHP separate (CLI) si prin serverul integrat (web).
 */
final class ErrorHandlerTest extends TestCase
{
    private int $errorReporting;

    protected function setUp(): void
    {
        // PHPUnit ruleaza testele cu error_reporting() redus; handler-ul il respecta, deci aici se cere E_ALL.
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        if (ErrorHandler::isRegistered()) {
            ErrorHandler::unregister();
        }

        error_reporting($this->errorReporting);
    }

    // In proces

    public function test_warning_becomes_error_exception(): void
    {
        ErrorHandler::register();

        try {
            trigger_error('avertisment de test', E_USER_WARNING);
            $this->fail('Expected exception.');
        } catch (ErrorException $e) {
            $this->assertSame('avertisment de test', $e->getMessage());
            $this->assertSame(E_USER_WARNING, $e->getSeverity());
            $this->assertSame(__FILE__, $e->getFile());
        }
    }

    public function test_native_warning_becomes_error_exception(): void
    {
        ErrorHandler::register();

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "lipsa"');

        $value = $this->emptyMap()['lipsa'];
        $this->fail('Expected exception, got '.$value);
    }

    public function test_suppressed_warning_is_ignored(): void
    {
        ErrorHandler::register();

        @trigger_error('suprimat', E_USER_WARNING);
        $this->addToAssertionCount(1);
    }

    public function test_error_reporting_is_respected(): void
    {
        $previous = error_reporting(E_ALL & ~E_USER_NOTICE);

        try {
            $this->assertFalse(ErrorHandler::handleError(E_USER_NOTICE, 'notice dezactivat'));
        } finally {
            error_reporting($previous);
        }

        $this->expectException(ErrorException::class);
        ErrorHandler::handleError(E_USER_NOTICE, 'notice activ');
    }

    public function test_deprecations_are_logged_not_thrown(): void
    {
        $logged = [];
        ErrorHandler::register(logger: static function (Throwable $e) use (&$logged): void {
            $logged[] = $e;
        });

        trigger_error('functie depreciata', E_USER_DEPRECATED);

        $this->assertCount(1, $logged);
        $this->assertInstanceOf(ErrorException::class, $logged[0]);
        $this->assertSame('functie depreciata', $logged[0]->getMessage());
        $this->assertSame(E_USER_DEPRECATED, $logged[0]->getSeverity());
    }

    public function test_deprecation_without_logger_falls_back_to_php(): void
    {
        $this->assertFalse(ErrorHandler::handleError(E_USER_DEPRECATED, 'x'));
    }

    public function test_unregister_restores_previous_handler(): void
    {
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            ErrorHandler::register();
            ErrorHandler::unregister();
            trigger_error('dupa unregister', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['dupa unregister'], $seen);
        $this->assertFalse(ErrorHandler::isRegistered());
    }

    public function test_register_twice(): void
    {
        ErrorHandler::register();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ErrorHandler::register(): the error handler is already registered.');

        ErrorHandler::register();
    }

    public function test_unregister_without_register(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ErrorHandler::unregister(): the error handler is not registered.');

        ErrorHandler::unregister();
    }

    // CLI, in proces separat

    public function test_uncaught_exception_in_cli(): void
    {
        [$code, $stdout, $stderr] = $this->runPhp(<<<'PHP'
            App\Utilities\ErrorHandler::register(logger: function (Throwable $e) { echo 'LOGGED: ', $e->getMessage(), "\n"; });
            throw new RuntimeException('eroare neprinsa', 0, new LogicException('cauza'));
            PHP);

        $this->assertSame(255, $code);
        $this->assertSame("LOGGED: eroare neprinsa\n", $stdout);
        $this->assertStringStartsWith('RuntimeException: eroare neprinsa in ', $stderr);
        $this->assertStringContainsString('Caused by: LogicException: cauza', $stderr);
    }

    public function test_fatal_out_of_memory_is_handled(): void
    {
        [$code, $stdout] = $this->runPhp(<<<'PHP'
            ini_set('memory_limit', '32M');
            App\Utilities\ErrorHandler::register(logger: function (Throwable $e) { echo 'LOGGED: ', $e->getMessage(), "\n"; });
            $data = [];
            while (true) { $data[] = str_repeat('x', 1024 * 1024); }
            PHP);

        $this->assertSame(255, $code);
        $this->assertStringStartsWith('LOGGED: Allowed memory size of 33554432 bytes exhausted', $stdout);
    }

    public function test_logger_failure_does_not_hide_the_error(): void
    {
        [$code, , $stderr] = $this->runPhp(<<<'PHP'
            App\Utilities\ErrorHandler::register(logger: function (Throwable $e) { throw new RuntimeException('log indisponibil'); });
            throw new DomainException('eroare originala');
            PHP);

        $this->assertSame(255, $code);
        $this->assertStringContainsString('the logger failed (RuntimeException: log indisponibil', $stderr);
        $this->assertStringContainsString('DomainException: eroare originala in ', $stderr);
    }

    // Web, prin serverul integrat

    public function test_production_response_hides_details_and_partial_output(): void
    {
        $response = WebServer::request('GET', '/error/production');

        $this->assertSame(500, $response['status']);
        $this->assertSame('Internal Server Error', $response['body']);
        $this->assertSame(['text/plain; charset=UTF-8'], $response['headers']['content-type'] ?? null);
    }

    public function test_debug_response_shows_details(): void
    {
        $response = WebServer::request('GET', '/error/debug');

        $this->assertSame(500, $response['status']);
        $this->assertStringStartsWith("Internal Server Error\n\nRuntimeException: detaliu intern in ", $response['body']);
        $this->assertStringContainsString('web-router.php', $response['body']);
    }

    public function test_json_response(): void
    {
        $production = WebServer::request('GET', '/error/production', ['Accept' => 'application/json']);
        $this->assertSame('{"error":"Internal Server Error"}', $production['body']);

        $debug = json_decode(WebServer::request('GET', '/error/debug', ['Accept' => 'application/json'])['body'], true);
        $this->assertIsArray($debug);
        $this->assertIsArray($debug['exception'] ?? null);
        $this->assertSame('detaliu intern', $debug['exception']['message'] ?? null);
    }

    public function test_warning_in_web_becomes_500(): void
    {
        $response = WebServer::request('GET', '/error/warning');

        $this->assertSame(500, $response['status']);
        $this->assertSame('Internal Server Error', $response['body']);
    }

    public function test_custom_renderer(): void
    {
        $response = WebServer::request('GET', '/error/renderer');

        $this->assertSame(500, $response['status']);
        $this->assertSame('pagina proprie: RuntimeException, debug=no', $response['body']);
    }

    public function test_failing_renderer_falls_back_to_default(): void
    {
        $response = WebServer::request('GET', '/error/renderer-fails');

        $this->assertSame(500, $response['status']);
        $this->assertSame('Internal Server Error', $response['body']);
    }

    public function test_fatal_error_in_web(): void
    {
        $response = WebServer::request('GET', '/error/fatal');

        $this->assertSame(500, $response['status']);
        $this->assertSame('Internal Server Error', $response['body']);
    }

    /**
     * @return array<string, int>
     */
    private function emptyMap(): array
    {
        return [];
    }

    /**
     * Ruleaza cod PHP intr-un proces separat, cu ErrorHandler.php incarcat.
     * Codul merge intr-un fisier: cu php -r, PHP nu apeleaza set_exception_handler().
     *
     * @return array{int, string, string} Codul de iesire, STDOUT, STDERR.
     */
    private function runPhp(string $code): array
    {
        $file = sys_get_temp_dir().'/error-handler-test-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($file, "<?php\nrequire ".var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).";\n".$code."\n");

        try {
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $stdout, $stderr];
        } finally {
            unlink($file);
        }
    }
}
