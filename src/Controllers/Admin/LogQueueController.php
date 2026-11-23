<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\LogQueueProcessor;
use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Models\LogQueue;
use RuntimeException;
use Throwable;

/**
 * Log Queue Controller
 *
 * Manages the high-throughput raw log ingestion buffer (log_queue) and worker heartbeat status.
 */
class LogQueueController extends BaseController
{
    public function index(): string
    {
        try {
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

            $allowedSorts = ['id', 'created_at', 'app_name'];
            $allowedDirections = ['ASC', 'DESC'];

            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            if (! in_array(strtoupper($sortDir), $allowedDirections, true)) {
                $sortDir = 'DESC';
            }

            $rawSearch = $_GET['search'] ?? '';
            $rawAppId = $_GET['filter_app_id'] ?? ($_GET['app_id'] ?? 'all');
            $filters = [
                'search' => is_string($rawSearch) ? trim($rawSearch) : '',
                'app_id' => is_string($rawAppId) && $rawAppId !== '' ? trim($rawAppId) : 'all',
            ];

            $result = LogQueue::getPaginatedFiltered($filters, $limit, $offset, $sortBy, $sortDir);
            $totalJobs = $result['total'];
            $totalPages = max(1, (int) ceil($totalJobs / $limit));
            $jobs = $result['items'];

            $stats = LogQueue::getStats();
            $distinctApps = LogQueue::getDistinctApps();
            $isWorkerRunning = $this->isLogWorkerRunning();

            return $this->render('admin.log-queue.index', [
                'title' => __('Log Queue'),
                'jobs' => $jobs,
                'totalJobs' => $totalJobs,
                'totalPages' => $totalPages,
                'page' => $page,
                'limit' => $limit,
                'filters' => $filters,
                'stats' => $stats,
                'distinctApps' => $distinctApps,
                'isWorkerRunning' => $isWorkerRunning,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue index processing failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'LogQueueController_Index_Failure',
                ]);
            }

            throw new RuntimeException(__('Critical error loading queue data. Please try again later.'), 500, $e);
        }
    }

    /**
     * Manually runs one log-ingestion batch cycle against `log_queue`.
     */
    public function runWorker(): never
    {
        try {
            $appsCache = [];
            $processed = LogQueueProcessor::processBatch($appsCache);

            if ($processed > 0) {
                $this->flash('success', __('Success'), __(':count buffered log payloads processed successfully.', ['count' => (string) $processed]));
            } else {
                $this->flash('info', __('Info'), __('No pending log payloads found in the queue.'));
            }
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue manual worker execution failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'LogQueueController_RunWorker_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to execute log ingestion worker cycle.'));
        }

        $this->redirect('/admin/log-queue');
    }

    public function delete(string $id): never
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            $this->redirectWithFlash('/admin/log-queue', 'error', __('Error'), __('Invalid job ID.'));
        }

        try {
            LogQueue::deleteById($jobId);
            $this->flash('success', __('Success'), __('Job has been deleted from queue.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue delete job failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'job_id' => $jobId,
                    'identifier' => 'LogQueueController_Delete_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to delete job from queue.'));
        }

        $this->redirect('/admin/log-queue');
    }

    public function purge(): never
    {
        try {
            LogQueue::purgeAll();
            $this->flash('success', __('Success'), __('The queue has been completely purged.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue purge failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'LogQueueController_Purge_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to purge the queue.'));
        }

        $this->redirect('/admin/log-queue');
    }

    public function isLogWorkerRunning(): bool
    {
        $heartbeatFile = \storage_path('worker.heartbeat');
        if (! file_exists($heartbeatFile)) {
            return false;
        }

        $lastSeenRaw = @file_get_contents($heartbeatFile);
        if (! is_string($lastSeenRaw) || ! is_numeric(trim($lastSeenRaw))) {
            return false;
        }

        return (time() - (int) trim($lastSeenRaw)) <= 30;
    }
}
