<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;
use PDO;

/**
 * QueueController Class
 *
 * Responsible for managing the page and operations within the Queue Manager.
 * Allows listing jobs in the queue, individual deletion, complete purging of the queue,
 * and checking the status of the background worker.
 *
 * @category Controller
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class QueueController extends BaseController
{
    /**
     * Displays the active queue view page.
     *
     * @param  array<array-key, mixed>  $queryParams  Filtering and pagination parameters.
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        try {
            $db = MySQLWrapper::getInstance();

            // Pagination configuration
            $rawPage = $queryParams['page'] ?? 1;
            $page = (is_int($rawPage) || is_string($rawPage)) ? (int) $rawPage : 1;
            if ($page < 1) {
                $page = 1;
            }

            $allowedLimits = [10, 25, 50, 100];
            $rawLimit = $queryParams['limit'] ?? 10;
            $limit = (is_int($rawLimit) || is_string($rawLimit)) ? (int) $rawLimit : 10;
            if (! in_array($limit, $allowedLimits, true)) {
                $limit = 10;
            }
            $offset = ($page - 1) * $limit;

            // Get sorting parameters from URL
            $rawSortBy = $queryParams['sort_by'] ?? 'id';
            $sortBy = is_string($rawSortBy) ? $rawSortBy : 'id';
            $rawSortDir = $queryParams['sort_dir'] ?? 'DESC';
            $sortDir = is_string($rawSortDir) ? $rawSortDir : 'DESC';

            $allowedSorts = ['id', 'created_at', 'app_name'];
            $allowedDirections = ['ASC', 'DESC'];

            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            if (! in_array(strtoupper($sortDir), $allowedDirections, true)) {
                $sortDir = 'DESC';
            }
            $sortOrder = strtoupper($sortDir);

            if ($sortBy === 'app_name') {
                $orderClause = "ORDER BY a.name {$sortOrder}";
            } elseif ($sortBy === 'created_at') {
                $orderClause = "ORDER BY q.created_at {$sortOrder}, q.id {$sortOrder}";
            } else {
                $orderClause = "ORDER BY q.id {$sortOrder}";
            }

            // Get the total number of jobs in the queue
            $stmtCount = $db->query('SELECT COUNT(*) FROM log_queue');
            $totalJobs = (int) $stmtCount->fetchColumn();
            $totalPages = (int) ceil($totalJobs / $limit);

            // Get elements from queue with JOIN on applications
            // Secure LIMIT and OFFSET by interpolating as integers
            $sql = "SELECT q.id, q.app_id, q.payload_raw, q.created_at, a.name as app_name 
                    FROM log_queue q 
                    LEFT JOIN apps a ON q.app_id = a.id 
                    {$orderClause} 
                    LIMIT ".(int) $limit.' OFFSET '.(int) $offset;

            $stmtJobs = $db->query($sql);
            $jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Verify background worker status
            $output = [];
            $returnVar = 0;
            $isWorkerRunning = false;
            $heartbeatFile = dirname(__DIR__, 2).'/storage/worker.heartbeat';

            if (file_exists($heartbeatFile)) {
                try {
                    $lastSeenRaw = @file_get_contents($heartbeatFile);
                    if ($lastSeenRaw !== false) {
                        $lastSeenTimestamp = (int) $lastSeenRaw;
                        $isWorkerRunning = (time() - $lastSeenTimestamp) <= 30;
                    }
                } catch (\Throwable) {
                    $isWorkerRunning = false;
                }
            }

            $this->render('queue/index', [
                'jobs' => $jobs,
                'totalJobs' => $totalJobs,
                'totalPages' => $totalPages,
                'page' => $page,
                'limit' => $limit,
                'isWorkerRunning' => $isWorkerRunning,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Queue index processing failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'QueueController_Index_Failure',
                ]);
            }

            throw new \RuntimeException(__('Critical error loading queue data. Please try again later.'));
        }
    }

    /**
     * Deletes a single specific job from the queue.
     *
     * @param  array<array-key, mixed>  $postData  The array of data passed via POST.
     * @return never Redirects back to the queue page.
     */
    public function delete(array $postData): never
    {
        $this->checkAuth();

        $rawId = $postData['id'] ?? 0;
        $id = (is_int($rawId) || is_string($rawId)) ? (int) $rawId : 0;
        if ($id <= 0) {
            setFlash('danger', __('Error'), __('Invalid job ID.'));
            $this->redirect('queue.php');
        }

        try {
            $db = MySQLWrapper::getInstance();
            $db->delete('log_queue', ['id' => $id]);
            setFlash('success', __('Success'), __('Job has been deleted from queue.'));
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Queue delete job failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'job_id' => $id,
                    'identifier' => 'QueueController_DeleteJob_Failure',
                ]);
            }

            setFlash('danger', __('Error'), __('Failed to delete job from queue.'));
        }

        $this->redirect('queue.php');
    }

    /**
     * Completely purges the queue.
     *
     * @return never Redirects back to the queue page.
     */
    public function purge(): never
    {
        $this->checkAuth();

        try {
            $db = MySQLWrapper::getInstance();
            // Clear log_queue table transactionally via TRUNCATE
            $db->getConnection()->exec('TRUNCATE TABLE log_queue');
            setFlash('success', __('Success'), __('The queue has been completely purged.'));
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Queue purge failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'identifier' => 'QueueController_Purge_Failure',
                ]);
            }

            setFlash('danger', __('Error'), __('Failed to purge the queue.'));
        }

        $this->redirect('queue.php');
    }
}
