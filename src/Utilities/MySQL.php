<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

/**
 * MySQL Class
 *
 * Wrapper over PDO (pdo_mysql), sharing a consistent public interface with SQLite and Oracle.
 *
 * Key differences compared to direct PDO usage:
 *   - Secure defaults: ERRMODE_EXCEPTION, native prepared statements (no emulation), utf8mb4,
 *     multiple queries in a single call are disabled ('...; DROP TABLE ...' is rejected);
 *   - Required credentials without defaults; password marked with #[SensitiveParameter]
 *     (excluded from stack traces);
 *   - Array-driven insert / update / delete with validated table and column names escaped in
 *     backticks; unqualified update and delete operations without conditions are rejected;
 *   - fetchOne() fails if multiple rows are returned (usually indicating a missing WHERE clause);
 *   - fetchPairs() rejects duplicate keys instead of overwriting them;
 *   - Connection is opened lazily upon the first query (or explicitly with connect()).
 *
 * Parameters are either positional (?) or named (:id), never mixed. Accepted values:
 * int, float, string, bool, null, and DateTimeInterface (formatted as 'Y-m-d H:i:s').
 * INT and FLOAT are retrieved as int/float; DECIMAL remains string (preserving precision).
 *
 * update() returns the number of actually changed rows (rows matching existing values are not counted).
 *
 * Error messages contain the SQL statement (excluding parameter values) and MySQL error; values in
 * "Duplicate entry '...'" and "Incorrect ... value: '...'" are masked.
 *
 * Usage:
 *   require_once 'MySQL.php';
 *   use App\Utilities\MySQL;
 *
 *   $db = new MySQL(host: Env::string('DB_HOST'), database: Env::string('DB_DATABASE'),
 *       username: Env::string('DB_USERNAME'), password: Env::string('DB_PASSWORD'));
 *   $users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
 *   $user = $db->fetchOneOrFail('SELECT * FROM users WHERE id = :id', ['id' => $id]);
 *   $id = $db->insertGetId('users', ['name' => 'Ion', 'email' => 'ion@example.com']);
 *   $db->update('users', ['active' => 0], ['id' => $id]);
 *   $db->transaction(function (MySQL $db) use ($order): void { ... });
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class MySQL
{
    /** @var string An identifier segment: letters, digits, '_', not starting with a digit. */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var int Maximum length of a MySQL identifier. */
    private const int MAX_IDENTIFIER_LENGTH = 64;

    /** @var int Rows per INSERT batch in insertMany() (also subject to the database parameter limit). */
    private const int INSERT_ROWS = 1000;

    /** @var int Maximum SQL length included in error messages. */
    private const int MAX_SQL_IN_MESSAGE = 500;

    /** @var self|null Singleton instance of the class. */
    private static ?self $instance = null;

    private ?PDO $pdo = null;

    /** @var (Closure(string, array<array-key, mixed>, float): void)|null */
    private ?Closure $queryListener = null;

    /**
     * @param  string  $host  Server hostname or IP address.
     * @param  string  $database  Database name.
     * @param  int  $timeout  Connection timeout in seconds.
     *
     * @throws InvalidArgumentException If parameters are invalid.
     */
    public function __construct(
        private readonly string $host,
        private readonly string $database,
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $password,
        private readonly int $port = 3306,
        private readonly string $charset = 'utf8mb4',
        private readonly int $timeout = 5,
    ) {
        // ';' ar permite adaugarea altor optiuni in DSN (ex: unix_socket).
        foreach (['host' => $host, 'database' => $database, 'charset' => $charset] as $name => $value) {
            if ($value === '' || str_contains($value, ';')) {
                throw new InvalidArgumentException("MySQL::__construct(): {$name} must not be empty or contain ';'.");
            }
        }

        if ($username === '') {
            throw new InvalidArgumentException('MySQL::__construct(): username must not be empty.');
        }

        if ($port < 1 || $port > 65535 || $timeout < 1) {
            throw new InvalidArgumentException("MySQL::__construct(): invalid port ({$port}) or timeout ({$timeout}).");
        }
    }

    /**
     * Returns a singleton instance built from application environment variables.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $hostVal = $_ENV['DB_HOST'] ?? null;
            $host = is_string($hostVal) && $hostVal !== '' ? $hostVal : '127.0.0.1';

            $dbVal = $_ENV['DB_NAME'] ?? null;
            $database = is_string($dbVal) && $dbVal !== '' ? $dbVal : 'log_monitor';

            $userVal = $_ENV['DB_USER'] ?? null;
            $username = is_string($userVal) && $userVal !== '' ? $userVal : 'root';

            $passVal = $_ENV['DB_PASS'] ?? null;
            $password = is_string($passVal) ? $passVal : '';

            $portVal = $_ENV['DB_PORT'] ?? null;
            $port = is_numeric($portVal) ? (int) $portVal : 3306;

            $charsetVal = $_ENV['DB_CHARSET'] ?? null;
            $charset = is_string($charsetVal) && $charsetVal !== '' ? $charsetVal : 'utf8mb4';

            self::$instance = new self(
                host: $host,
                database: $database,
                username: $username,
                password: $password,
                port: $port,
                charset: $charset,
            );
        }

        return self::$instance;
    }

    /**
     * Overrides or clears the singleton instance (useful in tests or reconfiguration).
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Returns the underlying active PDO connection object.
     */
    public function getConnection(): PDO
    {
        return $this->pdo('getConnection');
    }

    /**
     * Executes a raw parameterized query and returns the PDOStatement.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->run('query', $sql, $params);
    }

    /**
     * Inserts a record into the table and returns the generated primary key.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If the insert fails.
     */
    public function create(string $table, array $data): int|string
    {
        return $this->insertGetId($table, $data);
    }

    /**
     * Reads records matching conditions.
     *
     * @param  string  $table  Table name.
     * @param  array<string, mixed>  $conditions  Filtering conditions.
     * @param  array<int, string>  $columns  Selected column names.
     * @param  string  $logic  Logical operator between conditions (AND/OR).
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException If table or conditions are invalid.
     * @throws RuntimeException If query fails.
     */
    public function read(string $table, array $conditions = [], array $columns = ['*'], string $logic = 'AND'): array
    {
        $escapedSelectColumns = array_map(fn (string $col) => $col === '*' ? '*' : $this->quoteIdentifier($col), $columns);
        $sql = 'SELECT '.implode(', ', $escapedSelectColumns).' FROM '.$this->quoteIdentifier($table);
        $params = [];

        if (! empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $key => $value) {
                $clauses[] = $this->quoteIdentifier((string) $key).' = ?';
                $params[] = $value;
            }
            $operator = strtoupper(trim($logic)) === 'OR' ? ' OR ' : ' AND ';
            $sql .= ' WHERE '.implode($operator, $clauses);
        }

        /** @var array<int, array<string, mixed>> $results */
        $results = $this->fetchAll($sql, $params);

        return $results;
    }

    /**
     * Establishes the database connection immediately (otherwise opened lazily on first query). Useful for health checks at boot.
     *
     * @throws RuntimeException If connecting to the database fails.
     */
    public function connect(): void
    {
        $this->pdo('connect');
    }

    /**
     * Closes the connection; the next query will reopen it.
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Fetches all rows as associative arrays.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->run('fetchAll', $sql, $params);

        return $this->rows('fetchAll', $sql, $statement);
    }

    /**
     * Fetches a single row or null if not found. Returning multiple rows throws an exception (missing WHERE or LIMIT 1).
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->rows('fetchOne', $sql, $this->run('fetchOne', $sql, $params), 2);

        if (count($rows) > 1) {
            throw new UnexpectedValueException('MySQL::fetchOne(): query returned more than one row. SQL: '.$this->shorten($sql));
        }

        return $rows[0] ?? null;
    }

    /**
     * Fetches a single row; throws an exception if not found.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchOneOrFail(string $sql, array $params = []): array
    {
        return $this->fetchOne($sql, $params)
            ?? throw new OutOfBoundsException('MySQL::fetchOneOrFail(): query returned no rows. SQL: '.$this->shorten($sql));
    }

    /**
     * Fetches the first column of a single row (e.g., SELECT COUNT(*)). Returns null if the value is NULL.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->rows('fetchValue', $sql, $this->run('fetchValue', $sql, $params), 2);

        if ($rows === []) {
            throw new OutOfBoundsException('MySQL::fetchValue(): query returned no rows. SQL: '.$this->shorten($sql));
        }

        if (count($rows) > 1) {
            throw new UnexpectedValueException('MySQL::fetchValue(): query returned more than one row. SQL: '.$this->shorten($sql));
        }

        return reset($rows[0]);
    }

    /**
     * Fetches the first column from all rows.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return array_map(
            static fn (array $row): mixed => reset($row),
            $this->rows('fetchColumn', $sql, $this->run('fetchColumn', $sql, $params)),
        );
    }

    /**
     * Fetches the first column as key and second column as value: SELECT id, name -> [1 => 'Ion', 2 => 'Ana'].
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws UnexpectedValueException If the row does not have exactly 2 columns, key is not int/string, or keys are duplicate.
     */
    public function fetchPairs(string $sql, array $params = []): array
    {
        $pairs = [];
        foreach ($this->rows('fetchPairs', $sql, $this->run('fetchPairs', $sql, $params)) as $position => $row) {
            if (count($row) !== 2) {
                throw new UnexpectedValueException('MySQL::fetchPairs(): query must select exactly 2 columns, got '.count($row).'.');
            }

            [$key, $value] = array_values($row);
            if (! is_int($key) && ! is_string($key)) {
                throw new UnexpectedValueException("MySQL::fetchPairs(): the key in row #{$position} must be int or string, got ".get_debug_type($key).'.');
            }
            if (array_key_exists($key, $pairs)) {
                throw new UnexpectedValueException("MySQL::fetchPairs(): duplicate key in row #{$position}.");
            }
            $pairs[$key] = $value;
        }

        return $pairs;
    }

    /**
     * Executes a statement (INSERT, UPDATE, DELETE, DDL) and returns the number of affected rows.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the execution fails.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run('execute', $sql, $params)->rowCount();
    }

    /**
     * Inserts a row from an associative array: ['column' => value].
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If the insert fails.
     */
    public function insert(string $table, array $data): void
    {
        [$sql, $params] = $this->insertSql('insert', $table, $data);
        $this->run('insert', $sql, $params);
    }

    /**
     * Inserts a row from an array and returns the generated primary key (AUTO_INCREMENT).
     * $idColumn is used only by Oracle (RETURNING ... INTO); here it is only validated.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If the insert fails.
     */
    public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
    {
        $this->quote('insertGetId', $idColumn);
        [$sql, $params] = $this->insertSql('insertGetId', $table, $data);
        $this->run('insertGetId', $sql, $params);

        $id = $this->pdo('insertGetId')->lastInsertId();
        if ($id === false || $id === '0') {
            throw new RuntimeException("MySQL::insertGetId(): no id was generated (table '{$table}' has no AUTO_INCREMENT column).");
        }

        return $this->normalizeId($id);
    }

    /**
     * Inserts multiple rows in batches of up to 1000 rows (within database parameter limits)
     * inside a single transaction: all succeed or none. Returns the number of inserted rows.
     * All rows must have the same columns (in any order). Joins an active transaction if one exists.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails (no rows from this call remain committed).
     */
    public function insertMany(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        [$columns, $values] = $this->rowValues('insertMany', $rows);
        $sql = 'INSERT INTO '.$this->quote('insertMany', $table)
            .' ('.implode(', ', array_map(fn (string $column): string => $this->quote('insertMany', $column), $columns)).') VALUES ';
        $tuple = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $perStatement = max(1, min(self::INSERT_ROWS, intdiv($this->maxParameters(), count($columns))));

        $this->transaction(function () use ($values, $perStatement, $sql, $tuple): void {
            foreach (array_chunk($values, $perStatement) as $chunk) {
                $this->run('insertMany', $sql.implode(', ', array_fill(0, count($chunk), $tuple)), array_merge(...$chunk));
            }
        });

        return count($rows);
    }

    /**
     * Streams rows one by one without loading the entire result into memory (exports, large data processing).
     * The query is executed immediately (errors surface upon call); rows are fetched as the loop iterates.
     * Exiting the loop early (break) closes the cursor.
     * The cursor uses a separate, unbuffered connection (rows are delivered from the server as they are read);
     * the main connection remains free for write operations during the loop. Being a separate connection,
     * the cursor does not see uncommitted changes from an open transaction on the main connection.
     *
     * @param  array<array-key, mixed>  $params
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query or stream fetching fails.
     */
    public function cursor(string $sql, array $params = []): Generator
    {
        $connection = $this->connectPdo('cursor', true);

        return $this->stream('cursor', $sql, $this->run('cursor', $sql, $params, $connection), $connection);
    }

    /**
     * Updates rows matching conditions from an associative array. Conditions are required (refuses unqualified updates).
     * Returns the number of affected rows.
     *
     * @param  array<string, mixed>  $data  ['column' => new value]
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     * @param  string  $logic  Logical operator between conditions (AND/OR).
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid, or if $where is empty.
     * @throws RuntimeException If the update fails.
     */
    public function update(string $table, array $data, array $where, string $logic = 'AND'): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('MySQL::update(): data must not be empty.');
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quote('update', (string) $column).' = ?';
            $params[] = self::scalar('update', $value, "column '{$column}'");
        }

        [$condition, $whereParams] = $this->whereSql('update', $where, $logic);
        $sql = 'UPDATE '.$this->quote('update', $table).' SET '.implode(', ', $set).' WHERE '.$condition;

        return $this->run('update', $sql, [...$params, ...$whereParams])->rowCount();
    }

    /**
     * Deletes rows matching required conditions (refuses unqualified deletes). Returns the number of deleted rows.
     *
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     * @param  string  $logic  Logical operator between conditions (AND/OR).
     *
     * @throws InvalidArgumentException If table name or conditions are invalid, or if $where is empty.
     * @throws RuntimeException If the delete fails.
     */
    public function delete(string $table, array $where, string $logic = 'AND'): int
    {
        [$condition, $params] = $this->whereSql('delete', $where, $logic);

        return $this->run('delete', 'DELETE FROM '.$this->quote('delete', $table).' WHERE '.$condition, $params)->rowCount();
    }

    /**
     * Builds an IN condition with placeholders: whereIn('id', [1, 2]) -> ['`id` IN (?, ?)', [1, 2]].
     * An empty list produces ['1 = 0', []] (matches nothing, avoiding a SQL syntax error).
     *
     * @param  list<mixed>  $values
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If column name or values are invalid.
     */
    public function whereIn(string $column, array $values): array
    {
        if ($values === []) {
            return ['1 = 0', []];
        }

        $params = array_map(static fn (mixed $value): int|float|string|null => self::scalar('whereIn', $value, "column '{$column}'"), $values);

        return [$this->quote('whereIn', $column).' IN ('.implode(', ', array_fill(0, count($params), '?')).')', $params];
    }

    /**
     * Executes the callback within a database transaction: commits on success, rolls back on any exception (re-thrown).
     * Joins an existing outer transaction if one is active (the outermost transaction performs the commit).
     *
     * @template T
     *
     * @param  Closure(self): T  $callback
     * @return T
     *
     * @throws RuntimeException If the transaction cannot be started or committed.
     * @throws Throwable Exception thrown by the callback, re-thrown after rollback.
     */
    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo('transaction');

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        try {
            $pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new RuntimeException('MySQL::transaction(): cannot begin transaction: '.$e->getMessage(), 0, $e);
        }

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // Tranzactia era deja inchisa sau conexiunea s-a pierdut: exceptia relevanta este cea originala.
            }

            throw $e;
        }
    }

    /**
     * Checks whether a transaction is currently active.
     */
    public function inTransaction(): bool
    {
        return $this->pdo instanceof PDO && $this->pdo->inTransaction();
    }

    /**
     * Returns a page of results and total count: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6].
     * $sql must be the complete query including ORDER BY (without ordering, pagination is non-deterministic).
     * Requesting a page beyond the last page returns an empty items list.
     *
     * @param  array<array-key, mixed>  $params
     * @return array{items: list<array<array-key, mixed>>, total: int, page: int, per_page: int, pages: int}
     *
     * @throws InvalidArgumentException If $page or $perPage is less than 1.
     * @throws RuntimeException If the query fails.
     */
    public function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException("MySQL::paginate(): page and perPage must be >= 1, got {$page} and {$perPage}.");
        }

        $total = $this->fetchValue('SELECT COUNT(*) FROM ('.$sql.') paginate_count', $params);
        // Fara mysqlnd (sau pe drivere vechi) numerele pot veni ca string.
        if (is_string($total) && ctype_digit($total)) {
            $total = (int) $total;
        }

        if (! is_int($total)) {
            throw new UnexpectedValueException('MySQL::paginate(): COUNT(*) did not return an integer.');
        }

        $offset = ($page - 1) * $perPage;
        $items = $total > $offset ? $this->fetchAll($sql." LIMIT {$perPage} OFFSET {$offset}", $params) : [];

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Validates and quotes a table or column identifier: users -> `users`, app.users -> `app`.`users`.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    public function quoteIdentifier(string $name): string
    {
        return $this->quote('quoteIdentifier', $name);
    }

    /**
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    private function quote(string $method, string $name): string
    {
        $segments = explode('.', $name);

        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1 || strlen($segment) > self::MAX_IDENTIFIER_LENGTH) {
                throw new InvalidArgumentException("MySQL::{$method}(): invalid identifier '{$name}' (allowed: letters, digits, '_', at most 64 characters, optionally 'database.name').");
            }
        }

        return '`'.implode('`.`', $segments).'`';
    }

    /**
     * Callback invoked after each successful query: fn (string $sql, array $params, float $ms).
     * Parameters may contain sensitive data; the callback determines what to log. null disables it.
     *
     * @param  (Closure(string, array<array-key, mixed>, float): void)|null  $listener
     */
    public function onQuery(?Closure $listener): void
    {
        $this->queryListener = $listener;
    }

    /**
     * Returns the database connection, opening it on first use.
     *
     * @throws RuntimeException If opening the database connection fails.
     */
    private function pdo(string $method): PDO
    {
        return $this->pdo ??= $this->connectPdo($method, false);
    }

    /**
     * Creates a new connection; unbuffered for cursor() (rows are not buffered in PHP memory).
     *
     * @throws RuntimeException If connecting fails.
     */
    private function connectPdo(string $method, bool $unbuffered): PDO
    {
        $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";

        try {
            $pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => $this->timeout,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => ! $unbuffered,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException("MySQL::{$method}(): cannot connect to '{$this->host}:{$this->port}/{$this->database}': ".$e->getMessage(), 0, $e);
        }

        return $pdo;
    }

    /**
     * Prepares and executes the query with strongly typed bound parameters.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     */
    private function run(string $method, string $sql, array $params, ?PDO $connection = null): PDOStatement
    {
        $bindings = $this->bindings($method, $params);
        $pdo = $connection ?? $this->pdo($method);
        $start = hrtime(true);

        try {
            $statement = $pdo->prepare($sql);
            foreach ($bindings as [$key, $value, $type]) {
                $statement->bindValue($key, $value, $type);
            }
            $statement->execute();
        } catch (PDOException $e) {
            throw $this->queryFailed($method, $sql, $e);
        }

        if ($this->queryListener instanceof Closure) {
            ($this->queryListener)($sql, $params, (hrtime(true) - $start) / 1e6);
        }

        return $statement;
    }

    /**
     * Fetches result rows (at most $limit, if specified).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws RuntimeException If fetching fails.
     */
    private function rows(string $method, string $sql, PDOStatement $statement, ?int $limit = null): array
    {
        $rows = [];

        try {
            while (($limit === null || count($rows) < $limit) && is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $rows[] = $row;
            }
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw $this->queryFailed($method, $sql, $e);
        }

        return $rows;
    }

    /**
     * Streams rows of an already executed statement as the generator advances.
     *
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws RuntimeException If fetching fails.
     */
    private function stream(string $method, string $sql, PDOStatement $statement, ?PDO $connection = null): Generator
    {
        $index = 0;

        try {
            while (true) {
                try {
                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    throw $this->queryFailed($method, $sql, $e);
                }

                if (! is_array($row)) {
                    break;
                }

                yield $index++ => $row;
            }
        } finally {
            $statement->closeCursor();
            // Conexiunea separata a cursorului se inchide odata cu el.
            $connection = null;
        }
    }

    /**
     * Maximum number of parameters in a MySQL prepared statement.
     */
    private function maxParameters(): int
    {
        return 65535;
    }

    /**
     * Extracts columns of the first row and values of all rows in matching column order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: non-empty-list<string>, 1: list<list<int|float|string|null>>}
     *
     * @throws InvalidArgumentException If a row has mismatched columns or unsupported values.
     */
    private function rowValues(string $method, array $rows): array
    {
        $columns = array_map(strval(...), array_keys($rows[0] ?? []));
        if ($columns === []) {
            throw new InvalidArgumentException("MySQL::{$method}(): data must not be empty.");
        }

        $values = [];
        foreach ($rows as $index => $row) {
            if (count($row) !== count($columns)) {
                throw new InvalidArgumentException("MySQL::{$method}(): row #{$index} does not have the same columns as the first row.");
            }

            $list = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    throw new InvalidArgumentException("MySQL::{$method}(): row #{$index} does not have the same columns as the first row.");
                }
                $list[] = self::scalar($method, $row[$column], "row #{$index}, column '{$column}'");
            }
            $values[] = $list;
        }

        return [$columns, $values];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     */
    private function insertSql(string $method, string $table, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException("MySQL::{$method}(): data must not be empty.");
        }

        $columns = [];
        $params = [];
        foreach ($data as $column => $value) {
            $columns[] = $this->quote($method, (string) $column);
            $params[] = self::scalar($method, $value, "column '{$column}'");
        }

        $sql = 'INSERT INTO '.$this->quote($method, $table).' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($params), '?')).')';

        return [$sql, $params];
    }

    /**
     * Builds WHERE conditions from an associative array joined by logic operator (AND/OR).
     *
     * @param  array<string, mixed>  $where
     * @param  string  $logic  Logical operator (AND/OR).
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If $where is empty or contains invalid columns/values.
     */
    private function whereSql(string $method, array $where, string $logic = 'AND'): array
    {
        if ($where === []) {
            throw new InvalidArgumentException("MySQL::{$method}(): conditions must not be empty (refusing to affect the whole table).");
        }

        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $quoted = $this->quote($method, (string) $column);

            if ($value === null) {
                $conditions[] = $quoted.' IS NULL';
            } elseif (is_array($value)) {
                [$in, $inParams] = $value === [] ? ['1 = 0', []] : $this->whereIn((string) $column, array_values($value));
                $conditions[] = $in;
                array_push($params, ...$inParams);
            } else {
                $conditions[] = $quoted.' = ?';
                $params[] = self::scalar($method, $value, "column '{$column}'");
            }
        }

        $operator = strtoupper(trim($logic)) === 'OR' ? ' OR ' : ' AND ';

        return [implode($operator, $conditions), $params];
    }

    /**
     * Builds parameters for bindValue: [key, value, PDO param type].
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array{0: int|string, 1: int|float|string|bool|null, 2: int}>
     *
     * @throws InvalidArgumentException If parameters mix positional and named types, or contain unsupported types.
     */
    private function bindings(string $method, array $params): array
    {
        $positional = array_is_list($params);
        $bindings = [];

        foreach ($params as $key => $value) {
            if (! $positional && ! is_string($key)) {
                throw new InvalidArgumentException("MySQL::{$method}(): parameters must be either all positional (?) or all named (:name).");
            }

            $name = $positional ? $key + 1 : ':'.ltrim((string) $key, ':');
            $label = $positional ? 'parameter #'.($key + 1) : "parameter '{$key}'";

            $bindings[] = match (true) {
                is_bool($value) => [$name, $value, PDO::PARAM_BOOL],
                is_int($value) => [$name, $value, PDO::PARAM_INT],
                $value === null => [$name, null, PDO::PARAM_NULL],
                default => [$name, self::scalar($method, $value, $label), PDO::PARAM_STR],
            };
        }

        return $bindings;
    }

    /**
     * Converts a PHP value to a database-compatible scalar: bool -> 0/1, DateTimeInterface -> 'Y-m-d H:i:s', float -> string.
     *
     * @throws InvalidArgumentException If the type is unsupported (array, object, resource).
     */
    private static function scalar(string $method, mixed $value, string $name): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_string($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_float($value) => is_finite($value) ? $value : throw new InvalidArgumentException("MySQL::{$method}(): value for {$name} must be a finite number."),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => throw new InvalidArgumentException("MySQL::{$method}(): unsupported type ".get_debug_type($value)." for {$name}."),
        };
    }

    private function normalizeId(string $id): int|string
    {
        return preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 ? (int) $id : $id;
    }

    private function queryFailed(string $method, string $sql, PDOException $e): RuntimeException
    {
        $state = is_array($e->errorInfo) && isset($e->errorInfo[0]) && is_string($e->errorInfo[0]) ? $e->errorInfo[0] : (string) $e->getCode();
        $code = is_array($e->errorInfo) && isset($e->errorInfo[1]) && is_int($e->errorInfo[1]) ? ', code '.$e->errorInfo[1] : '';
        $detail = is_array($e->errorInfo) && isset($e->errorInfo[2]) && is_string($e->errorInfo[2]) ? $e->errorInfo[2] : $e->getMessage();

        // MySQL include valoarea in unele mesaje (ex: un email sau un CNP duplicat).
        $detail = (string) preg_replace(
            ["/Duplicate entry '.*' for key/s", "/ value: '.*' for column/s"],
            ["Duplicate entry '?' for key", " value: '?' for column"],
            $detail,
        );

        return new RuntimeException("MySQL::{$method}(): query failed [SQLSTATE {$state}{$code}]: {$detail} SQL: ".$this->shorten($sql), 0, $e);
    }

    private function shorten(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($sql) > self::MAX_SQL_IN_MESSAGE ? substr($sql, 0, self::MAX_SQL_IN_MESSAGE).'...' : $sql;
    }
}
