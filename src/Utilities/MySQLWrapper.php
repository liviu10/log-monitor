<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Enums\LogLevel;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * MySQLWrapper Class
 *
 * Implements a secure Singleton pattern over PDO.
 * Operations throw detailed exceptions sent exclusively via cURL.
 * All text messages destined for exceptions use the __() function.
 *
 * @category Utilities
 *
 * @version  1.7
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class MySQLWrapper
{
    /** @var MySQLWrapper|null Singleton instance of the class. */
    private static ?self $instance = null;

    /** @var PDO|null Active PDO connection object. */
    private ?PDO $connection = null;

    /** @var array<int, mixed> Standard security and behavior configurations for PDO. */
    private array $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ];

    /**
     * Private constructor to restrict direct instantiation.
     *
     * @param  string  $host  Database host.
     * @param  string  $db  Database name.
     * @param  string  $user  Database user.
     * @param  string  $pass  Access password.
     * @param  string  $port  Communication port.
     * @param  string  $charset  Utilized character set.
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
     * Returns the single instance of the wrapper class.
     *
     * @return self Unique instance.
     */
    public static function getInstance(): self
    {
        $host = $_ENV['DB_HOST'] ?? 'db';
        $db = $_ENV['DB_DATABASE'] ?? 'log_monitor';
        $user = $_ENV['DB_USERNAME'] ?? 'user';
        $pass = $_ENV['DB_PASSWORD'] ?? 'password';
        $port = $_ENV['DB_PORT'] ?? '3306';

        return self::$instance ??= new self(
            host: is_string($host) ? $host : 'db',
            db: is_string($db) ? $db : 'log_monitor',
            user: is_string($user) ? $user : 'user',
            pass: is_string($pass) ? $pass : 'password',
            port: is_string($port) ? $port : '3306',
        );
    }

    /**
     * Initializes the native PDO connection and sends errors directly to cURL.
     *
     * @throws RuntimeException If connection initialization fails.
     */
    public function connect(): void
    {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset};port={$this->port}";

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $this->options);
        } catch (Throwable $e) {
            $context = [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'db_host' => $this->host,
                'db_name' => $this->db,
                'identifier' => 'MySQLWrapper_Connection_Failure',
            ];

            $this->logEmergency('Database connection initial failure', $context);

            throw new RuntimeException(__('Database connection failed.'), 500, $e);
        }
    }

    /**
     * Returns the active connection or throws an exception if it doesn't exist.
     *
     * @return PDO Valid PDO object.
     *
     * @throws RuntimeException If the connection property is null.
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException(__('No active database connection found.'));
        }

        return $this->connection;
    }

    /**
     * Closes the current database connection.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Executes a secure SQL query and dispatches full technical details via cURL on failure.
     *
     * @param  string  $sql  SQL statement to execute.
     * @param  array<int|string, mixed>  $params  Parameters associated with placeholders.
     * @return PDOStatement The statement object on successful execution.
     *
     * @throws RuntimeException When execution encounters syntax or network errors.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $conn = $this->getConnection();

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (Throwable $e) {
            $context = [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ];

            $this->logEmergency('Query execution failure event', $context);
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', $context);

            throw new RuntimeException(__('Internal query execution error.'), 500, $e);
        }
    }

    /**
     * Inserts a new record into a specified table.
     *
     * @param  string  $table  Name of target table.
     * @param  array<string, mixed>  $data  Dataset in column => value format.
     * @return string|int ID of the last inserted record or row count of affected rows.
     */
    public function create(string $table, array $data): string|int
    {
        if (empty($data)) {
            throw new RuntimeException(__('Cannot insert empty data into table :table.', ['table' => $table]));
        }

        $escapedColumns = array_map(fn ($col) => "`{$col}`", array_keys($data));
        $columns = implode(', ', $escapedColumns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->query($sql, array_values($data));

        $lastId = $this->getConnection()->lastInsertId();
        if (is_string($lastId) && $lastId !== '0' && $lastId !== '') {
            return $lastId;
        }

        return $stmt->rowCount();
    }

    /**
     * Queries the database and returns all matches found.
     *
     * @param  string  $table  Table name.
     * @param  array<string, mixed>  $conditions  Filtering conditions of type column => value.
     * @param  array<int, string>  $columns  List of selected columns.
     * @param  string  $logic  Logical operator used between filters (AND/OR).
     * @return array<int, array<string, mixed>> Multidimensional array with matching results.
     */
    public function read(string $table, array $conditions = [], array $columns = ['*'], string $logic = 'AND'): array
    {
        $escapedSelectColumns = array_map(fn (string $col) => $col === '*' ? '*' : "`{$col}`", $columns);
        $sql = 'SELECT '.implode(', ', $escapedSelectColumns)." FROM `{$table}`";
        $params = [];

        if (! empty($conditions)) {
            $sql .= ' WHERE ';
            $clauses = [];
            foreach ($conditions as $key => $value) {
                $clauses[] = "`{$key}` = ?";
                $params[] = $value;
            }
            $sql .= implode(" {$logic} ", $clauses);
        }

        $stmt = $this->query($sql, $params);
        $results = $stmt->fetchAll();

        /** @var array<int, array<string, mixed>> $results */
        return $results;
    }

    /**
     * Modifies records in a table based on clear criteria.
     *
     * @param  string  $table  Affected table.
     * @param  array<string, mixed>  $data  New information to be saved.
     * @param  array<string, mixed>  $conditions  Conditions determining modified rows.
     * @param  string  $logic  Logical relation between filters.
     * @return int Total number of rows modified by the operation.
     */
    public function update(string $table, array $data, array $conditions, string $logic = 'AND'): int
    {
        if (empty($data) || empty($conditions)) {
            throw new RuntimeException(__('Update operations require both data and condition constraints.'));
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql = "UPDATE `{$table}` SET ".implode(', ', $setClauses);

        $whereClauses = [];
        foreach ($conditions as $key => $value) {
            $whereClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql .= ' WHERE '.implode(" {$logic} ", $whereClauses);

        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Deletes records from the table, protecting against accidental global deletes.
     *
     * @param  string  $table  Target table.
     * @param  array<string, mixed>  $conditions  Mandatory row deletion conditions.
     * @param  string  $logic  Linking operator for the WHERE clause.
     * @return int Number of permanently deleted rows.
     */
    public function delete(string $table, array $conditions, string $logic = 'AND'): int
    {
        if (empty($conditions)) {
            throw new RuntimeException(__('Unconditional delete operations are prohibited for security reasons.'));
        }

        $sql = "DELETE FROM `{$table}` WHERE ";
        $clauses = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            $clauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql .= implode(" {$logic} ", $clauses);

        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    /**
     * Writes an emergency log in case of database failure.
     *
     * @param  string  $message  Error message.
     * @param  array<string, mixed>  $context  Additional context information.
     */
    private function logEmergency(string $message, array $context): void
    {
        try {
            $dir = dirname(__DIR__, 2).'/storage/logs';

            if (! is_dir($dir)) {
                if (file_exists($dir)) {
                    throw new RuntimeException("Path '{$dir}' exists but is not a directory.");
                }
                if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
                    throw new RuntimeException("Failed to create directory '{$dir}'.");
                }
            }

            if (! is_writable($dir)) {
                throw new RuntimeException("Directory '{$dir}' is not writable.");
            }

            $filePath = $dir.'/emergency-logs-'.date('Ymd').'.log';
            $timestamp = date('Y-m-d H:i:s');

            $logEntry = sprintf(
                "[%s] [%s] %s\nContext: %s\n%s\n",
                $timestamp,
                'ERROR',
                $message,
                json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                str_repeat('-', 80)
            );

            if (file_put_contents($filePath, $logEntry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException("Failed to write to file '{$filePath}'.");
            }
        } catch (Throwable $logException) {
            // Send the error to the native system log (error_log) as a last resort fallback
            error_log('Emergency logging failed: '.$logException->getMessage());
        }
    }
}
