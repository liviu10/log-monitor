<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;
use RuntimeException;

/**
 * Queue Job Model
 *
 * Manages queries, state transitions, retry mechanisms, and filtering in the queue_jobs table.
 *
 * @category Model
 *
 * @version  2.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class QueueJob
{
    /**
     * Adds a new job to the queue table and validates the result.
     *
     * @param  string  $queue  The queue name.
     * @param  array<string, mixed>  $payload  The execution parameters.
     * @param  string|null  $availableAt  Timestamp when job becomes eligible for processing.
     * @param  string|null  $expiresAt  Timestamp when job expires (TTL).
     * @return string|int The ID of the created record.
     *
     * @throws RuntimeException If insertion fails.
     */
    public static function push(
        string $queue,
        array $payload,
        ?string $availableAt = null,
        ?string $expiresAt = null
    ): string|int {
        $db = MySQLWrapper::getInstance();

        if ($expiresAt === null && isset($payload['expires_at']) && is_string($payload['expires_at']) && $payload['expires_at'] !== '') {
            $expiresAt = $payload['expires_at'];
        }

        $now = date('Y-m-d H:i:s');
        $effectiveAvailableAt = ($availableAt !== null && $availableAt !== '') ? $availableAt : $now;

        $result = $db->create('queue_jobs', [
            'queue' => $queue,
            'payload' => json_encode($payload),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $effectiveAvailableAt,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return $result;
    }

    /**
     * Finds a single queue job by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT * FROM queue_jobs WHERE id = ? LIMIT 1', [$id]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    /**
     * Marks jobs that have passed their expiration timestamp as 'expired'.
     */
    public static function markExpired(): int
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            "UPDATE queue_jobs 
             SET status = 'expired', processed_at = NOW() 
             WHERE expires_at IS NOT NULL 
               AND expires_at <= NOW() 
               AND status IN ('pending', 'failed')"
        );

        return $stmt->rowCount();
    }

    /**
     * Retrieves queue jobs that are pending or failed with attempts < max_attempts
     * and eligible by timestamp (available_at <= NOW() and not expired).
     *
     * @param  int|null  $maxAttempts  Maximum retry limit (defaults to config value or 5).
     * @return array<int, array<string, mixed>> List of matching queue jobs.
     */
    public static function getPendingAndRetryable(?int $maxAttempts = null): array
    {
        self::markExpired();

        $limit = $maxAttempts;
        if ($limit === null) {
            $cfg = config('app.queue.max_attempts');
            $limit = is_numeric($cfg) ? (int) $cfg : 5;
        }

        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            "SELECT * FROM queue_jobs 
             WHERE (status = 'pending' OR (status = 'failed' AND attempts < ?))
               AND available_at <= NOW()
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id ASC",
            [$limit]
        );

        $rows = $stmt->fetchAll();
        $jobs = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $jobs[] = $row;
            }
        }

        return $jobs;
    }

    /**
     * Retrieves failed dead-letter jobs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getFailedJobs(?string $queue = null, int $limit = 50): array
    {
        $db = MySQLWrapper::getInstance();
        $sql = "SELECT * FROM queue_jobs WHERE status = 'failed'";
        $params = [];

        if ($queue !== null && $queue !== '' && $queue !== 'all') {
            $sql .= ' AND queue = ?';
            $params[] = $queue;
        }

        $sql .= ' ORDER BY id DESC LIMIT '.max(1, $limit);
        $stmt = $db->query($sql, $params);

        $rows = $stmt->fetchAll();
        $jobs = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $jobs[] = $row;
            }
        }

        return $jobs;
    }

    /**
     * Resets failed jobs back to 'pending' state and sets available_at to NOW().
     *
     * @param  int|null  $id  Specific job ID, or null for bulk retry.
     * @param  string|null  $queue  Optional queue filter for bulk retry.
     * @param  bool  $includeExpired  Whether to also reset expired jobs.
     * @return int Number of affected rows.
     */
    public static function retryFailed(?int $id = null, ?string $queue = null, bool $includeExpired = false): int
    {
        $db = MySQLWrapper::getInstance();

        if ($id !== null && $id > 0) {
            $statusCond = $includeExpired ? "IN ('failed', 'expired')" : "= 'failed'";
            $stmt = $db->query(
                "UPDATE queue_jobs 
                 SET status = 'pending', attempts = 0, error_message = NULL, available_at = NOW() 
                 WHERE id = ? AND status {$statusCond}",
                [$id]
            );

            return $stmt->rowCount();
        }

        $statusCond = $includeExpired ? "IN ('failed', 'expired')" : "= 'failed'";
        $sql = "UPDATE queue_jobs 
                SET status = 'pending', attempts = 0, error_message = NULL, available_at = NOW() 
                WHERE status {$statusCond}";
        $params = [];

        if ($queue !== null && $queue !== '' && $queue !== 'all') {
            $sql .= ' AND queue = ?';
            $params[] = $queue;
        }

        $stmt = $db->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Updates status and processing attributes of a job.
     *
     * @param  int  $id  The job ID.
     * @param  array<string, mixed>  $data  Modified fields.
     * @return bool True if operation finishes without error.
     */
    public static function updateStatus(int $id, array $data): bool
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->update('queue_jobs', $data, ['id' => $id]);

        return $rows >= 0;
    }

    /**
     * Deletes a specific job by ID.
     */
    public static function deleteJob(int $id): bool
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('DELETE FROM queue_jobs WHERE id = ?', [$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Retrieves aggregated status counts for the Queue Dashboard.
     *
     * @return array{total: int, pending: int, processing: int, completed: int, failed: int, expired: int}
     */
    public static function getStats(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
            FROM queue_jobs
        ");
        $row = $stmt->fetch();

        if (! is_array($row)) {
            return [
                'total' => 0,
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'expired' => 0,
            ];
        }

        return [
            'total' => isset($row['total']) && is_numeric($row['total']) ? (int) $row['total'] : 0,
            'pending' => isset($row['pending']) && is_numeric($row['pending']) ? (int) $row['pending'] : 0,
            'processing' => isset($row['processing']) && is_numeric($row['processing']) ? (int) $row['processing'] : 0,
            'completed' => isset($row['completed']) && is_numeric($row['completed']) ? (int) $row['completed'] : 0,
            'failed' => isset($row['failed']) && is_numeric($row['failed']) ? (int) $row['failed'] : 0,
            'expired' => isset($row['expired']) && is_numeric($row['expired']) ? (int) $row['expired'] : 0,
        ];
    }

    /**
     * Retrieves distinct queue names for filter dropdowns.
     *
     * @return array<int, string>
     */
    public static function getDistinctQueues(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT DISTINCT queue FROM queue_jobs ORDER BY queue ASC');
        $rows = $stmt->fetchAll();

        $queues = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['queue']) && is_string($row['queue']) && $row['queue'] !== '') {
                $queues[] = $row['queue'];
            }
        }

        return $queues;
    }

    /**
     * Returns paginated, searchable, sortable list of jobs for DataTable integration.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function getPaginatedFilteredJobs(
        array $filters = [],
        int $page = 1,
        int $perPage = 10,
        string $sortBy = 'id',
        bool $sortDesc = true
    ): array {
        $db = MySQLWrapper::getInstance();
        $where = [];
        $params = [];

        // 1. Search term
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $searchTerm = '%'.trim($filters['search']).'%';
            $where[] = '(id LIKE ? OR queue LIKE ? OR payload LIKE ? OR error_message LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // 2. Status filter
        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }

        // 3. Queue filter
        if (isset($filters['queue']) && is_string($filters['queue']) && $filters['queue'] !== '' && $filters['queue'] !== 'all') {
            $where[] = 'queue = ?';
            $params[] = $filters['queue'];
        }

        $whereClause = $where !== [] ? 'WHERE '.implode(' AND ', $where) : '';

        // Count total matching
        $countSql = "SELECT COUNT(*) as total FROM queue_jobs {$whereClause}";
        $countStmt = $db->query($countSql, $params);
        $countRow = $countStmt->fetch();
        $total = is_array($countRow) && isset($countRow['total']) && is_numeric($countRow['total']) ? (int) $countRow['total'] : 0;

        // Sorting whitelist
        $allowedSortColumns = ['id', 'queue', 'status', 'attempts', 'available_at', 'expires_at', 'created_at', 'processed_at'];
        $cleanSortBy = in_array($sortBy, $allowedSortColumns, true) ? $sortBy : 'id';
        $orderDir = $sortDesc ? 'DESC' : 'ASC';

        $limit = max(1, $perPage);
        $offset = max(0, ($page - 1) * $limit);

        $querySql = "SELECT * FROM queue_jobs {$whereClause} ORDER BY {$cleanSortBy} {$orderDir} LIMIT {$limit} OFFSET {$offset}";
        $queryStmt = $db->query($querySql, $params);
        $rows = $queryStmt->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $row;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Returns paginated, searchable, sortable list of sent notifications (`status = 'completed'`).
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function getSentNotifications(
        array $filters = [],
        int $limit = 10,
        int $offset = 0,
        string $sortBy = 'id',
        string $sortDir = 'DESC'
    ): array {
        $db = MySQLWrapper::getInstance();
        $where = ["status = 'completed'"];
        $params = [];

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $searchTerm = '%'.trim($filters['search']).'%';
            $where[] = '(id LIKE ? OR queue LIKE ? OR payload LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (isset($filters['queue']) && is_string($filters['queue']) && $filters['queue'] !== '' && $filters['queue'] !== 'all') {
            $where[] = 'queue = ?';
            $params[] = $filters['queue'];
        }

        $whereClause = 'WHERE '.implode(' AND ', $where);

        $countRow = $db->query("SELECT COUNT(*) as total FROM queue_jobs {$whereClause}", $params)->fetch();
        $total = is_array($countRow) && isset($countRow['total']) && is_numeric($countRow['total']) ? (int) $countRow['total'] : 0;

        $allowedSortColumns = ['id', 'queue', 'created_at', 'processed_at'];
        $cleanSortBy = in_array($sortBy, $allowedSortColumns, true) ? $sortBy : 'id';
        $orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $orderClause = $cleanSortBy === 'id'
            ? "ORDER BY id {$orderDir}"
            : "ORDER BY {$cleanSortBy} {$orderDir}, id {$orderDir}";

        $querySql = "SELECT * FROM queue_jobs {$whereClause} {$orderClause} LIMIT ".max(1, $limit).' OFFSET '.max(0, $offset);
        $rows = $db->query($querySql, $params)->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $items[] = $row;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Retrieves summary counts for delivered notifications (`status = 'completed'`).
     *
     * @return array{total: int, alerts: int, emails: int, today: int}
     */
    public static function getSentStats(): array
    {
        $db = MySQLWrapper::getInstance();
        $row = $db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN queue = 'alerts' THEN 1 ELSE 0 END) as alerts,
                SUM(CASE WHEN queue = 'emails' THEN 1 ELSE 0 END) as emails,
                SUM(CASE WHEN DATE(COALESCE(processed_at, created_at)) = CURDATE() THEN 1 ELSE 0 END) as today
             FROM queue_jobs
             WHERE status = 'completed'"
        )->fetch();

        if (! is_array($row)) {
            return ['total' => 0, 'alerts' => 0, 'emails' => 0, 'today' => 0];
        }

        return [
            'total' => isset($row['total']) && is_numeric($row['total']) ? (int) $row['total'] : 0,
            'alerts' => isset($row['alerts']) && is_numeric($row['alerts']) ? (int) $row['alerts'] : 0,
            'emails' => isset($row['emails']) && is_numeric($row['emails']) ? (int) $row['emails'] : 0,
            'today' => isset($row['today']) && is_numeric($row['today']) ? (int) $row['today'] : 0,
        ];
    }

    /**
     * Retrieves distinct queue names for delivered notifications.
     *
     * @return array<int, string>
     */
    public static function getDistinctCompletedQueues(): array
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->query("SELECT DISTINCT queue FROM queue_jobs WHERE status = 'completed' ORDER BY queue ASC")->fetchAll();

        $queues = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['queue']) && is_string($row['queue']) && $row['queue'] !== '') {
                $queues[] = $row['queue'];
            }
        }

        return $queues;
    }

    /**
     * Purges processed jobs (completed/failed/expired) older than cutoff.
     *
     * @param  string  $cutoffDate  Jobs retention limit date.
     * @return int Number of deleted jobs.
     */
    public static function purge(string $cutoffDate): int
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            "DELETE FROM queue_jobs 
             WHERE processed_at < ? 
               AND (status = 'completed' OR status = 'failed' OR status = 'expired')",
            [$cutoffDate]
        );

        return $stmt->rowCount();
    }
}
