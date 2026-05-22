<?php

declare(strict_types=1);

namespace App\Utilities;

use PDO;
use PDOException;
use PDOStatement;

/**
 * MySQLWrapper Class
 *
 * Provides a simplified and secure interface for interacting with a MySQL database using PDO.
 * Implements the Singleton pattern to ensure a single active connection throughout the script's execution.
 * Includes methods for CRUD (Create, Read, Update, Delete) operations and connection error handling.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class MySQLWrapper
{
    /** @var MySQLWrapper|null Singleton instance of the class. */
    private static ?self $instance = null;

    /** @var PDO|null Database connection resource. */
    private ?PDO $connection = null;

    /** @var array<int, int|bool> Default options for PDO configuration. */
    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * MySQLWrapper class constructor.
     * Using Constructor Property Promotion for all configuration parameters.
     *
     * @param string $host    Database host.
     * @param string $db      Database name.
     * @param string $user    Database username.
     * @param string $pass    Database password.
     * @param string $port    Port (default 3306).
     * @param string $charset Charset (default utf8mb4).
     */
    public function __construct(
        private readonly string $host,
        private readonly string $db,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $port = '3306',
        private readonly string $charset = 'utf8mb4',
    ) {
        $this->connect();
    }

    /**
     * Returns the unique instance of the MySQLWrapper class (Singleton).
     * Creates an instance using environment variables if it doesn't exist.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self(
            host: $_ENV['DB_HOST'] ?? 'db',
            db: $_ENV['DB_DATABASE'] ?? 'log_monitor',
            user: $_ENV['DB_USERNAME'] ?? 'user',
            pass: $_ENV['DB_PASSWORD'] ?? 'password',
            port: $_ENV['DB_PORT'] ?? '3306',
        );
    }

    /**
     * Establishes a database connection using the configured settings.
     */
    public function connect(): void
    {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset};port={$this->port}";

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $this->options);
        } catch (\Throwable $e) {
            // Send log via cURL (if DB connection is down, LogViaCurl has recursion protection)
            LogViaCurl::send('ERROR', 'Database connection error', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'db_host' => $this->host,
                'db_name' => $this->db
            ]);
            
            $this->connection = null;
        }
    }

    /**
     * Returns the active PDO object.
     */
    public function getConnection(): ?PDO
    {
        return $this->connection;
    }

    /**
     * Closes the database connection by setting the resource to null.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Executes a secure SQL query using prepared statements.
     *
     * @param string $sql    SQL query.
     * @param array  $params Parameters for binding.
     * @return PDOStatement|false The statement object or false if an error occurs.
     */
    public function query(string $sql, array $params = []): PDOStatement|false
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Eroare la executarea interogarii SQL', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql' => $sql,
                'sql_params' => $params
            ]);

            return false;
        }
    }

    /**
     * Inserts a new record into a table.
     *
     * @param string $table Table name.
     * @param array  $data  Associative array of data (column => value).
     * @return string|int|bool Inserted ID, true (for tables without auto-increment), or false.
     */
    public function create(string $table, array $data): string|int|bool
    {
        if (empty($data)) {
            return false;
        }

        $escapedColumns = array_map(fn($col) => "`{$col}`", array_keys($data));
        $columns = implode(', ', $escapedColumns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        $stmt = $this->query($sql, array_values($data));

        if ($stmt === false || $this->connection === null) {
            return false;
        }

        $lastId = $this->connection->lastInsertId();
        if ($lastId && $lastId !== '0') {
            return $lastId;
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Reads data from a table with optional filters.
     *
     * @param string $table      Table name.
     * @param array  $conditions WHERE conditions (column => value).
     * @param array  $columns    Columns to retrieve.
     * @param string $logic      Logic between conditions (AND/OR).
     * @return array|false Array of results or false in case of error.
     */
    public function read(string $table, array $conditions = [], array $columns = ['*'], string $logic = 'AND'): array|false
    {
        $escapedSelectColumns = array_map(function($col) {
            return $col === '*' ? '*' : "`{$col}`";
        }, $columns);

        $sql = "SELECT " . implode(', ', $escapedSelectColumns) . " FROM {$table}";
        $params = [];

        if (!empty($conditions)) {
            $sql .= " WHERE ";
            $clauses = [];
            foreach ($conditions as $key => $value) {
                $clauses[] = "`{$key}` = ?";
                $params[] = $value;
            }
            $sql .= implode(" {$logic} ", $clauses);
        }

        $stmt = $this->query($sql, $params);

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : false;
    }

    /**
     * Updates records in a table.
     *
     * @param string $table      Table name.
     * @param array  $data       New data (column => value).
     * @param array  $conditions WHERE conditions.
     * @param string $logic      Logic between conditions.
     * @return int|false Number of affected rows or false.
     */
    public function update(string $table, array $data, array $conditions, string $logic = 'AND'): int|false
    {
        if (empty($data) || empty($conditions)) {
            return false;
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses);

        $whereClauses = [];
        foreach ($conditions as $key => $value) {
            $whereClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql .= " WHERE " . implode(" {$logic} ", $whereClauses);

        $stmt = $this->query($sql, $params);
        
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Deletes records from a table based on certain conditions.
     *
     * @param string $table      Table name.
     * @param array  $conditions WHERE conditions.
     * @param string $logic      Logic between conditions.
     * @return int|false Number of affected rows or false.
     */
    public function delete(string $table, array $conditions, string $logic = 'AND'): int|false
    {
        if (empty($conditions)) {
            return false;
        }

        $sql = "DELETE FROM {$table} WHERE ";
        $clauses = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            $clauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql .= implode(" {$logic} ", $clauses);

        $stmt = $this->query($sql, $params);

        return $stmt ? $stmt->rowCount() : false;
    }
}
