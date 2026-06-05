<?php

declare(strict_types=1);

namespace App\Utilities;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
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
    /**
     * @readonly
     */
    private string $host;
    /**
     * @readonly
     */
    private string $db;
    /**
     * @readonly
     */
    private string $user;
    /**
     * @readonly
     */
    private string $pass;
    /**
     * @readonly
     */
    private string $port = '3306';
    /**
     * @readonly
     */
    private string $charset = 'utf8mb4';
    /** @var MySQLWrapper|null Instanta Singleton a clasei. */
    private static ?self $instance = null;

    /** @var PDO|null Obiectul conexiunii active PDO. */
    private ?PDO $connection = null;

    /** @var array Configurari standard de securitate si comportament pentru PDO. */
    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
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
        string $host,
        string $db,
        string $user,
        string $pass,
        string $port = '3306',
        string $charset = 'utf8mb4'
    ) {
        $this->host = $host;
        $this->db = $db;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
        $this->charset = $charset;
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
            $_ENV['DB_HOST'] ?? 'db',
            $_ENV['DB_DATABASE'] ?? 'log_monitor',
            $_ENV['DB_USERNAME'] ?? 'user',
            $_ENV['DB_PASSWORD'] ?? 'password',
            $_ENV['DB_PORT'] ?? '3306',
        );
    }

    /**
     * Initializeaza conexiunea nativa PDO si trimite erorile direct catre cURL.
     *
     * @throws RuntimeException Daca initializarea conexiunii esueaza.
     */
    public function connect(): void
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s;port=%s', $this->host, $this->db, $this->charset, $this->port);

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $this->options);
        } catch (PDOException $pdoException) {
            LogViaStream::send(LogLevel::ERROR, 'Database connection initial failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $pdoException->getMessage(),
                'exception_file' => $pdoException->getFile(),
                'exception_line' => $pdoException->getLine(),
                'exception_trace' => $pdoException->getTraceAsString(),
                'db_host' => $this->host,
                'db_name' => $this->db,
                'identifier' => 'MySQLWrapper_Connection_Failure'
            ]);
            
            throw new RuntimeException(__('Database connection failed.'), 500, $pdoException);
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
        if (!$this->connection instanceof \PDO) {
            throw new RuntimeException(__('No active database connection found.'));
        }

        return $this->connection;
    }

    /**
     * Intrerupe conexiunea curenta cu baza de date.
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
        } catch (PDOException $pdoException) {
            LogViaStream::send(LogLevel::ERROR, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $pdoException->getMessage(),
                'exception_file' => $pdoException->getFile(),
                'exception_line' => $pdoException->getLine(),
                'exception_trace' => $pdoException->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);

            throw new RuntimeException(__('Internal query execution error.'), 500, $pdoException);
        }
    }

    /**
     * Insereaza o inregistrare noua intr-o tabela specificata.
     *
     * @param string $table Numele tabelei vizate.
     * @param array $data Setul de date in format coloana => valoare.
     * @return string|int ID-ul ultimei inregistrari inserate sau numarul de randuri afectate.
     */
    public function create(string $table, array $data)
    {
        if ($data === []) {
            throw new RuntimeException(__('Cannot insert empty data into table :table.', ['table' => $table]));
        }

        $escapedColumns = array_map(fn(string $col): string => sprintf('`%s`', $col), array_keys($data));
        $columns = implode(', ', $escapedColumns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, $columns, $placeholders);
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
        $escapedSelectColumns = array_map(fn($col): string => $col === '*' ? '*' : sprintf('`%s`', $col), $columns);
        $sql = "SELECT " . implode(', ', $escapedSelectColumns) . sprintf(' FROM `%s`', $table);
        $params = [];

        if ($conditions !== []) {
            $sql .= " WHERE ";
            $clauses = [];
            foreach ($conditions as $key => $value) {
                $clauses[] = sprintf('`%s` = ?', $key);
                $params[] = $value;
            }

            $sql .= implode(sprintf(' %s ', $logic), $clauses);
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
        if ($data === [] || $conditions === []) {
            throw new RuntimeException(__('Update operations require both data and condition constraints.'));
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setClauses[] = sprintf('`%s` = ?', $key);
            $params[] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET ', $table) . implode(', ', $setClauses);

        $whereClauses = [];
        foreach ($conditions as $key => $value) {
            $whereClauses[] = sprintf('`%s` = ?', $key);
            $params[] = $value;
        }

        $sql .= " WHERE " . implode(sprintf(' %s ', $logic), $whereClauses);

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
        if ($conditions === []) {
            throw new RuntimeException(__('Unconditional delete operations are prohibited for security reasons.'));
        }

        $sql = sprintf('DELETE FROM `%s` WHERE ', $table);
        $clauses = [];
        $params = [];
        foreach ($conditions as $key => $value) {
            $clauses[] = sprintf('`%s` = ?', $key);
            $params[] = $value;
        }

        $sql .= implode(sprintf(' %s ', $logic), $clauses);

        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
}