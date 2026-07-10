<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Log Class
 *
 * Manages database operations for the Log entity.
 * Secured against SQL injection during pagination by removing interpolation and strict validation.
 * Uses FULLTEXT indexing for efficient searches.
 *
 * @category Model
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class Log
{
    /**
     * Constructor for the Log class.
     * Property promotion for database dependency injection.
     *
     * * @param MySQLWrapper $db Database wrapper instance.
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
     * Creates a new log entry in the database.
     *
     * @param  int  $appId  Source application ID.
     * @param  string  $level  Severity level.
     * @param  string  $message  Descriptive message.
     * @param  mixed  $context  Additional context data (will be JSON).
     * @return int Created log ID.
     *
     * @throws InvalidArgumentException If required data is missing.
     * @throws RuntimeException If operation fails.
     */
    public function create(int $appId, string $level, string $message, mixed $context = null): int
    {
        if ($appId <= 0 || trim($level) === '' || trim($message) === '') {
            throw new InvalidArgumentException(__('Required log details are missing or invalid'));
        }

        $jsonContext = null;
        if ($context !== null) {
            $jsonContext = json_encode($context, JSON_THROW_ON_ERROR);
        }

        $sql = 'INSERT INTO logs (app_id, level, message, context)';
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
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);
            throw new RuntimeException(__('Database error saving log entry'), 0, $e);
        }
    }

    /**
     * Returns a paginated list of logs based on applied filters.
     * Fully secured against SQL injection attacks via natively bound parameters for LIMIT/OFFSET.
     *
     * @param  array<string, mixed>  $filters  Applied filters.
     * @param  int  $limit  Maximum number of records.
     * @param  int  $offset  Pagination starting offset.
     * @param  string  $sortBy  Column to sort by.
     * @param  string  $sortDir  Sort direction (ASC or DESC).
     * @return array<int, array<string, mixed>> Found logs.
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

        if (! empty($filters['app_id'])) {
            $sql .= ' AND l.app_id = ?';
            $rawAppId = $filters['app_id'];
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        if (! empty($filters['level'])) {
            $sql .= ' AND l.level = ?';
            $rawLevel = $filters['level'];
            $params[] = strtoupper(trim(is_string($rawLevel) ? $rawLevel : ''));
        }

        if (! empty($filters['search'])) {
            $sql .= ' AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)';
            $rawSearch = $filters['search'];
            $params[] = trim(is_string($rawSearch) ? $rawSearch : '').'*';
        }

        // Strict validation of sort columns to prevent SQL Injection
        $allowedSorts = ['id', 'created_at', 'level', 'app_name'];
        $allowedDirections = ['ASC', 'DESC'];

        $sortField = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        if ($sortField === 'app_name') {
            $orderClause = "ORDER BY a.name {$sortOrder}";
        } else {
            $orderClause = "ORDER BY l.{$sortField} {$sortOrder}";
        }

        // Add l.id as secondary sort to ensure deterministic chronology for logs arriving in the same second
        if ($sortField !== 'id') {
            $orderClause .= ", l.id {$sortOrder}";
        }

        // Strict security: LIMIT and OFFSET are directly interpolated as integers to avoid binding them as string by PDO
        $sql .= " {$orderClause} LIMIT ".(int) $limit.' OFFSET '.(int) $offset;

        try {
            $stmt = $this->db->query($sql, $params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            /** @var array<int, array<string, mixed>> $rows */
            return $rows;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return [];
        }
    }

    /**
     * Counts the total logs matching the selected filters.
     *
     * @param  array<string, mixed>  $filters  Applied filters.
     * @return int Total number of matching logs found.
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM logs l WHERE 1=1';
        $params = [];

        if (! empty($filters['app_id'])) {
            $sql .= ' AND l.app_id = ?';
            $rawAppId = $filters['app_id'];
            $params[] = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        }

        if (! empty($filters['level'])) {
            $sql .= ' AND l.level = ?';
            $rawLevel = $filters['level'];
            $params[] = strtoupper(trim(is_string($rawLevel) ? $rawLevel : ''));
        }

        if (! empty($filters['search'])) {
            $sql .= ' AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)';
            $rawSearch = $filters['search'];
            $params[] = trim(is_string($rawSearch) ? $rawSearch : '').'*';
        }

        try {
            $stmt = $this->db->query($sql, $params);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return 0;
        }
    }

    /**
     * Counts records created before a specific date.
     *
     * @param  string  $date  Cutoff date.
     * @return int Total number of old logs.
     */
    public function countBeforeDate(string $date): int
    {
        $sql = 'SELECT COUNT(*) FROM logs WHERE created_at < ?';
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return 0;
        }
    }

    /**
     * Retrieves logs created before a specific date in a paginated manner.
     *
     * @param  string  $date  Cutoff date.
     * @param  int  $limit  Number of logs per chunk.
     * @param  int  $offset  Pagination starting point.
     * @return array<int, array<string, mixed>> List of results.
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
                LIMIT '.(int) $limit.' OFFSET '.(int) $offset;
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            /** @var array<int, array<string, mixed>> $rows */
            return $rows;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return [];
        }
    }

    /**
     * Deletes logs created before a specific date.
     *
     * @param  string  $date  Cutoff date.
     * @return int Number of deleted records.
     *
     * @throws RuntimeException If deletion fails catastrophically.
     */
    public function deleteBeforeDate(string $date): int
    {
        $sql = 'DELETE FROM logs WHERE created_at < ?';
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);

            return (int) $stmt->rowCount();
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);
            throw new RuntimeException(__('Failed to clear old logs from storage'), 0, $e);
        }
    }

    /**
     * Optimizes the logs table to reclaim fragmented storage space.
     *
     * @return bool True on success, otherwise False.
     */
    public function optimize(): bool
    {
        $sql = 'OPTIMIZE TABLE logs';
        try {
            $this->db->query($sql);

            return true;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => [],
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return false;
        }
    }

    /**
     * Extracts aggregated statistics for the administration dashboard.
     *
     * @return array{total: int, critical: int, warning: int}
     */
    public function getStats(): array
    {
        try {
            $total = $this->count();

            $critical = 0;
            foreach (['ERROR', 'CRITICAL', 'EMERGENCY', 'ALERT'] as $lvl) {
                $critical += $this->count(['level' => $lvl]);
            }

            $warning = $this->count(['level' => 'WARNING']);

            return [
                'total' => $total,
                'critical' => $critical,
                'warning' => $warning,
            ];
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'Dashboard Statistics Aggregation',
                'sql_parameters' => [],
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return ['total' => 0, 'critical' => 0, 'warning' => 0];
        }
    }
}
