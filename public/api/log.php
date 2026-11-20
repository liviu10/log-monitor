<?php

declare(strict_types=1);

// Fast ingestion execution limit (target < 2-3 ms)
set_time_limit(5);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;

$isFrankenPhpWorker = function_exists('frankenphp_handle_request');

$handler = static function (): void {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-API-KEY, Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);

        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['error' => __('Method Not Allowed. Use POST.')]);

        return;
    }

    header('Access-Control-Allow-Origin: *');

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['X_API_KEY'] ?? null;

    if (! is_string($apiKey) || $apiKey === '') {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, 'X-API-KEY') === 0) {
                    $apiKey = $value;
                    break;
                }
            }
        }
    }

    $apiKeyStr = is_string($apiKey) ? trim($apiKey) : '';

    if ($apiKeyStr === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('X-API-KEY header is missing or empty.')]);

        return;
    }

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
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::ERROR->value, 'Log API query failure event', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'Log API query failure event',
                'identifier' => 'Log_API_Query_Failure',
            ]);
        }

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Database connection or query failed.')]);

        return;
    }

    $rawPayload = file_get_contents('php://input');

    if ($rawPayload === false || trim($rawPayload) === '') {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => __('Empty request body.')]);

        return;
    }

    try {
        $db->create('log_queue', [
            'app_id' => $appId,
            'payload_raw' => $rawPayload,
        ]);
    } catch (Throwable $e) {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::ERROR->value, 'Failed to queue the log payload', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'INSERT INTO log_queue',
                'sql_parameters' => ['app_id' => $appId],
                'identifier' => 'Log_Queue_Failure',
            ]);
        }

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => __('Failed to queue the log payload.')]);

        return;
    }

    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode(['status' => 'queued']);
};

if ($isFrankenPhpWorker) {
    try {
        $maxRequests = 500;
        for ($nbRequests = 0; $nbRequests < $maxRequests; $nbRequests++) {
            $keepRunning = frankenphp_handle_request($handler);
            if (! $keepRunning) {
                break;
            }
        }
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'not in worker mode')) {
            $handler();
        } else {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
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
    $handler();
}
