<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\App;
use App\Models\Log;
use App\Enums\LogLevel;
use App\Utilities\Validation;
use App\Utilities\LogViaStream;

/**
 * Clasa LogController
 *
 * Responsabila pentru gestionarea cererilor de tip API dedicate inregistrarii de loguri.
 * Asigura securitatea prin verificarea User-Agent (pentru prevenirea expunerii cheilor in browser),
 * validarea stricta a headerului Content-Type si verificarea cheilor API unice per aplicatie.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class LogController extends BaseController
{
    /**
     * Proceseaza si stocheaza o noua intrare de log primita prin POST (cerere API JSON).
     * @return never
     */
    public function store(): void
    {
        // 1. Verificare User-Agent pentru protectie server-to-server
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

        // 2. Verificare Content-Type
        $contentType = $this->getRequestHeader('Content-Type') ?? '';
        if (!str_contains($contentType, 'application/json')) {
            $this->jsonResponse(['error' => 'Content-Type must be application/json'], 415);
        }

        // 3. Verificare prezenta si validitate API Key
        $apiKey = $this->getRequestHeader('X-API-KEY');
        if (!$apiKey) {
            $this->jsonResponse(['error' => 'X-API-KEY header is missing'], 401);
        }

        $appModel = new App();
        try {
            $app = $appModel->findByApiKey($apiKey);
        } catch (\Throwable $throwable) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }

        if ($app === []) {
            $this->jsonResponse(['error' => 'Invalid or inactive API Key'], 403);
        }

        // 4. Utilizare functionalitate nativa PHP 8.4 json_validate pentru performanta si protectie
        $rawPayload = file_get_contents('php://input');
        $jsonValidate = function (string $json, int $depth = 512, int $flags = 0) {
            if (function_exists('json_validate')) {
                return json_validate($json, $depth, $flags);
            }
            $maxDepth = 0x7fffffff;
            if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
                throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
            }
            if ($depth <= 0) {
                throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
            }
            if ($depth > $maxDepth) {
                throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $maxDepth));
            }
            json_decode($json, true, $depth, $flags);
            return \JSON_ERROR_NONE === json_last_error();
        };
        if (!$jsonValidate($rawPayload)) {
            $this->jsonResponse(['error' => 'Invalid JSON payload structure'], 400);
        }

        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $this->jsonResponse(['error' => 'Invalid JSON structure format'], 400);
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

        if ($errors !== []) {
            $errorMessages = [];
            foreach ($errors as $field => $rules) {
                foreach ($rules as $rule) {
                    $errorMessages[] = $validator->messages($field, $rule);
                }
            }

            $this->jsonResponse(['errors' => $errorMessages], 422);
        }

        // 5. Inregistrare log in baza de date si alertare automata
        try {
            $logModel = new Log();
            $result = $logModel->create(
                $app['id'],
                $payload['level'],
                $payload['message'],
                $payload['context'] ?? null,
            );

            if ($result !== 0) {
                $notificationController = new NotificationController();
                $notificationController->sendAlert($app, [
                    'level' => $payload['level'],
                    'message' => $payload['message'],
                    'context' => $payload['context'] ?? null,
                ]);

                $this->jsonResponse(['status' => true, 'message' => __('Log recorded')]);
            }

            $this->jsonResponse(['error' => __('Failed to store log')], 500);
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'API log storage critical failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'app_id' => $app['id'] ?? null,
                'identifier' => 'LogController_Store_CriticalFailure'
            ]);
            $this->jsonResponse(['error' => __('Internal Server Error')], 500);
        }
    }

    /**
     * Purgeaza si arhiveaza logurile mai vechi de un numar specificat de zile.
     *
     * @param int    $days       Numarul de zile salvat ca prag de retentie.
     * @param string $backupPath Calea absoluta catre fisierul de backup salvat.
     */
    public function purge(int $days, string $backupPath): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)));
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

            $dir = dirname($backupPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
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
                if ($rows === []) {
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
                'deleted_count' => $deletedCount
            ];
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'Log purge task failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'retention_days' => $days,
                'backup_destination' => $backupPath,
                'identifier' => 'LogController_Purge_Failure'
            ]);
            throw $throwable;
        }
    }

    /**
     * Returneaza valoarea unui header din request, case-insensitive.
     */
    private function getRequestHeader(string $name): ?string
    {
        $normalizedName = strtolower($name);
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    if (strtolower((string)$key) === $normalizedName) {
                        return (string)$value;
                    }
                }
            }
        }
        
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) {
            return (string)$_SERVER[$serverKey];
        }
        
        $directServerKey = strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$directServerKey])) {
            return (string)$_SERVER[$directServerKey];
        }
        
        return null;
    }
}