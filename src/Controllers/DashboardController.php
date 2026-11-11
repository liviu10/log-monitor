<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Utilities\Cache;
use App\Utilities\LogViaStream;
use App\Utilities\MySQL;
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
            $db = MySQL::getInstance();

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

            // Compute initial analytics data for charts (default: last 24 hours / day)
            $chartData = $this->getAnalyticsData('day');

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
                'chartData' => $chartData,
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

    /**
     * AJAX endpoint to return JSON chart analytics for a given period or custom date range.
     *
     * @param  array<array-key, mixed>  $queryParams
     */
    public function chartData(array $queryParams = []): never
    {
        $this->checkAuth();

        $rawPeriod = $queryParams['period'] ?? null;
        $period = is_string($rawPeriod) ? $rawPeriod : 'day';

        $rawStart = $queryParams['start_date'] ?? null;
        $startDate = is_string($rawStart) ? $rawStart : null;

        $rawEnd = $queryParams['end_date'] ?? null;
        $endDate = is_string($rawEnd) ? $rawEnd : null;

        $data = $this->getAnalyticsData($period, $startDate, $endDate);

        $this->jsonResponse($data);
    }

    /**
     * Retrieves aggregated analytics data for dashboard charts based on a period.
     *
     * @param  string  $period  'day', 'month', 'year', or 'custom'
     * @param  string|null  $startDate  Start date string for custom period.
     * @param  string|null  $endDate  End date string for custom period.
     * @return array<string, mixed>
     */
    public function getAnalyticsData(string $period = 'day', ?string $startDate = null, ?string $endDate = null): array
    {
        $db = MySQL::getInstance();

        // Determine date range and time grouping format based on selected period
        $slots = [];
        $labels = [];

        switch ($period) {
            case 'month':
                $startSql = date('Y-m-d 00:00:00', strtotime('-29 days'));
                $endSql = date('Y-m-d 23:59:59');
                $sqlSlotFormat = '%Y-%m-%d';
                $rangeLabel = date('d M Y', strtotime('-29 days')).' - '.date('d M Y');

                for ($i = 29; $i >= 0; $i--) {
                    $ts = strtotime("-$i days");
                    $key = date('Y-m-d', $ts);
                    $slots[$key] = ['total' => 0, 'errors' => 0];
                    $labels[$key] = date('d M', $ts);
                }
                break;

            case 'year':
                $startSql = date('Y-m-01 00:00:00', strtotime('-11 months'));
                $endSql = date('Y-m-t 23:59:59');
                $sqlSlotFormat = '%Y-%m';
                $rangeLabel = date('M Y', strtotime('-11 months')).' - '.date('M Y');

                for ($i = 11; $i >= 0; $i--) {
                    $ts = strtotime("-$i months");
                    $key = date('Y-m', $ts);
                    $slots[$key] = ['total' => 0, 'errors' => 0];
                    $labels[$key] = date('M Y', $ts);
                }
                break;

            case 'custom':
                $parsedStart = $startDate ? strtotime($startDate) : false;
                $parsedEnd = $endDate ? strtotime($endDate) : false;

                if ($parsedStart === false || $parsedEnd === false || $parsedStart > $parsedEnd) {
                    $startTs = (int) strtotime('-6 days 00:00:00');
                    $endTs = time();
                } else {
                    $startTs = $parsedStart;
                    $endTs = $parsedEnd;
                }

                if (strlen($startDate ?? '') <= 10) {
                    $dayStart = strtotime(date('Y-m-d 00:00:00', $startTs));
                    if ($dayStart !== false) {
                        $startTs = $dayStart;
                    }
                }
                if (strlen($endDate ?? '') <= 10) {
                    $dayEnd = strtotime(date('Y-m-d 23:59:59', $endTs));
                    if ($dayEnd !== false) {
                        $endTs = $dayEnd;
                    }
                }

                $startSql = date('Y-m-d H:i:s', $startTs);
                $endSql = date('Y-m-d H:i:s', $endTs);
                $diffDays = ($endTs - $startTs) / 86400;
                $rangeLabel = date('d M Y', $startTs).' - '.date('d M Y', $endTs);

                if ($diffDays <= 2) {
                    $sqlSlotFormat = '%Y-%m-%d %H:00';
                    $firstSlot = strtotime(date('Y-m-d H:00:00', $startTs));
                    $current = ($firstSlot !== false) ? $firstSlot : $startTs;
                    while ($current <= $endTs) {
                        $key = date('Y-m-d H:00', $current);
                        $slots[$key] = ['total' => 0, 'errors' => 0];
                        $labels[$key] = date('d M H:00', $current);
                        $current = (int) strtotime('+1 hour', $current);
                    }
                } elseif ($diffDays <= 60) {
                    $sqlSlotFormat = '%Y-%m-%d';
                    $firstSlot = strtotime(date('Y-m-d', $startTs));
                    $current = ($firstSlot !== false) ? $firstSlot : $startTs;
                    while ($current <= $endTs) {
                        $key = date('Y-m-d', $current);
                        $slots[$key] = ['total' => 0, 'errors' => 0];
                        $labels[$key] = date('d M', $current);
                        $current = (int) strtotime('+1 day', $current);
                    }
                } else {
                    $sqlSlotFormat = '%Y-%m';
                    $firstSlot = strtotime(date('Y-m-01', $startTs));
                    $current = ($firstSlot !== false) ? $firstSlot : $startTs;
                    while ($current <= $endTs) {
                        $key = date('Y-m', $current);
                        $slots[$key] = ['total' => 0, 'errors' => 0];
                        $labels[$key] = date('M Y', $current);
                        $current = (int) strtotime('+1 month', $current);
                    }
                }
                break;

            case 'day':
            default:
                $period = 'day';
                $startSql = date('Y-m-d H:00:00', strtotime('-23 hours'));
                $endSql = date('Y-m-d H:59:59');
                $sqlSlotFormat = '%Y-%m-%d %H:00';
                $rangeLabel = date('H:i d M', strtotime('-23 hours')).' - '.date('H:i d M');

                for ($i = 23; $i >= 0; $i--) {
                    $ts = strtotime("-$i hours");
                    $key = date('Y-m-d H:00', $ts);
                    $slots[$key] = ['total' => 0, 'errors' => 0];
                    $labels[$key] = date('H:00', $ts);
                }
                break;
        }

        // 1. Line Chart Data: Log volume over time (Total and Errors)
        $sql = "
            SELECT 
                DATE_FORMAT(created_at, '{$sqlSlotFormat}') as slot,
                COUNT(*) as total_count,
                SUM(CASE WHEN level IN ('ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT') THEN 1 ELSE 0 END) as error_count
            FROM logs
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY slot
            ORDER BY slot ASC
        ";
        /** @var list<array{slot: string, total_count: int|string, error_count: int|string}> $volumeRows */
        $volumeRows = $db->fetchAll($sql, [$startSql, $endSql]);

        foreach ($volumeRows as $row) {
            $slotKey = (string) $row['slot'];
            if (isset($slots[$slotKey])) {
                $slots[$slotKey]['total'] = (int) $row['total_count'];
                $slots[$slotKey]['errors'] = (int) $row['error_count'];
            } else {
                $slots[$slotKey] = [
                    'total' => (int) $row['total_count'],
                    'errors' => (int) $row['error_count'],
                ];
                $labels[$slotKey] = $slotKey;
            }
        }

        // 2. Pie / Doughnut Chart Data: Severity distribution
        /** @var list<array{level: string, count: int|string}> $sevRows */
        $sevRows = $db->fetchAll('
            SELECT level, COUNT(*) as count
            FROM logs
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY level
            ORDER BY count DESC
        ', [$startSql, $endSql]);

        $colorMap = [
            'EMERGENCY' => '#b91c1c',
            'ALERT' => '#dc2626',
            'CRITICAL' => '#ea580c',
            'ERROR' => '#ef4444',
            'WARNING' => '#f59e0b',
            'NOTICE' => '#10b981',
            'INFO' => '#06b6d4',
            'DEBUG' => '#64748b',
        ];

        $sevLabels = [];
        $sevData = [];
        $sevColors = [];
        foreach ($sevRows as $row) {
            $lvl = strtoupper((string) $row['level']);
            $sevLabels[] = $lvl;
            $sevData[] = (int) $row['count'];
            $sevColors[] = $colorMap[$lvl] ?? '#8b5cf6';
        }

        // 3. Bar Chart Data: Top applications by log volume
        /** @var list<array{app_name: string, log_count: int|string}> $appRows */
        $appRows = $db->fetchAll('
            SELECT a.name as app_name, COUNT(l.id) as log_count
            FROM logs l
            JOIN apps a ON l.app_id = a.id
            WHERE l.created_at >= ? AND l.created_at <= ?
            GROUP BY a.id, a.name
            ORDER BY log_count DESC
            LIMIT 10
        ', [$startSql, $endSql]);

        $appLabels = [];
        $appData = [];
        foreach ($appRows as $row) {
            $appLabels[] = (string) $row['app_name'];
            $appData[] = (int) $row['log_count'];
        }

        $totalInRange = (int) array_sum(array_column($slots, 'total'));
        $errorsInRange = (int) array_sum(array_column($slots, 'errors'));

        return [
            'period' => $period,
            'range' => [
                'start' => $startSql,
                'end' => $endSql,
                'label' => $rangeLabel,
            ],
            'lineChart' => [
                'labels' => array_values($labels),
                'total' => array_column($slots, 'total'),
                'errors' => array_column($slots, 'errors'),
            ],
            'severityPie' => [
                'labels' => $sevLabels,
                'data' => $sevData,
                'colors' => $sevColors,
            ],
            'appsBar' => [
                'labels' => $appLabels,
                'data' => $appData,
            ],
            'summary' => [
                'total' => $totalInRange,
                'errors' => $errorsInRange,
            ],
        ];
    }
}
