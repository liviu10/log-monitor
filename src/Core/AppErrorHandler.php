<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * AppErrorHandler Class
 *
 * Catches global exceptions, native PHP errors, and fatal shutdown errors.
 * Sanitizes errors and passes them to the logging mechanism.
 *
 * @category Core
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class AppErrorHandler
{
    private static ?string $memoryReserve = null;

    /**
     * Registers the global error, exception, and shutdown handlers.
     */
    public static function register(): void
    {
        // Allocate memory reserve (500 KB) for emergency situations (OOM)
        self::$memoryReserve = str_repeat('x', 1024 * 500);

        // 1. Intercept native PHP errors by converting them to ErrorException
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        // 2. Intercept unhandled exceptions
        set_exception_handler(static function (Throwable $exception): void {
            self::handleException($exception);
        });

        // 3. Intercept fatal errors (Shutdown Function) with buffer protection
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Public method to allow testing the shutdown logic.
     *
     * @param  array{type: int, message: string, file: string, line: int}|null  $mockError  Optional mock error for testing.
     */
    public static function handleShutdown(?array $mockError = null): void
    {
        $reserved = self::$memoryReserve;
        self::$memoryReserve = null; // Immediate release of RAM space
        unset($reserved);

        $error = $mockError ?? error_get_last();
        $bufferContent = '';

        // Defensive clearing of buffers, avoiding infinite locks
        $minLevel = defined('PHPUNIT_RUNNING') ? 1 : 0;
        try {
            $loopLimit = 50; // Safety valve against infinite loops
            while (ob_get_level() > $minLevel && $loopLimit-- > 0) {
                $status = ob_get_status(true);
                $currentBuffer = end($status);

                // @codeCoverageIgnoreStart
                if (is_array($currentBuffer) && isset($currentBuffer['flags'])) {
                    $flags = $currentBuffer['flags'];
                    if ((is_int($flags) || is_string($flags)) && (((int) $flags & PHP_OUTPUT_HANDLER_REMOVABLE) === 0)) {
                        ob_end_flush();
                        break;
                    }
                }
                // @codeCoverageIgnoreEnd

                $content = ob_get_clean();
                if ($content !== '') {
                    $bufferContent = $content.$bufferContent;
                }
            }
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            error_log('AppErrorHandler Alert: Output buffer cleanup failed during shutdown: '.$e->getMessage());
            // Ignore buffer failure in the terminal phase to avoid masking the main error
        }
        // @codeCoverageIgnoreEnd

        $hasFatalError = ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true));
        $hasOrphanedBuffer = (trim($bufferContent) !== '');

        if ($hasFatalError || $hasOrphanedBuffer) {
            // @codeCoverageIgnoreStart
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            // @codeCoverageIgnoreEnd

            if ($hasFatalError) {
                $message = $error['message'];
                $type = match (true) {
                    str_contains($message, 'Allowed memory size') => 'Fatal Out Of Memory (RAM Exceeded)',
                    str_contains($message, 'Maximum execution time') => 'Fatal Execution Timeout',
                    default => 'PHP Fatal Shutdown'
                };

                LogViaStream::send('CRITICAL', 'Fatal Error: '.$message, [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $type,
                    'captured_output_buffer' => substr($bufferContent, 0, 4000),
                ]);
            } else {
                LogViaStream::send('WARNING', 'Script terminated unexpectedly with unrendered output buffer.', [
                    'type' => 'Orphaned Buffer',
                    'captured_output_buffer' => substr($bufferContent, 0, 4000),
                ]);
            }

            if (! headers_sent()) {
                http_response_code(500);
            }
        }
    }

    /**
     * Processes and formats intercepted exceptions.
     */
    private static function handleException(Throwable $exception): void
    {
        if (! headers_sent()) {
            http_response_code(500);
        }

        $context = [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => self::formatTrace($exception),
        ];

        $level = 'CRITICAL';
        $message = $exception->getMessage();

        if ($exception instanceof ErrorException) {
            $severity = $exception->getSeverity();
            $level = match ($severity) {
                E_WARNING, E_USER_WARNING => 'WARNING',
                E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED => 'INFO',
                default => 'ERROR'
            };

            $context['type'] = 'PHP Native Error';
            if (str_contains(strtolower($message), 'permission denied')) {
                $context['type'] = 'File System Permission Error';
            }
        } elseif ($exception instanceof \PDOException || str_contains($exception::class, 'OCI')) {
            $context['type'] = 'Database Infrastructure Error';
            $message = self::maskDatabaseSecrets($message);
        } else {
            $context['type'] = $exception::class;
            $message = 'Exception: '.$message;
        }

        LogViaStream::send($level, $message, $context);
    }

    /**
     * Hides sensitive data in database connection strings (Multiline Safe).
     */
    private static function maskDatabaseSecrets(string $message): string
    {
        if (str_contains(strtolower($message), 'ora-01017') || str_contains(strtolower($message), 'logon denied')) {
            $replaced0 = preg_replace('/for user\s+[\'"][^\'"]+[\'"]/ims', "for user '******'", $message);
            $message = is_string($replaced0) ? $replaced0 : $message;
            $message = 'Database Failure (Oracle Auth): '.$message;
        }

        $patterns = [
            '/(user|username|uid|pwd|password|pass|host|port|sid|service_name)\s*=\s*[^\s;()"\']+/ims',
        ];
        $replaced1 = preg_replace($patterns, '$1=******', $message);
        $message = is_string($replaced1) ? $replaced1 : $message;
        $replaced2 = preg_replace('/(:?\/\/)[^:]+:[^@]+@/ims', '$1******:******@', $message);
        $message = is_string($replaced2) ? $replaced2 : $message;

        if (! str_contains($message, 'Database Failure')) {
            $message = 'Database Failure: '.$message;
        }

        return $message;
    }

    /**
     * Formats the exception stack trace in a readable format.
     *
     * @return array<int, string> Formatted trace.
     */
    private static function formatTrace(Throwable $exception): array
    {
        $trace = [];
        $rawTrace = $exception->getTrace();
        $counter = 0;

        foreach ($rawTrace as $step) {
            if ($counter++ >= 10) {
                break;
            }
            $trace[] = sprintf(
                '#%d %s(%d): %s%s%s()',
                $counter,
                $step['file'] ?? 'unknown_file',
                $step['line'] ?? 0,
                $step['class'] ?? '',
                $step['type'] ?? '',
                $step['function']
            );
        }

        return $trace;
    }
}
