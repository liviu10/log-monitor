<?php

declare(strict_types=1);

namespace App\Utilities;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Clasa MySQLWrapper
 *
 * Ofera o interfata simplificata si securizata pentru interactiunea cu o baza de date MySQL folosind PDO.
 * Implementeaza modelul Singleton pentru a asigura o singura conexiune activa pe parcursul executiei scriptului.
 * Include metode pentru operatiuni de tip CRUD (Create, Read, Update, Delete) si gestionarea erorilor de conectare.
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
    /** @var MySQLWrapper|null Instanta Singleton a clasei. */
    private static ?self $instance = null;

    /** @var PDO|null Resursa de conexiune la baza de date. */
    private ?PDO $connection = null;

    /** @var array<int, int|bool> Optiunile implicite pentru configurarea PDO. */
    private array $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /**
     * Constructorul clasei MySQLWrapper.
     * Utilizăm Constructor Property Promotion pentru toți parametrii de configurare.
     *
     * @param string $host    Gazda bazei de date.
     * @param string $db      Numele bazei de date.
     * @param string $user    Utilizatorul.
     * @param string $pass    Parola.
     * @param string $port    Portul (implicit 3306).
     * @param string $charset Charset-ul (implicit utf8mb4).
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
     * Returneaza instanta unica a clasei MySQLWrapper (Singleton).
     * Daca instanta nu exista, o creeaza folosind variabilele de mediu.
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
     * Stabileste conexiunea la baza de date folosind configuratiile setate.
     */
    public function connect(): void
    {
        $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset};port={$this->port}";

        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $this->options);
        } catch (PDOException $e) {
            error_log("Eroare de conectare la baza de date: " . $e->getMessage());
            $this->connection = null;
        }
    }

    /**
     * Returneaza obiectul PDO activ.
     */
    public function getConnection(): ?PDO
    {
        return $this->connection;
    }

    /**
     * Inchide conexiunea la baza de date prin setarea resursei la null.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }

    /**
     * Executa o interogare SQL securizata folosind prepared statements.
     *
     * @param string $sql    Interogarea SQL.
     * @param array  $params Parametrii pentru bind.
     * @return PDOStatement|false Obiectul statement sau false in caz de eroare.
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
        } catch (PDOException $e) {
            error_log("Eroare la executarea interogarii SQL: " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }

    /**
     * Insereaza o inregistrare noua intr-o tabela.
     *
     * @param string $table Numele tabelei.
     * @param array  $data  Tablou asociativ de date (coloana => valoare).
     * @return string|int|bool ID-ul inserat, true (pentru tabele fara auto-inc) sau false.
     */
    public function create(string $table, array $data): string|int|bool
    {
        if (empty($data)) {
            return false;
        }

        $columns = implode(', ', array_keys($data));
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
     * Citeste date dintr-o tabela cu filtre optionale.
     *
     * @param string $table      Numele tabelei.
     * @param array  $conditions Conditii WHERE (coloana => valoare).
     * @param array  $columns    Coloanele de extras.
     * @param string $logic      Logica dintre conditii (AND/OR).
     * @return array|false Tablou de rezultate sau false in caz de eroare.
     */
    public function read(string $table, array $conditions = [], array $columns = ['*'], string $logic = 'AND'): array|false
    {
        $sql = "SELECT " . implode(', ', $columns) . " FROM {$table}";
        $params = [];

        if (!empty($conditions)) {
            $sql .= " WHERE ";
            $clauses = [];
            foreach ($conditions as $key => $value) {
                $clauses[] = "{$key} = ?";
                $params[] = $value;
            }
            $sql .= implode(" {$logic} ", $clauses);
        }

        $stmt = $this->query($sql, $params);

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : false;
    }

    /**
     * Actualizeaza inregistrari intr-o tabela.
     *
     * @param string $table      Numele tabelei.
     * @param array  $data       Datele noi (coloana => valoare).
     * @param array  $conditions Conditii WHERE.
     * @param string $logic      Logica dintre conditii.
     * @return int|false Numarul de randuri afectate sau false.
     */
    public function update(string $table, array $data, array $conditions, string $logic = 'AND'): int|false
    {
        if (empty($data) || empty($conditions)) {
            return false;
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $key => $value) {
            $setClauses[] = "{$key} = ?";
            $params[] = $value;
        }
        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses);

        $whereClauses = [];
        foreach ($conditions as $key => $value) {
            $whereClauses[] = "{$key} = ?";
            $params[] = $value;
        }
        $sql .= " WHERE " . implode(" {$logic} ", $whereClauses);

        $stmt = $this->query($sql, $params);
        
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Sterge inregistrari dintr-o tabela pe baza unor conditii.
     *
     * @param string $table      Numele tabelei.
     * @param array  $conditions Conditii WHERE.
     * @param string $logic      Logica dintre conditii.
     * @return int|false Numarul de randuri afectate sau false.
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
            $clauses[] = "{$key} = ?";
            $params[] = $value;
        }
        $sql .= implode(" {$logic} ", $clauses);

        $stmt = $this->query($sql, $params);

        return $stmt ? $stmt->rowCount() : false;
    }
}
