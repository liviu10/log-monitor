<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;
use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * Log Class
 *
 * Gestioneaza operatiile bazei de date pentru entitatea Log.
 * Securizat impotriva SQL injection la paginare prin eliminarea interpolarii si validare stricta.
 * Utilizeaza indexare FULLTEXT pentru cautari eficiente.
 *
 * @category Model
 * @package  App\Models
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class Log
{
    protected MySQLWrapper $db;
    /**
     * Constructorul clasei Log.
     * Promovarea proprietatilor pentru injectarea bazei de date.
     * * @param MySQLWrapper $db Instanta wrapper-ului bazei de date.
     */
    public function __construct(
        ?MySQLWrapper $db = null
    ) {
        $db ??= new MySQLWrapper(
            'db',
            'log_monitor',
            'user',
            'password'
        );
        $this->db = $db;
        $this->db = MySQLWrapper::getInstance();
    }

    /**
     * Creaza o noua inregistrare de log in baza de date.
     *
     * @param int    $appId   ID-ul aplicatiei sursa.
     * @param string $level   Nivelul de severitate.
     * @param string $message Mesajul descriptiv.
     * @param mixed  $context Date suplimentare de context (vor fi JSON).
     * @return int ID-ul logului creat.
     * @throws InvalidArgumentException Daca datele obligatorii lipsesc.
     * @throws RuntimeException Daca operatiunea esueaza.
     */
    public function create(int $appId, string $level, string $message, $context = null): int
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
            if ($result === false) {
                throw new RuntimeException(__('Failed to write log to database'));
            }

            return (int)$result;
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
            throw new RuntimeException(__('Database error saving log entry'), 0, $pdoException);
        }
    }

    /**
     * Returneaza o lista paginata de loguri bazata pe filtre aplicate.
     * Securizat complet impotriva atacurilor de injectare SQL prin parametri legati nativ pentru LIMIT/OFFSET.
     *
     * @param array  $filters Filtre aplicate.
     * @param int    $limit   Numar maxim de inregistrari.
     * @param int    $offset  Punctul de pornire al paginarii.
     * @param string $sortBy  Coloana dupa care se face sortarea.
     * @param string $sortDir Directia de sortare (ASC sau DESC).
     * @return array Logurile gasite.
     */
    public function getPaginated(array $filters = [], int $limit = 50, int $offset = 0, string $sortBy = 'id', string $sortDir = 'DESC'): array
    {
        if ($limit < 1) {
            $limit = 50;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = (int)$filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = strtoupper(trim((string)$filters['level']));
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = trim((string)$filters['search']) . "*";
        }

        // Validare stricta a coloanelor de sortare pentru a preveni SQL Injection
        $allowedSorts = ['id', 'created_at', 'level', 'app_name'];
        $allowedDirections = ['ASC', 'DESC'];

        $sortField = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'id';
        $sortOrder = in_array(strtoupper($sortDir), $allowedDirections, true) ? strtoupper($sortDir) : 'DESC';

        $orderClause = $sortField === 'app_name' ? 'ORDER BY a.name ' . $sortOrder : sprintf('ORDER BY l.%s %s', $sortField, $sortOrder);

        // Adaugam l.id ca sortare secundara pentru a avea o cronologie determinista la loguri sosite in aceeasi secunda
        if ($sortField !== 'id') {
            $orderClause .= ', l.id ' . $sortOrder;
        }

        // Securizare stricta: LIMIT si OFFSET sunt interpolate direct ca intregi pentru a evita legarea lor ca string de catre PDO
        $sql .= sprintf(' %s LIMIT ', $orderClause) . $limit . " OFFSET " . $offset;

        try {
            $stmt = $this->db->query($sql, $params);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
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
            return [];
        }
    }

    /**
     * Numara logurile totale care corespund filtrelor selectate.
     *
     * @param array $filters Filtre aplicate.
     * @return int Numarul total de loguri gasite.
     */
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM logs l WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = (int)$filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = strtoupper(trim((string)$filters['level']));
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = trim((string)$filters['search']) . "*";
        }

        try {
            $stmt = $this->db->query($sql, $params);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
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
            return 0;
        }
    }

    /**
     * Numara inregistrarile create inainte de o anumita data.
     *
     * @param string $date Data limita de demarcare.
     * @return int Numarul total de loguri vechi.
     */
    public function countBeforeDate(string $date): int
    {
        $sql = "SELECT COUNT(*) FROM logs WHERE created_at < ?";
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);
            return $stmt ? (int)$stmt->fetchColumn() : 0;
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
            return 0;
        }
    }

    /**
     * Recupereaza logurile create inainte de o anumita data, in mod paginat.
     *
     * @param string $date   Data limita de demarcare.
     * @param int    $limit  Numar logs per chunk.
     * @param int    $offset Punct de pornire paginare.
     * @return array Lista rezultatelor.
     */
    public function getBeforeDate(string $date, int $limit, int $offset): array
    {
        if ($limit < 1) {
            $limit = 50;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE l.created_at < ? 
                ORDER BY l.created_at ASC 
                LIMIT " . $limit . " OFFSET " . $offset;
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
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
            return [];
        }
    }

    /**
     * Sterge logurile create mai vechi decat o anumita data.
     *
     * @param string $date Data limita de demarcare.
     * @return int Numarul de inregistrari sterse.
     * @throws RuntimeException Daca stergerea esueaza catastrofic.
     */
    public function deleteBeforeDate(string $date): int
    {
        $sql = "DELETE FROM logs WHERE created_at < ?";
        $params = [$date];

        try {
            $stmt = $this->db->query($sql, $params);
            return $stmt ? $stmt->rowCount() : 0;
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
            throw new RuntimeException(__('Failed to clear old logs from storage'), 0, $pdoException);
        }
    }

    /**
     * Optimizeaza tabelul de loguri pentru eliberarea spatiului de stocare fragmentat.
     *
     * @return bool True in caz de succes, altfel False.
     */
    public function optimize(): bool
    {
        $sql = "OPTIMIZE TABLE logs";
        try {
            $stmt = $this->db->query($sql);
            return $stmt !== false;
        } catch (PDOException $pdoException) {
            LogViaStream::send(LogLevel::ERROR, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $pdoException->getMessage(),
                'exception_file' => $pdoException->getFile(),
                'exception_line' => $pdoException->getLine(),
                'exception_trace' => $pdoException->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => [],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return false;
        }
    }

    /**
     * Extrage statistici agregate pentru dashboard-ul de administrare.
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
                'warning' => $warning
            ];
        } catch (PDOException $pdoException) {
            LogViaStream::send(LogLevel::ERROR, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $pdoException->getMessage(),
                'exception_file' => $pdoException->getFile(),
                'exception_line' => $pdoException->getLine(),
                'exception_trace' => $pdoException->getTraceAsString(),
                'sql_statement' => 'Dashboard Statistics Aggregation',
                'sql_parameters' => [],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return ['total' => 0, 'critical' => 0, 'warning' => 0];
        }
    }
}