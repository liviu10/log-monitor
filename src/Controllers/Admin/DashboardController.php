<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\DashboardStatsScanner;
use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;
use App\Exceptions\RedirectException;
use App\Models\App;
use App\Models\Log;
use App\Models\LogQueue;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Admin Dashboard Controller
 *
 * Displays system overview metrics, interactive Chart.js analytics, and live snapshots.
 */
class DashboardController extends BaseController
{
    public function index(): string
    {
        try {
            $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
            $scanner = new DashboardStatsScanner;
            $stats = $scanner->getStats($forceRefresh);

            if ($forceRefresh) {
                redirect('/admin');
            }

            $logModel = new Log;
            $latestLogs = $logModel->getPaginated([], 5, 0, 'id', 'DESC');
            $topApps = App::getTopApps(5);
            $pendingQueue = LogQueue::getPaginated(5, 0, 'id', 'DESC');
            $chartData = $this->getAnalyticsData('day');

            return $this->render('admin.dashboard', [
                'title' => __('Dashboard'),
                'summaries' => $stats['summaries'],
                'charts' => $stats['charts'],
                'cachedAt' => $stats['cached_at'],
                'latestLogs' => $latestLogs,
                'topApps' => $topApps,
                'pendingQueue' => $pendingQueue,
                'chartData' => $chartData,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof RedirectException) {
                throw $e;
            }

            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
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

            throw new RuntimeException(__('Critical error loading dashboard data. Please try again later.'), 500, $e);
        }
    }

    /**
     * AJAX endpoint returning JSON chart analytics for a period or custom date range.
     */
    public function chartData(): never
    {
        $rawPeriod = $_GET['period'] ?? 'day';
        $period = is_string($rawPeriod) ? $rawPeriod : 'day';

        $rawStart = $_GET['start_date'] ?? null;
        $startDate = is_string($rawStart) ? $rawStart : null;

        $rawEnd = $_GET['end_date'] ?? null;
        $endDate = is_string($rawEnd) ? $rawEnd : null;

        $data = $this->getAnalyticsData($period, $startDate, $endDate);

        $this->json($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalyticsData(string $period = 'day', ?string $startDate = null, ?string $endDate = null): array
    {
        $db = MySQLWrapper::getInstance();

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
                $parsedStart = $startDate !== null ? strtotime($startDate) : false;
                $parsedEnd = $endDate !== null ? strtotime($endDate) : false;

                if ($parsedStart === false || $parsedEnd === false || $parsedStart > $parsedEnd) {
                    $startTs = strtotime('-6 days 00:00:00');
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
                        $current = strtotime('+1 hour', $current);
                    }
                } elseif ($diffDays <= 60) {
                    $sqlSlotFormat = '%Y-%m-%d';
                    $firstSlot = strtotime(date('Y-m-d', $startTs));
                    $current = ($firstSlot !== false) ? $firstSlot : $startTs;
                    while ($current <= $endTs) {
                        $key = date('Y-m-d', $current);
                        $slots[$key] = ['total' => 0, 'errors' => 0];
                        $labels[$key] = date('d M', $current);
                        $current = strtotime('+1 day', $current);
                    }
                } else {
                    $sqlSlotFormat = '%Y-%m';
                    $firstSlot = strtotime(date('Y-m-01', $startTs));
                    $current = ($firstSlot !== false) ? $firstSlot : $startTs;
                    while ($current <= $endTs) {
                        $key = date('Y-m', $current);
                        $slots[$key] = ['total' => 0, 'errors' => 0];
                        $labels[$key] = date('M Y', $current);
                        $current = strtotime('+1 month', $current);
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
        $volumeRows = $db->query($sql, [$startSql, $endSql])->fetchAll(PDO::FETCH_ASSOC);

        foreach ($volumeRows as $row) {
            $slotKey = $row['slot'];
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

        /** @var list<array{level: string, count: int|string}> $sevRows */
        $sevRows = $db->query('
            SELECT level, COUNT(*) as count
            FROM logs
            WHERE created_at >= ? AND created_at <= ?
            GROUP BY level
            ORDER BY count DESC
        ', [$startSql, $endSql])->fetchAll(PDO::FETCH_ASSOC);

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
            $lvl = strtoupper($row['level']);
            $sevLabels[] = $lvl;
            $sevData[] = (int) $row['count'];
            $sevColors[] = $colorMap[$lvl] ?? '#8b5cf6';
        }

        /** @var list<array{app_name: string, log_count: int|string}> $appRows */
        $appRows = $db->query('
            SELECT a.name as app_name, COUNT(l.id) as log_count
            FROM logs l
            JOIN apps a ON l.app_id = a.id
            WHERE l.created_at >= ? AND l.created_at <= ?
            GROUP BY a.id, a.name
            ORDER BY log_count DESC
            LIMIT 10
        ', [$startSql, $endSql])->fetchAll(PDO::FETCH_ASSOC);

        $appLabels = [];
        $appData = [];
        foreach ($appRows as $row) {
            $appLabels[] = $row['app_name'];
            $appData[] = (int) $row['log_count'];
        }

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
                'total' => array_sum(array_column($slots, 'total')),
                'errors' => array_sum(array_column($slots, 'errors')),
            ],
        ];
    }
}
