<?php

declare(strict_types=1);

// Disable execution time limit completely, though execution should be under 2-3 ms
set_time_limit(5);

// Load base application configuration
require_once dirname(__DIR__).'/bootstrap.php';

use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

// Determine if we are running in the context of a FrankenPHP worker
$isFrankenPhpWorker = function_exists('frankenphp_handle_request');

$handler = function () {
    // CORS Support: Allow Preflight (OPTIONS) requests from external browsers
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        // Only X-API-KEY is left in the allowed headers list
        header('Access-Control-Allow-Headers: X-API-KEY, Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
        http_response_code(204); // No Content

        return;
    }

    // Validate HTTP method: only accept POST requests for data ingestion
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['error' => __('Method Not Allowed. Use POST.')]);

        return;
    }

    // Add origin header for POST request response as well
    header('Access-Control-Allow-Origin: *');

    // Extract the API Key EXCLUSIVELY from headers
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['X_API_KEY'] ?? null;

    if (! $apiKey) {
        // Case-insensitive fallback via getallheaders if the function is available
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers) {
                foreach ($headers as $key => $value) {
                    if (strcasecmp((string) $key, 'X-API-KEY') === 0) {
                        $apiKey = $value;
                        break;
                    }
                }
            }
        }
    }

    $apiKeyStr = is_string($apiKey) ? trim($apiKey) : '';

    // Fail-Fast: If the API key is missing, reject the request directly
    if ($apiKeyStr === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('X-API-KEY header is missing or empty.')]);

        return;
    }

    // Internal validation: find application ID directly from the database
    try {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT id FROM apps WHERE api_key = ? LIMIT 1', [$apiKeyStr]);
        $app = $stmt->fetch();

        if (! is_array($app) || ! isset($app['id'])) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => __('Invalid or inactive client API Key.')]);

            return;
        }

        $appIdVal = $app['id'];
        if (! is_int($appIdVal) && ! is_string($appIdVal)) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => __('Invalid application structure in database.')]);

            return;
        }

        $appId = (int) $appIdVal;
    } catch (Throwable $e) {
        if (class_exists('App\\Utilities\\LogViaStream')) {
            LogViaStream::send(LogLevel::ERROR->value, 'Log API query failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'Log API query failure event',
                'sql_parameters' => [
                    'api_key' => $apiKeyStr,
                ],
                'identifier' => 'Log_API_Query_Failure',
            ]);
        }

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Database connection or query failed.')]);

        return;
    }

    // Preluam corpul brut al cererii POST ca string
    $rawPayload = file_get_contents('php://input');

    if ($rawPayload === false || trim($rawPayload) === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('Empty request body.')]);

        return;
    }

    // Quickly insert payload into the log_queue table without validation or JSON decoding
    try {
        $db->create('log_queue', [
            'app_id' => $appId,
            'payload_raw' => $rawPayload,
        ]);
    } catch (Throwable $e) {
        if (class_exists('App\\Utilities\\LogViaStream')) {
            LogViaStream::send(LogLevel::ERROR->value, 'Failed to queue the log payload', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'INSERT INTO log_queue',
                'sql_parameters' => [
                    'app_id' => $appId,
                    'payload_raw' => $rawPayload,
                ],
                'identifier' => 'Log_Queue_Failure',
            ]);
        }

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Failed to queue the log payload.')]);

        return;
    }

    // Instantly return 202 Accepted status with standard JSON response
    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode(['status' => 'queued']);
};

if ($isFrankenPhpWorker) {
    try {
        // FrankenPHP worker loop
        $maxRequests = 500; // To prevent memory leaks
        for ($nbRequests = 0; $nbRequests < $maxRequests; $nbRequests++) {
            $keepRunning = frankenphp_handle_request($handler);
            if (! $keepRunning) {
                break;
            }
        }
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'not in worker mode')) {
            // If not in worker mode (e.g. standard request), execute the handler directly
            $handler();
        } else {
            if (class_exists('App\\Utilities\\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'FrankenPHP worker loop execution failure', [
                    'location' => __FILE__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'FrankenPHP_Worker_Failure',
                ]);
            }
            throw $e;
        }
    }
} else {
    // Normal execution (e.g. PHP-FPM or direct CLI)
    $handler();
}
