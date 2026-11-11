<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use ErrorException;
use LogicException;
use Throwable;

/**
 * ErrorHandler Class
 *
 * Application safety net, explicitly activated:
 *   - PHP warnings and notices are converted into ErrorException (no longer pass silently);
 *   - deprecations are only forwarded to the logger (a PHP update won't break pages);
 *   - uncaught exceptions are forwarded to the logger and return a 500 response: generic in production,
 *     detailed only in debug mode;
 *   - fatal errors (out of memory, execution time exceeded, syntax error in an include) are caught
 *     at shutdown and handled identically, with reserved memory freed beforehand.
 *
 * Rules:
 *   - including this file changes nothing; register() enables handling, unregister() restores previous handlers;
 *   - error_reporting() and the @ operator are respected;
 *   - default response is plain text or JSON (based on Accept header), without HTML or data leaks in production;
 *     custom response rendering can be provided via $renderer;
 *   - in CLI, details are sent to STDERR with exit code 255;
 *   - in production, display_errors must be Off (server configuration is not modified).
 *
 * Usage:
 *   require_once 'ErrorHandler.php';
 *   use App\Utilities\ErrorHandler;
 *
 *   ErrorHandler::register(
 *       debug: $config['debug'],
 *       logger: fn (Throwable $e) => $log->error('Uncaught exception', ['exception' => $e]),
 *       renderer: fn (Throwable $e, bool $debug) => print $view->render('errors/500', ['debug' => $debug]),
 *   );
 *
 *   // inside a front controller with its own try/catch:
 *   catch (Throwable $e) { ErrorHandler::handleException($e); }
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class ErrorHandler
{
    /** @var int Errors that set_error_handler() cannot catch; they only reach shutdown. */
    private const int FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private const int DEPRECATIONS = E_DEPRECATED | E_USER_DEPRECATED;

    /** @var int Reserved memory buffer for handling an "Allowed memory size exhausted" error. */
    private const int RESERVE_BYTES = 65_536;

    private static bool $registered = false;

    private static bool $shutdownRegistered = false;

    private static bool $debug = false;

    /** @var (Closure(Throwable): void)|null */
    private static ?Closure $logger = null;

    /** @var (Closure(Throwable, bool): void)|null */
    private static ?Closure $renderer = null;

    private static ?string $reserve = null;

    private static bool $handling = false;

    private function __construct() {}

    /**
     * Registers error and exception handlers.
     *
     * @param  bool  $debug  true = detailed output in response (development only).
     * @param  (Closure(Throwable): void)|null  $logger  Receives uncaught exceptions, fatal errors, and deprecations.
     * @param  (Closure(Throwable, bool): void)|null  $renderer  Renders the error response (500 status code is already set).
     *
     * @throws LogicException If the handler is already registered.
     */
    public static function register(bool $debug = false, ?Closure $logger = null, ?Closure $renderer = null): void
    {
        if (self::$registered) {
            throw new LogicException('ErrorHandler::register(): the error handler is already registered.');
        }

        self::$registered = true;
        self::$debug = $debug;
        self::$logger = $logger;
        self::$renderer = $renderer;
        self::$reserve = str_repeat(' ', self::RESERVE_BYTES);

        set_error_handler(self::handleError(...));
        set_exception_handler(self::handleException(...));

        // register_shutdown_function nu poate fi anulat: functia ramane, dar nu face nimic dupa unregister().
        if (! self::$shutdownRegistered) {
            register_shutdown_function(self::handleShutdown(...));
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Unregisters the handlers and restores previous error and exception handlers.
     *
     * @throws LogicException If the handler is not registered.
     */
    public static function unregister(): void
    {
        if (! self::$registered) {
            throw new LogicException('ErrorHandler::unregister(): the error handler is not registered.');
        }

        restore_error_handler();
        restore_exception_handler();
        self::$registered = false;
        self::$logger = null;
        self::$renderer = null;
        self::$reserve = null;
    }

    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * Warning / notice -> ErrorException; deprecation -> logger.
     *
     * @throws ErrorException
     */
    public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // Eroare dezactivata in error_reporting() sau suprimata cu @: comportamentul PHP obisnuit.
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $exception = new ErrorException($message, 0, $severity, $file, $line);

        if (($severity & self::DEPRECATIONS) !== 0) {
            if (! self::$logger instanceof Closure) {
                return false;
            }

            self::log($exception);

            return true;
        }

        throw $exception;
    }

    /**
     * Uncaught exception: logs the error, then renders the error response. Can also be called from application try/catch.
     */
    public static function handleException(Throwable $exception): void
    {
        // O eroare in timpul tratarii nu trebuie sa porneasca o noua tratare (bucla).
        if (self::$handling) {
            error_log('ErrorHandler: error while handling an error: '.self::summary($exception));

            return;
        }

        self::$handling = true;

        try {
            self::log($exception);
            self::respond($exception);
        } finally {
            self::$handling = false;
        }
    }

    /**
     * Executed on shutdown: fatal error (if any) is handled like an uncaught exception.
     */
    public static function handleShutdown(): void
    {
        if (! self::$registered) {
            return;
        }

        // Elibereaza rezerva si mareste limita temporar pentru a permite generarea raspunsului
        @ini_set('memory_limit', '-1');
        if (self::$reserve !== null) {
            self::$reserve = null;
        }

        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }

        self::handleException(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
    }

    private static function log(Throwable $exception): void
    {
        if (! self::$logger instanceof Closure) {
            return;
        }

        try {
            (self::$logger)($exception);
        } catch (Throwable $failure) {
            // Ultima plasa: logger-ul aplicatiei a esuat, eroarea merge in log-ul PHP.
            error_log('ErrorHandler: the logger failed ('.self::summary($failure).') while logging: '.self::summary($exception));
        }
    }

    private static function respond(Throwable $exception): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, self::details($exception)."\n");

            exit(255);
        }

        if (! headers_sent()) {
            // Pagina inceputa este aruncata: raspunsul de eroare nu se amesteca cu ea.
            while (ob_get_level() > 0 && ob_end_clean()) {
                // golire
            }

            @http_response_code(500);
        }

        if (self::$renderer instanceof Closure) {
            try {
                (self::$renderer)($exception, self::$debug);

                return;
            } catch (Throwable $failure) {
                error_log('ErrorHandler: the renderer failed: '.self::summary($failure));
            }
        }

        self::defaultResponse($exception);
    }

    private static function defaultResponse(Throwable $exception): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $json = is_string($accept) && str_contains(strtolower($accept), 'json');

        if (! headers_sent()) {
            header($json ? 'Content-Type: application/json; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        if ($json) {
            $body = ['error' => 'Internal Server Error'];
            if (self::$debug) {
                $body['exception'] = [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => explode("\n", $exception->getTraceAsString()),
                ];
            }

            echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return;
        }

        echo self::$debug ? "Internal Server Error\n\n".self::details($exception) : 'Internal Server Error';
    }

    /**
     * Formats class, message, location, and stack trace, including chained (previous) exceptions.
     */
    private static function details(Throwable $exception): string
    {
        $parts = [];
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $parts[] = self::summary($current)."\n".$current->getTraceAsString();
        }

        return implode("\n\nCaused by: ", $parts);
    }

    private static function summary(Throwable $exception): string
    {
        return $exception::class.': '.$exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine();
    }
}
