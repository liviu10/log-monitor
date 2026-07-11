<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\App;
use App\Models\Log;
use App\Utilities\LogViaStream;

/**
 * DashboardController Class
 *
 * Responsible for displaying and managing the main page of the control panel (Dashboard).
 * Manages fetching search filters, configuring pagination, and retrieving necessary
 * data for viewing the system logs in an organized manner.
 *
 * @category Controller
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class DashboardController extends BaseController
{
    /**
     * Displays the main dashboard page with the list of filtered logs.
     *
     * @param  array<array-key, mixed>  $queryParams
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        try {
            $logModel = new Log;
            $appModel = new App;

            $filters = [
                'app_id' => $queryParams['app_id'] ?? null,
                'level' => $queryParams['level'] ?? null,
                'search' => $queryParams['search'] ?? null,
            ];

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

            $rawSortBy = $queryParams['sort_by'] ?? 'id';
            $sortBy = is_string($rawSortBy) ? $rawSortBy : 'id';
            $rawSortDir = $queryParams['sort_dir'] ?? 'DESC';
            $sortDir = is_string($rawSortDir) ? $rawSortDir : 'DESC';

            $logs = $logModel->getPaginated($filters, $limit, $offset, $sortBy, $sortDir);
            $totalLogs = $logModel->count($filters);
            $totalPages = (int) ceil($totalLogs / $limit);

            $apps = $appModel->getAll();
            $levels = LogLevel::all();
            $stats = $logModel->getStats();

            $this->render('dashboard/index', [
                'logs' => $logs,
                'apps' => $apps,
                'levels' => $levels,
                'filters' => $filters,
                'page' => $page,
                'totalPages' => $totalPages,
                'stats' => $stats,
                'limit' => $limit,
                'sortBy' => $sortBy,
                'sortDir' => $sortDir,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Dashboard index processing failure', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'query_params' => $queryParams,
                    'identifier' => 'DashboardController_Index_Failure',
                ]);
            }

            // Defensive Fail Fast: do not allow loading a partial page with incomplete data
            throw new \RuntimeException(__('Critical error loading dashboard data. Please try again later.'));
        }
    }
}
