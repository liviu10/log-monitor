<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;
use PDO;

/**
 * LogQueue Model
 *
 * Manages buffered log ingestion entries, lifecycle transitions, retries, and filtering in `log_queue`.
 */
class LogQueue
{
    private const ACTIVE_STATUSES_SQL = "('pending', 'processing', 'failed', 'expired')";

    public static function countAll(): int
    {
        $db = MySQLWrapper::getInstance();
        $val = $db->query('SELECT COUNT(*) FROM log_queue')->fetchColumn();

        return is_numeric($val) ? (int) $val : 0;
    }

    public static function countPending(): int
    {
        $db = MySQLWrapper::getInstance();
        $val = $db->query("SELECT COUNT(*) FROM log_queue WHERE status IN ('pending', 'processing')")->fetchColumn();

        return is_numeric($val) ? (int) $val : 0;
    }

    /**
     * Marks log queue jobs whose expiration timestamp has elapsed as 'expired'.
     */
    public static function markExpired(): int
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            "UPDATE log_queue
             SET status = 'expired', processed_at = NOW()
             WHERE expires_at IS NOT NULL
               AND expires_at <= NOW()
               AND status IN ('pending', 'failed')"
        );

        return $stmt->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getPaginated(int $limit = 10, int $offset = 0, string $sortBy = 'id', string $sortDir = 'DESC'): array
    {
        $db = MySQLWrapper::getInstance();

        $allowedSorts = ['id', 'created_at', 'app_name'];
        $allowedDirections = ['ASC', 'DESC'];

        $cleanSort = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        if ($cleanSort === 'app_name') {
            $orderClause = "ORDER BY a.name {$sortOrder}";
        } elseif ($cleanSort === 'created_at') {
            $orderClause = "ORDER BY q.created_at {$sortOrder}, q.id {$sortOrder}";
        } else {
            $orderClause = "ORDER BY q.id {$sortOrder}";
        }

        $sql = "SELECT q.id, q.app_id, q.payload_raw, q.created_at, a.name as app_name
                FROM log_queue q
                LEFT JOIN apps a ON q.app_id = a.id
                WHERE q.status IN ('pending', 'processing')
                {$orderClause}
                LIMIT ".max(1, $limit).' OFFSET '.max(0, $offset);

        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function getPaginatedFiltered(
        array $filters = [],
        int $limit = 10,
        int $offset = 0,
        string $sortBy = 'id',
        string $sortDir = 'DESC'
    ): array {
        $db = MySQLWrapper::getInstance();
        $where = ['q.status IN '.self::ACTIVE_STATUSES_SQL];
        $params = [];

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $where[] = '(q.id LIKE ? OR a.name LIKE ? OR CAST(q.payload_raw AS CHAR) LIKE ? OR q.error_message LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $where[] = 'q.status = ?';
            $params[] = $filters['status'];
        }

        $rawAppId = $filters['app_id'] ?? null;
        if ($rawAppId !== null && $rawAppId !== '' && $rawAppId !== 'all' && $rawAppId !== 0 && $rawAppId !== '0') {
            $where[] = 'q.app_id = ?';
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        $whereClause = 'WHERE '.implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM log_queue q LEFT JOIN apps a ON q.app_id = a.id {$whereClause}";
        $countVal = $db->query($countSql, $params)->fetchColumn();
        $total = is_numeric($countVal) ? (int) $countVal : 0;

        $allowedSorts = ['id', 'app_name', 'status', 'attempts', 'available_at', 'created_at', 'processed_at'];
        $allowedDirections = ['ASC', 'DESC'];

        $cleanSort = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        $orderClause = match ($cleanSort) {
            'app_name' => "ORDER BY a.name {$sortOrder}, q.id {$sortOrder}",
            'id' => "ORDER BY q.id {$sortOrder}",
            default => "ORDER BY q.{$cleanSort} {$sortOrder}, q.id {$sortOrder}",
        };

        $sql = "SELECT q.id, q.app_id, q.payload_raw, q.status, q.attempts, q.available_at, q.expires_at, q.error_message, q.created_at, q.processed_at, a.name as app_name
                FROM log_queue q
                LEFT JOIN apps a ON q.app_id = a.id
                {$whereClause}
                {$orderClause}
                LIMIT ".max(1, $limit).' OFFSET '.max(0, $offset);

        $rows = $db->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        /** @var array<int, array<string, mixed>> $rows */

        return [
            'items' => $rows,
            'total' => $total,
        ];
    }

    /**
     * Retrieves aggregated status counts for the Log Queue stats grid.
     *
     * @return array{total: int, pending: int, processing: int, failed: int, expired: int}
     */
    public static function getStats(): array
    {
        $db = MySQLWrapper::getInstance();
        $row = $db->query(
            'SELECT
                SUM(CASE WHEN status IN '.self::ACTIVE_STATUSES_SQL." THEN 1 ELSE 0 END) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
             FROM log_queue"
        )->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row)) {
            return [
                'total' => 0,
                'pending' => 0,
                'processing' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return [
            'total' => isset($row['total']) && is_numeric($row['total']) ? (int) $row['total'] : 0,
            'pending' => isset($row['pending']) && is_numeric($row['pending']) ? (int) $row['pending'] : 0,
            'processing' => isset($row['processing']) && is_numeric($row['processing']) ? (int) $row['processing'] : 0,
            'failed' => isset($row['failed']) && is_numeric($row['failed']) ? (int) $row['failed'] : 0,
            'expired' => isset($row['expired']) && is_numeric($row['expired']) ? (int) $row['expired'] : 0,
        ];
    }

    /**
     * Retrieves distinct applications present in the active log queue.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function getDistinctApps(): array
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->query(
            'SELECT DISTINCT a.id, a.name
             FROM log_queue q
             JOIN apps a ON q.app_id = a.id
             WHERE q.status IN '.self::ACTIVE_STATUSES_SQL.'
             ORDER BY a.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $apps = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'], $row['name']) && is_numeric($row['id']) && is_string($row['name'])) {
                $apps[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        return $apps;
    }

    /**
     * Resets failed (and optionally expired) log queue jobs back to pending state.
     */
    public static function retryFailed(?int $id = null, ?int $appId = null, bool $includeExpired = false): int
    {
        $db = MySQLWrapper::getInstance();
        $statusCond = $includeExpired ? "IN ('failed', 'expired')" : "= 'failed'";

        if ($id !== null && $id > 0) {
            $stmt = $db->query(
                "UPDATE log_queue
                 SET status = 'pending', attempts = 0, error_message = NULL, available_at = NOW()
                 WHERE id = ? AND status {$statusCond}",
                [$id]
            );

            return $stmt->rowCount();
        }

        $sql = "UPDATE log_queue
                SET status = 'pending', attempts = 0, error_message = NULL, available_at = NOW()
                WHERE status {$statusCond}";
        $params = [];

        if ($appId !== null && $appId > 0) {
            $sql .= ' AND app_id = ?';
            $params[] = $appId;
        }

        $stmt = $db->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Purges processed log queue jobs (completed/failed/expired) older than cutoff.
     */
    public static function purgeProcessed(string $cutoffDate): int
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            "DELETE FROM log_queue
             WHERE COALESCE(processed_at, created_at) < ?
               AND status IN ('completed', 'failed', 'expired')",
            [$cutoffDate]
        );

        return $stmt->rowCount();
    }

    public static function deleteById(int $id): int
    {
        $db = MySQLWrapper::getInstance();

        return $db->delete('log_queue', ['id' => $id]);
    }

    public static function purgeAll(): void
    {
        $db = MySQLWrapper::getInstance();
        $db->getConnection()->exec('TRUNCATE TABLE log_queue');
    }

    /**
     * Dashboard summary card for pending ingestion queue.
     *
     * @return array<string, mixed>
     */
    public static function dashboardSummaryLogQueue(): array
    {
        return [
            'title' => __('Log Queue Size'),
            'value' => self::countPending(),
            'icon' => 'fa-solid fa-layer-group',
            'color' => 'warning',
            'url' => '/admin/log-queue',
        ];
    }
}
