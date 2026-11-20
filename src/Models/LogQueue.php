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
