<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Settings;

use App\Controllers\Common\QueueController;
use App\Core\BaseController;
use App\Models\QueueJob;

/**
 * Queue Management Controller
 *
 * Provides administrative visibility and operational control over asynchronous queue jobs,
 * failure recovery, dead-letter re-attempts, worker executions, and housekeeping.
 *
 * @category Controllers
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class QueueManagerController extends BaseController
{
    /**
     * Lists queue jobs with real-time stats, filtering, sorting, and pagination.
     */
    public function index(): string
    {
        $this->checkPermission('admin.settings.queuemanager.index');

        $rawPage = $_GET['page'] ?? 1;
        $page = is_numeric($rawPage) ? max(1, (int) $rawPage) : 1;

        $rawPerPage = $_GET['per_page'] ?? 10;
        $perPage = is_numeric($rawPerPage) ? max(5, (int) $rawPerPage) : 10;

        $rawSort = $_GET['sort'] ?? 'id';
        $sortBy = is_string($rawSort) && $rawSort !== '' ? $rawSort : 'id';

        $rawOrder = $_GET['order'] ?? 'desc';
        $order = is_string($rawOrder) && strtolower($rawOrder) === 'asc' ? 'asc' : 'desc';

        $rawSearch = $_GET['search'] ?? '';
        $search = is_string($rawSearch) ? trim($rawSearch) : '';

        $rawFilterStatus = $_GET['filter_status'] ?? 'all';
        $filterStatus = is_string($rawFilterStatus) ? trim($rawFilterStatus) : 'all';

        $rawFilterQueue = $_GET['filter_queue'] ?? 'all';
        $filterQueue = is_string($rawFilterQueue) ? trim($rawFilterQueue) : 'all';

        $result = QueueJob::getPaginatedFilteredJobs(
            filters: [
                'search' => $search,
                'status' => $filterStatus,
                'queue' => $filterQueue,
            ],
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDesc: $order === 'desc'
        );

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        $pagination = [
            'items' => $result['items'],
            'total_items' => $result['total'],
            'total_pages' => $totalPages,
            'current_page' => $page,
            'per_page' => $perPage,
            'sort' => $sortBy,
            'order' => $order,
            'search' => $search,
        ];

        $stats = QueueJob::getStats();
        $distinctQueues = QueueJob::getDistinctQueues();
        $isWorkerRunning = $this->isWorkerActive();

        return $this->render('admin.settings.queue.index', [
            'items' => $result['items'],
            'pagination' => $pagination,
            'stats' => $stats,
            'distinctQueues' => $distinctQueues,
            'isWorkerRunning' => $isWorkerRunning,
            'resource' => 'queue',
        ]);
    }

    /**
     * Retries a specific failed queue job.
     */
    public function retry(string $id): never
    {
        $this->checkPermission('admin.settings.queuemanager.retry');

        $jobId = (int) $id;
        $count = QueueJob::retryFailed($jobId);

        if ($count > 0) {
            $this->flash('success', __('Success'), __('Job #:id was reset and queued for immediate retry.', ['id' => (string) $jobId]));
        } else {
            $this->flash('error', __('Error'), __('Failed to reset job #:id. Ensure the job exists and is in failed status.', ['id' => (string) $jobId]));
        }

        $this->redirect('/admin/settings/queue');
    }

    /**
     * Retries all failed queue jobs after pre-flight health checks.
     */
    public function retryAll(): never
    {
        $this->checkPermission('admin.settings.queuemanager.retryall');

        $rawQueue = $_POST['queue'] ?? null;
        $queue = is_string($rawQueue) && $rawQueue !== '' && $rawQueue !== 'all' ? $rawQueue : null;

        $smtpOk = QueueController::checkSmtpHealth();
        $count = QueueJob::retryFailed(null, $queue);

        if (! $smtpOk) {
            $this->flash('warning', __('Warning'), __(':count jobs reset, but SMTP pre-flight test failed. Check SMTP configuration.', ['count' => (string) $count]));
        } else {
            $this->flash('success', __('Success'), __(':count failed jobs reset and queued for retry.', ['count' => (string) $count]));
        }

        $this->redirect('/admin/settings/queue');
    }

    /**
     * Deletes a specific queue job.
     */
    public function delete(string $id): never
    {
        $this->checkPermission('admin.settings.queuemanager.delete');

        $jobId = (int) $id;
        $success = QueueJob::deleteJob($jobId);

        if ($success) {
            $this->flash('success', __('Success'), __('Job #:id deleted successfully.', ['id' => (string) $jobId]));
        } else {
            $this->flash('error', __('Error'), __('Unable to delete job #:id.', ['id' => (string) $jobId]));
        }

        $this->redirect('/admin/settings/queue');
    }

    /**
     * Manually triggers a worker processing cycle.
     */
    public function runWorker(): never
    {
        $this->checkPermission('admin.settings.queuemanager.runworker');

        $queueCtrl = new QueueController;
        $queueCtrl->processQueue();

        $this->flash('success', __('Success'), __('Queue worker execution cycle completed successfully.'));
        $this->redirect('/admin/settings/queue');
    }

    /**
     * Purges processed jobs older than the configured threshold.
     */
    public function purge(): never
    {
        $this->checkPermission('admin.settings.queuemanager.purge');

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

        $this->redirect('/admin/settings/queue');
    }

    /**
     * Inspects the worker lock file to determine if a worker daemon is currently executing.
     */
    public function isWorkerActive(): bool
    {
        $lockFile = dirname(__DIR__, 4).'/storage/views/worker.lock';

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
