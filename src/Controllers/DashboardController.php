<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;
use PDO;

/**
 * DashboardController Class
 *
 * Responsible for displaying and managing the main page of the control panel (Dashboard).
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
     * Displays the main dashboard page with dynamic system overview metrics.
     *
     * @param  array<array-key, mixed>  $queryParams
     */
    public function index(array $queryParams = []): void
    {
        $this->checkAuth();

        try {
            $db = MySQLWrapper::getInstance();

            // 1. Basic Counts
            $totalLogs = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
            
            $errorLogs = (int) $db->query("SELECT COUNT(*) FROM logs WHERE level IN ('ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT')")->fetchColumn();
            $warningLogs = (int) $db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
            
            $infoLogs = $totalLogs - ($errorLogs + $warningLogs);
            if ($infoLogs < 0) {
                $infoLogs = 0;
            }

            $successRate = $totalLogs > 0 ? round((($totalLogs - $errorLogs) / $totalLogs) * 100, 1) : 100.0;
            
            $queueSize = (int) $db->query('SELECT COUNT(*) FROM log_queue')->fetchColumn();
            $activeApps = (int) $db->query('SELECT COUNT(*) FROM apps')->fetchColumn();

            // 2. Percentages for Severity Distribution
            $errorPct = $totalLogs > 0 ? round(($errorLogs / $totalLogs) * 100, 1) : 0.0;
            $warningPct = $totalLogs > 0 ? round(($warningLogs / $totalLogs) * 100, 1) : 0.0;
            $infoPct = $totalLogs > 0 ? round(($infoLogs / $totalLogs) * 100, 1) : 0.0;

            // 3. Lists
            // Latest Logs Activity (5 items)
            $latestLogs = $db->query('SELECT l.*, a.name as app_name FROM logs l JOIN apps a ON l.app_id = a.id ORDER BY l.id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Top Apps by Log Volume (5 items)
            $topApps = $db->query('SELECT a.name as app_name, COUNT(l.id) as log_count, MAX(l.created_at) as last_logged_at FROM apps a LEFT JOIN logs l ON l.app_id = a.id GROUP BY a.id, a.name ORDER BY log_count DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Pending Queue Preview (5 items)
            $pendingQueue = $db->query('SELECT q.*, a.name as app_name FROM log_queue q LEFT JOIN apps a ON q.app_id = a.id ORDER BY q.id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->render('dashboard/index', [
                'totalLogs' => $totalLogs,
                'errorLogs' => $errorLogs,
                'warningLogs' => $warningLogs,
                'infoLogs' => $infoLogs,
                'successRate' => $successRate,
                'queueSize' => $queueSize,
                'activeApps' => $activeApps,
                'errorPct' => $errorPct,
                'warningPct' => $warningPct,
                'infoPct' => $infoPct,
                'latestLogs' => $latestLogs,
                'topApps' => $topApps,
                'pendingQueue' => $pendingQueue,
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
                    'identifier' => 'DashboardController_Index_Failure',
                ]);
            }

            throw new \RuntimeException(__('Critical error loading dashboard data. Please try again later.'));
        }
    }
}
