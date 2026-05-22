<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Log;
use App\Models\App;
use App\Enums\LogLevel;
use App\Utilities\LogViaCurl;

/**
 * Clasa DashboardController
 *
 * Responsabila pentru afisarea si gestionarea paginii principale a panoului de control (Dashboard).
 * Gestioneaza preluarea filtrelor de cautare, configurarea paginatiei si recuperarea datelor
 * necesare pentru vizualizarea logurilor sistemului intr-un mod organizat.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class DashboardController extends BaseController
{
    /**
     * Afiseaza pagina principala de dashboard cu lista logurilor filtrate.
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        try {
            $logModel = new Log();
            $appModel = new App();

            $filters = [
                'app_id' => $queryParams['app_id'] ?? null,
                'level' => $queryParams['level'] ?? null,
                'search' => $queryParams['search'] ?? null,
            ];

            $page = (int)($queryParams['page'] ?? 1);
            if ($page < 1) {
                $page = 1;
            }
            
            $limit = 50;
            $offset = ($page - 1) * $limit;

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
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Dashboard index processing failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'query_params' => $queryParams,
                'identifier' => 'DashboardController_Index_Failure'
            ]);
            
            // Fail Fast defensiv: nu se permite incarcarea paginii partiale cu date incomplete
            throw new \RuntimeException(__('Critical error loading dashboard data. Please try again later.'));
        }
    }
}