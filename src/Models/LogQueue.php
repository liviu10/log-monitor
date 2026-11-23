<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;
use PDO;

/**
 * LogQueue Model
 *
 * Manages raw log ingestion buffer entries in the log_queue table.
 */
class LogQueue
{
    public static function countAll(): int
    {
        $db = MySQLWrapper::getInstance();
        $val = $db->query('SELECT COUNT(*) FROM log_queue')->fetchColumn();

        return is_numeric($val) ? (int) $val : 0;
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
        $where = [];
        $params = [];

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $where[] = '(q.id LIKE ? OR a.name LIKE ? OR CAST(q.payload_raw AS CHAR) LIKE ?)';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $rawAppId = $filters['app_id'] ?? null;
        if ($rawAppId !== null && $rawAppId !== '' && $rawAppId !== 'all' && $rawAppId !== 0 && $rawAppId !== '0') {
            $where[] = 'q.app_id = ?';
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        $whereClause = $where !== [] ? 'WHERE '.implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM log_queue q LEFT JOIN apps a ON q.app_id = a.id {$whereClause}";
        $countVal = $db->query($countSql, $params)->fetchColumn();
        $total = is_numeric($countVal) ? (int) $countVal : 0;

        $allowedSorts = ['id', 'created_at', 'app_name'];
        $allowedDirections = ['ASC', 'DESC'];

        $cleanSort = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        if ($cleanSort === 'app_name') {
            $orderClause = "ORDER BY a.name {$sortOrder}, q.id {$sortOrder}";
        } elseif ($cleanSort === 'created_at') {
            $orderClause = "ORDER BY q.created_at {$sortOrder}, q.id {$sortOrder}";
        } else {
            $orderClause = "ORDER BY q.id {$sortOrder}";
        }

        $sql = "SELECT q.id, q.app_id, q.payload_raw, q.created_at, a.name as app_name
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
     * Retrieves summary metrics for the Log Queue dashboard header cards.
     *
     * @return array{total: int, distinct_apps: int, oldest_at: string|null, latest_at: string|null}
     */
    public static function getStats(): array
    {
        $db = MySQLWrapper::getInstance();
        $row = $db->query(
            'SELECT COUNT(*) AS total, COUNT(DISTINCT app_id) AS distinct_apps, MIN(created_at) AS oldest_at, MAX(created_at) AS latest_at FROM log_queue'
        )->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row)) {
            return ['total' => 0, 'distinct_apps' => 0, 'oldest_at' => null, 'latest_at' => null];
        }

        return [
            'total' => isset($row['total']) && is_numeric($row['total']) ? (int) $row['total'] : 0,
            'distinct_apps' => isset($row['distinct_apps']) && is_numeric($row['distinct_apps']) ? (int) $row['distinct_apps'] : 0,
            'oldest_at' => is_string($row['oldest_at'] ?? null) && $row['oldest_at'] !== '' ? $row['oldest_at'] : null,
            'latest_at' => is_string($row['latest_at'] ?? null) && $row['latest_at'] !== '' ? $row['latest_at'] : null,
        ];
    }

    /**
     * Retrieves distinct applications present in the log queue.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function getDistinctApps(): array
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->query(
            'SELECT DISTINCT a.id, a.name FROM log_queue q JOIN apps a ON q.app_id = a.id ORDER BY a.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $apps = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'], $row['name']) && is_numeric($row['id']) && is_string($row['name'])) {
                $apps[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        return $apps;
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
            'value' => self::countAll(),
            'icon' => 'fa-solid fa-layer-group',
            'color' => 'warning',
            'url' => '/admin/log-queue',
        ];
    }
}
