<?php

namespace App\Controllers;

use App\Models\Log;
use App\Models\App;
use App\Enums\LogLevel;

/**
 * Clasa DashboardController
 *
 * Responsabila pentru afisarea si gestionarea paginii principale a panoului de control (Dashboard).
 * Se ocupa de preluarea filtrelor de cautare, gestionarea paginarii si recuperarea datelor necesare 
 * pentru vizualizarea logurilor sistemului intr-un mod organizat.
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
     * Afiseaza pagina principala a dashboard-ului cu lista de loguri filtrate.
     */
    public function index(): void
    {
        $this->checkAuth();

        $logModel = new Log();
        $appModel = new App();

        // Preluarea filtrelor din query string
        $filters = [
            'app_id' => $_GET['app_id'] ?? null,
            'level' => $_GET['level'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        // Configurare paginare
        $page = (int)($_GET['page'] ?? 1);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Recuperare date
        $logs = $logModel->getPaginated($filters, $limit, $offset);
        $totalLogs = $logModel->count($filters);
        $totalPages = (int)ceil($totalLogs / $limit);
        
        $apps = $appModel->getAll();
        $levels = LogLevel::all();

        $this->render('dashboard/index', [
            'logs' => $logs,
            'apps' => $apps,
            'levels' => $levels,
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}
