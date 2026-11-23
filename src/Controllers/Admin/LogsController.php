<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Models\App;
use App\Models\Log;
use RuntimeException;
use Throwable;

/**
 * Logs Controller
 *
 * Manages the searchable, filterable, live-tailing logs timeline and log archiving.
 */
class LogsController extends BaseController
{
    public function index(): string
    {
        try {
            $logModel = new Log;
            $appModel = new App;

            $filters = [
                'app_id' => $_GET['app_id'] ?? null,
                'level' => $_GET['level'] ?? null,
                'search' => $_GET['search'] ?? null,
            ];

            $rawPage = $_GET['page'] ?? 1;
            $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

            $allowedLimits = [10, 25, 50, 100];
            $rawLimit = $_GET['limit'] ?? 10;
            $limit = is_numeric($rawLimit) ? (int) $rawLimit : 10;
            if (! in_array($limit, $allowedLimits, true)) {
                $limit = 10;
            }

            $offset = ($page - 1) * $limit;

            $rawSortBy = $_GET['sort_by'] ?? 'id';
            $sortBy = is_string($rawSortBy) ? $rawSortBy : 'id';
            $rawSortDir = $_GET['sort_dir'] ?? 'DESC';
            $sortDir = is_string($rawSortDir) ? $rawSortDir : 'DESC';

            $logs = $logModel->getPaginated($filters, $limit, $offset, $sortBy, $sortDir);
            $totalLogs = $logModel->count($filters);
            $totalPages = max(1, (int) ceil($totalLogs / $limit));

            $apps = $appModel->getAll();
            $levels = LogLevel::all();
            $stats = $logModel->getStats();

            return $this->render('admin.logs.index', [
                'title' => __('Logs'),
                'logs' => $logs,
                'apps' => $apps,
                'levels' => $levels,
                'filters' => $filters,
                'page' => $page,
                'totalLogs' => $totalLogs,
                'totalPages' => $totalPages,
                'stats' => $stats,
                'limit' => $limit,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Logs index processing failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'LogsController_Index_Failure',
                ]);
            }

            throw new RuntimeException(__('Critical error loading logs data. Please try again later.'), 500, $e);
        }
    }

    /**
     * Purges and archives logs older than a specified number of days.
     *
     * @return array<string, mixed>
     */
    public function purge(int $days, string $backupPath): array
    {
        try {
            $timestamp = strtotime("-{$days} days");
            if ($timestamp === false) {
                throw new RuntimeException(__('Failed to calculate the retention cutoff date boundary.'));
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
                throw new RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }

            if (is_dir($backupPath)) {
                throw new RuntimeException(sprintf(__('Failed to create backup file at path: %s'), $backupPath));
            }

            $fileHandle = @fopen($backupPath, 'w');
            if ($fileHandle === false) {
                throw new RuntimeException(sprintf(__('Failed to create backup file at path: %s'), $backupPath));
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
                'deleted_count' => $deletedCount,
            ];
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log purge task failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'retention_days' => $days,
                    'backup_destination' => $backupPath,
                    'identifier' => 'LogsController_Purge_Failure',
                ]);
            }

            throw $e;
        }
    }
}
