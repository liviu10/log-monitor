<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Models\QueueJob;
use RuntimeException;
use Throwable;

/**
 * Notifications Controller
 *
 * Displays delivered notifications (`status = 'completed'`) in a filterable timeline.
 */
class NotificationsController extends BaseController
{
    public function index(): string
    {
        try {
            $rawSearch = $_GET['search'] ?? '';
            $rawQueue = $_GET['queue'] ?? '';
            $filters = [
                'search' => is_string($rawSearch) ? trim($rawSearch) : '',
                'queue' => is_string($rawQueue) ? trim($rawQueue) : '',
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

            $allowedSorts = ['id', 'processed_at', 'created_at', 'queue'];
            $allowedDirections = ['ASC', 'DESC'];

            if (! in_array($sortBy, $allowedSorts, true)) {
                $sortBy = 'id';
            }
            if (! in_array(strtoupper($sortDir), $allowedDirections, true)) {
                $sortDir = 'DESC';
            }

            $result = QueueJob::getSentNotifications($filters, $limit, $offset, $sortBy, $sortDir);
            $totalNotifications = $result['total'];
            $totalPages = max(1, (int) ceil($totalNotifications / $limit));

            $stats = QueueJob::getSentStats();
            $queues = QueueJob::getDistinctCompletedQueues();

            return $this->render('admin.notifications.index', [
                'title' => __('Notifications'),
                'notifications' => $result['items'],
                'queues' => $queues,
                'filters' => $filters,
                'page' => $page,
                'totalNotifications' => $totalNotifications,
                'totalPages' => $totalPages,
                'stats' => $stats,
                'limit' => $limit,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Notifications index processing failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'NotificationsController_Index_Failure',
                ]);
            }

            throw new RuntimeException(__('Critical error loading sent notifications. Please try again later.'), 500, $e);
        }
    }
}
