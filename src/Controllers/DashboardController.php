<?php

namespace App\Controllers;

use App\Models\Log;
use App\Models\App;
use App\Enums\LogLevel;

/**
 * DashboardController Class
 *
 * Responsible for displaying and managing the main page of the control panel (Dashboard).
 * Handles the retrieval of search filters, management of pagination, and recovery of the necessary data 
 * for viewing system logs in an organized manner.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class DashboardController extends BaseController
{
    /**
     * Displays the main dashboard page with the filtered logs list.
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        $logModel = new Log();
        $appModel = new App();

        // Retrieve filters from query string
        $filters = [
            'app_id' => $queryParams['app_id'] ?? null,
            'level' => $queryParams['level'] ?? null,
            'search' => $queryParams['search'] ?? null,
        ];

        // Pagination configuration
        $page = (int)($queryParams['page'] ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Retrieve data
        $logs = $logModel->getPaginated($filters, $limit, $offset);
        $totalLogs = $logModel->count($filters);
        $totalPages = (int)ceil($totalLogs / $limit);
        
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
        ]);
    }
}
