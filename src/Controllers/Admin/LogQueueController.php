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
 * Manages the buffered log ingestion queue (`log_queue`), job retries, dead-letter inspection,
 * worker runs, and processed-job purging.
 */
class LogQueueController extends BaseController
{
    public function index(): string
    {
        try {
            $rawPage = $_GET['page'] ?? 1;
            $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

            $allowedLimits = [10, 25, 50, 100];
            $rawLimit = $_GET['limit'] ?? ($_GET['per_page'] ?? 10);
            $limit = is_numeric($rawLimit) ? (int) $rawLimit : 10;
            if (! in_array($limit, $allowedLimits, true)) {
                $limit = 10;
            }
            $offset = ($page - 1) * $limit;

            $rawSortBy = $_GET['sort_by'] ?? 'id';
            $sortBy = is_string($rawSortBy) ? $rawSortBy : 'id';
            $rawSortDir = $_GET['sort_dir'] ?? 'DESC';
            $sortDir = is_string($rawSortDir) ? $rawSortDir : 'DESC';

            $allowedSorts = ['id', 'app_name', 'status', 'attempts', 'available_at', 'created_at'];
            $allowedDirections = ['ASC', 'DESC'];

            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            if (! in_array(strtoupper($sortDir), $allowedDirections, true)) {
                $sortDir = 'DESC';
            }

            $rawSearch = $_GET['search'] ?? '';
            $rawStatus = $_GET['filter_status'] ?? 'all';
            $rawAppId = $_GET['filter_app_id'] ?? ($_GET['app_id'] ?? 'all');
            $filters = [
                'search' => is_string($rawSearch) ? trim($rawSearch) : '',
                'status' => is_string($rawStatus) && $rawStatus !== '' ? trim($rawStatus) : 'all',
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

    /**
     * Retries a specific failed or expired log queue job.
     */
    public function retry(string $id): never
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            $this->redirectWithFlash('/admin/log-queue', 'error', __('Error'), __('Invalid job ID.'));
        }

        try {
            $count = LogQueue::retryFailed($jobId, null, true);
            if ($count > 0) {
                $this->flash('success', __('Success'), __('Job #:id was reset and queued for immediate retry.', ['id' => (string) $jobId]));
            } else {
                $this->flash('error', __('Error'), __('Failed to reset job #:id. Ensure the job exists and is in failed status.', ['id' => (string) $jobId]));
            }
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue retry job failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'job_id' => $jobId,
                    'identifier' => 'LogQueueController_Retry_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to retry log queue job.'));
        }

        $this->redirect('/admin/log-queue');
    }

    /**
     * Resets all failed jobs in `log_queue` back to pending status.
     */
    public function retryAll(): never
    {
        $rawAppId = $_POST['app_id'] ?? null;
        $appId = is_numeric($rawAppId) && (int) $rawAppId > 0 ? (int) $rawAppId : null;

        try {
            $count = LogQueue::retryFailed(null, $appId);
            $this->flash('success', __('Success'), __(':count failed jobs reset and queued for retry.', ['count' => (string) $count]));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue bulk retry failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'LogQueueController_RetryAll_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to retry dead-letter log queue jobs.'));
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

    /**
     * Purges processed (`completed`, `failed`, `expired`) log queue jobs older than the configured threshold.
     */
    public function purge(): never
    {
        $rawDays = $_POST['days'] ?? null;
        $purgeDaysConfig = config('app.queue.purge_days');
        $defaultDays = is_numeric($purgeDaysConfig) ? (int) $purgeDaysConfig : 30;
        $days = is_numeric($rawDays) ? max(1, (int) $rawDays) : $defaultDays;

        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($days * 86400));
            $purgedCount = LogQueue::purgeProcessed($cutoffDate);
            $this->flash('success', __('Success'), __(':count processed jobs older than :days days purged successfully.', [
                'count' => (string) $purgedCount,
                'days' => (string) $days,
            ]));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Log queue purge failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'LogQueueController_Purge_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to purge processed log queue jobs.'));
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
