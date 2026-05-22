<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;
use PDO;

/**
 * Log Class
 *
 * Manages database operations for the Log entity.
 * Provides functionality for creating new records, retrieving paginated logs with filters, and counting total logs.
 * Uses FULLTEXT indexing for efficient searches in log messages and context.
 *
 * @category Model
 * @package  App\Models
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class Log
{
    /**
     * Log class constructor.
     * Using Constructor Property Promotion for database injection.
     * 
     * @param MySQLWrapper $db The database wrapper instance.
     */
    public function __construct(
        protected MySQLWrapper $db = new MySQLWrapper(
            host: 'db',
            db: 'log_monitor',
            user: 'user',
            pass: 'password'
        )
    ) {
        $this->db = MySQLWrapper::getInstance();
    }

    /**
     * Creates a new log record in the database.
     *
     * @param int    $appId   The ID of the application generating the log.
     * @param string $level   The severity level of the log.
     * @param string $message The descriptive message of the log.
     * @param mixed  $context Additional data (will be stored as JSON).
     * @return int|bool The ID of the created record or false on failure.
     */
    public function create(int $appId, string $level, string $message, mixed $context = null): int|bool
    {
        return $this->db->create('logs', [
            'app_id' => $appId,
            'level' => $level,
            'message' => $message,
            'context' => $context ? json_encode($context) : null,
        ]);
    }

    /**
     * Fetches a paginated list of logs based on applied filters.
     *
     * @param array $filters Array with filters.
     * @param int   $limit   Maximum number of records per page.
     * @param int   $offset  Starting point for pagination.
     * @return array Array of found logs.
     */
    public function getPaginated(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = $filters['search'] . "*";
        }

        $sql .= " ORDER BY l.created_at DESC LIMIT $limit OFFSET $offset";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Counts the total number of logs that correspond to the selected filters.
     *
     * @param array $filters Array with applied filters.
     * @return int Total number of logs found.
     */
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM logs l WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = $filters['search'] . "*";
        }

        $stmt = $this->db->query($sql, $params);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    /**
     * Counts the total number of logs created before a specific date.
     *
     * @param string $date Cutoff date.
     * @return int Total number of logs.
     */
    public function countBeforeDate(string $date): int
    {
        $sql = "SELECT COUNT(*) FROM logs WHERE created_at < ?";
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    /**
     * Fetches logs created before a specific date, paginated.
     *
     * @param string $date   Cutoff date.
     * @param int    $limit  Number of logs per chunk.
     * @param int    $offset Starting point.
     * @return array Array of logs with associated application names.
     */
    public function getBeforeDate(string $date, int $limit, int $offset): array
    {
        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE l.created_at < ? 
                ORDER BY l.created_at ASC 
                LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Deletes logs created before a specific date.
     *
     * @param string $date Cutoff date.
     * @return int|false Number of deleted records or false on error.
     */
    public function deleteBeforeDate(string $date): int|false
    {
        $sql = "DELETE FROM logs WHERE created_at < ?";
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Optimizes the logs table to free up storage space.
     *
     * @return bool True on success, false otherwise.
     */
    public function optimize(): bool
    {
        $stmt = $this->db->query("OPTIMIZE TABLE logs");
        return $stmt !== false;
    }

    /**
     * Fetches statistics for the dashboard.
     *
     * @return array{
     *   total: int,
     *   critical: int,
     *   warning: int
     * }
     */
    public function getStats(): array
    {
        $total = $this->count();
        
        // Counts high severity errors
        $critical = 0;
        foreach (['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'] as $lvl) {
            $critical += $this->count(['level' => $lvl]);
        }
        
        $warning = $this->count(['level' => 'WARNING']);
        
        return [
            'total' => $total,
            'critical' => $critical,
            'warning' => $warning
        ];
    }
}
