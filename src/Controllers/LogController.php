<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\App;
use App\Models\Log;
use App\Utilities\LogViaStream;
use App\Utilities\Validation;

/**
 * LogController Class
 *
 * Responsible for handling API requests dedicated to log recording.
 * Ensures security by checking User-Agent (to prevent key exposure in the browser),
 * strict validation of the Content-Type header, and verifying unique API keys per application.
 *
 * @category Controller
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class LogController extends BaseController
{
    /**
     * Processes and stores a new log entry received via POST (JSON API request).
     */
    public function store(): never
    {
        // 1. Check User-Agent for server-to-server protection
        $userAgent = $this->getRequestHeader('User-Agent') ?? '';
        $browserSignatures = ['Mozilla', 'Chrome', 'Safari', 'Edge', 'Opera', 'Firefox'];

        foreach ($browserSignatures as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                $this->jsonResponse([
                    'error' => 'Security Violation',
                    'message' => 'Frontend logging is disabled. Please send logs from your server-side code (cURL, Guzzle, etc.) to keep your API keys secure.',
                ], 403);
            }
        }

        // 2. Check Content-Type
        $contentType = $this->getRequestHeader('Content-Type') ?? '';
        if (! str_contains($contentType, 'application/json')) {
            $this->jsonResponse(['error' => 'Content-Type must be application/json'], 415);
        }

        // 3. Check presence and validity of the API Key
        $apiKey = $this->getRequestHeader('X-API-KEY');
        if (! $apiKey) {
            $this->jsonResponse(['error' => 'X-API-KEY header is missing'], 401);
        }

        $appModel = new App;
        try {
            $app = $appModel->findByApiKey($apiKey);
        } catch (\Throwable $e) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }
        if (! $app) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }

        // 4. Use native PHP 8.4 json_validate for performance and protection
        $rawPayload = file_get_contents('php://input');
        if ($rawPayload === false || ! json_validate($rawPayload)) {
            $this->jsonResponse(['error' => 'Invalid JSON payload structure'], 400);
        }

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload)) {
            $this->jsonResponse(['error' => 'Invalid JSON structure format'], 400);
        }

        $validator = new Validation([
            'level' => __('Log level'),
            'message' => __('Message'),
            'context' => __('Context'),
        ]);

        $errors = $validator->validate([
            'level' => ['required', 'string', 'in:'.implode(',', LogLevel::all())],
            'message' => ['required', 'string'],
            'context' => ['array'],
        ], $payload);

        if (! empty($errors)) {
            $errorMessages = [];
            foreach ($errors as $field => $rules) {
                foreach ($rules as $rule) {
                    $errorMessages[] = $validator->messages((string) $field, $rule);
                }
            }
            $this->jsonResponse(['errors' => $errorMessages], 422);
        }

        // 5. Record log in database and send automatic alerts
        try {
            $logModel = new Log;
            $rawAppId = $app['id'] ?? null;
            $rawLevel = $payload['level'] ?? null;
            $rawMessage = $payload['message'] ?? null;
            $rawContext = $payload['context'] ?? null;

            if ((! is_int($rawAppId) && ! is_string($rawAppId)) ||
                ! is_string($rawLevel) ||
                ! is_string($rawMessage) ||
                ($rawContext !== null && ! is_array($rawContext))) {
                $this->jsonResponse(['error' => __('Invalid data types for log entry')], 400);
            }

            $result = $logModel->create(
                (int) $rawAppId,
                $rawLevel,
                $rawMessage,
                $rawContext,
            );

            if ($result) {
                $notificationController = new NotificationController;
                $notificationController->sendAlert($app, [
                    'level' => $rawLevel,
                    'message' => $rawMessage,
                    'context' => $rawContext,
                ]);

                $this->jsonResponse(['status' => true, 'message' => __('Log recorded')]);
            }

            $this->jsonResponse(['error' => __('Failed to store log')], 500);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'API log storage critical failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'app_id' => $app['id'] ?? null,
                    'identifier' => 'LogController_Store_CriticalFailure',
                ]);
            }

            $this->jsonResponse(['error' => __('Internal Server Error')], 500);
        }
    }

    /**
     * Purges and archives logs older than a specified number of days.
     *
     * @param  int  $days  The number of days saved as retention cutoff.
     * @param  string  $backupPath  The absolute path to the saved backup file.
     * @return array<string, mixed>
     */
    public function purge(int $days, string $backupPath): array
    {
        try {
            $timestamp = strtotime("-{$days} days");
            if ($timestamp === false) {
                throw new \RuntimeException(__('Failed to calculate the retention cutoff date boundary.'));
            }
            $cutoffDate = date('Y-m-d H:i:s', $timestamp);
            $logModel = new Log;

            $totalToArchive = $logModel->countBeforeDate($cutoffDate);

            if ($totalToArchive === 0) {
                return [
                    'status' => true,
                    'message' => __('No old logs to archive.'),
                    'archived_count' => 0,
                    'deleted_count' => 0,
                ];
            }

            $dir = dirname($backupPath);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }

            $fileHandle = fopen($backupPath, 'w');
            if (! $fileHandle) {
                throw new \RuntimeException(sprintf(__('Failed to create backup file at path: %s'), $backupPath));
            }

            fwrite($fileHandle, 'LOG MONITOR BACKUP - GENERATED AT '.date('Y-m-d H:i:s')."\n");
            fwrite($fileHandle, str_repeat('=', 80)."\n\n");

            $offset = 0;
            $chunkSize = 1000;

            while ($offset < $totalToArchive) {
                $rows = $logModel->getBeforeDate($cutoffDate, $chunkSize, $offset);

                foreach ($rows as $row) {
                    $createdAt = $row['created_at'] ?? '';
                    $appName = $row['app_name'] ?? '';
                    $level = $row['level'] ?? '';
                    $message = $row['message'] ?? '';
                    $context = $row['context'] ?? '{}';

                    $line = sprintf(
                        "[%s] [%s] [%s]: %s | Context: %s\n",
                        is_string($createdAt) ? $createdAt : '',
                        is_string($appName) ? $appName : '',
                        is_string($level) ? $level : '',
                        is_string($message) ? $message : '',
                        is_string($context) ? $context : '{}'
                    );
                    fwrite($fileHandle, $line);
                }

                $offset += count($rows);
                if (empty($rows)) {
                    break;
                }
            }

            fclose($fileHandle);

            $deletedCount = $logModel->deleteBeforeDate($cutoffDate);
            $logModel->optimize();

            return [
                'status' => true,
                'message' => __('Archive and cleanup process completed successfully.'),
                'archived_count' => $offset,
                'deleted_count' => $deletedCount,
            ];
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log purge task failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'retention_days' => $days,
                    'backup_destination' => $backupPath,
                    'identifier' => 'LogController_Purge_Failure',
                ]);
            }

            throw $e;
        }
    }

    /**
     * Returns the value of a request header, case-insensitively.
     */
    private function getRequestHeader(string $name): ?string
    {
        $normalizedName = strtolower($name);

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === $normalizedName) {
                    if (is_string($value) || is_numeric($value) || is_bool($value)) {
                        return (string) $value;
                    }
                }
            }
        }

        $serverKey = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            $srvVal = $_SERVER[$serverKey];
            if (is_string($srvVal) || is_numeric($srvVal) || is_bool($srvVal)) {
                return (string) $srvVal;
            }
        }

        $directServerKey = strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$directServerKey])) {
            $dirSrvVal = $_SERVER[$directServerKey];
            if (is_string($dirSrvVal) || is_numeric($dirSrvVal) || is_bool($dirSrvVal)) {
                return (string) $dirSrvVal;
            }
        }

        return null;
    }
}
