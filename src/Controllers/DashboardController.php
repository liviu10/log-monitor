<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Utilities\Cache;
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

            $cacheDir = dirname(__DIR__, 2).'/storage/framework/cache';
            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }
            $cache = new Cache($cacheDir);

            // Cache heavy aggregation queries for 15 seconds to maximize dashboard throughput
            $metrics = $cache->remember('dashboard_overview_metrics', 15, static function () use ($db): array {
                $total = (int) $db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
                $errors = (int) $db->query("SELECT COUNT(*) FROM logs WHERE level IN ('ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT')")->fetchColumn();
                $warnings = (int) $db->query("SELECT COUNT(*) FROM logs WHERE level = 'WARNING'")->fetchColumn();
                $info = max(0, $total - ($errors + $warnings));
                $rate = $total > 0 ? round((($total - $errors) / $total) * 100, 1) : 100.0;
                $queue = (int) $db->query('SELECT COUNT(*) FROM log_queue')->fetchColumn();
                $apps = (int) $db->query('SELECT COUNT(*) FROM apps')->fetchColumn();

                $ePct = $total > 0 ? round(($errors / $total) * 100, 1) : 0.0;
                $wPct = $total > 0 ? round(($warnings / $total) * 100, 1) : 0.0;
                $iPct = $total > 0 ? round(($info / $total) * 100, 1) : 0.0;

                $top = $db->query('SELECT a.name as app_name, COUNT(l.id) as log_count, MAX(l.created_at) as last_logged_at FROM apps a LEFT JOIN logs l ON l.app_id = a.id GROUP BY a.id, a.name ORDER BY log_count DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];

                return [
                    'totalLogs' => $total,
                    'errorLogs' => $errors,
                    'warningLogs' => $warnings,
                    'infoLogs' => $info,
                    'successRate' => $rate,
                    'queueSize' => $queue,
                    'activeApps' => $apps,
                    'errorPct' => $ePct,
                    'warningPct' => $wPct,
                    'infoPct' => $iPct,
                    'topApps' => $top,
                ];
            });
            assert(is_array($metrics));

            // Live items (fast indexed queries)
            $latestLogs = $db->query('SELECT l.*, a.name as app_name FROM logs l JOIN apps a ON l.app_id = a.id ORDER BY l.id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $pendingQueue = $db->query('SELECT q.*, a.name as app_name FROM log_queue q LEFT JOIN apps a ON q.app_id = a.id ORDER BY q.id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->render('dashboard/index', [
                'totalLogs' => $metrics['totalLogs'],
                'errorLogs' => $metrics['errorLogs'],
                'warningLogs' => $metrics['warningLogs'],
                'infoLogs' => $metrics['infoLogs'],
                'successRate' => $metrics['successRate'],
                'queueSize' => $metrics['queueSize'],
                'activeApps' => $metrics['activeApps'],
                'errorPct' => $metrics['errorPct'],
                'warningPct' => $metrics['warningPct'],
                'infoPct' => $metrics['infoPct'],
                'latestLogs' => $latestLogs,
                'topApps' => $metrics['topApps'],
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
