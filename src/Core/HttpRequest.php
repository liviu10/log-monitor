<?php

declare(strict_types=1);

namespace App\Core;

use App\Enums\LogLevel;
use RuntimeException;
use Throwable;

/**
 * HttpRequest Class
 *
 * Utility class for performing HTTP requests via cURL.
 * Supports GET, POST, PUT, DELETE operations.
 *
 * @category Core
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class HttpRequest
{
    /**
     * Performs a GET request.
     *
     * @param  string  $endpoint  The full URL to request.
     * @param  array<string, mixed>  $options  Options array ['payload' => [], 'headers' => [], 'timeout' => 10]
     */
    public static function get(string $endpoint, array $options = []): string
    {
        return self::execute('GET', $endpoint, $options);
    }

    /**
     * Performs a POST request.
     *
     * @param  string  $endpoint  The full URL to request.
     * @param  array<string, mixed>  $options  Options array ['payload' => [], 'headers' => [], 'timeout' => 10]
     */
    public static function post(string $endpoint, array $options = []): string
    {
        return self::execute('POST', $endpoint, $options);
    }

    /**
     * Performs a PUT request.
     *
     * @param  string  $endpoint  The full URL to request.
     * @param  array<string, mixed>  $options  Options array ['payload' => [], 'headers' => [], 'timeout' => 10]
     */
    public static function put(string $endpoint, array $options = []): string
    {
        return self::execute('PUT', $endpoint, $options);
    }

    /**
     * Performs a DELETE request.
     *
     * @param  string  $endpoint  The full URL to request.
     * @param  array<string, mixed>  $options  Options array ['payload' => [], 'headers' => [], 'timeout' => 10]
     */
    public static function delete(string $endpoint, array $options = []): string
    {
        return self::execute('DELETE', $endpoint, $options);
    }

    /**
     * Executes the internal HTTP request.
     * Restricted to 3 parameters for better testability.
     *
     * @param  string  $method  HTTP Method (GET, POST, PUT, DELETE).
     * @param  string  $endpoint  The full URL to request.
     * @param  array<string, mixed>  $options  Configuration options (payload, headers, timeout).
     * @return string Raw response from the server.
     *
     * @throws RuntimeException If the request fails or returns a non-2xx status code.
     */
    private static function execute(string $method, string $endpoint, array $options): string
    {
        $payload = (isset($options['payload']) && is_array($options['payload'])) ? $options['payload'] : [];
        $headers = (isset($options['headers']) && is_array($options['headers'])) ? $options['headers'] : [];
        $timeout = (isset($options['timeout']) && is_int($options['timeout'])) ? $options['timeout'] : 10;

        try {
            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize cURL.');
            }

            $method = strtoupper(trim($method));

            // Format headers for cURL: ['Header-Name: Value']
            $formattedHeaders = [];
            $isJson = false;
            foreach ($headers as $key => $value) {
                $headerStr = "{$key}: ".(is_scalar($value) ? (string) $value : '');
                $formattedHeaders[] = $headerStr;
                if (stripos($headerStr, 'application/json') !== false) {
                    $isJson = true;
                }
            }

            if ($method === 'GET' && count($payload) > 0) {
                $endpoint .= (str_contains($endpoint, '?') ? '&' : '?').http_build_query($payload);
            }
            if ($endpoint === '') {
                throw new RuntimeException('Endpoint cannot be empty.');
            }
            if ($method === '') {
                throw new RuntimeException('Method cannot be empty.');
            }

            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if (count($formattedHeaders) > 0) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
            }

            if ($method !== 'GET' && count($payload) > 0) {
                if ($isJson) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                }
            }

            $response = curl_exec($ch);

            if ($response === false) {
                $error = curl_error($ch);
                throw new RuntimeException("cURL Error: {$error}");
            }

            /** @var mixed $statusCodeRaw */
            $statusCodeRaw = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCode = is_numeric($statusCodeRaw) ? (int) $statusCodeRaw : 0;

            if ($statusCode < 200 || $statusCode >= 300) {
                $responsePreview = is_string($response) ? substr($response, 0, 500) : '';
                throw new RuntimeException("HTTP Error: Received status code {$statusCode}. Response: {$responsePreview}");
            }

            return is_string($response) ? $response : '';

        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, "Error executing HTTP Request to {$endpoint}", [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'HttpRequest_Failure',
                    'endpoint' => $endpoint,
                    'method' => $method,
                ]);
            }
            throw new RuntimeException(__('Error executing HTTP Request to :endpoint: :error', ['endpoint' => $endpoint, 'error' => $e->getMessage()]), 500, $e);
        }
    }
}
