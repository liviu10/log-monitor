<?php

declare(strict_types=1);

namespace App\Utilities;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use App\Enums\LogLevel;

/**
 * Clasa MySQLWrapper
 *
 * Implementeaza un sablon Singleton securizat peste PDO.
 * Operatiile genereaza exceptii detaliate trimise exclusiv catre cURL.
 * Toate mesajele text destinate exceptiilor folosesc functia __().
 *
 * @category Utilitare
 * @package  App\Utilities
 * @version  1.7
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class MySQLWrapper
{
    /** @var MySQLWrapper|null Instanta Singleton a clasei. */
    private static ?self $instance = null;

    /** @var PDO|null Obiectul conexiunii active PDO. */
    private ?PDO $connection = null;

    /** @var array Configurari standard de securitate si comportament pentru PDO. */
    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => true,
    ];

    /**
     * Constructor privat pentru restrictionarea instantierii directe.
     *
     * @param string $host Gazda bazei de date.
     * @param string $db Numele bazei de date.
     * @param string $user Utilizatorul bazei de date.
     * @param string $pass Parola de acces.
     * @param string $port Portul de comunicare.
     * @param string $charset Setul de caractere utilizat.
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
     * Returneaza instanta unica a clasei wrapper.
     *
     * @return self Instanta unica.
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
     * Initializeaza conexiunea nativa PDO si trimite erorile direct catre cURL.
     *
     * @throws RuntimeException Daca initializarea conexiunii esueaza.
     * @return void
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
                'identifier' => 'MySQLWrapper_Connection_Failure'
            ];

            $this->logEmergency('Database connection initial failure', $context);
            
            throw new RuntimeException(__('Database connection failed.'), 500, $e);
        }
    }

    /**
     * Returneaza conexiunea activa sau arunca o exceptie daca aceasta nu exista.
     *
     * @throws RuntimeException Daca proprietatea de conexiune este nula.
     * @return PDO Obiectul PDO valid.
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException(__('No active database connection found.'));
        }
        return $this->connection;
    }

    /**
     * Intrerupe conexiunea curenta cu baza de date.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Executa o interogare SQL securizata si trimite detaliile tehnice complete prin cURL in caz de eroare.
     *
     * @param string $sql Comanda SQL de executat.
     * @param array $params Parametrii asociati marcajelor de substitutie.
     * @throws RuntimeException Cand executia intampina erori de sintaxa sau retea.
     * @return PDOStatement Obiectul rezultat in urma executiei cu succes.
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
                'identifier' => 'MySQLWrapper_Query_Failure'
            ];

            $this->logEmergency('Query execution failure event', $context);
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', $context);

            throw new RuntimeException(__('Internal query execution error.'), 500, $e);
        }
    }

    /**
     * Insereaza o inregistrare noua intr-o tabela specificata.
     *
     * @param string $table Numele tabelei vizate.
     * @param array $data Setul de date in format coloana => valoare.
     * @return string|int ID-ul ultimei inregistrari inserate sau numarul de randuri afectate.
     */
    public function create(string $table, array $data): string|int
    {
        if (empty($data)) {
            throw new RuntimeException(__('Cannot insert empty data into table :table.', ['table' => $table]));
        }

        $escapedColumns = array_map(fn($col) => "`{$col}`", array_keys($data));
        $columns = implode(', ', $escapedColumns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->query($sql, array_values($data));

        $lastId = $this->getConnection()->lastInsertId();
        if ($lastId && $lastId !== '0') {
            return $lastId;
        }

        return $stmt->rowCount();
    }

    /**
     * Interogheaza baza de date si returneaza toate potrivirile gasite.
     *
     * @param string $table Numele tabelei.
     * @param array $conditions Conditii de filtrare de tip coloana => valoare.
     * @param array $columns Lista coloanelor selectate.
     * @param string $logic Operatorul logic folosit intre filtre (AND/OR).
     * @return array Tablou multidimensional cu rezultatele gasite.
     */
    public function read(string $table, array $conditions = [], array $columns = ['*'], string $logic = 'AND'): array
    {
        $escapedSelectColumns = array_map(fn($col) => $col === '*' ? '*' : "`{$col}`", $columns);
        $sql = "SELECT " . implode(', ', $escapedSelectColumns) . " FROM `{$table}`";
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
        return $stmt->fetchAll();
    }

    /**
     * Modifica inregistrarile dintr-o tabela in baza unor criterii clare.
     *
     * @param string $table Tabela afectata.
     * @param array $data Noile informatii care trebuiesc salvate.
     * @param array $conditions Conditiile de determinare a randurilor modificate.
     * @param string $logic Legatura logica dintre filtre.
     * @return int Numarul total de randuri modificate de operatie.
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
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setClauses);

        $whereClauses = [];
        foreach ($conditions as $key => $value) {
            $whereClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        $sql .= " WHERE " . implode(" {$logic} ", $whereClauses);

        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Sterge inregistrari din tabela protejand operatia contra stergerilor globale accidentale.
     *
     * @param string $table Tabela vizata.
     * @param array $conditions Conditii obligatorii de stergere randuri.
     * @param string $logic Operatorul de legatura pentru clauza WHERE.
     * @return int Numarul randurilor sterse definitiv.
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
     * Scrie un log de urgenta in caz de esec al bazei de date.
     *
     * @param string $message Mesajul de eroare.
     * @param array $context Informatiile suplimentare de context.
     * @return void
     */
    private function logEmergency(string $message, array $context): void
    {
        try {
            $dir = dirname(__DIR__, 2) . '/storage/logs';
            
            if (!is_dir($dir)) {
                if (file_exists($dir)) {
                    throw new RuntimeException("Path '{$dir}' exists but is not a directory.");
                }
                if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                    throw new RuntimeException("Failed to create directory '{$dir}'.");
                }
            }

            if (!is_writable($dir)) {
                throw new RuntimeException("Directory '{$dir}' is not writable.");
            }

            $filePath = $dir . '/emergency-logs-' . date('Ymd') . '.log';
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
            // Trimitem eroarea catre logul nativ de sistem (error_log) ca fallback de ultima instanta
            error_log("Emergency logging failed: " . $logException->getMessage());
        }
    }
}