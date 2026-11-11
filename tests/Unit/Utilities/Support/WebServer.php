<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities\Support;

use CurlHandle;
use RuntimeException;

/**
 * Serverul integrat PHP (php -S) cu tests/fixtures/web-router.php, pentru testele care trebuie
 * sa vada header-ele si cookie-urile reale (in CLI header() nu are efect).
 */
final class WebServer
{
    /** @var resource|null */
    private static $process;

    private static string $base = '';

    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot find a free port.');
        }

        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];

        $process = proc_open([PHP_BINARY, '-d', 'output_buffering=0', '-d', 'display_errors=0', '-S', "127.0.0.1:{$port}", __DIR__.'/../fixtures/web-router.php'], $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the PHP built-in server.');
        }

        self::$process = $process;
        self::$base = "http://127.0.0.1:{$port}";
        register_shutdown_function(self::stop(...));

        for ($i = 0; $i < 100; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The PHP built-in server did not start.');
    }

    public static function stop(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    /**
     * @param  non-empty-string  $method
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public static function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        self::start();

        $curl = curl_init(self::$base.$path);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        $received = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($curl);
        if (! is_string($result)) {
            throw new RuntimeException('Request failed: '.curl_error($curl));
        }

        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $received, 'body' => $result];
    }
}
