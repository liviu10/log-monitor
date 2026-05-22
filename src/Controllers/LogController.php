<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\App;
use App\Models\Log;
use App\Enums\LogLevel;
use App\Utilities\Validation;

/**
 * LogController Class
 *
 * Responsible for managing API-type requests for log registration.
 * Ensures security through User-Agent verification (to prevent browser logging), 
 * Content-Type header validation, and unique API keys per application.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class LogController extends BaseController
{
    /**
     * Processes and stores a new log entry received via POST.
     * 
     * This method performs the following checks:
     * - Blocks requests originating from web browsers (server-to-server security).
     * - Enforces JSON format for the payload.
     * - Validates the API key provided in the header.
     * - Validates the log structure and level according to the LogLevel enumeration.
     */
    public function store(): never
    {
        // 1. User-Agent verification (Block browsers for security)
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browserSignatures = ['Mozilla', 'Chrome', 'Safari', 'Edge', 'Opera', 'Firefox'];
        
        foreach ($browserSignatures as $signature) {
            if (stripos($userAgent, $signature) !== false) {
                $this->jsonResponse([
                    'error' => 'Security Violation',
                    'message' => 'Frontend logging is disabled. Please send logs from your server-side code (cURL, Guzzle, etc.) to keep your API keys secure.',
                ], 403);
            }
        }

        // 2. Content-Type verification
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'application/json')) {
            $this->jsonResponse(['error' => 'Content-Type must be application/json'], 415);
        }

        // 3. API Key verification
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        
        if (!$apiKey) {
            $this->jsonResponse(['error' => 'X-API-KEY header is missing'], 401);
        }

        $appModel = new App();
        $app = $appModel->findByApiKey($apiKey);

        if (!$app) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }

        // 4. JSON decoding and validation
        $payload = json_decode(file_get_contents('php://input'), true);

        if (!$payload) {
            $this->jsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        $validator = new Validation([
            'level' => __('Log level'),
            'message' => __('Message'),
            'context' => __('Context'),
        ]);

        $errors = $validator->validate([
            'level' => ['required', 'string', 'in:' . implode(',', LogLevel::all())],
            'message' => ['required', 'string'],
            'context' => ['array'],
        ], $payload);

        if (!empty($errors)) {
            $errorMessages = [];
            foreach ($errors as $field => $rules) {
                foreach ($rules as $rule) {
                    $errorMessages[] = $validator->messages($field, $rule);
                }
            }
            $this->jsonResponse(['errors' => $errorMessages], 422);
        }

        // 5. Log registration in database
        $logModel = new Log();
        $result = $logModel->create(
            $app['id'],
            $payload['level'],
            $payload['message'],
            $payload['context'] ?? null,
        );

        if ($result) {
            // Sending notifications through NotificationController
            $notificationController = new NotificationController();
            $notificationController->sendAlert($app, [
                'level' => $payload['level'],
                'message' => $payload['message'],
                'context' => $payload['context'] ?? null,
            ]);

            $this->jsonResponse(['status' => true, 'message' => __('Log recorded')]);
        }

        $this->jsonResponse(['error' => __('Failed to store log')], 500);
    }

    /**
     * Purges and archives logs older than a specified number of days.
     *
     * @param int    $days       Number of days for retention.
     * @param string $backupPath Absolute path to the backup file.
     * @return array{
     *   status: string,
     *   message: string,
     *   archived_count: int,
     *   deleted_count: int|false
     * }
     */
    public function purge(int $days, string $backupPath): array
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $logModel = new Log();

        $totalToArchive = $logModel->countBeforeDate($cutoffDate);

        if ($totalToArchive === 0) {
            return [
                'status' => true,
                'message' => __('No old logs to archive.'),
                'archived_count' => 0,
                'deleted_count' => 0
            ];
        }

        // Creating backup directory if it doesn't exist
        $dir = dirname($backupPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileHandle = fopen($backupPath, 'w');
        if (!$fileHandle) {
            throw new \RuntimeException(__('Failed to create backup file at path: %s', $backupPath));
        }

        fwrite($fileHandle, "LOG MONITOR BACKUP - GENERATED AT " . date('Y-m-d H:i:s') . "\n");
        fwrite($fileHandle, str_repeat("=", 80) . "\n\n");

        $offset = 0;
        $chunkSize = 1000;

        while ($offset < $totalToArchive) {
            $rows = $logModel->getBeforeDate($cutoffDate, $chunkSize, $offset);

            foreach ($rows as $row) {
                $line = sprintf(
                    "[%s] [%s] [%s]: %s | Context: %s\n",
                    $row['created_at'],
                    $row['app_name'],
                    $row['level'],
                    $row['message'],
                    $row['context'] ?? '{}'
                );
                fwrite($fileHandle, $line);
            }

            $offset += count($rows);
            if (empty($rows)) {
                break;
            }
        }

        fclose($fileHandle);

        // Deleting old logs from the database
        $deletedCount = $logModel->deleteBeforeDate($cutoffDate);

        // Optimizing the logs table
        $logModel->optimize();

        return [
            'status' => true,
            'message' => __('Archive and cleanup process completed successfully.'),
            'archived_count' => $offset,
            'deleted_count' => $deletedCount
        ];
    }
}
