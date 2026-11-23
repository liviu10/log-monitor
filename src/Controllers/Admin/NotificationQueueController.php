<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Common\QueueController;
use App\Core\BaseController;
use App\Models\QueueJob;

/**
 * Notification Queue Controller
 *
 * Provides administrative visibility and operational control over asynchronous notification
 * queue jobs, failure recovery, dead-letter retries, worker execution, and housekeeping.
 */
class NotificationQueueController extends BaseController
{
    /**
     * Lists notification queue jobs with real-time stats, filtering, sorting, and pagination.
     */
    public function index(): string
    {
        $this->checkPermission('admin.notificationqueue.index');

        $rawPage = $_GET['page'] ?? 1;
        $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

        $allowedLimits = [10, 25, 50, 100];
        $rawPerPage = $_GET['limit'] ?? ($_GET['per_page'] ?? 10);
        $limit = is_numeric($rawPerPage) ? (int) $rawPerPage : 10;
        if (! in_array($limit, $allowedLimits, true)) {
            $limit = 10;
        }

        $rawSort = $_GET['sort_by'] ?? ($_GET['sort'] ?? 'id');
        $sortBy = is_string($rawSort) && $rawSort !== '' ? $rawSort : 'id';

        $rawOrder = $_GET['sort_dir'] ?? ($_GET['order'] ?? 'DESC');
        $sortDir = is_string($rawOrder) && strtoupper($rawOrder) === 'ASC' ? 'ASC' : 'DESC';

        $allowedSorts = ['id', 'queue', 'status', 'attempts', 'available_at', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $rawSearch = $_GET['search'] ?? '';
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        $rawFilterStatus = $_GET['filter_status'] ?? 'all';
        $filterStatus = is_string($rawFilterStatus) && $rawFilterStatus !== '' ? trim($rawFilterStatus) : 'all';

        $rawFilterQueue = $_GET['filter_queue'] ?? 'all';
        $filterQueue = is_string($rawFilterQueue) && $rawFilterQueue !== '' ? trim($rawFilterQueue) : 'all';

        $filters = [
            'search' => $search,
            'status' => $filterStatus,
            'queue' => $filterQueue,
        ];

        $result = QueueJob::getPaginatedFilteredJobs(
            filters: $filters,
            page: $page,
            perPage: $limit,
            sortBy: $sortBy,
            sortDesc: $sortDir === 'DESC'
        );

        $totalJobs = $result['total'];
        $totalPages = max(1, (int) ceil($totalJobs / $limit));
        $stats = QueueJob::getStats();
        $distinctQueues = QueueJob::getDistinctQueues();
        $isWorkerRunning = $this->isWorkerActive();

        return $this->render('admin.notification-queue.index', [
            'title' => __('Notification Queue'),
            'jobs' => $result['items'],
            'totalJobs' => $totalJobs,
            'totalPages' => $totalPages,
            'page' => $page,
            'limit' => $limit,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'filters' => $filters,
            'stats' => $stats,
            'distinctQueues' => $distinctQueues,
            'isWorkerRunning' => $isWorkerRunning,
        ]);
    }

    /**
     * Retries a specific failed queue job.
     */
    public function retry(string $id): never
    {
        $this->checkPermission('admin.notificationqueue.retry');

        $jobId = (int) $id;
        $count = QueueJob::retryFailed($jobId);

        if ($count > 0) {
            $this->flash('success', __('Success'), __('Job #:id was reset and queued for immediate retry.', ['id' => (string) $jobId]));
        } else {
            $this->flash('error', __('Error'), __('Failed to reset job #:id. Ensure the job exists and is in failed status.', ['id' => (string) $jobId]));
        }

        $this->redirect('/admin/notification-queue');
    }

    /**
     * Retries all failed queue jobs after pre-flight health checks.
     */
    public function retryAll(): never
    {
        $this->checkPermission('admin.notificationqueue.retryall');

        $rawQueue = $_POST['queue'] ?? null;
        $queue = is_string($rawQueue) && $rawQueue !== '' && $rawQueue !== 'all' ? $rawQueue : null;

        $smtpOk = QueueController::checkSmtpHealth();
        $count = QueueJob::retryFailed(null, $queue);

        if (! $smtpOk) {
            $this->flash('warning', __('Warning'), __(':count jobs reset, but SMTP pre-flight test failed. Check SMTP configuration.', ['count' => (string) $count]));
        } else {
            $this->flash('success', __('Success'), __(':count failed jobs reset and queued for retry.', ['count' => (string) $count]));
        }

        $this->redirect('/admin/notification-queue');
    }

    /**
     * Deletes a specific queue job.
     */
    public function delete(string $id): never
    {
        $this->checkPermission('admin.notificationqueue.delete');

        $jobId = (int) $id;
        $success = QueueJob::deleteJob($jobId);

        if ($success) {
            $this->flash('success', __('Success'), __('Job #:id deleted successfully.', ['id' => (string) $jobId]));
        } else {
            $this->flash('error', __('Error'), __('Unable to delete job #:id.', ['id' => (string) $jobId]));
        }

        $this->redirect('/admin/notification-queue');
    }

    /**
     * Manually triggers a worker processing cycle.
     */
    public function runWorker(): never
    {
        $this->checkPermission('admin.notificationqueue.runworker');

        $queueCtrl = new QueueController;
        $queueCtrl->processQueue();

        $this->flash('success', __('Success'), __('Queue worker execution cycle completed successfully.'));
        $this->redirect('/admin/notification-queue');
    }

    /**
     * Purges processed jobs older than the configured threshold.
     */
    public function purge(): never
    {
        $this->checkPermission('admin.notificationqueue.purge');

        $rawDays = $_POST['days'] ?? null;
        $purgeDaysConfig = config('app.queue.purge_days');
        $defaultDays = is_numeric($purgeDaysConfig) ? (int) $purgeDaysConfig : 30;
        $days = is_numeric($rawDays) ? max(1, (int) $rawDays) : $defaultDays;

        $queueCtrl = new QueueController;
        $purgedCount = $queueCtrl->purge($days);

        $this->flash('success', __('Success'), __(':count processed jobs older than :days days purged successfully.', [
            'count' => (string) $purgedCount,
            'days' => (string) $days,
        ]));

        $this->redirect('/admin/notification-queue');
    }

    /**
     * Inspects the worker lock file or recent daemon heartbeat to determine if the worker is active.
     */
    public function isWorkerActive(): bool
    {
        $heartbeatFile = \storage_path('queue_worker.heartbeat');
        if (file_exists($heartbeatFile)) {
            $lastSeenRaw = @file_get_contents($heartbeatFile);
            if (is_string($lastSeenRaw) && is_numeric(trim($lastSeenRaw)) && (time() - (int) trim($lastSeenRaw)) <= 30) {
                return true;
            }
        }

        $lockFile = dirname(__DIR__, 3).'/storage/views/worker.lock';

        if (! file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        $locked = ! @flock($fp, LOCK_EX | LOCK_NB);
        if (! $locked) {
            @flock($fp, LOCK_UN);
        }
        @fclose($fp);

        return $locked;
    }
}
