<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;
use App\Utilities\TimeSeriesHelper;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Log Model
 *
 * Manages database operations for the logs table with FULLTEXT search and analytics.
 */
class Log
{
    protected MySQLWrapper $db;

    public function __construct(?MySQLWrapper $db = null)
    {
        $this->db = $db ?? MySQLWrapper::getInstance();
    }

    public function create(int $appId, string $level, string $message, mixed $context = null): int
    {
        if ($appId <= 0 || trim($level) === '' || trim($message) === '') {
            throw new InvalidArgumentException(__('Required log details are missing or invalid'));
        }

        $jsonContext = null;
        if ($context !== null) {
            $jsonContext = json_encode($context, JSON_THROW_ON_ERROR);
        }

        $params = [
            'app_id' => $appId,
            'level' => strtoupper(trim($level)),
            'message' => trim($message),
            'context' => $jsonContext,
        ];

        try {
            $result = $this->db->create('logs', $params);
            if ($result === 0 || $result === '') {
                throw new RuntimeException(__('Failed to write log to database'));
            }

            return (int) $result;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'INSERT INTO logs (app_id, level, message, context)',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error saving log entry'), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getPaginated(array $filters = [], int $limit = 50, int $offset = 0, string $sortBy = 'id', string $sortDir = 'DESC'): array
    {
        if ($limit < 1) {
            $limit = 50;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = 'SELECT l.*, a.name as app_name
                FROM logs l
                JOIN apps a ON l.app_id = a.id
                WHERE 1=1';
        $params = [];

        $rawAppId = $filters['app_id'] ?? null;
        if ($rawAppId !== null && $rawAppId !== '' && $rawAppId !== 0 && $rawAppId !== '0') {
            $sql .= ' AND l.app_id = ?';
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        $rawLevel = $filters['level'] ?? null;
        if ($rawLevel !== null && $rawLevel !== '') {
            $sql .= ' AND l.level = ?';
            $params[] = strtoupper(trim(is_string($rawLevel) ? $rawLevel : ''));
        }

        $rawSearch = $filters['search'] ?? null;
        if ($rawSearch !== null && $rawSearch !== '') {
            $sql .= ' AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)';
            $params[] = trim(is_string($rawSearch) ? $rawSearch : '').'*';
        }

        $allowedSorts = ['id', 'created_at', 'level', 'app_name'];
        $allowedDirections = ['ASC', 'DESC'];

        $sortField = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        $orderClause = $sortField === 'app_name'
            ? "ORDER BY a.name {$sortOrder}"
            : "ORDER BY l.{$sortField} {$sortOrder}";

        if ($sortField !== 'id') {
            $orderClause .= ", l.id {$sortOrder}";
        }

        $sql .= " {$orderClause} LIMIT ".$limit.' OFFSET '.$offset;

        try {
            $stmt = $this->db->query($sql, $params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /** @var array<int, array<string, mixed>> $rows */
            return $rows;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => $sql,
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) as total FROM logs l WHERE 1=1';
        $params = [];

        $rawAppId = $filters['app_id'] ?? null;
        if ($rawAppId !== null && $rawAppId !== '' && $rawAppId !== 0 && $rawAppId !== '0') {
            $sql .= ' AND l.app_id = ?';
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        $rawLevel = $filters['level'] ?? null;
        if ($rawLevel !== null && $rawLevel !== '') {
            $sql .= ' AND l.level = ?';
            $params[] = strtoupper(trim(is_string($rawLevel) ? $rawLevel : ''));
        }

        $rawSearch = $filters['search'] ?? null;
        if ($rawSearch !== null && $rawSearch !== '') {
            $sql .= ' AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)';
            $params[] = trim(is_string($rawSearch) ? $rawSearch : '').'*';
        }

        try {
            $stmt = $this->db->query($sql, $params);
            $val = $stmt->fetchColumn();

            return is_numeric($val) ? (int) $val : 0;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => $sql,
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return 0;
        }
    }

    public function countBeforeDate(string $date): int
    {
        try {
            $stmt = $this->db->query('SELECT COUNT(*) FROM logs WHERE created_at < ?', [$date]);
            $val = $stmt->fetchColumn();

            return is_numeric($val) ? (int) $val : 0;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'SELECT COUNT(*) FROM logs WHERE created_at < ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return 0;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBeforeDate(string $date, int $limit, int $offset): array
    {
        if ($limit < 1) {
            $limit = 50;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $sql = 'SELECT l.*, a.name as app_name
                FROM logs l
                JOIN apps a ON l.app_id = a.id
                WHERE l.created_at < ?
                ORDER BY l.created_at ASC
                LIMIT '.$limit.' OFFSET '.$offset;

        try {
            $stmt = $this->db->query($sql, [$date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            /** @var array<int, array<string, mixed>> $rows */
            return $rows;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => $sql,
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return [];
        }
    }

    public function deleteBeforeDate(string $date): int
    {
        try {
            $stmt = $this->db->query('DELETE FROM logs WHERE created_at < ?', [$date]);

            return $stmt->rowCount();
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'DELETE FROM logs WHERE created_at < ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Failed to clear old logs from storage'), 0, $e);
        }
    }

    public function optimize(): bool
    {
        try {
            $this->db->query('OPTIMIZE TABLE logs');

            return true;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'OPTIMIZE TABLE logs',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return false;
        }
    }

    /**
     * @return array{total: int, critical: int, warning: int, info: int, success_rate: float}
     */
    public function getStats(): array
    {
        try {
            $row = $this->db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN level IN ('ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT') THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN level = 'WARNING' THEN 1 ELSE 0 END) as warning
                FROM logs
            ")->fetch();

            $total = is_array($row) && is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;
            $critical = is_array($row) && is_numeric($row['critical'] ?? null) ? (int) $row['critical'] : 0;
            $warning = is_array($row) && is_numeric($row['warning'] ?? null) ? (int) $row['warning'] : 0;
            $info = max(0, $total - ($critical + $warning));
            $rate = $total > 0 ? round((($total - $critical) / $total) * 100, 1) : 100.0;

            return [
                'total' => $total,
                'critical' => $critical,
                'warning' => $warning,
                'info' => $info,
                'success_rate' => $rate,
            ];
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'Dashboard Statistics Aggregation',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return ['total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0, 'success_rate' => 100.0];
        }
    }

    /**
     * Dashboard summary cards discovered by DashboardStatsScanner.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function dashboardSummaryLogs(): array
    {
        $stats = (new self)->getStats();

        return [
            [
                'title' => __('Total Logs'),
                'value' => $stats['total'],
                'icon' => 'fa-solid fa-terminal',
                'color' => 'primary',
                'url' => '/admin/logs',
                'breakdown' => [
                    __('Critical') => $stats['critical'],
                    __('Warnings') => $stats['warning'],
                    __('Info/Notice') => $stats['info'],
                ],
            ],
            [
                'title' => __('Success Rate'),
                'value' => $stats['success_rate'].'%',
                'icon' => 'fa-solid fa-circle-check',
                'color' => 'success',
                'url' => '/admin/logs',
            ],
            [
                'title' => __('Critical Alerts'),
                'value' => $stats['critical'],
                'icon' => 'fa-solid fa-triangle-exclamation',
                'color' => 'danger',
                'url' => '/admin/logs?level=ERROR',
            ],
        ];
    }

    /**
     * Dashboard chart: Logs ingestion trend via TimeSeriesHelper.
     *
     * @return array<string, mixed>
     */
    public static function dashboardChartLogsTrend(): array
    {
        $periods = TimeSeriesHelper::buildPeriods('logs', 'created_at');
        $activePeriod = 'days';
        $periodData = $periods[$activePeriod] ?? $periods['months'];

        return [
            'id' => 'chart_logs_ingestion_trend',
            'title' => __('Logs Ingestion Trend'),
            'type' => 'line',
            'labels' => $periodData['labels'],
            'datasets' => [
                [
                    'label' => __('Logs'),
                    'data' => $periodData['data'],
                    'borderColor' => 'rgb(13, 202, 240)',
                    'backgroundColor' => 'rgba(13, 202, 240, 0.15)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 6,
                ],
            ],
            'periods' => $periods,
            'activePeriod' => $activePeriod,
        ];
    }
}
